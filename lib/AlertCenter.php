<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/PaymentReceiptService.php';

final class AlertCenter
{
    public const TIMEZONE='America/Caracas';
    public function __construct(private readonly PDO $pdo){}
    public static function create():self{return new self(Database::connection());}

    public function overview():array
    {
        $today=(new DateTimeImmutable('today',new DateTimeZone(self::TIMEZONE)))->format('Y-m-d');
        $now=(new DateTimeImmutable('now',new DateTimeZone(self::TIMEZONE)));
        $nextWednesday=$this->nextWednesday($now)->format('Y-m-d');
        try{(new PaymentReceiptService($this->pdo))->refreshAll();}catch(Throwable){}

        $accounts=$this->pdo->query("SELECT a.id,a.full_name,a.identity_document,a.phone,a.model,a.plate,a.contract_number,a.weekly_amount,a.installments_paid,a.installments_late,a.referrer,
            (SELECT MIN(i.due_date) FROM gp_finance_installments i WHERE i.account_id=a.id AND i.status='late') AS oldest_late_due,
            (SELECT MAX(p.paid_at) FROM gp_finance_payments p WHERE p.account_id=a.id AND p.status='confirmed') AS last_payment_at,
            (SELECT MIN(i2.due_date) FROM gp_finance_installments i2 WHERE i2.account_id=a.id AND i2.status='future') AS next_due_date
            FROM gp_finance_accounts a WHERE a.record_status<>'archived' AND a.installments_late>0
            ORDER BY a.installments_late DESC,oldest_late_due ASC,a.full_name ASC")->fetchAll();
        $alerts=[];$one=0;$two=0;$critical=0;$urgent=0;$lateWeeks=0;
        foreach($accounts as $a){
            $late=(int)$a['installments_late'];$lateWeeks+=$late;
            if($late===1)$one++;elseif($late===2)$two++;elseif($late>=3)$critical++;
            if($late>=5)$urgent++;
            $severity=$late>=5?'urgent':($late>=3?'critical':($late===2?'warning':'attention'));
            $alerts[]=[
                'accountId'=>(int)$a['id'],'client'=>(string)$a['full_name'],'identity'=>$a['identity_document'],'phone'=>$a['phone'],
                'model'=>$a['model'],'plate'=>$a['plate'],'contract'=>$a['contract_number'],'weeklyAmount'=>$a['weekly_amount']===null?null:(float)$a['weekly_amount'],
                'paid'=>(int)$a['installments_paid'],'late'=>$late,'referrer'=>$a['referrer'],'oldestLateDue'=>$a['oldest_late_due'],'lastPaymentAt'=>$a['last_payment_at'],'nextDueDate'=>$a['next_due_date'],
                'severity'=>$severity,'label'=>$late>=5?'Recuperación urgente':($late>=3?'Mora crítica':($late===2?'Mora alta':'Mora inicial')),
            ];
        }
        $review=(int)$this->pdo->query("SELECT COUNT(*) FROM gp_finance_payments WHERE status='review'")->fetchColumn();
        $dueToday=0;$dueNext=0;
        try{
            $q=$this->pdo->prepare("SELECT COUNT(*) FROM gp_finance_installments WHERE status='future' AND due_date=?");$q->execute([$today]);$dueToday=(int)$q->fetchColumn();
            $q->execute([$nextWednesday]);$dueNext=(int)$q->fetchColumn();
        }catch(Throwable){}
        return [
            'timezone'=>self::TIMEZONE,'generatedAt'=>$now->format('Y-m-d H:i:s'),'today'=>$today,'nextWednesday'=>$nextWednesday,
            'metrics'=>['clientsLate'=>count($accounts),'lateWeeks'=>$lateWeeks,'lateOne'=>$one,'lateTwo'=>$two,'critical'=>$critical,'urgent'=>$urgent,'paymentsReview'=>$review,'dueToday'=>$dueToday,'dueNextWednesday'=>$dueNext],
            'alerts'=>$alerts,
        ];
    }

    private function nextWednesday(DateTimeImmutable $date):DateTimeImmutable
    {
        $dow=(int)$date->format('N');$days=($dow<=3)?3-$dow:10-$dow;
        return $date->setTime(0,0)->modify('+'.$days.' days');
    }
}
