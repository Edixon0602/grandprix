<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/Database.php';
require_once dirname(__DIR__) . '/lib/TraccarClient.php';
require_once dirname(__DIR__) . '/lib/TelemetryStore.php';
require_once dirname(__DIR__) . '/lib/GpsPresentation.php';
require_once dirname(__DIR__) . '/lib/RealtimePublisher.php';
require_once dirname(__DIR__) . '/lib/SecretBox.php';
require_once dirname(__DIR__) . '/lib/Gt06CommandCatalog.php';
require_once dirname(__DIR__) . '/lib/CommandService.php';
require_once dirname(__DIR__) . '/lib/EventAudit.php';

gp_start_session();
gp_require_admin(true);
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

if (!Database::configured()) gp_json(['ok' => false, 'error' => 'Ejecuta la actualizacion V7.2 para preparar comandos protegidos.'], 503);
$config = gp_traccar_config();
$action = strtolower(trim((string) ($_GET['action'] ?? 'fleet')));
$actor=gp_current_admin();

try {
    ensureCommandSnapshot($config);
    $service = new CommandService($config);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        switch ($action) {
            case 'fleet':
                gp_json(['ok' => true, 'version' => gp_release(), 'polling' => false, 'devices' => $service->fleet()]);
            case 'catalog':
                $deviceId = commandPositiveInt($_GET['deviceId'] ?? null, 'deviceId');
                gp_json(['ok' => true, 'version' => gp_release(), 'polling' => false] + $service->catalog($deviceId));
            case 'audit':
                gp_json(['ok' => true, 'version' => gp_release(), 'polling' => false, 'events' => $service->audit(60)]);
            default:
                gp_json(['ok' => false, 'error' => 'Accion de comandos no reconocida.'], 404);
        }
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') gp_json(['ok' => false, 'error' => 'Metodo no permitido.'], 405);
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if (!gp_verify_csrf(is_string($csrf) ? $csrf : null)) gp_json(['ok' => false, 'error' => 'Sesion de seguridad vencida.'], 419);
    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > 65536) gp_json(['ok' => false, 'error' => 'Solicitud demasiado grande.'], 413);
    $input = json_decode($raw, true);
    if (!is_array($input)) gp_json(['ok' => false, 'error' => 'Solicitud JSON invalida.'], 400);

    if ($action === 'configure') {
        verifyAdminReauthentication((string) ($input['adminPassword'] ?? ''));
        $deviceId = commandPositiveInt($input['deviceId'] ?? null, 'deviceId');
        $result=$service->configureDevice($deviceId,$input);
        EventAudit::recordAdmin($actor,'gps','configure_device','update','traccar_device',$deviceId,'Configuración técnica de dispositivo GPS actualizada.');
        gp_json(['ok' => true, 'message' => 'Configuracion tecnica guardada.'] + $result);
    }

    if ($action === 'dispatch') {
        $last = (float) ($_SESSION['grandprix_last_command_at'] ?? 0);
        if ($last > 0 && microtime(true) - $last < 4.0) gp_json(['ok' => false, 'error' => 'Espera cuatro segundos antes de enviar otra orden.'], 429);
        if (($input['confirmation'] ?? '') !== 'CONFIRMAR') gp_json(['ok' => false, 'error' => 'Falta la confirmacion explicita del comando.'], 422);
        $deviceId = commandPositiveInt($input['deviceId'] ?? null, 'deviceId');
        $commandKey = (string) ($input['commandKey'] ?? '');
        $entry = Gt06CommandCatalog::get($commandKey);
        $reason = mb_substr(trim((string) ($input['reason'] ?? '')), 0, 300);
        if (in_array($entry['risk'], ['high', 'critical'], true)) {
            if (mb_strlen($reason) < 8) gp_json(['ok' => false, 'error' => 'Indica el motivo operativo del comando.'], 422);
            verifyAdminReauthentication((string) ($input['adminPassword'] ?? ''));
        }
        if ($entry['risk'] === 'critical' && ($input['authorizationPhrase'] ?? '') !== 'AUTORIZAR ' . $deviceId) {
            gp_json(['ok' => false, 'error' => 'La frase de autorizacion critica no coincide.'], 422);
        }
        $result = $service->dispatch(
            $deviceId,
            $commandKey,
            is_array($input['params'] ?? null) ? $input['params'] : [],
            (string) ($input['channel'] ?? 'auto'),
            $reason,
            (string) ($_SESSION['grandprix_admin_email'] ?? 'admin')
        );
        $_SESSION['grandprix_last_command_at'] = microtime(true);
        if (RealtimePublisher::configured($config)) {
            RealtimePublisher::publish($config, 'gps-command-status', $result, ['private-grandprix-fleet']);
        }
        EventAudit::recordAdmin($actor,'gps','dispatch_command','workflow','traccar_device',$deviceId,'Comando GPS enviado desde el Centro de comandos.',['command'=>$commandKey,'risk'=>$entry['risk'],'reason'=>$reason]);
        gp_json(['ok' => true] + $result);
    }

    gp_json(['ok' => false, 'error' => 'Accion de comandos no reconocida.'], 404);
} catch (InvalidArgumentException $error) {
    gp_json(['ok' => false, 'error' => safeCommandError($error->getMessage())], 422);
} catch (RuntimeException $error) {
    gp_json(['ok' => false, 'error' => safeCommandError($error->getMessage())], 409);
} catch (Throwable) {
    gp_json(['ok' => false, 'error' => 'No fue posible procesar el comando GPS.'], 500);
}

function verifyAdminReauthentication(string $password): void
{
    $app = gp_app_config();
    if ($password === '' || !password_verify($password, (string) ($app['password_hash'] ?? ''))) {
        gp_json(['ok' => false, 'error' => 'La contraseña administrativa no coincide.'], 403);
    }
}

function commandPositiveInt(mixed $value, string $name): int
{
    $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($number === false) throw new InvalidArgumentException('Parametro ' . $name . ' invalido.');
    return (int) $number;
}

function safeCommandError(string $message): string
{
    return mb_substr((string) preg_replace('/(?<!\d)\d{6}(?!\d)/', '[clave protegida]', $message), 0, 420);
}

function ensureCommandSnapshot(array $config): void
{
    $store = new TelemetryStore();
    $snapshot = $store->snapshot();
    if (count(array_filter((array) ($snapshot['devices'] ?? []), 'is_array')) > 0) return;
    $guardPath = dirname(__DIR__) . '/config/runtime/command-cold-start.lock';
    $guard = @fopen($guardPath, 'c+');
    if ($guard === false || !flock($guard, LOCK_EX | LOCK_NB)) {
        if (is_resource($guard)) fclose($guard);
        return;
    }
    try {
        $client = new TraccarClient($config);
        $store->bootstrap((array) $client->get('/devices'), (array) $client->get('/positions'));
    } finally {
        flock($guard, LOCK_UN);
        fclose($guard);
    }
}
