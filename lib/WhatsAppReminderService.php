<?php
declare(strict_types=1);

require_once __DIR__ . '/WhatsAppOutbox.php';

/**
 * Recordatorios de WhatsApp previos al vencimiento.
 * Encuentra las cuotas futuras que vencen dentro de N días (config
 * reminder_days_before, por defecto 5) y las encola para FlowBot.
 */
final class WhatsAppReminderService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function create(): self
    {
        return new self(Database::connection());
    }

    public function pendingReminders(): array
    {
        // 0 = el mismo día del vencimiento; N = N días antes.
        $days = max(0, (int) (gp_whatsapp_config()['reminder_days_before'] ?? 0));
        $statement = $this->pdo->prepare(
            "SELECT i.id, i.account_id, i.installment_no, i.due_date, i.amount_due,
                    a.full_name, a.phone, a.plate, a.model, a.contract_number, a.weekly_amount
             FROM gp_finance_installments i
             INNER JOIN gp_finance_accounts a ON a.id = i.account_id
             WHERE i.status = 'future'
               AND i.due_date = DATE_ADD(CURDATE(), INTERVAL :days DAY)
               AND a.record_status <> 'archived'
               AND a.phone IS NOT NULL AND a.phone <> ''
             ORDER BY a.full_name, i.installment_no"
        );
        $statement->bindValue(':days', $days, PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    public function run(): array
    {
        $outbox = new WhatsAppOutbox($this->pdo);
        if (!$outbox->enabled()) {
            return ['enabled' => false, 'candidates' => 0, 'enqueued' => 0, 'sent' => 0, 'failed' => 0];
        }
        $candidates = $this->pendingReminders();
        $enqueued = 0;
        foreach ($candidates as $installment) {
            $enqueued += $outbox->enqueueReminder($installment);
        }
        $processed = $outbox->processPending();
        return [
            'enabled' => true,
            'days_before' => (int) (gp_whatsapp_config()['reminder_days_before'] ?? 5),
            'candidates' => count($candidates),
            'enqueued' => $enqueued,
            'sent' => $processed['sent'],
            'failed' => $processed['failed'],
        ];
    }
}
