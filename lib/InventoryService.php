<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/AdminAuth.php';

final class InventoryService
{
    public function __construct(private readonly PDO $pdo) {}
    public static function create(): self { return new self(Database::connection()); }

    public function ready(): bool
    {
        try { return (bool)$this->pdo->query("SHOW TABLES LIKE 'gp_motorcycle_inventory'")->fetchColumn(); }
        catch (Throwable) { return false; }
    }

    public function list(): array
    {
        if (!$this->ready()) return [];
        $rows=$this->pdo->query("SELECT i.*,fa.full_name AS finance_client,fa.identity_document AS finance_identity,
                    u.full_name AS portal_client,u.identity_document AS portal_identity,c.contract_number
             FROM gp_motorcycle_inventory i
             LEFT JOIN gp_finance_accounts fa ON fa.id=i.current_finance_account_id
             LEFT JOIN gp_customers u ON u.id=i.current_customer_id
             LEFT JOIN gp_contracts c ON c.id=i.current_contract_id
             WHERE i.status<>'archived'
             ORDER BY FIELD(i.status,'assigned','reserved','maintenance','available'),i.plate,i.id")->fetchAll();
        return array_map([$this,'present'],$rows);
    }

    public function item(int $id): ?array
    {
        if(!$this->ready())return null;
        $q=$this->pdo->prepare("SELECT i.*,fa.full_name AS finance_client,fa.identity_document AS finance_identity,u.full_name AS portal_client,u.identity_document AS portal_identity,c.contract_number
            FROM gp_motorcycle_inventory i LEFT JOIN gp_finance_accounts fa ON fa.id=i.current_finance_account_id LEFT JOIN gp_customers u ON u.id=i.current_customer_id LEFT JOIN gp_contracts c ON c.id=i.current_contract_id WHERE i.id=? LIMIT 1");
        $q->execute([$id]);$row=$q->fetch();return $row?$this->present($row):null;
    }

    public function save(array $input,array $actor): array
    {
        if(!$this->ready())throw new RuntimeException('Ejecuta la actualización V21 antes de usar Inventario.');
        $id=max(0,(int)($input['id']??0));
        $code=mb_strtoupper(mb_substr(trim((string)($input['inventoryCode']??'')),0,40));
        $plate=$this->normalizePlate((string)($input['plate']??''));
        $brand=mb_substr(trim((string)($input['brand']??'')),0,80);
        $model=mb_substr(trim((string)($input['model']??'')),0,120);
        $engineCc=mb_substr(trim((string)($input['engineCc']??'')),0,40);
        $color=mb_substr(trim((string)($input['color']??'')),0,80);
        $chassis=mb_substr(trim((string)($input['chassisSerial']??'')),0,100);
        $engineSerial=mb_substr(trim((string)($input['engineSerial']??'')),0,100);
        $yearRaw=$input['year']??null;$year=$yearRaw===''||$yearRaw===null?null:filter_var($yearRaw,FILTER_VALIDATE_INT,['options'=>['min_range'=>2000,'max_range'=>2100]]);
        $gpsRaw=$input['gpsDeviceId']??null;$gps=$gpsRaw===''||$gpsRaw===null?null:filter_var($gpsRaw,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $gpsUnique=mb_substr(trim((string)($input['gpsUniqueId']??'')),0,120);
        $gpsLabel=mb_substr(trim((string)($input['gpsLabel']??'')),0,160);
        $notes=mb_substr(trim((string)($input['notes']??'')),0,1000);
        $status=trim((string)($input['status']??'available'));
        if($plate==='')throw new InvalidArgumentException('La placa es obligatoria en el inventario.');
        if($model==='')throw new InvalidArgumentException('El modelo de la motocicleta es obligatorio.');
        if($year===false)throw new InvalidArgumentException('El año de la motocicleta no es válido.');
        if($gps===false)throw new InvalidArgumentException('El GPS Device ID no es válido.');
        $gps=$gps===null?null:(int)$gps;
        if(!in_array($status,['available','maintenance','reserved','assigned'],true))$status='available';
        $dup=$this->pdo->prepare('SELECT id,plate FROM gp_motorcycle_inventory WHERE plate=? AND id<>? LIMIT 1');$dup->execute([$plate,$id]);if($dup->fetch())throw new InvalidArgumentException('La placa '.$plate.' ya existe y está bloqueada en el inventario.');
        if($gps!==null){$dup=$this->pdo->prepare('SELECT id,plate FROM gp_motorcycle_inventory WHERE gps_device_id=? AND id<>? LIMIT 1');$dup->execute([$gps,$id]);if($other=$dup->fetch())throw new InvalidArgumentException('Ese GPS ya pertenece a la motocicleta con placa '.(string)$other['plate'].'.');}
        if($gpsUnique!==''){$dup=$this->pdo->prepare("SELECT id,plate FROM gp_motorcycle_inventory WHERE gps_unique_id=? AND id<>? AND gps_unique_id IS NOT NULL LIMIT 1");$dup->execute([$gpsUnique,$id]);if($other=$dup->fetch())throw new InvalidArgumentException('Ese IMEI/Unique ID de Traccar ya pertenece a la placa '.(string)$other['plate'].'.');}
        if($code==='')$code='INV-'.str_pad((string)($id?:((int)$this->pdo->query('SELECT COALESCE(MAX(id),0)+1 FROM gp_motorcycle_inventory')->fetchColumn())),5,'0',STR_PAD_LEFT);
        $dup=$this->pdo->prepare('SELECT id FROM gp_motorcycle_inventory WHERE inventory_code=? AND id<>? LIMIT 1');$dup->execute([$code,$id]);if($dup->fetch())throw new InvalidArgumentException('Ese código interno ya existe.');
        $before=[];
        if($id>0){
            $q=$this->pdo->prepare('SELECT * FROM gp_motorcycle_inventory WHERE id=? FOR UPDATE');$q->execute([$id]);$before=$q->fetch()?:[];
            if(!$before)throw new InvalidArgumentException('La motocicleta no existe.');
            if($plate!==$this->normalizePlate((string)$before['plate']))throw new InvalidArgumentException('La placa pertenece a la unidad física y está bloqueada. No se modifica; archiva la unidad solo si fue creada por error.');
            if(!empty($before['current_finance_account_id']) && !empty($before['gps_device_id']) && $gps===null)throw new InvalidArgumentException('No puedes retirar el GPS de una motocicleta asignada a un cliente activo. Puedes reemplazarlo por otro GPS libre de Traccar.');
        }
        if($id>0){
            $this->pdo->prepare("UPDATE gp_motorcycle_inventory SET inventory_code=?,plate=?,brand=?,model=?,engine_cc=?,color=?,model_year=?,chassis_serial=?,engine_serial=?,gps_device_id=?,gps_unique_id=?,gps_label=?,status=CASE WHEN current_finance_account_id IS NOT NULL THEN 'assigned' ELSE ? END,notes=?,plate_locked=1 WHERE id=?")
                ->execute([$code,$plate,$brand?:null,$model,$engineCc?:null,$color?:null,$year,$chassis?:null,$engineSerial?:null,$gps,$gpsUnique?:null,$gpsLabel?:null,$status,$notes?:null,$id]);
        } else {
            $this->pdo->prepare("INSERT INTO gp_motorcycle_inventory (inventory_code,plate,brand,model,engine_cc,color,model_year,chassis_serial,engine_serial,gps_device_id,gps_unique_id,gps_label,status,notes,plate_locked,source_key) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,'manual')")
                ->execute([$code,$plate,$brand?:null,$model,$engineCc?:null,$color?:null,$year,$chassis?:null,$engineSerial?:null,$gps,$gpsUnique?:null,$gpsLabel?:null,$status,$notes?:null]);
            $id=(int)$this->pdo->lastInsertId();
        }
        $this->propagateGps($id,$gps,$gpsUnique,$gpsLabel);
        AdminAuth::audit($this->pdo,$actor,'inventory',$before?'update_vehicle_inventory':'create_vehicle_inventory','gp_motorcycle_inventory',$id,$before,$this->raw($id));
        return $this->item($id)??[];
    }

    /**
     * Reconstruye el inventario a partir de las placas reales de cartera y contratos.
     * No borra unidades ni reasigna una placa que ya pertenece a otro cliente activo.
     */
    public function syncRealData(array $actor): array
    {
        if(!$this->ready())throw new RuntimeException('El inventario todavía no está instalado.');
        $created=0;$updated=0;$linked=0;$conflicts=[];
        $this->pdo->exec("UPDATE gp_finance_accounts SET plate=UPPER(REPLACE(plate,' ','')) WHERE plate IS NOT NULL AND TRIM(plate)<>''");
        if($this->tableExists('gp_vehicles'))$this->pdo->exec("UPDATE gp_vehicles SET plate=UPPER(REPLACE(plate,' ','')) WHERE plate IS NOT NULL AND TRIM(plate)<>''");

        if($this->tableExists('gp_vehicles')){
            $hasColor=$this->columnExists('gp_vehicles','color');
            $hasUnique=$this->columnExists('gp_vehicles','traccar_unique_id');
            $sql="SELECT id,code,plate,model,".($hasColor?'color':'NULL AS color').",traccar_device_id,".($hasUnique?'traccar_unique_id':'NULL AS traccar_unique_id').",status FROM gp_vehicles WHERE plate IS NOT NULL AND TRIM(plate)<>'' ORDER BY id";
            foreach($this->pdo->query($sql)->fetchAll() as $v){
                $plate=$this->normalizePlate((string)$v['plate']);if($plate==='')continue;
                $q=$this->pdo->prepare('SELECT id FROM gp_motorcycle_inventory WHERE plate=? LIMIT 1');$q->execute([$plate]);$iid=(int)($q->fetchColumn()?:0);
                if(!$iid){
                    try{$this->pdo->prepare("INSERT INTO gp_motorcycle_inventory (inventory_code,plate,model,color,gps_device_id,gps_unique_id,gps_label,status,vehicle_id,plate_locked,source_key) VALUES (?,?,?,?,?,?,?,?,?,1,'real_data')")
                        ->execute([(string)($v['code']?:'INV-'.str_pad((string)$v['id'],5,'0',STR_PAD_LEFT)),$plate,(string)($v['model']?:'Motocicleta'),$v['color']?:null,!empty($v['traccar_device_id'])?(int)$v['traccar_device_id']:null,$v['traccar_unique_id']?:null,null,'available',(int)$v['id']]);$iid=(int)$this->pdo->lastInsertId();$created++;}
                    catch(Throwable $e){$conflicts[]=$plate.': '.$e->getMessage();continue;}
                }else{
                    $this->pdo->prepare("UPDATE gp_motorcycle_inventory SET vehicle_id=COALESCE(vehicle_id,?),gps_device_id=COALESCE(gps_device_id,?),gps_unique_id=COALESCE(gps_unique_id,?),model=CASE WHEN model='' THEN ? ELSE model END,color=COALESCE(color,?),plate_locked=1,source_key='real_data' WHERE id=?")
                        ->execute([(int)$v['id'],!empty($v['traccar_device_id'])?(int)$v['traccar_device_id']:null,$v['traccar_unique_id']?:null,(string)($v['model']?:'Motocicleta'),$v['color']?:null,$iid]);$updated++;
                }
            }
        }

        $accounts=$this->pdo->query("SELECT id,full_name,plate,model,gps_device_id,gps_label,contract_number,record_status FROM gp_finance_accounts WHERE plate IS NOT NULL AND TRIM(plate)<>'' ORDER BY record_status='archived',id")->fetchAll();
        foreach($accounts as $a){
            $plate=$this->normalizePlate((string)$a['plate']);if($plate==='')continue;
            $q=$this->pdo->prepare('SELECT * FROM gp_motorcycle_inventory WHERE plate=? LIMIT 1');$q->execute([$plate]);$inv=$q->fetch()?:null;
            if(!$inv){
                try{$this->pdo->prepare("INSERT INTO gp_motorcycle_inventory (inventory_code,plate,model,gps_device_id,gps_label,status,current_finance_account_id,plate_locked,source_key) VALUES (?,?,?,?,?,?,?,1,'real_data')")
                    ->execute(['INV-'.str_pad((string)$a['id'],5,'0',STR_PAD_LEFT),$plate,(string)($a['model']?:'Motocicleta'),$a['gps_device_id']?:null,$a['gps_label']?:null,$a['record_status']==='archived'?'available':'assigned',$a['record_status']==='archived'?null:(int)$a['id']]);$created++;$linked+=($a['record_status']!=='archived'?1:0);}
                catch(Throwable $e){$conflicts[]=$plate.': '.$e->getMessage();}
                continue;
            }
            if($a['record_status']!=='archived'){
                $owner=(int)($inv['current_finance_account_id']??0);
                if($owner>0&&$owner!==(int)$a['id']){
                    $o=$this->pdo->prepare("SELECT full_name,record_status FROM gp_finance_accounts WHERE id=? LIMIT 1");$o->execute([$owner]);$ownerRow=$o->fetch();
                    if($ownerRow&&$ownerRow['record_status']!=='archived'){$conflicts[]='Placa '.$plate.' aparece en dos clientes activos: '.(string)$ownerRow['full_name'].' y '.(string)$a['full_name'];continue;}
                }
                $this->pdo->prepare("UPDATE gp_motorcycle_inventory SET current_finance_account_id=?,status='assigned',gps_device_id=COALESCE(gps_device_id,?),gps_label=COALESCE(gps_label,?),model=CASE WHEN model='' THEN ? ELSE model END,plate_locked=1,source_key='real_data' WHERE id=?")
                    ->execute([(int)$a['id'],$a['gps_device_id']?:null,$a['gps_label']?:null,(string)($a['model']?:'Motocicleta'),(int)$inv['id']]);$linked++;
            } else {
                $this->pdo->prepare("UPDATE gp_motorcycle_inventory SET plate_locked=1,source_key='real_data' WHERE id=?")->execute([(int)$inv['id']]);
            }
        }

        if($this->tableExists('gp_contracts')&&$this->tableExists('gp_customers')&&$this->tableExists('gp_vehicles')){
            $links=$this->pdo->query("SELECT c.id contract_id,c.customer_id,c.vehicle_id,c.contract_number,v.plate,v.traccar_device_id,u.full_name FROM gp_contracts c INNER JOIN gp_vehicles v ON v.id=c.vehicle_id INNER JOIN gp_customers u ON u.id=c.customer_id WHERE c.status='active' AND v.plate IS NOT NULL AND TRIM(v.plate)<>''")->fetchAll();
            foreach($links as $l){$plate=$this->normalizePlate((string)$l['plate']);$q=$this->pdo->prepare('SELECT id,current_finance_account_id FROM gp_motorcycle_inventory WHERE plate=? LIMIT 1');$q->execute([$plate]);$inv=$q->fetch();if(!$inv)continue;$fa=$this->pdo->prepare("SELECT id FROM gp_finance_accounts WHERE contract_number=? AND record_status<>'archived' ORDER BY id DESC LIMIT 1");$fa->execute([(string)$l['contract_number']]);$fid=(int)($fa->fetchColumn()?:0);$this->pdo->prepare("UPDATE gp_motorcycle_inventory SET status='assigned',current_finance_account_id=COALESCE(?,current_finance_account_id),current_customer_id=?,current_contract_id=?,vehicle_id=?,gps_device_id=COALESCE(gps_device_id,?),plate_locked=1,source_key='real_data' WHERE id=?")->execute([$fid?:null,(int)$l['customer_id'],(int)$l['contract_id'],(int)$l['vehicle_id'],!empty($l['traccar_device_id'])?(int)$l['traccar_device_id']:null,(int)$inv['id']]);}
        }
        try{AdminAuth::audit($this->pdo,$actor,'inventory','sync_real_inventory','gp_motorcycle_inventory',0,[],['created'=>$created,'updated'=>$updated,'linked'=>$linked,'conflicts'=>count($conflicts)]);}catch(Throwable){}
        return ['created'=>$created,'updated'=>$updated,'linked'=>$linked,'conflicts'=>array_values(array_unique($conflicts)),'total'=>count($this->list())];
    }

    public function claimForFinanceAccount(int $financeAccountId,string $plate,?int $gpsId,string $model,array $actor): ?array
    {
        if(!$this->ready()||trim($plate)==='')return null;
        $plate=$this->normalizePlate($plate);
        $byPlate=$this->pdo->prepare("SELECT * FROM gp_motorcycle_inventory WHERE plate=? AND status<>'archived' LIMIT 1");$byPlate->execute([$plate]);$unit=$byPlate->fetch()?:null;
        if($gpsId){$byGps=$this->pdo->prepare("SELECT * FROM gp_motorcycle_inventory WHERE gps_device_id=? AND status<>'archived' LIMIT 1");$byGps->execute([$gpsId]);$gpsUnit=$byGps->fetch()?:null;if($unit&&$gpsUnit&&(int)$unit['id']!==(int)$gpsUnit['id'])throw new InvalidArgumentException('La placa y el GPS pertenecen a motocicletas diferentes del inventario.');if(!$unit)$unit=$gpsUnit;}
        if(!$unit){$this->pdo->prepare("INSERT INTO gp_motorcycle_inventory (inventory_code,plate,model,gps_device_id,status,current_finance_account_id,plate_locked,source_key) VALUES (?,?,?,?, 'assigned',?,1,'finance')")->execute(['INV-'.str_pad((string)((int)$this->pdo->query('SELECT COALESCE(MAX(id),0)+1 FROM gp_motorcycle_inventory')->fetchColumn()),5,'0',STR_PAD_LEFT),$plate,$model?:'Motocicleta',$gpsId,$financeAccountId]);$id=(int)$this->pdo->lastInsertId();$this->history($id,$financeAccountId,null,null,'assigned','Asignación creada desde Clientes y créditos.',(string)($actor['email']??'admin'));return $this->item($id);}
        $owner=(int)($unit['current_finance_account_id']??0);
        if($owner>0&&$owner!==$financeAccountId){$q=$this->pdo->prepare("SELECT full_name,record_status FROM gp_finance_accounts WHERE id=? LIMIT 1");$q->execute([$owner]);$other=$q->fetch();if($other&&$other['record_status']!=='archived')throw new InvalidArgumentException('La placa '.$plate.' ya está asignada a '.(string)$other['full_name'].'. Debes archivar y desactivar primero ese cliente para liberar la motocicleta.');}
        if($gpsId!==null&&$unit['gps_device_id']!==null&&(int)$unit['gps_device_id']!==$gpsId)throw new InvalidArgumentException('La placa '.$plate.' ya tiene otro GPS asociado en Inventario. Edita el GPS desde Inventario si se trata de un reemplazo físico.');
        $this->pdo->prepare("UPDATE gp_motorcycle_inventory SET model=CASE WHEN ?<>'' THEN ? ELSE model END,gps_device_id=COALESCE(?,gps_device_id),status='assigned',current_finance_account_id=?,plate_locked=1 WHERE id=?")->execute([$model,$model,$gpsId,$financeAccountId,(int)$unit['id']]);
        if($owner!==$financeAccountId)$this->history((int)$unit['id'],$financeAccountId,null,null,'assigned','Asignada desde Clientes y créditos.',(string)($actor['email']??'admin'));
        return $this->item((int)$unit['id']);
    }

    public function syncPortalAssignment(int $financeAccountId,int $customerId,int $contractId,int $vehicleId,string $plate,?int $gpsId,string $model,array $actor): void
    {
        $unit=$this->claimForFinanceAccount($financeAccountId,$plate,$gpsId,$model,$actor);if(!$unit)return;
        $id=(int)$unit['id'];
        $currentCustomer=(int)($unit['currentCustomerId']??0);
        if($currentCustomer>0&&$currentCustomer!==$customerId){$q=$this->pdo->prepare('SELECT full_name,status FROM gp_customers WHERE id=? LIMIT 1');$q->execute([$currentCustomer]);$other=$q->fetch();if($other&&$other['status']==='active')throw new InvalidArgumentException('La motocicleta sigue asociada al portal de '.(string)$other['full_name'].'. Debes archivar/desactivar ese cliente antes de reasignarla.');}
        $this->pdo->prepare("UPDATE gp_motorcycle_inventory SET status='assigned',current_finance_account_id=?,current_customer_id=?,current_contract_id=?,vehicle_id=?,plate_locked=1 WHERE id=?")->execute([$financeAccountId,$customerId,$contractId,$vehicleId,$id]);
        $this->history($id,$financeAccountId,$customerId,$contractId,'portal_assigned','Asignación formalizada en Mi GRANDPRIX.',(string)($actor['email']??'admin'));
    }

    public function releaseFinanceAccount(int $financeAccountId,string $reason,array $actor): void
    {
        if(!$this->ready())return;
        $q=$this->pdo->prepare('SELECT * FROM gp_motorcycle_inventory WHERE current_finance_account_id=? LIMIT 1');$q->execute([$financeAccountId]);$unit=$q->fetch();
        $a=$this->pdo->prepare('SELECT * FROM gp_finance_accounts WHERE id=? LIMIT 1');$a->execute([$financeAccountId]);$account=$a->fetch()?:[];
        $customerId=(int)($unit['current_customer_id']??0);$contractId=(int)($unit['current_contract_id']??0);
        if($contractId>0)$this->pdo->prepare("UPDATE gp_contracts SET status='archived' WHERE id=? AND status='active'")->execute([$contractId]);
        elseif(!empty($account['contract_number'])){$c=$this->pdo->prepare("SELECT id,customer_id FROM gp_contracts WHERE contract_number=? AND status='active' LIMIT 1");$c->execute([(string)$account['contract_number']]);$cr=$c->fetch();if($cr){$contractId=(int)$cr['id'];$customerId=(int)$cr['customer_id'];$this->pdo->prepare("UPDATE gp_contracts SET status='archived' WHERE id=?")->execute([$contractId]);}}
        if($customerId===0&&!empty($account['identity_document'])){$c=$this->pdo->prepare('SELECT id FROM gp_customers WHERE identity_document=? LIMIT 1');$c->execute([(string)$account['identity_document']]);$customerId=(int)($c->fetchColumn()?:0);}
        if($customerId>0)$this->pdo->prepare("UPDATE gp_customers SET status='archived',archived_at=NOW(),archived_reason=? WHERE id=?")->execute([$reason,$customerId]);
        if($unit){$this->pdo->prepare("UPDATE gp_motorcycle_inventory SET current_finance_account_id=NULL,current_customer_id=NULL,current_contract_id=NULL,status='available',released_at=NOW(),release_reason=? WHERE id=?")->execute([$reason,(int)$unit['id']]);$this->history((int)$unit['id'],$financeAccountId,$customerId?:null,$contractId?:null,'released',$reason,(string)($actor['email']??'admin'));}
    }

    private function propagateGps(int $inventoryId,?int $gpsId,string $gpsUnique,string $gpsLabel): void
    {
        $q=$this->pdo->prepare('SELECT current_finance_account_id,vehicle_id,current_contract_id FROM gp_motorcycle_inventory WHERE id=? LIMIT 1');$q->execute([$inventoryId]);$u=$q->fetch()?:[];
        $financeId=(int)($u['current_finance_account_id']??0);if($financeId>0)$this->pdo->prepare('UPDATE gp_finance_accounts SET gps_device_id=?,gps_label=? WHERE id=?')->execute([$gpsId,$gpsLabel?:null,$financeId]);
        $vehicleId=(int)($u['vehicle_id']??0);
        if($vehicleId===0&&(int)($u['current_contract_id']??0)>0){$c=$this->pdo->prepare('SELECT vehicle_id FROM gp_contracts WHERE id=? LIMIT 1');$c->execute([(int)$u['current_contract_id']]);$vehicleId=(int)($c->fetchColumn()?:0);}
        if($vehicleId>0&&$this->tableExists('gp_vehicles')){
            if($this->columnExists('gp_vehicles','traccar_unique_id'))$this->pdo->prepare('UPDATE gp_vehicles SET traccar_device_id=?,traccar_unique_id=? WHERE id=?')->execute([$gpsId,$gpsUnique?:null,$vehicleId]);
            else $this->pdo->prepare('UPDATE gp_vehicles SET traccar_device_id=? WHERE id=?')->execute([$gpsId,$vehicleId]);
        }
    }

    private function history(int $inventoryId,?int $financeId,?int $customerId,?int $contractId,string $event,string $notes,string $by): void
    {
        try{$this->pdo->prepare('INSERT INTO gp_vehicle_assignment_history (inventory_id,finance_account_id,customer_id,contract_id,event_key,notes,created_by) VALUES (?,?,?,?,?,?,?)')->execute([$inventoryId,$financeId,$customerId,$contractId,$event,$notes,$by]);}catch(Throwable){}
    }
    private function raw(int $id): array{$q=$this->pdo->prepare('SELECT * FROM gp_motorcycle_inventory WHERE id=?');$q->execute([$id]);return $q->fetch()?:[];}
    private function normalizePlate(string $plate): string{return mb_strtoupper(preg_replace('/\s+/u','',trim($plate))??'');}
    private function tableExists(string $table): bool{try{$q=$this->pdo->query('SHOW TABLES LIKE '.$this->pdo->quote($table));return (bool)$q->fetchColumn();}catch(Throwable){return false;}}
    private function columnExists(string $table,string $column): bool{try{$q=$this->pdo->query('SHOW COLUMNS FROM `'.str_replace('`','',$table).'` LIKE '.$this->pdo->quote($column));return (bool)$q->fetch();}catch(Throwable){return false;}}
    private function present(array $r): array{return [
        'id'=>(int)$r['id'],'inventoryCode'=>(string)$r['inventory_code'],'plate'=>(string)$r['plate'],'brand'=>$r['brand']??null,'model'=>(string)$r['model'],'engineCc'=>$r['engine_cc']??null,'color'=>$r['color'],'year'=>$r['model_year']===null?null:(int)$r['model_year'],'chassisSerial'=>$r['chassis_serial']??null,'engineSerial'=>$r['engine_serial']??null,'gpsDeviceId'=>$r['gps_device_id']===null?null:(int)$r['gps_device_id'],'gpsUniqueId'=>$r['gps_unique_id']??null,'gpsLabel'=>$r['gps_label']??null,'plateLocked'=>isset($r['plate_locked'])?(bool)$r['plate_locked']:true,'sourceKey'=>$r['source_key']??null,'status'=>(string)$r['status'],'financeAccountId'=>$r['current_finance_account_id']===null?null:(int)$r['current_finance_account_id'],'currentCustomerId'=>$r['current_customer_id']===null?null:(int)$r['current_customer_id'],'currentContractId'=>$r['current_contract_id']===null?null:(int)$r['current_contract_id'],'vehicleId'=>$r['vehicle_id']===null?null:(int)$r['vehicle_id'],'financeClient'=>$r['finance_client']??null,'portalClient'=>$r['portal_client']??null,'contractNumber'=>$r['contract_number']??null,'notes'=>$r['notes']??null,'releasedAt'=>$r['released_at']??null,'releaseReason'=>$r['release_reason']??null,'createdAt'=>$r['created_at']??null,'updatedAt'=>$r['updated_at']??null,
    ];}
}
