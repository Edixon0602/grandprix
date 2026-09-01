<?php
declare(strict_types=1);

/*
 * Recordatorio programado de WhatsApp: envía las plantillas de vencimiento
 * (por defecto el mismo día de la fecha de cobro, configurable con
 * reminder_days_before) y reintenta envíos pendientes de recibos.
 *
 * Uso CLI (recomendado en Hostinger):
 *   php /home/u843703195/domains/<dominio>/public_html/grandprix/tools/reminders-whatsapp.php
 *
 * Uso por HTTP (si el hosting no permite cron por CLI):
 *   https://<dominio>/grandprix/tools/reminders-whatsapp.php?token=<cron_token>
 *
 * No requiere sesión administrativa: es un job. En modo HTTP se valida el token
 * de config/whatsapp.php.
 */
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/Database.php';
require_once dirname(__DIR__) . '/lib/WhatsAppReminderService.php';

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    $config = gp_whatsapp_config();
    $token = trim((string) ($config['cron_token'] ?? ''));
    $given = (string) ($_GET['token'] ?? '');
    if ($token === '' || !hash_equals($token, $given)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Acceso denegado.']);
        exit;
    }
}

try {
    if (!Database::configured()) throw new RuntimeException('La base de datos no está configurada.');
    $result = WhatsAppReminderService::create()->run();
    if ($isCli) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
    } else {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
} catch (Throwable $error) {
    if ($isCli) {
        fwrite(STDERR, 'Error: ' . $error->getMessage() . PHP_EOL);
        exit(1);
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $error->getMessage()]);
}
