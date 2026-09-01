<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/DatabaseSchema.php';
require_once dirname(__DIR__) . '/lib/SecretBox.php';

gp_start_session();
gp_require_admin();
$csrf = gp_csrf_token();
$messages = [];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!gp_verify_csrf((string) ($_POST['csrf'] ?? ''))) {
        $error = 'La sesion de seguridad vencio. Recarga la pagina.';
    } else {
        try {
            $db = validateDatabaseInput($_POST);
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['database']);
            $pdo = new PDO($dsn, $db['username'], $db['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false,
            ]);
            DatabaseSchema::migrate($pdo);
            $messages[] = 'Base de datos y tablas V7.2 preparadas.';
            saveDatabaseConfig($db);
            $messages[] = 'Conexion protegida guardada dentro de config.';
            (new SecretBox())->ensureKey();
            $messages[] = 'Llave criptografica de comandos generada.';
            if (!empty($_POST['seed_yeivert'])) {
                seedYeivert($pdo, $_POST);
                $messages[] = 'Cuenta de Yeivert, moto asignada y plan de 50 semanas preparados.';
            }
            $messages[] = 'Actualizacion V7.2 instalada correctamente.';
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        } catch (PDOException) {
            $error = 'No fue posible conectar o crear las tablas. Revisa servidor, base, usuario y permisos MySQL.';
        } catch (Throwable $exception) {
            $error = 'La actualizacion no pudo completarse: ' . mb_substr($exception->getMessage(), 0, 240);
        }
    }
}

function validateDatabaseInput(array $input): array
{
    $host = strtolower(trim((string) ($input['db_host'] ?? 'localhost')));
    $port = filter_var($input['db_port'] ?? 3306, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
    $database = trim((string) ($input['db_name'] ?? ''));
    $username = trim((string) ($input['db_user'] ?? ''));
    $password = (string) ($input['db_password'] ?? '');
    if (!preg_match('/^[A-Za-z0-9._-]{1,190}$/', $host) || $port === false) throw new InvalidArgumentException('Servidor o puerto MySQL invalido.');
    if (!preg_match('/^[A-Za-z0-9_$-]{1,64}$/', $database) || !preg_match('/^[A-Za-z0-9_.$-]{1,96}$/', $username)) {
        throw new InvalidArgumentException('Nombre de base de datos o usuario invalido.');
    }
    return ['host' => $host, 'port' => (int) $port, 'database' => $database, 'username' => $username, 'password' => $password];
}

function saveDatabaseConfig(array $config): void
{
    $path = dirname(__DIR__) . '/config/database.php';
    $contents = "<?php\ndeclare(strict_types=1);\nreturn " . var_export($config, true) . ";\n";
    $temporary = tempnam(dirname($path), 'database-');
    if ($temporary === false || file_put_contents($temporary, $contents, LOCK_EX) === false) {
        if (is_string($temporary) && is_file($temporary)) @unlink($temporary);
        throw new RuntimeException('No fue posible escribir config/database.php.');
    }
    @chmod($temporary, 0640);
    if (!@rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('No fue posible publicar config/database.php.');
    }
}

function seedYeivert(PDO $pdo, array $input): void
{
    $password = (string) ($input['customer_password'] ?? '');
    if (strlen($password) < 8) throw new InvalidArgumentException('La clave inicial de Yeivert debe tener al menos 8 caracteres.');
    $identity = mb_strtoupper((string) preg_replace('/[^A-Za-z0-9-]/', '', (string) ($input['customer_identity'] ?? 'V-00000000')));
    $email = mb_strtolower(trim((string) ($input['customer_email'] ?? '')));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('El correo de Yeivert no es valido.');
    $deviceId = filter_var($input['device_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($deviceId === false) throw new InvalidArgumentException('Indica el Device ID numerico interno de Traccar.');
    $simPhone = preg_replace('/\D+/', '', (string) ($input['sim_phone'] ?? '')) ?: '';
    if ($simPhone !== '' && (strlen($simPhone) < 10 || strlen($simPhone) > 15)) throw new InvalidArgumentException('El numero de la SIM no es valido.');
    $gpsPassword = trim((string) ($input['gps_password'] ?? ''));
    if (!preg_match('/^\d{6}$/', $gpsPassword)) throw new InvalidArgumentException('La clave tecnica GT06 debe tener seis digitos.');
    $weekly = filter_var($input['weekly_amount'] ?? 65, FILTER_VALIDATE_FLOAT);
    $paidWeeks = filter_var($input['paid_weeks'] ?? 18, FILTER_VALIDATE_INT);
    $start = trim((string) ($input['start_date'] ?? date('Y-m-d')));
    $startDate = DateTimeImmutable::createFromFormat('!Y-m-d', $start);
    if ($weekly === false || $weekly <= 0 || $paidWeeks === false || $paidWeeks < 0 || $paidWeeks > 50 || !$startDate || $startDate->format('Y-m-d') !== $start) {
        throw new InvalidArgumentException('Revisa cuota semanal, semanas pagadas y fecha inicial.');
    }

    $pdo->beginTransaction();
    try {
        $customerQuery = $pdo->prepare('SELECT id FROM gp_customers WHERE public_key = ? LIMIT 1');
        $customerQuery->execute(['yeivert-sanchez']);
        $customerId = (int) ($customerQuery->fetchColumn() ?: 0);
        if ($customerId > 0) {
            $pdo->prepare(
                "UPDATE gp_customers SET full_name = 'Yeivert Sanchez', identity_document = ?, email = ?, password_hash = ?, status = 'active' WHERE id = ?"
            )->execute([$identity, $email ?: null, password_hash($password, PASSWORD_DEFAULT), $customerId]);
        } else {
            $pdo->prepare(
                "INSERT INTO gp_customers (public_key, full_name, identity_document, email, password_hash, status)
                 VALUES ('yeivert-sanchez', 'Yeivert Sanchez', ?, ?, ?, 'active')"
            )->execute([$identity, $email ?: null, password_hash($password, PASSWORD_DEFAULT)]);
            $customerId = (int) $pdo->lastInsertId();
        }

        $secret = (new SecretBox())->encrypt($gpsPassword);
        $vehicleQuery = $pdo->prepare('SELECT id FROM gp_vehicles WHERE traccar_device_id = ? OR code = ? LIMIT 1');
        $vehicleQuery->execute([(int) $deviceId, 'GP-0248']);
        $vehicleId = (int) ($vehicleQuery->fetchColumn() ?: 0);
        if ($vehicleId > 0) {
            $pdo->prepare(
                "UPDATE gp_vehicles SET code = 'GP-0248', plate = 'AA7K91E', model = 'Bera SBR 2025', traccar_device_id = ?,
                 sim_phone = ?, command_secret = ?, relay_verified = ?, commands_enabled = 1, status = 'active' WHERE id = ?"
            )->execute([(int) $deviceId, $simPhone ?: null, $secret, !empty($input['relay_verified']) ? 1 : 0, $vehicleId]);
        } else {
            $pdo->prepare(
                "INSERT INTO gp_vehicles (code, plate, model, traccar_device_id, sim_phone, command_secret, relay_verified, commands_enabled, status)
                 VALUES ('GP-0248', 'AA7K91E', 'Bera SBR 2025', ?, ?, ?, ?, 1, 'active')"
            )->execute([(int) $deviceId, $simPhone ?: null, $secret, !empty($input['relay_verified']) ? 1 : 0]);
            $vehicleId = (int) $pdo->lastInsertId();
        }

        $contractQuery = $pdo->prepare("SELECT id FROM gp_contracts WHERE customer_id = ? AND status = 'active' LIMIT 1");
        $contractQuery->execute([$customerId]);
        $contractId = (int) ($contractQuery->fetchColumn() ?: 0);
        $financed = round(((float) $weekly) * 50, 2);
        if ($contractId > 0) {
            $pdo->prepare(
                "UPDATE gp_contracts SET contract_number = 'GP-2026-0248', vehicle_id = ?, total_weeks = 50,
                 weekly_amount = ?, financed_amount = ?, start_date = ?, status = 'active' WHERE id = ?"
            )->execute([$vehicleId, $weekly, $financed, $start, $contractId]);
        } else {
            $pdo->prepare(
                "INSERT INTO gp_contracts (contract_number, customer_id, vehicle_id, total_weeks, weekly_amount, financed_amount, start_date, status)
                 VALUES ('GP-2026-0248', ?, ?, 50, ?, ?, ?, 'active')"
            )->execute([$customerId, $vehicleId, $weekly, $financed, $start]);
            $contractId = (int) $pdo->lastInsertId();
        }

        $week = $pdo->prepare(
            "INSERT INTO gp_contract_weeks (contract_id, week_number, due_date, amount, status, paid_at)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE due_date = VALUES(due_date), amount = VALUES(amount),
              status = CASE WHEN payment_report_id IS NULL THEN VALUES(status) ELSE status END,
              paid_at = CASE WHEN payment_report_id IS NULL THEN VALUES(paid_at) ELSE paid_at END"
        );
        $today = new DateTimeImmutable('today');
        for ($number = 1; $number <= 50; $number++) {
            $due = $startDate->add(new DateInterval('P' . (($number - 1) * 7) . 'D'));
            $status = $number <= (int) $paidWeeks ? 'paid' : ($due < $today ? 'late' : 'pending');
            $week->execute([$contractId, $number, $due->format('Y-m-d'), $weekly, $status, $status === 'paid' ? $due->format('Y-m-d 12:00:00') : null]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Actualizar GRANDPRIX V7.2</title>
<style>
*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at 78% 0,#174d7b,transparent 30%),#061a31;font-family:Inter,Arial,sans-serif;color:#102b48;padding:28px}.layout{width:min(1180px,100%);margin:auto;display:grid;grid-template-columns:1.35fr .65fr;gap:20px}.card{background:#fff;border:1px solid #dbe7f0;border-radius:26px;padding:28px;box-shadow:0 28px 75px #00132950}.brand{height:72px;background:#092d50;border-radius:16px;display:flex;align-items:center;padding:10px 18px;margin-bottom:23px}.brand img{width:230px;max-height:55px;object-fit:contain}.eyebrow{color:#1477ff;font-size:11px;font-weight:900;letter-spacing:.18em}.card h1{font-size:34px;margin:8px 0}.lead{color:#687f97;line-height:1.6}.section{margin:24px 0 12px;font-size:12px;letter-spacing:.12em;color:#1477ff}.grid{display:grid;grid-template-columns:1fr 1fr;gap:13px}.full{grid-column:1/-1}label{font-size:12px;font-weight:800}input{display:block;width:100%;border:1px solid #d5e2ec;border-radius:12px;padding:13px;margin-top:6px;font-size:14px;outline:0}input:focus{border-color:#1477ff;box-shadow:0 0 0 4px #1477ff17}.toggle{display:flex;gap:10px;align-items:flex-start;background:#eef5fb;border-radius:13px;padding:13px}.toggle input{width:auto;margin:2px 0}.toggle span{font-size:12px;line-height:1.5}.toggle b{display:block}.primary{width:100%;border:0;border-radius:13px;padding:15px;background:linear-gradient(100deg,#1477ff,#20bfe2);color:#fff;font-weight:900;cursor:pointer;box-shadow:0 14px 30px #1477ff35}.notice{border-radius:13px;padding:13px;margin:10px 0;font-size:12px;line-height:1.5}.notice.ok{background:#ddf8f1;color:#087d68}.notice.bad{background:#ffe7ec;color:#bb2f47}.side{color:#fff;background:linear-gradient(160deg,#0c355d,#071a31);border-color:#2d669a}.side h2{font-size:24px}.side p,.side li{color:#abc0d3;font-size:13px;line-height:1.6}.side li{margin:11px 0}.side strong{color:#fff}.go{display:block;text-align:center;text-decoration:none;background:#ffffff12;border:1px solid #ffffff25;color:#fff;border-radius:13px;padding:13px;margin-top:18px;font-weight:800}@media(max-width:820px){body{padding:12px}.layout{grid-template-columns:1fr}.card{padding:21px;border-radius:22px}.grid{grid-template-columns:1fr}.full{grid-column:auto}.card h1{font-size:27px}}
</style>
</head>
<body><main class="layout"><section class="card"><div class="brand"><img src="../assets/grandprix-logo.png" alt="GRANDPRIX"></div><span class="eyebrow">ACTUALIZACION TECNICA</span><h1>GRANDPRIX V7.2</h1><p class="lead">Prepara el portal financiero de clientes, asignacion individual de GPS y el catalogo protegido de 25 comandos GT06.</p>
<?php if($error):?><div class="notice bad"><b>Error:</b> <?=htmlspecialchars($error)?></div><?php endif;?>
<?php foreach($messages as $message):?><div class="notice ok">✓ <?=htmlspecialchars($message)?></div><?php endforeach;?>
<form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>"><h2 class="section">BASE DE DATOS MYSQL</h2><div class="grid"><label>Servidor<input name="db_host" value="localhost" required></label><label>Puerto<input name="db_port" type="number" value="3306" required></label><label>Base de datos<input name="db_name" required></label><label>Usuario MySQL<input name="db_user" required></label><label class="full">Contraseña MySQL<input name="db_password" type="password"></label></div>
<h2 class="section">CUENTA INICIAL DE YEIVERT</h2><label class="toggle full"><input id="seed-yeivert" type="checkbox" name="seed_yeivert" value="1" checked><span><b>Crear o actualizar cuenta inicial</b>Incluye moto GP-0248 y contrato de 50 semanas.</span></label><div id="seed-fields" class="grid"><label>Cedula<input name="customer_identity" value="V-00000000" data-seed-required></label><label>Correo opcional<input name="customer_email" type="email"></label><label class="full">Clave inicial del portal<input name="customer_password" type="password" minlength="8" autocomplete="new-password" data-seed-required></label><label>Device ID interno de Traccar<input name="device_id" type="number" min="1" data-seed-required></label><label>Numero de SIM del GPS<input name="sim_phone" placeholder="58412..."></label><label>Clave tecnica actual del GT06<input name="gps_password" type="password" inputmode="numeric" pattern="[0-9]{6}" placeholder="6 digitos" autocomplete="new-password" data-seed-required></label><label>Fecha inicial<input name="start_date" type="date" value="<?=date('Y-m-d')?>" data-seed-required></label><label>Cuota semanal<input name="weekly_amount" type="number" step="0.01" value="65" data-seed-required></label><label>Semanas ya pagadas<input name="paid_weeks" type="number" min="0" max="50" value="18" data-seed-required></label><label class="toggle full"><input type="checkbox" name="relay_verified" value="1"><span><b>Relay verificado fisicamente</b>Marcalo solo despues de probar el cableado con la moto detenida.</span></label></div><div style="height:20px"></div><button class="primary">Instalar actualizacion V7.2</button></form></section><aside class="card side"><span class="eyebrow">SEGURIDAD OPERATIVA</span><h2>Separacion total de permisos</h2><ul><li><strong>Personal GRANDPRIX:</strong> comandos, auditoria y conciliacion.</li><li><strong>Cliente:</strong> solo su moto, mapa, velocimetro, contrato y pagos.</li><li><strong>Sin polling:</strong> telemetria por webhook y WebSocket.</li><li><strong>Clave GPS cifrada:</strong> nunca viaja al navegador.</li><li><strong>Corte protegido:</strong> solo a 0 km/h, posicion menor a 30 segundos y relay verificado.</li></ul><?php if($messages):?><a class="go" href="../">Abrir GRANDPRIX Control 360</a><a class="go" href="../cliente/">Probar Mi GRANDPRIX</a><?php endif;?></aside></main><script>const seed=document.getElementById('seed-yeivert'),fields=document.getElementById('seed-fields');function syncSeed(){const active=seed.checked;fields.style.opacity=active?'1':'.52';fields.querySelectorAll('input').forEach(input=>{input.disabled=!active;if(input.hasAttribute('data-seed-required'))input.required=active})}seed.addEventListener('change',syncSeed);syncSeed()</script></body></html>
