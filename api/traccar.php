<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/TraccarClient.php';
require_once dirname(__DIR__) . '/lib/TelemetryStore.php';
require_once dirname(__DIR__) . '/lib/RealtimePublisher.php';
require_once dirname(__DIR__) . '/lib/GpsPresentation.php';
require_once dirname(__DIR__) . '/lib/Database.php';
require_once dirname(__DIR__) . '/lib/CustomerPortal.php';

gp_start_session();
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
$action = strtolower(trim((string) ($_GET['action'] ?? 'status')));
$config = gp_traccar_config();

if (empty($config['enabled']) || empty($config['production_mode'])) {
    gp_json(['ok' => false, 'mode' => 'production', 'configured' => false, 'error' => 'La conexión de producción con Traccar no está configurada.'], 503);
}

try {
    $store = new TelemetryStore();
    $snapshot = $store->snapshot();

    if ($action === 'customer-position') {
        if (!Database::configured()) gp_json(['ok' => false, 'error' => 'El portal de clientes V7.2 no está configurado.'], 503);
        $portal = new CustomerPortal();
        $customerId = (int) ($_SESSION['grandprix_customer_id'] ?? 0);
        if ($customerId < 1 && gp_is_admin() && !empty($config['customer_portal_live'])) {
            $customerId = (int) (($portal->previewCustomer((int) ($_SESSION['grandprix_preview_customer_id'] ?? 0))['id'] ?? 0));
        }
        if ($customerId < 1) gp_json(['ok' => false, 'error' => 'Sesión de cliente requerida.'], 401);
        $snapshot = warmTelemetrySnapshot($store, $snapshot, $config, $action);
        $assignment = $portal->assignedGps($customerId);
        if (!$assignment) gp_json(['ok' => false, 'error' => 'El contrato no tiene un GPS asignado.'], 404);
        $deviceId = (int) $assignment['deviceId'];
        $uniqueId = trim((string) ($assignment['uniqueId'] ?? ''));
        $device = $store->resolveDevice($deviceId, false, '');
        if ($device && $uniqueId !== '' && !hash_equals($uniqueId, (string) ($device['uniqueId'] ?? ''))) {
            // Un ID interno puede reutilizarse si el dispositivo fue borrado y
            // recreado en Traccar. El Unique ID evita mostrar otra moto.
            $device = null;
        }
        if (!$device && $uniqueId !== '') $device = $store->resolveDevice($uniqueId, false, '');

        // Rescate de una sola lectura cuando falta el equipo asignado en la
        // memoria local. Tiene bloqueo/cooldown y nunca se ejecuta en bucle.
        if (!$device) {
            $snapshot = warmAssignedTelemetrySnapshot($store, $snapshot, $config, $deviceId, $uniqueId);
            $device = $store->resolveDevice($deviceId, false, '');
            if ($device && $uniqueId !== '' && !hash_equals($uniqueId, (string) ($device['uniqueId'] ?? ''))) $device = null;
            if (!$device && $uniqueId !== '') $device = $store->resolveDevice($uniqueId, false, '');
        }
        if (!$device) {
            $reference = gp_runtime_error('customer-gps-assignment', new RuntimeException('Device ID asignado ausente del catalogo Traccar.'), [
                'customerId' => $customerId,
                'assignedDeviceId' => $deviceId,
                'vehicleCode' => $assignment['code'] ?? '',
            ]);
            gp_json([
                'ok' => false,
                'error' => 'La motocicleta está registrada, pero su Device ID no coincide con Traccar. Solicita a GRANDPRIX revisar la asignación.',
                'reference' => $reference,
            ], 409);
        }

        $resolvedDeviceId = (int) ($device['id'] ?? 0);
        if ($resolvedDeviceId < 1) gp_json(['ok' => false, 'error' => 'Traccar devolvió una identidad GPS inválida.'], 502);
        if ($resolvedDeviceId !== $deviceId && $uniqueId !== '' && hash_equals($uniqueId, (string) ($device['uniqueId'] ?? ''))) {
            // El Unique ID/IMEI previamente verificado permanece igual, pero
            // Traccar recreó su ID interno. Se reconcilia sin ampliar permisos.
            try {
                $portal->updateAssignedGps($customerId, $resolvedDeviceId, $uniqueId);
                $deviceId = $resolvedDeviceId;
            } catch (Throwable $syncError) {
                gp_runtime_error('customer-gps-reconcile', $syncError, ['customerId' => $customerId, 'deviceId' => $resolvedDeviceId]);
            }
        } else {
            $deviceId = $resolvedDeviceId;
        }
        $position = GpsPresentation::findPosition((array) $snapshot['positions'], (int) ($device['id'] ?? 0));
        $dashboard = $portal->dashboard($customerId);
        gp_json([
            'ok' => true,
            'version' => gp_release(),
            'mode' => 'production',
            'delivery' => 'webhook-websocket',
            'polling' => false,
            'mapConfig' => GpsPresentation::mapConfig($config),
            'realtime' => RealtimePublisher::publicConfig(
                $config,
                'private-grandprix-device-' . (int) $device['id'],
                '../api/realtime-auth.php'
            ),
            'lastWebhookAt' => $snapshot['meta']['lastWebhookAt'] ?? null,
            'device' => GpsPresentation::customerDevice((array) $device, $position, [
                'code' => $dashboard['vehicle']['code'] ?? null,
                'model' => $dashboard['vehicle']['model'] ?? null,
            ]),
        ]);
    }

    gp_require_admin(true);
    $snapshot = warmTelemetrySnapshot($store, $snapshot, $config, $action);

    switch ($action) {
        case 'status':
            $devices = array_values(array_filter((array) $snapshot['devices'], 'is_array'));
            $onlineCount = count(array_filter($devices, static fn($device) => is_array($device) && ($device['status'] ?? '') === 'online'));
            gp_json([
                'ok' => true,
                'version' => gp_release(),
                'mode' => 'production',
                'configured' => true,
                'deviceCount' => count($devices),
                'onlineCount' => $onlineCount,
                'commandsEnabled' => !empty($config['allow_commands']),
                'polling' => false,
                'delivery' => 'webhook-websocket',
                'webhookEnabled' => !empty($config['webhook_enabled']),
                'realtimeEnabled' => RealtimePublisher::configured($config),
                'lastWebhookAt' => $snapshot['meta']['lastWebhookAt'] ?? null,
                'mapConfig' => GpsPresentation::mapConfig($config),
                'tokenExpiresAt' => $config['token_expires_at'] ?? null,
                'tokenExpired' => !empty($config['token_expires_at']) && strtotime((string) $config['token_expires_at']) <= time(),
            ]);

        case 'fleet':
            $devices = array_values(array_filter((array) $snapshot['devices'], 'is_array'));
            $positions = (array) $snapshot['positions'];
            $items = [];
            foreach ($devices as $device) {
                if (!is_array($device)) continue;
                $items[] = GpsPresentation::device($device, GpsPresentation::findPosition($positions, (int) ($device['id'] ?? 0)));
            }
            gp_json([
                'ok' => true,
                'version' => gp_release(),
                'mode' => 'production',
                'polling' => false,
                'delivery' => 'webhook-websocket',
                'commandsEnabled' => !empty($config['allow_commands']),
                'mapConfig' => GpsPresentation::mapConfig($config),
                'realtime' => RealtimePublisher::publicConfig(
                    $config,
                    'private-grandprix-fleet',
                    'api/realtime-auth.php'
                ),
                'lastWebhookAt' => $snapshot['meta']['lastWebhookAt'] ?? null,
                'devices' => $items,
            ]);

        case 'groups':
            gp_json(['ok' => true, 'version' => gp_release(), 'mode' => 'production', 'polling' => false, 'groups' => (array) $snapshot['groups']]);

        case 'geofences':
            gp_json(['ok' => true, 'version' => gp_release(), 'mode' => 'production', 'polling' => false, 'geofences' => (array) $snapshot['geofences']]);

        case 'route':
            $client = new TraccarClient($config);
            $deviceId = positiveInt($_GET['deviceId'] ?? null, 'deviceId');
            $from = isoDate($_GET['from'] ?? null, 'from');
            $to = isoDate($_GET['to'] ?? null, 'to');
            if ((strtotime($to) - strtotime($from)) > 31 * 86400) {
                gp_json(['ok' => false, 'error' => 'El rango máximo por consulta es de 31 días.'], 422);
            }
            $route = (array) $client->get('/reports/route', ['deviceId' => $deviceId, 'from' => $from, 'to' => $to]);
            gp_json(['ok' => true, 'version' => gp_release(), 'mode' => 'production', 'route' => array_map(
                static fn(array $position): array => GpsPresentation::position($position),
                array_values(array_filter($route, 'is_array'))
            )]);

        case 'command-types':
            $client = new TraccarClient($config);
            $deviceId = positiveInt($_GET['deviceId'] ?? null, 'deviceId');
            $textChannel = filter_var($_GET['textChannel'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $types = (array) $client->get('/commands/types', ['deviceId' => $deviceId, 'textChannel' => $textChannel ? 'true' : 'false']);
            if (empty($config['allow_custom_commands'])) {
                $types = array_values(array_filter($types, static function ($item): bool {
                    $type = is_array($item) ? ($item['type'] ?? '') : (is_string($item) ? $item : '');
                    return $type !== 'custom';
                }));
            }
            gp_json(['ok' => true, 'version' => gp_release(), 'mode' => 'production', 'types' => $types, 'textChannel' => $textChannel]);

        case 'command-audit':
            gp_json(['ok' => true, 'version' => gp_release(), 'mode' => 'production', 'events' => readCommandAudit()]);

        case 'command':
            gp_json([
                'ok' => false,
                'error' => 'Endpoint legado deshabilitado. Usa el Centro de comandos V7.2 con catalogo GT06 y auditoria.',
            ], 410);

        default:
            gp_json(['ok' => false, 'error' => 'Acción no reconocida.'], 404);
    }
} catch (RuntimeException $error) {
    gp_json(['ok' => false, 'mode' => 'production', 'error' => $error->getMessage()], 502);
} catch (Throwable $error) {
    $reference = gp_runtime_error('traccar-api', $error, ['action' => $action]);
    gp_json(['ok' => false, 'error' => 'Error interno al procesar la conexión GPS. Referencia: ' . $reference . '.'], 500);
}

function positiveInt(mixed $value, string $name): int
{
    $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($number === false) gp_json(['ok' => false, 'error' => 'Parámetro ' . $name . ' inválido.'], 422);
    return (int) $number;
}

/**
 * Migra de forma segura una instalación parcial: si la memoria local nunca se
 * inicializó, realiza una única lectura de dispositivos/posiciones de Traccar.
 * El guard no bloqueante evita que varias pestañas disparen la misma carga.
 * Una vez creado el snapshot, todas las actualizaciones vuelven a entrar sólo
 * por webhook; esto no es polling ni se ejecuta en intervalos.
 */
function warmTelemetrySnapshot(TelemetryStore $store, array $snapshot, array $config, string $action): array
{
    if (!in_array($action, ['status', 'fleet', 'groups', 'geofences', 'customer-position'], true)) return $snapshot;
    $hasDevices = count(array_filter((array) ($snapshot['devices'] ?? []), 'is_array')) > 0;
    $hasPositions = count(array_filter((array) ($snapshot['positions'] ?? []), 'is_array')) > 0;
    if ($hasDevices && $hasPositions) return $snapshot;

    $lastSync = strtotime((string) ($snapshot['meta']['catalogSyncedAt'] ?? ''));
    if ($lastSync !== false && time() - $lastSync < 60) return $snapshot;

    $guardPath = dirname(__DIR__) . '/config/runtime/cold-start.lock';
    $guard = @fopen($guardPath, 'c+');
    if ($guard === false || !flock($guard, LOCK_EX | LOCK_NB)) {
        if (is_resource($guard)) fclose($guard);
        return $snapshot;
    }

    try {
        $latest = $store->snapshot();
        $latestHasDevices = count(array_filter((array) ($latest['devices'] ?? []), 'is_array')) > 0;
        $latestHasPositions = count(array_filter((array) ($latest['positions'] ?? []), 'is_array')) > 0;
        if ($latestHasDevices && $latestHasPositions) return $latest;

        $latestSync = strtotime((string) ($latest['meta']['catalogSyncedAt'] ?? ''));
        if ($latestSync !== false && time() - $latestSync < 60) return $latest;

        $client = new TraccarClient($config);
        $devices = (array) $client->get('/devices');
        $positions = (array) $client->get('/positions');
        return $store->bootstrap(
            $devices,
            $positions,
            (array) ($latest['groups'] ?? []),
            (array) ($latest['geofences'] ?? [])
        );
    } finally {
        flock($guard, LOCK_UN);
        fclose($guard);
    }
}

/**
 * Recupera exclusivamente el GPS ya asignado al contrato. No es polling: solo
 * se activa cuando la memoria local carece de ese equipo, con un maximo de una
 * consulta por minuto y Device ID. La operacion normal sigue siendo webhook +
 * WebSocket.
 */
function warmAssignedTelemetrySnapshot(
    TelemetryStore $store,
    array $snapshot,
    array $config,
    int $deviceId,
    string $uniqueId = ''
): array {
    if ($deviceId < 1) return $snapshot;
    $runtime = dirname(__DIR__) . '/config/runtime';
    if (!is_dir($runtime) && !@mkdir($runtime, 0750, true) && !is_dir($runtime)) return $snapshot;
    $guardPath = $runtime . '/customer-gps-' . hash('sha256', $deviceId . '|' . $uniqueId) . '.lock';
    $guard = @fopen($guardPath, 'c+');
    if ($guard === false || !flock($guard, LOCK_EX | LOCK_NB)) {
        if (is_resource($guard)) fclose($guard);
        return $snapshot;
    }

    try {
        rewind($guard);
        $lastAttempt = (int) trim((string) stream_get_contents($guard));
        if ($lastAttempt > 0 && time() - $lastAttempt < 60) return $store->snapshot();
        ftruncate($guard, 0);
        rewind($guard);
        fwrite($guard, (string) time());
        fflush($guard);

        $client = new TraccarClient($config);
        $devices = (array) $client->get('/devices', ['id' => $deviceId]);
        $devices = array_values(array_filter($devices, 'is_array'));
        if ($devices && $uniqueId !== '' && !hash_equals($uniqueId, (string) ($devices[0]['uniqueId'] ?? ''))) {
            $devices = [];
        }
        if (!$devices && $uniqueId !== '') {
            $devices = array_values(array_filter((array) $client->get('/devices', ['uniqueId' => $uniqueId]), 'is_array'));
        }
        if (!$devices) return $store->snapshot();

        $device = $devices[0];
        $resolvedId = (int) ($device['id'] ?? 0);
        $positions = [];
        $positionId = (int) ($device['positionId'] ?? 0);
        if ($positionId > 0) {
            $positions = (array) $client->get('/positions', ['id' => $positionId]);
        }
        $positions = array_values(array_filter($positions, static fn($position): bool =>
            is_array($position) && (int) ($position['deviceId'] ?? 0) === $resolvedId
        ));
        return $store->bootstrap(
            $devices,
            $positions,
            (array) ($snapshot['groups'] ?? []),
            (array) ($snapshot['geofences'] ?? [])
        );
    } finally {
        flock($guard, LOCK_UN);
        fclose($guard);
    }
}

function isoDate(mixed $value, string $name): string
{
    if (!is_string($value) || strtotime($value) === false) gp_json(['ok' => false, 'error' => 'Fecha ' . $name . ' inválida.'], 422);
    return (new DateTimeImmutable($value))->format(DateTimeInterface::ATOM);
}

function sanitizeCommandAttributes(mixed $attributes): array
{
    if (!is_array($attributes)) return [];
    $clean = [];
    foreach ($attributes as $key => $value) {
        if (!is_string($key) || !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,39}$/', $key)) continue;
        if (is_bool($value) || is_int($value) || is_float($value)) {
            $clean[$key] = $value;
        } elseif (is_string($value)) {
            $clean[$key] = mb_substr(trim($value), 0, 500);
        }
    }
    return $clean;
}

function auditCommand(int $deviceId, string $type, mixed $result, bool $textChannel = false): void
{
    $line = json_encode([
        'time' => gmdate('c'),
        'admin' => $_SESSION['grandprix_admin_email'] ?? 'admin',
        'deviceId' => $deviceId,
        'type' => $type,
        'channel' => $textChannel ? 'sms' : 'data',
        'accepted' => $result !== null,
        'ipHash' => hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')),
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    @file_put_contents(dirname(__DIR__) . '/config/traccar-audit.log', $line, FILE_APPEND | LOCK_EX);
}

function readCommandAudit(): array
{
    $path = dirname(__DIR__) . '/config/traccar-audit.log';
    if (!file_exists($path)) return [];
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $events = [];
    foreach (array_slice(array_reverse($lines), 0, 20) as $line) {
        $event = json_decode($line, true);
        if (is_array($event)) {
            unset($event['ipHash']);
            $events[] = $event;
        }
    }
    return $events;
}
