<?php
declare(strict_types=1);

/**
 * Publica telemetria en Pusher Channels mediante HTTPS.
 *
 * El navegador recibe solo app key + cluster. App ID y secret permanecen en
 * config/traccar.php. Los canales son privados y se autorizan con la sesion
 * PHP de GRANDPRIX.
 */
final class RealtimePublisher
{
    public static function configured(array $config): bool
    {
        return !empty($config['realtime_enabled'])
            && ($config['realtime_provider'] ?? 'pusher') === 'pusher'
            && trim((string) ($config['pusher_app_id'] ?? '')) !== ''
            && trim((string) ($config['pusher_key'] ?? '')) !== ''
            && trim((string) ($config['pusher_secret'] ?? '')) !== ''
            && preg_match('/^[a-z0-9-]+$/i', (string) ($config['pusher_cluster'] ?? '')) === 1;
    }

    public static function publicConfig(array $config, string $channel, string $authEndpoint): array
    {
        $enabled = self::configured($config) && $channel !== '';
        return [
            'enabled' => $enabled,
            'provider' => $enabled ? 'pusher' : null,
            'transport' => $enabled ? 'websocket' : null,
            'key' => $enabled ? (string) $config['pusher_key'] : null,
            'cluster' => $enabled ? (string) $config['pusher_cluster'] : null,
            'channel' => $enabled ? $channel : null,
            'authEndpoint' => $enabled ? $authEndpoint : null,
            'polling' => false,
        ];
    }

    /** @return array{ok: bool, status: int, error: ?string} */
    public static function publish(array $config, string $event, array $payload, array $channels): array
    {
        if (!self::configured($config)) {
            return ['ok' => false, 'status' => 0, 'error' => 'Canal Realtime no configurado.'];
        }
        if (!preg_match('/^[A-Za-z0-9_\-=@,.;]+$/', $event)) {
            return ['ok' => false, 'status' => 0, 'error' => 'Nombre de evento Realtime invalido.'];
        }
        $channels = array_values(array_unique(array_filter($channels, static function ($channel): bool {
            return is_string($channel)
                && strlen($channel) <= 164
                && preg_match('/^private-[A-Za-z0-9_\-=@,.;]+$/', $channel) === 1;
        })));
        if (!$channels) return ['ok' => false, 'status' => 0, 'error' => 'No hay canales Realtime validos.'];

        $data = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($data === false) return ['ok' => false, 'status' => 0, 'error' => 'No se pudo codificar el evento Realtime.'];
        $body = json_encode([
            'name' => $event,
            'channels' => array_slice($channels, 0, 100),
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false || strlen($body) > 9500) {
            return ['ok' => false, 'status' => 0, 'error' => 'El evento Realtime supera el limite seguro.'];
        }

        $appId = rawurlencode((string) $config['pusher_app_id']);
        $cluster = strtolower((string) $config['pusher_cluster']);
        $path = '/apps/' . $appId . '/events';
        $params = [
            'auth_key' => (string) $config['pusher_key'],
            'auth_timestamp' => (string) time(),
            'auth_version' => '1.0',
            'body_md5' => md5($body),
        ];
        ksort($params);
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $signature = hash_hmac('sha256', "POST\n" . $path . "\n" . $query, (string) $config['pusher_secret']);
        $url = 'https://api-' . $cluster . '.pusher.com' . $path . '?' . $query . '&auth_signature=' . $signature;

        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT_MS => 900,
                CURLOPT_TIMEOUT_MS => 2200,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $response = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $error = curl_error($curl);
            curl_close($curl);
            if ($response !== false && $status >= 200 && $status < 300) {
                return ['ok' => true, 'status' => $status, 'error' => null];
            }
            return ['ok' => false, 'status' => $status, 'error' => $error !== '' ? $error : 'Pusher respondio HTTP ' . $status . '.'];
        }

        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'content' => $body,
            'timeout' => 2.2,
            'ignore_errors' => true,
        ]]);
        $response = @file_get_contents($url, false, $context);
        $status = 0;
        foreach (($http_response_header ?? []) as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match)) $status = (int) $match[1];
        }
        return $response !== false && $status >= 200 && $status < 300
            ? ['ok' => true, 'status' => $status, 'error' => null]
            : ['ok' => false, 'status' => $status, 'error' => 'No se pudo publicar en Pusher.'];
    }

    public static function authorize(array $config, string $socketId, string $channel): string
    {
        if (!self::configured($config)) throw new RuntimeException('Canal Realtime no configurado.');
        if (preg_match('/^\d+\.\d+$/', $socketId) !== 1) throw new InvalidArgumentException('Socket ID invalido.');
        if (preg_match('/^private-[A-Za-z0-9_\-=@,.;]+$/', $channel) !== 1) throw new InvalidArgumentException('Canal invalido.');
        $signature = hash_hmac('sha256', $socketId . ':' . $channel, (string) $config['pusher_secret']);
        return (string) $config['pusher_key'] . ':' . $signature;
    }
}
