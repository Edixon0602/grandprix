<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/Database.php';
require_once dirname(__DIR__) . '/lib/EventAudit.php';
gp_start_session();
gp_require_admin(false);
$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!gp_verify_csrf((string) ($_POST['csrf'] ?? ''))) {
        $error = 'La sesión de seguridad venció.';
    } else {
        try {
            $pdo = Database::connection();
            $pdo->exec("SET time_zone='-04:00'");
            $suffix = " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            $pdo->exec("CREATE TABLE IF NOT EXISTS gp_whatsapp_log (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                message_type VARCHAR(40) NOT NULL,
                template_name VARCHAR(100) NOT NULL,
                wa_id VARCHAR(20) NOT NULL,
                entity_type VARCHAR(60) NULL,
                entity_id BIGINT NULL,
                payload_json LONGTEXT NULL,
                flowbot_message_id VARCHAR(120) NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                error VARCHAR(500) NULL,
                attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_gp_wa (message_type, entity_type, entity_id),
                INDEX idx_gp_wa_status (status, created_at)
            ){$suffix}");

            $configPath = dirname(__DIR__) . '/config/whatsapp.php';
            if (!is_file($configPath)) {
                $examplePath = dirname(__DIR__) . '/config/whatsapp.example.php';
                if (is_file($examplePath)) {
                    $content = (string) file_get_contents($examplePath);
                    if (@file_put_contents($configPath, $content) !== false) {
                        @chmod($configPath, 0640);
                    }
                }
            }

            EventAudit::recordAdmin(gp_current_admin(), 'system', 'v22_whatsapp_install', 'update', null, null, 'Instaló la integración de WhatsApp con FlowBot (plantillas y recibo PDF).', ['timezone' => 'America/Caracas'], $pdo);
            $configFile = is_file($configPath) ? 'configurado' : 'no creado (cópialo de whatsapp.example.php)';
            $message = 'V22 instalada correctamente. Bitácora de WhatsApp creada y archivo de configuración ' . $configFile . '. Completa flowbot_base_url y flowbot_api_key en config/whatsapp.php y crea las plantillas en Meta para activar los envíos.';
        } catch (Throwable $e) {
            $error = 'No fue posible instalar V22: ' . $e->getMessage();
        }
    }
}
$csrf = gp_csrf_token();
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>GRANDPRIX · Actualización V22</title><style>*{box-sizing:border-box}body{margin:0;background:#eef4f9;font-family:Inter,Arial,sans-serif;color:#0c2945;min-height:100vh;display:grid;place-items:center;padding:22px}.card{width:min(720px,100%);background:#fff;border:1px solid #dce7ef;border-radius:28px;padding:32px;box-shadow:0 24px 70px #092a441c}.eyebrow{color:#176fe8;font-size:11px;font-weight:900;letter-spacing:1.6px}.card h1{font-size:32px;margin:8px 0}.card p{color:#657e94;line-height:1.6}.box{padding:16px;border-radius:18px;background:#f6f9fc;margin:16px 0}.box b{display:block;margin-bottom:8px}.box span{display:block;color:#607a91;font-size:13px;line-height:1.7}.ok,.err{padding:13px 15px;border-radius:14px;margin:14px 0}.ok{background:#eaf9f3;color:#087c58}.err{background:#fff0f3;color:#b82e48}button{border:0;border-radius:14px;padding:14px 18px;background:#0e67df;color:#fff;font-weight:900;cursor:pointer;width:100%}a{display:block;text-align:center;margin-top:14px;color:#176fe8;text-decoration:none;font-weight:800}</style></head><body><main class="card"><span class="eyebrow">GRANDPRIX CONTROL 360 · V22</span><h1>WhatsApp con FlowBot</h1><p>Esta actualización habilita la cola de envíos de WhatsApp: recordatorio el día del vencimiento y envío del recibo PDF al conciliar un pago. Los envíos los hace FlowBot vía WhatsApp Cloud API.</p><?php if ($message): ?><div class="ok"><?= htmlspecialchars($message) ?></div><?php endif; ?><?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?><div class="box"><b>Se habilitará</b><span>• Tabla gp_whatsapp_log: bitácora y cola con idempotencia.</span><span>• lib/WhatsAppClient.php, lib/WhatsAppOutbox.php y lib/WhatsAppReminderService.php.</span><span>• tools/reminders-whatsapp.php para el cron diario (CLI o HTTP con token).</span><span>• Envío automático del recibo PDF tras aprobar una conciliación.</span></div><form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>"><button>Instalar actualización V22</button></form><a href="../login.php">Volver a GRANDPRIX</a></main></body></html>
