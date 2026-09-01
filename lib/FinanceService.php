<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/AdminAuth.php';
require_once __DIR__ . '/PaymentReceiptService.php';
require_once __DIR__ . '/InventoryService.php';
require_once __DIR__ . '/CustomerNotificationService.php';

final class FinanceService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function create(): self
    {
        return new self(Database::connection());
    }

    public function overview(): array
    {
        $receiptService = new PaymentReceiptService($this->pdo);
        $receiptService->refreshAll();
        $metrics = $this->pdo->query(
            "SELECT
                COUNT(*) AS total_records,
                SUM(CASE WHEN installments_paid < total_installments THEN 1 ELSE 0 END) AS active_accounts,
                SUM(CASE WHEN installments_paid < total_installments AND installments_late = 0 THEN 1 ELSE 0 END) AS current_accounts,
                SUM(CASE WHEN installments_paid < total_installments AND installments_late > 0 THEN 1 ELSE 0 END) AS late_accounts,
                SUM(installments_late) AS late_installments,
                SUM(CASE WHEN installments_paid >= total_installments THEN 1 ELSE 0 END) AS completed_accounts,
                SUM(CASE WHEN installments_late = 1 THEN 1 ELSE 0 END) AS late_x1,
                SUM(CASE WHEN installments_late = 2 THEN 1 ELSE 0 END) AS late_x2,
                SUM(CASE WHEN installments_late > 2 THEN 1 ELSE 0 END) AS recovery_cases,
                SUM(CASE WHEN UPPER(COALESCE(advance_note,'')) = 'RE' THEN 1 ELSE 0 END) AS recovered_accounts,
                SUM(CASE WHEN gps_device_id IS NOT NULL THEN 1 ELSE 0 END) AS gps_assigned,
                SUM(CASE WHEN advance_amount IS NOT NULL THEN advance_amount ELSE 0 END) AS advance_total
             FROM gp_finance_accounts WHERE record_status <> 'archived'"
        )->fetch() ?: [];
        foreach ($metrics as $key => $value) {
            if (str_contains($key, 'total') && $key === 'advance_total') $metrics[$key] = (float) $value;
            elseif ($value !== null) $metrics[$key] = (int) $value;
        }
        $history=['enabled'=>false,'accounts'=>0,'paid'=>0,'late'=>0,'future'=>0,'snapshotDate'=>null,'sourceName'=>null];
        try {
            $q=$this->pdo->query("SELECT COUNT(*) accounts,SUM(paid_count) paid,SUM(late_count) late,SUM(future_count) future,MAX(snapshot_date) snapshot_date,MAX(source_name) source_name FROM gp_finance_history_baseline");
            $h=$q->fetch()?:[];
            if((int)($h['accounts']??0)>0)$history=['enabled'=>true,'accounts'=>(int)$h['accounts'],'paid'=>(int)($h['paid']??0),'late'=>(int)($h['late']??0),'future'=>(int)($h['future']??0),'snapshotDate'=>$h['snapshot_date']??null,'sourceName'=>$h['source_name']??null];
        } catch(Throwable) {}
        return [
            'metrics' => $metrics,
            'referrers' => $this->referrerSummary(),
            'accounts' => $this->accounts(),
            'payments' => $this->recentPayments(80),
            'gpsDevices' => $this->gpsDevices(),
            'receipts' => $receiptService->receipts(80),
            'history' => $history,
            'paymentAnalytics' => $this->paymentAnalytics(null, null, 'month', true),
        ];
    }

    public function accounts(): array
    {
        $rows = $this->pdo->query(
            "SELECT id, source_row, full_name, identity_document, phone, address, contract_number, weekly_amount, financed_amount, start_date, model, model_family, image_path, plate,
                    total_installments, installments_paid, installments_late, advance_note, advance_amount, referrer,
                    gps_device_id, gps_label, notes, record_status, created_at, updated_at
             FROM gp_finance_accounts WHERE record_status <> 'archived'
             ORDER BY source_row IS NULL, source_row, full_name"
        )->fetchAll();
        foreach ($rows as &$row) $row = $this->presentAccount($row);
        unset($row);
        return $rows;
    }

    public function account(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM gp_finance_accounts WHERE id = ? AND record_status <> \'archived\' LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) return null;
        $result = $this->presentAccount($row);
        $stmt = $this->pdo->prepare('SELECT * FROM gp_finance_payments WHERE account_id = ? ORDER BY paid_at DESC, id DESC LIMIT 150');
        $stmt->execute([$id]);
        $result['payments'] = $stmt->fetchAll();
        $receiptService = new PaymentReceiptService($this->pdo);
        if ($receiptService->ready()) {
            $result['installments'] = $receiptService->schedule($id);
            $result['receipts'] = array_values(array_filter($receiptService->receipts(120), static fn(array $r): bool => (int)$r['accountId'] === $id));
            $result['historyBaseline'] = $receiptService->historyBaseline($id);
        } else {
            $result['installments'] = [];
            $result['receipts'] = [];
            $result['historyBaseline'] = null;
        }
        return $result;
    }

    private function presentAccount(array $row): array
    {
        $paid = (int) $row['installments_paid'];
        $late = (int) $row['installments_late'];
        $total = max(1, (int) $row['total_installments']);
        $remaining = max(0, $total - $paid);
        $future = max(0, $total - $paid - $late);
        $recovered = mb_strtoupper(trim((string) ($row['advance_note'] ?? ''))) === 'RE';
        $status = $paid >= $total ? 'completed' : ($late >= 3 ? 'critical' : ($late > 0 ? 'late' : ($recovered ? 'recovered' : 'current')));
        return [
            'id' => (int) $row['id'], 'sourceRow' => $row['source_row'] === null ? null : (int) $row['source_row'],
            'fullName' => (string) $row['full_name'], 'identityDocument' => $row['identity_document'], 'phone' => $row['phone'],
            'address' => $row['address'] ?? null, 'contractNumber' => $row['contract_number'] ?? null,
            'weeklyAmount' => ($row['weekly_amount'] ?? null) === null ? null : (float) $row['weekly_amount'],
            'financedAmount' => ($row['financed_amount'] ?? null) === null ? null : (float) $row['financed_amount'], 'startDate' => $row['start_date'] ?? null,
            'model' => $row['model'], 'modelFamily' => (string) ($row['model_family'] ?: ($row['model'] ?: 'Sin modelo')),
            'imagePath' => (string) ($row['image_path'] ?: 'assets/moto-blue.png'), 'plate' => $row['plate'],
            'totalInstallments' => $total, 'paid' => $paid, 'late' => $late, 'remaining' => $remaining, 'future' => $future,
            'advanceNote' => $row['advance_note'], 'advanceAmount' => $row['advance_amount'] === null ? null : (float) $row['advance_amount'],
            'referrer' => $row['referrer'], 'gpsDeviceId' => $row['gps_device_id'] === null ? null : (int) $row['gps_device_id'],
            'gpsLabel' => $row['gps_label'], 'notes' => $row['notes'], 'status' => $status, 'recovered' => $recovered,
            'progress' => round(($paid / $total) * 100, 2), 'updatedAt' => $row['updated_at'],
        ];
    }

    public function referrerSummary(): array
    {
        $refs = $this->pdo->query('SELECT display_name, source_key FROM gp_finance_referrers ORDER BY sort_order, id')->fetchAll();
        $result = [];
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) - SUM(CASE WHEN installments_paid >= total_installments THEN 1 ELSE 0 END) AS street,
                    SUM(CASE WHEN installments_late = 0 THEN 1 ELSE 0 END) - SUM(CASE WHEN installments_paid >= total_installments THEN 1 ELSE 0 END) AS current_count,
                    SUM(CASE WHEN installments_late = 1 THEN 1 ELSE 0 END) AS x1,
                    SUM(CASE WHEN installments_late = 2 THEN 1 ELSE 0 END) AS x2,
                    SUM(CASE WHEN installments_late = 3 THEN 1 ELSE 0 END) AS x3,
                    SUM(CASE WHEN installments_late > 3 THEN 1 ELSE 0 END) AS x4plus,
                    SUM(CASE WHEN UPPER(COALESCE(advance_note,'')) = 'RE' THEN 1 ELSE 0 END) AS recovered,
                    SUM(CASE WHEN installments_paid >= total_installments THEN 1 ELSE 0 END) AS completed,
                    SUM(installments_late) AS late_installments
             FROM gp_finance_accounts WHERE record_status <> 'archived' AND referrer = ?"
        );
        foreach ($refs as $ref) {
            $stmt->execute([(string) $ref['source_key']]);
            $row = $stmt->fetch() ?: [];
            $street = max(0, (int) ($row['street'] ?? 0));
            $current = max(0, (int) ($row['current_count'] ?? 0));
            $x3 = (int) ($row['x3'] ?? 0); $x4 = (int) ($row['x4plus'] ?? 0);
            $result[] = [
                'name' => (string) $ref['display_name'], 'sourceKey' => (string) $ref['source_key'], 'street' => $street,
                'current' => $current, 'x1' => (int) ($row['x1'] ?? 0), 'x2' => (int) ($row['x2'] ?? 0),
                'x3' => $x3, 'x4plus' => $x4, 'recovered' => (int) ($row['recovered'] ?? 0),
                'completed' => (int) ($row['completed'] ?? 0), 'lateInstallments' => (int) ($row['late_installments'] ?? 0),
                'pctCurrent' => $street ? round(($current / $street) * 100, 2) : 0,
                'pctLate' => $street ? round((($street - $current) / $street) * 100, 2) : 0,
                'pctCritical' => $street ? round((($x3 + $x4) / $street) * 100, 2) : 0,
            ];
        }
        return $result;
    }

    public function applications(): array
    {
        $rows = $this->pdo->query(
            "SELECT id, application_code, applicant_name, identity_document, phone, model_requested, referrer,
                    status, requested_at, notes, created_by, created_at, updated_at
             FROM gp_finance_applications ORDER BY requested_at DESC, id DESC"
        )->fetchAll();
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
        }
        unset($row);
        return $rows;
    }

    public function saveApplication(array $input, array $actor): array
    {
        $id = filter_var($input['id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $id = $id === false ? 0 : (int) $id;
        $name = mb_substr(trim((string) ($input['applicantName'] ?? '')), 0, 160);
        $identity = mb_substr(trim((string) ($input['identityDocument'] ?? '')), 0, 40);
        $ageRaw=$input['age']??null;$age=$ageRaw===''||$ageRaw===null?null:filter_var($ageRaw,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>120]]);
        if($ageRaw!==''&&$ageRaw!==null&&$age===false)throw new InvalidArgumentException('La edad no es válida.');
        $phone = mb_substr(trim((string) ($input['phone'] ?? '')), 0, 40);
        $model = mb_substr(trim((string) ($input['modelRequested'] ?? '')), 0, 120);
        $referrer = mb_substr(trim((string) ($input['referrer'] ?? '')), 0, 100);
        $status = (string) ($input['status'] ?? 'new');
        $date = trim((string) ($input['requestedAt'] ?? date('Y-m-d')));
        $notes = mb_substr(trim((string) ($input['notes'] ?? '')), 0, 1000);
        $allowedStatuses = ['new', 'evaluation', 'approved', 'rejected', 'deferred'];
        if ($name === '') throw new InvalidArgumentException('El nombre del solicitante es obligatorio.');
        if (!in_array($status, $allowedStatuses, true)) throw new InvalidArgumentException('El estado de la solicitud no es valido.');
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$dt || $dt->format('Y-m-d') !== $date) throw new InvalidArgumentException('La fecha de solicitud no es valida.');

        $this->pdo->beginTransaction();
        try {
            $before = [];
            if ($id > 0) {
                $stmt = $this->pdo->prepare('SELECT * FROM gp_finance_applications WHERE id = ? FOR UPDATE');
                $stmt->execute([$id]);
                $before = $stmt->fetch() ?: [];
                if (!$before) throw new InvalidArgumentException('La solicitud no existe.');
                $this->pdo->prepare(
                    'UPDATE gp_finance_applications SET applicant_name=?, identity_document=?, age=?, phone=?, model_requested=?, referrer=?, status=?, requested_at=?, notes=? WHERE id=?'
                )->execute([$name,$identity?:null,$age,$phone?:null,$model?:null,$referrer?:null,$status,$date,$notes?:null,$id]);
            } else {
                $code = 'SOL-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
                $this->pdo->prepare(
                    'INSERT INTO gp_finance_applications (application_code,applicant_name,identity_document,age,phone,model_requested,referrer,status,requested_at,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)'
                )->execute([$code,$name,$identity?:null,$age,$phone?:null,$model?:null,$referrer?:null,$status,$date,$notes?:null,(string)($actor['email']??'admin')]);
                $id = (int) $this->pdo->lastInsertId();
            }
            $stmt = $this->pdo->prepare('SELECT * FROM gp_finance_applications WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $after = $stmt->fetch() ?: [];
            AdminAuth::audit($this->pdo,$actor,'finance',$before?'update_application':'create_application','gp_finance_applications',$id,$before,$after);
            $this->pdo->commit();
            return $after;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function saveAccount(array $input, array $actor): array
    {
        $id = filter_var($input['id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $id = $id === false ? 0 : (int)$id;
        $name = mb_substr(trim((string)($input['fullName'] ?? '')), 0, 160);
        $identity = mb_substr(trim((string)($input['identityDocument'] ?? '')), 0, 40);
        $phone = mb_substr(trim((string)($input['phone'] ?? '')), 0, 40);
        $address = mb_substr(trim((string)($input['address'] ?? '')), 0, 300);
        $contractNumber = mb_substr(trim((string)($input['contractNumber'] ?? '')), 0, 80);
        $weeklyRaw = $input['weeklyAmount'] ?? null;
        $weeklyAmount = $weeklyRaw === '' || $weeklyRaw === null ? null : filter_var($weeklyRaw, FILTER_VALIDATE_FLOAT);
        $financedRaw = $input['financedAmount'] ?? null;
        $financedAmount = $financedRaw === '' || $financedRaw === null ? null : filter_var($financedRaw, FILTER_VALIDATE_FLOAT);
        $startDate = trim((string)($input['startDate'] ?? ''));
        $model = mb_substr(trim((string)($input['model'] ?? '')), 0, 120);
        $plate = mb_strtoupper(preg_replace('/\s+/u', '', mb_substr(trim((string)($input['plate'] ?? '')), 0, 40)) ?? '');
        $paid = filter_var($input['paid'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 50]]);
        $late = filter_var($input['late'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 50]]);
        $referrer = mb_substr(trim((string)($input['referrer'] ?? '')), 0, 100);
        $advanceNote = mb_substr(trim((string)($input['advanceNote'] ?? '')), 0, 80);
        $notes = mb_substr(trim((string)($input['notes'] ?? '')), 0, 1000);
        $gpsRaw = $input['gpsDeviceId'] ?? null;
        $gpsId = ($gpsRaw === '' || $gpsRaw === null) ? null : filter_var($gpsRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($gpsId === false) throw new InvalidArgumentException('El GPS Device ID no es válido.');
        $gpsId = $gpsId === null ? null : (int)$gpsId;
        $gpsLabel = mb_substr(trim((string)($input['gpsLabel'] ?? '')), 0, 120);

        if ($name === '') throw new InvalidArgumentException('El nombre del cliente es obligatorio.');
        if ($paid === false || $late === false || ((int)$paid + (int)$late) > 50) throw new InvalidArgumentException('Pagadas + mora no pueden superar las 50 cuotas.');
        if ($weeklyAmount === false || ($weeklyAmount !== null && $weeklyAmount < 0)) throw new InvalidArgumentException('El monto de la cuota no es válido.');
        if ($financedAmount === false || ($financedAmount !== null && $financedAmount < 0)) throw new InvalidArgumentException('El monto financiado no es válido.');
        if ($startDate !== '') {
            $start = DateTimeImmutable::createFromFormat('!Y-m-d', $startDate);
            if (!$start || $start->format('Y-m-d') !== $startDate) throw new InvalidArgumentException('La fecha inicial del contrato no es válida.');
        }

        // Reglas de negocio críticas: una placa/GPS solo puede pertenecer a un cliente activo.
        if ($gpsId !== null) {
            $q = $this->pdo->prepare("SELECT id,full_name FROM gp_finance_accounts WHERE gps_device_id=? AND id<>? AND record_status<>'archived' LIMIT 1");
            $q->execute([$gpsId,$id]);
            if ($other=$q->fetch()) throw new InvalidArgumentException('Ese GPS ya está asignado a '.(string)$other['full_name'].'.');
        }
        if ($plate !== '') {
            $q = $this->pdo->prepare("SELECT id,full_name FROM gp_finance_accounts WHERE UPPER(REPLACE(COALESCE(plate,''),' ',''))=? AND id<>? AND record_status<>'archived' LIMIT 1");
            $q->execute([$plate,$id]);
            if ($other=$q->fetch()) throw new InvalidArgumentException('La placa '.$plate.' ya está asignada a '.(string)$other['full_name'].'. Debes archivar y desactivar primero ese cliente para liberar la motocicleta.');
        }

        $image = $this->referenceImage($model);
        $family = $this->modelFamily($model);
        $advanceAmount = is_numeric($advanceNote) ? (float)$advanceNote : null;
        $warnings = [];
        $this->pdo->beginTransaction();
        try {
            $before=[];
            if ($id > 0) {
                $stmt=$this->pdo->prepare('SELECT * FROM gp_finance_accounts WHERE id=? FOR UPDATE');
                $stmt->execute([$id]); $before=$stmt->fetch()?:[];
                if(!$before) throw new InvalidArgumentException('El cliente financiero no existe.');
                $beforePlate=mb_strtoupper(preg_replace('/\s+/u','',trim((string)($before['plate']??'')))??'');
                $beforeGps=!empty($before['gps_device_id'])?(int)$before['gps_device_id']:null;
                if (($beforePlate!=='' && $plate!=='' && $beforePlate!==$plate) || ($beforeGps!==null && $gpsId!==null && $beforeGps!==$gpsId)) {
                    throw new InvalidArgumentException('No puedes cambiar la placa o GPS de un cliente activo. Primero archiva y desactiva al cliente para liberar su motocicleta; luego asigna esa unidad al nuevo cliente.');
                }
                $sql='UPDATE gp_finance_accounts SET full_name=?,identity_document=?,phone=?,address=?,contract_number=?,weekly_amount=?,financed_amount=?,start_date=?,model=?,model_family=?,image_path=?,plate=?,installments_paid=?,installments_late=?,advance_note=?,advance_amount=?,referrer=?,gps_device_id=?,gps_label=?,notes=? WHERE id=?';
                $this->pdo->prepare($sql)->execute([$name,$identity?:null,$phone?:null,$address?:null,$contractNumber?:null,$weeklyAmount,$financedAmount,$startDate?:null,$model?:null,$family,$image,$plate?:null,(int)$paid,(int)$late,$advanceNote?:null,$advanceAmount,$referrer?:null,$gpsId,$gpsLabel?:null,$notes?:null,$id]);
            } else {
                $sql="INSERT INTO gp_finance_accounts (full_name,identity_document,phone,address,contract_number,weekly_amount,financed_amount,start_date,model,model_family,image_path,plate,installments_paid,installments_late,advance_note,advance_amount,referrer,gps_device_id,gps_label,notes,total_installments,record_status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'active')";
                $this->pdo->prepare($sql)->execute([$name,$identity?:null,$phone?:null,$address?:null,$contractNumber?:null,$weeklyAmount,$financedAmount,$startDate?:null,$model?:null,$family,$image,$plate?:null,(int)$paid,(int)$late,$advanceNote?:null,$advanceAmount,$referrer?:null,$gpsId,$gpsLabel?:null,$notes?:null,50]);
                $id=(int)$this->pdo->lastInsertId();
                if($id<1) throw new RuntimeException('La base de datos no devolvió el ID del nuevo cliente.');
            }

            // Inventario sigue dentro de la transacción cuando la regla de negocio detecta un conflicto.
            // Un fallo técnico de estructura secundaria se registra, pero no destruye el expediente financiero.
            try {
                $inventory=new InventoryService($this->pdo);
                if($inventory->ready() && $plate!=='') $inventory->claimForFinanceAccount($id,$plate,$gpsId,$model,$actor);
            } catch (InvalidArgumentException $e) {
                throw $e;
            } catch (Throwable $e) {
                $warnings[]='Inventario pendiente de sincronización';
                error_log('[GRANDPRIX finance save] inventory sync: '.$e->getMessage());
            }

            try {
                if($referrer!=='') $this->pdo->prepare('INSERT IGNORE INTO gp_finance_referrers (display_name,source_key,sort_order) VALUES (?,?,999)')->execute([$referrer,$referrer]);
            } catch(Throwable $e) {
                $warnings[]='Referente pendiente de sincronización';
                error_log('[GRANDPRIX finance save] referrer sync: '.$e->getMessage());
            }

            $after=$this->rawAccount($id);
            try {
                AdminAuth::audit($this->pdo,$actor,'finance',$before?'update_account':'create_account','gp_finance_accounts',$id,$before,$after);
            } catch(Throwable $e) {
                $warnings[]='Auditoría secundaria pendiente';
                error_log('[GRANDPRIX finance save] audit: '.$e->getMessage());
            }

            try {
                $receiptService=new PaymentReceiptService($this->pdo);
                if($receiptService->ready()) {
                    $receiptService->syncFromAggregate($id,(int)$paid,(int)$late);
                    if($weeklyAmount!==null && (float)$weeklyAmount>0) $receiptService->syncWeeklyAmount($id,(float)$weeklyAmount,false);
                }
            } catch(Throwable $e) {
                $warnings[]='Cronograma pendiente de sincronización';
                error_log('[GRANDPRIX finance save] schedule sync: '.$e->getMessage());
            }

            $this->pdo->commit();
            $result=$this->account($id)??['id'=>$id,'fullName'=>$name];
            if($warnings) $result['_saveWarnings']=array_values(array_unique($warnings));
            return $result;
        } catch(Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function archiveAccount(int $id, array $actor): void
    {
        $this->pdo->beginTransaction();
        try {
            $before=$this->rawAccount($id);
            if(!$before) throw new InvalidArgumentException('El cliente financiero no existe.');
            $reason='Cliente archivado y desactivado desde Clientes y créditos. Motocicleta liberada para reasignación.';
            $this->pdo->prepare("UPDATE gp_finance_accounts SET record_status='archived',notes=CONCAT(COALESCE(notes,''),CASE WHEN COALESCE(notes,'')='' THEN '' ELSE '\n' END,?) WHERE id=?")->execute([$reason,$id]);
            $inventory=new InventoryService($this->pdo);if($inventory->ready())$inventory->releaseFinanceAccount($id,$reason,$actor);
            AdminAuth::audit($this->pdo,$actor,'finance','archive_account','gp_finance_accounts',$id,$before,['record_status'=>'archived','assignment_released'=>true]);
            $this->pdo->commit();
        } catch(Throwable $error){ if($this->pdo->inTransaction())$this->pdo->rollBack(); throw $error; }
    }

    public function recordPayment(array $input, array $actor): array
    {
        $accountId=filter_var($input['accountId']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $amount=filter_var($input['amount']??null,FILTER_VALIDATE_FLOAT);
        $currency=mb_strtoupper(trim((string)($input['currency']??'USD')));
        $rateRaw=$input['exchangeRate']??null;
        $exchangeRate=($rateRaw===''||$rateRaw===null)?null:filter_var($rateRaw,FILTER_VALIDATE_FLOAT);
        $method=mb_substr(trim((string)($input['paymentMethod']??'')),0,80);
        $bank=mb_substr(trim((string)($input['bank']??'')),0,100);
        $reference=mb_substr(trim((string)($input['reference']??'')),0,100);
        $date=trim((string)($input['paidAt']??date('Y-m-d')));
        $notes=mb_substr(trim((string)($input['notes']??'')),0,500);
        $needsReview=!empty($input['needsReview']);
        if($accountId===false)throw new InvalidArgumentException('Selecciona un cliente válido.');
        if($amount===false||$amount<=0)throw new InvalidArgumentException('El monto del pago debe ser mayor que cero.');
        if(!in_array($currency,['USD','BS'],true))throw new InvalidArgumentException('La moneda del pago no es válida.');
        if($currency==='BS' && ($exchangeRate===false||$exchangeRate===null||$exchangeRate<=0))throw new InvalidArgumentException('Indica la tasa Bs./USD utilizada para conciliar el pago.');
        $amountUsd=$currency==='BS'?round((float)$amount/(float)$exchangeRate,2):round((float)$amount,2);
        if($amountUsd<=0)throw new InvalidArgumentException('El equivalente USD del pago no es válido.');
        $dt=DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$dt||$dt->format('Y-m-d')!==$date)throw new InvalidArgumentException('La fecha del pago no es válida.');
        $receiptService=new PaymentReceiptService($this->pdo);
        if(!$receiptService->ready())throw new RuntimeException('Ejecuta la actualización V28 antes de registrar nuevos pagos.');
        $this->pdo->beginTransaction();
        try{
            $stmt=$this->pdo->prepare('SELECT * FROM gp_finance_accounts WHERE id=? FOR UPDATE');$stmt->execute([(int)$accountId]);$account=$stmt->fetch();
            if(!$account)throw new InvalidArgumentException('El cliente financiero no existe.');
            if((float)($account['weekly_amount']??0)<=0)throw new InvalidArgumentException('Este cliente no tiene cuota semanal configurada. Edita Clientes y créditos antes de registrar el pago.');
            $receiptService->syncWeeklyAmount((int)$accountId,(float)$account['weekly_amount'],false);
            $status=$needsReview?'review':'confirmed';
            $this->pdo->prepare("INSERT INTO gp_finance_payments
                (account_id,paid_at,amount,currency,exchange_rate,amount_usd,applied_usd,unapplied_usd,payment_method,bank,reference_number,installments_applied,late_reduced,notes,status,created_by,week_numbers_json)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,0,0,?,?,?,'[]')")
                ->execute([(int)$accountId,$date,(float)$amount,$currency,$currency==='BS'?(float)$exchangeRate:null,$amountUsd,0,$amountUsd,$method?:null,$bank?:null,$reference?:null,$notes?:null,$status,(string)($actor['email']??'admin')]);
            $paymentId=(int)$this->pdo->lastInsertId();$receipt=null;$applied=null;$weeks=[];
            if(!$needsReview){
                $applied=$receiptService->applyConfirmedPayment($paymentId,(int)$accountId,$date,[],$actor);$receipt=$applied['receipt']??null;$weeks=$applied['touchedWeeks']??[];
                $this->notifyAccountPayment((int)$accountId,'approved',$receipt,$weeks);
            }else{$this->notifyAccountPayment((int)$accountId,'review',null,[]);}
            AdminAuth::audit($this->pdo,$actor,'finance',$needsReview?'submit_payment_review':'record_payment','gp_finance_payments',$paymentId,[],[
                'account_id'=>(int)$accountId,'status'=>$status,'amount_original'=>(float)$amount,'currency'=>$currency,'exchange_rate'=>$currency==='BS'?(float)$exchangeRate:null,'amount_usd'=>$amountUsd,
                'allocations'=>$applied['allocations']??[],'receipt_id'=>$receipt['id']??null
            ]);
            $this->pdo->commit();$this->flushWhatsApp();
            return ['id'=>$paymentId,'status'=>$status,'receipt'=>$receipt,'distribution'=>$applied,'account'=>$this->account((int)$accountId)];
        }catch(Throwable $error){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $error;}
    }

    public function reconcilePayment(int $paymentId, string $decision, array $actor): array
    {
        if(!in_array($decision,['approved','rejected'],true))throw new InvalidArgumentException('Decisión de conciliación inválida.');
        $receiptService=new PaymentReceiptService($this->pdo);if(!$receiptService->ready())throw new RuntimeException('Ejecuta la actualización V28 antes de conciliar pagos.');
        $this->pdo->beginTransaction();
        try{
            $stmt=$this->pdo->prepare('SELECT * FROM gp_finance_payments WHERE id=? FOR UPDATE');$stmt->execute([$paymentId]);$payment=$stmt->fetch();
            if(!$payment)throw new InvalidArgumentException('El movimiento no existe.');
            if((string)$payment['status']!=='review')throw new InvalidArgumentException('Este movimiento ya fue procesado.');
            $accountId=(int)$payment['account_id'];$receipt=null;$distribution=null;$newStatus='rejected';$portalReportId=(int)($payment['portal_report_id']??0);
            if($decision==='approved'){
                if($portalReportId>0)$this->pdo->prepare("UPDATE gp_payment_reports SET status='approved',reviewed_by=?,reviewed_at=NOW() WHERE id=?")->execute([(string)($actor['email']??'admin'),$portalReportId]);
                $this->pdo->prepare("UPDATE gp_finance_payments SET status='confirmed' WHERE id=?")->execute([$paymentId]);
                $distribution=$receiptService->applyConfirmedPayment($paymentId,$accountId,(string)$payment['paid_at'],[],$actor);$receipt=$distribution['receipt']??null;$newStatus='confirmed';
                $this->notifyAccountPayment($accountId,'approved',$receipt,$distribution['touchedWeeks']??[]);
            }else{
                $this->pdo->prepare("UPDATE gp_finance_payments SET status='rejected' WHERE id=?")->execute([$paymentId]);
                if($portalReportId>0){
                    $this->pdo->prepare("UPDATE gp_payment_reports SET status='rejected',reviewed_by=?,reviewed_at=NOW() WHERE id=?")->execute([(string)($actor['email']??'admin'),$portalReportId]);
                    $q=$this->pdo->prepare('SELECT contract_id,week_number FROM gp_payment_reports WHERE id=? LIMIT 1');$q->execute([$portalReportId]);$pr=$q->fetch();
                    if($pr)$this->pdo->prepare("UPDATE gp_contract_weeks SET status=CASE WHEN due_date<CURRENT_DATE THEN 'late' ELSE 'pending' END,payment_report_id=NULL WHERE contract_id=? AND week_number=? AND status='reported'")->execute([(int)$pr['contract_id'],(int)$pr['week_number']]);
                }
                $this->notifyAccountPayment($accountId,'rejected',null,[]);
            }
            AdminAuth::audit($this->pdo,$actor,'finance',$decision==='approved'?'approve_payment':'reject_payment','gp_finance_payments',$paymentId,$payment,['status'=>$newStatus,'distribution'=>$distribution,'receipt_id'=>$receipt['id']??null]);
            $this->pdo->commit();$this->flushWhatsApp();
            return ['id'=>$paymentId,'status'=>$newStatus,'receipt'=>$receipt,'distribution'=>$distribution,'account'=>$this->account($accountId)];
        }catch(Throwable $error){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $error;}
    }

    public function paymentAnalytics(?string $from=null, ?string $to=null, string $preset='month', bool $includeRows=true): array
    {
        $timezone=new DateTimeZone('America/Caracas');
        $now=new DateTimeImmutable('now',$timezone);
        $preset=mb_strtolower(trim($preset));
        if(!in_array($preset,['day','week','month','custom'],true))$preset='month';
        if($preset==='day'){$start=$now->format('Y-m-d');$end=$start;$label='Hoy';}
        elseif($preset==='week'){$monday=$now->modify('monday this week');$start=$monday->format('Y-m-d');$end=$monday->modify('+6 days')->format('Y-m-d');$label='Esta semana';}
        elseif($preset==='custom'){
            $valid=static function(?string $value):?string{if(!$value)return null;$d=DateTimeImmutable::createFromFormat('!Y-m-d',$value);return $d&&$d->format('Y-m-d')===$value?$value:null;};
            $start=$valid($from);$end=$valid($to);if(!$start||!$end)throw new InvalidArgumentException('Indica un rango de fechas válido.');if($start>$end)throw new InvalidArgumentException('La fecha inicial no puede ser posterior a la final.');$label='Rango personalizado';
        }else{$start=$now->modify('first day of this month')->format('Y-m-d');$end=$now->modify('last day of this month')->format('Y-m-d');$label='Este mes';$preset='month';}

        $stmt=$this->pdo->prepare("SELECT
            SUM(CASE WHEN p.status<>'rejected' THEN 1 ELSE 0 END) reported_payments,
            SUM(CASE WHEN p.status='confirmed' THEN 1 ELSE 0 END) confirmed_payments,
            SUM(CASE WHEN p.status='review' THEN 1 ELSE 0 END) review_payments,
            SUM(CASE WHEN p.status<>'rejected' AND UPPER(COALESCE(p.currency,'USD'))='USD' THEN COALESCE(p.amount,0) ELSE 0 END) reported_usd,
            SUM(CASE WHEN p.status<>'rejected' AND UPPER(COALESCE(p.currency,'USD'))='BS' THEN COALESCE(p.amount,0) ELSE 0 END) reported_bs,
            SUM(CASE WHEN p.status='confirmed' AND UPPER(COALESCE(p.currency,'USD'))='USD' THEN COALESCE(p.amount,0) ELSE 0 END) confirmed_usd,
            SUM(CASE WHEN p.status='confirmed' AND UPPER(COALESCE(p.currency,'USD'))='BS' THEN COALESCE(p.amount,0) ELSE 0 END) confirmed_bs,
            SUM(CASE WHEN p.status<>'rejected' THEN COALESCE(p.amount_usd,CASE WHEN UPPER(COALESCE(p.currency,'USD'))='USD' THEN p.amount ELSE 0 END,0) ELSE 0 END) reported_equivalent_usd
            FROM gp_finance_payments p WHERE p.paid_at BETWEEN ? AND ?");
        $stmt->execute([$start,$end]);$m=$stmt->fetch()?:[];
        $completed=0;$partial=0;$touched=0;
        try{
            $a=$this->pdo->prepare("SELECT SUM(CASE WHEN a.completed=1 THEN 1 ELSE 0 END) completed_installments,SUM(CASE WHEN a.completed=0 AND a.allocated_amount>0 THEN 1 ELSE 0 END) partial_allocations,COUNT(*) touched_allocations FROM gp_finance_payment_allocations a INNER JOIN gp_finance_payments p ON p.id=a.payment_id WHERE p.status='confirmed' AND p.paid_at BETWEEN ? AND ?");
            $a->execute([$start,$end]);$am=$a->fetch()?:[];$completed=(int)($am['completed_installments']??0);$partial=(int)($am['partial_allocations']??0);$touched=(int)($am['touched_allocations']??0);
        }catch(Throwable){
            $a=$this->pdo->prepare("SELECT SUM(CASE WHEN status='confirmed' THEN COALESCE(installments_applied,0) ELSE 0 END) completed_installments FROM gp_finance_payments WHERE paid_at BETWEEN ? AND ?");$a->execute([$start,$end]);$completed=(int)($a->fetchColumn()?:0);
        }
        $reportedUsd=round((float)($m['reported_usd']??0),2);$reportedBs=round((float)($m['reported_bs']??0),2);
        $result=[
            'preset'=>$preset,'from'=>$start,'to'=>$end,'label'=>$label,'generatedAt'=>$now->format('Y-m-d H:i:s'),'timezone'=>'America/Caracas',
            'metrics'=>[
                'reportedPayments'=>(int)($m['reported_payments']??0),'confirmedPayments'=>(int)($m['confirmed_payments']??0),'reviewPayments'=>(int)($m['review_payments']??0),
                'completedInstallments'=>$completed,'partialAllocations'=>$partial,'touchedAllocations'=>$touched,
                'reportedUsd'=>$reportedUsd,'reportedBs'=>$reportedBs,'confirmedUsd'=>round((float)($m['confirmed_usd']??0),2),'confirmedBs'=>round((float)($m['confirmed_bs']??0),2),
                'reportedEquivalentUsd'=>round((float)($m['reported_equivalent_usd']??0),2),'tenPercentUsd'=>round($reportedUsd*.10,2),'tenPercentBs'=>round($reportedBs*.10,2),
            ],
            'payments'=>[],'daily'=>[]
        ];
        if(!$includeRows)return $result;
        try{
            $sql="SELECT p.id,p.account_id,p.paid_at,p.amount,p.currency,p.exchange_rate,p.amount_usd,p.applied_usd,p.unapplied_usd,p.payment_method,p.bank,p.reference_number,p.installments_applied,p.late_reduced,p.notes,p.status,p.created_by,p.created_at,p.receipt_id,p.week_numbers_json,r.receipt_number,a.full_name,a.plate,a.model,a.contract_number,COALESCE(x.completed_installments,0) completed_installments,COALESCE(x.partial_allocations,0) partial_allocations,COALESCE(x.touched_allocations,0) touched_allocations FROM gp_finance_payments p INNER JOIN gp_finance_accounts a ON a.id=p.account_id LEFT JOIN gp_finance_receipts r ON r.id=p.receipt_id LEFT JOIN (SELECT payment_id,SUM(completed=1) completed_installments,SUM(completed=0 AND allocated_amount>0) partial_allocations,COUNT(*) touched_allocations FROM gp_finance_payment_allocations GROUP BY payment_id) x ON x.payment_id=p.id WHERE p.paid_at BETWEEN ? AND ? ORDER BY p.paid_at DESC,p.id DESC LIMIT 500";
            $q=$this->pdo->prepare($sql);$q->execute([$start,$end]);$result['payments']=$q->fetchAll();
        }catch(Throwable){
            $q=$this->pdo->prepare("SELECT p.*,a.full_name,a.plate,a.model,a.contract_number,COALESCE(p.installments_applied,0) completed_installments,0 partial_allocations,COALESCE(p.installments_applied,0) touched_allocations FROM gp_finance_payments p INNER JOIN gp_finance_accounts a ON a.id=p.account_id WHERE p.paid_at BETWEEN ? AND ? ORDER BY p.paid_at DESC,p.id DESC LIMIT 500");$q->execute([$start,$end]);$result['payments']=$q->fetchAll();
        }
        $q=$this->pdo->prepare("SELECT paid_at day,SUM(CASE WHEN status<>'rejected' THEN 1 ELSE 0 END) reported_payments,SUM(CASE WHEN status<>'rejected' AND UPPER(COALESCE(currency,'USD'))='USD' THEN amount ELSE 0 END) usd,SUM(CASE WHEN status<>'rejected' AND UPPER(COALESCE(currency,'USD'))='BS' THEN amount ELSE 0 END) bs FROM gp_finance_payments WHERE paid_at BETWEEN ? AND ? GROUP BY paid_at ORDER BY paid_at");$q->execute([$start,$end]);$result['daily']=$q->fetchAll();
        return $result;
    }

    public function audit(int $limit=250): array
    {
        $limit=max(1,min(500,$limit));
        return $this->pdo->query(
            'SELECT id, user_email, module_key, action_key, entity_type, entity_id, summary, created_at FROM gp_admin_audit ORDER BY id DESC LIMIT '.(int)$limit
        )->fetchAll();
    }

    public function recentPayments(int $limit=80): array
    {
        $limit=max(1,min(200,$limit));
        $receiptService=new PaymentReceiptService($this->pdo);
        if(!$receiptService->ready()){
            return $this->pdo->query("SELECT p.id,p.account_id,p.paid_at,p.amount,NULL AS payment_method,p.bank,p.reference_number,p.installments_applied,p.late_reduced,p.notes,p.status,p.created_by,p.created_at,NULL AS receipt_id,NULL AS week_numbers_json,NULL AS receipt_number,a.full_name,a.plate,a.model,a.contract_number FROM gp_finance_payments p INNER JOIN gp_finance_accounts a ON a.id=p.account_id ORDER BY p.id DESC LIMIT ".(int)$limit)->fetchAll();
        }
        return $this->pdo->query(
            "SELECT p.id,p.account_id,p.paid_at,p.amount,p.currency,p.exchange_rate,p.amount_usd,p.applied_usd,p.unapplied_usd,p.payment_method,p.bank,p.reference_number,p.installments_applied,p.late_reduced,p.notes,p.status,p.created_by,p.created_at,p.receipt_id,p.week_numbers_json,
                    r.receipt_number,a.full_name,a.plate,a.model,a.contract_number,COALESCE(x.completed_installments,0) completed_installments,COALESCE(x.partial_allocations,0) partial_allocations
             FROM gp_finance_payments p
             INNER JOIN gp_finance_accounts a ON a.id=p.account_id
             LEFT JOIN gp_finance_receipts r ON r.id=p.receipt_id
             LEFT JOIN (SELECT payment_id,SUM(completed=1) completed_installments,SUM(completed=0 AND allocated_amount>0) partial_allocations FROM gp_finance_payment_allocations GROUP BY payment_id) x ON x.payment_id=p.id
             ORDER BY p.id DESC LIMIT ".(int)$limit
        )->fetchAll();
    }

    public function gpsDevices(): array
    {
        $path=dirname(__DIR__).'/config/runtime/telemetry.json';
        if(!is_file($path)) return [];
        $raw=@file_get_contents($path);$data=is_string($raw)?json_decode($raw,true):null;if(!is_array($data))return [];
        $devices=(array)($data['devices']??[]);$result=[];
        foreach($devices as $device){if(!is_array($device)|| (int)($device['id']??0)<1)continue;$result[]=[
            'id'=>(int)$device['id'],'name'=>(string)($device['name']??('GPS '.(int)$device['id'])),'uniqueId'=>(string)($device['uniqueId']??''),'status'=>(string)($device['status']??'unknown')
        ];}
        usort($result,static fn($a,$b)=>$a['id']<=>$b['id']);return $result;
    }

    private function notifyAccountPayment(int $accountId,string $state,?array $receipt,array $weeks): void
    {
        try{
            $q=$this->pdo->prepare('SELECT contract_number FROM gp_finance_accounts WHERE id=? LIMIT 1');$q->execute([$accountId]);$contract=(string)($q->fetchColumn()?:'');if($contract==='')return;
            $notifications=new CustomerNotificationService($this->pdo);$customerId=$notifications->customerIdForContract($contract);if($customerId<1)return;
            if($state==='approved'){$number=(string)($receipt['receiptNumber']??'');$label=$weeks?'Semana(s) '.implode(', ',$weeks):'tu pago';$notifications->create($customerId,'payment_approved','Pago conciliado',$label.' fue conciliado correctamente.'.($number!==''?' Tu recibo '.$number.' ya está disponible en PDF.':''),'gp_finance_receipts',isset($receipt['id'])?(int)$receipt['id']:null);}
            elseif($state==='review'){$notifications->create($customerId,'payment_review','Pago recibido para conciliación','GRANDPRIX recibió el reporte de pago y está pendiente de conciliación.','gp_finance_payments',null);}
            else{$notifications->create($customerId,'payment_rejected','Pago observado','El pago fue observado/rechazado durante la conciliación. Revisa el detalle o contacta a GRANDPRIX.','gp_finance_payments',null);}
        }catch(Throwable){}
    }

    private function rawAccount(int $id): array
    {
        $stmt=$this->pdo->prepare('SELECT * FROM gp_finance_accounts WHERE id=? LIMIT 1');$stmt->execute([$id]);return $stmt->fetch()?:[];
    }
    private function modelFamily(string $model): string
    {
        $up=mb_strtoupper(trim($model)); if($up==='')return 'Sin modelo';
        if(str_contains($up,'LEON'))return 'Bera León';if(str_contains($up,'SBR'))return 'Bera SBR';if(str_contains($up,'BRF'))return 'Bera BRF';if(str_contains($up,'X1'))return 'Bera X1';if(str_contains($up,'VELOZ'))return 'Veloz';if(str_contains($up,'SOCIALISTA'))return 'Socialista';if(str_contains($up,'AGUILA'))return 'MD Águila';return $model;
    }
    private function referenceImage(string $model): string
    {
        $up=mb_strtoupper(trim($model));if(str_contains($up,'BRF')||str_contains($up,'SOCIALISTA'))return 'assets/moto-red.png';if(str_contains($up,'LEON')||str_contains($up,'KADI')||str_contains($up,'LOVIS')||str_contains($up,'AGUILA'))return 'assets/moto-black.png';return 'assets/moto-blue.png';
    }

    /**
     * Envía los envíos de WhatsApp encolados (recibos) justo después del
     * commit. No bloquea la operación principal si FlowBot no responde: la
     * fila queda pendiente y el cron la reintenta.
     */
    private function flushWhatsApp(): void
    {
        try {
            require_once __DIR__ . '/WhatsAppOutbox.php';
            (new WhatsAppOutbox($this->pdo))->processPending();
        } catch (Throwable $error) {
            error_log('[GRANDPRIX whatsapp flush] '.$error->getMessage());
        }
    }
}
