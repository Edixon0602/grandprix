<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/Database.php';
require_once dirname(__DIR__) . '/lib/PaymentReceiptService.php';

gp_start_session();
gp_require_admin(false);
if (array_key_exists('grandprix_admin_permissions', $_SESSION) && !gp_user_can('users.permissions')) {
    http_response_code(403); exit('No tienes permiso para ejecutar esta actualización.');
}
$csrf=gp_csrf_token();$done=false;$error='';$changes=[];
function gp_v173_table(PDO $pdo,string $table):bool{$q=$pdo->query('SHOW TABLES LIKE '.$pdo->quote($table));return (bool)$q->fetchColumn();}
function gp_v173_date(?string $raw):?DateTimeImmutable{
    $raw=trim((string)$raw);if($raw==='')return null;$d=DateTimeImmutable::createFromFormat('!Y-m-d',$raw);return $d&&$d->format('Y-m-d')===$raw?$d:null;
}
function gp_v173_wednesday(DateTimeImmutable $date):DateTimeImmutable{
    $iso=(int)$date->format('N');$days=(3-$iso+7)%7;return $days===0?$date:$date->modify('+'.$days.' days');
}
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!gp_verify_csrf((string)($_POST['csrf']??'')))$error='La sesión de seguridad venció. Recarga la página.';
    else{
        try{
            $pdo=Database::connection();
            foreach(['gp_contracts','gp_contract_weeks','gp_finance_accounts','gp_finance_installments','gp_finance_receipts'] as $t){if(!gp_v173_table($pdo,$t))throw new RuntimeException('Falta la tabla '.$t.'. Ejecuta primero las actualizaciones V16/V17.1.');}
            $pdo->beginTransaction();
            $contractAnchors=[];$contractUpdated=0;$financeUpdated=0;
            $contracts=$pdo->query("SELECT id,contract_number,start_date FROM gp_contracts WHERE status IN ('active','completed') ORDER BY id")->fetchAll();
            $weekSelect=$pdo->prepare('SELECT id,week_number,due_date FROM gp_contract_weeks WHERE contract_id=? ORDER BY week_number');
            $weekUpdate=$pdo->prepare('UPDATE gp_contract_weeks SET due_date=? WHERE id=?');
            foreach($contracts as $contract){
                $start=gp_v173_date($contract['start_date']??null);$anchor=$start?gp_v173_wednesday($start):null;
                if($anchor)$contractAnchors[(string)$contract['contract_number']]=$anchor;
                $weekSelect->execute([(int)$contract['id']]);
                foreach($weekSelect->fetchAll() as $week){
                    $due=null;
                    if($anchor){$due=$anchor->modify('+'.(((int)$week['week_number']-1)*7).' days')->format('Y-m-d');}
                    else{$old=gp_v173_date($week['due_date']??null);if($old)$due=gp_v173_wednesday($old)->format('Y-m-d');}
                    if($due!==null && $due!==(string)($week['due_date']??'')){$weekUpdate->execute([$due,(int)$week['id']]);$contractUpdated++;}
                }
            }
            $accounts=$pdo->query("SELECT id,contract_number,start_date FROM gp_finance_accounts WHERE record_status<>'archived' ORDER BY id")->fetchAll();
            $instSelect=$pdo->prepare('SELECT id,installment_no,due_date FROM gp_finance_installments WHERE account_id=? ORDER BY installment_no');
            $instUpdate=$pdo->prepare('UPDATE gp_finance_installments SET due_date=? WHERE id=?');
            foreach($accounts as $account){
                $start=gp_v173_date($account['start_date']??null);$anchor=$start?gp_v173_wednesday($start):($contractAnchors[(string)($account['contract_number']??'')]??null);
                $instSelect->execute([(int)$account['id']]);
                foreach($instSelect->fetchAll() as $week){
                    $due=null;
                    if($anchor){$due=$anchor->modify('+'.(((int)$week['installment_no']-1)*7).' days')->format('Y-m-d');}
                    else{$old=gp_v173_date($week['due_date']??null);if($old)$due=gp_v173_wednesday($old)->format('Y-m-d');}
                    if($due!==null && $due!==(string)($week['due_date']??'')){$instUpdate->execute([$due,(int)$week['id']]);$financeUpdated++;}
                }
            }
            // El miércoles completo sigue siendo día válido de pago. La mora comienza el jueves.
            $latePortal=$pdo->exec("UPDATE gp_contract_weeks SET status='late' WHERE status='pending' AND due_date IS NOT NULL AND due_date<CURRENT_DATE");
            $lateFinance=$pdo->exec("UPDATE gp_finance_installments SET status='late' WHERE status='future' AND due_date IS NOT NULL AND due_date<CURRENT_DATE");
            // Los recibos ya emitidos conservan su historia, pero la próxima fecha debe reflejar el miércoles contractual correcto.
            $receiptDates=$pdo->exec("UPDATE gp_finance_receipts r INNER JOIN gp_finance_installments i ON i.account_id=r.account_id AND i.installment_no=r.next_week SET r.next_due_date=i.due_date WHERE r.next_week IS NOT NULL");
            if(gp_v173_table($pdo,'gp_schema_meta')){
                $m=$pdo->prepare("INSERT INTO gp_schema_meta(meta_key,meta_value) VALUES('grandprix_v17_collection_day','17.3.0-wednesday') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");$m->execute();
            }
            $pdo->commit();
            // Sincroniza agregados financieros después de la normalización.
            (new PaymentReceiptService($pdo))->refreshAll();
            $changes[]='Cronograma contractual normalizado a miércoles: '.$contractUpdated.' fechas ajustadas.';
            $changes[]='Cronograma financiero normalizado a miércoles: '.$financeUpdated.' fechas ajustadas.';
            $changes[]='Semanas que ya pasaron su miércoles y entraron en mora: '.((int)$latePortal+(int)$lateFinance).'.';
            $changes[]='Recibos existentes con próxima cuota resincronizada: '.(int)$receiptDates.'.';
            $changes[]='Mi GRANDPRIX ahora lista todos los recibos oficiales, incluso pagos creados directamente por Administración.';
            $done=true;
        }catch(Throwable $e){if(isset($pdo)&&$pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();$error=$e->getMessage();}
    }
}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>GRANDPRIX V17.3 · Cobros miércoles</title><style>
*{box-sizing:border-box}body{margin:0;background:#edf4fa;color:#0b2743;font:15px system-ui,-apple-system,Segoe UI,sans-serif}.wrap{max-width:920px;margin:40px auto;padding:18px}.card{background:#fff;border:1px solid #dce7f0;border-radius:26px;padding:30px;box-shadow:0 22px 60px #0b274315}.tag{display:inline-flex;padding:7px 11px;border-radius:999px;background:#eaf4ff;color:#1265cf;font-size:12px;font-weight:900}h1{font-size:32px;margin:13px 0 8px}p{color:#60778e;line-height:1.6}.rule{display:flex;gap:14px;align-items:center;margin:20px 0;padding:17px;border-radius:18px;background:#082744;color:#fff}.rule i{width:48px;height:48px;border-radius:15px;background:#ffffff13;display:grid;place-items:center;font-style:normal;font-size:22px}.rule b{display:block;font-size:18px}.rule span{display:block;color:#c9dbea;margin-top:3px;font-size:13px}.ok,.err{padding:15px 17px;border-radius:15px;margin:18px 0}.ok{background:#eaf9f2;color:#147454}.err{background:#fff0f2;color:#b52d49}ul{line-height:1.8}button,.btn{border:0;border-radius:14px;background:#146ef5;color:#fff;padding:13px 18px;font-weight:900;cursor:pointer;text-decoration:none;display:inline-flex}.flow{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin:18px 0}.flow div{padding:14px;border:1px solid #e0e9f1;border-radius:15px;background:#f8fbfd}.flow b{display:block}.flow small{color:#6b8195}@media(max-width:700px){.flow{grid-template-columns:1fr}.wrap{margin:14px auto}.card{padding:21px}}</style></head><body><div class="wrap"><div class="card"><span class="tag">GRANDPRIX CONTROL 360 · V17.3</span><h1>Cobros los miércoles + recibos en Mi GRANDPRIX</h1><p>Esta actualización conserva la cartera real y reorganiza únicamente las fechas contractuales conocidas para que cada cuota venza en miércoles. No inventa fechas para contratos históricos que no tienen una fecha de inicio ni una fecha de vencimiento conocida.</p><div class="rule"><i>📅</i><div><b>Todos los cobros vencen los miércoles</b><span>El miércoles sigue siendo día válido para pagar; una cuota pendiente entra en mora desde el jueves.</span></div></div><div class="flow"><div><b>1. Cronograma</b><small>Las 50 semanas quedan alineadas a miércoles.</small></div><div><b>2. Pago</b><small>Administración o conciliación marca la semana pagada.</small></div><div><b>3. Mi GRANDPRIX</b><small>El recibo PDF queda disponible en la cuenta del cliente.</small></div></div><?php if($error):?><div class="err"><?=htmlspecialchars($error)?></div><?php endif;?><?php if($done):?><div class="ok"><b>Actualización completada.</b><ul><?php foreach($changes as $change):?><li><?=htmlspecialchars($change)?></li><?php endforeach;?></ul></div><a class="btn" href="../">Abrir Administración</a><?php else:?><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>"><button>Instalar V17.3</button></form><?php endif;?></div></div></body></html>
