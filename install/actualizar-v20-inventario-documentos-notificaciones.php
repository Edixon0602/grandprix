<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/lib/bootstrap.php';
require_once dirname(__DIR__).'/lib/Database.php';
require_once dirname(__DIR__).'/lib/InventoryService.php';

gp_start_session();gp_require_admin(false);
if(array_key_exists('grandprix_admin_permissions',$_SESSION)&&!gp_user_can('users.permissions')){http_response_code(403);exit('No tienes permiso para ejecutar esta actualización.');}
$done=false;$error='';$changes=[];$csrf=gp_csrf_token();
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!gp_verify_csrf((string)($_POST['csrf']??'')))$error='La sesión de seguridad venció. Recarga la página.';
 else try{
  $pdo=Database::connection();$suffix=' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
  foreach(['gp_customers','gp_vehicles','gp_contracts','gp_finance_accounts','gp_finance_payments'] as $table){if(!$pdo->query('SHOW TABLES LIKE '.$pdo->quote($table))->fetchColumn())throw new RuntimeException('Falta '.$table.'. Ejecuta primero las actualizaciones anteriores.');}
  foreach(['archived_at'=>'DATETIME NULL AFTER last_login_at','archived_reason'=>'VARCHAR(500) NULL AFTER archived_at'] as $name=>$def){if(!$pdo->query('SHOW COLUMNS FROM gp_customers LIKE '.$pdo->quote($name))->fetch()){$pdo->exec("ALTER TABLE gp_customers ADD COLUMN `$name` $def");$changes[]='Campo '.$name.' agregado a clientes';}}
  $pdo->exec("CREATE TABLE IF NOT EXISTS gp_motorcycle_inventory (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    inventory_code VARCHAR(40) NOT NULL UNIQUE,
    plate VARCHAR(40) NOT NULL UNIQUE,
    model VARCHAR(120) NOT NULL,
    color VARCHAR(80) NULL,
    model_year SMALLINT UNSIGNED NULL,
    gps_device_id BIGINT UNSIGNED NULL UNIQUE,
    status VARCHAR(30) NOT NULL DEFAULT 'available',
    current_finance_account_id BIGINT UNSIGNED NULL,
    current_customer_id BIGINT UNSIGNED NULL,
    current_contract_id BIGINT UNSIGNED NULL,
    vehicle_id BIGINT UNSIGNED NULL,
    notes VARCHAR(1000) NULL,
    released_at DATETIME NULL,
    release_reason VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_gp_inv_status(status),INDEX idx_gp_inv_finance(current_finance_account_id),INDEX idx_gp_inv_customer(current_customer_id)
  )$suffix");$changes[]='Inventario único de motocicletas verificado';
  $pdo->exec("CREATE TABLE IF NOT EXISTS gp_vehicle_assignment_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,inventory_id BIGINT UNSIGNED NOT NULL,finance_account_id BIGINT UNSIGNED NULL,customer_id BIGINT UNSIGNED NULL,contract_id BIGINT UNSIGNED NULL,event_key VARCHAR(40) NOT NULL,notes VARCHAR(500) NULL,created_by VARCHAR(190) NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_gp_vah_inventory(inventory_id,created_at),INDEX idx_gp_vah_customer(customer_id,created_at)
  )$suffix");$changes[]='Historial de asignaciones y liberaciones verificado';
  $pdo->exec("CREATE TABLE IF NOT EXISTS gp_customer_notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,customer_id BIGINT UNSIGNED NOT NULL,type VARCHAR(40) NOT NULL,title VARCHAR(160) NOT NULL,message VARCHAR(500) NOT NULL,entity_type VARCHAR(80) NULL,entity_id BIGINT UNSIGNED NULL,read_at DATETIME NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_gp_cnot_customer(customer_id,read_at,created_at),CONSTRAINT fk_gp_cnot_customer FOREIGN KEY(customer_id) REFERENCES gp_customers(id) ON DELETE CASCADE
  )$suffix");$changes[]='Notificaciones de Mi GRANDPRIX verificadas';
  $pdo->exec("CREATE TABLE IF NOT EXISTS gp_customer_documents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,customer_id BIGINT UNSIGNED NOT NULL,document_type VARCHAR(40) NOT NULL,label VARCHAR(160) NOT NULL,original_name VARCHAR(255) NOT NULL,stored_path VARCHAR(255) NOT NULL,mime_type VARCHAR(80) NOT NULL,file_size INT UNSIGNED NOT NULL,visible_to_customer TINYINT(1) NOT NULL DEFAULT 1,status VARCHAR(20) NOT NULL DEFAULT 'active',created_by VARCHAR(190) NULL,uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_gp_cdoc_customer(customer_id,status,visible_to_customer),CONSTRAINT fk_gp_cdoc_customer FOREIGN KEY(customer_id) REFERENCES gp_customers(id) ON DELETE CASCADE
  )$suffix");$changes[]='Archivo digital de documentos del cliente verificado';

  // Normalizamos placas activas antes del backfill.
  $pdo->exec("UPDATE gp_finance_accounts SET plate=UPPER(REPLACE(plate,' ','')) WHERE plate IS NOT NULL AND TRIM(plate)<>''");
  $pdo->exec("UPDATE gp_vehicles SET plate=UPPER(REPLACE(plate,' ','')) WHERE plate IS NOT NULL AND TRIM(plate)<>''");
  // Primero, unidades físicas conocidas por la capa GPS/contratos.
  $vehicles=$pdo->query("SELECT id,code,plate,model,color,traccar_device_id,status FROM gp_vehicles WHERE plate IS NOT NULL AND TRIM(plate)<>'' ORDER BY id")->fetchAll();$created=0;
  foreach($vehicles as $v){$plate=mb_strtoupper(str_replace(' ','',trim((string)$v['plate'])));$q=$pdo->prepare('SELECT id FROM gp_motorcycle_inventory WHERE plate=? LIMIT 1');$q->execute([$plate]);$iid=(int)($q->fetchColumn()?:0);if(!$iid){try{$pdo->prepare("INSERT INTO gp_motorcycle_inventory (inventory_code,plate,model,color,gps_device_id,status,vehicle_id) VALUES (?,?,?,?,?,?,?)")->execute([(string)$v['code'],$plate,(string)$v['model'],$v['color']?:null,(int)$v['traccar_device_id'],'available',(int)$v['id']]);$iid=(int)$pdo->lastInsertId();$created++;}catch(Throwable){continue;}}else{$pdo->prepare('UPDATE gp_motorcycle_inventory SET vehicle_id=COALESCE(vehicle_id,?),gps_device_id=COALESCE(gps_device_id,?),model=CASE WHEN model="" THEN ? ELSE model END WHERE id=?')->execute([(int)$v['id'],(int)$v['traccar_device_id'],(string)$v['model'],$iid]);}}
  // Cartera histórica/real: si una placa no estaba en gp_vehicles, también debe existir en Inventario.
  $accounts=$pdo->query("SELECT id,full_name,plate,model,gps_device_id,contract_number,record_status FROM gp_finance_accounts WHERE plate IS NOT NULL AND TRIM(plate)<>'' ORDER BY record_status='archived',id")->fetchAll();
  foreach($accounts as $a){$plate=mb_strtoupper(str_replace(' ','',trim((string)$a['plate'])));$q=$pdo->prepare('SELECT id,current_finance_account_id FROM gp_motorcycle_inventory WHERE plate=? LIMIT 1');$q->execute([$plate]);$inv=$q->fetch();if(!$inv){try{$pdo->prepare("INSERT INTO gp_motorcycle_inventory (inventory_code,plate,model,gps_device_id,status,current_finance_account_id) VALUES (?,?,?,?,?,?)")->execute(['INV-'.str_pad((string)$a['id'],5,'0',STR_PAD_LEFT),$plate,(string)($a['model']?:'Motocicleta'),$a['gps_device_id']?:null,$a['record_status']==='archived'?'available':'assigned',$a['record_status']==='archived'?null:(int)$a['id']]);$created++;}catch(Throwable){}}elseif($a['record_status']!=='archived'&&empty($inv['current_finance_account_id'])){$pdo->prepare("UPDATE gp_motorcycle_inventory SET current_finance_account_id=?,status='assigned',gps_device_id=COALESCE(gps_device_id,?) WHERE id=?")->execute([(int)$a['id'],$a['gps_device_id']?:null,(int)$inv['id']]);}}
  // Vinculación portal/contrato activa, cuando ya existe.
  $links=$pdo->query("SELECT c.id contract_id,c.customer_id,c.vehicle_id,c.contract_number,v.plate,v.traccar_device_id,u.full_name FROM gp_contracts c INNER JOIN gp_vehicles v ON v.id=c.vehicle_id INNER JOIN gp_customers u ON u.id=c.customer_id WHERE c.status='active' AND v.plate IS NOT NULL")->fetchAll();
  foreach($links as $l){$plate=mb_strtoupper(str_replace(' ','',trim((string)$l['plate'])));$q=$pdo->prepare('SELECT id,current_finance_account_id FROM gp_motorcycle_inventory WHERE plate=? LIMIT 1');$q->execute([$plate]);$inv=$q->fetch();if(!$inv)continue;$fa=$pdo->prepare("SELECT id FROM gp_finance_accounts WHERE contract_number=? AND record_status<>'archived' ORDER BY id DESC LIMIT 1");$fa->execute([(string)$l['contract_number']]);$fid=(int)($fa->fetchColumn()?:0);$pdo->prepare("UPDATE gp_motorcycle_inventory SET status='assigned',current_finance_account_id=COALESCE(?,current_finance_account_id),current_customer_id=?,current_contract_id=?,vehicle_id=?,gps_device_id=COALESCE(gps_device_id,?) WHERE id=?")->execute([$fid?:null,(int)$l['customer_id'],(int)$l['contract_id'],(int)$l['vehicle_id'],(int)$l['traccar_device_id'],(int)$inv['id']]);}
  $changes[]='Inventario reconstruido desde la data real: '.$created.' unidades añadidas';

  $dir=dirname(__DIR__).'/config/customer-documents';if(!is_dir($dir))@mkdir($dir,0750,true);$ht=dirname(__DIR__).'/config/.htaccess';if(!is_file($ht))@file_put_contents($ht,"Require all denied\n");
  // Si la cédula ya fue recibida durante la solicitud, la hacemos disponible en Mi GRANDPRIX sin pedirla otra vez.
  try{
    if($pdo->query("SHOW TABLES LIKE 'gp_finance_application_documents'")->fetchColumn()&&$pdo->query("SHOW TABLES LIKE 'gp_finance_applications'")->fetchColumn()){
      $rows=$pdo->query("SELECT a.portal_customer_id customer_id,d.original_name,d.stored_path,d.mime_type,d.file_size FROM gp_finance_applications a INNER JOIN gp_finance_application_documents d ON d.application_id=a.id WHERE a.portal_customer_id IS NOT NULL AND d.doc_type IN ('identity_document','identity_front','identity_back') ORDER BY d.id")->fetchAll();$copied=0;
      foreach($rows as $d){$cid=(int)$d['customer_id'];$exists=$pdo->prepare("SELECT id FROM gp_customer_documents WHERE customer_id=? AND document_type='identity' AND status='active' LIMIT 1");$exists->execute([$cid]);if($exists->fetch())continue;$src=dirname(__DIR__).'/config/application-files/'.ltrim((string)$d['stored_path'],'/');if(!is_file($src))continue;$cdir=$dir.'/'.$cid;if(!is_dir($cdir))@mkdir($cdir,0750,true);$ext=pathinfo((string)$d['original_name'],PATHINFO_EXTENSION);if($ext==='')$ext=((string)$d['mime_type']==='application/pdf'?'pdf':'jpg');$name='identity-imported-'.bin2hex(random_bytes(6)).'.'.preg_replace('/[^A-Za-z0-9]+/','',$ext);$dest=$cdir.'/'.$name;if(!@copy($src,$dest))continue;@chmod($dest,0640);$pdo->prepare("INSERT INTO gp_customer_documents (customer_id,document_type,label,original_name,stored_path,mime_type,file_size,visible_to_customer,status,created_by) VALUES (?,'identity','Cédula de identidad',?,?,?,?,1,'active','Migración V20')")->execute([$cid,(string)$d['original_name'],$cid.'/'.$name,(string)$d['mime_type'],(int)$d['file_size']]);$copied++;}
      if($copied)$changes[]='Cédulas históricas incorporadas a Mi GRANDPRIX: '.$copied;
    }
  }catch(Throwable){}
  $pdo->prepare("INSERT INTO gp_schema_meta(meta_key,meta_value) VALUES('grandprix_v20','20.0.0') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)")->execute();
  $changes[]='Regla de reasignación segura placa/GPS activada';$changes[]='Pagos reportados por clientes entran automáticamente a conciliación';$changes[]='Notificación de conciliación + PDF automático habilitados';$changes[]='Sección Documentos de Mi GRANDPRIX habilitada';$done=true;
 }catch(Throwable $e){$error=$e->getMessage();}
}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>GRANDPRIX V20</title><style>body{margin:0;background:#f2f6fa;color:#0b2946;font:15px system-ui,-apple-system,Segoe UI,sans-serif}.wrap{max-width:920px;margin:42px auto;padding:18px}.card{background:#fff;border:1px solid #dce6ee;border-radius:25px;padding:30px;box-shadow:0 20px 60px #082d5015}.tag{display:inline-flex;padding:7px 11px;border-radius:999px;background:#eaf3ff;color:#1268d6;font-size:12px;font-weight:900}h1{font-size:32px;margin:14px 0 8px}p{color:#62788d;line-height:1.55}.rules{display:grid;grid-template-columns:repeat(2,1fr);gap:11px;margin:20px 0}.rules div{padding:15px;border-radius:16px;background:#f8fbfe;border:1px solid #e2eaf1}.rules b{display:block;margin-bottom:5px}.rules span{font-size:12px;color:#667d91}.ok,.err{padding:15px;border-radius:15px;margin:18px 0}.ok{background:#e8faf2;color:#157553}.err{background:#fff0f2;color:#b42c47}button,a{display:inline-flex;border:0;border-radius:13px;padding:13px 18px;background:#126df5;color:#fff;font-weight:900;text-decoration:none;cursor:pointer}ul{line-height:1.8}@media(max-width:640px){.rules{grid-template-columns:1fr}.card{padding:21px}.wrap{margin:15px auto}}</style></head><body><div class="wrap"><section class="card"><span class="tag">GRANDPRIX CONTROL 360 · V20</span><h1>Inventario, reasignación, pagos y documentos</h1><p>Esta actualización protege la relación entre motocicleta, placa, GPS y cliente, y completa Mi GRANDPRIX con notificaciones y archivo digital.</p><div class="rules"><div><b>1 placa = 1 motocicleta</b><span>No puede existir en dos unidades del inventario.</span></div><div><b>1 unidad = 1 cliente activo</b><span>Para reasignarla primero debes archivar/desactivar al cliente anterior.</span></div><div><b>Pago reportado → Conciliación</b><span>Aparece de inmediato en Pagos y conciliación.</span></div><div><b>Conciliado → Notificación + PDF</b><span>Mi GRANDPRIX se actualiza automáticamente.</span></div></div><?php if($error):?><div class="err"><?=htmlspecialchars($error)?></div><?php endif;?><?php if($done):?><div class="ok"><b>V20 instalada correctamente.</b><ul><?php foreach($changes as $c):?><li><?=htmlspecialchars($c)?></li><?php endforeach;?></ul></div><a href="../">Abrir Administración</a><?php else:?><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>"><button>Instalar V20</button></form><?php endif;?></section></div></body></html>
