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
$done=false;$error='';$changes=[];$csrf=gp_csrf_token();

function gp_v171_table(PDO $pdo,string $table): bool {
    $q=$pdo->query('SHOW TABLES LIKE '.$pdo->quote($table));return (bool)$q->fetchColumn();
}
function gp_v171_snapshot_date(array $payload): string {
    $sheet=(string)($payload['sheet']??'');
    if(preg_match('/(\d{8})/',$sheet,$m)){
        $d=DateTimeImmutable::createFromFormat('!dmY',$m[1]);
        if($d)return $d->format('Y-m-d');
    }
    return '2026-08-17';
}
function gp_v171_json_weeks(mixed $raw): array {
    if(!is_string($raw)||trim($raw)==='')return [];
    $v=json_decode($raw,true);if(!is_array($v))return [];
    $v=array_values(array_unique(array_filter(array_map('intval',$v),fn($n)=>$n>0&&$n<=50)));sort($v);return $v;
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!gp_verify_csrf((string)($_POST['csrf']??''))){$error='La sesión de seguridad venció. Recarga la página.';}
    else{
        try{
            $pdo=Database::connection();$suffix=' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
            foreach(['gp_finance_accounts','gp_finance_payments'] as $table){if(!gp_v171_table($pdo,$table))throw new RuntimeException('Falta la tabla '.$table.'. Ejecuta primero la actualización financiera base.');}

            $pdo->exec("CREATE TABLE IF NOT EXISTS gp_finance_installments (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                account_id BIGINT UNSIGNED NOT NULL,
                installment_no SMALLINT UNSIGNED NOT NULL,
                due_date DATE NULL,
                amount_due DECIMAL(12,2) NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'future',
                paid_at DATETIME NULL,
                paid_payment_id BIGINT UNSIGNED NULL,
                source_key VARCHAR(40) NOT NULL DEFAULT 'legacy-bootstrap',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_gp_fin_installment (account_id,installment_no),
                INDEX idx_gp_fin_install_status (account_id,status,due_date),
                INDEX idx_gp_fin_install_payment (paid_payment_id),
                CONSTRAINT fk_gp_fin_install_account FOREIGN KEY (account_id) REFERENCES gp_finance_accounts(id) ON DELETE CASCADE
            ){$suffix}");
            $pdo->exec("CREATE TABLE IF NOT EXISTS gp_finance_receipts (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                receipt_number VARCHAR(40) NOT NULL UNIQUE,
                payment_id BIGINT UNSIGNED NOT NULL UNIQUE,
                account_id BIGINT UNSIGNED NOT NULL,
                issued_at DATETIME NOT NULL,
                paid_at DATE NOT NULL,
                amount DECIMAL(12,2) NULL,
                paid_weeks_json LONGTEXT NOT NULL,
                pending_weeks_json LONGTEXT NOT NULL,
                pending_total DECIMAL(12,2) NULL,
                next_week SMALLINT UNSIGNED NULL,
                next_due_date DATE NULL,
                snapshot_json LONGTEXT NOT NULL,
                created_by VARCHAR(190) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_gp_receipt_account (account_id,paid_at),
                INDEX idx_gp_receipt_issued (issued_at),
                CONSTRAINT fk_gp_receipt_account FOREIGN KEY (account_id) REFERENCES gp_finance_accounts(id) ON DELETE RESTRICT,
                CONSTRAINT fk_gp_receipt_payment FOREIGN KEY (payment_id) REFERENCES gp_finance_payments(id) ON DELETE RESTRICT
            ){$suffix}");
            $pdo->exec("CREATE TABLE IF NOT EXISTS gp_finance_history_baseline (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                account_id BIGINT UNSIGNED NOT NULL UNIQUE,
                snapshot_date DATE NOT NULL,
                source_name VARCHAR(190) NOT NULL,
                source_row INT UNSIGNED NULL,
                paid_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                late_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                future_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_gp_hist_snapshot (snapshot_date),
                CONSTRAINT fk_gp_hist_account FOREIGN KEY (account_id) REFERENCES gp_finance_accounts(id) ON DELETE CASCADE
            ){$suffix}");
            $changes[]='Estructura de cronogramas, recibos y continuidad histórica verificada';

            $columns=[
                'payment_method'=>"VARCHAR(80) NULL AFTER amount",
                'week_numbers_json'=>"LONGTEXT NULL AFTER created_by",
                'receipt_id'=>"BIGINT UNSIGNED NULL AFTER week_numbers_json",
                'portal_report_id'=>"BIGINT UNSIGNED NULL AFTER receipt_id"
            ];
            foreach($columns as $name=>$definition){$check=$pdo->query('SHOW COLUMNS FROM gp_finance_payments LIKE '.$pdo->quote($name));if(!$check->fetch())$pdo->exec("ALTER TABLE gp_finance_payments ADD COLUMN `{$name}` {$definition}");}
            try{$idx=$pdo->query("SHOW INDEX FROM gp_finance_payments WHERE Key_name='idx_gp_finpay_receipt'");if(!$idx->fetch())$pdo->exec('ALTER TABLE gp_finance_payments ADD INDEX idx_gp_finpay_receipt (receipt_id)');}catch(Throwable){}
            try{$idx=$pdo->query("SHOW INDEX FROM gp_finance_payments WHERE Key_name='uq_gp_finpay_portal_report'");if(!$idx->fetch())$pdo->exec('ALTER TABLE gp_finance_payments ADD UNIQUE KEY uq_gp_finpay_portal_report (portal_report_id)');}catch(Throwable){}

            $service=new PaymentReceiptService($pdo);
            $jsonPath=dirname(__DIR__).'/data/finanzas-grandprix-20260817.json';
            $baselineCount=0;$baselinePaid=0;$baselineLate=0;$baselineFuture=0;$snapshotDate='2026-08-17';$sourceName='Para Cargar la data.xlsx';
            if(is_file($jsonPath)){
                $payload=json_decode((string)file_get_contents($jsonPath),true,512,JSON_THROW_ON_ERROR);
                $snapshotDate=gp_v171_snapshot_date($payload);$sourceName=(string)($payload['source']??$sourceName);
                $byRow=[];foreach((array)($payload['records']??[]) as $r)$byRow[(int)($r['source_row']??0)]=$r;
                $accounts=$pdo->query("SELECT id,source_row,total_installments,start_date,weekly_amount FROM gp_finance_accounts WHERE record_status<>'archived' AND source_row IS NOT NULL ORDER BY id")->fetchAll();
                $baselineStmt=$pdo->prepare("INSERT INTO gp_finance_history_baseline (account_id,snapshot_date,source_name,source_row,paid_count,late_count,future_count)
                    VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE snapshot_date=VALUES(snapshot_date),source_name=VALUES(source_name),source_row=VALUES(source_row),paid_count=VALUES(paid_count),late_count=VALUES(late_count),future_count=VALUES(future_count)");
                foreach($accounts as $a){
                    $row=(int)$a['source_row'];if(!isset($byRow[$row]))continue;$src=$byRow[$row];$paid=max(0,(int)($src['installments_paid']??0));$late=max(0,(int)($src['installments_late']??0));$total=max(1,(int)($src['total_installments']??50));$future=max(0,$total-$paid-$late);
                    $baselineStmt->execute([(int)$a['id'],$snapshotDate,$sourceName,$row,$paid,$late,$future]);
                    $baselineCount++;$baselinePaid+=$paid;$baselineLate+=$late;$baselineFuture+=$future;
                    $service->ensureSchedule((int)$a['id']);
                    // Restablece solo la porción histórica sin tocar semanas ya asociadas a pagos reales.
                    $u=$pdo->prepare("UPDATE gp_finance_installments SET
                        status=CASE WHEN installment_no<=? THEN 'paid' WHEN installment_no<=? THEN 'late' ELSE 'future' END,
                        paid_at=NULL,
                        source_key='historical-import'
                        WHERE account_id=? AND paid_payment_id IS NULL");
                    $u->execute([$paid,$paid+$late,(int)$a['id']]);
                }
                $changes[]='Corte histórico real reconstruido para '.$baselineCount.' clientes desde '.$sourceName.' ('.$snapshotDate.')';
                $changes[]=$baselinePaid.' semanas pagadas y '.$baselineLate.' semanas en mora conservadas como saldo histórico';
            } else {
                $changes[]='No se encontró el JSON de carga inicial; se conservaron los cronogramas actuales sin inventar historia';
            }

            // Elimina fechas de pago ficticias que una V17 anterior pudo haber inferido de la fecha de vencimiento.
            $cleared=$pdo->exec("UPDATE gp_finance_installments SET paid_at=NULL,source_key='historical-import' WHERE paid_payment_id IS NULL AND status='paid' AND source_key IN ('legacy-bootstrap','historical-import')");
            if($cleared>0)$changes[]='Fechas históricas inferidas eliminadas: '.$cleared.' semanas ahora quedan como pago histórico sin fecha inventada';

            // Reproduce pagos reales registrados después del corte y genera recibos cuando existen fecha y movimiento real.
            $confirmed=$pdo->query("SELECT * FROM gp_finance_payments WHERE status='confirmed' ORDER BY paid_at,id")->fetchAll();
            $replayed=0;$receipts=0;
            foreach($confirmed as $payment){
                $pid=(int)$payment['id'];$aid=(int)$payment['account_id'];$count=max(0,(int)($payment['installments_applied']??0));if($aid<1||$count<1)continue;
                $linked=$pdo->prepare('SELECT COUNT(*) FROM gp_finance_installments WHERE paid_payment_id=?');$linked->execute([$pid]);
                if((int)$linked->fetchColumn()>0){if(!$service->receiptByPayment($pid)){try{$weeks=$pdo->prepare('SELECT installment_no FROM gp_finance_installments WHERE paid_payment_id=? ORDER BY installment_no');$weeks->execute([$pid]);$nums=array_map('intval',$weeks->fetchAll(PDO::FETCH_COLUMN));if($nums){$service->createReceipt($pid,$aid,$nums,['email'=>(string)($payment['created_by']??'migration')]);$receipts++;}}catch(Throwable){}}continue;}
                $service->ensureSchedule($aid);
                $explicit=gp_v171_json_weeks($payment['week_numbers_json']??null);$selected=[];
                if($explicit){
                    $in=implode(',',array_fill(0,count($explicit),'?'));$params=array_merge([$aid],$explicit);$q=$pdo->prepare("SELECT id,installment_no,status FROM gp_finance_installments WHERE account_id=? AND installment_no IN ($in) AND paid_payment_id IS NULL ORDER BY installment_no");$q->execute($params);$selected=$q->fetchAll();
                } else {
                    $lateWanted=min($count,max(0,(int)($payment['late_reduced']??0)));
                    if($lateWanted>0){$q=$pdo->prepare("SELECT id,installment_no,status FROM gp_finance_installments WHERE account_id=? AND status='late' AND paid_payment_id IS NULL ORDER BY installment_no LIMIT ".(int)$lateWanted);$q->execute([$aid]);$selected=$q->fetchAll();}
                    $remaining=$count-count($selected);
                    if($remaining>0){$used=array_map(fn($x)=>(int)$x['id'],$selected);$not=$used?' AND id NOT IN ('.implode(',',array_fill(0,count($used),'?')).')':'';$params=array_merge([$aid],$used);$q=$pdo->prepare("SELECT id,installment_no,status FROM gp_finance_installments WHERE account_id=? AND status='future' AND paid_payment_id IS NULL{$not} ORDER BY installment_no LIMIT ".(int)$remaining);$q->execute($params);$selected=array_merge($selected,$q->fetchAll());}
                    $remaining=$count-count($selected);
                    if($remaining>0){$used=array_map(fn($x)=>(int)$x['id'],$selected);$not=$used?' AND id NOT IN ('.implode(',',array_fill(0,count($used),'?')).')':'';$params=array_merge([$aid],$used);$q=$pdo->prepare("SELECT id,installment_no,status FROM gp_finance_installments WHERE account_id=? AND status='late' AND paid_payment_id IS NULL{$not} ORDER BY installment_no LIMIT ".(int)$remaining);$q->execute($params);$selected=array_merge($selected,$q->fetchAll());}
                }
                if(!$selected)continue;
                $paidDate=(string)($payment['paid_at']??date('Y-m-d'));$actualLate=0;$nums=[];$up=$pdo->prepare("UPDATE gp_finance_installments SET status='paid',paid_at=?,paid_payment_id=?,source_key='registered-payment' WHERE id=?");
                foreach(array_slice($selected,0,$count) as $week){if((string)$week['status']==='late')$actualLate++;$nums[]=(int)$week['installment_no'];$up->execute([$paidDate.' 12:00:00',$pid,(int)$week['id']]);}
                sort($nums);$pdo->prepare('UPDATE gp_finance_payments SET installments_applied=?,late_reduced=?,week_numbers_json=? WHERE id=?')->execute([count($nums),$actualLate,json_encode($nums),$pid]);
                try{if(!$service->receiptByPayment($pid)){$service->createReceipt($pid,$aid,$nums,['email'=>(string)($payment['created_by']??'migration')]);$receipts++;}}catch(Throwable){}
                $replayed++;
            }
            if($replayed>0)$changes[]='Se reconstruyeron '.$replayed.' movimientos reales posteriores al corte dentro de sus semanas correctas';
            if($receipts>0)$changes[]='Se generaron '.$receipts.' recibos para pagos reales que ya existían y tenían fecha registrada';

            // Si existían reportes aprobados de Mi GRANDPRIX, intégralos una sola vez.
            $portalReceipts=0;
            try{
                if(gp_v171_table($pdo,'gp_payment_reports')){
                    $reports=$pdo->query("SELECT * FROM gp_payment_reports WHERE status='approved' ORDER BY id")->fetchAll();
                    foreach($reports as $report){
                        $pdo->beginTransaction();
                        try{$receipt=$service->syncPortalApprovedPayment($report,(string)($report['reviewed_by']??'installer-v17.1'));$pdo->commit();if($receipt)$portalReceipts++;}
                        catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();}
                    }
                }
            }catch(Throwable){}
            if($portalReceipts>0)$changes[]='Pagos aprobados desde Mi GRANDPRIX sincronizados: '.$portalReceipts;

            $service->refreshAll();
            if(gp_v171_table($pdo,'gp_schema_meta')){$meta=$pdo->prepare("INSERT INTO gp_schema_meta (meta_key,meta_value) VALUES ('grandprix_v17_history','17.1.0') ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)");$meta->execute();}
            $changes[]='La cartera continúa desde su estado real; no comienza en cero ni inventa fechas de pago faltantes';
            $done=true;
        }catch(Throwable $e){$error=$e->getMessage();}
    }
}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>GRANDPRIX V17.1 · Continuidad histórica</title><style>
body{margin:0;background:#eef4f9;color:#0b2743;font:15px system-ui,-apple-system,Segoe UI,sans-serif}.wrap{max-width:960px;margin:42px auto;padding:20px}.card{background:#fff;border:1px solid #dbe6ef;border-radius:25px;padding:30px;box-shadow:0 20px 60px #0b274315}.tag{display:inline-block;padding:7px 11px;border-radius:999px;background:#e9f3ff;color:#1265cf;font-size:12px;font-weight:900}h1{font-size:33px;margin:14px 0 8px}p{color:#61778d;line-height:1.55}.flow{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin:20px 0}.flow div{padding:15px;border:1px solid #e2eaf1;border-radius:15px;background:#f8fbfd}.flow b{display:block;margin-bottom:5px}.flow span{font-size:12px;color:#687d91}.ok,.err{padding:15px;border-radius:14px;margin:18px 0}.ok{background:#e9f9f2;color:#167454}.err{background:#fff0f2;color:#b43248}button,a.btn{display:inline-flex;border:0;border-radius:13px;padding:13px 18px;background:#146df5;color:white;font-weight:800;text-decoration:none;cursor:pointer}ul{line-height:1.8;color:#536b80}@media(max-width:720px){.flow{grid-template-columns:1fr 1fr}.wrap{margin:18px auto}.card{padding:22px}}@media(max-width:460px){.flow{grid-template-columns:1fr}}
</style></head><body><div class="wrap"><div class="card"><span class="tag">GRANDPRIX CONTROL 360 · V17.1</span><h1>Continuidad histórica de la cartera real</h1><p>Esta actualización no reinicia GRANDPRIX. Reconstruye el corte real importado desde la cartera original y lo combina con los pagos registrados posteriormente. Las semanas pagadas anteriores quedan como historial; si la fuente no trae la fecha exacta, no se inventa.</p><div class="flow"><div><b>1. Corte real</b><span>Recupera pagadas y mora del Excel original.</span></div><div><b>2. Cronograma</b><span>Construye las 50 semanas por cliente.</span></div><div><b>3. Movimientos</b><span>Reaplica pagos reales con su fecha registrada.</span></div><div><b>4. Recibos</b><span>Genera comprobantes solo con movimientos comprobables.</span></div></div><?php if($error):?><div class="err"><?=htmlspecialchars($error)?></div><?php endif;?><?php if($done):?><div class="ok"><b>Continuidad histórica completada.</b><ul><?php foreach($changes as $c):?><li><?=htmlspecialchars($c)?></li><?php endforeach;?></ul></div><a class="btn" href="../">Abrir Administración</a><?php else:?><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>"><button>Instalar V17.1 · Continuidad histórica</button></form><?php endif;?></div></div></body></html>
