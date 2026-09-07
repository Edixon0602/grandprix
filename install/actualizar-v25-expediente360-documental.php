<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/Database.php';
require_once dirname(__DIR__) . '/lib/ClientDossierService.php';

gp_start_session();
gp_require_admin(false);
if (!gp_user_can('finance.clients.edit') && !gp_user_can('users.permissions')) {
    http_response_code(403);
    exit('No tienes permiso para instalar la actualización de Expediente 360.');
}

$done=false;$error='';$summary=[];$warnings=[];
if($_SERVER['REQUEST_METHOD']==='POST'){
    $csrf=$_POST['csrf']??null;
    if(!gp_verify_csrf(is_string($csrf)?$csrf:null)){$error='Sesión de seguridad vencida.';}
    else{
        try{
            if(!Database::configured())throw new RuntimeException('La base de datos no está configurada.');
            $pdo=Database::connection();
            $exists=$pdo->query("SHOW TABLES LIKE 'gp_finance_accounts'")->fetchColumn();
            if(!$exists)throw new RuntimeException('No existe gp_finance_accounts. Instala primero el módulo financiero de GRANDPRIX.');

            $pdo->exec("CREATE TABLE IF NOT EXISTS gp_client_dossier_meta (
                account_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
                folder_key VARCHAR(190) NOT NULL,
                home_lat DECIMAL(10,7) NULL,
                home_lng DECIMAL(10,7) NULL,
                home_address VARCHAR(300) NULL,
                home_notes VARCHAR(1000) NULL,
                home_location_updated_at DATETIME NULL,
                updated_by VARCHAR(190) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_gp_dossier_folder (folder_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $pdo->exec("CREATE TABLE IF NOT EXISTS gp_client_dossier_files (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                account_id BIGINT UNSIGNED NOT NULL,
                doc_key VARCHAR(60) NOT NULL,
                category VARCHAR(30) NOT NULL DEFAULT 'documents',
                label VARCHAR(160) NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                stored_path VARCHAR(500) NOT NULL,
                mime_type VARCHAR(100) NOT NULL,
                file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
                source_type VARCHAR(50) NOT NULL DEFAULT 'manual',
                source_id BIGINT UNSIGNED NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'active',
                created_by VARCHAR(190) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_gp_dossier_account (account_id,status,created_at),
                INDEX idx_gp_dossier_doc (account_id,doc_key,status),
                UNIQUE KEY uq_gp_dossier_source (account_id,source_type,source_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $root=dirname(__DIR__).'/config/client-dossiers';
            if(!is_dir($root)&&!mkdir($root,0750,true)&&!is_dir($root))throw new RuntimeException('No se pudo crear config/client-dossiers. Verifica permisos de escritura.');
            $ht=$root.'/.htaccess';
            if(!is_file($ht))@file_put_contents($ht,"Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
            @file_put_contents($root.'/index.html','');

            $service=new ClientDossierService($pdo);
            $sync=$service->syncAll();
            $accounts=(int)$pdo->query("SELECT COUNT(*) FROM gp_finance_accounts WHERE record_status <> 'archived'")->fetchColumn();
            $folders=(int)$pdo->query('SELECT COUNT(*) FROM gp_client_dossier_meta')->fetchColumn();
            $files=(int)$pdo->query("SELECT COUNT(*) FROM gp_client_dossier_files WHERE status='active'")->fetchColumn();
            $paymentPdfs=(int)$pdo->query("SELECT COUNT(*) FROM gp_client_dossier_files WHERE status='active' AND doc_key='payment_pdf'")->fetchColumn();
            $locations=(int)$pdo->query('SELECT COUNT(*) FROM gp_client_dossier_meta WHERE home_lat IS NOT NULL AND home_lng IS NOT NULL')->fetchColumn();
            $summary=[
                'Clientes activos'=>$accounts,
                'Carpetas preparadas'=>$folders,
                'Archivos organizados'=>$files,
                'PDF de pagos'=>$paymentPdfs,
                'Ubicaciones GPS'=>$locations,
                'Documentos importados ahora'=>(int)($sync['filesImported']??0),
                'PDF generados ahora'=>(int)($sync['paymentPdfs']??0),
            ];
            $warnings=array_slice((array)($sync['errors']??[]),0,20);
            $done=true;
        }catch(Throwable $e){$error=$e->getMessage();}
    }
}
$csrf=gp_csrf_token();
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>GRANDPRIX V25 · Expediente 360</title><style>
*{box-sizing:border-box}body{margin:0;background:linear-gradient(160deg,#edf4fa,#f7fbff);font-family:Inter,Arial,sans-serif;color:#082744}.wrap{max-width:980px;margin:46px auto;padding:20px}.card{background:#fff;border:1px solid #dce7f0;border-radius:28px;padding:32px;box-shadow:0 30px 80px rgba(8,39,68,.10)}.tag{display:inline-flex;align-items:center;gap:7px;background:#eaf4ff;color:#1268c8;padding:8px 12px;border-radius:999px;font-size:11px;font-weight:900;letter-spacing:.5px}h1{font-size:30px;margin:12px 0 8px;letter-spacing:-.6px}.lead{color:#617c93;line-height:1.65;max-width:820px}.ok,.err,.warn{padding:17px 18px;border-radius:17px;margin:18px 0}.ok{background:#eaf9f2;border:1px solid #cae9db;color:#116f4e}.err{background:#fff0f2;border:1px solid #f0ced5;color:#a33d50}.warn{background:#fff8e9;border:1px solid #f3e2b5;color:#8a630d}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-top:14px}.grid div{border:1px solid #e5edf4;border-radius:15px;padding:13px;background:#fbfdff}.grid small,.grid b{display:block}.grid small{font-size:9px;text-transform:uppercase;letter-spacing:.5px;color:#7990a4}.grid b{font-size:15px;margin-top:5px}.structure{margin:20px 0;padding:18px;border-radius:18px;background:#071f39;color:#dcecff;font:13px/1.8 ui-monospace,SFMono-Regular,Consolas,monospace}.structure b{color:#62a8ff}button{border:0;border-radius:14px;background:linear-gradient(135deg,#1676ff,#075ddd);color:#fff;padding:14px 20px;font-weight:900;cursor:pointer;box-shadow:0 12px 28px rgba(20,117,255,.22)}a{color:#116edb;font-weight:800}.small{font-size:12px;color:#6a8195}@media(max-width:640px){.wrap{margin:15px auto;padding:10px}.card{padding:22px 16px;border-radius:22px}.grid{grid-template-columns:1fr}h1{font-size:24px}}</style></head><body><div class="wrap"><div class="card"><span class="tag">GRANDPRIX V25 · EXPEDIENTE 360</span><h1>Vista ejecutiva + expediente documental</h1><p class="lead">Prepara la nueva experiencia de Expediente 360, crea la carpeta privada de cada cliente y organiza automáticamente documentos, ubicación de vivienda y PDFs oficiales de pagos sin alterar Traccar ni el monitoreo GPS.</p>
<div class="structure"><b>/config/client-dossiers/00001-NOMBRE_CLIENTE/</b><br>├── DOCUMENTOS/<br>│ &nbsp; ├── CEDULA_IDENTIDAD/<br>│ &nbsp; ├── CONTRATO/<br>│ &nbsp; ├── CARTA_RESIDENCIA/<br>│ &nbsp; ├── FOTO_FRENTE_CASA/<br>│ &nbsp; └── UBICACION_GPS/<br>└── PAGOS_PDF/</div>
<?php if($done): ?><div class="ok"><b>✓ V25 instalada correctamente.</b><div class="grid"><?php foreach($summary as $k=>$v): ?><div><small><?=htmlspecialchars((string)$k)?></small><b><?=htmlspecialchars((string)$v)?></b></div><?php endforeach; ?></div></div><?php if($warnings): ?><div class="warn"><b>Revisiones no bloqueantes</b><br><?php foreach($warnings as $w): ?>• <?=htmlspecialchars((string)$w)?><br><?php endforeach; ?></div><?php endif; ?><p class="small">Los documentos existentes de solicitudes y Mi GRANDPRIX se copian a la nueva estructura cuando pueden vincularse de forma segura. Los recibos se materializan únicamente cuando existe un recibo real; no se inventan PDFs históricos.</p><p><a href="../index.php">Volver a GRANDPRIX</a></p>
<?php elseif($error): ?><div class="err"><b>No se pudo completar la actualización.</b><br><?=htmlspecialchars($error)?></div><?php endif; ?>
<?php if(!$done): ?><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>"><button type="submit">Instalar V25 · Expediente 360</button></form><?php endif; ?>
</div></div></body></html>
