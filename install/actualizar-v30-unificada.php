<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/lib/bootstrap.php';
require_once dirname(__DIR__).'/lib/Database.php';
require_once dirname(__DIR__).'/lib/PaymentReceiptService.php';

gp_start_session();
gp_require_admin(false);
if(!gp_user_can('finance.clients.edit')&&!gp_user_can('finance.payments.reconcile')&&!gp_user_can('users.permissions')){
    http_response_code(403);exit('No tienes permiso para instalar la actualización unificada V30.');
}

function v28_table(PDO $pdo,string $t):bool{try{$q=$pdo->query("SHOW TABLES LIKE ".$pdo->quote($t));return(bool)$q->fetchColumn();}catch(Throwable){return false;}}
function v28_col(PDO $pdo,string $t,string $c):bool{try{$q=$pdo->query("SHOW COLUMNS FROM `".str_replace('`','``',$t)."` LIKE ".$pdo->quote($c));return(bool)$q->fetch();}catch(Throwable){return false;}}
function v28_add_col(PDO $pdo,string $t,string $c,string $def):void{if(!v28_col($pdo,$t,$c))$pdo->exec("ALTER TABLE `{$t}` ADD COLUMN `{$c}` {$def}");}
function v30_index(PDO $pdo,string $table,string $name,string $cols):void{try{$q=$pdo->query("SHOW INDEX FROM `".str_replace('`','``',$table)."` WHERE Key_name=".$pdo->quote($name));if(!$q->fetch())$pdo->exec("ALTER TABLE `{$table}` ADD INDEX `{$name}` ({$cols})");}catch(Throwable){}}
function v28_account_from_key(PDO $pdo,string $key):int{
    if(preg_match('/^gp_finance_accounts:(\d+)$/',$key,$m))return(int)$m[1];
    if(preg_match('/^gp_customers:(\d+)$/',$key,$m)&&v28_table($pdo,'gp_customers')){$q=$pdo->prepare('SELECT finance_account_id FROM gp_customers WHERE id=? LIMIT 1');$q->execute([(int)$m[1]]);return(int)($q->fetchColumn()?:0);}return 0;
}
function v28_sync_portal(PDO $pdo,int $accountId,int $weekNo,float $amountDue,float $paidAmount,bool $done,string $paidAt):void{
    if(!v28_table($pdo,'gp_contracts')||!v28_table($pdo,'gp_contract_weeks'))return;
    $q=$pdo->prepare('SELECT contract_number FROM gp_finance_accounts WHERE id=? LIMIT 1');$q->execute([$accountId]);$contract=(string)($q->fetchColumn()?:'');if($contract==='')return;
    $q=$pdo->prepare('SELECT id FROM gp_contracts WHERE contract_number=? ORDER BY id DESC LIMIT 1');$q->execute([$contract]);$cid=(int)($q->fetchColumn()?:0);if($cid<1)return;
    $q=$pdo->prepare('SELECT due_date FROM gp_contract_weeks WHERE contract_id=? AND week_number=? LIMIT 1');$q->execute([$cid,$weekNo]);$due=(string)($q->fetchColumn()?:'');$status=$done?'paid':($due!==''&&$due<date('Y-m-d')?'late':'pending');
    $pdo->prepare('UPDATE gp_contract_weeks SET amount=?,paid_amount=?,status=?,paid_at=? WHERE contract_id=? AND week_number=?')->execute([$amountDue,$paidAmount,$status,$done?$paidAt.' 12:00:00':null,$cid,$weekNo]);
}
function v28_refresh_receipt(PDO $pdo,int $paymentId,int $accountId,PaymentReceiptService $service):void{
    $q=$pdo->prepare('SELECT id,paid_at FROM gp_finance_receipts WHERE payment_id=? LIMIT 1');$q->execute([$paymentId]);$r=$q->fetch();if(!$r)return;
    $paidAt=(string)$r['paid_at'];
    $q=$pdo->prepare("SELECT installment_no,amount_due,paid_amount FROM gp_finance_installments WHERE account_id=? AND status='paid' AND paid_payment_id=? ORDER BY installment_no");$q->execute([$accountId,$paymentId]);$paid=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
    // También cuenta semanas completadas por asignaciones canónicas del pago.
    $q=$pdo->prepare('SELECT installment_no FROM gp_finance_payment_allocations WHERE payment_id=? AND completed=1 ORDER BY id');$q->execute([$paymentId]);foreach($q->fetchAll(PDO::FETCH_COLUMN) as $w)$paid[]=(int)$w;$paid=array_values(array_unique($paid));sort($paid);
    $q=$pdo->prepare("SELECT installment_no,amount_due,paid_amount FROM gp_finance_installments WHERE account_id=? AND status<>'paid' AND due_date IS NOT NULL AND due_date<=? ORDER BY installment_no");$q->execute([$accountId,$paidAt]);$rows=$q->fetchAll();$pending=[];$pendingTotal=0;foreach($rows as $x){$pending[]=(int)$x['installment_no'];$pendingTotal+=max(0,(float)$x['amount_due']-(float)$x['paid_amount']);}
    $q=$pdo->prepare("SELECT installment_no,due_date FROM gp_finance_installments WHERE account_id=? AND status<>'paid' ORDER BY CASE WHEN due_date IS NOT NULL AND due_date<CURRENT_DATE THEN 0 ELSE 1 END,COALESCE(due_date,'9999-12-31'),installment_no LIMIT 1");$q->execute([$accountId]);$next=$q->fetch()?:null;
    $pdo->prepare('UPDATE gp_finance_receipts SET paid_weeks_json=?,pending_weeks_json=?,pending_total=?,next_week=?,next_due_date=? WHERE id=?')->execute([json_encode($paid),json_encode($pending),round($pendingTotal,2),$next?(int)$next['installment_no']:null,$next['due_date']??null,(int)$r['id']]);
}

$done=false;$error='';$summary=[];$warnings=[];
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!gp_verify_csrf((string)($_POST['csrf']??'')))$error='Sesión de seguridad vencida. Recarga la página.';
    else try{
        $pdo=Database::connection();
        foreach(['gp_finance_accounts','gp_finance_payments','gp_finance_installments','gp_finance_receipts'] as $t)if(!v28_table($pdo,$t))throw new RuntimeException('Falta la tabla '.$t.'. Instala primero el módulo financiero existente.');

        // Columnas canónicas para pagos por monto + multimoneda.
        v28_add_col($pdo,'gp_finance_installments','paid_amount','DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER amount_due');
        v28_add_col($pdo,'gp_finance_payments','currency',"VARCHAR(3) NOT NULL DEFAULT 'USD' AFTER amount");
        v28_add_col($pdo,'gp_finance_payments','exchange_rate','DECIMAL(18,6) NULL AFTER currency');
        v28_add_col($pdo,'gp_finance_payments','amount_usd','DECIMAL(12,2) NULL AFTER exchange_rate');
        v28_add_col($pdo,'gp_finance_payments','applied_usd','DECIMAL(12,2) NULL AFTER amount_usd');
        v28_add_col($pdo,'gp_finance_payments','unapplied_usd','DECIMAL(12,2) NULL AFTER applied_usd');
        if(v28_table($pdo,'gp_contract_weeks'))v28_add_col($pdo,'gp_contract_weeks','paid_amount','DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER amount');
        if(v28_table($pdo,'gp_payment_reports')){
            v28_add_col($pdo,'gp_payment_reports','currency',"VARCHAR(3) NOT NULL DEFAULT 'USD' AFTER amount");
            v28_add_col($pdo,'gp_payment_reports','exchange_rate','DECIMAL(18,6) NULL AFTER currency');
            v28_add_col($pdo,'gp_payment_reports','amount_usd','DECIMAL(12,2) NULL AFTER exchange_rate');
        }
        v30_index($pdo,'gp_finance_payments','idx_gp_v30_payment_period','paid_at,status,currency');
        $pdo->exec("CREATE TABLE IF NOT EXISTS gp_finance_payment_allocations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            payment_id BIGINT UNSIGNED NOT NULL,
            account_id BIGINT UNSIGNED NOT NULL,
            installment_id BIGINT UNSIGNED NOT NULL,
            installment_no SMALLINT UNSIGNED NOT NULL,
            due_date DATE NULL,
            amount_due DECIMAL(12,2) NOT NULL,
            balance_before DECIMAL(12,2) NOT NULL,
            allocated_amount DECIMAL(12,2) NOT NULL,
            balance_after DECIMAL(12,2) NOT NULL,
            completed TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_gp_v28_alloc (payment_id,installment_id),
            INDEX idx_gp_v28_account (account_id,installment_no),
            INDEX idx_gp_v28_payment (payment_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Conserva toda la historia ya cerrada.
        $pdo->exec("UPDATE gp_finance_installments SET paid_amount=COALESCE(amount_due,0) WHERE status='paid' AND paid_amount=0");
        if(v28_table($pdo,'gp_contract_weeks'))$pdo->exec("UPDATE gp_contract_weeks SET paid_amount=amount WHERE status='paid' AND paid_amount=0");
        $pdo->exec("UPDATE gp_finance_payments SET currency='USD' WHERE currency IS NULL OR currency=''");
        $pdo->exec("UPDATE gp_finance_payments SET amount_usd=amount WHERE amount_usd IS NULL AND amount IS NOT NULL AND currency='USD'");
        $pdo->exec("UPDATE gp_finance_payments SET applied_usd=CASE WHEN status='confirmed' THEN COALESCE(amount_usd,amount,0) ELSE 0 END WHERE applied_usd IS NULL");
        $pdo->exec("UPDATE gp_finance_payments SET unapplied_usd=CASE WHEN status='review' THEN COALESCE(amount_usd,amount,0) ELSE 0 END WHERE unapplied_usd IS NULL");
        if(v28_table($pdo,'gp_payment_reports'))$pdo->exec("UPDATE gp_payment_reports SET currency='USD',amount_usd=amount WHERE (currency IS NULL OR currency='' OR currency='USD') AND amount_usd IS NULL");

        $service=new PaymentReceiptService($pdo);

        // Si V27 ya tenía cuotas configuradas, las convierte a fuente maestra oficial.
        $plansImported=0;
        if(v28_table($pdo,'gp_v27_customer_weekly_plan')){
            foreach($pdo->query('SELECT customer_key,weekly_amount_usd FROM gp_v27_customer_weekly_plan WHERE weekly_amount_usd>0')->fetchAll() as $plan){
                $aid=v28_account_from_key($pdo,(string)$plan['customer_key']);if($aid<1)continue;
                $amount=(float)$plan['weekly_amount_usd'];$pdo->prepare('UPDATE gp_finance_accounts SET weekly_amount=? WHERE id=?')->execute([$amount,$aid]);$service->syncWeeklyAmount($aid,$amount,false);$plansImported++;
            }
        }
        // Para el resto, gp_finance_accounts.weekly_amount se replica a contrato/portal/cronograma.
        $accountsSynced=0;$q=$pdo->query("SELECT id,weekly_amount FROM gp_finance_accounts WHERE record_status<>'archived' AND weekly_amount IS NOT NULL AND weekly_amount>0");
        foreach($q->fetchAll() as $a){$service->ensureSchedule((int)$a['id']);$service->syncWeeklyAmount((int)$a['id'],(float)$a['weekly_amount'],false);$accountsSynced++;}

        // V28 deja de usar las tablas auxiliares gp_v26_* como fuente de verdad.
        // Se conservan intactas por trazabilidad, pero todos los pagos nuevos entran al motor financiero oficial.

        // Repara pagos confirmados recientes donde el monto era mayor que las semanas que el formulario antiguo marcó.
        $repaired=0;$repairDetails=[];
        $payments=$pdo->query("SELECT p.* FROM gp_finance_payments p WHERE p.status='confirmed' AND p.amount IS NOT NULL AND p.amount>0 AND NOT EXISTS(SELECT 1 FROM gp_finance_payment_allocations a WHERE a.payment_id=p.id) AND p.created_at>='2026-08-31 00:00:00' ORDER BY p.id")->fetchAll();
        foreach($payments as $pay){
            $pid=(int)$pay['id'];$aid=(int)$pay['account_id'];$amountUsd=(float)($pay['amount_usd']??$pay['amount']??0);if($amountUsd<=0)continue;
            $q=$pdo->prepare('SELECT * FROM gp_finance_installments WHERE account_id=? AND paid_payment_id=? ORDER BY installment_no');$q->execute([$aid,$pid]);$legacy=$q->fetchAll();$credited=0.0;$touched=[];$completed=[];
            $ins=$pdo->prepare('INSERT IGNORE INTO gp_finance_payment_allocations (payment_id,account_id,installment_id,installment_no,due_date,amount_due,balance_before,allocated_amount,balance_after,completed) VALUES (?,?,?,?,?,?,?,?,?,?)');
            foreach($legacy as $w){$due=(float)($w['amount_due']??0);if($due<=0)continue;$credited+=$due;$touched[]=(int)$w['installment_no'];$completed[]=(int)$w['installment_no'];$ins->execute([$pid,$aid,(int)$w['id'],(int)$w['installment_no'],$w['due_date'],$due,$due,$due,0,1]);}
            $remaining=round($amountUsd-$credited,2);if($remaining<=0.009)continue;
            $q=$pdo->prepare("SELECT * FROM gp_finance_installments WHERE account_id=? AND status<>'paid' ORDER BY CASE WHEN due_date IS NOT NULL AND due_date<CURRENT_DATE THEN 0 ELSE 1 END,COALESCE(due_date,'9999-12-31'),installment_no FOR UPDATE");$q->execute([$aid]);
            foreach($q->fetchAll() as $w){if($remaining<=0.009)break;$due=(float)($w['amount_due']??0);$before=max(0,$due-(float)($w['paid_amount']??0));if($before<=0)continue;$alloc=min($before,$remaining);$paidAfter=(float)($w['paid_amount']??0)+$alloc;$after=max(0,$due-$paidAfter);$done=$after<=0.009;$status=$done?'paid':((!empty($w['due_date'])&&(string)$w['due_date']<date('Y-m-d'))?'late':'future');
                $pdo->prepare('UPDATE gp_finance_installments SET paid_amount=?,status=?,paid_at=?,paid_payment_id=? WHERE id=?')->execute([$paidAfter,$status,$done?(string)$pay['paid_at'].' 12:00:00':null,$done?$pid:null,(int)$w['id']]);
                $ins->execute([$pid,$aid,(int)$w['id'],(int)$w['installment_no'],$w['due_date'],$due,$before,$alloc,$after,$done?1:0]);v28_sync_portal($pdo,$aid,(int)$w['installment_no'],$due,$paidAfter,$done,(string)$pay['paid_at']);$touched[]=(int)$w['installment_no'];if($done)$completed[]=(int)$w['installment_no'];$remaining=round($remaining-$alloc,2);
            }
            $applied=round($amountUsd-max(0,$remaining),2);$pdo->prepare('UPDATE gp_finance_payments SET installments_applied=?,week_numbers_json=?,amount_usd=?,applied_usd=?,unapplied_usd=? WHERE id=?')->execute([count($touched),json_encode(array_values(array_unique($touched))),$amountUsd,$applied,max(0,$remaining),$pid]);
            v28_refresh_receipt($pdo,$pid,$aid,$service);$repaired++;$repairDetails[]='Pago #'.$pid.': USD '.number_format($amountUsd,2).' → semanas '.implode(',',array_unique($touched));
        }

        $service->refreshAll();
        $summary=['Clientes con cuota sincronizada'=>$accountsSynced,'Cuotas importadas desde V27'=>$plansImported,'Pagos anteriores reparados'=>$repaired,'Pagos automáticos USD/BS'=>'Activos','Abonos parciales'=>'Activos','Resumen Ejecutivo interactivo'=>'Activo','Responsive Administración'=>'Activo','Responsive Mi GRANDPRIX'=>'Activo','Analítica por día / semana / mes'=>'Activa','10% USD y Bs. por período'=>'Activo'];
        if($repairDetails)$warnings=array_slice($repairDetails,0,20);
        $done=true;
    }catch(Throwable $e){$error=$e->getMessage();}
}
$csrf=gp_csrf_token();
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>GRANDPRIX V30 · Actualización unificada</title><style>*{box-sizing:border-box}body{margin:0;background:#eef4f9;font-family:Inter,Arial,sans-serif;color:#082744}.wrap{max-width:900px;margin:40px auto;padding:18px}.card{background:#fff;border:1px solid #dce7f0;border-radius:26px;padding:30px;box-shadow:0 25px 70px rgba(8,39,68,.1)}.tag{display:inline-block;padding:7px 11px;border-radius:999px;background:#eaf4ff;color:#1268ce;font-weight:900;font-size:11px}h1{margin:12px 0 8px}.lead{color:#617c93;line-height:1.6}.ok,.err,.warn{padding:16px;border-radius:16px;margin:18px 0}.ok{background:#eaf9f2;color:#116f4e}.err{background:#fff0f2;color:#a33d50}.warn{background:#fff8e9;color:#8a630d}.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px}.grid div{padding:12px;border:1px solid #e5edf4;border-radius:14px}.grid small,.grid b{display:block}.grid small{color:#7890a4;font-size:9px;text-transform:uppercase}.grid b{margin-top:4px}button{border:0;border-radius:13px;padding:14px 20px;background:#1268ce;color:#fff;font-weight:900;cursor:pointer}a{color:#1268ce;font-weight:800}@media(max-width:620px){.wrap{margin:10px auto;padding:10px}.card{padding:20px 15px}.grid{grid-template-columns:1fr}}</style></head><body><div class="wrap"><div class="card"><span class="tag">GRANDPRIX V30 · ACTUALIZACIÓN UNIFICADA</span><h1>Pagos automáticos + Resumen Ejecutivo + móvil + analítica de cobranza</h1><p class="lead">Esta instalación es acumulativa: incorpora pagos por monto, multimoneda y abonos parciales; tarjetas interactivas, responsive móvil y la nueva analítica de cobranza con filtros por día, semana, mes o rango y cálculo del 10% separado en USD y Bs.</p>
<?php if($done): ?><div class="ok"><b>✓ Actualización unificada V30 completada.</b><div class="grid"><?php foreach($summary as $k=>$v): ?><div><small><?=htmlspecialchars((string)$k)?></small><b><?=htmlspecialchars((string)$v)?></b></div><?php endforeach; ?></div></div><?php if($warnings): ?><div class="warn"><b>Pagos recalculados durante la instalación</b><br><?php foreach($warnings as $w): ?>• <?=htmlspecialchars($w)?><br><?php endforeach; ?></div><?php endif; ?><p><a href="../index.php">Volver a GRANDPRIX</a></p>
<?php elseif($error): ?><div class="err"><b>No se pudo completar la actualización.</b><br><?=htmlspecialchars($error)?></div><?php endif; ?>
<?php if(!$done): ?><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>"><button type="submit">Instalar V30 completa</button></form><?php endif; ?></div></div></body></html>
