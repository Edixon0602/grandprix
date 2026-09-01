<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/TelemetryStore.php';
require_once dirname(__DIR__) . '/lib/RealtimePublisher.php';
require_once dirname(__DIR__) . '/lib/GpsPresentation.php';
require_once dirname(__DIR__) . '/lib/Database.php';
require_once dirname(__DIR__) . '/lib/TraccarClient.php';
require_once dirname(__DIR__) . '/lib/SecretBox.php';
require_once dirname(__DIR__) . '/lib/Gt06CommandCatalog.php';
require_once dirname(__DIR__) . '/lib/CommandService.php';

header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    gp_json(['ok' => false, 'error' => 'Metodo no permitido.'], 405);
}

$config = gp_traccar_config();
$secret = trim((string) ($config['webhook_secret'] ?? ''));
if (empty($config['webhook_enabled']) || strlen($secret) < 32) {
    gp_json(['ok' => false, 'error' => 'El receptor de webhooks no esta configurado.'], 503);
}

$provided = trim((string) ($_SERVER['HTTP_X_GRANDPRIX_WEBHOOK'] ?? ''));
if ($provided === '') {
    $authorization = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $match)) $provided = trim($match[1]);
}
if ($provided === '' || !hash_equals($secret, $provided)) {
    gp_json(['ok' => false, 'error' => 'Firma de webhook invalida.'], 401);
}

$length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($length > 262144) gp_json(['ok' => false, 'error' => 'Payload demasiado grande.'], 413);
$raw = file_get_contents('php://input');
if ($raw === false || $raw === '' || strlen($raw) > 262144) {
    gp_json(['ok' => false, 'error' => 'Payload vacio o demasiado grande.'], 400);
}
$payload = json_decode($raw, true, 64, JSON_BIGINT_AS_STRING);
if (!is_array($payload)) gp_json(['ok' => false, 'error' => 'JSON invalido.'], 400);

$device = is_array($payload['device'] ?? null) ? $payload['device'] : null;
$position = is_array($payload['position'] ?? null) ? $payload['position'] : null;
$event = is_array($payload['event'] ?? null) ? $payload['event'] : null;

// Traccar Position Forwarder JSON entrega la posicion como objeto raiz.
if ($position === null && isset($payload['deviceId'], $payload['latitude'], $payload['longitude'])) {
    $position = $payload;
}
// Event Forwarder puede entregar el evento como objeto raiz.
if ($event === null && isset($payload['deviceId'], $payload['type']) && !isset($payload['latitude'])) {
    $event = $payload;
}
if ($position === null && $event === null) {
    gp_json(['ok' => false, 'error' => 'El webhook no contiene una posicion o evento compatible.'], 422);
}

$store = new TelemetryStore();
$accepted = [];
$realtime = [];

try {
    if ($position !== null) {
        $result = $store->acceptPosition($position, $device);
        $accepted['position'] = $result['changed'];
        if ($result['changed']) {
            $publicDevice = GpsPresentation::device($result['device'], $result['position']);
            $deviceId = (int) $publicDevice['id'];
            $realtime['positionAdmin'] = RealtimePublisher::configured($config)
                ? RealtimePublisher::publish($config, 'gps-position', [
                    'source' => 'traccar-webhook',
                    'receivedAt' => gmdate('c'),
                    'device' => $publicDevice,
                ], ['private-grandprix-fleet'])
                : ['ok' => false, 'status' => 0, 'error' => 'Canal Realtime pendiente.'];
            $realtime['positionCustomer'] = RealtimePublisher::configured($config)
                ? RealtimePublisher::publish($config, 'gps-position', [
                    'source' => 'traccar-webhook',
                    'receivedAt' => gmdate('c'),
                    'device' => GpsPresentation::customerDevice($result['device'], $result['position']),
                ], ['private-grandprix-device-' . $deviceId])
                : ['ok' => false, 'status' => 0, 'error' => 'Canal Realtime pendiente.'];
            if (RealtimePublisher::configured($config) && (!$realtime['positionAdmin']['ok'] || !$realtime['positionCustomer']['ok'])) {
                $store->recordRealtimeResult(false, $realtime['positionAdmin']['error'] ?? $realtime['positionCustomer']['error']);
            }
        }
    }

    if ($event !== null) {
        $result = $store->acceptEvent($event, $device, $position);
        $accepted['event'] = $result['changed'];
        if ($result['changed']) {
            $deviceId = (int) ($event['deviceId'] ?? $device['id'] ?? $position['deviceId'] ?? 0);
            $eventPayload = [
                'source' => 'traccar-webhook',
                'receivedAt' => gmdate('c'),
                'event' => GpsPresentation::event($event),
            ];
            if ($result['device']) {
                $eventPayload['device'] = GpsPresentation::device($result['device'], $result['position']);
            }
            $realtime['eventAdmin'] = RealtimePublisher::configured($config)
                ? RealtimePublisher::publish($config, 'gps-event', $eventPayload, ['private-grandprix-fleet'])
                : ['ok' => false, 'status' => 0, 'error' => 'Canal Realtime pendiente.'];
            if ($deviceId > 0) {
                $customerPayload = [
                    'source' => 'traccar-webhook',
                    'receivedAt' => gmdate('c'),
                    'event' => GpsPresentation::customerEvent($event),
                ];
                if ($result['device']) {
                    $customerPayload['device'] = GpsPresentation::customerDevice($result['device'], $result['position']);
                }
                $realtime['eventCustomer'] = RealtimePublisher::configured($config)
                    ? RealtimePublisher::publish($config, 'gps-event', $customerPayload, ['private-grandprix-device-' . $deviceId])
                    : ['ok' => false, 'status' => 0, 'error' => 'Canal Realtime pendiente.'];
            }
            if (RealtimePublisher::configured($config) && !$realtime['eventAdmin']['ok']) {
                $store->recordRealtimeResult(false, $realtime['eventAdmin']['error']);
            }
        }
    }

    // La confirmacion del forwarder nunca depende de MySQL. Si la auditoria de
    // comandos esta temporalmente fuera de servicio, la posicion ya guardada se
    // confirma igualmente para evitar reintentos y sobrecarga de la instancia.
    if (Database::configured()) {
        try {
            $commandStatus = CommandService::consumeWebhook(Database::connection(), $event ?? [], $position);
            if ($commandStatus && RealtimePublisher::configured($config)) {
                $realtime['command'] = RealtimePublisher::publish(
                    $config,
                    'gps-command-status',
                    $commandStatus,
                    ['private-grandprix-fleet']
                );
            }
        } catch (Throwable $commandError) {
            $store->recordRealtimeResult(false, 'Auditoria de comandos: ' . mb_substr($commandError->getMessage(), 0, 180));
        }
    }
} catch (InvalidArgumentException $error) {
    gp_json(['ok' => false, 'error' => $error->getMessage()], 422);
} catch (Throwable) {
    gp_json(['ok' => false, 'error' => 'No fue posible registrar la telemetria.'], 500);
}

// Siempre se confirma a Traccar si la posicion fue guardada. Una interrupcion
// del canal WebSocket no debe crear una tormenta de reintentos del forwarder.
gp_json([
    'ok' => true,
    'version' => gp_release(),
    'accepted' => $accepted,
    'realtimeConfigured' => RealtimePublisher::configured($config),
    'realtimeDelivered' => !array_filter($realtime, static fn($item) => empty($item['ok'])),
]);
