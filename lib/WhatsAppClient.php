<?php
declare(strict_types=1);

/**
 * Cliente HTTP hacia FlowBot para envío de plantillas de WhatsApp.
 * Soporta plantillas de texto (JSON) y plantillas con documento (multipart).
 */
final class WhatsAppClient
{
    private const ENDPOINT_PATH = '/api/v1/whatsapp/templates/send';

    private string $baseUrl;
    private string $apiKey;

    public function __construct(array $config)
    {
        $base = rtrim((string) ($config['flowbot_base_url'] ?? ''), '/');
        $allowInsecure = !empty($config['flowbot_allow_insecure']);
        $httpsOk = (bool) preg_match('~^https://[a-z0-9.-]+(?::\d+)?(?:/.*)?$~i', $base);
        $httpOk = $allowInsecure && (bool) preg_match('~^http://[a-z0-9.-]+(?::\d+)?(?:/.*)?$~i', $base);
        if ($base === '' || (!$httpsOk && !$httpOk)) {
            throw new RuntimeException('La URL base de FlowBot debe usar HTTPS' . ($allowInsecure ? ' (o HTTP solo en pruebas locales)' : '.'));
        }
        $this->baseUrl = $base;
        $this->apiKey = trim((string) ($config['flowbot_api_key'] ?? ''));
        if ($this->apiKey === '') {
            throw new RuntimeException('Falta configurar la API Key de FlowBot.');
        }
    }

    public function sendTemplate(array $payload): array
    {
        return $this->requestJson($payload);
    }

    public function sendTemplateWithDocument(array $payload, string $pdfBytes, string $filename): array
    {
        return $this->requestMultipart($payload, $pdfBytes, $filename);
    }

    private function endpoint(): string
    {
        return $this->baseUrl . self::ENDPOINT_PATH;
    }

    private function requestJson(array $payload): array
    {
        $this->assertCurl();
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $curl = curl_init($this->endpoint());
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'X-Api-Key: ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'GRANDPRIX-Control-360/' . gp_release(),
        ]);
        return $this->execute($curl);
    }

    private function requestMultipart(array $payload, string $pdfBytes, string $filename): array
    {
        $this->assertCurl();
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?: 'recibo.pdf';
        if (!str_ends_with($filename, '.pdf')) $filename .= '.pdf';
        $payload['media'] = ['filename' => $filename];

        $boundary = '----GPWhatsApp' . bin2hex(random_bytes(8));
        $crlf = "\r\n";
        $body = '';
        $body .= '--' . $boundary . $crlf;
        $body .= 'Content-Disposition: form-data; name="payload"' . $crlf;
        $body .= 'Content-Type: application/json' . $crlf . $crlf;
        $body .= json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . $crlf;
        $body .= '--' . $boundary . $crlf;
        $body .= 'Content-Disposition: form-data; name="file"; filename="' . $filename . '"' . $crlf;
        $body .= 'Content-Type: application/pdf' . $crlf . $crlf;
        $body .= $pdfBytes . $crlf;
        $body .= '--' . $boundary . '--' . $crlf;

        $curl = curl_init($this->endpoint());
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'X-Api-Key: ' . $this->apiKey,
                'Content-Type: multipart/form-data; boundary=' . $boundary,
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'GRANDPRIX-Control-360/' . gp_release(),
        ]);
        return $this->execute($curl);
    }

    private function execute($curl): array
    {
        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if ($raw === false) {
            throw new RuntimeException('No fue posible contactar FlowBot: ' . $error);
        }
        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('FlowBot devolvió una respuesta no válida (HTTP ' . $status . ').');
        }
        if ($status < 200 || $status >= 300 || empty($data['ok'])) {
            $message = trim((string) ($data['error'] ?? 'Error desconocido de FlowBot.'));
            throw new RuntimeException('FlowBot respondió HTTP ' . $status . ': ' . mb_substr($message, 0, 240));
        }
        return $data;
    }

    private function assertCurl(): void
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('El servidor necesita la extensión PHP cURL para conectar con FlowBot.');
        }
    }
}
