<?php
declare(strict_types=1);
// Validación local de la integración WhatsApp: GRANDPRIX -> stub FlowBot.
// Crea las tablas financieras de prueba en la BD local (no toca la BD remota),
// siembra una cuenta con una cuota que vence según reminder_days_before
// (0 = el mismo día del vencimiento) y valida:
//   1) El recordatorio programado (cron) -> plantilla JSON.
//   2) El recibo PDF al conciliar un pago -> plantilla multipart con PDF.
// Requiere config/whatsapp.php con enabled=true apuntando al stub local.
require_once dirname(__DIR__, 2) . '/lib/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/Database.php';
require_once dirname(__DIR__, 2) . '/lib/PaymentReceiptService.php';
require_once dirname(__DIR__, 2) . '/lib/WhatsAppReminderService.php';
require_once dirname(__DIR__, 2) . '/lib/WhatsAppOutbox.php';

// Conexión a la BD local de Docker Compose (servicio "db").
$host = (string) (getenv('GP_LOCAL_DB_HOST') ?: 'db');
$user = (string) (getenv('GP_LOCAL_DB_USER') ?: 'grandprix');
$pass = (string) (getenv('GP_LOCAL_DB_PASS') ?: 'grandprix');
$pdo = new PDO("mysql:host=$host;port=3306;dbname=u843703195_grandprix;charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec("SET time_zone='-04:00'");
$suffix = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

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
  UNIQUE KEY uq_gp_wa (message_type, entity_type, entity_id)
){$suffix}");

$pdo->exec("CREATE TABLE IF NOT EXISTS gp_finance_accounts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  source_row INT UNSIGNED NULL,
  full_name VARCHAR(160) NOT NULL,
  identity_document VARCHAR(40) NULL,
  phone VARCHAR(40) NULL,
  address VARCHAR(300) NULL,
  contract_number VARCHAR(80) NULL,
  weekly_amount DECIMAL(12,2) NULL,
  financed_amount DECIMAL(12,2) NULL,
  start_date DATE NULL,
  model VARCHAR(120) NULL,
  model_family VARCHAR(120) NULL,
  image_path VARCHAR(255) NOT NULL DEFAULT 'assets/moto-blue.png',
  plate VARCHAR(40) NULL,
  total_installments SMALLINT UNSIGNED NOT NULL DEFAULT 50,
  installments_paid SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  installments_late SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  advance_note VARCHAR(80) NULL,
  advance_amount DECIMAL(12,2) NULL,
  referrer VARCHAR(100) NULL,
  gps_device_id BIGINT UNSIGNED NULL,
  gps_label VARCHAR(120) NULL,
  notes VARCHAR(1000) NULL,
  record_status VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
){$suffix}");
$pdo->exec("CREATE TABLE IF NOT EXISTS gp_finance_installments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  account_id BIGINT UNSIGNED NOT NULL,
  installment_no SMALLINT UNSIGNED NOT NULL,
  due_date DATE NULL,
  amount_due DECIMAL(12,2) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'future',
  paid_at DATETIME NULL,
  paid_payment_id BIGINT UNSIGNED NULL,
  source_key VARCHAR(40) NOT NULL DEFAULT 'legacy-bootstrap',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_gp_fin_installment (account_id, installment_no)
){$suffix}");
$pdo->exec("CREATE TABLE IF NOT EXISTS gp_finance_payments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  account_id BIGINT UNSIGNED NOT NULL,
  paid_at DATE NOT NULL,
  amount DECIMAL(12,2) NULL,
  payment_method VARCHAR(80) NULL,
  bank VARCHAR(100) NULL,
  reference_number VARCHAR(100) NULL,
  installments_applied SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  late_reduced SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  notes VARCHAR(500) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'confirmed',
  created_by VARCHAR(190) NOT NULL,
  week_numbers_json LONGTEXT NULL,
  receipt_id BIGINT UNSIGNED NULL,
  portal_report_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
){$suffix}");
$pdo->exec("CREATE TABLE IF NOT EXISTS gp_finance_receipts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  receipt_number VARCHAR(40) NOT NULL,
  payment_id BIGINT UNSIGNED NOT NULL,
  account_id BIGINT UNSIGNED NOT NULL,
  issued_at DATETIME NOT NULL,
  paid_at DATE NOT NULL,
  amount DECIMAL(12,2) NULL,
  paid_weeks_json LONGTEXT NOT NULL,
  pending_weeks_json LONGTEXT NOT NULL,
  pending_total DECIMAL(12,2) NULL,
  next_week SMALLINT UNSIGNED NULL,
  next_due_date DATE NULL,
  snapshot_json LONGTEXT NOT NULL,
  created_by VARCHAR(190) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY receipt_number (receipt_number),
  UNIQUE KEY payment_id (payment_id)
){$suffix}");

// Limpiar estado previo de prueba.
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (['gp_whatsapp_log', 'gp_finance_receipts', 'gp_finance_payments', 'gp_finance_installments', 'gp_finance_accounts'] as $t) {
    $pdo->exec("DELETE FROM $t");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

// --- Seed: cuenta de prueba ---
$testPhone = (string) (getenv('GP_TEST_PHONE') ?: '04124078366');
$testName = (string) (getenv('GP_TEST_NAME') ?: 'Edixon Serrano');
$pdo->prepare("INSERT INTO gp_finance_accounts (full_name,identity_document,phone,address,contract_number,weekly_amount,financed_amount,start_date,model,model_family,plate,total_installments,installments_paid,installments_late,record_status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,'active')")
    ->execute([$testName, 'V-99999999', $testPhone, 'Direccion de prueba', 'GP-LOCAL-001', 65.00, 3250.00, date('Y-m-d', strtotime('-20 days')), 'Bera SBR', 'Bera SBR', 'AA1B2C', 50, 0, 1]);
$accountId = (int) $pdo->lastInsertId();

$today = date('Y-m-d');
$reminderDays = max(0, (int) (gp_whatsapp_config()['reminder_days_before'] ?? 0));
$reminderDate = date('Y-m-d', strtotime('+' . $reminderDays . ' days'));
$stmt = $pdo->prepare('INSERT INTO gp_finance_installments (account_id,installment_no,due_date,amount_due,status,source_key) VALUES (?,?,?,?,?,?)');
$stmt->execute([$accountId, 1, date('Y-m-d', strtotime('-8 days')), 65.00, 'late', 'test']);
// Semanas 2..50 con vencimientos en intervalos de 7 días (no colisionan con el vencimiento del recordatorio).
for ($w = 2; $w <= 50; $w++) {
    $stmt->execute([$accountId, $w, date('Y-m-d', strtotime('+' . ($w * 7) . ' days')), 65.00, 'future', 'test']);
}
// Solo la semana 3 vence exactamente dentro de reminder_days_before (0 = hoy).
$pdo->prepare('UPDATE gp_finance_installments SET due_date=? WHERE account_id=? AND installment_no=3')->execute([$reminderDate, $accountId]);

echo "hoy: $today | vencimiento del recordatorio (hoy+" . $reminderDays . "): $reminderDate | telefono: $testPhone\n";

// --- Test 1: recordatorio (cron) ---
echo "\n[1] WhatsAppReminderService::run()\n";
$res = (new WhatsAppReminderService($pdo))->run();
echo json_encode($res, JSON_UNESCAPED_UNICODE) . "\n";

// --- Test 2: recibo al conciliar (semana 1) ---
echo "\n[2] applyConfirmedPayment (pago semana 1)\n";
$pdo->prepare('INSERT INTO gp_finance_payments (account_id,paid_at,amount,payment_method,bank,reference_number,installments_applied,late_reduced,notes,status,created_by,week_numbers_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
    ->execute([$accountId, $today, 65.00, 'Efectivo', '', 'REF-LOCAL-001', 1, 0, '', 'confirmed', 'test@local', '[1]']);
$paymentId = (int) $pdo->lastInsertId();
$applied = (new PaymentReceiptService($pdo))->applyConfirmedPayment($paymentId, $accountId, $today, [1], ['email' => 'test@local']);
echo 'weeks aplicadas: ' . implode(',', $applied['weeks']) . ' | recibo: ' . ($applied['receipt']['receiptNumber'] ?? '?') . "\n";

// --- Test 3: proceso de la cola (envío al stub) ---
echo "\n[3] WhatsAppOutbox::processPending()\n";
$proc = (new WhatsAppOutbox($pdo))->processPending(20);
echo json_encode($proc, JSON_UNESCAPED_UNICODE) . "\n";

// --- Test 4: bitácora final ---
echo "\n[4] gp_whatsapp_log\n";
foreach ($pdo->query('SELECT id,message_type,wa_id,entity_type,entity_id,status,flowbot_message_id,error FROM gp_whatsapp_log ORDER BY id') as $r) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\nRESULTADO: enqueued={$res['enqueued']} sent={$proc['sent']} failed={$proc['failed']}\n";
