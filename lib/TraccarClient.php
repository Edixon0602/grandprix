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
}
