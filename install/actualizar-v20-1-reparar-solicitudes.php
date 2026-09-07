<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/lib/bootstrap.php';
require_once dirname(__DIR__).'/lib/Database.php';
require_once dirname(__DIR__).'/lib/PublicApplicationService.php';

gp_start_session();
gp_require_admin(false);
if(array_key_exists('grandprix_admin_permissions',$_SESSION) && !gp_user_can('users.permissions')){http_response_code(403);exit('No tienes permiso para ejecutar esta reparación.');}
$csrf=gp_csrf_token();$done=false;$error='';$changes=[];$warnings=[];$stats=[];
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        if(!gp_verify_csrf((string)($_POST['csrf']??'')))throw new RuntimeException('La sesión de seguridad venció. Recarga la página.');
        if(!Database::configured())throw new RuntimeException('La base de datos no está configurada.');
        $pdo=Database::connection();
        $suffix=" ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        // 1) Tabla base de solicitudes: si no existe, se crea sin tocar ninguna otra data.
        if(!$pdo->query("SHOW TABLES LIKE 'gp_finance_applications'")->fetchColumn()){
            $pdo->exec("CREATE TABLE gp_finance_applications (
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
                INDEX idx_gp_finapp_status (status,requested_at),
                INDEX idx_gp_finapp_name (applicant_name)
            ){$suffix}");
            $changes[]='Tabla base de solicitudes creada';
        }

        // 2) Consolidación de columnas V11 + V16 + V19. Sin depender del orden de instaladores anteriores.
        $columns=[
            'first_names'=>'VARCHAR(100) NULL','last_names'=>'VARCHAR(100) NULL','birth_date'=>'DATE NULL','age'=>'SMALLINT UNSIGNED NULL',
            'phone_2'=>'VARCHAR(40) NULL','address'=>'VARCHAR(300) NULL','occupation'=>'VARCHAR(160) NULL','family_load'=>'SMALLINT UNSIGNED NULL',
            'monthly_income'=>'DECIMAL(12,2) NULL','email'=>'VARCHAR(190) NULL','referral_type'=>'VARCHAR(30) NULL','referral_detail'=>'VARCHAR(160) NULL',
            'model_requested_2'=>'VARCHAR(120) NULL','documents_status'=>"VARCHAR(30) NOT NULL DEFAULT 'pending'",'validation_notes'=>'VARCHAR(500) NULL',
            'visit_status'=>"VARCHAR(30) NOT NULL DEFAULT 'locked'",'visit_notes'=>'VARCHAR(500) NULL','latitude'=>'DECIMAL(10,7) NULL','longitude'=>'DECIMAL(10,7) NULL',
            'facade_photo_path'=>'VARCHAR(255) NULL','appointment_status'=>"VARCHAR(30) NOT NULL DEFAULT 'pending'",'appointment_at'=>'DATETIME NULL','office_notes'=>'VARCHAR(500) NULL',
            'access_token_hash'=>'CHAR(64) NULL','public_submitted'=>'TINYINT(1) NOT NULL DEFAULT 0','delivered_at'=>'DATETIME NULL','delivery_notes'=>'VARCHAR(500) NULL',
            'portal_customer_id'=>'BIGINT UNSIGNED NULL','portal_activation_status'=>"VARCHAR(30) NOT NULL DEFAULT 'not_created'",'portal_activated_at'=>'DATETIME NULL',
            'formalized_at'=>'DATETIME NULL','formalization_finance_account_id'=>'BIGINT UNSIGNED NULL'
        ];
        $added=0;
        foreach($columns as $name=>$definition){
            $q=$pdo->query("SHOW COLUMNS FROM gp_finance_applications LIKE ".$pdo->quote($name));
            if(!$q->fetch()){$pdo->exec("ALTER TABLE gp_finance_applications ADD COLUMN `{$name}` {$definition}");$added++;}
        }
        $changes[]='Estructura de solicitudes consolidada ('.$added.' campos faltantes agregados)';
        $pdo->exec("UPDATE gp_finance_applications SET age=TIMESTAMPDIFF(YEAR,birth_date,CURDATE()) WHERE age IS NULL AND birth_date IS NOT NULL");

        // 3) Tablas de documentos, historial y checklist.
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
            INDEX idx_gp_finappdoc_app (application_id,doc_type,status)
        ){$suffix}");
        $pdo->exec("CREATE TABLE IF NOT EXISTS gp_finance_application_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            application_id BIGINT UNSIGNED NOT NULL,
            event_key VARCHAR(80) NOT NULL,
            label VARCHAR(300) NOT NULL,
            created_by VARCHAR(190) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_gp_finappevent_app (application_id,created_at)
        ){$suffix}");
        $pdo->exec("CREATE TABLE IF NOT EXISTS gp_finance_application_checklist (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            application_id BIGINT UNSIGNED NOT NULL,
            item_key VARCHAR(80) NOT NULL,
            item_group VARCHAR(30) NOT NULL DEFAULT 'documents',
            label VARCHAR(160) NOT NULL,
            required TINYINT(1) NOT NULL DEFAULT 1,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            source VARCHAR(30) NOT NULL DEFAULT 'system',
            document_id BIGINT UNSIGNED NULL,
            received_at DATETIME NULL,
            validated_at DATETIME NULL,
            notes VARCHAR(500) NULL,
            sort_order INT NOT NULL DEFAULT 100,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_gp_finappcheck_item (application_id,item_key),
            INDEX idx_gp_finappcheck_status (application_id,status,required),
            INDEX idx_gp_finappcheck_received (application_id,received_at)
        ){$suffix}");
        $changes[]='Documentos, historial y checklist verificados';

        // 4) Directorio protegido para recaudos del sitio público.
        $root=dirname(__DIR__).'/config/application-files';
        if(!is_dir($root) && !mkdir($root,0750,true) && !is_dir($root))throw new RuntimeException('No se pudo preparar config/application-files.');
        @file_put_contents($root.'/.htaccess',"Require all denied\nDeny from all\nOptions -Indexes\n");

        // 5) Sincronización de checklists existentes. Un expediente defectuoso no bloquea los demás.
        $service=PublicApplicationService::create();
        $ids=$pdo->query('SELECT id FROM gp_finance_applications ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        $synced=0;
        foreach($ids as $id){
            try{$service->ensureChecklist((int)$id);$synced++;}
            catch(Throwable $e){$warnings[]='Solicitud #'.(int)$id.': '.$e->getMessage();}
        }
        $changes[]='Checklist sincronizado para '.$synced.' de '.count($ids).' expedientes';

        // 6) Prueba real del mismo método que consume el panel administrativo.
        $list=$service->adminList();
        $stats['applications']=count($list);
        $stats['documents']=(int)$pdo->query('SELECT COUNT(*) FROM gp_finance_application_documents')->fetchColumn();
        $stats['checklist']=(int)$pdo->query('SELECT COUNT(*) FROM gp_finance_application_checklist')->fetchColumn();
        $stats['public']=(int)$pdo->query("SELECT COUNT(*) FROM gp_finance_applications WHERE public_submitted=1")->fetchColumn();

        $pdo->exec("CREATE TABLE IF NOT EXISTS gp_schema_meta (meta_key VARCHAR(80) PRIMARY KEY,meta_value VARCHAR(255) NOT NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP){$suffix}");
        $m=$pdo->prepare("INSERT INTO gp_schema_meta(meta_key,meta_value) VALUES('grandprix_v20_1','20.1.0') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");$m->execute();
        $changes[]='Conexión Sitio público → Solicitudes de crédito verificada';
        $done=true;
    }catch(Throwable $e){$error=$e->getMessage();}
}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>GRANDPRIX · Reparar Solicitudes</title><style>
body{margin:0;background:#f2f6fa;color:#092642;font:15px system-ui,-apple-system,Segoe UI,sans-serif}.wrap{max-width:980px;margin:36px auto;padding:18px}.card{background:#fff;border:1px solid #dce6ef;border-radius:26px;padding:30px;box-shadow:0 22px 60px #082d5014}.tag{display:inline-flex;padding:8px 12px;border-radius:999px;background:#e9f3ff;color:#1268d6;font-size:12px;font-weight:900}h1{font-size:34px;margin:14px 0 8px}p{color:#60778e;line-height:1.6}.flow,.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:11px;margin:20px 0}.flow div,.stats div{padding:15px;border:1px solid #e2eaf1;border-radius:16px;background:#f8fbfe}.flow b,.stats b{display:block}.flow span,.stats span{display:block;margin-top:5px;color:#6b8195;font-size:12px}.stats strong{display:block;font-size:28px;margin-top:5px}.ok,.err,.warn{padding:15px;border-radius:15px;margin:18px 0}.ok{background:#e9f9f2;color:#167454}.err{background:#fff0f2;color:#b43248}.warn{background:#fff8e7;color:#8b6611}button,a.btn{display:inline-flex;border:0;border-radius:14px;padding:14px 19px;background:#146df5;color:#fff;font-weight:900;text-decoration:none;cursor:pointer}ul{line-height:1.75}@media(max-width:760px){.flow,.stats{grid-template-columns:1fr 1fr}.card{padding:22px}}@media(max-width:460px){.flow,.stats{grid-template-columns:1fr}}
</style></head><body><div class="wrap"><section class="card"><span class="tag">GRANDPRIX CONTROL 360 · REPARACIÓN V20.1</span><h1>Solicitudes de crédito</h1><p><b>Sí:</b> este es el módulo que recibe automáticamente las solicitudes enviadas desde el website público. Este reparador consolida la estructura de base de datos de Solicitudes sin borrar expedientes existentes.</p><div class="flow"><div><b>1. Website</b><span>El cliente envía su solicitud.</span></div><div><b>2. Base de datos</b><span>Se guarda el expediente y sus recaudos.</span></div><div><b>3. Solicitudes de crédito</b><span>Aparece automáticamente en el pipeline.</span></div><div><b>4. Seguimiento</b><span>Checklist, visita, aprobación y formalización.</span></div></div><?php if($error):?><div class="err"><b>No se pudo completar la reparación.</b><br><?=htmlspecialchars($error)?></div><?php endif;?><?php if($done):?><div class="ok"><b>Reparación completada.</b><ul><?php foreach($changes as $c):?><li><?=htmlspecialchars($c)?></li><?php endforeach;?></ul></div><div class="stats"><div><span>Solicitudes</span><strong><?=number_format((int)$stats['applications'])?></strong></div><div><span>Enviadas por web</span><strong><?=number_format((int)$stats['public'])?></strong></div><div><span>Documentos</span><strong><?=number_format((int)$stats['documents'])?></strong></div><div><span>Ítems checklist</span><strong><?=number_format((int)$stats['checklist'])?></strong></div></div><?php if($warnings):?><div class="warn"><b>Advertencias de sincronización</b><ul><?php foreach(array_slice($warnings,0,20) as $w):?><li><?=htmlspecialchars($w)?></li><?php endforeach;?></ul></div><?php endif;?><a class="btn" href="../#solicitudes">Abrir Solicitudes de crédito</a> <a class="btn" href="../public/" target="_blank">Abrir sitio público</a><?php else:?><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>"><button>Reparar Solicitudes de crédito</button></form><?php endif;?></section></div></body></html>
