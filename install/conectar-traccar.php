<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/TraccarClient.php';
require_once dirname(__DIR__) . '/lib/TelemetryStore.php';
require_once dirname(__DIR__) . '/lib/RealtimePublisher.php';


$configPath = dirname(__DIR__) . '/config/traccar.php';
$config = gp_traccar_config();
$message = '';
$error = '';
$devices = [];
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
$rootPath = rtrim(str_replace('\\', '/', dirname(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/install/conectar-traccar.php')))), '/');
$webhookUrl = $scheme . '://' . (string) ($_SERVER['HTTP_HOST'] ?? 'grandprixvzla.com') . $rootPath . '/api/traccar-webhook.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!gp_verify_csrf($_POST['csrf'] ?? null)) {
        $error = 'La sesión de seguridad venció. Recarga la página.';
    } else {
        $baseUrl = rtrim(trim((string) ($_POST['base_url'] ?? '')), '/');
        $newToken = trim((string) ($_POST['token'] ?? ''));
        $token = $newToken !== '' ? $newToken : (string) ($config['token'] ?? '');
        $authMode = in_array(($_POST['auth_mode'] ?? ''), ['query', 'bearer'], true) ? (string) $_POST['auth_mode'] : 'bearer';
        $newMaptilerKey = trim((string) ($_POST['maptiler_key'] ?? ''));
        $maptilerKey = $newMaptilerKey !== '' ? $newMaptilerKey : (string) ($config['maptiler_key'] ?? '');
        $mapStyle = in_array(($_POST['map_style'] ?? ''), ['hybrid', 'streets-v4', 'dataviz-dark'], true)
            ? (string) $_POST['map_style'] : 'hybrid';
        $deviceId = filter_var($_POST['customer_device_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $match = mb_substr(trim((string) ($_POST['customer_device_match'] ?? 'GP-0248')), 0, 80);
        $existingWebhookSecret = trim((string) ($config['webhook_secret'] ?? ''));
        $webhookSecret = $existingWebhookSecret !== '' ? $existingWebhookSecret : bin2hex(random_bytes(32));
        $newPusherSecret = trim((string) ($_POST['pusher_secret'] ?? ''));
        $pusherSecret = $newPusherSecret !== '' ? $newPusherSecret : (string) ($config['pusher_secret'] ?? '');
        $candidate = [
            'enabled' => true,
            'production_mode' => true,
            'base_url' => $baseUrl,
            'token' => $token,
            'token_expires_at' => $newToken === '' ? ($config['token_expires_at'] ?? null) : null,
            'auth_mode' => $authMode,
            'webhook_enabled' => true,
            'webhook_secret' => $webhookSecret,
            'realtime_enabled' => isset($_POST['realtime_enabled']),
            'realtime_provider' => 'pusher',
            'pusher_app_id' => trim((string) ($_POST['pusher_app_id'] ?? '')),
            'pusher_key' => trim((string) ($_POST['pusher_key'] ?? '')),
            'pusher_secret' => $pusherSecret,
            'pusher_cluster' => strtolower(trim((string) ($_POST['pusher_cluster'] ?? 'mt1'))),
            'map_provider' => 'maptiler',
            'maptiler_key' => $maptilerKey,
            'map_style' => $mapStyle,
            'allow_commands' => isset($_POST['allow_commands']),
            'allow_custom_commands' => false,
            'customer_portal_live' => isset($_POST['customer_portal_live']),
            'customer_auto_assign' => false,
            'customer_device_match' => $match,
            'customer_devices' => ['yeivert-sanchez' => $deviceId === false ? 0 : (int) $deviceId],
            'configured_for' => 'GRANDPRIX INVERSIONES',
            'updated_at' => date(DATE_ATOM),
        ];
        try {
            if ($maptilerKey !== '' && !preg_match('/^[A-Za-z0-9_-]{8,160}$/', $maptilerKey)) {
                throw new RuntimeException('La llave de MapTiler no tiene un formato valido. Copiala completa desde MapTiler Cloud.');
            }
            if ($candidate['realtime_enabled']) {
                if (!preg_match('/^[A-Za-z0-9_-]{2,80}$/', (string) $candidate['pusher_app_id'])) {
                    throw new RuntimeException('El App ID de Pusher no es valido.');
                }
                if (!preg_match('/^[A-Za-z0-9_-]{8,160}$/', (string) $candidate['pusher_key'])) {
                    throw new RuntimeException('La App Key de Pusher no es valida.');
                }
                if (strlen((string) $candidate['pusher_secret']) < 12) {
                    throw new RuntimeException('Falta el App Secret de Pusher.');
                }
                if (!preg_match('/^[a-z0-9-]+$/', (string) $candidate['pusher_cluster'])) {
                    throw new RuntimeException('El cluster de Pusher no es valido.');
                }
            }
            $client = new TraccarClient($candidate);
            $devices = (array) $client->get('/devices');
            $positions = (array) $client->get('/positions');
            try { $groups = (array) $client->get('/groups'); } catch (Throwable) { $groups = []; }
            try { $geofences = (array) $client->get('/geofences'); } catch (Throwable) { $geofences = []; }

            if ($deviceId === false) {
                throw new RuntimeException('Indica el Device ID numerico interno de Traccar. El valor 0 y el IMEI no son validos.');
            }
            $deviceExists = false;
            foreach ($devices as $device) {
                if (is_array($device) && (int) ($device['id'] ?? 0) === (int) $deviceId) {
                    $deviceExists = true;
                    break;
                }
            }
            if (!$deviceExists) {
                throw new RuntimeException('El Device ID indicado no existe dentro de los dispositivos visibles para este token de Traccar.');
            }

            (new TelemetryStore())->bootstrap($devices, $positions, $groups, $geofences);
            if ($candidate['realtime_enabled']) {
                $test = RealtimePublisher::publish($candidate, 'integration-test', [
                    'source' => 'grandprix-installer',
                    'time' => gmdate('c'),
                    'message' => 'Canal WebSocket verificado sin polling.',
                ], ['private-grandprix-fleet']);
                if (!$test['ok']) throw new RuntimeException('Pusher no pudo verificarse: ' . ($test['error'] ?? 'error desconocido'));
            }
            $php = "<?php\n// Generado por el configurador de producción GRANDPRIX.\nreturn " . var_export($candidate, true) . ";\n";
            if (file_put_contents($configPath, $php, LOCK_EX) === false) {
                throw new RuntimeException('No se pudo escribir config/traccar.php. Revisa permisos de la carpeta config.');
            }
            @chmod($configPath, 0640);
            $config = $candidate;
            $message = 'Conexión verificada. Se guardó el catálogo de ' . count($devices) . ' dispositivo(s), el webhook quedó activo y no existe polling.'
                . ($candidate['realtime_enabled'] ? ' El canal WebSocket privado también fue validado.' : ' Falta activar Pusher para movimiento instantáneo en pantallas abiertas.');
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    }
}

$tokenValue = (string) ($config['token'] ?? '');
$masked = $tokenValue !== '' ? 'Token protegido · termina en ' . htmlspecialchars(substr($tokenValue, -6)) : 'Falta guardar el token de producción';
$expiry = !empty($config['token_expires_at']) ? date('d/m/Y H:i', strtotime((string) $config['token_expires_at'])) . ' UTC' : 'No registrada; verifícala en Traccar';
$webhookSecretDisplay = (string) ($config['webhook_secret'] ?? '');
$pusherSecretMasked = !empty($config['pusher_secret']) ? 'App Secret protegido · termina en ' . htmlspecialchars(substr((string) $config['pusher_secret'], -6)) : 'Falta configurar el App Secret';
$telemetryDeviceCount = 0;
$telemetryPositionCount = 0;
$lastWebhookAt = null;
$catalogSyncedAt = null;
try {
    $telemetrySnapshot = (new TelemetryStore())->snapshot();
    $telemetryDeviceCount = count(array_filter((array) ($telemetrySnapshot['devices'] ?? []), 'is_array'));
    $telemetryPositionCount = count(array_filter((array) ($telemetrySnapshot['positions'] ?? []), 'is_array'));
    $lastWebhookAt = $telemetrySnapshot['meta']['lastWebhookAt'] ?? null;
    $catalogSyncedAt = $telemetrySnapshot['meta']['catalogSyncedAt'] ?? null;
} catch (Throwable) {
    // El formulario sigue disponible para poder corregir permisos/configuración.
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Traccar V<?=htmlspecialchars(gp_release())?> · GRANDPRIX</title>
<link rel="icon" href="../assets/grandprix-logo.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at 80% 0,#1477ff45,transparent 30%),linear-gradient(145deg,#061a31,#0b355d);min-height:100dvh;font-family:Inter,Arial;color:#132a43;padding:28px}.wrap{max-width:1160px;margin:auto}.top{display:flex;align-items:center;justify-content:space-between;color:#fff;margin-bottom:22px}.top img{width:210px;height:70px;object-fit:contain}.top a{color:#fff;text-decoration:none;border:1px solid #ffffff2f;border-radius:11px;padding:10px 14px}.grid{display:grid;grid-template-columns:minmax(0,1.28fr) minmax(320px,.72fr);gap:18px}.card{background:linear-gradient(145deg,#fff,#f9fcff);border:1px solid #ffffff73;border-radius:23px;padding:25px;box-shadow:0 26px 65px #0004}.card h1{margin:3px 0 8px}.card>p{color:#718399;line-height:1.5;font-size:13px}.eyebrow{color:#1477ff;font-weight:900;letter-spacing:2px;font-size:10px}.prod{display:inline-flex;align-items:center;gap:7px;background:#ddf8f1;color:#087d67;padding:8px 10px;border-radius:18px;font-size:9px;font-weight:900;margin-bottom:10px}.prod i{width:7px;height:7px;border-radius:50%;background:#18cda8;box-shadow:0 0 0 5px #18cda819}.fields{display:grid;grid-template-columns:1fr 1fr;gap:13px;margin-top:20px}label{font-size:11px;font-weight:800;display:block}.full{grid-column:1/-1}input,select{display:block;width:100%;margin-top:6px;border:1px solid #d8e4ed;background:#fff;border-radius:11px;padding:12px;outline:0}input:focus,select:focus{border-color:#1477ff;box-shadow:0 0 0 4px #1477ff12}.toggle{display:flex;align-items:flex-start;gap:10px;background:#f0f6fb;border:1px solid #e3ebf2;border-radius:12px;padding:12px}.toggle input{width:auto;margin:2px 0}.toggle span{font-weight:500;color:#556b81;line-height:1.4}.toggle b{display:block;color:#132a43;margin-bottom:2px}.submit{border:0;border-radius:12px;background:linear-gradient(95deg,#1477ff,#21b5df);color:#fff;padding:14px 18px;font-weight:900;cursor:pointer;width:100%;margin-top:18px;box-shadow:0 12px 28px #1477ff30}.msg,.err{padding:12px 14px;border-radius:11px;margin:15px 0;font-size:12px}.msg{background:#ddf8f1;color:#087c67}.err{background:#ffe8eb;color:#bd3146}.status{background:radial-gradient(circle at 90% 0,#1b8eff3b,transparent 30%),linear-gradient(145deg,#071e38,#0a2f52);color:#fff}.status .eyebrow{color:#4bdaf0}.status h2{font-size:20px}.row{display:flex;gap:10px;align-items:center;padding:12px 0;border-bottom:1px solid #ffffff18}.row i{width:38px;height:38px;border-radius:11px;background:#1477ff29;color:#58ccea;display:grid;place-items:center}.row span{display:block;font-size:11px;color:#9fb5c9}.row b{display:block;color:#fff;margin-bottom:3px}.note{background:#f2a82c18;border:1px solid #f2a82c45;color:#ffe1a4;border-radius:12px;padding:12px;font-size:11px;line-height:1.5;margin-top:15px}.expiry{background:#ef476018;border-color:#ef476055;color:#ffc3cc}.devices{margin-top:16px;max-height:300px;overflow:auto}.device{padding:9px 11px;background:#ffffff0b;border:1px solid #ffffff0a;border-radius:9px;margin:6px 0;font-size:11px}.device b,.device span{display:block}.device span{color:#9fb5c9;margin-top:3px}@media(max-width:760px){body{padding:14px}.top img{width:150px}.top a span{display:none}.grid{grid-template-columns:1fr}.fields{grid-template-columns:1fr}.full{grid-column:auto}.card{padding:19px}}
</style>
</head>
<body><div class="wrap">
<header class="top"><img src="../assets/grandprix-logo.png" alt="GRANDPRIX"><a href="../"><i class="fa-solid fa-arrow-left"></i> <span>Volver a Control 360</span></a></header>
<div class="grid">
<form class="card" method="post" autocomplete="off">
<span class="prod"><i></i> GRANDPRIX V<?=htmlspecialchars(gp_release())?> · SIN POLLING</span><span class="eyebrow">INTEGRACIÓN GPS</span><h1>Servidor Traccar</h1><p>La credencial se guarda sólo en PHP dentro de la carpeta protegida <b>config</b>. El navegador nunca recibe el token.</p>
<?php if ($message): ?><div class="msg"><i class="fa-solid fa-circle-check"></i> <?=htmlspecialchars($message)?></div><?php endif; ?>
<?php if ($error): ?><div class="err"><i class="fa-solid fa-triangle-exclamation"></i> <?=htmlspecialchars($error)?></div><?php endif; ?>
<input type="hidden" name="csrf" value="<?=htmlspecialchars(gp_csrf_token())?>">
<div class="fields">
<label class="full">URL del servidor<input name="base_url" type="url" required value="<?=htmlspecialchars((string) $config['base_url'])?>" placeholder="https://traccar.nevox.pro"></label>
<label class="full">Token de acceso<input name="token" type="password" placeholder="Déjalo vacío para conservar el token incluido"><small style="display:block;color:#718399;margin-top:6px"><?=$masked?> · vence: <?=htmlspecialchars($expiry)?></small></label>
<label>Método de autenticación<select name="auth_mode"><option value="bearer" <?=($config['auth_mode'] ?? 'bearer') === 'bearer' ? 'selected' : ''?>>Bearer token (recomendado)</option><option value="query" <?=($config['auth_mode'] ?? '') === 'query' ? 'selected' : ''?>>Query param ?token=</option></select></label>
<label>Entrega de telemetría<input value="Webhook + WebSocket · sin polling" readonly></label>
<div class="full" style="padding:14px;border:1px solid #bde9df;background:#edfbf7;border-radius:14px"><span class="eyebrow">WEBHOOK DE TRACCAR</span><p style="margin:7px 0 0;color:#42677a;font-size:12px;line-height:1.5">Traccar enviará cada posición y evento al sistema. El navegador no realizará consultas repetitivas.</p></div>
<label class="full">URL del webhook<input value="<?=htmlspecialchars($webhookUrl)?>" readonly onclick="this.select()"><small style="display:block;color:#718399;margin-top:6px">Usa esta misma URL para <b>forward.url</b> y <b>event.forward.url</b>.</small></label>
<label class="full">Secreto de autenticación del webhook<input value="<?=htmlspecialchars($webhookSecretDisplay)?>" readonly onclick="this.select()" placeholder="Se generará al guardar"><small style="display:block;color:#718399;margin-top:6px">Cabecera Traccar: <b>X-Grandprix-Webhook: SECRETO</b>. Nunca coloques este valor en JavaScript.</small></label>
<label class="full">Llave pública de MapTiler<input name="maptiler_key" type="password" placeholder="Déjala vacía para conservar la llave guardada"><small style="display:block;color:#718399;margin-top:6px"><?=!empty($config['maptiler_key']) ? 'Llave MapTiler configurada y protegida contra edición accidental.' : 'Necesaria para el mapa híbrido Satélite Pro. Restríngela a https://grandprixvzla.com/* en MapTiler Cloud.'?></small></label>
<label class="full">Estilo de mapa predeterminado<select name="map_style"><option value="hybrid" <?=($config['map_style'] ?? 'hybrid') === 'hybrid' ? 'selected' : ''?>>Satélite Pro · imágenes + calles</option><option value="streets-v4" <?=in_array(($config['map_style'] ?? ''), ['streets-v4','streets-v2'], true) ? 'selected' : ''?>>Street Premium · calles</option><option value="dataviz-dark" <?=in_array(($config['map_style'] ?? ''), ['dataviz-dark','streets-v2-dark'], true) ? 'selected' : ''?>>Operación nocturna</option></select></label>
<div class="full" style="padding:14px;border:1px solid #cbdffa;background:#f3f8ff;border-radius:14px"><span class="eyebrow">CANAL PUSH PARA PANTALLAS ABIERTAS</span><p style="margin:7px 0 0;color:#42677a;font-size:12px;line-height:1.5">Pusher Channels entrega el movimiento mediante WebSocket privado. El cliente queda limitado exclusivamente a WebSocket; no usa fallback HTTP.</p></div>
<label class="toggle full"><input type="checkbox" name="realtime_enabled" <?=!empty($config['realtime_enabled']) ? 'checked' : ''?>><span><b>Activar movimiento instantáneo por WebSocket</b>Recomendado para que el mapa y el velocímetro cambien al llegar cada webhook.</span></label>
<label>Pusher App ID<input name="pusher_app_id" value="<?=htmlspecialchars((string) ($config['pusher_app_id'] ?? ''))?>" placeholder="Ej. 1234567"></label>
<label>Pusher Cluster<input name="pusher_cluster" value="<?=htmlspecialchars((string) ($config['pusher_cluster'] ?? 'mt1'))?>" placeholder="Ej. mt1"></label>
<label class="full">Pusher App Key<input name="pusher_key" value="<?=htmlspecialchars((string) ($config['pusher_key'] ?? ''))?>" placeholder="App Key pública"></label>
<label class="full">Pusher App Secret<input name="pusher_secret" type="password" placeholder="Déjalo vacío para conservar el secreto"><small style="display:block;color:#718399;margin-top:6px"><?=$pusherSecretMasked?></small></label>
<label>Device ID interno de Yeivert Sánchez<input name="customer_device_id" type="number" min="1" required value="<?=htmlspecialchars((string) (($config['customer_devices']['yeivert-sanchez'] ?? 0) ?: ''))?>" placeholder="Ej. 7"><small style="display:block;color:#718399;margin-top:6px">Usa el campo <b>id</b> numérico de Traccar. No uses 0 ni el IMEI/uniqueId.</small></label>
<label>Código visible de la moto<input name="customer_device_match" maxlength="80" value="<?=htmlspecialchars((string) ($config['customer_device_match'] ?? 'GP-0248'))?>" placeholder="GP-0248"><small style="display:block;color:#718399;margin-top:6px">La asignación definitiva de cada cliente se administra en Portal del cliente.</small></label>
<label class="toggle full"><input type="checkbox" name="allow_commands" <?=!empty($config['allow_commands']) ? 'checked' : ''?>><span><b>Permitir comandos remotos</b>La consola consulta /commands/types y sólo muestra órdenes compatibles con cada GPS.</span></label>
<div class="toggle full"><i class="fa-solid fa-list-check" style="margin-top:3px;color:#1477ff"></i><span><b>Catálogo GT06 cerrado</b>V7.2 permite únicamente las 25 plantillas verificadas del manual. No se acepta texto libre desde el frontend.</span></div>
<label class="toggle full"><input type="checkbox" name="customer_portal_live" <?=!empty($config['customer_portal_live']) ? 'checked' : ''?>><span><b>GPS productivo en Mi GRANDPRIX</b>Entrega al portal del cliente únicamente el Device ID que tiene asignado.</span></label>
</div><button class="submit"><i class="fa-solid fa-plug-circle-check"></i> Probar, guardar y activar producción</button>
</form>
<aside class="card status"><span class="eyebrow">SEGURIDAD Y OPERACIÓN</span><h2>Integración protegida</h2>
<div class="row"><i class="fa-solid fa-code-branch"></i><div><b>Versión activa <?=htmlspecialchars(gp_release())?></b><span>Si ves este texto, Hostinger reemplazó correctamente el instalador.</span></div></div>
<div class="row"><i class="fa-solid fa-database"></i><div><b>Snapshot: <?=$telemetryDeviceCount?> GPS · <?=$telemetryPositionCount?> posición(es)</b><span>Catálogo: <?=htmlspecialchars($catalogSyncedAt ? date('d/m/Y H:i:s', strtotime((string) $catalogSyncedAt)) : 'pendiente')?> · webhook: <?=htmlspecialchars($lastWebhookAt ? date('d/m/Y H:i:s', strtotime((string) $lastWebhookAt)) : 'pendiente')?></span></div></div>
<div class="row"><i class="fa-solid fa-webhook"></i><div><b>Traccar envía los datos</b><span>Posiciones y eventos entran por webhook autenticado.</span></div></div>
<div class="row"><i class="fa-solid fa-bolt"></i><div><b>Cero polling</b><span>Sin temporizadores, long-polling ni consultas periódicas a Traccar.</span></div></div>
<div class="row"><i class="fa-solid fa-tower-broadcast"></i><div><b>WebSocket privado</b><span>Sesión GRANDPRIX autoriza la flota o sólo la moto asignada.</span></div></div>
<div class="row"><i class="fa-solid fa-layer-group"></i><div><b>MapTiler + MapLibre</b><span>Satélite híbrido, calles, modo nocturno y atribución profesional.</span></div></div>
<div class="row"><i class="fa-solid fa-list-check"></i><div><b>Compatibilidad dinámica</b><span>Comandos consultados por Device ID y canal de datos/SMS.</span></div></div>
<div class="row"><i class="fa-solid fa-shield-halved"></i><div><b>Apagado protegido</b><span>Sesión, CSRF, confirmación, auditoría y velocidad máxima de 1 km/h.</span></div></div>
<div class="note expiry"><i class="fa-solid fa-clock"></i> Vencimiento registrado del token: <b><?=htmlspecialchars($expiry)?></b>. Sustitúyelo antes de esa fecha para evitar interrupciones.</div>
<div class="note"><i class="fa-solid fa-code"></i> Configura en Traccar <b>forward.type=json</b>, <b>forward.url</b>, <b>event.forward.type=json</b>, <b>event.forward.url</b> y la cabecera secreta mostrada en este formulario.</div>
<div class="note"><i class="fa-solid fa-key"></i> La credencial fue compartida por mensajería. Para la operación definitiva conviene revocarla y generar una nueva desde Traccar después de esta presentación.</div>
<?php if ($devices): ?><div class="devices"><b>Dispositivos detectados</b><?php foreach ($devices as $device): ?><div class="device"><b>ID <?=htmlspecialchars((string) ($device['id'] ?? ''))?> · <?=htmlspecialchars((string) ($device['name'] ?? 'Sin nombre'))?></b><span>Identificador: <?=htmlspecialchars((string) ($device['uniqueId'] ?? ''))?> · <?=htmlspecialchars((string) ($device['status'] ?? 'unknown'))?></span></div><?php endforeach; ?></div><?php endif; ?>
</aside></div></div></body></html>
