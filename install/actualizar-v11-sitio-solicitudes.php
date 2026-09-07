<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/Database.php';

gp_start_session();
gp_require_admin(false);
if (array_key_exists('grandprix_admin_permissions', $_SESSION) && !gp_user_can('users.permissions')) {
    http_response_code(403); exit('No tienes permiso para ejecutar esta actualización.');
}
$done=false;$error='';$changes=[];$csrf=gp_csrf_token();
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!gp_verify_csrf((string)($_POST['csrf']??''))){$error='La sesión de seguridad venció. Recarga la página.';}
    else{
        try{
            $pdo=Database::connection();$suffix=' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
            $columns=[
                'first_names'=>'VARCHAR(100) NULL AFTER applicant_name','last_names'=>'VARCHAR(100) NULL AFTER first_names','birth_date'=>'DATE NULL AFTER identity_document','phone_2'=>'VARCHAR(40) NULL AFTER phone','address'=>'VARCHAR(300) NULL AFTER phone_2','occupation'=>'VARCHAR(160) NULL AFTER address','family_load'=>'SMALLINT UNSIGNED NULL AFTER occupation','monthly_income'=>'DECIMAL(12,2) NULL AFTER family_load','email'=>'VARCHAR(190) NULL AFTER monthly_income','referral_type'=>'VARCHAR(30) NULL AFTER email','referral_detail'=>'VARCHAR(160) NULL AFTER referral_type','model_requested_2'=>'VARCHAR(120) NULL AFTER model_requested','documents_status'=>"VARCHAR(30) NOT NULL DEFAULT 'pending' AFTER status",'validation_notes'=>'VARCHAR(500) NULL AFTER documents_status','visit_status'=>"VARCHAR(30) NOT NULL DEFAULT 'locked' AFTER validation_notes",'visit_notes'=>'VARCHAR(500) NULL AFTER visit_status','latitude'=>'DECIMAL(10,7) NULL AFTER visit_notes','longitude'=>'DECIMAL(10,7) NULL AFTER latitude','facade_photo_path'=>'VARCHAR(255) NULL AFTER longitude','appointment_status'=>"VARCHAR(30) NOT NULL DEFAULT 'pending' AFTER facade_photo_path",'appointment_at'=>'DATETIME NULL AFTER appointment_status','office_notes'=>'VARCHAR(500) NULL AFTER appointment_at','access_token_hash'=>'CHAR(64) NULL AFTER office_notes','public_submitted'=>'TINYINT(1) NOT NULL DEFAULT 0 AFTER access_token_hash'
            ];
            foreach($columns as $name=>$definition){
                $check=$pdo->query("SHOW COLUMNS FROM gp_finance_applications LIKE ".$pdo->quote($name));
                if(!$check->fetch()){$pdo->exec("ALTER TABLE gp_finance_applications ADD COLUMN `{$name}` {$definition}");$changes[]='Columna '.$name;}
            }
            $pdo->exec("CREATE TABLE IF NOT EXISTS gp_finance_application_documents (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                application_id BIGINT UNSIGNED NOT NULL,
                doc_type VARCHAR(50) NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                stored_path VARCHAR(255) NOT NULL,
                mime_type VARCHAR(100) NOT NULL,
                file_size INT UNSIGNED NOT NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'review',
                admin_notes VARCHAR(500) NULL,
                uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                reviewed_at DATETIME NULL,
                reviewed_by VARCHAR(190) NULL,
                CONSTRAINT fk_gp_finappdoc_app FOREIGN KEY (application_id) REFERENCES gp_finance_applications(id) ON DELETE CASCADE,
                INDEX idx_gp_finappdoc_app (application_id,doc_type,status)
            ){$suffix}");
            $pdo->exec("CREATE TABLE IF NOT EXISTS gp_finance_application_events (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                application_id BIGINT UNSIGNED NOT NULL,
                event_key VARCHAR(80) NOT NULL,
                label VARCHAR(300) NOT NULL,
                created_by VARCHAR(190) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_gp_finappevent_app FOREIGN KEY (application_id) REFERENCES gp_finance_applications(id) ON DELETE CASCADE,
                INDEX idx_gp_finappevent_app (application_id,created_at)
            ){$suffix}");
            $pdo->exec("UPDATE gp_finance_applications SET documents_status=CASE WHEN public_submitted=1 THEN 'review' ELSE documents_status END WHERE documents_status IS NULL OR documents_status=''");
            $meta=$pdo->prepare("INSERT INTO gp_schema_meta (meta_key,meta_value) VALUES ('grandprix_v11','11.0.0') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");$meta->execute();
            $root=dirname(__DIR__).'/config/application-files';if(!is_dir($root)&&!mkdir($root,0750,true)&&!is_dir($root))throw new RuntimeException('No se pudo crear config/application-files.');
            file_put_contents($root.'/.htaccess',"Require all denied\nDeny from all\nOptions -Indexes\n");
            $changes[]='Flujo público de solicitudes y documentos habilitado';$done=true;
        }catch(Throwable $e){$error=$e->getMessage();}
    }
}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>GRANDPRIX V11</title><style>body{margin:0;background:#f3f7fb;color:#0b2743;font:15px system-ui,-apple-system,Segoe UI,sans-serif}.wrap{max-width:850px;margin:50px auto;padding:20px}.card{background:#fff;border:1px solid #dbe6ef;border-radius:24px;padding:30px;box-shadow:0 20px 60px #0b274315}.tag{display:inline-block;padding:7px 11px;border-radius:999px;background:#e9f3ff;color:#1265cf;font-size:12px;font-weight:800}h1{font-size:32px;margin:14px 0 8px}p{color:#61778d;line-height:1.55}.ok,.err{padding:14px;border-radius:14px;margin:18px 0}.ok{background:#e9f9f2;color:#167454}.err{background:#fff0f2;color:#b43248}button,a.btn{display:inline-flex;border:0;border-radius:13px;padding:13px 18px;background:#146df5;color:white;font-weight:800;text-decoration:none;cursor:pointer}ul{line-height:1.8;color:#536b80}</style></head><body><div class="wrap"><div class="card"><span class="tag">GRANDPRIX CONTROL 360 · V11</span><h1>Sitio público + flujo de crédito</h1><p>Esta actualización agrega el sitio comercial, solicitud digital, documentación, validación, visita con ubicación GPS/fachada y cita en oficina. No modifica Traccar, webhook, comandos ni telemetría.</p><?php if($error):?><div class="err"><?=htmlspecialchars($error)?></div><?php endif;?><?php if($done):?><div class="ok"><b>Actualización completada.</b><ul><?php foreach($changes as $c):?><li><?=htmlspecialchars($c)?></li><?php endforeach;?></ul></div><a class="btn" href="../">Abrir Administración</a> <a class="btn" href="../public/">Abrir sitio público</a><?php else:?><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>"><button>Instalar actualización V11</button></form><?php endif;?></div></div></body></html>
