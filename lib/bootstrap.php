<?php
declare(strict_types=1);

// GRANDPRIX opera con hora oficial de Venezuela (UTC-4).
date_default_timezone_set('America/Caracas');

function gp_release(): string
{
    return '22.1.2';
}

/*
 * Hostinger permite activar o desactivar extensiones por version de PHP. El
 * portal no debe quedar en blanco si mbstring no esta disponible: estas
 * compatibilidades mantienen operativo el acceso y dejan el diagnostico
 * visible al administrador. Cuando mbstring existe se usan sus funciones
 * nativas y este bloque no interviene.
 */
if (!function_exists('mb_strtolower')) {
    function mb_strtolower(string $value, ?string $encoding = null): string
    {
        return strtolower($value);
    }
}

if (!function_exists('mb_strtoupper')) {
    function mb_strtoupper(string $value, ?string $encoding = null): string
    {
        return strtoupper($value);
    }
}

if (!function_exists('mb_substr')) {
    function mb_substr(string $value, int $offset, ?int $length = null, ?string $encoding = null): string
    {
        return $length === null ? substr($value, $offset) : substr($value, $offset, $length);
    }
}

function gp_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_name('grandprix360');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function gp_app_config(): array
{
    $path = dirname(__DIR__) . '/config/app.php';
    return file_exists($path) ? (array) require $path : [];
}

function gp_traccar_config(): array
{
    $path = dirname(__DIR__) . '/config/traccar.php';
    $defaults = [
        'enabled' => true,
        'production_mode' => true,
        'base_url' => 'https://traccar.nevox.pro',
        'token' => '',
        'auth_mode' => 'bearer',
        'webhook_enabled' => true,
        'webhook_secret' => '',
        'realtime_enabled' => false,
        'realtime_provider' => 'pusher',
        'pusher_app_id' => '',
        'pusher_key' => '',
        'pusher_secret' => '',
        'pusher_cluster' => 'mt1',
        // La llave MapTiler es publica en el navegador. Restrinjala al dominio en MapTiler Cloud.
        'map_provider' => 'maptiler',
        'maptiler_key' => '',
        'map_style' => 'hybrid',
        'allow_commands' => true,
        'allow_custom_commands' => false,
        'customer_portal_live' => true,
        'customer_auto_assign' => false,
        // V22.1.1: sin asignaciones demo. El portal hereda moto/GPS desde Inventario.
        'customer_device_match' => '',
        'customer_devices' => [],
    ];
    return file_exists($path) ? array_replace($defaults, (array) require $path) : $defaults;
}

function gp_is_admin(): bool
{
    return !empty($_SESSION['grandprix_admin']);
}

function gp_current_admin(): array
{
    gp_start_session();
    return [
        'id' => (int) ($_SESSION['grandprix_admin_user_id'] ?? 0),
        'name' => (string) ($_SESSION['grandprix_admin_name'] ?? 'Administrador GRANDPRIX'),
        'email' => (string) ($_SESSION['grandprix_admin_email'] ?? ''),
        'role' => (string) ($_SESSION['grandprix_admin_role'] ?? 'Administrador'),
        'permissions' => array_values((array) ($_SESSION['grandprix_admin_permissions'] ?? [])),
    ];
}

function gp_refresh_admin_session_if_needed(): bool
{
    gp_start_session();
    if (!gp_is_admin()) return false;
    $userId = (int) ($_SESSION['grandprix_admin_user_id'] ?? 0);
    // Sesiones heredadas V7.2 no se consultan en BD hasta instalar V8 y volver a iniciar sesión.
    if ($userId < 1 || !array_key_exists('grandprix_admin_permissions', $_SESSION)) return true;
    $lastCheck = (int) ($_SESSION['grandprix_admin_access_checked_at'] ?? 0);
    if ($lastCheck > 0 && time() - $lastCheck < 60) return true;
    $_SESSION['grandprix_admin_access_checked_at'] = time();
    try {
        require_once __DIR__ . '/Database.php';
        require_once __DIR__ . '/AdminAuth.php';
        if (!Database::configured() || !AdminAuth::tablesReady()) return true;
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT u.id,u.full_name,u.email,u.status,u.role_id,r.name AS role_name '
            . 'FROM gp_admin_users u LEFT JOIN gp_admin_roles r ON r.id=u.role_id WHERE u.id=? LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row || (string) ($row['status'] ?? '') !== 'active') {
            $_SESSION = [];
            if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
            return false;
        }
        $_SESSION['grandprix_admin_name'] = (string) $row['full_name'];
        $_SESSION['grandprix_admin_email'] = (string) $row['email'];
        $_SESSION['grandprix_admin_role'] = (string) ($row['role_name'] ?: 'Sin rol');
        $_SESSION['grandprix_admin_permissions'] = AdminAuth::effectivePermissions($userId, (int) ($row['role_id'] ?? 0), $pdo);
        return true;
    } catch (Throwable $error) {
        // Si la BD administrativa tiene una incidencia temporal, no alteramos el motor GPS ni expulsamos sesiones válidas por error técnico.
        gp_runtime_error('admin-session-refresh', $error, ['user_id' => $userId]);
        return true;
    }
}

function gp_user_can(string $permission): bool
{
    gp_start_session();
    if (!gp_is_admin()) return false;
    if ($permission === '') return true;

    // El rol Superadministrador siempre representa acceso total al panel.
    // Esto evita ocultar módulos cuando una sesión antigua aún no tiene
    // materializado el comodín '*' dentro de grandprix_admin_permissions.
    $role = mb_strtolower(trim((string) ($_SESSION['grandprix_admin_role'] ?? '')));
    if ($role !== '' && (str_contains($role, 'superadmin') || str_contains($role, 'super administrador'))) {
        return true;
    }

    // Sesiones heredadas de V7.2 conservan acceso total hasta volver a iniciar sesion.
    if (!array_key_exists('grandprix_admin_permissions', $_SESSION)) return true;
    $permissions = array_map('strval', (array) $_SESSION['grandprix_admin_permissions']);
    return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
}

function gp_route_permission(): ?string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $map = [
        '/api/traccar.php' => 'gps.monitor.view',
        '/api/commands.php' => 'gps.commands.execute',
        '/api/realtime-auth.php' => 'gps.monitor.view',
        '/api/customer-admin.php' => 'portal.manage',
    ];
    foreach ($map as $suffix => $permission) {
        if (str_ends_with($script, $suffix)) return $permission;
    }
    return null;
}

function gp_require_admin(bool $json = false): void
{
    gp_start_session();
    if (gp_is_admin() && gp_refresh_admin_session_if_needed()) {
        $permission = gp_route_permission();
        if ($permission === null || gp_user_can($permission)) return;
        if ($json) gp_json(['ok' => false, 'error' => 'No tienes permiso para esta operacion.'], 403);
        http_response_code(403);
        echo 'Acceso no autorizado para este modulo.';
        exit;
    }
    if ($json) {
        gp_json(['ok' => false, 'error' => 'Sesión administrativa requerida.'], 401);
    }
    $target = rawurlencode($_SERVER['REQUEST_URI'] ?? 'index.php');
    header('Location: ' . (str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/install/') ? '../login.php' : 'login.php') . '?next=' . $target);
    exit;
}

function gp_require_permission(string $permission, bool $json = true): void
{
    gp_require_admin($json);
    if (gp_user_can($permission)) return;
    if ($json) gp_json(['ok' => false, 'error' => 'No tienes permiso para esta operacion.'], 403);
    http_response_code(403);
    echo 'Acceso no autorizado.';
    exit;
}

function gp_csrf_token(): string
{
    gp_start_session();
    if (empty($_SESSION['grandprix_csrf'])) {
        $_SESSION['grandprix_csrf'] = bin2hex(random_bytes(24));
    }
    return (string) $_SESSION['grandprix_csrf'];
}

function gp_verify_csrf(?string $token): bool
{
    $stored = gp_csrf_token();
    return is_string($token) && hash_equals($stored, $token);
}

function gp_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Registra un error tecnico sin exponer credenciales al navegador.
 * Devuelve una referencia corta que el usuario puede comunicar a soporte.
 */
function gp_runtime_error(string $channel, Throwable $error, array $context = []): string
{
    $reference = strtoupper(substr(bin2hex(random_bytes(8)), 0, 10));
    $directory = dirname(__DIR__) . '/config/runtime';
    if (!is_dir($directory)) @mkdir($directory, 0750, true);

    $safeContext = [];
    foreach ($context as $key => $value) {
        if (!is_string($key) || preg_match('/password|secret|token|authorization|cookie/i', $key)) continue;
        if (is_scalar($value) || $value === null) $safeContext[$key] = mb_substr((string) $value, 0, 160);
    }

    $record = json_encode([
        'time' => gmdate('c'),
        'reference' => $reference,
        'channel' => preg_replace('/[^a-z0-9_-]/i', '', $channel),
        'type' => get_class($error),
        'message' => mb_substr($error->getMessage(), 0, 500),
        'file' => basename($error->getFile()),
        'line' => $error->getLine(),
        'context' => $safeContext,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if (is_string($record) && is_dir($directory)) {
        $path = $directory . '/portal-errors.log';
        @file_put_contents($path, $record . PHP_EOL, FILE_APPEND | LOCK_EX);
        @chmod($path, 0640);
    }
    return $reference;
}
