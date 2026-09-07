<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/Database.php';

gp_start_session();
gp_require_admin(false);
// Antes de V8 la sesión heredada no tiene arreglo de permisos y puede ejecutar la primera instalación.
// Después de V8, solo quienes administran permisos pueden volver a ejecutar la migración.
if (array_key_exists('grandprix_admin_permissions', $_SESSION) && !gp_user_can('users.permissions')) {
    http_response_code(403);
    exit('No tienes permiso para ejecutar esta actualización administrativa.');
}

$done = false;
$error = '';
$report = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!gp_verify_csrf((string) ($_POST['csrf'] ?? ''))) {
        $error = 'La sesión de seguridad venció. Recarga la página.';
    } else {
        try {
            $pdo = Database::connection();
            $suffix = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
            $statements = [
                "CREATE TABLE IF NOT EXISTS gp_admin_permissions (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    permission_key VARCHAR(100) NOT NULL UNIQUE,
                    module_key VARCHAR(80) NOT NULL,
                    label VARCHAR(160) NOT NULL,
                    description VARCHAR(300) NULL,
                    sort_order INT NOT NULL DEFAULT 100,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_gp_permission_module (module_key, sort_order)
                ){$suffix}",
                "CREATE TABLE IF NOT EXISTS gp_admin_roles (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(100) NOT NULL UNIQUE,
                    description VARCHAR(300) NULL,
                    is_system TINYINT(1) NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ){$suffix}",
                "CREATE TABLE IF NOT EXISTS gp_admin_role_permissions (
                    role_id INT UNSIGNED NOT NULL,
                    permission_id INT UNSIGNED NOT NULL,
                    PRIMARY KEY (role_id, permission_id),
                    CONSTRAINT fk_gp_roleperm_role FOREIGN KEY (role_id) REFERENCES gp_admin_roles(id) ON DELETE CASCADE,
                    CONSTRAINT fk_gp_roleperm_perm FOREIGN KEY (permission_id) REFERENCES gp_admin_permissions(id) ON DELETE CASCADE
                ){$suffix}",
                "CREATE TABLE IF NOT EXISTS gp_admin_users (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    full_name VARCHAR(160) NOT NULL,
                    email VARCHAR(190) NOT NULL UNIQUE,
                    password_hash VARCHAR(255) NOT NULL,
                    role_id INT UNSIGNED NULL,
                    status VARCHAR(20) NOT NULL DEFAULT 'active',
                    last_login_at DATETIME NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    CONSTRAINT fk_gp_admin_user_role FOREIGN KEY (role_id) REFERENCES gp_admin_roles(id) ON DELETE SET NULL,
                    INDEX idx_gp_admin_status (status, role_id)
                ){$suffix}",
                "CREATE TABLE IF NOT EXISTS gp_admin_user_permissions (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    user_id BIGINT UNSIGNED NOT NULL,
                    permission_id INT UNSIGNED NOT NULL,
                    allowed TINYINT(1) NOT NULL DEFAULT 1,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_gp_user_permission (user_id, permission_id),
                    CONSTRAINT fk_gp_userperm_user FOREIGN KEY (user_id) REFERENCES gp_admin_users(id) ON DELETE CASCADE,
                    CONSTRAINT fk_gp_userperm_perm FOREIGN KEY (permission_id) REFERENCES gp_admin_permissions(id) ON DELETE CASCADE
                ){$suffix}",
                "CREATE TABLE IF NOT EXISTS gp_admin_audit (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    user_id BIGINT UNSIGNED NULL,
                    user_email VARCHAR(190) NOT NULL,
                    module_key VARCHAR(80) NOT NULL,
                    action_key VARCHAR(80) NOT NULL,
                    entity_type VARCHAR(100) NULL,
                    entity_id BIGINT UNSIGNED NULL,
                    summary VARCHAR(300) NULL,
                    before_json LONGTEXT NULL,
                    after_json LONGTEXT NULL,
                    ip_hash CHAR(64) NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_gp_audit_module (module_key, created_at),
                    INDEX idx_gp_audit_user (user_id, created_at)
                ){$suffix}",
                "CREATE TABLE IF NOT EXISTS gp_finance_accounts (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    source_row INT UNSIGNED NULL UNIQUE,
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
                    source_name VARCHAR(160) NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_gp_finance_status (record_status, installments_late),
                    INDEX idx_gp_finance_referrer (referrer),
                    UNIQUE KEY uq_gp_finance_gps (gps_device_id),
                    INDEX idx_gp_finance_name (full_name)
                ){$suffix}",
                "CREATE TABLE IF NOT EXISTS gp_finance_referrers (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    display_name VARCHAR(100) NOT NULL,
                    source_key VARCHAR(100) NOT NULL UNIQUE,
                    sort_order INT NOT NULL DEFAULT 100,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ){$suffix}",
                "CREATE TABLE IF NOT EXISTS gp_finance_applications (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    application_code VARCHAR(40) NOT NULL UNIQUE,
                    applicant_name VARCHAR(160) NOT NULL,
                    identity_document VARCHAR(40) NULL,
                    phone VARCHAR(40) NULL,
                    model_requested VARCHAR(120) NULL,
                    referrer VARCHAR(100) NULL,
                    status VARCHAR(30) NOT NULL DEFAULT 'new',
                    requested_at DATE NOT NULL,
                    notes VARCHAR(1000) NULL,
                    created_by VARCHAR(190) NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_gp_finapp_status (status, requested_at),
                    INDEX idx_gp_finapp_name (applicant_name)
                ){$suffix}",
                "CREATE TABLE IF NOT EXISTS gp_finance_payments (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    account_id BIGINT UNSIGNED NOT NULL,
                    paid_at DATE NOT NULL,
                    amount DECIMAL(12,2) NULL,
                    bank VARCHAR(100) NULL,
                    reference_number VARCHAR(100) NULL,
                    installments_applied SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                    late_reduced SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                    notes VARCHAR(500) NULL,
                    status VARCHAR(20) NOT NULL DEFAULT 'confirmed',
                    created_by VARCHAR(190) NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT fk_gp_finpay_account FOREIGN KEY (account_id) REFERENCES gp_finance_accounts(id),
                    INDEX idx_gp_finpay_account (account_id, paid_at),
                    INDEX idx_gp_finpay_created (created_at)
                ){$suffix}",
            ];
            foreach ($statements as $sql) $pdo->exec($sql);

            // Migración segura para instalaciones V8 parciales: agrega los campos de contrato sin borrar ni reemplazar datos existentes.
            $accountColumns = [
                'address' => "VARCHAR(300) NULL AFTER phone",
                'contract_number' => "VARCHAR(80) NULL AFTER address",
                'weekly_amount' => "DECIMAL(12,2) NULL AFTER contract_number",
                'financed_amount' => "DECIMAL(12,2) NULL AFTER weekly_amount",
                'start_date' => "DATE NULL AFTER financed_amount",
            ];
            foreach ($accountColumns as $column => $definition) {
                $check = $pdo->query("SHOW COLUMNS FROM gp_finance_accounts LIKE " . $pdo->quote($column));
                if (!$check->fetch()) $pdo->exec("ALTER TABLE gp_finance_accounts ADD COLUMN `{$column}` {$definition}");
            }

            $permissions = [
                ['dashboard.view','direccion','Ver resumen ejecutivo','Acceso al tablero principal',10],
                ['finance.view','finanzas','Ver módulo financiero','Indicadores, cartera y gestores',20],
                ['finance.applications.view','finanzas','Ver solicitudes de crédito','Consultar solicitudes registradas',21],
                ['finance.applications.create','finanzas','Crear solicitudes de crédito','Registrar nuevas solicitudes',22],
                ['finance.applications.edit','finanzas','Editar solicitudes de crédito','Cambiar datos y estado de solicitudes',23],
                ['finance.clients.view','finanzas','Ver clientes y créditos','Consultar expedientes financieros',24],
                ['finance.clients.create','finanzas','Crear clientes','Registrar nuevas cuentas financieras',25],
                ['finance.clients.edit','finanzas','Editar clientes','Modificar datos, cuotas y observaciones',26],
                ['finance.clients.archive','finanzas','Archivar clientes','Retirar una cuenta del listado operativo',27],
                ['finance.payments.view','finanzas','Ver pagos','Consultar movimientos registrados',28],
                ['finance.payments.create','finanzas','Registrar pagos','Registrar movimientos o enviarlos a conciliación',29],
                ['finance.payments.reconcile','finanzas','Conciliar pagos','Aprobar o rechazar movimientos en revisión',30],
                ['finance.reports.export','finanzas','Exportar reportes','Descargar cartera y reportes',31],
                ['finance.gps.assign','finanzas','Asignar GPS a clientes','Vincular Device ID sin alterar telemetría',32],
                ['users.view','usuarios','Ver usuarios','Consultar usuarios y roles',40],
                ['users.create','usuarios','Crear usuarios','Crear accesos administrativos',41],
                ['users.edit','usuarios','Editar usuarios','Cambiar datos, estado y rol',42],
                ['users.permissions','usuarios','Administrar permisos','Configurar permisos por rol y usuario',43],
                ['audit.view','seguridad','Ver auditoría','Consultar acciones administrativas',50],
                ['gps.monitor.view','gps','Ver monitoreo GPS','Acceder al mapa y telemetría',60],
                ['gps.vehicles.view','gps','Ver expedientes GPS','Consultar motos y dispositivos',61],
                ['gps.commands.execute','gps','Ejecutar comandos GPS','Usar centro de comandos protegido',62],
                ['gps.alerts.view','gps','Ver alertas GPS','Consultar alertas de seguridad',63],
                ['gps.history.view','gps','Ver historial GPS','Consultar recorridos e historial',64],
                ['gps.settings.manage','gps','Gestionar GPS','Activaciones, grupos y geocercas',65],
                ['portal.manage','portal','Administrar portal cliente','Cuentas, contratos y conciliaciones del portal',70],
                ['settings.manage','configuracion','Administrar configuración','Cambiar parámetros generales',80],
            ];
            $permStmt = $pdo->prepare(
                'INSERT INTO gp_admin_permissions (permission_key,module_key,label,description,sort_order) VALUES (?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE module_key=VALUES(module_key),label=VALUES(label),description=VALUES(description),sort_order=VALUES(sort_order)'
            );
            foreach ($permissions as $p) $permStmt->execute($p);

            $existingRoleNames = array_fill_keys(array_map('strval', $pdo->query('SELECT name FROM gp_admin_roles')->fetchAll(PDO::FETCH_COLUMN)), true);
            $roleDefs = [
                'Superadministrador' => ['Acceso total al sistema', ['*']],
                'Dirección' => ['Dirección y consulta gerencial', ['dashboard.view','finance.view','finance.applications.view','finance.clients.view','finance.payments.view','finance.reports.export','audit.view','gps.monitor.view','gps.vehicles.view','gps.alerts.view','gps.history.view','portal.manage']],
                'Finanzas / Cobranza' => ['Cartera, clientes, pagos y conciliación', ['dashboard.view','finance.view','finance.applications.view','finance.applications.create','finance.applications.edit','finance.clients.view','finance.clients.create','finance.clients.edit','finance.payments.view','finance.payments.create','finance.payments.reconcile','finance.reports.export','finance.gps.assign','portal.manage']],
                'Operaciones' => ['Operación de flota y consulta de clientes', ['dashboard.view','finance.clients.view','gps.monitor.view','gps.vehicles.view','gps.alerts.view','gps.history.view']],
                'Monitoreo GPS' => ['Mapa, alertas e historial', ['dashboard.view','gps.monitor.view','gps.vehicles.view','gps.alerts.view','gps.history.view']],
                'Técnico GPS' => ['Dispositivos y configuración técnica', ['dashboard.view','gps.monitor.view','gps.vehicles.view','gps.settings.manage']],
                'Auditoría' => ['Consulta financiera, usuarios y trazabilidad', ['dashboard.view','finance.view','finance.applications.view','finance.clients.view','finance.payments.view','finance.reports.export','users.view','audit.view']],
            ];
            $roleStmt = $pdo->prepare('INSERT INTO gp_admin_roles (name,description,is_system) VALUES (?,?,1) ON DUPLICATE KEY UPDATE description=VALUES(description),is_system=1');
            foreach ($roleDefs as $roleName => [$desc]) $roleStmt->execute([$roleName,$desc]);

            $permIdStmt = $pdo->prepare('SELECT id FROM gp_admin_permissions WHERE permission_key = ?');
            $roleIdStmt = $pdo->prepare('SELECT id FROM gp_admin_roles WHERE name = ?');
            $insertRolePerm = $pdo->prepare('INSERT IGNORE INTO gp_admin_role_permissions (role_id,permission_id) VALUES (?,?)');
            $allPermissionIds = $pdo->query('SELECT id FROM gp_admin_permissions')->fetchAll(PDO::FETCH_COLUMN);
            foreach ($roleDefs as $roleName => [$desc,$keys]) {
                $roleIdStmt->execute([$roleName]); $roleId = (int) $roleIdStmt->fetchColumn();
                if ($keys === ['*']) {
                    // El Superadministrador siempre conserva todos los permisos, incluso si se agregan permisos nuevos en una actualización.
                    foreach ($allPermissionIds as $permissionId) $insertRolePerm->execute([$roleId,(int)$permissionId]);
                } elseif (!isset($existingRoleNames[$roleName])) {
                    // Los perfiles base se cargan una sola vez. Si luego los personalizas, una re-ejecución del instalador no borra esos cambios.
                    foreach ($keys as $key) { $permIdStmt->execute([$key]); $permissionId=(int)$permIdStmt->fetchColumn(); if($permissionId)$insertRolePerm->execute([$roleId,$permissionId]); }
                }
            }

            $config = gp_app_config();
            $adminEmail = mb_strtolower(trim((string) ($config['admin_email'] ?? '')));
            $adminHash = (string) ($config['password_hash'] ?? '');
            $roleIdStmt->execute(['Superadministrador']); $superRoleId = (int) $roleIdStmt->fetchColumn();
            if ($adminEmail !== '' && $adminHash !== '') {
                $stmt = $pdo->prepare('SELECT id FROM gp_admin_users WHERE email = ? LIMIT 1'); $stmt->execute([$adminEmail]);
                if (!$stmt->fetchColumn()) {
                    $pdo->prepare("INSERT INTO gp_admin_users (full_name,email,password_hash,role_id,status) VALUES ('Administrador GRANDPRIX',?,?,?,'active')")
                        ->execute([$adminEmail,$adminHash,$superRoleId]);
                }
            }

            $jsonPath = dirname(__DIR__) . '/data/finanzas-grandprix-20260817.json';
            if (!is_file($jsonPath)) throw new RuntimeException('No se encontró el archivo de carga financiera incluido en el paquete.');
            $payload = json_decode((string) file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);
            $insertAccount = $pdo->prepare(
                "INSERT IGNORE INTO gp_finance_accounts
                 (source_row,full_name,model,model_family,image_path,plate,total_installments,installments_paid,installments_late,advance_note,advance_amount,referrer,source_name,record_status)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'active')"
            );
            $inserted = 0;
            foreach ((array) ($payload['records'] ?? []) as $row) {
                $insertAccount->execute([
                    (int) $row['source_row'], (string) $row['full_name'], $row['model'] ?: null, (string) $row['model_family'],
                    (string) $row['image_path'], $row['plate'] ?: null, (int) $row['total_installments'], (int) $row['installments_paid'],
                    (int) $row['installments_late'], $row['advance_note'] ?: null, $row['advance_amount'], $row['referrer'] ?: null,
                    (string) ($payload['source'] ?? 'Para Cargar la data.xlsx'),
                ]);
                $inserted += $insertAccount->rowCount();
            }
            $refInsert = $pdo->prepare('INSERT INTO gp_finance_referrers (display_name,source_key,sort_order) VALUES (?,?,?) ON DUPLICATE KEY UPDATE display_name=VALUES(display_name),sort_order=VALUES(sort_order)');
            $position = 1;
            foreach ((array) ($payload['referrer_summary'] ?? []) as $ref) $refInsert->execute([(string)$ref['display_name'],(string)$ref['source_key'],$position++]);

            $meta = $pdo->prepare("INSERT INTO gp_schema_meta (meta_key,meta_value) VALUES ('finance_users_version','8.0.0') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");
            $meta->execute();
            $report = [
                'Registros reales detectados en el Excel' => count((array) ($payload['records'] ?? [])),
                'Registros nuevos importados ahora' => $inserted,
                'Usuarios administrativos' => (int) $pdo->query('SELECT COUNT(*) FROM gp_admin_users')->fetchColumn(),
                'Roles disponibles' => (int) $pdo->query('SELECT COUNT(*) FROM gp_admin_roles')->fetchColumn(),
            ];
            $done = true;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}
$csrf = gp_csrf_token();
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>GRANDPRIX · Actualización Finanzas + Usuarios V8</title><style>
*{box-sizing:border-box}body{margin:0;background:#f4f7fb;color:#122235;font:15px Inter,Arial,sans-serif}.wrap{width:min(900px,calc(100% - 28px));margin:40px auto}.card{background:#fff;border:1px solid #dfe7ef;border-radius:24px;padding:30px;box-shadow:0 24px 70px #0b294517}h1{margin:8px 0 10px;font-size:30px}p{color:#66798d;line-height:1.6}.tag{display:inline-block;padding:7px 10px;border-radius:999px;background:#e8f6ef;color:#177953;font-size:12px;font-weight:800}.warn{background:#fff7df;border:1px solid #f0d682;padding:14px;border-radius:14px;color:#705615}.err{background:#ffecef;border:1px solid #ffc4ce;padding:14px;border-radius:14px;color:#9c2539}.ok{background:#e8f8f0;border:1px solid #bce7d2;padding:16px;border-radius:14px;color:#176b4e}button,a.btn{display:inline-flex;align-items:center;justify-content:center;text-decoration:none;border:0;border-radius:12px;background:#f68b1f;color:#fff;padding:14px 18px;font-weight:900;cursor:pointer}.rows{display:grid;gap:9px;margin:18px 0}.rows div{display:flex;justify-content:space-between;padding:12px 14px;background:#f7f9fc;border-radius:11px}.rows b{color:#0a2741}.muted{font-size:12px}.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:20px}.secondary{background:#0a2741!important}</style></head><body><div class="wrap"><div class="card"><span class="tag">GRANDPRIX V8 · FINANZAS + USUARIOS</span><h1>Actualización administrativa</h1><p>Esta actualización crea la cartera financiera real, importa los 98 registros del archivo entregado y habilita usuarios, roles, permisos y auditoría. No modifica Traccar, webhook, telemetría, mapa ni comandos GPS.</p>
<?php if($error):?><div class="err"><b>No se pudo completar:</b><br><?=htmlspecialchars($error)?></div><?php endif;?>
<?php if($done):?><div class="ok"><b>Actualización completada correctamente.</b></div><div class="rows"><?php foreach($report as $label=>$value):?><div><span><?=htmlspecialchars($label)?></span><b><?=htmlspecialchars((string)$value)?></b></div><?php endforeach;?></div><div class="actions"><a class="btn" href="../index.php">Abrir Control 360</a><a class="btn secondary" href="../logout.php">Cerrar sesión</a></div><?php else:?><div class="warn"><b>Importante:</b> puedes ejecutar este instalador más de una vez. Los registros ya importados no se duplican ni se sobrescriben.</div><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>"><div class="actions"><button type="submit">Instalar Finanzas + Usuarios V8</button><a class="btn secondary" href="../index.php">Cancelar</a></div></form><?php endif;?>
<p class="muted">Archivo de datos: Para Cargar la data.xlsx · Hoja Lunes 17082026.</p></div></div></body></html>
