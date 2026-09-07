<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/lib/bootstrap.php';
require_once dirname(__DIR__).'/lib/Database.php';
require_once dirname(__DIR__).'/lib/EventAudit.php';
gp_start_session();gp_require_admin(false);
$message='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!gp_verify_csrf((string)($_POST['csrf']??''))){$error='La sesión de seguridad venció.';}
    else{
        try{
            $pdo=Database::connection();
            $pdo->exec("SET time_zone='-04:00'");
            $suffix=" ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            $pdo->exec("CREATE TABLE IF NOT EXISTS gp_event_audit (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                event_at DATETIME NOT NULL,
                timezone_name VARCHAR(64) NOT NULL DEFAULT 'America/Caracas',
                actor_type VARCHAR(30) NOT NULL DEFAULT 'admin',
                user_id BIGINT UNSIGNED NULL,
                user_name VARCHAR(180) NULL,
                user_email VARCHAR(190) NULL,
                user_role VARCHAR(120) NULL,
                module_key VARCHAR(80) NOT NULL,
                action_key VARCHAR(100) NOT NULL,
                event_type VARCHAR(40) NOT NULL,
                entity_type VARCHAR(120) NULL,
                entity_id BIGINT UNSIGNED NULL,
                summary VARCHAR(500) NULL,
                http_method VARCHAR(12) NULL,
                route VARCHAR(500) NULL,
                ip_address VARCHAR(45) NULL,
                user_agent VARCHAR(500) NULL,
                session_hash CHAR(64) NULL,
                metadata_json LONGTEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_event_time (event_at,id),
                INDEX idx_event_user (user_id,event_at),
                INDEX idx_event_email (user_email,event_at),
                INDEX idx_event_module (module_key,event_at),
                INDEX idx_event_type (event_type,event_at),
                INDEX idx_event_actor (actor_type,event_at)
            ){$suffix}");
            // Garantiza el permiso existente de auditoría si una instalación antigua no lo tuviera.
            $pdo->exec("INSERT INTO gp_admin_permissions (permission_key,module_key,label,description,sort_order)
                VALUES ('audit.view','seguridad','Ver auditoría de eventos','Consultar la trazabilidad completa del sistema',50)
                ON DUPLICATE KEY UPDATE label=VALUES(label),description=VALUES(description)");
            // Dirección y Auditoría deben poder consultar el nuevo centro de trazabilidad.
            foreach(['Dirección','Auditoría','Superadministrador'] as $roleName){
                $role=$pdo->prepare('SELECT id FROM gp_admin_roles WHERE name=? LIMIT 1');$role->execute([$roleName]);$roleId=(int)($role->fetchColumn()?:0);
                $perm=(int)($pdo->query("SELECT id FROM gp_admin_permissions WHERE permission_key='audit.view' LIMIT 1")->fetchColumn()?:0);
                if($roleId>0&&$perm>0)$pdo->prepare('INSERT IGNORE INTO gp_admin_role_permissions (role_id,permission_id) VALUES (?,?)')->execute([$roleId,$perm]);
            }
            EventAudit::recordAdmin(gp_current_admin(),'system','v18_install','update',null,null,'Instaló Centro de alertas y Auditoría de eventos V18.',['timezone'=>'America/Caracas'],$pdo);
            $message='V18 instalada correctamente. El Centro de alertas y la Auditoría de eventos ya están activos con hora Venezuela.';
        }catch(Throwable $e){$error='No fue posible instalar V18: '.$e->getMessage();}
    }
}
$csrf=gp_csrf_token();
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>GRANDPRIX · Actualización V18</title><style>*{box-sizing:border-box}body{margin:0;background:#eef4f9;font-family:Inter,Arial,sans-serif;color:#0c2945;min-height:100vh;display:grid;place-items:center;padding:22px}.card{width:min(720px,100%);background:#fff;border:1px solid #dce7ef;border-radius:28px;padding:32px;box-shadow:0 24px 70px #092a441c}.eyebrow{color:#176fe8;font-size:11px;font-weight:900;letter-spacing:1.6px}.card h1{font-size:32px;margin:8px 0}.card p{color:#657e94;line-height:1.6}.box{padding:16px;border-radius:18px;background:#f6f9fc;margin:16px 0}.box b{display:block;margin-bottom:8px}.box span{display:block;color:#607a91;font-size:13px;line-height:1.7}.ok,.err{padding:13px 15px;border-radius:14px;margin:14px 0}.ok{background:#eaf9f3;color:#087c58}.err{background:#fff0f3;color:#b82e48}button{border:0;border-radius:14px;padding:14px 18px;background:#0e67df;color:#fff;font-weight:900;cursor:pointer;width:100%}a{display:block;text-align:center;margin-top:14px;color:#176fe8;text-decoration:none;font-weight:800}</style></head><body><main class="card"><span class="eyebrow">GRANDPRIX CONTROL 360 · V18</span><h1>Alertas + Auditoría de eventos</h1><p>Esta actualización habilita el centro financiero de alertas y una trazabilidad transversal con fecha y hora oficial de Venezuela.</p><?php if($message):?><div class="ok"><?=htmlspecialchars($message)?></div><?php endif;?><?php if($error):?><div class="err"><?=htmlspecialchars($error)?></div><?php endif;?><div class="box"><b>Se habilitará</b><span>• Mora x1 y x2, mora crítica de 3+ semanas y recuperación urgente de 5+.</span><span>• Pagos pendientes de conciliación y vencimientos de miércoles.</span><span>• Login/logout, vistas de módulos, descargas, exportaciones, altas, modificaciones, conciliaciones y acciones administrativas.</span><span>• Eventos de Mi GRANDPRIX y solicitudes públicas.</span><span>• Hora oficial: America/Caracas (UTC-4).</span></div><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>"><button>Instalar V18 · Alertas y Auditoría</button></form><a href="../index.php">Volver a Administración</a></main></body></html>
