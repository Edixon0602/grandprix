<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/Database.php';
require_once dirname(__DIR__) . '/lib/WhatsAppOutbox.php';
require_once dirname(__DIR__) . '/lib/WhatsAppClient.php';
require_once dirname(__DIR__) . '/lib/ReceiptPdfRenderer.php';
gp_start_session();
gp_require_admin(false);
$message = '';
$error = '';
$config = gp_whatsapp_config();
$outbox = new WhatsAppOutbox(Database::connection());
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!gp_verify_csrf((string) ($_POST['csrf'] ?? ''))) {
        $error = 'La sesión de seguridad venció.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        try {
            if ($action === 'process') {
                $result = $outbox->processPending(50);
                $message = 'Envíos procesados: ' . $result['sent'] . ' enviados, ' . $result['failed'] . ' fallidos.';
            } elseif ($action === 'test') {
                if (empty($config['enabled'])) throw new RuntimeException('La integración está deshabilitada (enabled=false en config/whatsapp.php).');
                $wa = WhatsAppOutbox::normalizePhone((string) ($config['test_recipient'] ?? ''));
                if ($wa === null) throw new RuntimeException('test_recipient no es un número venezolano válido (ej. 58412...).');
                $template = (string) ($config['templates']['reminder'] ?? 'gp_recordatorio_cuota');
                $client = new WhatsAppClient($config);
                $payload = [
                    'recipient' => $wa,
                    'template' => ['name' => $template, 'language' => (string) ($config['templates']['language'] ?? 'es')],
                    'parameters' => [
                        ['type' => 'text', 'text' => 'Cliente de prueba'],
                        ['type' => 'text', 'text' => '65.00'],
                    ],
                    'external_id' => 'gp-test-' . date('Ymd-His'),
                ];
                $phoneNumberId = trim((string) ($config['phone_number_id'] ?? ''));
                if ($phoneNumberId !== '') $payload['phone_number_id'] = $phoneNumberId;
                $response = $client->sendTemplate($payload);
                $message = 'Prueba enviada correctamente a ' . $wa . ' (message_id: ' . ($response['message_id'] ?? '?') . ').';
            } elseif ($action === 'test-receipt') {
                if (empty($config['enabled'])) throw new RuntimeException('La integración está deshabilitada (enabled=false en config/whatsapp.php).');
                $wa = WhatsAppOutbox::normalizePhone((string) ($config['test_recipient'] ?? ''));
                if ($wa === null) throw new RuntimeException('test_recipient no es un número venezolano válido (ej. 58412...).');
                $receiptNumber = 'REC-TEST-' . date('Ymd');
                $receipt = [
                    'receiptNumber' => $receiptNumber,
                    'amount' => 65.00,
                    'paidAt' => date('Y-m-d'),
                    'nextDueDate' => date('Y-m-d', strtotime('+7 days')),
                    'clientName' => 'Cliente de prueba',
                    'identityDocument' => 'V-99999999',
                    'phone' => $wa,
                    'address' => 'Dirección de prueba',
                    'model' => 'Bera SBR',
                    'plate' => 'AA1B2C',
                    'contractNumber' => 'GP-TEST-001',
                    'totalWeeks' => 50,
                    'weeklyAmount' => 65.00,
                    'paymentMethod' => 'Efectivo',
                    'bank' => '',
                    'reference' => 'REF-TEST',
                    'paidWeeks' => [1],
                    'nextWeek' => 2,
                    'pending' => [],
                ];
                $pdf = ReceiptPdfRenderer::bytes($receipt);
                $template = (string) ($config['templates']['receipt'] ?? 'gp_pago_conciliado');
                $client = new WhatsAppClient($config);
                $payload = [
                    'recipient' => $wa,
                    'template' => ['name' => $template, 'language' => (string) ($config['templates']['language'] ?? 'es')],
                    'parameters' => [
                        ['type' => 'text', 'text' => 'Cliente de prueba'],
                        ['type' => 'text', 'text' => '65.00'],
                        ['type' => 'text', 'text' => $receiptNumber],
                    ],
                    'external_id' => 'gp-test-receipt-' . date('Ymd-His'),
                    'media' => ['filename' => $receiptNumber . '.pdf'],
                ];
                $phoneNumberId = trim((string) ($config['phone_number_id'] ?? ''));
                if ($phoneNumberId !== '') $payload['phone_number_id'] = $phoneNumberId;
                $response = $client->sendTemplateWithDocument($payload, $pdf, $receiptNumber . '.pdf');
                $message = 'Recibo de prueba enviado correctamente a ' . $wa . ' (message_id: ' . ($response['message_id'] ?? '?') . ').';
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}
$summary = $outbox->logSummary();
$recent = $outbox->recentLog(25);
$status = [
    'ready' => $outbox->ready(),
    'enabled' => !empty($config['enabled']),
    'base_url' => (string) $config['flowbot_base_url'],
    'api_key' => $config['flowbot_api_key'] !== '' ? 'configurada' : 'sin configurar',
    'cron_token' => $config['cron_token'] !== '' ? 'configurado' : 'sin configurar',
    'reminder_days' => (int) $config['reminder_days_before'],
    'templates' => $config['templates'],
    'test_recipient' => (string) $config['test_recipient'],
];
$csrf = gp_csrf_token();
$badge = static fn(bool $ok): string => $ok
    ? '<b style="color:#0a7d50">CORRECTO</b>'
    : '<b style="color:#c0392b">REVISAR</b>';
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>WhatsApp FlowBot · GRANDPRIX</title><style>*{box-sizing:border-box}body{margin:0;background:#eef4f9;font-family:Inter,Arial,sans-serif;color:#0c2945;padding:26px}.wrap{max-width:920px;margin:0 auto}.card{background:#fff;border:1px solid #dce7ef;border-radius:22px;padding:26px;box-shadow:0 20px 60px #092a4418;margin-bottom:18px}.eyebrow{color:#176fe8;font-size:11px;font-weight:900;letter-spacing:1.6px}h1{font-size:28px;margin:8px 0 4px}p{color:#657e94}.ok,.err{padding:13px 15px;border-radius:13px;margin:14px 0}.ok{background:#eaf9f3;color:#087c58}.err{background:#fff0f3;color:#b82e48}.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin:16px 0}.grid div{padding:13px 14px;background:#f7f9fc;border-radius:11px}.grid b{display:block;margin-bottom:4px;font-size:12px;color:#8aa0b4;text-transform:uppercase;letter-spacing:.5px}.grid span{font-size:13px;font-weight:700}table{width:100%;border-collapse:collapse;font-size:13px}th,td{text-align:left;padding:8px 10px;border-bottom:1px solid #e8eef4}th{color:#8aa0b4;font-size:11px;text-transform:uppercase}.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}button{border:0;border-radius:12px;padding:12px 16px;color:#fff;font-weight:900;cursor:pointer}.primary{background:#0e67df}.warn{background:#e67e22}button:disabled{opacity:.5;cursor:not-allowed}@media(max-width:640px){.grid{grid-template-columns:1fr}}</style></head><body><div class="wrap"><div class="card"><span class="eyebrow">GRANDPRIX CONTROL 360 · V22</span><h1>WhatsApp · FlowBot</h1><p>Diagnóstico de la integración de plantillas y envío del recibo PDF. Los envíos los procesa FlowBot vía WhatsApp Cloud API.</p><?php if ($message): ?><div class="ok"><?= htmlspecialchars($message) ?></div><?php endif; ?><?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?><div class="grid"><div><b>Tabla bitácora</b><span><?= $badge($status['ready']) ?> · gp_whatsapp_log</span></div><div><b>Integración habilitada</b><span><?= $badge($status['enabled']) ?></span></div><div><b>URL FlowBot</b><span><?= htmlspecialchars($status['base_url'] !== '' ? $status['base_url'] : '—') ?></span></div><div><b>API Key</b><span><?= $status['api_key'] === 'configurada' ? 'CORRECTO · configurada' : 'REVISAR · ' . $status['api_key'] ?></span></div><div><b>Token cron</b><span><?= $status['cron_token'] === 'configurado' ? 'CORRECTO · configurado' : 'REVISAR · ' . $status['cron_token'] ?></span></div><div><b>Días antes del vencimiento</b><span><?= $status['reminder_days'] ?></span></div><div><b>Plantilla recordatorio</b><span><?= htmlspecialchars((string) $status['templates']['reminder']) ?></span></div><div><b>Plantilla recibo</b><span><?= htmlspecialchars((string) $status['templates']['receipt']) ?></span></div></div><form method="post" class="actions"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>"><button class="primary" name="action" value="process" <?= $status['enabled'] ? '' : 'disabled' ?>>Procesar pendientes</button><button class="warn" name="action" value="test" <?= $status['enabled'] ? '' : 'disabled' ?>>Probar recordatorio</button><button class="warn" name="action" value="test-receipt" <?= $status['enabled'] ? '' : 'disabled' ?>>Probar recibo PDF</button></form></div><div class="card"><span class="eyebrow">BITÁCORA</span><h1 style="font-size:20px">Resumen por tipo y estado</h1><?php if ($summary): ?><table><thead><tr><th>Tipo</th><th>Estado</th><th>Cantidad</th></tr></thead><tbody><?php foreach ($summary as $s): ?><tr><td><?= htmlspecialchars((string) $s['message_type']) ?></td><td><?= htmlspecialchars((string) $s['status']) ?></td><td><?= (int) $s['total'] ?></td></tr><?php endforeach; ?></tbody></table><?php else: ?><p>Sin registros todavía. La bitácora se llena al encolar recordatorios o recibos.</p><?php endif; ?></div><div class="card"><span class="eyebrow">ÚLTIMOS ENVÍOS</span><h1 style="font-size:20px">Historial reciente</h1><?php if ($recent): ?><table><thead><tr><th>ID</th><th>Tipo</th><th>WA ID</th><th>Entidad</th><th>Estado</th><th>Message ID</th><th>Error</th><th>Intentos</th></tr></thead><tbody><?php foreach ($recent as $r): ?><tr><td><?= (int) $r['id'] ?></td><td><?= htmlspecialchars((string) $r['message_type']) ?></td><td><?= htmlspecialchars((string) $r['wa_id']) ?></td><td><?= htmlspecialchars((string) $r['entity_type'] . '#' . $r['entity_id']) ?></td><td><?= htmlspecialchars((string) $r['status']) ?></td><td><?= htmlspecialchars((string) $r['flowbot_message_id']) ?></td><td><?= htmlspecialchars((string) $r['error']) ?></td><td><?= (int) $r['attempts'] ?></td></tr><?php endforeach; ?></tbody></table><?php else: ?><p>Sin envíos registrados.</p><?php endif; ?></div></div></body></html>
