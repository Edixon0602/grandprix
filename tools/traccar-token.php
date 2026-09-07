<?php
declare(strict_types=1);

/**
 * GRANDPRIX: Herramienta CLI para gestión y renovación dinámica de tokens API en Traccar.
 * 
 * Uso:
 *   php tools/traccar-token.php --generate --user=admin@example.com --password=Secret [--days=365] [--url=https://traccar.grandprixvzla.com]
 *   php tools/traccar-token.php --check
 *   php tools/traccar-token.php --help
 */

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/TraccarClient.php';

$options = getopt('', ['generate', 'check', 'user:', 'password:', 'days:', 'url:', 'help']);

if (isset($options['help']) || (empty($options) && $argc <= 1)) {
    echo "========================================================\n";
    echo "GRANDPRIX · Gestor de Tokens API Traccar\n";
    echo "========================================================\n\n";
    echo "Comandos disponibles:\n";
    echo "  --generate              Genera un nuevo token API mediante POST /api/session/token\n";
    echo "  --user=EMAIL            Usuario / Email de Traccar (o variable TRACCAR_ADMIN_USER)\n";
    echo "  --password=CLAVE        Contraseña de Traccar (o variable TRACCAR_ADMIN_PASSWORD)\n";
    echo "  --days=DIAS             Vigencia en días (por defecto: 365)\n";
    echo "  --url=URL               URL base de Traccar (por defecto: la configurada en config/traccar.php)\n";
    echo "  --check                 Verifica el estado y vigencia del token actual\n";
    echo "  --help                  Muestra esta ayuda\n\n";
    exit(0);
}

$configPath = dirname(__DIR__) . '/config/traccar.php';
$config = gp_traccar_config();

if (isset($options['check'])) {
    echo "========================================================\n";
    echo "Estado del Token Traccar en config/traccar.php\n";
    echo "========================================================\n";
    echo "Servidor:    " . ($config['base_url'] ?? 'No configurado') . "\n";
    
    $token = (string) ($config['token'] ?? '');
    if ($token === '') {
        echo "Token:       [NO CONFIGURADO]\n";
        exit(1);
    }
    echo "Token:       ..." . substr($token, -12) . " (longitud: " . strlen($token) . ")\n";
    
    $expires = $config['token_expires_at'] ?? null;
    if ($expires) {
        $timestamp = strtotime((string) $expires);
        $diffDays = (int) ceil(($timestamp - time()) / 86400);
        echo "Expiración:  " . date('Y-m-d H:i:s T', $timestamp) . " ($diffDays días restantes)\n";
        if ($diffDays <= 0) {
            echo "ALERTA:      El token HA EXPIRADO.\n";
            exit(2);
        } elseif ($diffDays < 15) {
            echo "ADVERTENCIA: El token está próximo a vencer (menos de 15 días).\n";
            exit(1);
        } else {
            echo "Estado:      Token vigente y operativo.\n";
            exit(0);
        }
    } else {
        echo "Expiración:  No registrada en la configuración local.\n";
        exit(0);
    }
}

if (isset($options['generate'])) {
    $user = (string) ($options['user'] ?? getenv('TRACCAR_ADMIN_USER') ?: ($_ENV['TRACCAR_ADMIN_USER'] ?? ''));
    $password = (string) ($options['password'] ?? getenv('TRACCAR_ADMIN_PASSWORD') ?: ($_ENV['TRACCAR_ADMIN_PASSWORD'] ?? ''));
    $days = (int) ($options['days'] ?? 365);
    if ($days <= 0) $days = 365;
    
    $baseUrl = (string) ($options['url'] ?? $config['base_url'] ?? 'https://traccar.grandprixvzla.com');

    if ($user === '' || $password === '') {
        fwrite(STDERR, "Error: Debes indicar --user y --password (o definir TRACCAR_ADMIN_USER y TRACCAR_ADMIN_PASSWORD).\n");
        exit(1);
    }

    $expirationIso = gmdate('Y-m-d\TH:i:s\Z', strtotime("+{$days} days"));

    echo "Solicitando token a {$baseUrl}/api/session/token para {$user} (vigencia: {$days} días)...\n";

    try {
        $token = TraccarClient::requestApiToken($baseUrl, $user, $password, $expirationIso);
        echo "Token generado con éxito.\n";

        // Probar el token inmediatamente
        $testConfig = array_merge($config, [
            'base_url' => $baseUrl,
            'token' => $token,
            'token_expires_at' => $expirationIso,
        ]);
        $client = new TraccarClient($testConfig);
        $devices = $client->get('/devices');
        $deviceCount = is_array($devices) ? count($devices) : 0;
        echo "Verificación API exitosa: {$deviceCount} dispositivo(s) visible(s).\n";

        // Guardar en config/traccar.php
        $config['base_url'] = $baseUrl;
        $config['token'] = $token;
        $config['token_expires_at'] = $expirationIso;
        $config['updated_at'] = date(DATE_ATOM);

        $php = "<?php\n// Generado por tools/traccar-token.php\nreturn " . var_export($config, true) . ";\n";
        if (file_put_contents($configPath, $php, LOCK_EX) === false) {
            throw new RuntimeException("No se pudo escribir en {$configPath}.");
        }
        @chmod($configPath, 0640);

        echo "Configuración actualizada en {$configPath}.\n";
        echo "Fecha de expiración: {$expirationIso}\n";
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, "Error al generar o verificar el token: " . $e->getMessage() . "\n");
        exit(1);
    }
}
