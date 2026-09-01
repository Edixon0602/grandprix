<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/lib/bootstrap.php';
require_once dirname(__DIR__).'/lib/Database.php';

gp_start_session();
gp_require_admin(false);
if(array_key_exists('grandprix_admin_permissions',$_SESSION)&&!gp_user_can('users.permissions')){http_response_code(403);exit('No tienes permiso para ejecutar esta reparación.');}
$csrf=gp_csrf_token();$done=false;$error='';$changes=[];$diag=[];
function gp_v208_table(PDO $pdo,string $table):bool{return (bool)$pdo->query('SHOW TABLES LIKE '.$pdo->quote($table))->fetchColumn();}
function gp_v208_col(PDO $pdo,string $table,string $column):bool{return (bool)$pdo->query('SHOW COLUMNS FROM `'.$table.'` LIKE '.$pdo->quote($column))->fetch();}
function gp_v208_add(PDO $pdo,string $table,string $column,string $definition,array &$changes):void{if(!gp_v208_col($pdo,$table,$column)){$pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");$changes[]="Campo $table.$column agregado";}}
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!gp_verify_csrf((string)($_POST['csrf']??'')))$error='La sesión de seguridad venció. Recarga la página.';
 else try{
  $pdo=Database::connection();
  if(!gp_v208_table($pdo,'gp_finance_accounts'))throw new RuntimeException('No existe gp_finance_accounts. Ejecuta primero la instalación financiera base.');
  $columns=[
   'source_row'=>'INT UNSIGNED NULL UNIQUE AFTER id','full_name'=>'VARCHAR(160) NOT NULL AFTER source_row','identity_document'=>'VARCHAR(40) NULL AFTER full_name','phone'=>'VARCHAR(40) NULL AFTER identity_document','address'=>'VARCHAR(300) NULL AFTER phone','contract_number'=>'VARCHAR(80) NULL AFTER address','weekly_amount'=>'DECIMAL(12,2) NULL AFTER contract_number','financed_amount'=>'DECIMAL(12,2) NULL AFTER weekly_amount','start_date'=>'DATE NULL AFTER financed_amount','model'=>'VARCHAR(120) NULL AFTER start_date','model_family'=>'VARCHAR(120) NULL AFTER model','image_path'=>"VARCHAR(255) NOT NULL DEFAULT 'assets/moto-blue.png' AFTER model_family",'plate'=>'VARCHAR(40) NULL AFTER image_path','total_installments'=>'SMALLINT UNSIGNED NOT NULL DEFAULT 50 AFTER plate','installments_paid'=>'SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER total_installments','installments_late'=>'SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER installments_paid','advance_note'=>'VARCHAR(80) NULL AFTER installments_late','advance_amount'=>'DECIMAL(12,2) NULL AFTER advance_note','referrer'=>'VARCHAR(100) NULL AFTER advance_amount','gps_device_id'=>'BIGINT UNSIGNED NULL AFTER referrer','gps_label'=>'VARCHAR(120) NULL AFTER gps_device_id','notes'=>'VARCHAR(1000) NULL AFTER gps_label','record_status'=>"VARCHAR(20) NOT NULL DEFAULT 'active' AFTER notes",'source_name'=>'VARCHAR(160) NULL AFTER record_status','created_at'=>'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER source_name','updated_at'=>'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at'
  ];
  foreach($columns as $name=>$def)gp_v208_add($pdo,'gp_finance_accounts',$name,$def,$changes);
  $pdo->exec("UPDATE gp_finance_accounts SET record_status='active' WHERE record_status IS NULL OR TRIM(record_status)=''");
  $pdo->exec("UPDATE gp_finance_accounts SET total_installments=50 WHERE total_installments IS NULL OR total_installments=0");
  $pdo->exec("UPDATE gp_finance_accounts SET installments_paid=0 WHERE installments_paid IS NULL");
  $pdo->exec("UPDATE gp_finance_accounts SET installments_late=0 WHERE installments_late IS NULL");
  $pdo->exec("UPDATE gp_finance_accounts SET plate=UPPER(REPLACE(plate,' ','')) WHERE plate IS NOT NULL AND TRIM(plate)<>''");
  $changes[]='Estructura principal de Clientes y créditos verificada';

  $suffix=' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
  $pdo->exec("CREATE TABLE IF NOT EXISTS gp_finance_referrers (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,display_name VARCHAR(100) NOT NULL,source_key VARCHAR(100) NOT NULL UNIQUE,sort_order INT NOT NULL DEFAULT 100,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)$suffix");
  $changes[]='Tabla de referentes verificada';

  if(gp_v208_table($pdo,'gp_finance_installments')){
    $iCols=['due_date'=>'DATE NULL AFTER installment_no','amount_due'=>'DECIMAL(12,2) NULL AFTER due_date','status'=>"VARCHAR(20) NOT NULL DEFAULT 'future' AFTER amount_due",'paid_at'=>'DATETIME NULL AFTER status','paid_payment_id'=>'BIGINT UNSIGNED NULL AFTER paid_at','source_key'=>"VARCHAR(40) NOT NULL DEFAULT 'legacy-bootstrap' AFTER paid_payment_id"];
    foreach($iCols as $name=>$def)gp_v208_add($pdo,'gp_finance_installments',$name,$def,$changes);
    $changes[]='Cronograma de 50 semanas verificado';
  }

  if(!gp_v208_table($pdo,'gp_motorcycle_inventory')){
    $pdo->exec("CREATE TABLE gp_motorcycle_inventory (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,inventory_code VARCHAR(40) NOT NULL UNIQUE,plate VARCHAR(40) NOT NULL UNIQUE,model VARCHAR(120) NOT NULL,color VARCHAR(80) NULL,model_year SMALLINT UNSIGNED NULL,gps_device_id BIGINT UNSIGNED NULL UNIQUE,status VARCHAR(30) NOT NULL DEFAULT 'available',current_finance_account_id BIGINT UNSIGNED NULL,current_customer_id BIGINT UNSIGNED NULL,current_contract_id BIGINT UNSIGNED NULL,vehicle_id BIGINT UNSIGNED NULL,notes VARCHAR(1000) NULL,released_at DATETIME NULL,release_reason VARCHAR(500) NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX idx_gp_inv_status(status),INDEX idx_gp_inv_finance(current_finance_account_id))$suffix");
    $changes[]='Inventario V20 creado';
  }else{
    $invCols=['inventory_code'=>'VARCHAR(40) NULL AFTER id','plate'=>'VARCHAR(40) NULL AFTER inventory_code','model'=>"VARCHAR(120) NOT NULL DEFAULT 'Motocicleta' AFTER plate",'gps_device_id'=>'BIGINT UNSIGNED NULL AFTER model_year','status'=>"VARCHAR(30) NOT NULL DEFAULT 'available' AFTER gps_device_id",'current_finance_account_id'=>'BIGINT UNSIGNED NULL AFTER status','current_customer_id'=>'BIGINT UNSIGNED NULL AFTER current_finance_account_id','current_contract_id'=>'BIGINT UNSIGNED NULL AFTER current_customer_id','vehicle_id'=>'BIGINT UNSIGNED NULL AFTER current_contract_id','released_at'=>'DATETIME NULL AFTER notes','release_reason'=>'VARCHAR(500) NULL AFTER released_at'];
    foreach($invCols as $name=>$def)gp_v208_add($pdo,'gp_motorcycle_inventory',$name,$def,$changes);
    $changes[]='Inventario V20 verificado';
  }

  // La auditoría nunca debe impedir guardar un cliente, pero verificamos su tabla para mantener trazabilidad.
  if(!gp_v208_table($pdo,'gp_admin_audit')){
    $pdo->exec("CREATE TABLE gp_admin_audit (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NULL,user_email VARCHAR(190) NOT NULL,module_key VARCHAR(80) NOT NULL,action_key VARCHAR(80) NOT NULL,entity_type VARCHAR(100) NULL,entity_id BIGINT NULL,summary VARCHAR(300) NULL,before_json LONGTEXT NULL,after_json LONGTEXT NULL,ip_hash CHAR(64) NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX idx_gp_audit_created(created_at),INDEX idx_gp_audit_user(user_id,created_at))$suffix");
    $changes[]='Tabla de auditoría administrativa creada';
  }

  // Prueba real de escritura, sin alterar información: no-op dentro de transacción y rollback.
  $sample=(int)($pdo->query("SELECT id FROM gp_finance_accounts ORDER BY id LIMIT 1")->fetchColumn()?:0);
  $pdo->beginTransaction();
  try{
    if($sample>0){$q=$pdo->prepare('UPDATE gp_finance_accounts SET full_name=full_name WHERE id=?');$q->execute([$sample]);$diag[]='Prueba de escritura sobre cliente existente: OK';}
    else{$diag[]='No hay cliente de muestra; la estructura quedó lista para INSERT.';}
    $pdo->rollBack();
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw new RuntimeException('La prueba de escritura falló: '.$e->getMessage(),0,$e);}

  $diag[]='Clientes activos: '.(int)$pdo->query("SELECT COUNT(*) FROM gp_finance_accounts WHERE record_status<>'archived'")->fetchColumn();
  $diag[]='Columnas financieras verificadas: '.count($columns);
  $diag[]='La sincronización secundaria ya no revierte el guardado principal del cliente.';
  $done=true;
 }catch(Throwable $e){$error=$e->getMessage();}
}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>GRANDPRIX V20.8</title><style>body{margin:0;background:#eef4f9;color:#0a2948;font:15px system-ui,-apple-system,Segoe UI,sans-serif}.wrap{max-width:920px;margin:38px auto;padding:18px}.card{background:#fff;border:1px solid #dce7ef;border-radius:26px;padding:30px;box-shadow:0 24px 70px #082d5018}.tag{display:inline-flex;padding:7px 11px;border-radius:999px;background:#eaf4ff;color:#1269d9;font-size:12px;font-weight:900}h1{font-size:31px;margin:14px 0 8px}p{color:#61798f;line-height:1.55}.ok,.err{padding:16px;border-radius:16px;margin:18px 0}.ok{background:#e9faf3;color:#127452}.err{background:#fff0f2;color:#ad2943}.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:18px 0}.grid div{padding:15px;border:1px solid #e2eaf1;border-radius:15px;background:#f8fbfe}.grid b{display:block;margin-bottom:5px}.grid span{font-size:12px;color:#6d8296}button,a{display:inline-flex;border:0;border-radius:13px;padding:13px 18px;background:#126df5;color:white;font-weight:900;text-decoration:none;cursor:pointer}ul{line-height:1.8}@media(max-width:640px){.grid{grid-template-columns:1fr}.card{padding:21px}.wrap{margin:14px auto}}</style></head><body><div class="wrap"><section class="card"><span class="tag">GRANDPRIX CONTROL 360 · V20.8</span><h1>Reparar guardado de Clientes y Expediente 360</h1><p>Verifica la estructura financiera y desacopla el guardado principal de sincronizaciones auxiliares. No borra clientes, pagos, contratos, recibos ni historial.</p><div class="grid"><div><b>Cliente primero</b><span>El expediente financiero se guarda de forma segura.</span></div><div><b>Placa/GPS protegidos</b><span>Los conflictos reales siguen bloqueando la operación.</span></div><div><b>Inventario tolerante</b><span>Un fallo técnico secundario no elimina el cliente.</span></div><div><b>Diagnóstico real</b><span>Errores SQL posteriores tendrán código técnico identificable.</span></div></div><?php if($error):?><div class="err"><b>No se pudo completar la reparación.</b><br><?=htmlspecialchars($error)?></div><?php endif;?><?php if($done):?><div class="ok"><b>Reparación completada.</b><ul><?php foreach(array_unique($changes) as $c):?><li><?=htmlspecialchars($c)?></li><?php endforeach;?><?php foreach($diag as $d):?><li><?=htmlspecialchars($d)?></li><?php endforeach;?></ul></div><a href="../">Volver a Administración</a><?php else:?><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>"><button>Reparar guardado V20.8</button></form><?php endif;?></section></div></body></html>
