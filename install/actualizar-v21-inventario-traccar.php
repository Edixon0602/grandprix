<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/lib/bootstrap.php';
require_once dirname(__DIR__).'/lib/Database.php';
require_once dirname(__DIR__).'/lib/InventoryService.php';

gp_start_session();
gp_require_admin(false);
if(array_key_exists('grandprix_admin_permissions',$_SESSION)&&!gp_user_can('users.permissions')&&!gp_user_can('finance.clients.edit')){http_response_code(403);exit('No tienes permiso para ejecutar esta actualización.');}
$done=false;$error='';$result=null;$changes=[];$csrf=gp_csrf_token();
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!gp_verify_csrf((string)($_POST['csrf']??'')))$error='La sesión de seguridad venció. Recarga la página.';
    else try{
        $pdo=Database::connection();
        $suffix=' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        foreach(['gp_finance_accounts'] as $table){if(!$pdo->query('SHOW TABLES LIKE '.$pdo->quote($table))->fetchColumn())throw new RuntimeException('Falta '.$table.'. Ejecuta primero las actualizaciones financieras anteriores.');}
        $pdo->exec("CREATE TABLE IF NOT EXISTS gp_motorcycle_inventory (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          inventory_code VARCHAR(40) NOT NULL UNIQUE,
          plate VARCHAR(40) NOT NULL UNIQUE,
          brand VARCHAR(80) NULL,
          model VARCHAR(120) NOT NULL,
          engine_cc VARCHAR(40) NULL,
          color VARCHAR(80) NULL,
          model_year SMALLINT UNSIGNED NULL,
          chassis_serial VARCHAR(100) NULL,
          engine_serial VARCHAR(100) NULL,
          gps_device_id BIGINT UNSIGNED NULL UNIQUE,
          gps_unique_id VARCHAR(120) NULL,
          gps_label VARCHAR(160) NULL,
          status VARCHAR(30) NOT NULL DEFAULT 'available',
          current_finance_account_id BIGINT UNSIGNED NULL,
          current_customer_id BIGINT UNSIGNED NULL,
          current_contract_id BIGINT UNSIGNED NULL,
          vehicle_id BIGINT UNSIGNED NULL,
          notes VARCHAR(1000) NULL,
          plate_locked TINYINT(1) NOT NULL DEFAULT 1,
          source_key VARCHAR(30) NOT NULL DEFAULT 'manual',
          released_at DATETIME NULL,
          release_reason VARCHAR(500) NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          INDEX idx_gp_inv_status(status),INDEX idx_gp_inv_finance(current_finance_account_id),INDEX idx_gp_inv_customer(current_customer_id),INDEX idx_gp_inv_unique(gps_unique_id)
        )$suffix");
        $defs=[
          'brand'=>'VARCHAR(80) NULL AFTER plate',
          'engine_cc'=>'VARCHAR(40) NULL AFTER model',
          'chassis_serial'=>'VARCHAR(100) NULL AFTER model_year',
          'engine_serial'=>'VARCHAR(100) NULL AFTER chassis_serial',
          'gps_unique_id'=>'VARCHAR(120) NULL AFTER gps_device_id',
          'gps_label'=>'VARCHAR(160) NULL AFTER gps_unique_id',
          'plate_locked'=>'TINYINT(1) NOT NULL DEFAULT 1 AFTER notes',
          'source_key'=>"VARCHAR(30) NOT NULL DEFAULT 'manual' AFTER plate_locked",
        ];
        foreach($defs as $name=>$def){$q=$pdo->query('SHOW COLUMNS FROM gp_motorcycle_inventory LIKE '.$pdo->quote($name));if(!$q->fetch()){$pdo->exec("ALTER TABLE gp_motorcycle_inventory ADD COLUMN `$name` $def");$changes[]='Campo '.$name.' agregado';}}
        try{$q=$pdo->query("SHOW INDEX FROM gp_motorcycle_inventory WHERE Key_name='idx_gp_inv_unique'");if(!$q->fetch())$pdo->exec('CREATE INDEX idx_gp_inv_unique ON gp_motorcycle_inventory(gps_unique_id)');}catch(Throwable){}
        $pdo->exec("UPDATE gp_motorcycle_inventory SET plate=UPPER(REPLACE(plate,' ','')),plate_locked=1 WHERE plate IS NOT NULL AND TRIM(plate)<>''");
        $actor=gp_current_admin();
        $inventory=new InventoryService($pdo);
        $result=$inventory->syncRealData($actor);
        $changes[]='Placas reales importadas/bloqueadas: '.(int)($result['total']??0);
        $changes[]='Nuevas unidades creadas: '.(int)($result['created']??0);
        $changes[]='Asignaciones activas vinculadas: '.(int)($result['linked']??0);
        // Enriquece los GPS ya vinculados usando el snapshot local de Traccar, sin polling.
        try{
            $telemetryFile=dirname(__DIR__).'/lib/TelemetryStore.php';
            if(is_file($telemetryFile)){
                require_once $telemetryFile;
                $snapshot=(new TelemetryStore())->snapshot();$gpsUpdated=0;
                foreach((array)($snapshot['devices']??[]) as $d){if(!is_array($d))continue;$id=(int)($d['id']??0);if($id<1)continue;$q=$pdo->prepare('UPDATE gp_motorcycle_inventory SET gps_unique_id=COALESCE(NULLIF(gps_unique_id,\'\'),?),gps_label=COALESCE(NULLIF(gps_label,\'\'),?) WHERE gps_device_id=?');$q->execute([trim((string)($d['uniqueId']??''))?:null,trim((string)($d['name']??''))?:null,$id]);$gpsUpdated+=$q->rowCount();}
                if($gpsUpdated)$changes[]='GPS existentes enriquecidos desde snapshot Traccar: '.$gpsUpdated;
            }
        }catch(Throwable){}
        try{$pdo->prepare("INSERT INTO gp_schema_meta(meta_key,meta_value) VALUES('grandprix_v21','21.0.0') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)")->execute();}catch(Throwable){}
        $done=true;
    }catch(Throwable $e){$error=$e->getMessage();}
}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>GRANDPRIX V21</title><style>
body{margin:0;background:#eef4f9;color:#0b2946;font:15px system-ui,-apple-system,Segoe UI,sans-serif}.wrap{max-width:980px;margin:38px auto;padding:18px}.card{background:#fff;border:1px solid #dce7ef;border-radius:28px;padding:30px;box-shadow:0 22px 65px #082d5018}.tag{display:inline-flex;gap:8px;padding:8px 12px;border-radius:999px;background:#eaf3ff;color:#1268d6;font-size:12px;font-weight:900}h1{font-size:34px;margin:14px 0 8px}p{color:#62788d;line-height:1.6}.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;margin:20px 0}.grid div{padding:16px;border-radius:18px;background:#f8fbfe;border:1px solid #e1ebf2}.grid b{display:block;margin-bottom:5px}.grid span{font-size:12px;color:#6a8093}.ok,.err,.warn{padding:15px 17px;border-radius:16px;margin:18px 0}.ok{background:#e9faf3;color:#157553}.err{background:#fff0f2;color:#b42c47}.warn{background:#fff8e9;color:#8b6110}button,a{display:inline-flex;border:0;border-radius:14px;padding:13px 18px;background:#126df5;color:#fff;font-weight:900;text-decoration:none;cursor:pointer}ul{line-height:1.75}@media(max-width:680px){.grid{grid-template-columns:1fr}.card{padding:21px}.wrap{margin:12px auto}}
</style></head><body><div class="wrap"><section class="card"><span class="tag">GRANDPRIX CONTROL 360 · V21</span><h1>Inventario maestro + GPS real de Traccar</h1><p>Esta actualización registra todas las placas reales de la cartera como unidades únicas, bloquea duplicados y habilita la selección de GPS directamente desde el catálogo vigente de Traccar.</p><div class="grid"><div><b>Placas reales bloqueadas</b><span>Una placa identifica una sola motocicleta y permanece asociada a esa unidad física.</span></div><div><b>GPS desde Traccar</b><span>El panel lee el catálogo GPS existente y muestra Device ID, nombre, IMEI/Unique ID y estado.</span></div><div><b>GPS único por unidad</b><span>Un equipo utilizado por otra moto queda deshabilitado automáticamente.</span></div><div><b>Reasignación segura</b><span>La placa se libera a otro cliente solo después de archivar/desactivar al anterior.</span></div></div><?php if($error):?><div class="err"><b>No se pudo completar la actualización.</b><br><?=htmlspecialchars($error)?></div><?php endif;?><?php if($done):?><div class="ok"><b>V21 instalada correctamente.</b><ul><?php foreach($changes as $c):?><li><?=htmlspecialchars($c)?></li><?php endforeach;?></ul></div><?php if(!empty($result['conflicts'])):?><div class="warn"><b>Placas que requieren revisión manual:</b><ul><?php foreach($result['conflicts'] as $c):?><li><?=htmlspecialchars((string)$c)?></li><?php endforeach;?></ul></div><?php endif;?><a href="../">Abrir Administración</a><?php else:?><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>"><button>Instalar V21 · Inventario maestro</button></form><?php endif;?></section></div></body></html>
