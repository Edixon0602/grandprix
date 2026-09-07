<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/Database.php';
require_once dirname(__DIR__) . '/lib/CustomerPortal.php';
require_once dirname(__DIR__) . '/lib/TelemetryStore.php';
require_once dirname(__DIR__) . '/lib/TraccarClient.php';
require_once dirname(__DIR__) . '/lib/GpsPresentation.php';
require_once dirname(__DIR__) . '/lib/RealtimePublisher.php';

gp_start_session();
gp_require_admin();
header('Cache-Control: no-store, private');
$csrf = gp_csrf_token();
$message = '';
$error = '';
$config = gp_traccar_config();

try {
    if (!Database::configured()) throw new RuntimeException('Falta config/database.php. Ejecuta primero la actualización V7.2.');
    $pdo = Database::connection();
    $portal = new CustomerPortal($pdo);
    $store = new TelemetryStore();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!gp_verify_csrf((string) ($_POST['csrf'] ?? ''))) {
            throw new InvalidArgumentException('La sesión de seguridad venció. Recarga la página.');
        }
        $action = (string) ($_POST['action'] ?? 'sync');
        if ($action === 'refresh') {
            $client = new TraccarClient($config);
            $devices = array_values(array_filter((array) $client->get('/devices'), 'is_array'));
            $positions = array_values(array_filter((array) $client->get('/positions'), 'is_array'));
            $store->bootstrap($devices, $positions);
            $message = 'Catálogo y últimas posiciones sincronizados una sola vez desde Traccar.';
        } elseif ($action === 'sync') {
            $customerId = filter_var($_POST['customer_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $deviceId = filter_var($_POST['device_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($customerId === false || $deviceId === false) throw new InvalidArgumentException('Selecciona un cliente y un Device ID válidos.');

            $snapshot = $store->snapshot();
            $device = resolveRepairDevice($snapshot, (int) $deviceId);
            if (!$device) {
                $client = new TraccarClient($config);
                $devices = array_values(array_filter((array) $client->get('/devices', ['id' => (int) $deviceId]), 'is_array'));
                $device = $devices[0] ?? null;
                if ($device) {
                    $positions = [];
                    $positionId = (int) ($device['positionId'] ?? 0);
                    if ($positionId > 0) $positions = array_values(array_filter((array) $client->get('/positions', ['id' => $positionId]), 'is_array'));
                    $store->bootstrap([$device], $positions, (array) ($snapshot['groups'] ?? []), (array) ($snapshot['geofences'] ?? []));
                }
            }
            if (!$device || (int) ($device['id'] ?? 0) !== (int) $deviceId) {
                throw new InvalidArgumentException('Ese Device ID no existe o no está autorizado por el token de Traccar.');
            }
            $portal->updateAssignedGps((int) $customerId, (int) $deviceId, (string) ($device['uniqueId'] ?? ''));
            $message = 'Asignación corregida: el cliente quedó vinculado exclusivamente al GPS seleccionado.';
        }
    }

    $customers = $pdo->query(
        "SELECT u.id, u.full_name, u.public_key, u.status, c.contract_number, v.code, v.traccar_device_id, v.traccar_unique_id
         FROM gp_customers u
         LEFT JOIN gp_contracts c ON c.customer_id = u.id AND c.status = 'active'
         LEFT JOIN gp_vehicles v ON v.id = c.vehicle_id
         ORDER BY u.full_name"
    )->fetchAll();
    $requestedCustomer = filter_var($_GET['customer_id'] ?? $_POST['customer_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $selectedCustomerId = $requestedCustomer !== false && $requestedCustomer !== null
        ? (int) $requestedCustomer
        : (int) ($customers[0]['id'] ?? 0);
    $selectedCustomer = null;
    foreach ($customers as $candidate) {
        if ((int) ($candidate['id'] ?? 0) === $selectedCustomerId) {
            $selectedCustomer = $candidate;
            break;
        }
    }

    $snapshot = $store->snapshot();
    $devices = array_values(array_filter((array) ($snapshot['devices'] ?? []), 'is_array'));
    usort($devices, static fn(array $a, array $b): int => strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));
    $assignment = $selectedCustomerId > 0 ? $portal->assignedGps($selectedCustomerId) : null;
    $assignedDevice = $assignment ? resolveRepairDevice($snapshot, (int) $assignment['deviceId'], (string) ($assignment['uniqueId'] ?? '')) : null;
    $position = $assignedDevice ? GpsPresentation::findPosition((array) ($snapshot['positions'] ?? []), (int) ($assignedDevice['id'] ?? 0)) : null;
    $validPosition = is_array($position)
        && is_numeric($position['latitude'] ?? null)
        && is_numeric($position['longitude'] ?? null)
        && !((float) $position['latitude'] === 0.0 && (float) $position['longitude'] === 0.0);
} catch (Throwable $exception) {
    $reference = gp_runtime_error('customer-repair', $exception);
    $error = $exception instanceof InvalidArgumentException
        ? $exception->getMessage()
        : 'No fue posible completar el diagnóstico. Referencia: ' . $reference . '.';
    $customers = $customers ?? [];
    $devices = $devices ?? [];
    $selectedCustomerId = $selectedCustomerId ?? 0;
    $selectedCustomer = $selectedCustomer ?? null;
    $snapshot = $snapshot ?? ['meta' => []];
    $assignment = $assignment ?? null;
    $assignedDevice = $assignedDevice ?? null;
    $validPosition = $validPosition ?? false;
}

function resolveRepairDevice(array $snapshot, int $deviceId, string $uniqueId = ''): ?array
{
    $devices = (array) ($snapshot['devices'] ?? []);
    if (is_array($devices[(string) $deviceId] ?? null)) {
        $candidate = $devices[(string) $deviceId];
        if ($uniqueId === '' || hash_equals($uniqueId, (string) ($candidate['uniqueId'] ?? ''))) return $candidate;
    }
    foreach ($devices as $device) {
        if (!is_array($device)) continue;
        if ((int) ($device['id'] ?? 0) === $deviceId
            && ($uniqueId === '' || hash_equals($uniqueId, (string) ($device['uniqueId'] ?? '')))) return $device;
    }
    if ($uniqueId !== '') {
        foreach ($devices as $device) {
            if (is_array($device) && hash_equals($uniqueId, (string) ($device['uniqueId'] ?? ''))) return $device;
        }
    }
    return null;
}

function checkCard(bool $ok, string $label, string $detail): string
{
    $class = $ok ? 'ok' : 'bad';
    $icon = $ok ? '✓' : '!';
    return '<article class="check ' . $class . '"><i>' . $icon . '</i><span><b>' . htmlspecialchars($label) . '</b><small>' . htmlspecialchars($detail) . '</small></span></article>';
}

$currentAssignedId = (int) ($assignment['deviceId'] ?? 0);
$lastWebhook = (string) ($snapshot['meta']['lastWebhookAt'] ?? '');
$webhookFresh = $lastWebhook !== '' && ($stamp = strtotime($lastWebhook)) !== false && time() - $stamp < 900;
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reparar portal cliente · GRANDPRIX</title>
<style>
:root{--navy:#061a31;--blue:#1477ff;--cyan:#25cce4;--green:#10ad84;--red:#e4455d;--line:#d9e5ee;--muted:#6b8197}*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at 78% 0,#174e7d,transparent 31%),var(--navy);font-family:Inter,Arial,sans-serif;color:#102944;padding:28px}.layout{width:min(1220px,100%);margin:auto;display:grid;grid-template-columns:1.1fr .9fr;gap:20px}.card{background:#fff;border:1px solid #dbe7ef;border-radius:26px;padding:28px;box-shadow:0 28px 80px #00132760}.brand{height:76px;border-radius:17px;background:linear-gradient(135deg,#092e52,#0a416f);display:flex;align-items:center;padding:10px 18px}.brand img{width:230px;max-height:56px;object-fit:contain}.eyebrow{display:block;color:var(--blue);font-size:11px;font-weight:900;letter-spacing:.16em;margin-top:25px}h1{font-size:34px;margin:8px 0}p{color:var(--muted);line-height:1.6}.notice{padding:13px 15px;border-radius:12px;margin:18px 0;font-size:13px}.notice.ok{background:#def8f0;color:#087b63}.notice.bad{background:#ffe7eb;color:#b82f45}.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}label{font-size:12px;font-weight:800}.full{grid-column:1/-1}select{width:100%;margin-top:7px;border:1px solid var(--line);border-radius:12px;padding:13px;background:#fbfdff;font-size:14px}.actions{display:flex;gap:10px;margin-top:18px}.actions button{border:0;border-radius:12px;padding:13px 16px;font-weight:900;cursor:pointer}.primary{background:linear-gradient(135deg,var(--blue),#24a8df);color:#fff;box-shadow:0 12px 26px #1477ff30}.secondary{background:#eaf2fb;color:#235477}.checks{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:20px}.check{border:1px solid var(--line);border-radius:14px;padding:13px;display:flex;align-items:center;gap:10px}.check i{width:34px;height:34px;border-radius:11px;display:grid;place-items:center;font-style:normal;font-weight:900}.check b,.check small{display:block}.check b{font-size:12px}.check small{font-size:10px;color:var(--muted);margin-top:4px}.check.ok i{background:#def8f0;color:var(--green)}.check.bad i{background:#ffe7eb;color:var(--red)}.side{background:linear-gradient(155deg,#0a3155,#071d36);color:#fff}.side .eyebrow{color:#43d8ed}.side h2{font-size:25px}.summary{margin:20px 0;padding:18px;border:1px solid #ffffff20;background:#ffffff08;border-radius:16px}.summary span{display:flex;justify-content:space-between;gap:15px;padding:10px 0;border-bottom:1px solid #ffffff12}.summary span:last-child{border:0}.summary small{color:#8ea9c2}.summary b{text-align:right}.warning{padding:15px;border-radius:13px;background:#ffb22912;border:1px solid #ffbc3c55;color:#ffd78b;font-size:12px;line-height:1.6}.links{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:18px}.links a{color:#fff;text-decoration:none;text-align:center;padding:12px;border-radius:11px;background:#1477ff;font-weight:800;font-size:12px}.links a:last-child{background:#ffffff12;border:1px solid #ffffff20}@media(max-width:820px){body{padding:12px}.layout{display:block}.card{padding:20px;border-radius:22px;margin-bottom:13px}h1{font-size:27px}.form-grid,.checks{grid-template-columns:1fr}.full{grid-column:auto}.actions{display:grid}.links{grid-template-columns:1fr}}
</style>
</head>
<body><main class="layout"><section class="card"><div class="brand"><img src="../assets/grandprix-logo.png" alt="GRANDPRIX"></div><span class="eyebrow">HOTFIX V7.2.1 · DIAGNÓSTICO PROTEGIDO</span><h1>Portal cliente y GPS asignado</h1><p>Corrige la relación cliente → contrato → motocicleta → Device ID interno sin dar acceso a otras unidades.</p>
<?php if($message):?><div class="notice ok">✓ <?=htmlspecialchars($message)?></div><?php endif;?><?php if($error):?><div class="notice bad">! <?=htmlspecialchars($error)?></div><?php endif;?>
<form method="get" class="form-grid"><label class="full">Cliente a diagnosticar<select name="customer_id" onchange="this.form.submit()"><?php foreach($customers as $customer):?><option value="<?=(int)$customer['id']?>" <?=(int)$customer['id']===$selectedCustomerId?'selected':''?>><?=htmlspecialchars((string)$customer['full_name'])?> · <?=htmlspecialchars((string)($customer['code']??'Sin moto'))?></option><?php endforeach;?></select></label></form>
<div class="checks">
<?=checkCard(Database::configured(),'Base de datos','Configuración privada detectada')?>
<?=checkCard($selectedCustomer!==null&&($selectedCustomer['status']??'')==='active','Cuenta del cliente',$selectedCustomer['full_name']??'No encontrada')?>
<?=checkCard($assignment!==null,'Contrato y motocicleta',$assignment?($assignment['code'].' · '.$assignment['model']):'Sin contrato activo')?>
<?=checkCard($assignedDevice!==null,'Coincidencia en Traccar',$assignedDevice?('Device ID '.(int)$assignedDevice['id']):('Asignado: '.$currentAssignedId.' · no encontrado'))?>
<?=checkCard($validPosition,'Última posición',$validPosition?'Coordenadas reales disponibles':'Sin posición válida para esta asignación')?>
<?=checkCard($webhookFresh,'Webhook reciente',$lastWebhook!==''?$lastWebhook:'Todavía sin recepción')?>
<?=checkCard(RealtimePublisher::configured($config),'WebSocket privado',RealtimePublisher::configured($config)?'Pusher configurado':'Falta completar Pusher')?>
<?=checkCard(count($devices)>0,'Catálogo local',count($devices).' dispositivo(s) en memoria')?>
</div>
<form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="customer_id" value="<?=$selectedCustomerId?>"><div class="form-grid" style="margin-top:20px"><label class="full">Device ID real de Traccar<select name="device_id" required><option value="">Seleccionar dispositivo</option><?php foreach($devices as $device):$id=(int)($device['id']??0);?><option value="<?=$id?>" <?=$id===$currentAssignedId?'selected':''?>>ID <?=$id?> · <?=htmlspecialchars((string)($device['name']??'GPS'))?> · <?=htmlspecialchars((string)($device['uniqueId']??'Sin Unique ID'))?> · <?=htmlspecialchars((string)($device['status']??'unknown'))?></option><?php endforeach;?></select></label></div><div class="actions"><button class="primary" name="action" value="sync">Guardar asignación y verificar</button><button class="secondary" name="action" value="refresh" formnovalidate>Sincronizar catálogo una vez</button></div></form></section>
<aside class="card side"><span class="eyebrow">RESULTADO ACTUAL</span><h2><?=htmlspecialchars((string)($selectedCustomer['full_name']??'Cliente no seleccionado'))?></h2><div class="summary"><span><small>Moto</small><b><?=htmlspecialchars((string)($assignment['code']??'Sin asignar'))?></b></span><span><small>Device ID guardado</small><b><?=$currentAssignedId>0?$currentAssignedId:'Sin asignar'?></b></span><span><small>Device ID encontrado</small><b><?=$assignedDevice?(int)$assignedDevice['id']:'No coincide'?></b></span><span><small>GPS en portal</small><b><?=$validPosition?'Listo para mostrar':'Pendiente de corregir'?></b></span><span><small>Entrega continua</small><b>Webhook + WebSocket</b></span></div><div class="warning"><b>Importante:</b> selecciona el número interno que aparece en Traccar, no el IMEI. Después de obtener todo en verde, prueba el portal y elimina nuevamente la carpeta <code>install</code>.</div><div class="links"><a href="../cliente/">Abrir Mi GRANDPRIX</a><a href="../">Volver al panel</a></div></aside></main></body></html>
