<?php
declare(strict_types=1);

final class TraccarClient
{
    private string $baseUrl;
    private string $token;
    private string $authMode;

    public function __construct(array $config)
    {
        $base = rtrim((string) ($config['base_url'] ?? ''), '/');
        if (!preg_match('~^https://[a-z0-9.-]+(?::\d+)?(?:/.*)?$~i', $base)) {
            throw new RuntimeException('La URL de Traccar debe usar HTTPS.');
        }
        $this->baseUrl = str_ends_with($base, '/api') ? $base : $base . '/api';
        $this->token = trim((string) ($config['token'] ?? ''));
        $this->authMode = in_array(($config['auth_mode'] ?? ''), ['query', 'bearer'], true)
            ? (string) $config['auth_mode'] : 'bearer';
        if ($this->token === '') throw new RuntimeException('Falta configurar el token de Traccar.');
    }

    public function get(string $path, array $query = []): mixed
    {
        return $this->request('GET', $path, $query);
    }

    public function post(string $path, array $body, array $query = []): mixed
    {
        return $this->request('POST', $path, $query, $body);
    }

    public function put(string $path, array $body, array $query = []): mixed
    {
        return $this->request('PUT', $path, $query, $body);
    }

    private function request(string $method, string $path, array $query = [], ?array $body = null): mixed
    {
        $headers = ['Accept: application/json'];
        if ($this->authMode === 'query') {
            $query['token'] = $this->token;
        } else {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        if ($query) $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $encoded = $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded !== null) $headers[] = 'Content-Type: application/json';

        if (!function_exists('curl_init')) {
            throw new RuntimeException('El servidor necesita la extensión PHP cURL para conectar con Traccar.');
        }
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'GRANDPRIX-Control-360/' . gp_release(),
        ]);
        if ($encoded !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, $encoded);
        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if ($raw === false) throw new RuntimeException('No fue posible contactar el servidor GPS: ' . $error);
        if ($status < 200 || $status >= 300) {
            $detail = trim(strip_tags((string) $raw));
            $detail = str_replace($this->token, '[token protegido]', $detail);
            throw new RuntimeException('Traccar respondió HTTP ' . $status . ($detail ? ': ' . mb_substr($detail, 0, 240) : '.'));
        }
        if ($status === 204 || trim((string) $raw) === '') return null;
        try {
            return json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Traccar devolvió una respuesta no válida.');
        }
    }

    /**
     * Genera un token API dinámico en Traccar solicitando POST /api/session/token
     * mediante HTTP Basic Auth (usuario y contraseña) y el parámetro expiration en formato ISO-8601.
     * Devuelve el token en texto plano.
     */
    public static function requestApiToken(
        string $baseUrl,
        string $username,
        string $password,
        string $expirationIso8601
    ): string {
        $base = rtrim(trim($baseUrl), '/');
        if (!preg_match('~^https://[a-z0-9.-]+(?::\d+)?(?:/.*)?$~i', $base)) {
            throw new RuntimeException('La URL de Traccar debe usar HTTPS.');
        }
        $apiBase = str_ends_with($base, '/api') ? $base : $base . '/api';
        $url = $apiBase . '/session/token';

        if (trim($username) === '' || trim($password) === '') {
            throw new InvalidArgumentException('Debes indicar el usuario y la contraseña de Traccar.');
        }

        // Validar formato de la fecha de expiración
        $timestamp = strtotime($expirationIso8601);
        if ($timestamp === false || $timestamp <= time()) {
            throw new InvalidArgumentException('La fecha de expiración debe ser una fecha futura válida en formato ISO-8601.');
        }

        if (!function_exists('curl_init')) {
            throw new RuntimeException('El servidor necesita la extensión PHP cURL para conectar con Traccar.');
        }

        $postFields = http_build_query(['expiration' => $expirationIso8601]);

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_USERPWD => $username . ':' . $password,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: text/plain, application/json, */*',
            ],
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'GRANDPRIX-Control-360/' . (function_exists('gp_release') ? gp_release() : '1.0'),
        ]);

        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($raw === false) {
            throw new RuntimeException('No fue posible contactar el servidor Traccar para generar el token: ' . $error);
        }

        if ($status === 401 || $status === 403) {
            throw new RuntimeException('Credenciales incorrectas en Traccar. Verifica el usuario y la contraseña.');
        }

        if ($status < 200 || $status >= 300) {
            $detail = trim(strip_tags((string) $raw));
            throw new RuntimeException('Traccar respondió HTTP ' . $status . ($detail ? ': ' . mb_substr($detail, 0, 240) : ' al generar el token.'));
        }

        $token = trim((string) $raw);
        if (str_starts_with($token, '"') && str_ends_with($token, '"')) {
            $token = trim($token, '"');
        }

        if ($token === '') {
            throw new RuntimeException('Traccar respondió con un token vacío.');
        }

        return $token;
    }
}
