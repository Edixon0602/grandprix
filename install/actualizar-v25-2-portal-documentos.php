<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/Database.php';
require_once dirname(__DIR__) . '/lib/ClientDossierService.php';

gp_start_session();
gp_require_admin(false);
if (!gp_user_can('finance.clients.edit') && !gp_user_can('users.permissions')) {
    http_response_code(403);
    exit('No tienes permiso para instalar esta actualización.');
}

$done=false;$error='';$summary=[];$warnings=[];
if($_SERVER['REQUEST_METHOD']==='POST'){
    $csrf=$_POST['csrf']??null;
    if(!gp_verify_csrf(is_string($csrf)?$csrf:null)){$error='Sesión de seguridad vencida.';}
    else{
        try{
            if(!Database::configured())throw new RuntimeException('La base de datos no está configurada.');
            $pdo=Database::connection();
            $service=new ClientDossierService($pdo);
            if(!$service->ready())throw new RuntimeException('Primero ejecuta V25.1 para crear el expediente documental por cédula.');
            $root=dirname(__DIR__).'/config/clientes';
            if(!is_dir($root)&&!mkdir($root,0750,true)&&!is_dir($root))throw new RuntimeException('No se pudo preparar config/clientes.');
            $ht=$root.'/.htaccess';
            if(!is_file($ht))@file_put_contents($ht,"Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
            @file_put_contents($root.'/index.html','');
            $sync=$service->syncAll();
            $customers=0;$linked=0;$withFiles=0;$withoutLink=0;
            if($pdo->query("SHOW TABLES LIKE 'gp_customers'")->fetchColumn()){
                $customers=(int)$pdo->query("SELECT COUNT(*) FROM gp_customers WHERE status<>'archived'")->fetchColumn();
                $ids=$pdo->query("SELECT id FROM gp_customers WHERE status<>'archived' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
                foreach($ids as $raw){
                    $cid=(int)$raw;$aid=$service->accountIdForPortalCustomer($cid);
                    if($aid>0){
                        $linked++;
                        $q=$pdo->prepare("SELECT COUNT(*) FROM gp_client_dossier_files WHERE account_id=? AND status='active'");$q->execute([$aid]);
                        if((int)$q->fetchColumn()>0)$withFiles++;
                    }else{$withoutLink++;}
                }
            }
            $summary=[
                'Usuarios Mi GRANDPRIX'=>$customers,
                'Usuarios vinculados a expediente'=>$linked,
                'Usuarios con archivos disponibles'=>$withFiles,
                'Usuarios por revisar'=>$withoutLink,
                'Documentos importados'=>(int)($sync['filesImported']??0),
                'PDF de pagos sincronizados'=>(int)($sync['paymentPdfs']??0),
            ];
            $warnings=array_slice((array)($sync['errors']??[]),0,15);
            $done=true;
        }catch(Throwable $e){$error=$e->getMessage();}
    }
}
$csrf=gp_csrf_token();
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>GRANDPRIX V25.2 · Documentos Mi GRANDPRIX</title><style>
*{box-sizing:border-box}body{margin:0;background:linear-gradient(160deg,#edf4fa,#f7fbff);font-family:Inter,Arial,sans-serif;color:#082744}.wrap{max-width:920px;margin:46px auto;padding:20px}.card{background:#fff;border:1px solid #dce7f0;border-radius:28px;padding:32px;box-shadow:0 30px 80px rgba(8,39,68,.10)}.tag{display:inline-flex;background:#eaf4ff;color:#1268c8;padding:8px 12px;border-radius:999px;font-size:11px;font-weight:900}h1{font-size:29px;margin:12px 0 8px}.lead{color:#617c93;line-height:1.65}.ok,.err,.warn{padding:17px 18px;border-radius:17px;margin:18px 0}.ok{background:#eaf9f2;border:1px solid #cae9db;color:#116f4e}.err{background:#fff0f2;border:1px solid #f0ced5;color:#a33d50}.warn{background:#fff8e9;border:1px solid #f3e2b5;color:#8a630d}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-top:14px}.grid div{border:1px solid #e5edf4;border-radius:15px;padding:13px;background:#fbfdff}.grid small,.grid b{display:block}.grid small{font-size:9px;text-transform:uppercase;color:#7990a4}.grid b{font-size:15px;margin-top:5px}.flow{margin:20px 0;padding:18px;border-radius:18px;background:#071f39;color:#dcecff;line-height:1.8}.flow b{color:#63adff}button{border:0;border-radius:14px;background:linear-gradient(135deg,#1676ff,#075ddd);color:#fff;padding:14px 20px;font-weight:900;cursor:pointer}a{color:#116edb;font-weight:800}@media(max-width:640px){.wrap{margin:15px auto;padding:10px}.card{padding:22px 16px}.grid{grid-template-columns:1fr}}</style></head><body><div class="wrap"><div class="card"><span class="tag">GRANDPRIX V25.2 · MI GRANDPRIX</span><h1>Acceso privado a documentos del cliente</h1><p class="lead">Conecta el usuario Mi GRANDPRIX con su expediente por cédula. Cada cliente puede consultar y descargar únicamente sus propios documentos y PDFs de pagos, sin exponer la carpeta privada del servidor.</p><div class="flow"><b>Usuario Mi GRANDPRIX</b> → Cliente financiero → Cédula → <b>/config/clientes/CEDULA/</b><br>↳ DOCUMENTOS/ · contrato · cédula · residencia · foto casa · ubicación GPS<br>↳ PAGOS_PDF/ · recibos oficiales</div>
<?php if($done): ?><div class="ok"><b>✓ V25.2 preparada correctamente.</b><div class="grid"><?php foreach($summary as $k=>$v): ?><div><small><?=htmlspecialchars((string)$k)?></small><b><?=htmlspecialchars((string)$v)?></b></div><?php endforeach; ?></div></div><?php if($warnings): ?><div class="warn"><b>Revisiones no bloqueantes</b><br><?php foreach($warnings as $w): ?>• <?=htmlspecialchars((string)$w)?><br><?php endforeach; ?></div><?php endif; ?><p><a href="../cliente/">Abrir Mi GRANDPRIX</a> · <a href="../index.php">Volver a Administración</a></p>
<?php elseif($error): ?><div class="err"><b>No se pudo completar la actualización.</b><br><?=htmlspecialchars($error)?></div><?php endif; ?>
<?php if(!$done): ?><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>"><button type="submit">Instalar V25.2 · Documentos del cliente</button></form><?php endif; ?></div></div></body></html>
