<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/lib/bootstrap.php';
require_once dirname(__DIR__).'/lib/Database.php';

gp_start_session();
gp_require_admin(false);
if(array_key_exists('grandprix_admin_permissions',$_SESSION)&&!gp_user_can('users.permissions')&&!gp_user_can('finance.clients.edit')){http_response_code(403);exit('No tienes permiso para ejecutar esta reparación.');}
$done=false;$error='';$changes=[];$conflicts=[];$csrf=gp_csrf_token();
$tableExists=static function(PDO $pdo,string $table): bool{try{return (bool)$pdo->query('SHOW TABLES LIKE '.$pdo->quote($table))->fetchColumn();}catch(Throwable){return false;}};
if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!gp_verify_csrf((string)($_POST['csrf']??'')))$error='La sesión de seguridad venció. Recarga la página.';
  else try{
    $pdo=Database::connection();
    foreach(['gp_finance_accounts','gp_motorcycle_inventory'] as $table){if(!$tableExists($pdo,$table))throw new RuntimeException('Falta '.$table.'. Ejecuta primero V21/V22.');}
    $actor=gp_current_admin();$linked=0;$already=0;$without=0;
    $accounts=$pdo->query("SELECT id,full_name,plate,gps_device_id,record_status FROM gp_finance_accounts WHERE record_status<>'archived' ORDER BY id")->fetchAll();
    foreach($accounts as $a){
      $fid=(int)$a['id'];
      $q=$pdo->prepare("SELECT id,plate,current_finance_account_id,status FROM gp_motorcycle_inventory WHERE current_finance_account_id=? AND status<>'archived' ORDER BY id DESC LIMIT 1");$q->execute([$fid]);
      if($q->fetch()){$already++;continue;}
      $plate=mb_strtoupper(preg_replace('/\s+/u','',trim((string)($a['plate']??'')))??'');$gps=(int)($a['gps_device_id']??0);
      $unit=null;
      if($plate!==''){$q=$pdo->prepare("SELECT id,plate,current_finance_account_id,status FROM gp_motorcycle_inventory WHERE UPPER(REPLACE(plate,' ',''))=? AND status<>'archived' ORDER BY id DESC LIMIT 2");$q->execute([$plate]);$rows=$q->fetchAll();if(count($rows)===1)$unit=$rows[0];elseif(count($rows)>1){$conflicts[]=(string)$a['full_name'].' · '.$plate.': placa duplicada en Inventario';continue;}}
      if(!$unit&&$gps>0){$q=$pdo->prepare("SELECT id,plate,current_finance_account_id,status FROM gp_motorcycle_inventory WHERE gps_device_id=? AND status<>'archived' ORDER BY id DESC LIMIT 2");$q->execute([$gps]);$rows=$q->fetchAll();if(count($rows)===1)$unit=$rows[0];elseif(count($rows)>1){$conflicts[]=(string)$a['full_name'].' · GPS '.$gps.': GPS duplicado en Inventario';continue;}}
      if(!$unit){$without++;continue;}
      $owner=(int)($unit['current_finance_account_id']??0);
      if($owner>0&&$owner!==$fid){$o=$pdo->prepare("SELECT full_name,record_status FROM gp_finance_accounts WHERE id=? LIMIT 1");$o->execute([$owner]);$other=$o->fetch();if($other&&($other['record_status']??'active')!=='archived'){$conflicts[]=(string)$a['full_name'].' · '.(string)$unit['plate'].': ya pertenece a '.(string)$other['full_name'];continue;}}
      gp_v22_2_assign_inventory($pdo,$fid,(int)$unit['id'],$actor);$linked++;
    }
    $changes[]='Vínculos Inventario ya correctos: '.$already;
    $changes[]='Vínculos reparados automáticamente: '.$linked;
    $changes[]='Clientes sin coincidencia física por placa/GPS: '.$without;
    $changes[]='Conflictos que requieren revisión manual: '.count($conflicts);
    try{$pdo->prepare("INSERT INTO gp_schema_meta(meta_key,meta_value) VALUES('grandprix_v22_2','22.2.2') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)")->execute();}catch(Throwable){}
    $done=true;
  }catch(Throwable $e){$error=$e->getMessage();}
}

function gp_v22_2_assign_inventory(PDO $pdo,int $financeAccountId,int $inventoryId,array $actor): void
{
    $account=$pdo->prepare("SELECT id,full_name,record_status FROM gp_finance_accounts WHERE id=? LIMIT 1");
    $account->execute([$financeAccountId]);$fa=$account->fetch();
    if(!$fa||($fa['record_status']??'active')==='archived')throw new InvalidArgumentException('El cliente financiero ya no está activo.');

    $unitQ=$pdo->prepare("SELECT id,plate,current_finance_account_id,status FROM gp_motorcycle_inventory WHERE id=? AND status<>'archived' LIMIT 1");
    $unitQ->execute([$inventoryId]);$unit=$unitQ->fetch();
    if(!$unit)throw new InvalidArgumentException('La motocicleta seleccionada ya no existe en Inventario.');

    $owner=(int)($unit['current_finance_account_id']??0);
    if($owner>0&&$owner!==$financeAccountId){
        $o=$pdo->prepare("SELECT full_name,record_status FROM gp_finance_accounts WHERE id=? LIMIT 1");$o->execute([$owner]);$other=$o->fetch();
        if($other&&($other['record_status']??'active')!=='archived')throw new InvalidArgumentException('La placa '.(string)$unit['plate'].' ya está asignada a '.(string)$other['full_name'].'.');
    }

    $otherUnit=$pdo->prepare("SELECT id,plate FROM gp_motorcycle_inventory WHERE current_finance_account_id=? AND id<>? AND status<>'archived' LIMIT 1");
    $otherUnit->execute([$financeAccountId,$inventoryId]);
    if($dup=$otherUnit->fetch())throw new InvalidArgumentException('Este cliente ya tiene asignada la placa '.(string)$dup['plate'].'. Libera la asignación anterior antes de cambiar de moto.');

    $pdo->prepare("UPDATE gp_motorcycle_inventory SET status='assigned',current_finance_account_id=?,plate_locked=1 WHERE id=?")
        ->execute([$financeAccountId,$inventoryId]);

    try{$pdo->prepare("INSERT INTO gp_vehicle_assignment_history (inventory_id,finance_account_id,customer_id,contract_id,event_key,notes,created_by) VALUES (?,?,?,?,?,?,?)")
        ->execute([$inventoryId,$financeAccountId,null,null,'assigned_repair','Vínculo Cliente → Inventario reparado por V22.2.2.',(string)($actor['email']??'admin')]);}catch(Throwable){}
}

?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>GRANDPRIX V22.2.2</title><style>
body{margin:0;background:#edf4fa;color:#0b2946;font:15px system-ui,-apple-system,Segoe UI,sans-serif}.wrap{max-width:980px;margin:36px auto;padding:18px}.card{background:#fff;border:1px solid #dbe7f0;border-radius:28px;padding:30px;box-shadow:0 24px 70px #082c4f17}.tag{display:inline-flex;padding:8px 12px;border-radius:999px;background:#e9f3ff;color:#1268d6;font-size:12px;font-weight:900}h1{font-size:30px;margin:14px 0 8px}p{color:#62798e;line-height:1.6}.ok,.err,.warn{padding:15px 17px;border-radius:16px;margin:18px 0}.ok{background:#e9faf3;color:#157553}.err{background:#fff0f2;color:#b42c47}.warn{background:#fff7e7;color:#8d6516}button,a{display:inline-flex;border:0;border-radius:14px;padding:13px 18px;background:#126df5;color:#fff;font-weight:900;text-decoration:none;cursor:pointer}ul{line-height:1.8}.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;margin:20px 0}.grid div{padding:15px;border-radius:16px;background:#f8fbfe;border:1px solid #e1ebf2}.grid b{display:block}.grid span{display:block;margin-top:4px;color:#6e8395;font-size:12px}@media(max-width:680px){.grid{grid-template-columns:1fr}.card{padding:21px}.wrap{margin:12px auto}}</style></head><body><div class="wrap"><section class="card"><span class="tag">GRANDPRIX V22.2.2 · REPARACIÓN DE VÍNCULOS</span><h1>Cliente → Inventario → Portal</h1><p>Repara de forma segura los clientes que ya tienen placa/GPS en Clientes y créditos pero cuya unidad todavía aparece como “Disponible” en Inventario. No borra datos y no modifica Traccar.</p><div class="grid"><div><b>Coincidencia por placa</b><span>La placa real es la primera referencia para consolidar la unidad.</span></div><div><b>Coincidencia por GPS</b><span>Si no hay placa coincidente, usa el Device ID cuando es único.</span></div><div><b>Sin reasignaciones riesgosas</b><span>Una moto de otro cliente activo queda como conflicto y no se mueve.</span></div><div><b>Portal consistente</b><span>Después de reparar, Mi GRANDPRIX reconocerá la moto y GPS del mismo expediente.</span></div></div><?php if($error):?><div class="err"><b>No se pudo completar la reparación.</b><br><?=htmlspecialchars($error)?></div><?php endif;?><?php if($done):?><div class="ok"><b>Reparación finalizada.</b><ul><?php foreach($changes as $c):?><li><?=htmlspecialchars($c)?></li><?php endforeach;?></ul></div><?php if($conflicts):?><div class="warn"><b>Conflictos detectados</b><ul><?php foreach($conflicts as $c):?><li><?=htmlspecialchars($c)?></li><?php endforeach;?></ul></div><?php endif;?><a href="../">Abrir Administración</a><?php else:?><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>"><button>Reparar vínculos V22.2</button></form><?php endif;?></section></div></body></html>
