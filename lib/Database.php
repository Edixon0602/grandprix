<?php
declare(strict_types=1);

final class Database
{
    private static ?PDO $connection = null;

    public static function configured(): bool
    {
        $envDatabase = getenv('DB_DATABASE');
        $envUsername = getenv('DB_USERNAME');
        if ($envDatabase !== false && trim((string) $envDatabase) !== '' &&
            $envUsername !== false && trim((string) $envUsername) !== '') {
            return true;
        }

        $path = dirname(__DIR__) . '/config/database.php';
        if (!is_file($path)) return false;
        $config = (array) require $path;
        return trim((string) ($config['database'] ?? '')) !== ''
            && trim((string) ($config['username'] ?? '')) !== '';
    }

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) return self::$connection;

        $host = '';
        $port = 3306;
        $database = '';
        $username = '';
        $password = '';

        // Prioridad 1: Variables de entorno (Docker / Compose)
        $envHost = getenv('DB_HOST');
        $envDatabase = getenv('DB_DATABASE');
        $envUsername = getenv('DB_USERNAME');
        $envPassword = getenv('DB_PASSWORD');
        $envPort = getenv('DB_PORT');

        if ($envDatabase !== false && trim((string) $envDatabase) !== '' &&
            $envUsername !== false && trim((string) $envUsername) !== '') {
            $host = $envHost !== false && trim((string) $envHost) !== '' ? trim((string) $envHost) : 'db';
            $port = $envPort !== false && (int)$envPort > 0 ? (int)$envPort : 3306;
            $database = trim((string) $envDatabase);
            $username = trim((string) $envUsername);
            $password = $envPassword !== false ? (string) $envPassword : '';
        } else {
            // Prioridad 2: Archivo de configuración tradicional
            $path = dirname(__DIR__) . '/config/database.php';
            if (!is_file($path)) {
                throw new RuntimeException('La base de datos de GRANDPRIX V7.2 no ha sido configurada.');
            }
            $config = (array) require $path;
            $host = trim((string) ($config['host'] ?? 'localhost'));
            $port = (int) ($config['port'] ?? 3306);
            $database = trim((string) ($config['database'] ?? ''));
            $username = trim((string) ($config['username'] ?? ''));
            $password = (string) ($config['password'] ?? '');
        }

        if ($host === '' || $database === '' || $username === '' || $port < 1 || $port > 65535) {
            throw new RuntimeException('La configuracion de la base de datos esta incompleta.');
        }

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database);
        try {
            self::$connection = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false,
            ]);
            // Mantiene NOW(), CURRENT_DATE y TIMESTAMP alineados con Venezuela (UTC-4).
            self::$connection->exec("SET time_zone = '-04:00'");
        } catch (PDOException $e) {
            throw new RuntimeException('No fue posible conectar con la base de datos de GRANDPRIX: ' . $e->getMessage());
        }
        return self::$connection;
    }
}
