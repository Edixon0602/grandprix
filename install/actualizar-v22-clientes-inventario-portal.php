<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/lib/bootstrap.php';
require_once dirname(__DIR__).'/lib/Database.php';

gp_start_session();
gp_require_admin(false);
if(array_key_exists('grandprix_admin_permissions',$_SESSION)&&!gp_user_can('users.permissions')&&!gp_user_can('finance.clients.edit')){http_response_code(403);exit('No tienes permiso para ejecutar esta actualización.');}
$done=false;$error='';$changes=[];$csrf=gp_csrf_token();
$columnExists=static function(PDO $pdo,string $table,string $column): bool{try{$q=$pdo->query('SHOW COLUMNS FROM `'.str_replace('`','',$table).'` LIKE '.$pdo->quote($column));return (bool)$q->fetch();}catch(Throwable){return false;}};
$tableExists=static function(PDO $pdo,string $table): bool{try{return (bool)$pdo->query('SHOW TABLES LIKE '.$pdo->quote($table))->fetchColumn();}catch(Throwable){return false;}};
$imageFor=static function(string $brand,string $model): string{
  $v=mb_strtolower(trim($brand.' '.$model));
  $map=[['/(^|\\s)(bera\\s*)?leon(\\s|$)/u','../assets/inventory-models/leon-silver.png'],['/(^|\\s)(bera\\s*)?sbr(\\s|$)/u','../assets/inventory-models/sbr-blue.png'],['/(^|\\s)(bera\\s*)?brf(\\s|$)/u','../assets/inventory-models/brf-red.png'],['/veloz/u','../assets/inventory-models/veloz-white.png'],['/socialista/u','../assets/inventory-models/socialista-blue.png'],['/lovis/u','../assets/inventory-models/lovis-cream.png'],['/kadi/u','../assets/inventory-models/kadi-classic-red.png'],['/(^|\\s)x1(\\s|$)/u','../assets/inventory-models/x1-yellow.png'],['/aguila/u','../assets/inventory-models/aguila-black.png'],['/gbr/u','../assets/inventory-models/gbr-black.png'],['/express/u','../assets/inventory-models/express-blue.png']];
  foreach($map as [$pattern,$path])if(preg_match($pattern,$v))return $path;return '../assets/inventory-models/generic-default.png';
};
if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!gp_verify_csrf((string)($_POST['csrf']??'')))$error='La sesión de seguridad venció. Recarga la página.';
  else try{
    $pdo=Database::connection();
    foreach(['gp_finance_accounts','gp_motorcycle_inventory','gp_customers','gp_contracts','gp_vehicles','gp_contract_weeks'] as $table){if(!$tableExists($pdo,$table))throw new RuntimeException('Falta '.$table.'. Ejecuta primero las actualizaciones anteriores, incluida V21.');}
    if(!$columnExists($pdo,'gp_customers','finance_account_id')){$pdo->exec('ALTER TABLE gp_customers ADD COLUMN finance_account_id BIGINT UNSIGNED NULL AFTER id');$changes[]='Vínculo cliente financiero → Mi GRANDPRIX agregado';}
    try{$q=$pdo->query("SHOW INDEX FROM gp_customers WHERE Key_name='idx_gp_customer_finance'");if(!$q->fetch()){$pdo->exec('CREATE INDEX idx_gp_customer_finance ON gp_customers(finance_account_id)');$changes[]='Índice de vínculo financiero creado';}}catch(Throwable){}
    if(!$columnExists($pdo,'gp_vehicles','traccar_unique_id')){$pdo->exec('ALTER TABLE gp_vehicles ADD COLUMN traccar_unique_id VARCHAR(120) NULL AFTER traccar_device_id');$changes[]='IMEI/Unique ID habilitado en motos del portal';}
    if(!$columnExists($pdo,'gp_vehicles','image_path')){$pdo->exec("ALTER TABLE gp_vehicles ADD COLUMN image_path VARCHAR(255) NULL AFTER model");$changes[]='Imagen de modelo habilitada en motos del portal';}
    if(!$columnExists($pdo,'gp_vehicles','color')){$pdo->exec('ALTER TABLE gp_vehicles ADD COLUMN color VARCHAR(80) NULL AFTER model');$changes[]='Color habilitado en motos del portal';}

    // Primero usa vínculos inequívocos ya guardados en Inventario.
    $pdo->exec("UPDATE gp_customers u INNER JOIN gp_motorcycle_inventory i ON i.current_customer_id=u.id AND i.current_finance_account_id IS NOT NULL SET u.finance_account_id=i.current_finance_account_id WHERE u.finance_account_id IS NULL");

    // Luego intenta recuperar clientes históricos por cédula normalizada.
    $linkedByIdentity=0;
    $customers=$pdo->query("SELECT id,identity_document FROM gp_customers WHERE finance_account_id IS NULL AND status<>'archived' ORDER BY id")->fetchAll();
    foreach($customers as $c){
      $identity=preg_replace('/[^A-Za-z0-9]/','',mb_strtoupper((string)($c['identity_document']??'')))?:'';if(strlen($identity)<5)continue;
      $q=$pdo->prepare("SELECT id FROM gp_finance_accounts WHERE record_status<>'archived' AND UPPER(REPLACE(REPLACE(REPLACE(COALESCE(identity_document,''),'.',''),'-',''),' ',''))=? ORDER BY id DESC LIMIT 2");
      $q->execute([$identity]);$matches=$q->fetchAll(PDO::FETCH_COLUMN);if(count($matches)===1){$pdo->prepare('UPDATE gp_customers SET finance_account_id=? WHERE id=? AND finance_account_id IS NULL')->execute([(int)$matches[0],(int)$c['id']]);$linkedByIdentity++;}
    }
    if($linkedByIdentity)$changes[]='Usuarios históricos vinculados por cédula: '.$linkedByIdentity;

    // Sincroniza contratos/motos existentes del portal con el Inventario maestro, sin cambiar la placa física.
    $synced=0;
    $rows=$pdo->query("SELECT u.id customer_id,u.finance_account_id,c.id contract_id,c.vehicle_id,i.id inventory_id,i.inventory_code,i.plate,i.brand,i.model,i.color,i.gps_device_id,i.gps_unique_id
      FROM gp_customers u
      INNER JOIN gp_motorcycle_inventory i ON i.current_finance_account_id=u.finance_account_id AND i.status='assigned'
      LEFT JOIN gp_contracts c ON c.customer_id=u.id AND c.status IN ('active','completed')
      WHERE u.finance_account_id IS NOT NULL AND u.status<>'archived'
      ORDER BY u.id,c.status='active' DESC,c.id DESC")->fetchAll();
    $seen=[];
    foreach($rows as $r){$cid=(int)$r['customer_id'];if(isset($seen[$cid]))continue;$seen[$cid]=true;$contractId=(int)($r['contract_id']??0);$vehicleId=(int)($r['vehicle_id']??0);if($contractId<1||$vehicleId<1)continue;
      $model=trim((string)($r['brand']??'').' '.(string)($r['model']??''));$model=$model!==''?$model:'Motocicleta';$imagePath=$imageFor((string)($r['brand']??''),(string)($r['model']??''));
      $pdo->prepare('UPDATE gp_vehicles SET code=?,plate=?,model=?,color=?,image_path=?,traccar_device_id=?,traccar_unique_id=?,status=\'active\' WHERE id=?')
        ->execute([(string)($r['inventory_code']??('INV-'.$r['inventory_id'])),(string)$r['plate'],$model,$r['color']?:null,$imagePath,$r['gps_device_id']?:null,$r['gps_unique_id']?:null,$vehicleId]);
      $pdo->prepare("UPDATE gp_motorcycle_inventory SET current_customer_id=?,current_contract_id=?,vehicle_id=?,status='assigned',plate_locked=1 WHERE id=?")->execute([$cid,$contractId,$vehicleId,(int)$r['inventory_id']]);$synced++;
    }
    if($synced)$changes[]='Portales existentes sincronizados con Inventario: '.$synced;

    try{$pdo->prepare("INSERT INTO gp_schema_meta(meta_key,meta_value) VALUES('grandprix_v22','22.0.0') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)")->execute();}catch(Throwable){}
    $financeCount=(int)$pdo->query("SELECT COUNT(*) FROM gp_finance_accounts WHERE record_status<>'archived'")->fetchColumn();
    $inventoryCount=(int)$pdo->query("SELECT COUNT(*) FROM gp_motorcycle_inventory WHERE status<>'archived'")->fetchColumn();
    $portalCount=(int)$pdo->query("SELECT COUNT(*) FROM gp_customers WHERE status<>'archived'")->fetchColumn();
    $linkedCount=(int)$pdo->query("SELECT COUNT(*) FROM gp_customers WHERE finance_account_id IS NOT NULL AND status<>'archived'")->fetchColumn();
    $changes[]='Clientes financieros disponibles: '.$financeCount;$changes[]='Motos en Inventario: '.$inventoryCount;$changes[]='Usuarios Mi GRANDPRIX: '.$portalCount;$changes[]='Usuarios vinculados a cliente real: '.$linkedCount;
    $done=true;
  }catch(Throwable $e){$error=$e->getMessage();}
}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>GRANDPRIX V22</title><style>
body{margin:0;background:#edf4fa;color:#0b2946;font:15px system-ui,-apple-system,Segoe UI,sans-serif}.wrap{max-width:980px;margin:36px auto;padding:18px}.card{background:#fff;border:1px solid #dbe7f0;border-radius:28px;padding:30px;box-shadow:0 24px 70px #082c4f17}.tag{display:inline-flex;padding:8px 12px;border-radius:999px;background:#e9f3ff;color:#1268d6;font-size:12px;font-weight:900}h1{font-size:32px;margin:14px 0 8px}p{color:#62798e;line-height:1.6}.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;margin:20px 0}.grid div{padding:16px;border-radius:18px;background:#f8fbfe;border:1px solid #e1ebf2}.grid b{display:block;margin-bottom:5px}.grid span{font-size:12px;color:#6e8395}.ok,.err{padding:15px 17px;border-radius:16px;margin:18px 0}.ok{background:#e9faf3;color:#157553}.err{background:#fff0f2;color:#b42c47}button,a{display:inline-flex;border:0;border-radius:14px;padding:13px 18px;background:#126df5;color:#fff;font-weight:900;text-decoration:none;cursor:pointer}ul{line-height:1.8}@media(max-width:680px){.grid{grid-template-columns:1fr}.card{padding:21px}.wrap{margin:12px auto}}
</style></head><body><div class="wrap"><section class="card"><span class="tag">GRANDPRIX CONTROL 360 · V22</span><h1>Cliente → Inventario → Mi GRANDPRIX</h1><p>Esta actualización hace que la placa, modelo y GPS se seleccionen desde Inventario y que el Portal del cliente cree usuarios únicamente sobre clientes reales ya registrados.</p><div class="grid"><div><b>Clientes y créditos</b><span>Selecciona la placa desde Inventario y precarga automáticamente los datos físicos y GPS.</span></div><div><b>Expediente 360</b><span>El mismo selector maestro queda disponible al editar el expediente.</span></div><div><b>Mi GRANDPRIX</b><span>Selecciona un cliente existente y crea solamente usuario, clave y activación.</span></div><div><b>Seguridad GPS</b><span>El portal hereda el Device ID de la moto asignada y solo puede visualizar esa unidad.</span></div></div><?php if($error):?><div class="err"><b>No se pudo completar V22.</b><br><?=htmlspecialchars($error)?></div><?php endif;?><?php if($done):?><div class="ok"><b>V22 instalada correctamente.</b><ul><?php foreach($changes as $c):?><li><?=htmlspecialchars($c)?></li><?php endforeach;?></ul></div><a href="../">Abrir Administración</a><?php else:?><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>"><button>Instalar V22 · Vincular clientes e Inventario</button></form><?php endif;?></section></div></body></html>
