<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/lib/bootstrap.php';
require_once dirname(__DIR__).'/lib/Database.php';

gp_start_session();
gp_require_admin(false);
if(array_key_exists('grandprix_admin_permissions',$_SESSION) && !gp_user_can('users.permissions') && !gp_user_can('finance.clients.edit')){
    http_response_code(403); exit('No tienes permiso para ejecutar esta actualización.');
}
$csrf=gp_csrf_token();$done=false;$error='';$messages=[];$freed=[];
$tableExists=static function(PDO $pdo,string $table): bool{try{return (bool)$pdo->query('SHOW TABLES LIKE '.$pdo->quote($table))->fetchColumn();}catch(Throwable){return false;}};
$columnExists=static function(PDO $pdo,string $table,string $column): bool{try{$q=$pdo->query('SHOW COLUMNS FROM `'.str_replace('`','',$table).'` LIKE '.$pdo->quote($column));return (bool)$q->fetch();}catch(Throwable){return false;}};
$norm=static fn(?string $v): string => preg_replace('/[^A-Z0-9]/','',mb_strtoupper(trim((string)$v)))?:'';

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!gp_verify_csrf((string)($_POST['csrf']??'')))$error='La sesión de seguridad venció. Recarga la página.';
    else try{
        $pdo=Database::connection();
        if(!$tableExists($pdo,'gp_customers'))throw new RuntimeException('No existe gp_customers. Ejecuta primero las actualizaciones del portal.');
        $hasFinance=$columnExists($pdo,'gp_customers','finance_account_id');
        $select='SELECT id,'.($hasFinance?'finance_account_id':'NULL AS finance_account_id').',public_key,full_name,identity_document FROM gp_customers WHERE '
            ."LOWER(TRIM(full_name)) IN ('yeivert sanchez','yeiver sanchez') "
            ."OR LOWER(TRIM(public_key))='yeivert-sanchez' "
            ."OR UPPER(REPLACE(REPLACE(REPLACE(COALESCE(identity_document,''),'.',''),'-',''),' ','')) IN ('23767789','V23767789','00000000','V00000000')";
        $targets=$pdo->query($select)->fetchAll();
        $pdo->beginTransaction();
        foreach($targets as $customer){
            $cid=(int)$customer['id'];$financeId=(int)($customer['finance_account_id']??0);
            $contractIds=[];$vehicleIds=[];
            if($tableExists($pdo,'gp_contracts')){
                $q=$pdo->prepare('SELECT id,vehicle_id,contract_number FROM gp_contracts WHERE customer_id=? ORDER BY id');$q->execute([$cid]);
                foreach($q->fetchAll() as $c){$contractIds[]=(int)$c['id'];$vehicleIds[]=(int)$c['vehicle_id'];}
            }
            $vehicleRows=[];
            if($tableExists($pdo,'gp_vehicles')){
                if($vehicleIds){$marks=implode(',',array_fill(0,count($vehicleIds),'?'));$q=$pdo->prepare("SELECT * FROM gp_vehicles WHERE id IN ($marks)");$q->execute($vehicleIds);$vehicleRows=$q->fetchAll();}
                if(!$vehicleRows){$q=$pdo->query("SELECT * FROM gp_vehicles WHERE code='GP-0248' OR UPPER(REPLACE(COALESCE(plate,''),' ',''))='AA7K91E' ORDER BY id LIMIT 3");$vehicleRows=$q->fetchAll();}
            }

            // Desvincula solicitudes públicas que hubieran quedado asociadas al usuario demo.
            if($tableExists($pdo,'gp_finance_applications') && $columnExists($pdo,'gp_finance_applications','portal_customer_id')){
                $sets=['portal_customer_id=NULL'];$vals=[];
                if($columnExists($pdo,'gp_finance_applications','portal_activation_status'))$sets[]="portal_activation_status='pending'";
                if($columnExists($pdo,'gp_finance_applications','portal_activated_at'))$sets[]='portal_activated_at=NULL';
                $pdo->prepare('UPDATE gp_finance_applications SET '.implode(',',$sets).' WHERE portal_customer_id=?')->execute([$cid]);
            }

            // Libera la unidad física sin borrar su placa ni su GPS. Así queda disponible para reasignación real.
            if($tableExists($pdo,'gp_motorcycle_inventory')){
                $where=['current_customer_id=?'];$args=[$cid];
                if($financeId>0){$where[]='current_finance_account_id=?';$args[]=$financeId;}
                foreach($vehicleRows as $v){if(!empty($v['plate'])){$where[]="UPPER(REPLACE(COALESCE(plate,''),' ',''))=?";$args[]=$norm((string)$v['plate']);} if(!empty($v['traccar_device_id'])){$where[]='gps_device_id=?';$args[]=(int)$v['traccar_device_id'];}}
                $q=$pdo->prepare('SELECT id,plate,gps_device_id,inventory_code FROM gp_motorcycle_inventory WHERE '.implode(' OR ',$where));$q->execute($args);$units=$q->fetchAll();
                foreach($units as $u){
                    $pdo->prepare("UPDATE gp_motorcycle_inventory SET current_finance_account_id=NULL,current_customer_id=NULL,current_contract_id=NULL,status='available',plate_locked=1,released_at=NOW(),release_reason='Usuario demo Yeivert eliminado en V22.1' WHERE id=?")->execute([(int)$u['id']]);
                    $freed[]=['plate'=>(string)$u['plate'],'gps'=>(int)($u['gps_device_id']??0),'code'=>(string)$u['inventory_code']];
                    if($tableExists($pdo,'gp_vehicle_assignment_history')){
                        try{$pdo->prepare("INSERT INTO gp_vehicle_assignment_history(inventory_id,finance_account_id,customer_id,contract_id,event_key,notes,created_by) VALUES (?,?,?,?,?,?,?)")
                            ->execute([(int)$u['id'],$financeId?:null,$cid,$contractIds[0]??null,'demo_removed','Usuario demo Yeivert eliminado; unidad y GPS liberados para reasignación.',(string)(gp_current_admin()['email']??'admin')]);}catch(Throwable){}
                    }
                }
                // Si la moto demo todavía no había entrado a Inventario, la recupera como unidad disponible.
                if(!$units && $vehicleRows){
                    foreach($vehicleRows as $v){
                        $plate=$norm((string)($v['plate']??''));$model=trim((string)($v['model']??''));$gps=(int)($v['traccar_device_id']??0);if($plate===''||$model==='')continue;
                        $check=$pdo->prepare('SELECT id FROM gp_motorcycle_inventory WHERE plate=? LIMIT 1');$check->execute([$plate]);$iid=(int)($check->fetchColumn()?:0);
                        if(!$iid){
                            $code='INV-LEGACY-'.(int)$v['id'];
                            $cols=['inventory_code','plate','model','gps_device_id','status','vehicle_id','plate_locked','source_key'];
                            $vals=[$code,$plate,$model,$gps?:null,'available',(int)$v['id'],1,'legacy_recovered'];
                            $pdo->prepare('INSERT INTO gp_motorcycle_inventory('.implode(',',$cols).') VALUES (?,?,?,?,?,?,?,?)')->execute($vals);$iid=(int)$pdo->lastInsertId();
                            $freed[]=['plate'=>$plate,'gps'=>$gps,'code'=>$code];
                        }
                    }
                }
            }

            // El contrato demo se elimina; semanas y reportes ligados al contrato usan ON DELETE CASCADE.
            if($tableExists($pdo,'gp_contracts')){$pdo->prepare('DELETE FROM gp_contracts WHERE customer_id=?')->execute([$cid]);}
            if($tableExists($pdo,'gp_vehicle_assignment_history')){try{$pdo->prepare('DELETE FROM gp_vehicle_assignment_history WHERE customer_id=? AND event_key<>\'demo_removed\'')->execute([$cid]);}catch(Throwable){}}
            // Elimina el usuario Mi GRANDPRIX demo. Documentos/notificaciones con FK CASCADE se limpian automáticamente.
            $pdo->prepare('DELETE FROM gp_customers WHERE id=?')->execute([$cid]);

            // Si V22 había creado/vinculado un expediente financiero del mismo demo, se archiva para que no aparezca en cartera.
            if($financeId>0 && $tableExists($pdo,'gp_finance_accounts')){
                $q=$pdo->prepare('SELECT full_name,identity_document FROM gp_finance_accounts WHERE id=? LIMIT 1');$q->execute([$financeId]);$fa=$q->fetch();
                if($fa){$n=mb_strtolower(trim((string)$fa['full_name']));$idn=$norm((string)$fa['identity_document']);if(in_array($n,['yeivert sanchez','yeiver sanchez'],true)||in_array($idn,['23767789','V23767789','00000000','V00000000'],true)){
                    $pdo->prepare("UPDATE gp_finance_accounts SET record_status='archived',plate=NULL,gps_device_id=NULL,gps_label=NULL,notes=CONCAT(COALESCE(notes,''),CASE WHEN COALESCE(notes,'')='' THEN '' ELSE '\n' END,'Registro demo Yeivert retirado en V22.1') WHERE id=?")->execute([$financeId]);
                }}
            }
            $messages[]='Usuario demo retirado: '.(string)$customer['full_name'].' (#'.$cid.')';
        }
        try{$pdo->prepare("INSERT INTO gp_schema_meta(meta_key,meta_value) VALUES('grandprix_v22_1','22.1.0') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)")->execute();}catch(Throwable){}
        $pdo->commit();

        // Elimina únicamente la asociación heredada del demo en config/traccar.php. No toca token, webhook, Pusher, mapas ni comandos.
        $configPath=dirname(__DIR__).'/config/traccar.php';
        if(is_file($configPath)){
            $cfg=require $configPath;if(is_array($cfg)){
                $changed=false;
                if(isset($cfg['customer_devices'])&&is_array($cfg['customer_devices'])){foreach(array_keys($cfg['customer_devices']) as $key){if(str_contains(mb_strtolower((string)$key),'yeivert')||str_contains(mb_strtolower((string)$key),'yeiver')){unset($cfg['customer_devices'][$key]);$changed=true;}}}
                if(($cfg['customer_device_match']??'')==='GP-0248'){$cfg['customer_device_match']='';$changed=true;}
                if($changed){
                    $backup=$configPath.'.bak-v22-1-'.date('Ymd-His');@copy($configPath,$backup);
                    $payload="<?php\n// GRANDPRIX: configuración preservada. V22.1 retiró únicamente la asociación legacy del usuario demo.\nreturn ".var_export($cfg,true).";\n";
                    if(@file_put_contents($configPath,$payload,LOCK_EX)===false)$messages[]='Aviso: no se pudo limpiar automáticamente la asociación legacy en config/traccar.php; el resto de la limpieza sí quedó aplicada.';
                    else $messages[]='Asociación legacy Yeivert → GPS retirada de la configuración sin alterar Traccar.';
                }
            }
        }
        unset($_SESSION['grandprix_preview_customer_id']);
        $messages[]=$targets?'Registros demo encontrados y retirados: '.count($targets):'No quedaban usuarios demo Yeivert en la base de datos.';
        $done=true;
    }catch(Throwable $e){if(isset($pdo)&&$pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();$error=$e->getMessage();}
}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>GRANDPRIX V22.1</title><style>
*{box-sizing:border-box}body{margin:0;background:#edf4fa;color:#0b2946;font:15px system-ui,-apple-system,Segoe UI,sans-serif}.wrap{max-width:930px;margin:34px auto;padding:18px}.card{background:#fff;border:1px solid #dbe7f0;border-radius:28px;padding:30px;box-shadow:0 24px 70px #082c4f17}.tag{display:inline-flex;padding:8px 12px;border-radius:999px;background:#e9f3ff;color:#1268d6;font-size:12px;font-weight:900}h1{font-size:31px;margin:14px 0 8px}p{color:#62798e;line-height:1.6}.notice{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;margin:22px 0}.notice div{padding:16px;border-radius:18px;background:#f8fbfe;border:1px solid #e1ebf2}.notice b{display:block;margin-bottom:5px}.notice span{font-size:12px;color:#6e8395}.ok,.err{padding:16px;border-radius:16px;margin:18px 0}.ok{background:#e9faf3;color:#157553}.err{background:#fff0f2;color:#b42c47}.freed{display:grid;gap:8px;margin:12px 0}.freed div{display:flex;justify-content:space-between;gap:12px;padding:11px 13px;background:#fff;border:1px solid #cfe9dc;border-radius:12px}button,a{display:inline-flex;align-items:center;border:0;border-radius:14px;padding:13px 18px;background:#126df5;color:#fff;font-weight:900;text-decoration:none;cursor:pointer}@media(max-width:680px){.notice{grid-template-columns:1fr}.card{padding:21px}.wrap{margin:12px auto}}
</style></head><body><div class="wrap"><section class="card"><span class="tag">GRANDPRIX CONTROL 360 · V22.1</span><h1>Retirar usuario demo y liberar su moto/GPS</h1><p>Esta actualización elimina el usuario inicial de prueba Yeivert/Yeiver Sánchez, libera la unidad física para Inventario y elimina la asociación heredada del GPS. No modifica la comunicación Traccar, webhook, tiempo real, mapas ni comandos.</p><div class="notice"><div><b>Portal del cliente</b><span>Ya no seleccionará automáticamente el primer usuario. Debes elegir explícitamente el cliente a visualizar.</span></div><div><b>Inventario</b><span>La placa y el GPS del demo permanecen como unidad disponible para asignarlos al cliente real que corresponda.</span></div></div><?php if($error):?><div class="err"><b>No se pudo completar la limpieza.</b><br><?=htmlspecialchars($error)?></div><?php endif;?><?php if($done):?><div class="ok"><b>V22.1 aplicada correctamente.</b><ul><?php foreach($messages as $m):?><li><?=htmlspecialchars($m)?></li><?php endforeach;?></ul><?php if($freed):?><div class="freed"><?php foreach($freed as $u):?><div><b><?=htmlspecialchars($u['plate']?:$u['code'])?></b><span><?=((int)$u['gps']>0)?'GPS Device ID '.(int)$u['gps']:'Sin GPS'?></span></div><?php endforeach;?></div><?php endif;?></div><a href="../">Abrir Administración</a><?php else:?><form method="post" onsubmit="return confirm('¿Eliminar definitivamente el usuario demo Yeivert y liberar su motocicleta/GPS para Inventario?');"><input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>"><button>Eliminar demo y liberar unidad</button></form><?php endif;?></section></div></body></html>
