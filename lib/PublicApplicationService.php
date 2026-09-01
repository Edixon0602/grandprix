<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/AdminAuth.php';
require_once __DIR__ . '/InventoryService.php';
require_once __DIR__ . '/EventAudit.php';

final class PublicApplicationService
{
    private string $uploadRoot;
    private ?bool $checklistAvailable = null;

    public function __construct(private readonly PDO $pdo)
    {
        $this->uploadRoot = dirname(__DIR__) . '/config/application-files';
    }

    public static function create(): self
    {
        return new self(Database::connection());
    }

    public function catalog(): array
    {
        $rows = $this->pdo->query(
            "SELECT model_family, MAX(image_path) AS image_path, COUNT(*) AS units
             FROM gp_finance_accounts
             WHERE record_status <> 'archived'
               AND model_family IS NOT NULL
               AND TRIM(model_family) <> ''
               AND LOWER(TRIM(model_family)) NOT IN ('sin modelo','n/a')
             GROUP BY model_family
             ORDER BY COUNT(*) DESC, model_family"
        )->fetchAll();
        $result = [];
        foreach ($rows as $row) {
            $model = trim((string) ($row['model_family'] ?? ''));
            if ($model === '') continue;
            $image = basename((string) ($row['image_path'] ?? 'moto-blue.png'));
            if (!in_array($image, ['moto-blue.png','moto-black.png','moto-red.png'], true)) $image = 'moto-blue.png';
            $result[] = [
                'name' => $model,
                'image' => 'assets/' . $image,
                'referenceCount' => (int) ($row['units'] ?? 0),
            ];
        }
        return $result;
    }

    public function ensureChecklist(int $applicationId): void
    {
        if (!$this->hasChecklistTable()) return;
        $stmt=$this->pdo->prepare('SELECT * FROM gp_finance_applications WHERE id=? LIMIT 1');
        $stmt->execute([$applicationId]);
        $app=$stmt->fetch();
        if(!$app)return;
        $requestAt=(string)($app['created_at']??'');
        if($requestAt==='')$requestAt=((string)($app['requested_at']??date('Y-m-d'))).' 00:00:00';
        $defaults=[
            ['request_received','process','Solicitud registrada',1,'system',10],
            ['identity_card','documents','Foto de cédula de identidad',1,'public',20],
            ['income_proof','documents','Soporte de ingresos',0,'public',30],
            ['other_document','documents','Documento adicional',0,'public',40],
            ['documents_validation','process','Validación documental',1,'system',60],
            ['visit_location','visit','Ubicación GPS de vivienda',1,'public',70],
            ['facade_photo','visit','Foto de fachada',1,'public',80],
            ['visit_validation','process','Validación de visita',1,'system',90],
            ['office_appointment','office','Cita en oficina',1,'system',100],
            ['credit_decision','office','Decisión de crédito',1,'system',110],
            ['customer_created','formalization','Cliente GRANDPRIX creado',1,'system',120],
            ['portal_activated','formalization','Cuenta Mi GRANDPRIX activada',1,'system',130],
            ['contract_assigned','formalization','Contrato asignado',1,'system',140],
            ['motorcycle_assigned','formalization','Motocicleta asignada',1,'system',150],
            ['gps_assigned','formalization','GPS asignado',1,'system',160],
            ['plan_generated','formalization','Plan de 50 cuotas generado',1,'system',170],
            ['contract_documents_signed','formalization','Documentación contractual firmada',1,'system',180],
            ['motorcycle_delivery','delivery','Entrega de motocicleta',1,'system',190],
        ];
        $insert=$this->pdo->prepare("INSERT IGNORE INTO gp_finance_application_checklist (application_id,item_key,item_group,label,required,status,source,received_at,validated_at,sort_order) VALUES (?,?,?,?,?,'pending',?,?,?,?)");
        foreach($defaults as [$key,$group,$label,$required,$source,$sort]){
            $received=null;$validated=null;
            if($key==='request_received'){$received=$requestAt;$validated=$requestAt;}
            $insert->execute([$applicationId,$key,$group,$label,$required,$source,$received,$validated,$sort]);
        }
        $this->syncChecklistFromCurrentData($applicationId,$app);
    }

    public function updateChecklistItem(int $applicationId, int $itemId, array $input, array $actor): array
    {
        if(!$this->hasChecklistTable())throw new RuntimeException('Instala primero la actualización de checklist de expedientes.');
        $this->ensureChecklist($applicationId);
        $email=(string)($actor['email']??'admin');
        $status=$this->clean($input['status']??'pending',30);
        if(!in_array($status,['pending','received','validated','observed','not_applicable'],true))throw new InvalidArgumentException('Estado de recaudo no válido.');
        $required=!empty($input['required'])?1:0;
        $notes=$this->clean($input['notes']??'',500);
        $receivedAt=$this->parseDateTime($input['receivedAt']??null);
        if(in_array($status,['received','validated','observed'],true)&&$receivedAt===null)$receivedAt=date('Y-m-d H:i:s');
        $validatedAt=$status==='validated'?date('Y-m-d H:i:s'):null;
        if($itemId>0){
            $stmt=$this->pdo->prepare('SELECT * FROM gp_finance_application_checklist WHERE id=? AND application_id=? LIMIT 1');$stmt->execute([$itemId,$applicationId]);$before=$stmt->fetch();
            if(!$before)throw new InvalidArgumentException('El recaudo no existe en este expediente.');
            if($status==='pending'){$receivedAt=null;$validatedAt=null;}
            $this->pdo->prepare('UPDATE gp_finance_application_checklist SET required=?,status=?,received_at=?,validated_at=?,notes=? WHERE id=? AND application_id=?')
                ->execute([$required,$status,$receivedAt,$validatedAt,$notes?:null,$itemId,$applicationId]);
            $label=(string)$before['label'];
            $this->addEvent($applicationId,'checklist_updated','Checklist actualizado: '.$label.' → '.$this->checklistStatusLabel($status).'.',$email);
        }else{
            $label=$this->clean($input['label']??'',160);if($label==='')throw new InvalidArgumentException('Indica el nombre del recaudo.');
            $key='custom_'.bin2hex(random_bytes(8));
            $this->pdo->prepare("INSERT INTO gp_finance_application_checklist (application_id,item_key,item_group,label,required,status,source,received_at,validated_at,notes,sort_order) VALUES (?,?, 'documents', ?, ?, ?, 'admin', ?, ?, ?, 75)")
                ->execute([$applicationId,$key,$label,$required,$status,$receivedAt,$validatedAt,$notes?:null]);
            $this->addEvent($applicationId,'checklist_added','Nuevo recaudo agregado al checklist: '.$label.'.',$email);
        }
        return $this->adminDetail($applicationId)??[];
    }

    public function deleteChecklistItem(int $applicationId, int $itemId, array $actor): array
    {
        if(!$this->hasChecklistTable())throw new RuntimeException('Checklist no disponible.');
        $stmt=$this->pdo->prepare("SELECT * FROM gp_finance_application_checklist WHERE id=? AND application_id=? AND source='admin' LIMIT 1");$stmt->execute([$itemId,$applicationId]);$row=$stmt->fetch();
        if(!$row)throw new InvalidArgumentException('Solo puedes eliminar recaudos personalizados agregados por administración.');
        $this->pdo->prepare('DELETE FROM gp_finance_application_checklist WHERE id=? AND application_id=?')->execute([$itemId,$applicationId]);
        $this->addEvent($applicationId,'checklist_deleted','Recaudo personalizado eliminado: '.(string)$row['label'].'.',(string)($actor['email']??'admin'));
        return $this->adminDetail($applicationId)??[];
    }

    public function adminUploadDocument(int $applicationId, int $itemId, array $file, array $actor): array
    {
        if(!$this->hasChecklistTable())throw new RuntimeException('Checklist no disponible.');
        $this->ensureChecklist($applicationId);
        $stmt=$this->pdo->prepare("SELECT * FROM gp_finance_application_checklist WHERE id=? AND application_id=? AND item_group='documents' LIMIT 1");
        $stmt->execute([$itemId,$applicationId]);
        $item=$stmt->fetch();
        if(!$item)throw new InvalidArgumentException('El recaudo documental no existe en este expediente.');
        $email=(string)($actor['email']??'admin');
        $this->saveUpload($applicationId,(string)$item['item_key'],$file,(string)$item['item_key']==='identity_card',$email);
        $this->addEvent($applicationId,'admin_document_upload','Administración adjuntó el recaudo: '.(string)$item['label'].'.',$email);
        return $this->adminDetail($applicationId)??[];
    }

    public function activatePortalAccount(string $code, string $rawToken, array $input): array
    {
        $row=$this->publicRow($code,$rawToken);
        if(!in_array((string)$row['status'],['approved','delivered'],true))throw new InvalidArgumentException('La cuenta Mi GRANDPRIX se habilita después de la aprobación del financiamiento.');
        $customerId=(int)($row['portal_customer_id']??0);
        if($customerId<1)throw new InvalidArgumentException('GRANDPRIX aún está preparando tu cuenta. Intenta nuevamente cuando el expediente indique cuenta disponible.');
        $stmt=$this->pdo->prepare('SELECT * FROM gp_customers WHERE id=? LIMIT 1');$stmt->execute([$customerId]);$customer=$stmt->fetch();
        if(!$customer)throw new InvalidArgumentException('La cuenta asociada no está disponible.');
        if((string)$customer['status']==='active')throw new InvalidArgumentException('Tu cuenta Mi GRANDPRIX ya está activa. Ingresa directamente al portal de clientes.');
        if((string)$customer['status']!=='pending_activation')throw new InvalidArgumentException('La cuenta no puede activarse desde este enlace. Contacta a GRANDPRIX.');
        $password=(string)($input['password']??'');$confirm=(string)($input['passwordConfirm']??'');
        if(strlen($password)<8)throw new InvalidArgumentException('La contraseña debe tener al menos 8 caracteres.');
        if($password!==$confirm)throw new InvalidArgumentException('Las contraseñas no coinciden.');
        $now=date('Y-m-d H:i:s');
        $this->pdo->beginTransaction();
        try{
            $this->pdo->prepare("UPDATE gp_customers SET password_hash=?,status='active' WHERE id=?")->execute([password_hash($password,PASSWORD_DEFAULT),$customerId]);
            $this->pdo->prepare("UPDATE gp_finance_applications SET portal_activation_status='active',portal_activated_at=? WHERE id=?")->execute([$now,(int)$row['id']]);
            $this->ensureChecklist((int)$row['id']);
            $this->setChecklistState((int)$row['id'],'portal_activated','validated',$now,$now,'Cuenta activada por el cliente desde el seguimiento.');
            $this->addEvent((int)$row['id'],'portal_activated','Cuenta Mi GRANDPRIX activada por el cliente.','public-web');
            $this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        return $this->status($code,$rawToken);
    }

    public function formalizeApplication(int $applicationId, array $input, array $actor): array
    {
        $stmt=$this->pdo->prepare('SELECT * FROM gp_finance_applications WHERE id=? LIMIT 1');$stmt->execute([$applicationId]);$app=$stmt->fetch();
        if(!$app)throw new InvalidArgumentException('La solicitud no existe.');
        if((string)$app['status']!=='approved')throw new InvalidArgumentException('La formalización solo se habilita después de aprobar la solicitud.');
        $email=(string)($actor['email']??'admin');
        $customer=$this->ensurePortalCustomer($applicationId,$app,$email);
        $customerId=(int)$customer['id'];
        $vehicleCode=mb_substr(trim((string)($input['vehicleCode']??'')),0,40);
        $plate=mb_strtoupper(mb_substr(trim((string)($input['plate']??'')),0,40));
        $model=mb_substr(trim((string)($input['model']??($app['model_requested']??''))),0,120);
        $contractNumber=mb_substr(trim((string)($input['contractNumber']??'')),0,60);
        $weekly=filter_var($input['weeklyAmount']??null,FILTER_VALIDATE_FLOAT);
        $paidWeeks=filter_var($input['paidWeeks']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>50]]);
        $gpsId=filter_var($input['gpsDeviceId']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $startDate=trim((string)($input['startDate']??''));$date=DateTimeImmutable::createFromFormat('!Y-m-d',$startDate);
        if($vehicleCode===''||$model===''||$contractNumber==='')throw new InvalidArgumentException('Debes indicar código de moto, modelo y número de contrato.');
        if($weekly===false||$weekly<=0||$weekly>1000000)throw new InvalidArgumentException('La cuota semanal no es válida.');
        if($paidWeeks===false)throw new InvalidArgumentException('Las cuotas pagadas deben estar entre 0 y 50.');
        if($gpsId===false)throw new InvalidArgumentException('Selecciona el GPS real asignado a la motocicleta.');
        if(!$date||$date->format('Y-m-d')!==$startDate)throw new InvalidArgumentException('La fecha inicial del contrato no es válida.');
        if($plate!==''){
            $plateConflict=$this->pdo->prepare("SELECT c.customer_id,u.full_name FROM gp_contracts c INNER JOIN gp_customers u ON u.id=c.customer_id INNER JOIN gp_vehicles v ON v.id=c.vehicle_id WHERE UPPER(REPLACE(COALESCE(v.plate,''),' ',''))=? AND c.customer_id<>? AND c.status='active' LIMIT 1");
            $plateConflict->execute([$plate,$customerId]);$pc=$plateConflict->fetch();if($pc)throw new InvalidArgumentException('La placa '.$plate.' ya está asignada a '.(string)$pc['full_name'].'. Primero debes archivar y desactivar al cliente anterior para liberar la motocicleta.');
        }
        $before=$app;$now=date('Y-m-d H:i:s');
        $this->pdo->beginTransaction();
        try{
            $byGps=$this->pdo->prepare('SELECT id FROM gp_vehicles WHERE traccar_device_id=? LIMIT 1');$byGps->execute([(int)$gpsId]);$gpsVehicleId=(int)($byGps->fetchColumn()?:0);
            $byCode=$this->pdo->prepare('SELECT id FROM gp_vehicles WHERE code=? LIMIT 1');$byCode->execute([$vehicleCode]);$codeVehicleId=(int)($byCode->fetchColumn()?:0);
            if($gpsVehicleId>0&&$codeVehicleId>0&&$gpsVehicleId!==$codeVehicleId)throw new InvalidArgumentException('El código de moto y el GPS seleccionado pertenecen a unidades distintas.');
            $vehicleId=$gpsVehicleId?:$codeVehicleId;
            if($vehicleId>0){
                $assignment=$this->pdo->prepare("SELECT c.customer_id,u.full_name FROM gp_contracts c INNER JOIN gp_customers u ON u.id=c.customer_id WHERE c.vehicle_id=? AND c.customer_id<>? AND c.status='active' LIMIT 1");$assignment->execute([$vehicleId,$customerId]);$other=$assignment->fetch();
                if($other)throw new InvalidArgumentException('Esa motocicleta/GPS ya está asignada a '.(string)$other['full_name'].'.');
                $this->pdo->prepare("UPDATE gp_vehicles SET code=?,plate=?,model=?,traccar_device_id=?,status='active' WHERE id=?")->execute([$vehicleCode,$plate?:null,$model,(int)$gpsId,$vehicleId]);
            }else{
                $this->pdo->prepare("INSERT INTO gp_vehicles (code,plate,model,traccar_device_id,status) VALUES (?,?,?,?,'active')")->execute([$vehicleCode,$plate?:null,$model,(int)$gpsId]);
                $vehicleId=(int)$this->pdo->lastInsertId();
            }
            $contractLookup=$this->pdo->prepare("SELECT id FROM gp_contracts WHERE customer_id=? AND status IN ('active','completed') ORDER BY id DESC LIMIT 1");$contractLookup->execute([$customerId]);$contractId=(int)($contractLookup->fetchColumn()?:0);
            $financed=round(((float)$weekly)*50,2);
            if($contractId>0){
                $this->pdo->prepare("UPDATE gp_contracts SET contract_number=?,vehicle_id=?,total_weeks=50,weekly_amount=?,financed_amount=?,start_date=?,status='active' WHERE id=?")->execute([$contractNumber,$vehicleId,$weekly,$financed,$startDate,$contractId]);
            }else{
                $this->pdo->prepare("INSERT INTO gp_contracts (contract_number,customer_id,vehicle_id,total_weeks,weekly_amount,financed_amount,start_date,status) VALUES (?,?,?,50,?,?,?,'active')")->execute([$contractNumber,$customerId,$vehicleId,$weekly,$financed,$startDate]);
                $contractId=(int)$this->pdo->lastInsertId();
            }
            $weekStmt=$this->pdo->prepare("INSERT INTO gp_contract_weeks (contract_id,week_number,due_date,amount,status,paid_at) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE due_date=VALUES(due_date),amount=VALUES(amount),status=CASE WHEN payment_report_id IS NULL THEN VALUES(status) ELSE status END,paid_at=CASE WHEN payment_report_id IS NULL THEN VALUES(paid_at) ELSE paid_at END");
            // GRANDPRIX cobra todos los miércoles. La primera cuota corresponde al
            // primer miércoles igual o posterior a la fecha de inicio del contrato.
            $isoDay=(int)$date->format('N');$daysToWednesday=(3-$isoDay+7)%7;
            $firstWednesday=$daysToWednesday===0?$date:$date->modify('+'.$daysToWednesday.' days');
            $today=new DateTimeImmutable('today');$lateWeeks=0;
            for($week=1;$week<=50;$week++){
                $due=$firstWednesday->add(new DateInterval('P'.(($week-1)*7).'D'));
                $weekStatus=$week<=(int)$paidWeeks?'paid':($due<$today?'late':'pending');
                if($weekStatus==='late')$lateWeeks++;
                // Si paidWeeks viene como saldo histórico, no inventamos una fecha de pago.
                $weekStmt->execute([$contractId,$week,$due->format('Y-m-d'),$weekly,$weekStatus,null]);
            }
            $identity=$this->normalizeIdentity((string)($app['identity_document']??''));
            $financeId=0;
            if($identity!==''){$f=$this->pdo->prepare('SELECT id FROM gp_finance_accounts WHERE UPPER(REPLACE(REPLACE(REPLACE(identity_document,".","")," ",""),"-",""))=? AND record_status<>\'archived\' ORDER BY id DESC LIMIT 1');$f->execute([str_replace('-','',$identity)]);$financeId=(int)($f->fetchColumn()?:0);}
            if($financeId===0){$f=$this->pdo->prepare('SELECT id FROM gp_finance_accounts WHERE contract_number=? AND record_status<>\'archived\' LIMIT 1');$f->execute([$contractNumber]);$financeId=(int)($f->fetchColumn()?:0);}
            $gpsConflict=$this->pdo->prepare("SELECT id,full_name FROM gp_finance_accounts WHERE gps_device_id=? AND id<>? AND record_status<>'archived' LIMIT 1");$gpsConflict->execute([(int)$gpsId,$financeId]);$fc=$gpsConflict->fetch();if($fc)throw new InvalidArgumentException('Ese GPS ya figura asignado a '.(string)$fc['full_name'].' en la cartera financiera.');
            if($plate!==''){$plateFinance=$this->pdo->prepare("SELECT id,full_name FROM gp_finance_accounts WHERE UPPER(REPLACE(COALESCE(plate,''),' ',''))=? AND id<>? AND record_status<>'archived' LIMIT 1");$plateFinance->execute([$plate,$financeId]);$pf=$plateFinance->fetch();if($pf)throw new InvalidArgumentException('La placa '.$plate.' ya figura asignada a '.(string)$pf['full_name'].' en la cartera financiera. Debes archivar/desactivar ese cliente antes de reasignarla.');}
            $family=$this->modelFamily($model);$image=$this->referenceImage($model);$notes='Creado desde solicitud '.$app['application_code'].'.';
            if($financeId>0){
                $this->pdo->prepare("UPDATE gp_finance_accounts SET full_name=?,identity_document=?,phone=?,address=?,contract_number=?,weekly_amount=?,financed_amount=?,start_date=?,model=?,model_family=?,image_path=?,plate=?,installments_paid=?,installments_late=?,referrer=?,gps_device_id=?,gps_label=?,notes=CONCAT(COALESCE(notes,''),CASE WHEN COALESCE(notes,'')='' THEN '' ELSE '\n' END,?) WHERE id=?")
                    ->execute([(string)$app['applicant_name'],$identity?:null,$app['phone']?:null,$app['address']?:null,$contractNumber,$weekly,$financed,$startDate,$model,$family,$image,$plate?:null,(int)$paidWeeks,$lateWeeks,$app['referrer']?:null,(int)$gpsId,'Solicitud '.$app['application_code'],$notes,$financeId]);
            }else{
                $this->pdo->prepare("INSERT INTO gp_finance_accounts (full_name,identity_document,phone,address,contract_number,weekly_amount,financed_amount,start_date,model,model_family,image_path,plate,total_installments,installments_paid,installments_late,referrer,gps_device_id,gps_label,notes,record_status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,50,?,?,?,?,?,?,'active')")
                    ->execute([(string)$app['applicant_name'],$identity?:null,$app['phone']?:null,$app['address']?:null,$contractNumber,$weekly,$financed,$startDate,$model,$family,$image,$plate?:null,(int)$paidWeeks,$lateWeeks,$app['referrer']?:null,(int)$gpsId,'Solicitud '.$app['application_code'],$notes]);
                $financeId=(int)$this->pdo->lastInsertId();
            }
            $inventory=new InventoryService($this->pdo);if($inventory->ready())$inventory->syncPortalAssignment($financeId,$customerId,$contractId,$vehicleId,$plate,(int)$gpsId,$model,$actor);
            if(!empty($app['referrer']))$this->pdo->prepare('INSERT IGNORE INTO gp_finance_referrers (display_name,source_key,sort_order) VALUES (?,?,999)')->execute([(string)$app['referrer'],(string)$app['referrer']]);
            $this->pdo->prepare('UPDATE gp_finance_applications SET formalized_at=?,formalization_finance_account_id=? WHERE id=?')->execute([$now,$financeId,$applicationId]);
            $this->ensureChecklist($applicationId);
            foreach(['customer_created','contract_assigned','motorcycle_assigned','gps_assigned','plan_generated'] as $key)$this->setChecklistState($applicationId,$key,'validated',$now,$now,'Formalización completada por '.$email.'.');
            $this->addEvent($applicationId,'formalization_completed','Cliente, contrato, motocicleta, GPS y plan de 50 cuotas formalizados.',$email);
            $afterStmt=$this->pdo->prepare('SELECT * FROM gp_finance_applications WHERE id=?');$afterStmt->execute([$applicationId]);$after=$afterStmt->fetch()?:[];
            AdminAuth::audit($this->pdo,$actor,'finance','formalize_application','gp_finance_applications',$applicationId,$before,$after);
            $this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        return $this->adminDetail($applicationId)??[];
    }

    public function backfillApprovedPortalAccounts(string $actor='installer-v16'): int
    {
        $rows=$this->pdo->query("SELECT * FROM gp_finance_applications WHERE status IN ('approved','delivered') ORDER BY id")->fetchAll();$count=0;
        foreach($rows as $app){$this->ensureChecklist((int)$app['id']);$this->ensurePortalCustomer((int)$app['id'],$app,$actor);$count++;}
        return $count;
    }

    /**
     * Verifica y repara únicamente la estructura mínima necesaria para que el
     * formulario público pueda guardar solicitudes. Se ejecuta antes de abrir
     * la transacción para evitar que una instalación parcial de versiones
     * anteriores bloquee el alta del expediente.
     */
    public function repairPublicSubmissionSchema(): array
    {
        $changes = [];
        $suffix = " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if (!$this->tableExists('gp_finance_applications')) {
            $this->pdo->exec("CREATE TABLE gp_finance_applications (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                application_code VARCHAR(40) NOT NULL UNIQUE,
                applicant_name VARCHAR(160) NOT NULL,
                first_names VARCHAR(100) NULL,
                last_names VARCHAR(100) NULL,
                identity_document VARCHAR(40) NULL,
                birth_date DATE NULL,
                age SMALLINT UNSIGNED NULL,
                phone VARCHAR(40) NULL,
                phone_2 VARCHAR(40) NULL,
                address VARCHAR(300) NULL,
                occupation VARCHAR(160) NULL,
                family_load SMALLINT UNSIGNED NULL,
                monthly_income DECIMAL(12,2) NULL,
                email VARCHAR(190) NULL,
                referral_type VARCHAR(30) NULL,
                referral_detail VARCHAR(160) NULL,
                model_requested VARCHAR(120) NULL,
                model_requested_2 VARCHAR(120) NULL,
                referrer VARCHAR(100) NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'new',
                documents_status VARCHAR(30) NOT NULL DEFAULT 'pending',
                validation_notes VARCHAR(500) NULL,
                visit_status VARCHAR(30) NOT NULL DEFAULT 'locked',
                visit_notes VARCHAR(500) NULL,
                latitude DECIMAL(10,7) NULL,
                longitude DECIMAL(10,7) NULL,
                facade_photo_path VARCHAR(255) NULL,
                appointment_status VARCHAR(30) NOT NULL DEFAULT 'pending',
                appointment_at DATETIME NULL,
                office_notes VARCHAR(500) NULL,
                access_token_hash CHAR(64) NULL,
                public_submitted TINYINT(1) NOT NULL DEFAULT 0,
                delivered_at DATETIME NULL,
                delivery_notes VARCHAR(500) NULL,
                portal_customer_id BIGINT UNSIGNED NULL,
                portal_activation_status VARCHAR(30) NOT NULL DEFAULT 'not_created',
                portal_activated_at DATETIME NULL,
                formalized_at DATETIME NULL,
                formalization_finance_account_id BIGINT UNSIGNED NULL,
                requested_at DATE NOT NULL,
                notes VARCHAR(1000) NULL,
                created_by VARCHAR(190) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_gp_finapp_status (status,requested_at),
                INDEX idx_gp_finapp_name (applicant_name)
            ){$suffix}");
            $changes[] = 'gp_finance_applications creada';
        }

        $requiredColumns = [
            'first_names'=>'VARCHAR(100) NULL','last_names'=>'VARCHAR(100) NULL','identity_document'=>'VARCHAR(40) NULL',
            'birth_date'=>'DATE NULL','age'=>'SMALLINT UNSIGNED NULL','phone'=>'VARCHAR(40) NULL','phone_2'=>'VARCHAR(40) NULL',
            'address'=>'VARCHAR(300) NULL','occupation'=>'VARCHAR(160) NULL','family_load'=>'SMALLINT UNSIGNED NULL',
            'monthly_income'=>'DECIMAL(12,2) NULL','email'=>'VARCHAR(190) NULL','referral_type'=>'VARCHAR(30) NULL',
            'referral_detail'=>'VARCHAR(160) NULL','model_requested'=>'VARCHAR(120) NULL','model_requested_2'=>'VARCHAR(120) NULL',
            'referrer'=>'VARCHAR(100) NULL','status'=>"VARCHAR(30) NOT NULL DEFAULT 'new'",'documents_status'=>"VARCHAR(30) NOT NULL DEFAULT 'pending'",
            'validation_notes'=>'VARCHAR(500) NULL','visit_status'=>"VARCHAR(30) NOT NULL DEFAULT 'locked'",'visit_notes'=>'VARCHAR(500) NULL',
            'latitude'=>'DECIMAL(10,7) NULL','longitude'=>'DECIMAL(10,7) NULL','facade_photo_path'=>'VARCHAR(255) NULL',
            'appointment_status'=>"VARCHAR(30) NOT NULL DEFAULT 'pending'",'appointment_at'=>'DATETIME NULL','office_notes'=>'VARCHAR(500) NULL',
            'access_token_hash'=>'CHAR(64) NULL','public_submitted'=>'TINYINT(1) NOT NULL DEFAULT 0','delivered_at'=>'DATETIME NULL',
            'delivery_notes'=>'VARCHAR(500) NULL','portal_customer_id'=>'BIGINT UNSIGNED NULL',
            'portal_activation_status'=>"VARCHAR(30) NOT NULL DEFAULT 'not_created'",'portal_activated_at'=>'DATETIME NULL',
            'formalized_at'=>'DATETIME NULL','formalization_finance_account_id'=>'BIGINT UNSIGNED NULL',
            'requested_at'=>'DATE NULL','notes'=>'VARCHAR(1000) NULL','created_by'=>'VARCHAR(190) NULL'
        ];
        foreach ($requiredColumns as $name=>$definition) {
            $q=$this->pdo->query("SHOW COLUMNS FROM gp_finance_applications LIKE ".$this->pdo->quote($name));
            if(!$q->fetch()){
                $this->pdo->exec("ALTER TABLE gp_finance_applications ADD COLUMN `{$name}` {$definition}");
                $changes[]='columna '.$name.' agregada';
            }
        }

        // El formulario V19+ ya no utiliza fecha de nacimiento. Una instalación
        // previa pudo dejar esta columna como NOT NULL, lo que impediría insertar.
        $birth=$this->pdo->query("SHOW COLUMNS FROM gp_finance_applications LIKE 'birth_date'")->fetch();
        if($birth && strtoupper((string)($birth['Null']??''))!=='YES'){
            $this->pdo->exec("ALTER TABLE gp_finance_applications MODIFY birth_date DATE NULL");
            $changes[]='birth_date normalizada como opcional';
        }

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS gp_finance_application_documents (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            application_id BIGINT UNSIGNED NOT NULL,
            doc_type VARCHAR(50) NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            stored_path VARCHAR(255) NOT NULL,
            mime_type VARCHAR(100) NOT NULL,
            file_size INT UNSIGNED NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'review',
            admin_notes VARCHAR(500) NULL,
            uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            reviewed_at DATETIME NULL,
            reviewed_by VARCHAR(190) NULL,
            INDEX idx_gp_finappdoc_app (application_id,doc_type,status)
        ){$suffix}");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS gp_finance_application_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            application_id BIGINT UNSIGNED NOT NULL,
            event_key VARCHAR(80) NOT NULL,
            label VARCHAR(300) NOT NULL,
            created_by VARCHAR(190) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_gp_finappevent_app (application_id,created_at)
        ){$suffix}");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS gp_finance_application_checklist (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            application_id BIGINT UNSIGNED NOT NULL,
            item_key VARCHAR(80) NOT NULL,
            item_group VARCHAR(30) NOT NULL DEFAULT 'documents',
            label VARCHAR(160) NOT NULL,
            required TINYINT(1) NOT NULL DEFAULT 1,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            source VARCHAR(30) NOT NULL DEFAULT 'system',
            document_id BIGINT UNSIGNED NULL,
            received_at DATETIME NULL,
            validated_at DATETIME NULL,
            notes VARCHAR(500) NULL,
            sort_order INT NOT NULL DEFAULT 100,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_gp_finappcheck_item (application_id,item_key),
            INDEX idx_gp_finappcheck_status (application_id,status,required)
        ){$suffix}");

        // Si la tabla ya existía desde una versión anterior, completar las columnas
        // que usa el guardado actual.
        $checkCols=[
            'document_id'=>'BIGINT UNSIGNED NULL','received_at'=>'DATETIME NULL','validated_at'=>'DATETIME NULL',
            'notes'=>'VARCHAR(500) NULL','sort_order'=>'INT NOT NULL DEFAULT 100','source'=>"VARCHAR(30) NOT NULL DEFAULT 'system'"
        ];
        foreach($checkCols as $name=>$definition){
            $q=$this->pdo->query("SHOW COLUMNS FROM gp_finance_application_checklist LIKE ".$this->pdo->quote($name));
            if(!$q->fetch()){$this->pdo->exec("ALTER TABLE gp_finance_application_checklist ADD COLUMN `{$name}` {$definition}");$changes[]='checklist.'.$name.' agregada';}
        }

        $docCols=[
            'admin_notes'=>'VARCHAR(500) NULL','reviewed_at'=>'DATETIME NULL','reviewed_by'=>'VARCHAR(190) NULL'
        ];
        foreach($docCols as $name=>$definition){
            $q=$this->pdo->query("SHOW COLUMNS FROM gp_finance_application_documents LIKE ".$this->pdo->quote($name));
            if(!$q->fetch()){$this->pdo->exec("ALTER TABLE gp_finance_application_documents ADD COLUMN `{$name}` {$definition}");$changes[]='documentos.'.$name.' agregada';}
        }

        $root=$this->uploadRoot;
        if(!is_dir($root) && !mkdir($root,0750,true) && !is_dir($root))throw new RuntimeException('No fue posible preparar la carpeta de documentos de solicitudes.');
        if(!is_file($root.'/.htaccess'))@file_put_contents($root.'/.htaccess',"Require all denied\nDeny from all\nOptions -Indexes\n");
        $this->checklistAvailable = null;
        return $changes;
    }

    public function submit(array $input, array $files): array
    {
        // Autorreparación mínima: evita que una migración parcial bloquee el website.
        $this->repairPublicSubmissionSchema();

        $firstNames = $this->clean($input['firstNames'] ?? '', 100);
        $lastNames = $this->clean($input['lastNames'] ?? '', 100);
        $identity = $this->clean($input['identityDocument'] ?? '', 40);
        $ageRaw = trim((string)($input['age'] ?? ''));
        // Normalización robusta: acepta valores enteros enviados como "32", "032" o "32.0".
        // Algunos teclados/navegadores pueden serializar el input number con esos formatos.
        $ageNormalized = str_replace(',', '.', $ageRaw);
        $age = null;
        if ($ageNormalized !== '' && is_numeric($ageNormalized)) {
            $ageFloat = (float)$ageNormalized;
            if (is_finite($ageFloat) && floor($ageFloat) === $ageFloat) $age = (int)$ageFloat;
        }
        $phone1 = $this->clean($input['phone'] ?? '', 40);
        $phone2 = $this->clean($input['phone2'] ?? '', 40);
        $address = $this->clean($input['address'] ?? '', 300);
        $occupation = $this->clean($input['occupation'] ?? '', 160);
        $familyLoad = filter_var($input['familyLoad'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0,'max_range'=>30]]);
        $income = filter_var($input['monthlyIncome'] ?? null, FILTER_VALIDATE_FLOAT);
        $email = mb_strtolower($this->clean($input['email'] ?? '', 190));
        $referralType = $this->clean($input['referralType'] ?? '', 30);
        $referralDetail = $this->clean($input['referralDetail'] ?? '', 160);
        $model1 = $this->clean($input['modelRequested'] ?? '', 120);
        $model2 = $this->clean($input['modelRequested2'] ?? '', 120);
        $notes = $this->clean($input['notes'] ?? '', 1000);

        if ($firstNames === '' || $lastNames === '') throw new InvalidArgumentException('Nombres y apellidos son obligatorios.');
        if ($identity === '') throw new InvalidArgumentException('La cédula es obligatoria.');
        if ($age === null || $age < 1 || $age > 120) throw new InvalidArgumentException('Indica una edad válida entre 1 y 120 años.');
        if ($phone1 === '' || $phone2 === '') throw new InvalidArgumentException('Debes registrar los dos números de teléfono.');
        if ($phone1 === $phone2) throw new InvalidArgumentException('Los dos números de teléfono deben ser diferentes.');
        if ($address === '') throw new InvalidArgumentException('La dirección es obligatoria.');
        if ($occupation === '') throw new InvalidArgumentException('La ocupación es obligatoria.');
        if ($familyLoad === false) throw new InvalidArgumentException('La carga familiar no es válida.');
        if ($income === false || $income < 0) throw new InvalidArgumentException('El ingreso mensual no es válido.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('El correo electrónico no es válido.');
        if (!in_array($referralType, ['redes','persona'], true)) throw new InvalidArgumentException('Indica cómo conociste GRANDPRIX.');
        if ($referralDetail === '') {
            throw new InvalidArgumentException($referralType === 'persona' ? 'Indica el nombre de la persona que te refirió.' : 'Indica en qué red social conociste GRANDPRIX.');
        }
        if ($model1 === '') throw new InvalidArgumentException('Selecciona el modelo que deseas como primera opción.');

        $catalogNames = array_column($this->catalog(), 'name');
        if ($catalogNames && !in_array($model1, $catalogNames, true)) throw new InvalidArgumentException('La primera opción de moto no pertenece al catálogo actual.');
        if ($model2 !== '' && $catalogNames && !in_array($model2, $catalogNames, true)) throw new InvalidArgumentException('La segunda opción de moto no pertenece al catálogo actual.');
        if ($model2 !== '' && $model2 === $model1) throw new InvalidArgumentException('La segunda opción debe ser un modelo diferente.');

        if (!isset($files['identityCard']) || (int)($files['identityCard']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new InvalidArgumentException('Debes cargar una foto de tu cédula de identidad.');
        }

        $fullName = trim($firstNames . ' ' . $lastNames);
        $applicationCode = 'SOL-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $continuationCode = $this->generateContinuationCode();
        $tokenHash = hash('sha256', $continuationCode);
        $referrer = $referralType === 'persona' ? $referralDetail : null;

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO gp_finance_applications
                (application_code,applicant_name,first_names,last_names,identity_document,birth_date,age,phone,phone_2,address,occupation,family_load,monthly_income,email,referral_type,referral_detail,model_requested,model_requested_2,referrer,status,documents_status,visit_status,appointment_status,requested_at,notes,created_by,access_token_hash,public_submitted)
                VALUES (?,?,?,?,?,NULL,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)"
            );
            $stmt->execute([
                $applicationCode,$fullName,$firstNames,$lastNames,$identity,(int)$age,$phone1,$phone2,$address,$occupation,(int)$familyLoad,(float)$income,$email,$referralType,$referralDetail,$model1,$model2?:null,$referrer,'documents_review','review','locked','pending',date('Y-m-d'),$notes?:null,'public-web',$tokenHash
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $this->ensureChecklist($id);
            $this->saveUpload($id, 'identity_card', $files['identityCard'], true);
            if (isset($files['incomeProof']) && (int)($files['incomeProof']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $this->saveUpload($id, 'income_proof', $files['incomeProof'], false);
            }
            if (isset($files['otherDocument']) && (int)($files['otherDocument']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $this->saveUpload($id, 'other_document', $files['otherDocument'], false);
            }
            $this->addEvent($id, 'public_submission', 'Solicitud enviada desde el sitio público.', 'public-web');
            $this->pdo->commit();
            EventAudit::recordPublic('applications','public_application_submit','create','Solicitud pública enviada.',['application_code'=>$applicationCode,'application_id'=>$id],$this->pdo);
            return ['applicationCode'=>$applicationCode,'continuationCode'=>$continuationCode,'trackingToken'=>$continuationCode,'status'=>'documents_review'];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function statusByAccessCode(string $accessCode): array
    {
        $row=$this->publicRowByAccessCode($accessCode);
        return $this->status((string)$row['application_code'], (string)$row['__verified_access_code']);
    }

    public function addDocumentsByAccessCode(string $accessCode, array $files): array
    {
        $row=$this->publicRowByAccessCode($accessCode);
        return $this->addDocuments((string)$row['application_code'], (string)$row['__verified_access_code'], $files);
    }

    public function submitVisitByAccessCode(string $accessCode, array $input, array $files): array
    {
        $row=$this->publicRowByAccessCode($accessCode);
        return $this->submitVisit((string)$row['application_code'], (string)$row['__verified_access_code'], $input, $files);
    }

    public function activatePortalAccountByAccessCode(string $accessCode, array $input): array
    {
        $row=$this->publicRowByAccessCode($accessCode);
        return $this->activatePortalAccount((string)$row['application_code'], (string)$row['__verified_access_code'], $input);
    }

    public function status(string $code, string $rawToken): array
    {
        $row = $this->publicRow($code, $rawToken);
        $id = (int) $row['id'];
        $stmt = $this->pdo->prepare("SELECT doc_type,status,uploaded_at FROM gp_finance_application_documents WHERE application_id=? ORDER BY id");
        $stmt->execute([$id]);
        $docs = $stmt->fetchAll();
        EventAudit::recordPublic('applications','tracking_status_view','view','Solicitante consultó el estado de su solicitud.',['application_code'=>$code,'application_id'=>$id],$this->pdo);
        return $this->presentPublic($row, $docs);
    }

    public function addDocuments(string $code, string $rawToken, array $files): array
    {
        $row = $this->publicRow($code, $rawToken);
        if (in_array((string)$row['status'], ['rejected','delivered'], true)) throw new InvalidArgumentException('La solicitud ya no admite nuevos recaudos desde el sitio público.');
        $id = (int)$row['id'];
        $this->ensureChecklist($id);
        $found = false;
        $this->pdo->beginTransaction();
        try {
            $fixed=[
                'identityCard'=>'identity_card',
                'identityFront'=>'identity_front', // compatibilidad con solicitudes anteriores
                'identityBack'=>'identity_back',
                'incomeProof'=>'income_proof',
                'otherDocument'=>'other_document',
            ];
            foreach ($fixed as $key=>$type) {
                if (!isset($files[$key]) || (int)($files[$key]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
                $this->saveUpload($id,$type,$files[$key],$type==='identity_card');
                $found = true;
            }
            foreach($files as $field=>$file){
                if(!str_starts_with((string)$field,'doc__'))continue;
                if((int)($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)continue;
                $itemKey=substr((string)$field,5);
                if(!preg_match('/^[a-zA-Z0-9_]{1,50}$/',$itemKey))throw new InvalidArgumentException('El recaudo solicitado no es válido.');
                $q=$this->pdo->prepare("SELECT id FROM gp_finance_application_checklist WHERE application_id=? AND item_key=? AND item_group='documents' LIMIT 1");$q->execute([$id,$itemKey]);
                if(!$q->fetchColumn())throw new InvalidArgumentException('Uno de los recaudos ya no pertenece a este expediente.');
                $this->saveUpload($id,$itemKey,$file,$itemKey==='identity_card');
                $found=true;
            }
            if (!$found) throw new InvalidArgumentException('Selecciona al menos un documento para cargar.');
            if((string)$row['documents_status']!=='approved'){
                $this->pdo->prepare("UPDATE gp_finance_applications SET documents_status='review', status='documents_review' WHERE id=?")->execute([$id]);
            }
            $this->addEvent($id,'documents_resubmitted','Documentación actualizada por el solicitante.','public-web');
            $this->pdo->commit();
            EventAudit::recordPublic('applications','documents_upload','create','Solicitante cargó o actualizó documentos.',['application_code'=>$code,'application_id'=>$id],$this->pdo);
            return $this->status($code,$rawToken);
        } catch(Throwable $e){ if($this->pdo->inTransaction())$this->pdo->rollBack(); throw $e; }
    }

    public function submitVisit(string $code, string $rawToken, array $input, array $files): array
    {
        $row = $this->publicRow($code, $rawToken);
        if ((string)$row['documents_status'] !== 'approved') throw new InvalidArgumentException('La documentación debe ser validada por GRANDPRIX antes de registrar la visita.');
        if (!in_array((string)$row['visit_status'], ['pending','rejected'], true)) throw new InvalidArgumentException('La etapa de visita no está disponible actualmente.');
        $lat = filter_var($input['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
        $lng = filter_var($input['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
        $address = $this->clean($input['visitAddress'] ?? ($row['address'] ?? ''), 300);
        if ($lat === false || $lat < -90 || $lat > 90 || $lng === false || $lng < -180 || $lng > 180) throw new InvalidArgumentException('Debes registrar una ubicación GPS válida.');
        if (!isset($files['facadePhoto']) || (int)($files['facadePhoto']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) throw new InvalidArgumentException('La foto de la fachada es obligatoria para la visita.');
        $id = (int)$row['id'];
        $this->ensureChecklist($id);
        $this->pdo->beginTransaction();
        try {
            $stored = $this->saveUpload($id,'facade',$files['facadePhoto'],true);
            $this->pdo->prepare("UPDATE gp_finance_applications SET address=?, latitude=?, longitude=?, facade_photo_path=?, visit_status='review', status='visit_review' WHERE id=?")
                ->execute([$address,(float)$lat,(float)$lng,$stored,$id]);
            $this->setChecklistState($id,'visit_location','received',date('Y-m-d H:i:s'),null,'Ubicación GPS registrada por el solicitante.');
            $this->addEvent($id,'visit_submitted','Ubicación GPS y fachada registradas para validación.','public-web');
            $this->pdo->commit();
            EventAudit::recordPublic('applications','visit_submit','create','Solicitante envió ubicación GPS y foto de fachada.',['application_code'=>$code,'application_id'=>$id],$this->pdo);
            return $this->status($code,$rawToken);
        } catch(Throwable $e){ if($this->pdo->inTransaction())$this->pdo->rollBack(); throw $e; }
    }

    public function adminList(): array
    {
        // Esta vista debe ser de solo lectura. En versiones anteriores el listado
        // intentaba sincronizar checklists durante un GET y cualquier migración
        // incompleta terminaba bloqueando todo el módulo de Solicitudes.
        $hasChecklist=$this->hasChecklistTable();
        $hasDocuments=$this->tableExists('gp_finance_application_documents');
        $selectDocuments=$hasDocuments
            ? "(SELECT COUNT(*) FROM gp_finance_application_documents d WHERE d.application_id=a.id) AS document_count"
            : "0 AS document_count";
        $check=$hasChecklist?",
              (SELECT COUNT(*) FROM gp_finance_application_checklist c WHERE c.application_id=a.id AND c.required=1) AS checklist_required,
              (SELECT COUNT(*) FROM gp_finance_application_checklist c WHERE c.application_id=a.id AND c.required=1 AND c.status IN ('received','validated','observed')) AS checklist_received,
              (SELECT COUNT(*) FROM gp_finance_application_checklist c WHERE c.application_id=a.id AND c.required=1 AND c.status='validated') AS checklist_validated,
              (SELECT MAX(c.received_at) FROM gp_finance_application_checklist c WHERE c.application_id=a.id AND c.received_at IS NOT NULL) AS last_recaudo_at":"";
        $rows=$this->pdo->query(
            "SELECT a.*, {$selectDocuments}{$check}
             FROM gp_finance_applications a
             ORDER BY a.requested_at DESC, a.id DESC"
        )->fetchAll();
        foreach($rows as &$row){
            if(!$hasChecklist){
                $row['checklist_required']=0;$row['checklist_received']=0;
                $row['checklist_validated']=0;$row['last_recaudo_at']=null;
            }
            $row=$this->presentAdminSummary($row);
        }
        unset($row);
        return $rows;
    }

    public function adminDetail(int $id): ?array
    {
        $this->ensureChecklist($id);
        $stmt=$this->pdo->prepare('SELECT * FROM gp_finance_applications WHERE id=? LIMIT 1');$stmt->execute([$id]);$row=$stmt->fetch();
        if(!$row)return null;
        $docs=$this->pdo->prepare('SELECT id,doc_type,original_name,mime_type,file_size,status,admin_notes,uploaded_at,reviewed_at,reviewed_by FROM gp_finance_application_documents WHERE application_id=? ORDER BY uploaded_at,id');
        $docs->execute([$id]);
        $events=$this->pdo->prepare('SELECT event_key,label,created_by,created_at FROM gp_finance_application_events WHERE application_id=? ORDER BY id DESC LIMIT 150');
        $events->execute([$id]);
        $row=$this->presentAdminSummary($row);
        $row['documents']=$docs->fetchAll();
        $row['events']=$events->fetchAll();
        $row['checklist']=[];
        if($this->hasChecklistTable()){
            $items=$this->pdo->prepare('SELECT * FROM gp_finance_application_checklist WHERE application_id=? ORDER BY sort_order,id');$items->execute([$id]);$row['checklist']=$items->fetchAll();
            $required=0;$received=0;$validated=0;$last=null;
            foreach($row['checklist'] as $item){
                if((int)$item['required']===1){$required++;if(in_array((string)$item['status'],['received','validated','observed'],true))$received++;if((string)$item['status']==='validated')$validated++;}
                if(!empty($item['received_at'])&&($last===null||$item['received_at']>$last))$last=$item['received_at'];
            }
            $row['checklist_summary']=['required'=>$required,'received'=>$received,'validated'=>$validated,'pending'=>max(0,$required-$received),'progress'=>$required?round(($received/$required)*100,1):100,'lastRecaudoAt'=>$last];
        }
        $row['portal_customer']=null;$row['formalization']=null;
        $customerId=(int)($row['portal_customer_id']??0);
        if($customerId>0){
            $c=$this->pdo->prepare('SELECT id,public_key,full_name,identity_document,email,phone,status,last_login_at,created_at FROM gp_customers WHERE id=? LIMIT 1');$c->execute([$customerId]);$row['portal_customer']=$c->fetch()?:null;
            $f=$this->pdo->prepare("SELECT c.id AS contract_id,c.contract_number,c.total_weeks,c.weekly_amount,c.financed_amount,c.start_date,c.status AS contract_status,v.id AS vehicle_id,v.code AS vehicle_code,v.plate,v.model,v.traccar_device_id,v.traccar_unique_id,(SELECT COUNT(*) FROM gp_contract_weeks w WHERE w.contract_id=c.id AND w.status='paid') AS paid_weeks FROM gp_contracts c INNER JOIN gp_vehicles v ON v.id=c.vehicle_id WHERE c.customer_id=? AND c.status IN ('active','completed') ORDER BY c.id DESC LIMIT 1");$f->execute([$customerId]);$row['formalization']=$f->fetch()?:null;
        }
        $row['delivery_blockers']=$this->deliveryBlockers($id,$row);
        $row['delivery_ready']=count($row['delivery_blockers'])===0;
        return $row;
    }

    public function transition(int $id, string $decision, array $input, array $actor): array
    {
        $allowed=['approve-documents','reject-documents','approve-visit','reject-visit','schedule-office','approve','reject','defer','mark-delivered'];
        if(!in_array($decision,$allowed,true))throw new InvalidArgumentException('Acción de flujo no válida.');
        $this->pdo->beginTransaction();
        try{
            $stmt=$this->pdo->prepare('SELECT * FROM gp_finance_applications WHERE id=? FOR UPDATE');$stmt->execute([$id]);$before=$stmt->fetch();
            if(!$before)throw new InvalidArgumentException('La solicitud no existe.');
            $notes=$this->clean($input['notes']??'',500);
            $email=(string)($actor['email']??'admin');
            $this->ensureChecklist($id);
            switch($decision){
                case 'approve-documents':
                    $this->pdo->prepare("UPDATE gp_finance_applications SET documents_status='approved', validation_notes=?, visit_status='pending', status='visit_pending' WHERE id=?")->execute([$notes?:null,$id]);
                    $this->pdo->prepare("UPDATE gp_finance_application_documents SET status='approved',reviewed_at=NOW(),reviewed_by=? WHERE application_id=? AND doc_type<>'facade'")->execute([$email,$id]);
                    $this->validateReceivedDocumentItems($id,true);
                    $this->setChecklistState($id,'documents_validation','validated',date('Y-m-d H:i:s'),date('Y-m-d H:i:s'),$notes?:'Documentación validada.');
                    $label='Documentación validada. Se habilitó la visita.';break;
                case 'reject-documents':
                    $this->pdo->prepare("UPDATE gp_finance_applications SET documents_status='rejected', validation_notes=?, visit_status='locked', status='documents_review' WHERE id=?")->execute([$notes?:null,$id]);
                    $this->pdo->prepare("UPDATE gp_finance_application_documents SET status='rejected',admin_notes=?,reviewed_at=NOW(),reviewed_by=? WHERE application_id=? AND doc_type<>'facade'")->execute([$notes?:null,$email,$id]);
                    $this->validateReceivedDocumentItems($id,false,$notes);
                    $this->setChecklistState($id,'documents_validation','observed',date('Y-m-d H:i:s'),null,$notes?:'Documentación observada.');
                    $label='Documentación observada; requiere corrección.';break;
                case 'approve-visit':
                    if((string)$before['visit_status']!=='review')throw new InvalidArgumentException('La visita aún no está lista para validar.');
                    $this->pdo->prepare("UPDATE gp_finance_applications SET visit_status='approved', visit_notes=?, appointment_status='pending', status='office_pending' WHERE id=?")->execute([$notes?:null,$id]);
                    $this->setChecklistState($id,'visit_location','validated',null,date('Y-m-d H:i:s'),$notes?:null);
                    $this->setChecklistState($id,'facade_photo','validated',null,date('Y-m-d H:i:s'),$notes?:null);
                    $this->setChecklistState($id,'visit_validation','validated',date('Y-m-d H:i:s'),date('Y-m-d H:i:s'),$notes?:'Visita validada.');
                    $label='Visita validada. Pendiente de cita en oficina.';break;
                case 'reject-visit':
                    $this->pdo->prepare("UPDATE gp_finance_applications SET visit_status='rejected', visit_notes=?, status='visit_pending' WHERE id=?")->execute([$notes?:null,$id]);
                    $this->setChecklistState($id,'visit_location','observed',null,null,$notes?:'Visita observada.');
                    $this->setChecklistState($id,'facade_photo','observed',null,null,$notes?:'Fachada observada.');
                    $this->setChecklistState($id,'visit_validation','observed',date('Y-m-d H:i:s'),null,$notes?:'Visita observada.');
                    $label='Visita observada; el solicitante debe actualizar ubicación o fachada.';break;
                case 'schedule-office':
                    $raw=(string)($input['appointmentAt']??'');
                    $dt=DateTimeImmutable::createFromFormat('Y-m-d\TH:i',$raw);
                    if(!$dt)throw new InvalidArgumentException('Indica fecha y hora válidas para la cita.');
                    $this->pdo->prepare("UPDATE gp_finance_applications SET appointment_status='scheduled', appointment_at=?, office_notes=?, status='office_scheduled' WHERE id=?")->execute([$dt->format('Y-m-d H:i:s'),$notes?:null,$id]);
                    $this->setChecklistState($id,'office_appointment','received',$dt->format('Y-m-d H:i:s'),null,$notes?:'Cita programada.');
                    $label='Cita en oficina programada para '.$dt->format('d/m/Y H:i').'.';break;
                case 'approve':
                    $this->pdo->prepare("UPDATE gp_finance_applications SET status='approved', office_notes=? WHERE id=?")->execute([$notes?:null,$id]);
                    $this->setChecklistState($id,'office_appointment','validated',null,date('Y-m-d H:i:s'),$notes?:null);
                    $this->setChecklistState($id,'credit_decision','validated',date('Y-m-d H:i:s'),date('Y-m-d H:i:s'),$notes?:'Solicitud aprobada.');
                    $fresh=$this->pdo->prepare('SELECT * FROM gp_finance_applications WHERE id=? LIMIT 1');$fresh->execute([$id]);$approved=$fresh->fetch()?:$before;
                    $this->ensurePortalCustomer($id,$approved,$email);
                    $label='Solicitud aprobada. Se preparó la cuenta Mi GRANDPRIX para activación.';break;
                case 'reject':
                    $this->pdo->prepare("UPDATE gp_finance_applications SET status='rejected', office_notes=? WHERE id=?")->execute([$notes?:null,$id]);
                    $this->setChecklistState($id,'office_appointment','validated',null,date('Y-m-d H:i:s'),$notes?:null);
                    $this->setChecklistState($id,'credit_decision','validated',date('Y-m-d H:i:s'),date('Y-m-d H:i:s'),$notes?:'Solicitud rechazada.');
                    $label='Solicitud rechazada.';break;
                case 'mark-delivered':
                    if((string)$before['status']!=='approved')throw new InvalidArgumentException('La moto solo puede marcarse como entregada después de aprobar la solicitud.');
                    $blockers=$this->deliveryBlockers($id,$before);
                    if($blockers)throw new InvalidArgumentException('Antes de entregar la moto completa: '.implode(' · ',$blockers).'.');
                    $raw=(string)($input['deliveryAt']??'');$delivery=$this->parseDateTime($raw);
                    if($delivery===null)throw new InvalidArgumentException('Indica la fecha y hora de entrega de la motocicleta.');
                    $this->pdo->prepare("UPDATE gp_finance_applications SET status='delivered', delivered_at=?, delivery_notes=? WHERE id=?")->execute([$delivery,$notes?:null,$id]);
                    $this->setChecklistState($id,'motorcycle_delivery','validated',$delivery,$delivery,$notes?:'Motocicleta entregada.');
                    $label='Motocicleta entregada el '.date('d/m/Y H:i',strtotime($delivery)).'.';break;
                default:
                    $this->pdo->prepare("UPDATE gp_finance_applications SET status='deferred', office_notes=? WHERE id=?")->execute([$notes?:null,$id]);
                    $this->setChecklistState($id,'office_appointment','validated',null,date('Y-m-d H:i:s'),$notes?:null);
                    $this->setChecklistState($id,'credit_decision','validated',date('Y-m-d H:i:s'),date('Y-m-d H:i:s'),$notes?:'Solicitud diferida.');
                    $label='Solicitud diferida.';break;
            }
            $this->addEvent($id,str_replace('-','_',$decision),$label,$email);
            $afterStmt=$this->pdo->prepare('SELECT * FROM gp_finance_applications WHERE id=?');$afterStmt->execute([$id]);$after=$afterStmt->fetch()?:[];
            AdminAuth::audit($this->pdo,$actor,'finance','application_workflow','gp_finance_applications',$id,$before,$after);
            $this->pdo->commit();
            return $this->adminDetail($id)??$after;
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function documentFile(int $documentId): ?array
    {
        $stmt=$this->pdo->prepare('SELECT * FROM gp_finance_application_documents WHERE id=? LIMIT 1');$stmt->execute([$documentId]);$row=$stmt->fetch();
        if(!$row)return null;
        $path=$this->uploadRoot.'/'.ltrim((string)$row['stored_path'],'/');
        if(!is_file($path))return null;
        $row['absolute_path']=$path;
        return $row;
    }

    private function ensurePortalCustomer(int $applicationId,array $app,string $actor): array
    {
        $linkedId=(int)($app['portal_customer_id']??0);
        if($linkedId>0){
            $stmt=$this->pdo->prepare('SELECT * FROM gp_customers WHERE id=? LIMIT 1');$stmt->execute([$linkedId]);$existing=$stmt->fetch();
            if($existing){
                $now=date('Y-m-d H:i:s');$active=(string)$existing['status']==='active';
                $this->pdo->prepare("UPDATE gp_finance_applications SET portal_activation_status=?,portal_activated_at=CASE WHEN ?=1 THEN COALESCE(portal_activated_at,?) ELSE portal_activated_at END WHERE id=?")
                    ->execute([$active?'active':'pending_activation',$active?1:0,$now,$applicationId]);
                $this->setChecklistState($applicationId,'customer_created','validated',$now,$now,'Cuenta de cliente asociada.');
                if($active)$this->setChecklistState($applicationId,'portal_activated','validated',$app['portal_activated_at']??$now,$app['portal_activated_at']??$now,'Cuenta Mi GRANDPRIX activa.');
                return $existing;
            }
        }
        $identity=$this->normalizeIdentity((string)($app['identity_document']??''));$email=mb_strtolower(trim((string)($app['email']??'')));
        $existing=null;
        if($identity!==''){$q=$this->pdo->prepare('SELECT * FROM gp_customers WHERE identity_document=? LIMIT 1');$q->execute([$identity]);$existing=$q->fetch()?:null;}
        if(!$existing&&$email!==''){$q=$this->pdo->prepare('SELECT * FROM gp_customers WHERE LOWER(COALESCE(email,\'\'))=? LIMIT 1');$q->execute([$email]);$existing=$q->fetch()?:null;}
        $created=false;$now=date('Y-m-d H:i:s');
        if($existing){$customerId=(int)$existing['id'];}
        else{
            $key=$this->uniquePortalKey($identity,(string)($app['first_names']??$app['applicant_name']??'cliente'));
            $random=password_hash(bin2hex(random_bytes(18)),PASSWORD_DEFAULT);
            $this->pdo->prepare("INSERT INTO gp_customers (public_key,full_name,identity_document,email,phone,password_hash,status) VALUES (?,?,?,?,?,?,'pending_activation')")
                ->execute([$key,(string)$app['applicant_name'],$identity!==''?$identity:('SOL-'.(int)$applicationId),$email!==''?$email:null,$app['phone']?:null,$random]);
            $customerId=(int)$this->pdo->lastInsertId();$created=true;
            $q=$this->pdo->prepare('SELECT * FROM gp_customers WHERE id=? LIMIT 1');$q->execute([$customerId]);$existing=$q->fetch()?:[];
        }
        $active=(string)($existing['status']??'')==='active';
        $this->pdo->prepare("UPDATE gp_finance_applications SET portal_customer_id=?,portal_activation_status=?,portal_activated_at=CASE WHEN ?=1 THEN COALESCE(portal_activated_at,?) ELSE portal_activated_at END WHERE id=?")
            ->execute([$customerId,$active?'active':'pending_activation',$active?1:0,$now,$applicationId]);
        $this->ensureChecklist($applicationId);
        $this->setChecklistState($applicationId,'customer_created','validated',$now,$now,$created?'Cliente GRANDPRIX creado desde la solicitud.':'Cliente existente asociado a la solicitud.');
        if($active)$this->setChecklistState($applicationId,'portal_activated','validated',$now,$now,'Cuenta Mi GRANDPRIX ya estaba activa.');
        if($created)$this->addEvent($applicationId,'customer_created','Cliente GRANDPRIX creado y pendiente de activación.',$actor);
        else $this->addEvent($applicationId,'customer_linked','Solicitud asociada a un cliente GRANDPRIX existente.',$actor);
        return $existing;
    }

    private function uniquePortalKey(string $identity,string $name): string
    {
        $base='gp-';$digits=preg_replace('/\D+/','',$identity)?:'';
        if($digits!=='')$base.=substr($digits,-8);
        else{$slug=mb_strtolower(trim($name));$slug=preg_replace('/[^a-z0-9]+/u','-',iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$slug)?:$slug);$base.=trim((string)$slug,'-');}
        $base=substr($base,0,70);if(strlen($base)<3)$base='gp-cliente';$candidate=$base;$i=1;
        $q=$this->pdo->prepare('SELECT id FROM gp_customers WHERE public_key=? LIMIT 1');
        while(true){$q->execute([$candidate]);if($q->fetchColumn()===false)return $candidate;$i++;$candidate=substr($base,0,68).'-'.$i;}
    }

    private function normalizeIdentity(string $value): string
    {
        $value=mb_strtoupper(trim($value));$value=preg_replace('/[^A-Z0-9-]/','',$value)?:'';return mb_substr($value,0,40);
    }

    private function deliveryBlockers(int $applicationId,array $app): array
    {
        $blockers=[];$customerId=(int)($app['portal_customer_id']??0);
        if($customerId<1)$blockers[]='Cliente GRANDPRIX no creado';
        else{
            $q=$this->pdo->prepare('SELECT status FROM gp_customers WHERE id=? LIMIT 1');$q->execute([$customerId]);$status=(string)($q->fetchColumn()?:'');
            if($status!=='active')$blockers[]='Cuenta Mi GRANDPRIX no activada';
            $q=$this->pdo->prepare("SELECT c.id,v.traccar_device_id FROM gp_contracts c INNER JOIN gp_vehicles v ON v.id=c.vehicle_id WHERE c.customer_id=? AND c.status='active' ORDER BY c.id DESC LIMIT 1");$q->execute([$customerId]);$contract=$q->fetch();
            if(!$contract)$blockers[]='Contrato/motocicleta sin formalizar';
            elseif((int)($contract['traccar_device_id']??0)<1)$blockers[]='GPS sin asignar';
        }
        if(empty($app['formalized_at']))$blockers[]='Formalización administrativa pendiente';
        if($this->hasChecklistTable()){
            $q=$this->pdo->prepare("SELECT label FROM gp_finance_application_checklist WHERE application_id=? AND item_group='formalization' AND required=1 AND status NOT IN ('validated','not_applicable') ORDER BY sort_order,id");$q->execute([$applicationId]);
            foreach($q->fetchAll(PDO::FETCH_COLUMN) as $label){if(!in_array((string)$label,$blockers,true))$blockers[]=(string)$label;}
        }
        return array_values(array_unique($blockers));
    }

    private function modelFamily(string $model): string
    {
        $up=mb_strtoupper(trim($model));if($up==='')return 'Sin modelo';
        if(str_contains($up,'LEON'))return 'Bera León';if(str_contains($up,'SBR'))return 'Bera SBR';if(str_contains($up,'BRF'))return 'Bera BRF';if(str_contains($up,'X1'))return 'Bera X1';if(str_contains($up,'VELOZ'))return 'Veloz';if(str_contains($up,'SOCIALISTA'))return 'Socialista';if(str_contains($up,'AGUILA'))return 'MD Águila';return $model;
    }

    private function referenceImage(string $model): string
    {
        $up=mb_strtoupper(trim($model));if(str_contains($up,'BRF')||str_contains($up,'SOCIALISTA'))return 'assets/moto-red.png';if(str_contains($up,'LEON')||str_contains($up,'KADI')||str_contains($up,'LOVIS')||str_contains($up,'AGUILA'))return 'assets/moto-black.png';return 'assets/moto-blue.png';
    }

    private function publicRowByAccessCode(string $accessCode): array
    {
        $raw=$this->clean($accessCode,120);
        if($raw==='')throw new InvalidArgumentException('Indica tu código de seguimiento.');
        $candidates=[$raw];
        $upper=mb_strtoupper($raw);
        if($upper!==$raw)$candidates[]=$upper;
        foreach(array_unique($candidates) as $candidate){
            $stmt=$this->pdo->prepare('SELECT * FROM gp_finance_applications WHERE access_token_hash=? AND public_submitted=1 LIMIT 1');
            $stmt->execute([hash('sha256',$candidate)]);
            $row=$stmt->fetch();
            if($row){$row['__verified_access_code']=$candidate;return $row;}
        }
        throw new InvalidArgumentException('No pudimos validar ese código. Revisa que esté escrito exactamente como fue entregado.');
    }

    private function generateContinuationCode(): string
    {
        $alphabet='ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $part=function()use($alphabet){$out='';for($i=0;$i<4;$i++)$out.=$alphabet[random_int(0,strlen($alphabet)-1)];return $out;};
        return 'GP-'.$part().'-'.$part();
    }

    private function publicRow(string $code, string $rawToken): array
    {
        $code=$this->clean($code,40);$rawToken=$this->clean($rawToken,120);
        if($code===''||$rawToken==='')throw new InvalidArgumentException('Código y clave de seguimiento son obligatorios.');
        $stmt=$this->pdo->prepare('SELECT * FROM gp_finance_applications WHERE application_code=? AND public_submitted=1 LIMIT 1');$stmt->execute([$code]);$row=$stmt->fetch();
        if(!$row||!is_string($row['access_token_hash']??null)||!hash_equals((string)$row['access_token_hash'],hash('sha256',$rawToken)))throw new InvalidArgumentException('No fue posible validar el seguimiento de esta solicitud.');
        return $row;
    }

    private function presentPublic(array $row, array $docs): array
    {
        $rawAge=$row['age']??null;$age=$rawAge===null?null:(int)$rawAge;
        if($age===null){$birth=(string)($row['birth_date']??'');if($birth!==''){try{$age=(new DateTimeImmutable($birth))->diff(new DateTimeImmutable('today'))->y;}catch(Throwable){$age=null;}}}
        $requirements=[];
        if($this->hasChecklistTable()){
            $rq=$this->pdo->prepare("SELECT item_key,label,required,status,source,received_at,validated_at,notes FROM gp_finance_application_checklist WHERE application_id=? AND item_group='documents' ORDER BY sort_order,id");
            $rq->execute([(int)$row['id']]);
            foreach($rq->fetchAll() as $r){
                if(in_array((string)$r['item_key'],['identity_front','identity_back'],true))continue;
                $requirements[]=['key'=>(string)$r['item_key'],'label'=>(string)$r['label'],'required'=>(int)$r['required']===1,'status'=>(string)$r['status'],'notes'=>$r['notes']??null,'receivedAt'=>$r['received_at']??null,'validatedAt'=>$r['validated_at']??null];
            }
        }
        $portal=['status'=>'not_created','username'=>null,'activatedAt'=>null,'canActivate'=>false,'loginUrl'=>'../cliente/login.php','formalized'=>!empty($row['formalized_at'])];
        $customerId=(int)($row['portal_customer_id']??0);
        if($customerId>0){
            $q=$this->pdo->prepare('SELECT public_key,status,last_login_at FROM gp_customers WHERE id=? LIMIT 1');$q->execute([$customerId]);$customer=$q->fetch();
            if($customer){$portal['status']=(string)$customer['status'];$portal['username']=(string)$customer['public_key'];$portal['activatedAt']=$row['portal_activated_at']??null;$portal['canActivate']=in_array((string)$row['status'],['approved','delivered'],true)&&(string)$customer['status']==='pending_activation';}
        }
        return [
            'applicationCode'=>(string)$row['application_code'],'applicantName'=>(string)$row['applicant_name'],'age'=>$age,
            'modelRequested'=>$row['model_requested'],'modelRequested2'=>$row['model_requested_2']??null,
            'status'=>(string)$row['status'],'documentsStatus'=>(string)($row['documents_status']??'pending'),'visitStatus'=>(string)($row['visit_status']??'locked'),
            'appointmentStatus'=>(string)($row['appointment_status']??'pending'),'appointmentAt'=>$row['appointment_at']??null,
            'validationNotes'=>$row['validation_notes']??null,'visitNotes'=>$row['visit_notes']??null,'officeNotes'=>$row['office_notes']??null,'deliveredAt'=>$row['delivered_at']??null,
            'portal'=>$portal,
            'requirements'=>$requirements,
            'documents'=>array_map(static fn($d)=>['type'=>$d['doc_type'],'status'=>$d['status'],'uploadedAt'=>$d['uploaded_at']],$docs),
        ];
    }

    private function presentAdminSummary(array $row): array
    {
        $rawAge=$row['age']??null;$age=$rawAge===null?null:(int)$rawAge;if($age===null){$birth=(string)($row['birth_date']??'');if($birth!==''){try{$age=(new DateTimeImmutable($birth))->diff(new DateTimeImmutable('today'))->y;}catch(Throwable){$age=null;}}}
        $row['id']=(int)$row['id'];$row['age']=$age;$row['family_load']=$row['family_load']===null?null:(int)$row['family_load'];$row['monthly_income']=$row['monthly_income']===null?null:(float)$row['monthly_income'];$row['document_count']=(int)($row['document_count']??0);
        unset($row['access_token_hash']);
        return $row;
    }

    private function saveUpload(int $applicationId, string $type, array $file, bool $imageOnly, string $receivedBy='public-web'): string
    {
        $error=(int)($file['error']??UPLOAD_ERR_NO_FILE);
        if($error!==UPLOAD_ERR_OK)throw new InvalidArgumentException('No fue posible cargar uno de los archivos.');
        $size=(int)($file['size']??0);if($size<1||$size>8*1024*1024)throw new InvalidArgumentException('Cada archivo debe pesar máximo 8 MB.');
        $tmp=(string)($file['tmp_name']??'');if($tmp===''||!is_uploaded_file($tmp))throw new InvalidArgumentException('El archivo recibido no es válido.');
        $finfo=new finfo(FILEINFO_MIME_TYPE);$mime=(string)$finfo->file($tmp);
        $allowed=$imageOnly?['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp']:['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','application/pdf'=>'pdf'];
        if(!isset($allowed[$mime]))throw new InvalidArgumentException($imageOnly?'La imagen debe ser JPG, PNG o WEBP.':'Los documentos deben ser PDF, JPG, PNG o WEBP.');
        $dir=$this->uploadRoot.'/'.$applicationId;if(!is_dir($dir)&&!mkdir($dir,0750,true)&&!is_dir($dir))throw new RuntimeException('No fue posible preparar la carpeta de documentos.');
        $name=$type.'-'.bin2hex(random_bytes(12)).'.'.$allowed[$mime];$dest=$dir.'/'.$name;
        if(!move_uploaded_file($tmp,$dest))throw new RuntimeException('No fue posible almacenar el documento.');
        $relative=$applicationId.'/'.$name;
        $stmt=$this->pdo->prepare('INSERT INTO gp_finance_application_documents (application_id,doc_type,original_name,stored_path,mime_type,file_size,status) VALUES (?,?,?,?,?,?,\'review\')');
        $stmt->execute([$applicationId,$type,mb_substr((string)($file['name']??'archivo'),0,255),$relative,$mime,$size]);
        $docId=(int)$this->pdo->lastInsertId();
        $this->ensureChecklist($applicationId);
        $key=$this->checklistKeyForDocType($type);
        if($key!==null&&$this->hasChecklistTable()){
            $this->pdo->prepare("UPDATE gp_finance_application_checklist SET status='received',document_id=?,received_at=NOW(),validated_at=NULL,notes=NULL WHERE application_id=? AND item_key=?")
                ->execute([$docId,$applicationId,$key]);
        }
        $this->addEvent($applicationId,'recaudo_received','Recaudo recibido: '.$this->documentLabel($type,$applicationId).'.',$receivedBy);
        return $relative;
    }

    private function tableExists(string $table): bool
    {
        if(!preg_match('/^[A-Za-z0-9_]+$/',$table))return false;
        try{$stmt=$this->pdo->query("SHOW TABLES LIKE ".$this->pdo->quote($table));return (bool)$stmt->fetchColumn();}
        catch(Throwable){return false;}
    }

    private function hasChecklistTable(): bool
    {
        if($this->checklistAvailable!==null)return $this->checklistAvailable;
        try{$stmt=$this->pdo->query("SHOW TABLES LIKE 'gp_finance_application_checklist'");$this->checklistAvailable=(bool)$stmt->fetchColumn();}
        catch(Throwable){$this->checklistAvailable=false;}
        return $this->checklistAvailable;
    }

    private function syncChecklistFromCurrentData(int $id,array $app): void
    {
        if(!$this->hasChecklistTable())return;
        $docs=$this->pdo->prepare("SELECT id,doc_type,status,uploaded_at,reviewed_at,admin_notes FROM gp_finance_application_documents WHERE application_id=? ORDER BY uploaded_at,id");$docs->execute([$id]);
        foreach($docs->fetchAll() as $doc){
            $key=$this->checklistKeyForDocType((string)$doc['doc_type']);if($key===null)continue;
            $status=(string)$doc['status']==='approved'?'validated':((string)$doc['status']==='rejected'?'observed':'received');
            $validated=$status==='validated'?($doc['reviewed_at']?:$doc['uploaded_at']):null;
            $this->pdo->prepare('UPDATE gp_finance_application_checklist SET status=?,document_id=?,received_at=?,validated_at=?,notes=COALESCE(?,notes) WHERE application_id=? AND item_key=?')
                ->execute([$status,(int)$doc['id'],$doc['uploaded_at'],$validated,$doc['admin_notes']?:null,$id,$key]);
        }
        if(!empty($app['latitude'])&&!empty($app['longitude']))$this->setChecklistState($id,'visit_location',in_array((string)($app['visit_status']??''),['approved'],true)?'validated':'received',$this->eventDate($id,'visit_submitted')?:($app['updated_at']??null),(string)($app['visit_status']??'')==='approved'?($this->eventDate($id,'approve_visit')?:null):null,'Ubicación GPS registrada.');
        if((string)($app['documents_status']??'')==='approved')$this->setChecklistState($id,'documents_validation','validated',$this->eventDate($id,'approve_documents')?:($app['updated_at']??null),$this->eventDate($id,'approve_documents')?:($app['updated_at']??null),$app['validation_notes']??null);
        elseif((string)($app['documents_status']??'')==='rejected')$this->setChecklistState($id,'documents_validation','observed',$this->eventDate($id,'reject_documents')?:($app['updated_at']??null),null,$app['validation_notes']??null);
        if((string)($app['visit_status']??'')==='approved')$this->setChecklistState($id,'visit_validation','validated',$this->eventDate($id,'approve_visit')?:($app['updated_at']??null),$this->eventDate($id,'approve_visit')?:($app['updated_at']??null),$app['visit_notes']??null);
        elseif((string)($app['visit_status']??'')==='rejected')$this->setChecklistState($id,'visit_validation','observed',$this->eventDate($id,'reject_visit')?:($app['updated_at']??null),null,$app['visit_notes']??null);
        if(!empty($app['appointment_at']))$this->setChecklistState($id,'office_appointment',in_array((string)($app['status']??''),['approved','rejected','deferred','delivered'],true)?'validated':'received',(string)$app['appointment_at'],in_array((string)($app['status']??''),['approved','rejected','deferred','delivered'],true)?($this->eventDate($id,'approve')?:$this->eventDate($id,'reject')?:$this->eventDate($id,'defer')):null,$app['office_notes']??null);
        if(in_array((string)($app['status']??''),['approved','rejected','deferred','delivered'],true)){
            $event=(string)$app['status']==='delivered'?'approve':(string)$app['status'];
            $decisionDate=$this->eventDate($id,$event)?:($app['updated_at']??null);
            $this->setChecklistState($id,'credit_decision','validated',$decisionDate,$decisionDate,$app['office_notes']??null);
        }
        $customerId=(int)($app['portal_customer_id']??0);
        if($customerId>0){
            $createdAt=$this->eventDate($id,'customer_created')?:($app['updated_at']??date('Y-m-d H:i:s'));
            $this->setChecklistState($id,'customer_created','validated',$createdAt,$createdAt,'Cliente GRANDPRIX asociado.');
            $q=$this->pdo->prepare('SELECT status FROM gp_customers WHERE id=? LIMIT 1');$q->execute([$customerId]);$customerStatus=(string)($q->fetchColumn()?:'');
            if($customerStatus==='active'){$activated=(string)($app['portal_activated_at']??'');if($activated==='')$activated=$this->eventDate($id,'portal_activated')?:($app['updated_at']??date('Y-m-d H:i:s'));$this->setChecklistState($id,'portal_activated','validated',$activated,$activated,'Cuenta Mi GRANDPRIX activa.');}
            $q=$this->pdo->prepare("SELECT c.id,c.created_at,v.id AS vehicle_id,v.traccar_device_id FROM gp_contracts c INNER JOIN gp_vehicles v ON v.id=c.vehicle_id WHERE c.customer_id=? AND c.status IN ('active','completed') ORDER BY c.id DESC LIMIT 1");$q->execute([$customerId]);$contract=$q->fetch();
            if($contract){$formal=(string)($app['formalized_at']??$contract['created_at']??date('Y-m-d H:i:s'));foreach(['contract_assigned','motorcycle_assigned','plan_generated'] as $key)$this->setChecklistState($id,$key,'validated',$formal,$formal,'Formalización registrada.');if((int)($contract['traccar_device_id']??0)>0)$this->setChecklistState($id,'gps_assigned','validated',$formal,$formal,'GPS asignado a la unidad.');}
        }
        if(!empty($app['delivered_at']))$this->setChecklistState($id,'motorcycle_delivery','validated',(string)$app['delivered_at'],(string)$app['delivered_at'],$app['delivery_notes']??null);
    }

    private function setChecklistState(int $id,string $key,string $status,?string $receivedAt=null,?string $validatedAt=null,?string $notes=null): void
    {
        if(!$this->hasChecklistTable())return;
        $current=$this->pdo->prepare('SELECT received_at,validated_at FROM gp_finance_application_checklist WHERE application_id=? AND item_key=? LIMIT 1');$current->execute([$id,$key]);$row=$current->fetch();if(!$row)return;
        if($receivedAt===null)$receivedAt=$row['received_at']?:null;
        if($validatedAt===null&&$status==='validated')$validatedAt=$row['validated_at']?:date('Y-m-d H:i:s');
        $this->pdo->prepare('UPDATE gp_finance_application_checklist SET status=?,received_at=?,validated_at=?,notes=COALESCE(?,notes) WHERE application_id=? AND item_key=?')
            ->execute([$status,$receivedAt,$validatedAt,$notes?:null,$id,$key]);
    }

    private function validateReceivedDocumentItems(int $id,bool $approved,?string $notes=null): void
    {
        if(!$this->hasChecklistTable())return;
        $status=$approved?'validated':'observed';$validated=$approved?date('Y-m-d H:i:s'):null;
        $this->pdo->prepare("UPDATE gp_finance_application_checklist SET status=?,validated_at=?,notes=COALESCE(?,notes) WHERE application_id=? AND item_group='documents' AND received_at IS NOT NULL")
            ->execute([$status,$validated,$notes?:null,$id]);
    }

    private function checklistKeyForDocType(string $type): ?string
    {
        $map=['identity_card'=>'identity_card','identity_front'=>'identity_front','identity_back'=>'identity_back','income_proof'=>'income_proof','other'=>'other_document','other_document'=>'other_document','facade'=>'facade_photo'];
        if(isset($map[$type]))return $map[$type];
        return preg_match('/^[a-zA-Z0-9_]{1,50}$/',$type)?$type:null;
    }

    private function documentLabel(string $type,int $applicationId=0): string
    {
        $known=['identity_card'=>'Foto de cédula de identidad','identity_front'=>'Cédula frente','identity_back'=>'Cédula reverso','income_proof'=>'Soporte de ingresos','other'=>'Documento adicional','other_document'=>'Documento adicional','facade'=>'Foto de fachada'];
        if(isset($known[$type]))return $known[$type];
        if($applicationId>0&&$this->hasChecklistTable()){
            $q=$this->pdo->prepare('SELECT label FROM gp_finance_application_checklist WHERE application_id=? AND item_key=? LIMIT 1');$q->execute([$applicationId,$type]);$label=$q->fetchColumn();if(is_string($label)&&$label!=='')return $label;
        }
        return $type;
    }

    private function checklistStatusLabel(string $status): string
    {
        return ['pending'=>'Pendiente','received'=>'Recibido','validated'=>'Validado','observed'=>'Observado','not_applicable'=>'No aplica'][$status]??$status;
    }

    private function eventDate(int $id,string $key): ?string
    {
        $stmt=$this->pdo->prepare('SELECT MAX(created_at) FROM gp_finance_application_events WHERE application_id=? AND event_key=?');$stmt->execute([$id,$key]);$v=$stmt->fetchColumn();return $v?((string)$v):null;
    }

    private function parseDateTime(mixed $value): ?string
    {
        $raw=trim((string)($value??''));if($raw==='')return null;
        foreach(['Y-m-d\\TH:i','Y-m-d H:i:s','Y-m-d H:i','Y-m-d'] as $format){$dt=DateTimeImmutable::createFromFormat('!'.$format,$raw);if($dt)return $format==='Y-m-d'?$dt->format('Y-m-d 00:00:00'):$dt->format('Y-m-d H:i:s');}
        return null;
    }

    private function addEvent(int $id,string $key,string $label,string $by):void
    {
        $this->pdo->prepare('INSERT INTO gp_finance_application_events (application_id,event_key,label,created_by) VALUES (?,?,?,?)')->execute([$id,$key,mb_substr($label,0,300),mb_substr($by,0,190)]);
    }

    private function clean(mixed $value,int $length):string{return mb_substr(trim((string)$value),0,$length);}
    private function date(string $value,string $message):string{$dt=DateTimeImmutable::createFromFormat('!Y-m-d',$value);if(!$dt||$dt->format('Y-m-d')!==$value)throw new InvalidArgumentException($message);return $value;}
}
