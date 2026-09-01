<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/TelemetryStore.php';
require_once dirname(__DIR__) . '/lib/RealtimePublisher.php';
require_once dirname(__DIR__) . '/lib/Database.php';
require_once dirname(__DIR__) . '/lib/CustomerPortal.php';

gp_start_session();
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') gp_json(['error' => 'Metodo no permitido.'], 405);
$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if (!gp_verify_csrf(is_string($csrf) ? $csrf : null)) gp_json(['error' => 'Sesion de seguridad vencida.'], 419);

$config = gp_traccar_config();
if (!RealtimePublisher::configured($config)) gp_json(['error' => 'Canal Realtime no configurado.'], 503);
$socketId = trim((string) ($_POST['socket_id'] ?? ''));
$channel = trim((string) ($_POST['channel_name'] ?? ''));

$allowed = false;
if (gp_is_admin()) {
    $allowed = $channel === 'private-grandprix-fleet'
        || preg_match('/^private-grandprix-device-\d+$/', $channel) === 1;
} else {
    $customerId = (int) ($_SESSION['grandprix_customer_id'] ?? 0);
    $deviceId = null;
    if ($customerId > 0 && Database::configured()) {
        $deviceId = (new CustomerPortal())->assignedDeviceId($customerId);
    }
    $allowed = $deviceId !== null && $channel === 'private-grandprix-device-' . $deviceId;
}
if (!$allowed) gp_json(['error' => 'No tienes permiso para este canal GPS.'], 403);

try {
    gp_json(['auth' => RealtimePublisher::authorize($config, $socketId, $channel)]);
} catch (InvalidArgumentException $error) {
    gp_json(['error' => $error->getMessage()], 422);
} catch (Throwable) {
    gp_json(['error' => 'No fue posible autorizar el canal Realtime.'], 500);
}
