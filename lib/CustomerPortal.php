<?php
declare(strict_types=1);

require_once __DIR__ . '/PaymentReceiptService.php';
require_once __DIR__ . '/CustomerNotificationService.php';
require_once __DIR__ . '/CustomerDocumentService.php';

final class CustomerPortal
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?: Database::connection();
    }

    public function authenticate(string $login, string $password): ?array
    {
        $normalized = mb_strtolower(trim($login));
        if ($normalized === '' || $password === '') return null;
        $statement = $this->pdo->prepare(
            "SELECT id, public_key, full_name, identity_document, email, phone, password_hash, status
             FROM gp_customers
             WHERE LOWER(public_key) = :public_key
                OR LOWER(identity_document) = :identity_document
                OR LOWER(COALESCE(email, '')) = :email
             LIMIT 1"
        );
        // PDO MySQL con emulacion desactivada no permite reutilizar un mismo
        // parametro nombrado. Tres nombres evitan SQLSTATE[HY093] en Hostinger.
        $statement->execute([
            'public_key' => $normalized,
            'identity_document' => $normalized,
            'email' => $normalized,
        ]);
        $customer = $statement->fetch();
        if (!$customer || ($customer['status'] ?? '') !== 'active' || !password_verify($password, (string) $customer['password_hash'])) {
            return null;
        }
        $this->pdo->prepare('UPDATE gp_customers SET last_login_at = NOW() WHERE id = ?')->execute([(int) $customer['id']]);
        unset($customer['password_hash']);
        return $customer;
    }

    public function customer(int $customerId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, public_key, full_name, identity_document, email, phone, status FROM gp_customers WHERE id = ? LIMIT 1'
        );
        $statement->execute([$customerId]);
        $customer = $statement->fetch();
        return $customer ?: null;
    }

    public function previewCustomer(?int $customerId = null): ?array
    {
        if ($customerId && $customerId > 0) {
            $statement = $this->pdo->prepare(
                "SELECT id, public_key, full_name, identity_document, email, phone, status
                 FROM gp_customers WHERE id = ? AND status = 'active' LIMIT 1"
            );
            $statement->execute([$customerId]);
        } else {
            $statement = $this->pdo->query(
                "SELECT id, public_key, full_name, identity_document, email, phone, status
                 FROM gp_customers WHERE status = 'active' ORDER BY id LIMIT 1"
            );
        }
        $customer = $statement->fetch();
        return $customer ?: null;
    }

    public function assignedDeviceId(int $customerId): ?int
    {
        $assignment = $this->assignedGps($customerId);
        return $assignment ? (int) $assignment['deviceId'] : null;
    }

    /**
     * Devuelve la identidad GPS guardada en el contrato sin exponerla al
     * navegador. El Unique ID permite reconciliar de forma segura un cambio
     * del ID interno de Traccar, pero nunca autoriza una moto distinta.
     */
    public function assignedGps(int $customerId): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT v.id AS vehicle_id, COALESCE(NULLIF(i.inventory_code,''),v.code) AS code,
                    COALESCE(NULLIF(CONCAT_WS(' ',i.brand,i.model),''),v.model) AS model,
                    COALESCE(i.gps_device_id,v.traccar_device_id) AS traccar_device_id,
                    COALESCE(NULLIF(i.gps_unique_id,''),v.traccar_unique_id) AS traccar_unique_id
             FROM gp_contracts c
             INNER JOIN gp_customers u ON u.id=c.customer_id
             INNER JOIN gp_vehicles v ON v.id = c.vehicle_id
             LEFT JOIN gp_motorcycle_inventory i ON i.current_finance_account_id=u.finance_account_id AND i.status='assigned'
             WHERE c.customer_id = ? AND c.status = 'active'
             ORDER BY c.id DESC LIMIT 1"
        );
        $statement->execute([$customerId]);
        $row = $statement->fetch();
        if (!$row || (int) ($row['traccar_device_id'] ?? 0) < 1) return null;
        return [
            'vehicleId' => (int) $row['vehicle_id'],
            'code' => (string) $row['code'],
            'model' => (string) $row['model'],
            'deviceId' => (int) $row['traccar_device_id'],
            'uniqueId' => trim((string) ($row['traccar_unique_id'] ?? '')),
        ];
    }

    /** Uso exclusivo del reparador administrativo protegido. */
    public function updateAssignedGps(int $customerId, int $deviceId, string $uniqueId): void
    {
        if ($customerId < 1 || $deviceId < 1) throw new InvalidArgumentException('La asignacion GPS no es valida.');
        $assignment = $this->assignedGps($customerId);
        if (!$assignment) throw new InvalidArgumentException('El cliente no tiene un contrato activo con una motocicleta.');
        $statement = $this->pdo->prepare(
            'UPDATE gp_vehicles SET traccar_device_id = ?, traccar_unique_id = ?, updated_at = NOW() WHERE id = ?'
        );
        $statement->execute([$deviceId, trim($uniqueId) ?: null, (int) $assignment['vehicleId']]);
    }

    public function dashboard(int $customerId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT c.id AS contract_id, c.contract_number, c.total_weeks, c.weekly_amount,
                    c.financed_amount, c.start_date, c.status AS contract_status,
                    u.id AS customer_id, u.public_key, u.full_name, u.identity_document, u.email, u.phone,
                    v.id AS vehicle_id, COALESCE(NULLIF(i.inventory_code,''),v.code) AS code,
                    COALESCE(NULLIF(i.plate,''),v.plate) AS plate,
                    COALESCE(NULLIF(CONCAT_WS(' ',i.brand,i.model),''),v.model) AS model,
                    COALESCE(NULLIF(i.color,''),v.color) AS color, v.image_path,
                    COALESCE(i.gps_device_id,v.traccar_device_id) AS traccar_device_id, v.status AS vehicle_status
             FROM gp_contracts c
             INNER JOIN gp_customers u ON u.id = c.customer_id
             INNER JOIN gp_vehicles v ON v.id = c.vehicle_id
             LEFT JOIN gp_motorcycle_inventory i ON i.current_finance_account_id=u.finance_account_id AND i.status='assigned'
             WHERE c.customer_id = ? AND c.status IN ('active', 'completed')
             ORDER BY c.status = 'active' DESC, c.id DESC LIMIT 1"
        );
        $statement->execute([$customerId]);
        $row = $statement->fetch();
        if (!$row) throw new RuntimeException('El cliente no tiene un contrato de financiamiento asignado.');

        // GRANDPRIX cobra todos los miércoles. Una cuota pasa a vencida el jueves
        // siguiente si seguía pendiente al cerrar su miércoles de cobro.
        $this->pdo->prepare("UPDATE gp_contract_weeks SET status='late' WHERE contract_id=? AND status='pending' AND due_date IS NOT NULL AND due_date<CURRENT_DATE")
            ->execute([(int)$row['contract_id']]);

        $weekStatement = $this->pdo->prepare(
            'SELECT week_number, due_date, amount, paid_amount, status, paid_at, payment_report_id FROM gp_contract_weeks WHERE contract_id = ? ORDER BY week_number'
        );
        $weekStatement->execute([(int) $row['contract_id']]);
        $weeks = $weekStatement->fetchAll();

        $receiptService = new PaymentReceiptService($this->pdo);
        if ($receiptService->ready()) {
            $paymentStatement = $this->pdo->prepare(
                "SELECT pr.id,pr.week_number,pr.bank,pr.reference_number,pr.transfer_date,pr.amount,pr.currency,pr.exchange_rate,pr.amount_usd,pr.status,pr.notes,
                        pr.proof_path IS NOT NULL AS has_proof,pr.reviewed_at,pr.created_at,
                        fp.receipt_id,fr.receipt_number
                 FROM gp_payment_reports pr
                 LEFT JOIN gp_finance_payments fp ON fp.portal_report_id=pr.id
                 LEFT JOIN gp_finance_receipts fr ON fr.id=fp.receipt_id
                 WHERE pr.contract_id=? ORDER BY pr.created_at DESC LIMIT 30"
            );
        } else {
            $paymentStatement = $this->pdo->prepare(
                "SELECT id,week_number,bank,reference_number,transfer_date,amount,currency,exchange_rate,amount_usd,status,notes,
                        proof_path IS NOT NULL AS has_proof,reviewed_at,created_at,NULL AS receipt_id,NULL AS receipt_number
                 FROM gp_payment_reports WHERE contract_id=? ORDER BY created_at DESC LIMIT 30"
            );
        }
        $paymentStatement->execute([(int) $row['contract_id']]);
        $payments = $paymentStatement->fetchAll();

        // Los recibos oficiales se consultan independientemente de quién registró el pago.
        // Así un pago ingresado directamente por Administración también aparece en Mi GRANDPRIX.
        $officialReceipts = [];
        if ($receiptService->ready()) {
            $receiptStatement = $this->pdo->prepare(
                "SELECT r.id,r.receipt_number,r.issued_at,r.paid_at,r.amount,r.paid_weeks_json,r.pending_weeks_json,
                        r.pending_total,r.next_week,r.next_due_date,p.payment_method,p.bank,p.reference_number
                 FROM gp_finance_receipts r
                 INNER JOIN gp_finance_accounts a ON a.id=r.account_id
                 INNER JOIN gp_finance_payments p ON p.id=r.payment_id
                 WHERE a.contract_number=?
                 ORDER BY r.paid_at DESC,r.id DESC LIMIT 100"
            );
            $receiptStatement->execute([(string)$row['contract_number']]);
            $officialReceipts = $receiptStatement->fetchAll();
        }

        $paidWeeks = count(array_filter($weeks, static fn(array $week): bool => ($week['status'] ?? '') === 'paid'));
        $reportedWeeks = count(array_filter($weeks, static fn(array $week): bool => ($week['status'] ?? '') === 'reported'));
        $lateWeeks = count(array_filter($weeks, static fn(array $week): bool => ($week['status'] ?? '') === 'late'));
        $paidTotal = array_reduce($weeks, static fn(float $sum, array $week): float =>
            $sum + max(0.0, min((float)$week['amount'], (float)($week['paid_amount'] ?? (($week['status'] ?? '') === 'paid' ? $week['amount'] : 0)))), 0.0);
        $partialWeeks = count(array_filter($weeks, static fn(array $week): bool =>
            (float)($week['paid_amount'] ?? 0) > 0 && (float)($week['paid_amount'] ?? 0) + 0.00001 < (float)$week['amount']));
        $totalWeeks = max(1, (int) $row['total_weeks']);
        $next = null;
        foreach ($weeks as $week) {
            if (($week['status'] ?? '') !== 'paid') {
                $next = $week;
                break;
            }
        }
        $currentWeek = $next ? (int) $next['week_number'] : $totalWeeks;
        $notificationService=new CustomerNotificationService($this->pdo);
        $notifications=$notificationService->list($customerId,60);
        $unreadNotifications=$notificationService->unreadCount($customerId);
        $documentService=new CustomerDocumentService($this->pdo);
        $documents=$documentService->listForCustomer($customerId);

        return [
            'customer' => [
                'id' => (int) $row['customer_id'],
                'key' => (string) $row['public_key'],
                'name' => (string) $row['full_name'],
                'identityDocument' => (string) $row['identity_document'],
                'email' => $row['email'],
                'phone' => $row['phone'],
                'initials' => self::initials((string) $row['full_name']),
            ],
            'vehicle' => [
                'id' => (int) $row['vehicle_id'],
                'code' => (string) $row['code'],
                'plate' => (string) ($row['plate'] ?? ''),
                'model' => (string) $row['model'],
                'color' => $row['color'],
                'image' => (string) $row['image_path'],
                'status' => (string) $row['vehicle_status'],
            ],
            'contract' => [
                'id' => (int) $row['contract_id'],
                'number' => (string) $row['contract_number'],
                'status' => (string) $row['contract_status'],
                'totalWeeks' => $totalWeeks,
                'weeklyAmount' => (float) $row['weekly_amount'],
                'financedAmount' => (float) $row['financed_amount'],
                'startDate' => (string) $row['start_date'],
                'paidWeeks' => $paidWeeks,
                'reportedWeeks' => $reportedWeeks,
                'lateWeeks' => $lateWeeks,
                'pendingWeeks' => max(0, $totalWeeks - $paidWeeks),
                'currentWeek' => $currentWeek,
                'paidTotal' => round($paidTotal, 2),
                'balance' => round(max(0, (float) $row['financed_amount'] - $paidTotal), 2),
                'progress' => round(min(100, ($paidTotal / max(0.01, ((float)$row['weekly_amount'] * $totalWeeks))) * 100), 1),
                'paidEquivalentWeeks' => round($paidTotal / max(0.01,(float)$row['weekly_amount']), 2),
                'partialWeeks' => $partialWeeks,
                'nextDueDate' => $next['due_date'] ?? null,
                'nextAmount' => $next ? round(max(0,(float)$next['amount']-(float)($next['paid_amount']??0)),2) : 0.0,
                'collectionDay' => 'Miércoles',
                'collectionWeekday' => 3,
            ],
            'weeks' => array_map(static fn(array $week): array => [
                'number' => (int) $week['week_number'],
                'dueDate' => (string) $week['due_date'],
                'amount' => (float) $week['amount'],
                'paidAmount' => round((float)($week['paid_amount'] ?? (($week['status']??'')==='paid'?$week['amount']:0)),2),
                'balance' => round(max(0,(float)$week['amount']-(float)($week['paid_amount'] ?? (($week['status']??'')==='paid'?$week['amount']:0))),2),
                'paidPercentage' => (float)$week['amount']>0?round(min(100,((float)($week['paid_amount'] ?? (($week['status']??'')==='paid'?$week['amount']:0))/(float)$week['amount'])*100),1):0,
                'isPartial' => (float)($week['paid_amount']??0)>0 && (float)($week['paid_amount']??0)+0.00001<(float)$week['amount'],
                'status' => (string) $week['status'],
                'paidAt' => $week['paid_at'],
                'paymentReportId' => $week['payment_report_id'] ? (int) $week['payment_report_id'] : null,
            ], $weeks),
            'payments' => array_map(static fn(array $payment): array => [
                'id' => (int) $payment['id'],
                'weekNumber' => (int) $payment['week_number'],
                'bank' => (string) $payment['bank'],
                'reference' => (string) $payment['reference_number'],
                'transferDate' => (string) $payment['transfer_date'],
                'amount' => (float) $payment['amount'],
                'currency' => (string)($payment['currency']??'USD'),
                'exchangeRate' => $payment['exchange_rate']===null?null:(float)$payment['exchange_rate'],
                'amountUsd' => $payment['amount_usd']===null?null:(float)$payment['amount_usd'],
                'status' => (string) $payment['status'],
                'notes' => $payment['notes'],
                'hasProof' => (bool) $payment['has_proof'],
                'reviewedAt' => $payment['reviewed_at'],
                'createdAt' => $payment['created_at'],
                'receiptId' => !empty($payment['receipt_id']) ? (int)$payment['receipt_id'] : null,
                'receiptNumber' => $payment['receipt_number'] ?? null,
            ], $payments),
            'receipts' => array_map(static function(array $receipt): array {
                $paid=json_decode((string)($receipt['paid_weeks_json']??'[]'),true);if(!is_array($paid))$paid=[];
                $pending=json_decode((string)($receipt['pending_weeks_json']??'[]'),true);if(!is_array($pending))$pending=[];
                return [
                    'id'=>(int)$receipt['id'],
                    'number'=>(string)$receipt['receipt_number'],
                    'issuedAt'=>(string)$receipt['issued_at'],
                    'paidAt'=>(string)$receipt['paid_at'],
                    'amount'=>$receipt['amount']===null?null:(float)$receipt['amount'],
                    'paidWeeks'=>array_values(array_map('intval',$paid)),
                    'pendingWeeks'=>array_values(array_map('intval',$pending)),
                    'pendingTotal'=>$receipt['pending_total']===null?null:(float)$receipt['pending_total'],
                    'nextWeek'=>$receipt['next_week']===null?null:(int)$receipt['next_week'],
                    'nextDueDate'=>$receipt['next_due_date'],
                    'paymentMethod'=>$receipt['payment_method']??null,
                    'bank'=>$receipt['bank']??null,
                    'reference'=>$receipt['reference_number']??null,
                    'pdfReady'=>true,
                ];
            }, $officialReceipts),
            'notifications'=>$notifications,
            'unreadNotifications'=>$unreadNotifications,
            'documents'=>$documents,
        ];
    }

    public function markNotificationsRead(int $customerId): void
    {
        (new CustomerNotificationService($this->pdo))->markAllRead($customerId);
    }

    public function customerDocument(int $customerId,int $documentId): ?array
    {
        return (new CustomerDocumentService($this->pdo))->document($documentId,$customerId,false);
    }

    public function reportPayment(int $customerId, array $input, ?array $upload): array
    {
        $dashboard=$this->dashboard($customerId);$contractId=(int)$dashboard['contract']['id'];
        // La semana queda solo como referencia visual; el monto será distribuido automáticamente al conciliar.
        $weekNumber=(int)($dashboard['contract']['currentWeek']??1);
        $requestedWeek=filter_var($input['week_number']??$weekNumber,FILTER_VALIDATE_INT);
        if($requestedWeek!==false && $requestedWeek>0)$weekNumber=(int)$requestedWeek;
        $bank=mb_substr(trim((string)($input['bank']??'')),0,100);
        $reference=preg_replace('/[^A-Za-z0-9-]/','',(string)($input['reference']??''));
        $date=trim((string)($input['transfer_date']??''));$amount=filter_var($input['amount']??null,FILTER_VALIDATE_FLOAT);
        $currency=mb_strtoupper(trim((string)($input['currency']??'USD')));$rateRaw=$input['exchange_rate']??null;$rate=($rateRaw===''||$rateRaw===null)?null:filter_var($rateRaw,FILTER_VALIDATE_FLOAT);
        $notes=mb_substr(trim((string)($input['notes']??'')),0,500);
        if($bank===''||!is_string($reference)||strlen($reference)<4||strlen($reference)>80)throw new InvalidArgumentException('Banco y número de referencia son obligatorios.');
        $dateObject=DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$dateObject||$dateObject->format('Y-m-d')!==$date)throw new InvalidArgumentException('La fecha de transferencia no es válida.');
        if($amount===false||$amount<=0||$amount>100000000)throw new InvalidArgumentException('El monto transferido no es válido.');
        if(!in_array($currency,['USD','BS'],true))throw new InvalidArgumentException('La moneda del pago no es válida.');
        if($currency==='BS'&&($rate===false||$rate===null||$rate<=0))throw new InvalidArgumentException('Indica la tasa Bs./USD utilizada en la transferencia.');
        $amountUsd=$currency==='BS'?round((float)$amount/(float)$rate,2):round((float)$amount,2);
        $weekStatement=$this->pdo->prepare('SELECT id,status FROM gp_contract_weeks WHERE contract_id=? AND week_number=? FOR UPDATE');
        $storedProof=null;$storedMime=null;$this->pdo->beginTransaction();
        try{
            $weekStatement->execute([$contractId,$weekNumber]);$week=$weekStatement->fetch();
            if(!$week){$q=$this->pdo->prepare("SELECT id,week_number,status FROM gp_contract_weeks WHERE contract_id=? AND status<>'paid' ORDER BY due_date,week_number LIMIT 1 FOR UPDATE");$q->execute([$contractId]);$week=$q->fetch();if(!$week)throw new InvalidArgumentException('El contrato no tiene cuotas pendientes.');$weekNumber=(int)$week['week_number'];}
            if(($week['status']??'')==='paid'){ $q=$this->pdo->prepare("SELECT id,week_number,status FROM gp_contract_weeks WHERE contract_id=? AND status<>'paid' ORDER BY due_date,week_number LIMIT 1 FOR UPDATE");$q->execute([$contractId]);$week=$q->fetch();if(!$week)throw new InvalidArgumentException('El contrato ya está completamente pagado.');$weekNumber=(int)$week['week_number']; }
            if($upload&&(int)($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE)[$storedProof,$storedMime]=$this->storeProof($upload,$customerId);
            $insert=$this->pdo->prepare("INSERT INTO gp_payment_reports
                (contract_id,week_number,bank,reference_number,transfer_date,amount,currency,exchange_rate,amount_usd,proof_path,proof_mime,notes,status)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'review')");
            $insert->execute([$contractId,$weekNumber,$bank,$reference,$date,(float)$amount,$currency,$currency==='BS'?(float)$rate:null,$amountUsd,$storedProof,$storedMime,$notes?:null]);
            $reportId=(int)$this->pdo->lastInsertId();
            $this->pdo->prepare("UPDATE gp_contract_weeks SET status='reported',payment_report_id=? WHERE id=? AND status<>'paid'")->execute([$reportId,(int)$week['id']]);
            try{
                $contractNumber=(string)($dashboard['contract']['number']??'');if($contractNumber!==''){
                    $aq=$this->pdo->prepare("SELECT id FROM gp_finance_accounts WHERE contract_number=? AND record_status<>'archived' ORDER BY id DESC LIMIT 1");$aq->execute([$contractNumber]);$accountId=(int)($aq->fetchColumn()?:0);
                    if($accountId>0)$this->pdo->prepare("INSERT IGNORE INTO gp_finance_payments
                        (account_id,paid_at,amount,currency,exchange_rate,amount_usd,applied_usd,unapplied_usd,payment_method,bank,reference_number,installments_applied,late_reduced,notes,status,created_by,week_numbers_json,portal_report_id)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,'review','Mi GRANDPRIX','[]',?)")
                        ->execute([$accountId,$date,(float)$amount,$currency,$currency==='BS'?(float)$rate:null,$amountUsd,0,$amountUsd,'Transferencia reportada por cliente',$bank,$reference,0,0,$notes?:'Reportado desde Mi GRANDPRIX',$reportId]);
                }
            }catch(Throwable){}
            (new CustomerNotificationService($this->pdo))->create($customerId,'payment_review','Pago reportado','Tu transferencia fue recibida y se distribuirá automáticamente sobre las cuotas más antiguas cuando GRANDPRIX la concilie.','gp_payment_reports',$reportId);
            $this->pdo->commit();return['id'=>$reportId,'status'=>'review','weekNumber'=>$weekNumber,'amountUsd'=>$amountUsd];
        }catch(PDOException $error){if($this->pdo->inTransaction())$this->pdo->rollBack();if($storedProof)@unlink($this->proofDirectory().'/'.$storedProof);if((string)$error->getCode()==='23000')throw new InvalidArgumentException('La referencia bancaria ya fue reportada.');throw new RuntimeException('No fue posible guardar el reporte de pago.');}
        catch(Throwable $error){if($this->pdo->inTransaction())$this->pdo->rollBack();if($storedProof)@unlink($this->proofDirectory().'/'.$storedProof);throw$error;}
    }

    public function proof(int $customerId, int $reportId, bool $admin = false): ?array
    {
        $sql = "SELECT p.proof_path, p.proof_mime
                FROM gp_payment_reports p
                INNER JOIN gp_contracts c ON c.id = p.contract_id
                WHERE p.id = ? AND p.proof_path IS NOT NULL" . ($admin ? '' : ' AND c.customer_id = ?') . ' LIMIT 1';
        $statement = $this->pdo->prepare($sql);
        $statement->execute($admin ? [$reportId] : [$reportId, $customerId]);
        $row = $statement->fetch();
        if (!$row) return null;
        $path = $this->proofDirectory() . '/' . basename((string) $row['proof_path']);
        return is_file($path) ? ['path' => $path, 'mime' => (string) $row['proof_mime']] : null;
    }

    public function adminOverview(): array
    {
        $customers = $this->pdo->query(
            "SELECT u.id, u.finance_account_id, u.public_key, u.full_name, u.identity_document, u.email, u.phone, u.status,
                    c.id AS contract_id, c.contract_number, c.total_weeks, c.weekly_amount, c.financed_amount,
                    c.start_date, c.status AS contract_status, v.id AS vehicle_id, v.code,
                    COALESCE(i.plate,v.plate) AS plate, COALESCE(NULLIF(i.model,''),v.model) AS model,
                    i.brand AS inventory_brand,i.engine_cc,i.color AS inventory_color,i.model_year,i.chassis_serial,i.engine_serial,
                    COALESCE(i.gps_device_id,v.traccar_device_id) AS traccar_device_id,
                    i.gps_unique_id,i.gps_label,v.sim_phone,v.relay_verified,
                    SUM(CASE WHEN w.status = 'paid' THEN 1 ELSE 0 END) AS paid_weeks,
                    SUM(CASE WHEN w.status = 'late' THEN 1 ELSE 0 END) AS late_weeks
             FROM gp_customers u
             LEFT JOIN gp_contracts c ON c.customer_id = u.id AND c.status IN ('active', 'completed')
             LEFT JOIN gp_vehicles v ON v.id = c.vehicle_id
             LEFT JOIN gp_motorcycle_inventory i ON (i.current_finance_account_id=u.finance_account_id OR (u.finance_account_id IS NULL AND i.current_customer_id=u.id)) AND i.status='assigned'
             LEFT JOIN gp_contract_weeks w ON w.contract_id = c.id
             GROUP BY u.id, c.id, v.id, i.id ORDER BY u.full_name"
        )->fetchAll();
        $payments = $this->pdo->query(
            "SELECT p.id, p.week_number, p.bank, p.reference_number, p.transfer_date, p.amount, p.status,
                    p.proof_path IS NOT NULL AS has_proof, p.created_at, u.full_name, v.code
             FROM gp_payment_reports p
             INNER JOIN gp_contracts c ON c.id = p.contract_id
             INNER JOIN gp_customers u ON u.id = c.customer_id
             INNER JOIN gp_vehicles v ON v.id = c.vehicle_id
             WHERE p.status = 'review' ORDER BY p.created_at ASC LIMIT 100"
        )->fetchAll();
        $financeCandidates = $this->pdo->query(
            "SELECT fa.id AS finance_account_id,fa.full_name,fa.identity_document,fa.phone,fa.address,
                    fa.contract_number,fa.start_date,fa.weekly_amount,fa.financed_amount,fa.installments_paid,fa.installments_late,
                    i.id AS inventory_id,i.inventory_code,COALESCE(i.plate,fa.plate) AS plate,i.brand,
                    COALESCE(NULLIF(i.model,''),fa.model) AS model,i.engine_cc,i.color,i.model_year,
                    i.chassis_serial,i.engine_serial,COALESCE(i.gps_device_id,fa.gps_device_id) AS gps_device_id,
                    i.gps_unique_id,COALESCE(NULLIF(i.gps_label,''),fa.gps_label) AS gps_label,i.status AS inventory_status,
                    i.current_finance_account_id AS inventory_finance_account_id,
                    pc.id AS portal_customer_id,pc.public_key AS portal_public_key,pc.status AS portal_status
             FROM gp_finance_accounts fa
             LEFT JOIN gp_motorcycle_inventory i ON i.id=(
                 SELECT ix.id
                 FROM gp_motorcycle_inventory ix
                 WHERE ix.status<>'archived' AND (
                     ix.current_finance_account_id=fa.id
                     OR (
                         ix.current_finance_account_id IS NULL
                         AND fa.plate IS NOT NULL AND TRIM(fa.plate)<>''
                         AND UPPER(REPLACE(ix.plate,' ',''))=UPPER(REPLACE(fa.plate,' ',''))
                     )
                     OR (
                         ix.current_finance_account_id IS NULL
                         AND fa.gps_device_id IS NOT NULL
                         AND ix.gps_device_id=fa.gps_device_id
                     )
                 )
                 ORDER BY (ix.current_finance_account_id=fa.id) DESC,
                          (UPPER(REPLACE(ix.plate,' ',''))=UPPER(REPLACE(COALESCE(fa.plate,''),' ',''))) DESC,
                          ix.id DESC
                 LIMIT 1
             )
             LEFT JOIN gp_customers pc ON pc.finance_account_id=fa.id AND pc.status<>'archived'
             WHERE fa.record_status<>'archived'
             ORDER BY fa.full_name,fa.id"
        )->fetchAll();
        return ['customers' => $customers, 'pendingPayments' => $payments, 'financeCandidates'=>$financeCandidates];
    }

    public function reviewPayment(int $reportId, string $decision, string $reviewer): array
    {
        if(!in_array($decision,['approved','rejected'],true))throw new InvalidArgumentException('Decisión de conciliación inválida.');
        $this->pdo->beginTransaction();
        try{
            $statement=$this->pdo->prepare('SELECT * FROM gp_payment_reports WHERE id=? FOR UPDATE');$statement->execute([$reportId]);$payment=$statement->fetch();
            if(!$payment)throw new InvalidArgumentException('El reporte de pago no existe.');if(($payment['status']??'')!=='review')throw new InvalidArgumentException('Este reporte ya fue procesado.');
            $cq=$this->pdo->prepare('SELECT customer_id FROM gp_contracts WHERE id=? LIMIT 1');$cq->execute([(int)$payment['contract_id']]);$notificationCustomerId=(int)($cq->fetchColumn()?:0);
            $receipt=null;
            if($decision==='approved'){
                $this->pdo->prepare("UPDATE gp_payment_reports SET status='approved',reviewed_by=?,reviewed_at=NOW() WHERE id=?")->execute([$reviewer,$reportId]);
                $receiptService=new PaymentReceiptService($this->pdo);if($receiptService->ready())$receipt=$receiptService->syncPortalApprovedPayment($payment,$reviewer);
            }else{
                $this->pdo->prepare("UPDATE gp_payment_reports SET status='rejected',reviewed_by=?,reviewed_at=NOW() WHERE id=?")->execute([$reviewer,$reportId]);
                $this->pdo->prepare("UPDATE gp_contract_weeks SET status=CASE WHEN due_date<CURRENT_DATE THEN 'late' ELSE 'pending' END,payment_report_id=NULL WHERE contract_id=? AND week_number=? AND status='reported'")->execute([(int)$payment['contract_id'],(int)$payment['week_number']]);
                $this->pdo->prepare("UPDATE gp_finance_payments SET status='rejected' WHERE portal_report_id=? AND status='review'")->execute([$reportId]);
            }
            if($notificationCustomerId>0){$ns=new CustomerNotificationService($this->pdo);if($decision==='approved'){$receiptNumber=(string)($receipt['receiptNumber']??'');$ns->create($notificationCustomerId,'payment_approved','Pago conciliado','Tu transferencia fue conciliada y distribuida automáticamente entre tus cuotas pendientes.'.($receiptNumber!==''?' El recibo '.$receiptNumber.' ya está disponible en PDF.':''),'gp_finance_receipts',isset($receipt['id'])?(int)$receipt['id']:null);}else{$ns->create($notificationCustomerId,'payment_rejected','Pago observado','Tu transferencia fue observada/rechazada. Revisa el detalle o contacta a GRANDPRIX.','gp_payment_reports',$reportId);}}
            $this->pdo->commit();return['id'=>$reportId,'status'=>$decision,'receipt'=>$receipt];
        }catch(Throwable $error){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$error;}
    }

    private function storeProof(array $upload, int $customerId): array
    {
        $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) throw new InvalidArgumentException('No fue posible recibir el comprobante.');
        $size = (int) ($upload['size'] ?? 0);
        if ($size < 1 || $size > 5 * 1024 * 1024) throw new InvalidArgumentException('El comprobante debe pesar menos de 5 MB.');
        $temporary = (string) ($upload['tmp_name'] ?? '');
        if ($temporary === '' || !is_uploaded_file($temporary)) throw new InvalidArgumentException('El comprobante recibido no es valido.');
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($temporary);
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];
        if (!isset($extensions[$mime])) throw new InvalidArgumentException('El comprobante debe ser JPG, PNG o PDF.');
        if (str_starts_with($mime, 'image/') && @getimagesize($temporary) === false) {
            throw new InvalidArgumentException('La imagen del comprobante no es valida.');
        }
        $name = sprintf('c%d-%s.%s', $customerId, bin2hex(random_bytes(18)), $extensions[$mime]);
        if (!move_uploaded_file($temporary, $this->proofDirectory() . '/' . $name)) {
            throw new RuntimeException('No fue posible proteger el comprobante recibido.');
        }
        @chmod($this->proofDirectory() . '/' . $name, 0640);
        return [$name, $mime];
    }

    private function proofDirectory(): string
    {
        $directory = dirname(__DIR__) . '/config/payment-proofs';
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('No fue posible preparar el almacenamiento de comprobantes.');
        }
        return $directory;
    }

    private static function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        return mb_strtoupper(mb_substr((string) ($parts[0] ?? 'C'), 0, 1) . mb_substr((string) ($parts[1] ?? ''), 0, 1));
    }
}
