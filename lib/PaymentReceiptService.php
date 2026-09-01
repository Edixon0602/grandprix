<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';

/**
 * GRANDPRIX V28 · Motor financiero unificado.
 *
 * Fuente maestra: gp_finance_accounts.weekly_amount.
 * Cada pago se distribuye por monto, desde la cuota pendiente más antigua.
 * Soporta cuotas completas y abonos parciales sin inventar semanas pagadas.
 */
final class PaymentReceiptService
{
    public function __construct(private readonly PDO $pdo) {}

    public static function create(): self
    {
        return new self(Database::connection());
    }

    public function ready(): bool
    {
        try {
            return $this->tableExists('gp_finance_installments') && $this->columnExists('gp_finance_installments','paid_amount');
        } catch (Throwable) {
            return false;
        }
    }

    private function assertReady(): void
    {
        if (!$this->ready()) {
            throw new RuntimeException('Ejecuta la actualización V28 para habilitar pagos automáticos y abonos parciales.');
        }
    }

    public function refreshAll(): void
    {
        if (!$this->ready()) return;
        $this->pdo->exec("UPDATE gp_finance_installments
            SET status='late'
            WHERE status<>'paid' AND status<>'reported' AND due_date IS NOT NULL AND due_date<CURRENT_DATE");
        $this->pdo->exec("UPDATE gp_finance_installments
            SET status='future'
            WHERE status<>'paid' AND status<>'reported' AND (due_date IS NULL OR due_date>=CURRENT_DATE)");
        $this->pdo->exec("UPDATE gp_finance_accounts a
            SET installments_paid=(SELECT COUNT(*) FROM gp_finance_installments i WHERE i.account_id=a.id AND i.status='paid'),
                installments_late=(SELECT COUNT(*) FROM gp_finance_installments i WHERE i.account_id=a.id AND i.status<>'paid' AND i.due_date IS NOT NULL AND i.due_date<CURRENT_DATE)
            WHERE EXISTS (SELECT 1 FROM gp_finance_installments x WHERE x.account_id=a.id)");
    }

    public function ensureSchedule(int $accountId): array
    {
        $this->assertReady();
        $stmt=$this->pdo->prepare("SELECT * FROM gp_finance_accounts WHERE id=? AND record_status<>'archived' LIMIT 1");
        $stmt->execute([$accountId]);
        $account=$stmt->fetch();
        if(!$account) throw new InvalidArgumentException('El cliente financiero no existe.');

        $total=max(1,(int)($account['total_installments']??50));
        $contractWeeks=[];
        $contractNumber=trim((string)($account['contract_number']??''));
        if($contractNumber!=='' && $this->tableExists('gp_contracts') && $this->tableExists('gp_contract_weeks')){
            $q=$this->pdo->prepare("SELECT w.week_number,w.due_date,w.amount,w.status,w.paid_at,
                    ".($this->columnExists('gp_contract_weeks','paid_amount')?'w.paid_amount':'0 AS paid_amount')."
                FROM gp_contracts c INNER JOIN gp_contract_weeks w ON w.contract_id=c.id
                WHERE c.contract_number=? ORDER BY w.week_number");
            $q->execute([$contractNumber]);
            foreach($q->fetchAll() as $week)$contractWeeks[(int)$week['week_number']]=$week;
        }

        $startRaw=trim((string)($account['start_date']??''));
        $start=$startRaw!==''?DateTimeImmutable::createFromFormat('!Y-m-d',$startRaw):false;
        $anchor=($start && $start->format('Y-m-d')===$startRaw)?self::wednesdayOnOrAfter($start):null;
        $weekly=($account['weekly_amount']??null)===null?null:(float)$account['weekly_amount'];
        $legacyPaid=max(0,(int)($account['installments_paid']??0));
        $legacyLate=max(0,(int)($account['installments_late']??0));

        $insert=$this->pdo->prepare("INSERT INTO gp_finance_installments
            (account_id,installment_no,due_date,amount_due,paid_amount,status,paid_at,source_key)
            VALUES (?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
                due_date=VALUES(due_date),
                amount_due=CASE WHEN gp_finance_installments.status='paid' THEN gp_finance_installments.amount_due ELSE VALUES(amount_due) END,
                paid_amount=CASE WHEN gp_finance_installments.status='paid' THEN COALESCE(gp_finance_installments.amount_due,VALUES(amount_due),0) ELSE gp_finance_installments.paid_amount END");

        for($weekNo=1;$weekNo<=$total;$weekNo++){
            $due=$anchor?$anchor->add(new DateInterval('P'.(($weekNo-1)*7).'D'))->format('Y-m-d'):null;
            $amount=$weekly;
            $paidAmount=0.0;
            $paidAt=null;
            $source='legacy-bootstrap';
            if(isset($contractWeeks[$weekNo])){
                $cw=$contractWeeks[$weekNo];
                if(!$due && !empty($cw['due_date'])) $due=(string)$cw['due_date'];
                if($amount===null && $cw['amount']!==null)$amount=(float)$cw['amount'];
                $paidAmount=max(0,(float)($cw['paid_amount']??0));
                if((string)($cw['status']??'')==='paid')$paidAmount=max($paidAmount,(float)($amount??$cw['amount']??0));
                $paidAt=$cw['paid_at']?:null;
                $source='portal-contract';
            } elseif($weekNo<=$legacyPaid){
                $paidAmount=max(0,(float)($amount??0));
            }
            $status=$paidAmount>0 && $amount!==null && $paidAmount+0.00001 >= (float)$amount ? 'paid' : (($due && $due<date('Y-m-d'))?'late':'future');
            if($weekNo>$legacyPaid && $weekNo<=($legacyPaid+$legacyLate) && $status!=='paid')$status='late';
            $insert->execute([$accountId,$weekNo,$due,$amount,$paidAmount,$status,$paidAt,$source]);
        }

        // La cuota semanal del cliente gobierna todas las semanas no cerradas.
        if($weekly!==null && $weekly>0) $this->syncWeeklyAmount($accountId,$weekly,false);
        $this->refreshAccount($accountId);
        return $this->schedule($accountId,false);
    }

    public function historyBaseline(int $accountId): ?array
    {
        if(!$this->tableExists('gp_finance_history_baseline'))return null;
        $stmt=$this->pdo->prepare('SELECT snapshot_date,source_name,paid_count,late_count,future_count,captured_at FROM gp_finance_history_baseline WHERE account_id=? LIMIT 1');
        $stmt->execute([$accountId]);$row=$stmt->fetch();if(!$row)return null;
        return ['snapshotDate'=>(string)$row['snapshot_date'],'sourceName'=>(string)$row['source_name'],'paid'=>(int)$row['paid_count'],'late'=>(int)$row['late_count'],'future'=>(int)$row['future_count'],'capturedAt'=>(string)$row['captured_at']];
    }

    public function schedule(int $accountId,bool $ensure=true): array
    {
        if($ensure)$this->ensureSchedule($accountId);
        $this->refreshAccount($accountId);
        $stmt=$this->pdo->prepare("SELECT id,installment_no,due_date,amount_due,paid_amount,status,paid_at,paid_payment_id,source_key
            FROM gp_finance_installments WHERE account_id=? ORDER BY installment_no");
        $stmt->execute([$accountId]);
        return array_map(static function(array $row):array{
            $amount=$row['amount_due']===null?null:(float)$row['amount_due'];
            $paid=max(0,(float)($row['paid_amount']??0));
            if((string)$row['status']==='paid' && $amount!==null)$paid=max($paid,$amount);
            $balance=$amount===null?null:max(0,$amount-$paid);
            $pct=$amount!==null && $amount>0?round(min(100,($paid/$amount)*100),2):0.0;
            return [
                'id'=>(int)$row['id'],'number'=>(int)$row['installment_no'],'dueDate'=>$row['due_date'],'amount'=>$amount,
                'paidAmount'=>round($paid,2),'balance'=>$balance===null?null:round($balance,2),'paidPercentage'=>$pct,
                'isPartial'=>$paid>0.00001 && ($balance===null || $balance>0.00001),
                'overdue'=>(string)$row['status']!=='paid' && !empty($row['due_date']) && (string)$row['due_date']<date('Y-m-d'),
                'status'=>(string)$row['status'],'paidAt'=>$row['paid_at'],'paymentId'=>$row['paid_payment_id']===null?null:(int)$row['paid_payment_id'],'source'=>(string)$row['source_key'],
            ];
        },$stmt->fetchAll());
    }

    /** Compatibilidad V17: devuelve las primeras cuotas pendientes. */
    public function chooseWeeks(int $accountId,int $count,array $explicit=[]): array
    {
        $this->ensureSchedule($accountId);
        $count=max(1,min(50,$count));
        $stmt=$this->pdo->prepare("SELECT id,installment_no,status,due_date,amount_due,paid_amount FROM gp_finance_installments
            WHERE account_id=? AND status<>'paid'
            ORDER BY CASE WHEN due_date IS NOT NULL AND due_date<CURRENT_DATE THEN 0 ELSE 1 END,COALESCE(due_date,'9999-12-31'),installment_no
            LIMIT ".(int)$count." FOR UPDATE");
        $stmt->execute([$accountId]);$rows=$stmt->fetchAll();
        if(!$rows)throw new InvalidArgumentException('Este contrato no tiene semanas pendientes por pagar.');
        return $rows;
    }

    /**
     * Aplica el monto REAL del pago. weekNumbers queda solo por compatibilidad;
     * la distribución siempre es automática: deuda más antigua primero.
     */
    public function applyConfirmedPayment(int $paymentId,int $accountId,string $paidAt,array $weekNumbers=[],array $actor=[]): array
    {
        $this->assertReady();
        $this->ensureSchedule($accountId);
        $payment=$this->payment($paymentId);
        if(!$payment)throw new RuntimeException('No fue posible localizar el pago para distribuirlo.');
        $amountUsd=$this->paymentUsdAmount($payment);
        if($amountUsd<=0)throw new InvalidArgumentException('El pago no tiene un monto válido para aplicar.');

        $stmt=$this->pdo->prepare("SELECT id,installment_no,due_date,amount_due,paid_amount,status FROM gp_finance_installments
            WHERE account_id=? AND status<>'paid'
            ORDER BY CASE WHEN due_date IS NOT NULL AND due_date<CURRENT_DATE THEN 0 ELSE 1 END,COALESCE(due_date,'9999-12-31'),installment_no FOR UPDATE");
        $stmt->execute([$accountId]);$weeks=$stmt->fetchAll();
        if(!$weeks)throw new InvalidArgumentException('Este contrato no tiene semanas pendientes por pagar.');

        $remaining=round($amountUsd,2);$allocations=[];$completed=[];$lateReduced=0;
        $allocInsert=$this->pdo->prepare("INSERT INTO gp_finance_payment_allocations
            (payment_id,account_id,installment_id,installment_no,due_date,amount_due,balance_before,allocated_amount,balance_after,completed)
            VALUES (?,?,?,?,?,?,?,?,?,?)");
        $update=$this->pdo->prepare("UPDATE gp_finance_installments SET paid_amount=?,status=?,paid_at=?,paid_payment_id=? WHERE id=?");
        $portalReportId=(int)($payment['portal_report_id']??0);

        foreach($weeks as $week){
            if($remaining<=0.00001)break;
            $amountDue=(float)($week['amount_due']??0);
            if($amountDue<=0){
                $a=$this->accountRow($accountId);$amountDue=(float)($a['weekly_amount']??0);
                if($amountDue<=0)continue;
            }
            $paidBefore=max(0,(float)($week['paid_amount']??0));
            $balanceBefore=max(0,$amountDue-$paidBefore);
            if($balanceBefore<=0.00001)continue;
            $allocated=min($remaining,$balanceBefore);
            $paidAfter=round($paidBefore+$allocated,2);
            $balanceAfter=max(0,round($amountDue-$paidAfter,2));
            $done=$balanceAfter<=0.00001;
            $wasLate=!empty($week['due_date']) && (string)$week['due_date']<date('Y-m-d') && (string)$week['status']!=='paid';
            $newStatus=$done?'paid':((!empty($week['due_date']) && (string)$week['due_date']<date('Y-m-d'))?'late':'future');
            $update->execute([$paidAfter,$newStatus,$done?$paidAt.' 12:00:00':null,$done?$paymentId:null,(int)$week['id']]);
            if($done){$completed[]=(int)$week['installment_no'];if($wasLate)$lateReduced++;}
            $allocInsert->execute([$paymentId,$accountId,(int)$week['id'],(int)$week['installment_no'],$week['due_date'],$amountDue,$balanceBefore,$allocated,$balanceAfter,$done?1:0]);
            $allocations[]=[
                'weekNumber'=>(int)$week['installment_no'],'dueDate'=>$week['due_date'],'amountDue'=>round($amountDue,2),
                'balanceBefore'=>round($balanceBefore,2),'allocated'=>round($allocated,2),'balanceAfter'=>round($balanceAfter,2),
                'completed'=>$done,'paidPercentageAfter'=>round(min(100,($paidAfter/$amountDue)*100),2),
            ];
            $this->syncPortalWeekAllocation($accountId,(int)$week['installment_no'],$amountDue,$paidAfter,$done,$paidAt,$portalReportId);
            $remaining=round($remaining-$allocated,2);
        }

        $touched=array_map(static fn(array $a):int=>(int)$a['weekNumber'],$allocations);
        $applied=round($amountUsd-max(0,$remaining),2);
        $this->updatePaymentDistribution($paymentId,$completed,$touched,$lateReduced,$amountUsd,$applied,max(0,$remaining));
        $this->syncAccountAggregate($accountId);
        $receipt=$this->createReceipt($paymentId,$accountId,$completed,$actor,$allocations);
        try{require_once __DIR__.'/WhatsAppOutbox.php';(new WhatsAppOutbox($this->pdo))->enqueueReceipt((int)($receipt['id']??0));}catch(Throwable){}
        return ['weeks'=>$completed,'touchedWeeks'=>$touched,'allocations'=>$allocations,'lateReduced'=>$lateReduced,'appliedUsd'=>$applied,'unappliedUsd'=>round(max(0,$remaining),2),'receipt'=>$receipt];
    }

    public function createReceipt(int $paymentId,int $accountId,array $paidWeeks,array $actor=[],array $allocations=[]): array
    {
        $existing=$this->receiptByPayment($paymentId);if($existing)return $existing;
        $account=$this->accountRow($accountId);if(!$account)throw new RuntimeException('No fue posible preparar el recibo: cliente no encontrado.');
        $payment=$this->payment($paymentId);if(!$payment)throw new RuntimeException('No fue posible preparar el recibo: pago no encontrado.');
        if(!$allocations)$allocations=$this->paymentAllocations($paymentId);

        $pendingStmt=$this->pdo->prepare("SELECT installment_no,due_date,amount_due,paid_amount FROM gp_finance_installments
            WHERE account_id=? AND status<>'paid' AND due_date IS NOT NULL AND due_date<=? ORDER BY installment_no");
        $pendingStmt->execute([$accountId,(string)$payment['paid_at']]);$pendingRows=$pendingStmt->fetchAll();
        $pending=array_map(static fn(array $r):int=>(int)$r['installment_no'],$pendingRows);
        $pendingTotal=0.0;foreach($pendingRows as $r)$pendingTotal+=max(0,(float)($r['amount_due']??0)-(float)($r['paid_amount']??0));
        $nextStmt=$this->pdo->prepare("SELECT installment_no,due_date,amount_due,paid_amount,status FROM gp_finance_installments WHERE account_id=? AND status<>'paid' ORDER BY CASE WHEN due_date IS NOT NULL AND due_date<CURRENT_DATE THEN 0 ELSE 1 END,COALESCE(due_date,'9999-12-31'),installment_no LIMIT 1");
        $nextStmt->execute([$accountId]);$next=$nextStmt->fetch()?:null;
        $receiptNumber='REC-'.date('Y',strtotime((string)$payment['paid_at'])).'-'.str_pad((string)$paymentId,6,'0',STR_PAD_LEFT);
        $snapshot=[
            'client'=>['name'=>(string)$account['full_name'],'identity'=>$account['identity_document'],'phone'=>$account['phone'],'address'=>$account['address']],
            'motorcycle'=>['model'=>$account['model'],'family'=>$account['model_family'],'plate'=>$account['plate'],'image'=>$account['image_path']],
            'contract'=>['number'=>$account['contract_number'],'startDate'=>$account['start_date'],'totalWeeks'=>(int)$account['total_installments'],'weeklyAmount'=>$account['weekly_amount']===null?null:(float)$account['weekly_amount']],
            'payment'=>['id'=>$paymentId,'date'=>(string)$payment['paid_at'],'amount'=>$payment['amount']===null?null:(float)$payment['amount'],'currency'=>$payment['currency']??'USD','exchangeRate'=>$payment['exchange_rate']??null,'amountUsd'=>$this->paymentUsdAmount($payment),'method'=>$payment['payment_method']??null,'bank'=>$payment['bank'],'reference'=>$payment['reference_number'],'notes'=>$payment['notes'],'operator'=>$payment['created_by']],
            'allocations'=>$allocations,
        ];
        $nextBalance=$next?max(0,(float)($next['amount_due']??0)-(float)($next['paid_amount']??0)):null;
        $issuedAt=date('Y-m-d H:i:s');
        $this->pdo->prepare("INSERT INTO gp_finance_receipts
            (receipt_number,payment_id,account_id,issued_at,paid_at,amount,paid_weeks_json,pending_weeks_json,pending_total,next_week,next_due_date,snapshot_json,created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
                $receiptNumber,$paymentId,$accountId,$issuedAt,$payment['paid_at'],$payment['amount'],json_encode(array_values($paidWeeks)),json_encode($pending),round($pendingTotal,2),
                $next?(int)$next['installment_no']:null,$next['due_date']??null,json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),(string)($actor['email']??$payment['created_by']??'admin')
            ]);
        $receiptId=(int)$this->pdo->lastInsertId();
        $this->pdo->prepare('UPDATE gp_finance_payments SET receipt_id=? WHERE id=?')->execute([$receiptId,$paymentId]);
        $receipt=$this->receipt($receiptId)??['id'=>$receiptId,'receiptNumber'=>$receiptNumber];
        $receipt['nextAmount']=$nextBalance===null?null:round($nextBalance,2);
        return $receipt;
    }

    public function receipts(int $limit=80): array
    {
        if(!$this->ready())return[];$limit=max(1,min(250,$limit));
        $rows=$this->pdo->query("SELECT r.*,a.full_name,a.identity_document,a.phone,a.model,a.plate,a.contract_number,p.payment_method,p.bank,p.reference_number,p.created_by AS payment_created_by,p.currency,p.exchange_rate,p.amount_usd,p.applied_usd,p.unapplied_usd
            FROM gp_finance_receipts r INNER JOIN gp_finance_accounts a ON a.id=r.account_id INNER JOIN gp_finance_payments p ON p.id=r.payment_id ORDER BY r.id DESC LIMIT ".(int)$limit)->fetchAll();
        return array_map(fn(array $r):array=>$this->presentReceipt($r),$rows);
    }

    public function receipt(int $id): ?array
    {
        if(!$this->ready())return null;
        $stmt=$this->pdo->prepare("SELECT r.*,a.full_name,a.identity_document,a.phone,a.address,a.model,a.model_family,a.image_path,a.plate,a.contract_number,a.start_date,a.total_installments,a.weekly_amount,p.payment_method,p.bank,p.reference_number,p.notes AS payment_notes,p.created_by AS payment_created_by,p.currency,p.exchange_rate,p.amount_usd,p.applied_usd,p.unapplied_usd
            FROM gp_finance_receipts r INNER JOIN gp_finance_accounts a ON a.id=r.account_id INNER JOIN gp_finance_payments p ON p.id=r.payment_id WHERE r.id=? LIMIT 1");
        $stmt->execute([$id]);$row=$stmt->fetch();return$row?$this->presentReceipt($row):null;
    }

    public function receiptByPayment(int $paymentId): ?array
    {
        if(!$this->ready())return null;$stmt=$this->pdo->prepare('SELECT id FROM gp_finance_receipts WHERE payment_id=? LIMIT 1');$stmt->execute([$paymentId]);$id=(int)($stmt->fetchColumn()?:0);return$id>0?$this->receipt($id):null;
    }

    public function portalReceiptForCustomer(int $receiptId,int $customerId): ?array
    {
        $receipt=$this->receipt($receiptId);if(!$receipt)return null;$contract=(string)($receipt['contractNumber']??'');if($contract==='')return null;
        $stmt=$this->pdo->prepare('SELECT c.id FROM gp_contracts c WHERE c.contract_number=? AND c.customer_id=? LIMIT 1');$stmt->execute([$contract,$customerId]);return$stmt->fetchColumn()!==false?$receipt:null;
    }

    public function syncPortalApprovedPayment(array $portalPayment,string $reviewer): ?array
    {
        $this->assertReady();$reportId=(int)($portalPayment['id']??0);$contractId=(int)($portalPayment['contract_id']??0);if($reportId<1||$contractId<1)return null;
        $exists=$this->pdo->prepare('SELECT id,receipt_id,status,account_id FROM gp_finance_payments WHERE portal_report_id=? LIMIT 1');$exists->execute([$reportId]);$row=$exists->fetch();
        $contractStmt=$this->pdo->prepare('SELECT contract_number FROM gp_contracts WHERE id=? LIMIT 1');$contractStmt->execute([$contractId]);$contractNumber=(string)($contractStmt->fetchColumn()?:'');if($contractNumber==='')return null;
        $accountStmt=$this->pdo->prepare("SELECT id FROM gp_finance_accounts WHERE contract_number=? AND record_status<>'archived' ORDER BY id DESC LIMIT 1");$accountStmt->execute([$contractNumber]);$accountId=(int)($accountStmt->fetchColumn()?:0);if($accountId<1)return null;
        $date=(string)($portalPayment['transfer_date']??date('Y-m-d'));
        $amount=(float)($portalPayment['amount']??0);$currency=strtoupper((string)($portalPayment['currency']??'USD'));if(!in_array($currency,['USD','BS'],true))$currency='USD';
        $rate=($portalPayment['exchange_rate']??null)!==null?(float)$portalPayment['exchange_rate']:null;$amountUsd=$currency==='BS'?($rate&&$rate>0?$amount/$rate:0):$amount;
        if($amountUsd<=0)throw new InvalidArgumentException('No fue posible convertir el monto reportado a USD.');
        if($row){
            $rid=(int)($row['receipt_id']??0);if($rid>0)return$this->receipt($rid);
            $paymentId=(int)$row['id'];
            $this->pdo->prepare("UPDATE gp_finance_payments SET status='confirmed',paid_at=?,amount=?,currency=?,exchange_rate=?,amount_usd=?,payment_method='Transferencia reportada por cliente',bank=?,reference_number=?,created_by=? WHERE id=?")
                ->execute([$date,$amount,$currency,$rate,$amountUsd,$portalPayment['bank']??null,$portalPayment['reference_number']??null,$reviewer,$paymentId]);
            $applied=$this->applyConfirmedPayment($paymentId,$accountId,$date,[],['email'=>$reviewer]);return$applied['receipt']??null;
        }
        $this->ensureSchedule($accountId);
        $notes=trim((string)($portalPayment['notes']??''));
        $this->pdo->prepare("INSERT INTO gp_finance_payments
            (account_id,paid_at,amount,currency,exchange_rate,amount_usd,payment_method,bank,reference_number,installments_applied,late_reduced,notes,status,created_by,week_numbers_json,portal_report_id)
            VALUES (?,?,?,?,?,?,'Transferencia reportada por cliente',?,?,0,0,?,'confirmed',?,'[]',?)")
            ->execute([$accountId,$date,$amount,$currency,$rate,$amountUsd,$portalPayment['bank']??null,$portalPayment['reference_number']??null,$notes?:'Conciliado desde Mi GRANDPRIX',$reviewer,$reportId]);
        $paymentId=(int)$this->pdo->lastInsertId();$applied=$this->applyConfirmedPayment($paymentId,$accountId,$date,[],['email'=>$reviewer]);return$applied['receipt']??null;
    }

    public function syncFromAggregate(int $accountId,int $paid,int $late): void
    {
        if(!$this->ready())return;$this->ensureSchedule($accountId);
        $hasReal=$this->pdo->prepare('SELECT COUNT(*) FROM gp_finance_payment_allocations WHERE account_id=?');$hasReal->execute([$accountId]);if((int)$hasReal->fetchColumn()>0)return;
        $account=$this->accountRow($accountId);$weekly=(float)($account['weekly_amount']??0);
        $stmt=$this->pdo->prepare("UPDATE gp_finance_installments SET
            paid_amount=CASE WHEN installment_no<=? THEN COALESCE(amount_due,?) ELSE 0 END,
            status=CASE WHEN installment_no<=? THEN 'paid' WHEN installment_no<=? THEN 'late' ELSE 'future' END
            WHERE account_id=?");
        $stmt->execute([$paid,$weekly,$paid,$paid+$late,$accountId]);$this->refreshAccount($accountId);
    }

    /** Sincroniza la cuota semanal en cartera, contrato, semanas y portal. */
    public function syncWeeklyAmount(int $accountId,float $weeklyAmount,bool $updateAccount=true): void
    {
        if($weeklyAmount<=0)return;
        if($updateAccount)$this->pdo->prepare('UPDATE gp_finance_accounts SET weekly_amount=? WHERE id=?')->execute([$weeklyAmount,$accountId]);
        if($this->tableExists('gp_finance_installments')&&$this->columnExists('gp_finance_installments','paid_amount')){
            $this->pdo->prepare("UPDATE gp_finance_installments SET amount_due=GREATEST(?,paid_amount) WHERE account_id=? AND status<>'paid'")->execute([$weeklyAmount,$accountId]);
        }
        $account=$this->accountRow($accountId);$contract=trim((string)($account['contract_number']??''));
        if($contract!==''&&$this->tableExists('gp_contracts')){
            $c=$this->pdo->prepare("SELECT id FROM gp_contracts WHERE contract_number=? ORDER BY id DESC LIMIT 1");$c->execute([$contract]);$contractId=(int)($c->fetchColumn()?:0);
            if($contractId>0){
                $this->pdo->prepare('UPDATE gp_contracts SET weekly_amount=? WHERE id=?')->execute([$weeklyAmount,$contractId]);
                if($this->tableExists('gp_contract_weeks')){
                    if($this->columnExists('gp_contract_weeks','paid_amount'))$this->pdo->prepare("UPDATE gp_contract_weeks SET amount=GREATEST(?,paid_amount) WHERE contract_id=? AND status<>'paid'")->execute([$weeklyAmount,$contractId]);
                    else $this->pdo->prepare("UPDATE gp_contract_weeks SET amount=? WHERE contract_id=? AND status<>'paid'")->execute([$weeklyAmount,$contractId]);
                }
            }
        }
    }

    public function paymentAllocations(int $paymentId): array
    {
        if(!$this->tableExists('gp_finance_payment_allocations'))return[];
        $st=$this->pdo->prepare('SELECT installment_no,due_date,amount_due,balance_before,allocated_amount,balance_after,completed FROM gp_finance_payment_allocations WHERE payment_id=? ORDER BY id');$st->execute([$paymentId]);
        return array_map(static fn(array $a):array=>[
            'weekNumber'=>(int)$a['installment_no'],'dueDate'=>$a['due_date'],'amountDue'=>(float)$a['amount_due'],'balanceBefore'=>(float)$a['balance_before'],'allocated'=>(float)$a['allocated_amount'],'balanceAfter'=>(float)$a['balance_after'],'completed'=>(bool)$a['completed'],
            'paidPercentageAfter'=>(float)$a['amount_due']>0?round(min(100,(((float)$a['amount_due']-(float)$a['balance_after'])/(float)$a['amount_due'])*100),2):0.0,
        ],$st->fetchAll());
    }

    private function refreshAccount(int $accountId): void
    {
        if(!$this->ready())return;
        $this->pdo->prepare("UPDATE gp_finance_installments SET status=CASE WHEN due_date IS NOT NULL AND due_date<CURRENT_DATE THEN 'late' ELSE 'future' END WHERE account_id=? AND status<>'paid' AND status<>'reported'")->execute([$accountId]);
        $this->syncAccountAggregate($accountId);
    }

    private function syncAccountAggregate(int $accountId): void
    {
        $stmt=$this->pdo->prepare("SELECT SUM(status='paid') paid_count,SUM(status<>'paid' AND due_date IS NOT NULL AND due_date<CURRENT_DATE) late_count FROM gp_finance_installments WHERE account_id=?");$stmt->execute([$accountId]);$c=$stmt->fetch()?:[];
        $this->pdo->prepare('UPDATE gp_finance_accounts SET installments_paid=?,installments_late=? WHERE id=?')->execute([(int)($c['paid_count']??0),(int)($c['late_count']??0),$accountId]);
    }

    private function syncPortalWeekAllocation(int $accountId,int $weekNo,float $amountDue,float $paidAmount,bool $completed,string $paidAt,int $reportId=0): void
    {
        if(!$this->tableExists('gp_contracts')||!$this->tableExists('gp_contract_weeks'))return;
        $account=$this->accountRow($accountId);$contract=trim((string)($account['contract_number']??''));if($contract==='')return;
        $c=$this->pdo->prepare('SELECT id FROM gp_contracts WHERE contract_number=? ORDER BY id DESC LIMIT 1');$c->execute([$contract]);$contractId=(int)($c->fetchColumn()?:0);if($contractId<1)return;
        $status=$completed?'paid':null;
        if(!$completed){$d=$this->pdo->prepare('SELECT due_date FROM gp_contract_weeks WHERE contract_id=? AND week_number=? LIMIT 1');$d->execute([$contractId,$weekNo]);$due=(string)($d->fetchColumn()?:'');$status=$due!==''&&$due<date('Y-m-d')?'late':'pending';}
        if($this->columnExists('gp_contract_weeks','paid_amount')){
            $u=$this->pdo->prepare('UPDATE gp_contract_weeks SET amount=?,paid_amount=?,status=?,paid_at=?,payment_report_id=CASE WHEN ?>0 THEN ? ELSE payment_report_id END WHERE contract_id=? AND week_number=?');
            $u->execute([$amountDue,$paidAmount,$status,$completed?$paidAt.' 12:00:00':null,$reportId,$reportId,$contractId,$weekNo]);
        }else{
            $u=$this->pdo->prepare('UPDATE gp_contract_weeks SET amount=?,status=?,paid_at=? WHERE contract_id=? AND week_number=?');$u->execute([$amountDue,$status,$completed?$paidAt.' 12:00:00':null,$contractId,$weekNo]);
        }
    }

    private function updatePaymentDistribution(int $paymentId,array $completedWeeks,array $touchedWeeks,int $lateReduced,float $amountUsd,float $applied,float $unapplied): void
    {
        // installments_applied representa exclusivamente cuotas cerradas al 100%.
        // week_numbers_json conserva todas las cuotas tocadas, incluida la cuota con abono parcial.
        $this->pdo->prepare('UPDATE gp_finance_payments SET installments_applied=?,late_reduced=?,week_numbers_json=?,amount_usd=?,applied_usd=?,unapplied_usd=? WHERE id=?')
            ->execute([count(array_unique($completedWeeks)),$lateReduced,json_encode(array_values(array_unique($touchedWeeks)),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),round($amountUsd,2),round($applied,2),round($unapplied,2),$paymentId]);
    }

    private function presentReceipt(array $row): array
    {
        $snapshot=json_decode((string)($row['snapshot_json']??''),true);if(!is_array($snapshot))$snapshot=[];
        $paid=json_decode((string)($row['paid_weeks_json']??'[]'),true);if(!is_array($paid))$paid=[];
        $pending=json_decode((string)($row['pending_weeks_json']??'[]'),true);if(!is_array($pending))$pending=[];
        $alloc=$this->paymentAllocations((int)$row['payment_id']);if(!$alloc)$alloc=(array)($snapshot['allocations']??[]);
        $partial=array_values(array_filter($alloc,static fn(array $a):bool=>empty($a['completed']) && (float)($a['allocated']??0)>0));
        $nextAmount=null;
        if($row['next_week']!==null){$q=$this->pdo->prepare('SELECT GREATEST(0,COALESCE(amount_due,0)-COALESCE(paid_amount,0)) FROM gp_finance_installments WHERE account_id=? AND installment_no=? LIMIT 1');$q->execute([(int)$row['account_id'],(int)$row['next_week']]);$v=$q->fetchColumn();if($v!==false)$nextAmount=(float)$v;}
        return [
            'id'=>(int)$row['id'],'receiptNumber'=>(string)$row['receipt_number'],'paymentId'=>(int)$row['payment_id'],'accountId'=>(int)$row['account_id'],'issuedAt'=>(string)$row['issued_at'],'paidAt'=>(string)$row['paid_at'],'amount'=>$row['amount']===null?null:(float)$row['amount'],
            'currency'=>(string)($row['currency']??($snapshot['payment']['currency']??'USD')),'exchangeRate'=>isset($row['exchange_rate'])&&$row['exchange_rate']!==null?(float)$row['exchange_rate']:($snapshot['payment']['exchangeRate']??null),'amountUsd'=>isset($row['amount_usd'])&&$row['amount_usd']!==null?(float)$row['amount_usd']:($snapshot['payment']['amountUsd']??null),'appliedUsd'=>isset($row['applied_usd'])&&$row['applied_usd']!==null?(float)$row['applied_usd']:null,'unappliedUsd'=>isset($row['unapplied_usd'])&&$row['unapplied_usd']!==null?(float)$row['unapplied_usd']:null,
            'paidWeeks'=>array_values(array_map('intval',$paid)),'pendingWeeks'=>array_values(array_map('intval',$pending)),'pendingTotal'=>$row['pending_total']===null?null:(float)$row['pending_total'],'nextWeek'=>$row['next_week']===null?null:(int)$row['next_week'],'nextDueDate'=>$row['next_due_date'],'nextAmount'=>$nextAmount===null?null:round($nextAmount,2),
            'allocations'=>$alloc,'partialWeeks'=>$partial,
            'clientName'=>(string)($row['full_name']??($snapshot['client']['name']??'')),'identityDocument'=>$row['identity_document']??($snapshot['client']['identity']??null),'phone'=>$row['phone']??($snapshot['client']['phone']??null),'address'=>$row['address']??($snapshot['client']['address']??null),
            'model'=>$row['model']??($snapshot['motorcycle']['model']??null),'modelFamily'=>$row['model_family']??($snapshot['motorcycle']['family']??null),'imagePath'=>$row['image_path']??($snapshot['motorcycle']['image']??null),'plate'=>$row['plate']??($snapshot['motorcycle']['plate']??null),
            'contractNumber'=>$row['contract_number']??($snapshot['contract']['number']??null),'startDate'=>$row['start_date']??($snapshot['contract']['startDate']??null),'totalWeeks'=>(int)($row['total_installments']??($snapshot['contract']['totalWeeks']??50)),'weeklyAmount'=>isset($row['weekly_amount'])&&$row['weekly_amount']!==null?(float)$row['weekly_amount']:($snapshot['contract']['weeklyAmount']??null),
            'paymentMethod'=>$row['payment_method']??($snapshot['payment']['method']??null),'bank'=>$row['bank']??($snapshot['payment']['bank']??null),'reference'=>$row['reference_number']??($snapshot['payment']['reference']??null),'notes'=>$row['payment_notes']??($snapshot['payment']['notes']??null),'operator'=>$row['payment_created_by']??($snapshot['payment']['operator']??null),'snapshot'=>$snapshot,
        ];
    }

    private function payment(int $id): array
    {
        $st=$this->pdo->prepare('SELECT * FROM gp_finance_payments WHERE id=? LIMIT 1');$st->execute([$id]);return$st->fetch()?:[];
    }
    private function accountRow(int $id): array
    {
        $st=$this->pdo->prepare('SELECT * FROM gp_finance_accounts WHERE id=? LIMIT 1');$st->execute([$id]);return$st->fetch()?:[];
    }
    private function paymentUsdAmount(array $payment): float
    {
        $usd=(float)($payment['amount_usd']??0);if($usd>0)return round($usd,2);
        $amount=(float)($payment['amount']??0);$currency=strtoupper((string)($payment['currency']??'USD'));$rate=(float)($payment['exchange_rate']??0);
        return round($currency==='BS'&&$rate>0?$amount/$rate:$amount,2);
    }
    public static function wednesdayOnOrAfter(DateTimeImmutable $date): DateTimeImmutable
    {
        $days=(3-(int)$date->format('N')+7)%7;return$days===0?$date:$date->modify('+'.$days.' days');
    }
    private function tableExists(string $table): bool
    {
        try{$stmt=$this->pdo->query("SHOW TABLES LIKE ".$this->pdo->quote($table));return(bool)$stmt->fetchColumn();}catch(Throwable){return false;}
    }
    private function columnExists(string $table,string $column): bool
    {
        try{$stmt=$this->pdo->query("SHOW COLUMNS FROM `".str_replace('`','``',$table)."` LIKE ".$this->pdo->quote($column));return(bool)$stmt->fetch();}catch(Throwable){return false;}
    }
}
