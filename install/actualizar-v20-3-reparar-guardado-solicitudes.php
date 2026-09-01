<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/lib/bootstrap.php';
require_once dirname(__DIR__).'/lib/Database.php';
require_once dirname(__DIR__).'/lib/PublicApplicationService.php';

gp_start_session();
gp_require_admin(false);
if(array_key_exists('grandprix_admin_permissions',$_SESSION) && !gp_user_can('users.permissions')){http_response_code(403);exit('No tienes permiso para ejecutar esta reparación.');}
$csrf=gp_csrf_token();$done=false;$error='';$changes=[];$checks=[];
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        if(!gp_verify_csrf((string)($_POST['csrf']??'')))throw new RuntimeException('La sesión de seguridad venció. Recarga la página.');
        if(!Database::configured())throw new RuntimeException('La base de datos no está configurada.');
        $pdo=Database::connection();
        $service=PublicApplicationService::create();
        $changes=$service->repairPublicSubmissionSchema();
        $required=['application_code','applicant_name','first_names','last_names','identity_document','age','phone','phone_2','address','occupation','family_load','monthly_income','email','referral_type','referral_detail','model_requested','model_requested_2','status','documents_status','visit_status','appointment_status','requested_at','created_by','access_token_hash','public_submitted'];
        foreach($required as $col){$q=$pdo->query("SHOW COLUMNS FROM gp_finance_applications LIKE ".$pdo->quote($col));$checks[$col]=(bool)$q->fetch();}
        $birth=$pdo->query("SHOW COLUMNS FROM gp_finance_applications LIKE 'birth_date'")->fetch();
        $checks['birth_date_nullable']=$birth ? strtoupper((string)$birth['Null'])==='YES' : true;
        foreach(['gp_finance_application_documents','gp_finance_application_events','gp_finance_application_checklist'] as $table){$q=$pdo->query("SHOW TABLES LIKE ".$pdo->quote($table));$checks[$table]=(bool)$q->fetchColumn();}
        $missing=array_keys(array_filter($checks,fn($ok)=>!$ok));
        if($missing)throw new RuntimeException('La reparación terminó pero faltan componentes: '.implode(', ',$missing));
        $suffix=" ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $pdo->exec("CREATE TABLE IF NOT EXISTS gp_schema_meta (meta_key VARCHAR(80) PRIMARY KEY,meta_value VARCHAR(255) NOT NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP){$suffix}");
        $m=$pdo->prepare("INSERT INTO gp_schema_meta(meta_key,meta_value) VALUES('grandprix_v20_3','20.3.0') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");$m->execute();
        $done=true;
    }catch(Throwable $e){$error=$e->getMessage();}
}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>GRANDPRIX · Reparar guardado</title><style>
body{margin:0;background:#f2f6fa;color:#092642;font:15px system-ui,-apple-system,Segoe UI,sans-serif}.wrap{max-width:940px;margin:36px auto;padding:18px}.card{background:#fff;border:1px solid #dce6ef;border-radius:26px;padding:30px;box-shadow:0 22px 60px #082d5014}.tag{display:inline-flex;padding:8px 12px;border-radius:999px;background:#e9f3ff;color:#1268d6;font-size:12px;font-weight:900}h1{font-size:34px;margin:14px 0 8px}p{color:#60778e;line-height:1.6}.ok,.err{padding:15px;border-radius:15px;margin:18px 0}.ok{background:#e9f9f2;color:#167454}.err{background:#fff0f2;color:#b43248}button,a.btn{display:inline-flex;border:0;border-radius:14px;padding:14px 19px;background:#146df5;color:#fff;font-weight:900;text-decoration:none;cursor:pointer}.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin:18px 0}.grid div{padding:14px;border:1px solid #e2eaf1;border-radius:14px;background:#f8fbfd}.grid b{display:block}.grid span{display:block;margin-top:4px;color:#687d91;font-size:12px}@media(max-width:720px){.grid{grid-template-columns:1fr}.card{padding:22px}}
</style></head><body><div class="wrap"><section class="card"><span class="tag">GRANDPRIX CONTROL 360 · V20.3</span><h1>Reparar envío de solicitudes</h1><p>Corrige la estructura de base de datos utilizada por el formulario público, incluyendo instalaciones parciales de versiones anteriores y el campo antiguo de fecha de nacimiento.</p><div class="grid"><div><b>Formulario</b><span>Edad directa + cédula + datos personales.</span></div><div><b>Base de datos</b><span>Columnas y tablas necesarias verificadas.</span></div><div><b>Expediente</b><span>Documentos + checklist + historial.</span></div></div><?php if($error):?><div class="err"><b>No se pudo completar.</b><br><?=htmlspecialchars($error)?></div><?php endif;?><?php if($done):?><div class="ok"><b>Reparación completada.</b><br>El website ya puede guardar nuevas solicitudes en Solicitudes de crédito.<?= $changes ? '<br><small>'.htmlspecialchars(implode(' · ',$changes)).'</small>' : '' ?></div><a class="btn" href="../public/" target="_blank">Probar solicitud pública</a> <a class="btn" href="../#solicitudes">Abrir Solicitudes</a><?php else:?><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>"><button>Reparar guardado de solicitudes</button></form><?php endif;?></section></div></body></html>
