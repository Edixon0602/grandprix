<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/Database.php';
require_once dirname(__DIR__) . '/lib/CustomerPortal.php';
require_once dirname(__DIR__) . '/lib/TelemetryStore.php';
require_once dirname(__DIR__) . '/lib/EventAudit.php';

gp_start_session();
gp_require_admin(true);
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
if (!Database::configured()) gp_json(['ok' => false, 'error' => 'Ejecuta la actualizacion V7.2 para preparar el portal.'], 503);
$action = strtolower(trim((string) ($_GET['action'] ?? 'overview')));
$portal = new CustomerPortal();
$actor=gp_current_admin();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'overview') {
        $snapshot = (new TelemetryStore())->snapshot();
        $devices = [];
        foreach ((array) ($snapshot['devices'] ?? []) as $device) {
            if (!is_array($device) || (int) ($device['id'] ?? 0) < 1) continue;
            $devices[] = [
                'id' => (int) $device['id'],
                'name' => (string) ($device['name'] ?? ('GPS ' . (int) $device['id'])),
                'uniqueId' => (string) ($device['uniqueId'] ?? ''),
                'status' => (string) ($device['status'] ?? 'unknown'),
            ];
        }
        gp_json(['ok' => true, 'version' => gp_release(), 'polling' => false, 'devices' => $devices] + $portal->adminOverview());
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') gp_json(['ok' => false, 'error' => 'Metodo no permitido.'], 405);
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if (!gp_verify_csrf(is_string($csrf) ? $csrf : null)) gp_json(['ok' => false, 'error' => 'Sesion de seguridad vencida.'], 419);
    $input = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($input)) gp_json(['ok' => false, 'error' => 'Solicitud invalida.'], 400);

    if ($action === 'save-customer') {
        $result = saveCustomerAccount(Database::connection(), $input, $actor);
        EventAudit::recordAdmin($actor,'portal','save_customer_portal','update','gp_customers',(int)($result['id']??0),'Creó o actualizó el acceso de un cliente a Mi GRANDPRIX.');
        gp_json(['ok' => true, 'message' => !empty($input['id']) ? 'Acceso Mi GRANDPRIX actualizado correctamente.' : 'Usuario Mi GRANDPRIX creado y activado con su moto y GPS asignados.', 'customer' => $result]);
    }
    if ($action === 'review-payment') {
        $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) throw new InvalidArgumentException('Reporte de pago invalido.');
        $decision=(string)($input['decision']??'');
        $result = $portal->reviewPayment((int) $id, $decision, (string) ($_SESSION['grandprix_admin_email'] ?? 'admin'));
        EventAudit::recordAdmin($actor,'portal',$decision==='approved'?'approve_customer_payment':'reject_customer_payment','workflow','gp_payment_reports',(int)$id,$decision==='approved'?'Conciliación de pago reportado por cliente.':'Rechazo de pago reportado por cliente.');
        gp_json(['ok' => true, 'message' => $result['status'] === 'approved' ? 'Pago conciliado y semana marcada como pagada.' : 'Reporte rechazado.', 'payment' => $result]);
    }
    if ($action === 'preview-customer') {
        $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false || !$portal->previewCustomer((int) $id)) throw new InvalidArgumentException('Cliente de vista previa invalido.');
        $_SESSION['grandprix_preview_customer_id'] = (int) $id;
        EventAudit::recordAdmin($actor,'portal','preview_customer_portal','view','gp_customers',(int)$id,'Abrió vista previa del portal de un cliente.');
        gp_json(['ok' => true, 'message' => 'Vista previa preparada.']);
    }
    gp_json(['ok' => false, 'error' => 'Accion administrativa no reconocida.'], 404);
} catch (InvalidArgumentException $error) {
    gp_json(['ok' => false, 'error' => $error->getMessage()], 422);
} catch (PDOException $error) {
    gp_json(['ok' => false, 'error' => (string) $error->getCode() === '23000' ? 'La cedula, correo, moto o GPS ya estan registrados.' : 'No fue posible guardar la informacion.'], 409);
} catch (Throwable) {
    gp_json(['ok' => false, 'error' => 'No fue posible completar la operacion administrativa.'], 500);
}

function saveCustomerAccount(PDO $pdo, array $input, array $actor): array
{
    $customerId = filter_var($input['id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    $customerId = $customerId === false ? 0 : (int)$customerId;
    $financeRaw=$input['financeAccountId']??null;
    $financeAccountId=($financeRaw===''||$financeRaw===null)?0:(int)$financeRaw;
    $key=strtolower(trim((string)($input['key']??'')));
    $password=(string)($input['password']??'');
    $email=mb_strtolower(trim((string)($input['email']??'')));
    $active=!array_key_exists('active',$input)||!empty($input['active']);

    if(!preg_match('/^[a-z0-9-]{3,80}$/',$key))throw new InvalidArgumentException('El usuario debe tener entre 3 y 80 caracteres: letras minúsculas, números o guiones.');
    if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('El correo no es válido.');
    if($customerId===0&&strlen($password)<8)throw new InvalidArgumentException('La clave inicial debe tener al menos 8 caracteres.');
    if($password!==''&&strlen($password)<8)throw new InvalidArgumentException('La clave debe tener al menos 8 caracteres.');

    if($customerId>0){
        $q=$pdo->prepare('SELECT id,finance_account_id FROM gp_customers WHERE id=? LIMIT 1');$q->execute([$customerId]);$current=$q->fetch();
        if(!$current)throw new InvalidArgumentException('La cuenta Mi GRANDPRIX no existe.');
        $linked=(int)($current['finance_account_id']??0);if($linked>0)$financeAccountId=$linked;
    }
    if($financeAccountId<1)throw new InvalidArgumentException('Selecciona primero un cliente existente de Clientes y créditos.');

    $q=$pdo->prepare("SELECT fa.id,fa.full_name,fa.identity_document,fa.phone,fa.address,fa.contract_number,fa.start_date,fa.weekly_amount,fa.financed_amount,fa.installments_paid,fa.installments_late,fa.record_status,
            i.id inventory_id,i.inventory_code,COALESCE(i.plate,fa.plate) AS plate,i.brand,COALESCE(NULLIF(i.model,''),fa.model) AS model,
            i.engine_cc,i.color,i.model_year,i.chassis_serial,i.engine_serial,COALESCE(i.gps_device_id,fa.gps_device_id) AS gps_device_id,
            i.gps_unique_id,COALESCE(NULLIF(i.gps_label,''),fa.gps_label) AS gps_label,i.vehicle_id,i.current_customer_id,i.current_finance_account_id AS inventory_finance_account_id
        FROM gp_finance_accounts fa
        LEFT JOIN gp_motorcycle_inventory i ON i.id=(
            SELECT ix.id FROM gp_motorcycle_inventory ix
            WHERE ix.status<>'archived' AND (
                ix.current_finance_account_id=fa.id
                OR (ix.current_finance_account_id IS NULL AND fa.plate IS NOT NULL AND TRIM(fa.plate)<>'' AND UPPER(REPLACE(ix.plate,' ',''))=UPPER(REPLACE(fa.plate,' ','')))
                OR (ix.current_finance_account_id IS NULL AND fa.gps_device_id IS NOT NULL AND ix.gps_device_id=fa.gps_device_id)
            )
            ORDER BY (ix.current_finance_account_id=fa.id) DESC,
                     (UPPER(REPLACE(ix.plate,' ',''))=UPPER(REPLACE(COALESCE(fa.plate,''),' ',''))) DESC,
                     ix.id DESC LIMIT 1
        )
        WHERE fa.id=? LIMIT 1");
    $q->execute([$financeAccountId]);$finance=$q->fetch();
    if(!$finance||($finance['record_status']??'active')==='archived')throw new InvalidArgumentException('El cliente financiero ya no está activo.');
    $name=trim((string)($finance['full_name']??''));
    $identity=mb_strtoupper((string)preg_replace('/[^A-Za-z0-9-]/','',(string)($finance['identity_document']??'')));
    $phone=preg_replace('/\D+/','',(string)($finance['phone']??''))?:'';
    $contractNumber=trim((string)($finance['contract_number']??''));
    $startDate=trim((string)($finance['start_date']??''));
    $weekly=$finance['weekly_amount']===null?null:(float)$finance['weekly_amount'];
    $financed=$finance['financed_amount']===null?null:(float)$finance['financed_amount'];
    $paidWeeks=max(0,min(50,(int)($finance['installments_paid']??0)));
    $lateWeeks=max(0,min(50-$paidWeeks,(int)($finance['installments_late']??0)));
    $inventoryId=(int)($finance['inventory_id']??0);
    $plate=trim((string)($finance['plate']??''));
    $brand=trim((string)($finance['brand']??''));
    $model=trim((string)($finance['model']??''));
    $deviceId=(int)($finance['gps_device_id']??0);
    $gpsUnique=trim((string)($finance['gps_unique_id']??''));
    $gpsLabel=trim((string)($finance['gps_label']??''));
    $vehicleCode=trim((string)($finance['inventory_code']??''));

    if($name===''||strlen($identity)<5)throw new InvalidArgumentException('Completa nombre y cédula en Clientes y créditos antes de crear el usuario.');
    if($inventoryId<1||$plate===''||$model==='')throw new InvalidArgumentException('Este cliente todavía no tiene una motocicleta asignada desde Inventario. Asígnala primero en Clientes y créditos / Expediente 360.');
    if($deviceId<1)throw new InvalidArgumentException('La motocicleta '.$plate.' todavía no tiene GPS asignado en Inventario. Asigna un GPS de Traccar antes de activar Mi GRANDPRIX.');
    if($contractNumber===''||$weekly===null||$weekly<=0||$startDate==='')throw new InvalidArgumentException('Completa contrato, fecha inicial y cuota semanal en Clientes y créditos antes de crear el usuario.');
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$startDate);if(!$date||$date->format('Y-m-d')!==$startDate)throw new InvalidArgumentException('La fecha inicial del contrato no es válida.');
    if($financed===null||$financed<=0)$financed=round($weekly*50,2);
    if($vehicleCode==='')$vehicleCode='INV-'.str_pad((string)$inventoryId,5,'0',STR_PAD_LEFT);

    $other=$pdo->prepare('SELECT id,full_name FROM gp_customers WHERE finance_account_id=? AND id<>? AND status<>\'archived\' LIMIT 1');$other->execute([$financeAccountId,$customerId]);if($dup=$other->fetch())throw new InvalidArgumentException('Este cliente ya tiene un usuario Mi GRANDPRIX: '.(string)$dup['full_name'].'.');
    $other=$pdo->prepare('SELECT id,full_name FROM gp_customers WHERE public_key=? AND id<>? AND status<>\'archived\' LIMIT 1');$other->execute([$key,$customerId]);if($other->fetch())throw new InvalidArgumentException('Ese usuario de acceso ya está utilizado por otro cliente.');

    $pdo->beginTransaction();
    try{
        // Consolida la relación física Inventario -> cliente financiero antes de crear Mi GRANDPRIX.
        // Esto repara expedientes donde placa/modelo/GPS ya estaban en Clientes y créditos,
        // pero la unidad seguía marcada como Disponible en Inventario.
        if($inventoryId>0){
            gp_customer_assign_inventory($pdo,$financeAccountId,$inventoryId,$actor);
        }
        if($customerId>0){
            $sql='UPDATE gp_customers SET finance_account_id=?,public_key=?,full_name=?,identity_document=?,email=?,phone=?,status=?';
            $values=[$financeAccountId,$key,$name,$identity,$email?:null,$phone?:null,$active?'active':'suspended'];
            if($password!==''){$sql.=',password_hash=?';$values[]=password_hash($password,PASSWORD_DEFAULT);}
            $sql.=' WHERE id=?';$values[]=$customerId;$pdo->prepare($sql)->execute($values);
        }else{
            $pdo->prepare("INSERT INTO gp_customers (finance_account_id,public_key,full_name,identity_document,email,phone,password_hash,status) VALUES (?,?,?,?,?,?,?,'active')")
                ->execute([$financeAccountId,$key,$name,$identity,$email?:null,$phone?:null,password_hash($password,PASSWORD_DEFAULT)]);
            $customerId=(int)$pdo->lastInsertId();
        }

        $vehicleId=(int)($finance['vehicle_id']??0);
        if($vehicleId<1){
            $vq=$pdo->prepare('SELECT id FROM gp_vehicles WHERE plate=? OR traccar_device_id=? LIMIT 1');$vq->execute([$plate,$deviceId]);$vehicleId=(int)($vq->fetchColumn()?:0);
        }
        $image=portalInventoryImagePath($brand,$model);
        $vehicleColumns=[];try{$vehicleColumns=$pdo->query('SHOW COLUMNS FROM gp_vehicles')->fetchAll(PDO::FETCH_COLUMN);}catch(Throwable){}
        $hasColor=in_array('color',$vehicleColumns,true);$hasImage=in_array('image_path',$vehicleColumns,true);$hasUnique=in_array('traccar_unique_id',$vehicleColumns,true);
        if($vehicleId>0){
            $sets=['code=?','plate=?','model=?','traccar_device_id=?','status=?'];$vals=[$vehicleCode,$plate,trim($brand.' '.$model),$deviceId,'active'];
            if($hasColor){$sets[]='color=?';$vals[]=$finance['color']?:null;}if($hasImage){$sets[]='image_path=?';$vals[]=$image;}if($hasUnique){$sets[]='traccar_unique_id=?';$vals[]=$gpsUnique?:null;}
            $vals[]=$vehicleId;$pdo->prepare('UPDATE gp_vehicles SET '.implode(',',$sets).' WHERE id=?')->execute($vals);
        }else{
            $cols=['code','plate','model','traccar_device_id','status'];$marks=['?','?','?','?','?'];$vals=[$vehicleCode,$plate,trim($brand.' '.$model),$deviceId,'active'];
            if($hasColor){$cols[]='color';$marks[]='?';$vals[]=$finance['color']?:null;}if($hasImage){$cols[]='image_path';$marks[]='?';$vals[]=$image;}if($hasUnique){$cols[]='traccar_unique_id';$marks[]='?';$vals[]=$gpsUnique?:null;}
            $pdo->prepare('INSERT INTO gp_vehicles ('.implode(',',$cols).') VALUES ('.implode(',',$marks).')')->execute($vals);$vehicleId=(int)$pdo->lastInsertId();
        }

        $cq=$pdo->prepare("SELECT id FROM gp_contracts WHERE customer_id=? AND status IN ('active','completed') ORDER BY id DESC LIMIT 1");$cq->execute([$customerId]);$contractId=(int)($cq->fetchColumn()?:0);
        if($contractId>0){
            $pdo->prepare('UPDATE gp_contracts SET contract_number=?,vehicle_id=?,total_weeks=50,weekly_amount=?,financed_amount=?,start_date=?,status=? WHERE id=?')
                ->execute([$contractNumber,$vehicleId,$weekly,$financed,$startDate,$paidWeeks>=50?'completed':'active',$contractId]);
        }else{
            $pdo->prepare("INSERT INTO gp_contracts (contract_number,customer_id,vehicle_id,total_weeks,weekly_amount,financed_amount,start_date,status) VALUES (?,?,?,50,?,?,?,?)")
                ->execute([$contractNumber,$customerId,$vehicleId,$weekly,$financed,$startDate,$paidWeeks>=50?'completed':'active']);$contractId=(int)$pdo->lastInsertId();
        }

        $weekStmt=$pdo->prepare("INSERT INTO gp_contract_weeks (contract_id,week_number,due_date,amount,status,paid_at) VALUES (?,?,?,?,?,NULL)
            ON DUPLICATE KEY UPDATE due_date=VALUES(due_date),amount=VALUES(amount),status=CASE WHEN payment_report_id IS NULL THEN VALUES(status) ELSE status END,paid_at=CASE WHEN payment_report_id IS NULL THEN paid_at ELSE paid_at END");
        $dow=(int)$date->format('N');$anchor=$date->modify('+'.(($dow<=3)?3-$dow:10-$dow).' days');
        for($week=1;$week<=50;$week++){
            $due=$anchor->add(new DateInterval('P'.(($week-1)*7).'D'));
            $status=$week<=$paidWeeks?'paid':($week<=($paidWeeks+$lateWeeks)?'late':'pending');
            $weekStmt->execute([$contractId,$week,$due->format('Y-m-d'),$weekly,$status]);
        }

        $pdo->prepare("UPDATE gp_motorcycle_inventory SET current_customer_id=?,current_contract_id=?,vehicle_id=?,status='assigned',plate_locked=1 WHERE id=? AND current_finance_account_id=?")
            ->execute([$customerId,$contractId,$vehicleId,$inventoryId,$financeAccountId]);
        $pdo->prepare('UPDATE gp_finance_accounts SET plate=?,model=?,gps_device_id=?,gps_label=? WHERE id=?')
            ->execute([$plate,trim($brand.' '.$model),$deviceId,$gpsLabel?:null,$financeAccountId]);
        try{$pdo->prepare("INSERT INTO gp_vehicle_assignment_history (inventory_id,finance_account_id,customer_id,contract_id,event_key,notes,created_by) VALUES (?,?,?,?,?,?,?)")
            ->execute([$inventoryId,$financeAccountId,$customerId,$contractId,'portal_user_activated','Usuario Mi GRANDPRIX vinculado a la motocicleta y GPS ya asignados.',(string)($actor['email']??'admin')]);}catch(Throwable){}
        $pdo->commit();
        return ['id'=>$customerId,'financeAccountId'=>$financeAccountId,'contractId'=>$contractId,'vehicleId'=>$vehicleId,'inventoryId'=>$inventoryId,'plate'=>$plate,'model'=>trim($brand.' '.$model),'deviceId'=>$deviceId,'publicKey'=>$key,'status'=>$active?'active':'suspended'];
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}


function gp_customer_assign_inventory(PDO $pdo,int $financeAccountId,int $inventoryId,array $actor): void
{
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
    if($dup=$otherUnit->fetch())throw new InvalidArgumentException('Este cliente ya tiene asignada la placa '.(string)$dup['plate'].'. Libera primero esa motocicleta.');
    $pdo->prepare("UPDATE gp_motorcycle_inventory SET status='assigned',current_finance_account_id=?,plate_locked=1 WHERE id=?")
        ->execute([$financeAccountId,$inventoryId]);
    try{$pdo->prepare("INSERT INTO gp_vehicle_assignment_history (inventory_id,finance_account_id,customer_id,contract_id,event_key,notes,created_by) VALUES (?,?,?,?,?,?,?)")
        ->execute([$inventoryId,$financeAccountId,null,null,'assigned_portal_prepare','Unidad consolidada antes de activar Mi GRANDPRIX.',(string)($actor['email']??'admin')]);}catch(Throwable){}
}

function portalInventoryImagePath(string $brand,string $model): string
{
    $v=mb_strtolower(trim($brand.' '.$model));
    $map=[
        ['/(^|\\s)(bera\\s*)?leon(\\s|$)/u','../assets/inventory-models/leon-silver.png'],
        ['/(^|\\s)(bera\\s*)?sbr(\\s|$)/u','../assets/inventory-models/sbr-blue.png'],
        ['/(^|\\s)(bera\\s*)?brf(\\s|$)/u','../assets/inventory-models/brf-red.png'],
        ['/veloz/u','../assets/inventory-models/veloz-white.png'],['/socialista/u','../assets/inventory-models/socialista-blue.png'],
        ['/lovis/u','../assets/inventory-models/lovis-cream.png'],['/kadi\\s*classic|(^|\\s)kadi(\\s|$)/u','../assets/inventory-models/kadi-classic-red.png'],
        ['/(^|\\s)x1(\\s|$)/u','../assets/inventory-models/x1-yellow.png'],['/aguila/u','../assets/inventory-models/aguila-black.png'],
        ['/gbr/u','../assets/inventory-models/gbr-black.png'],['/express/u','../assets/inventory-models/express-blue.png'],
    ];
    foreach($map as [$pattern,$path])if(preg_match($pattern,$v))return $path;
    return '../assets/inventory-models/generic-default.png';
}

