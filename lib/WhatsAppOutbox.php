<?php
declare(strict_types=1);

require_once __DIR__ . '/WhatsAppClient.php';
require_once __DIR__ . '/PaymentReceiptService.php';
require_once __DIR__ . '/ReceiptPdfRenderer.php';

/**
 * Cola e bitácora de envíos de WhatsApp (outbox).
 *
 * Los eventos se encolan con INSERT IGNORE para que cada semana o recibo se
 * notifique una sola vez (idempotencia). processPending() envía las filas
 * pendientes a FlowBot y las marca como sent/failed; las fallidas se
 * reintentan en la siguiente ejecución del cron.
 */
final class WhatsAppOutbox
{
    private array $config;

    public function __construct(private readonly PDO $pdo)
    {
        $this->config = gp_whatsapp_config();
    }

    public static function create(): self
    {
        return new self(Database::connection());
    }

    public function ready(): bool
    {
        try {
            return (bool) $this->pdo->query("SHOW TABLES LIKE 'gp_whatsapp_log'")->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    public function enabled(): bool
    {
        return $this->ready() && !empty($this->config['enabled']);
    }

    /**
     * Normaliza un teléfono venezolano a E.164 sin "+" (58412...).
     * Acepta 0414..., 414..., +58..., 5841...; devuelve null si es inválido.
     */
    public static function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if ($digits === '') return null;
        if (strlen($digits) === 11 && $digits[0] === '0') $digits = '58' . substr($digits, 1);
        elseif (strlen($digits) === 10 && $digits[0] === '4') $digits = '58' . $digits;
        elseif (strlen($digits) === 13 && str_starts_with($digits, '58')) $digits = substr($digits, 0, 12);
        if (strlen($digits) !== 12 || !str_starts_with($digits, '58')) return null;
        return $digits;
    }

    public function enqueueReminder(array $installment): int
    {
        if (!$this->enabled()) return 0;
        $wa = self::normalizePhone((string) ($installment['phone'] ?? ''));
        if ($wa === null) return 0;
        $template = (string) ($this->config['templates']['reminder'] ?? 'gp_recordatorio_cuota');
        if ($template === '') return 0;
        $amount = $installment['amount_due'] ?? $installment['weekly_amount'] ?? null;
        $payload = [
            'recipient' => $wa,
            'template' => [
                'name' => $template,
                'language' => (string) ($this->config['templates']['language'] ?? 'es'),
            ],
            'parameters' => [
                ['type' => 'text', 'text' => (string) ($installment['full_name'] ?? '')],
                ['type' => 'text', 'text' => $amount === null ? '-' : number_format((float) $amount, 2, '.', '')],
            ],
            'external_id' => 'gp-reminder-' . (int) ($installment['id'] ?? 0),
        ];
        $this->applyPhoneNumberId($payload);
        return $this->insertPending('reminder', $template, $wa, 'gp_finance_installments', (int) ($installment['id'] ?? 0), $payload);
    }

    public function enqueueReceipt(int $receiptId): int
    {
        if (!$this->enabled() || $receiptId < 1) return 0;
        $receipt = (new PaymentReceiptService($this->pdo))->receipt($receiptId);
        if (!$receipt) return 0;
        $wa = self::normalizePhone((string) ($receipt['phone'] ?? ''));
        if ($wa === null) return 0;
        $template = (string) ($this->config['templates']['receipt'] ?? 'gp_pago_conciliado');
        if ($template === '') return 0;
        $receiptNumber = (string) ($receipt['receiptNumber'] ?? '');
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', $receiptNumber !== '' ? $receiptNumber : ('RECIBO-' . $receiptId)) . '.pdf';
        $payload = [
            'recipient' => $wa,
            'template' => [
                'name' => $template,
                'language' => (string) ($this->config['templates']['language'] ?? 'es'),
            ],
            'parameters' => [
                ['type' => 'text', 'text' => (string) ($receipt['clientName'] ?? '')],
                ['type' => 'text', 'text' => $receipt['amount'] === null ? '-' : number_format((float) $receipt['amount'], 2, '.', '')],
                ['type' => 'text', 'text' => $receiptNumber],
            ],
            'external_id' => 'gp-receipt-' . $receiptId,
            'media' => ['filename' => $filename],
        ];
        $this->applyPhoneNumberId($payload);
        return $this->insertPending('receipt', $template, $wa, 'gp_finance_receipts', $receiptId, $payload);
    }

    /**
     * Inyecta phone_number_id si está configurado (selección explícita de
     * línea/WABA en FlowBot).
     */
    private function applyPhoneNumberId(array &$payload): void
    {
        $phoneNumberId = trim((string) ($this->config['phone_number_id'] ?? ''));
        if ($phoneNumberId !== '') $payload['phone_number_id'] = $phoneNumberId;
    }

    public function processPending(int $limit = 20): array
    {
        $result = ['sent' => 0, 'failed' => 0];
        if (!$this->enabled()) return $result;
        $limit = max(1, min(100, $limit));
        // Reintenta pendientes y fallidas con intentos < 3 (el cron de recordatorios
        // y el flush posterior a la conciliación vuelven a pasar por aquí).
        $rows = $this->pdo->query(
            "SELECT id, message_type, template_name, wa_id, entity_id, payload_json
             FROM gp_whatsapp_log
             WHERE status='pending' OR (status='failed' AND attempts < 3)
             ORDER BY id ASC LIMIT " . $limit
        )->fetchAll();
        foreach ($rows as $row) {
            $payload = json_decode((string) ($row['payload_json'] ?? '[]'), true);
            if (!is_array($payload)) {
                $this->markFailed((int) $row['id'], 'Payload inválido en la bitácora.');
                $result['failed']++;
                continue;
            }
            try {
                $client = new WhatsAppClient($this->config);
                if (($row['message_type'] ?? '') === 'receipt') {
                    $pdf = $this->receiptPdf((int) ($row['entity_id'] ?? 0));
                    $filename = (string) ($payload['media']['filename'] ?? 'recibo.pdf');
                    $response = $client->sendTemplateWithDocument($payload, $pdf, $filename);
                } else {
                    $response = $client->sendTemplate($payload);
                }
                $this->markSent((int) $row['id'], (string) ($response['message_id'] ?? ''));
                $result['sent']++;
            } catch (Throwable $error) {
                $this->markFailed((int) $row['id'], mb_substr($error->getMessage(), 0, 450));
                $result['failed']++;
            }
        }
        return $result;
    }

    public function logSummary(): array
    {
        if (!$this->ready()) return [];
        $rows = $this->pdo->query(
            "SELECT message_type, status, COUNT(*) AS total FROM gp_whatsapp_log GROUP BY message_type, status ORDER BY message_type, status"
        )->fetchAll();
        return $rows;
    }

    public function recentLog(int $limit = 20): array
    {
        if (!$this->ready()) return [];
        $limit = max(1, min(100, $limit));
        return $this->pdo->query(
            "SELECT id, message_type, template_name, wa_id, entity_type, entity_id, flowbot_message_id, status, error, attempts, created_at, updated_at
             FROM gp_whatsapp_log ORDER BY id DESC LIMIT " . $limit
        )->fetchAll();
    }

    private function receiptPdf(int $receiptId): string
    {
        $receipt = (new PaymentReceiptService($this->pdo))->receipt($receiptId);
        if (!$receipt) throw new RuntimeException('El recibo ' . $receiptId . ' no existe.');
        return ReceiptPdfRenderer::bytes($receipt);
    }

    private function insertPending(string $type, string $template, string $wa, string $entityType, int $entityId, array $payload): int
    {
        $statement = $this->pdo->prepare(
            "INSERT IGNORE INTO gp_whatsapp_log (message_type, template_name, wa_id, entity_type, entity_id, payload_json)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $statement->execute([
            $type, $template, $wa, $entityType, $entityId,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        return (int) $statement->rowCount();
    }

    private function markSent(int $id, string $messageId): void
    {
        $this->pdo->prepare("UPDATE gp_whatsapp_log SET status='sent', flowbot_message_id=?, error=NULL, updated_at=NOW() WHERE id=?")
            ->execute([$messageId, $id]);
    }

    private function markFailed(int $id, string $error): void
    {
        $this->pdo->prepare("UPDATE gp_whatsapp_log SET status='failed', error=?, attempts=attempts+1, updated_at=NOW() WHERE id=?")
            ->execute([$error, $id]);
    }
}
