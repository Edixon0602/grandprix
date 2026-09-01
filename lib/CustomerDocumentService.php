<?php
declare(strict_types=1);

require_once __DIR__ . '/CustomerNotificationService.php';

final class CustomerDocumentService
{
    private string $root;
    public function __construct(private readonly PDO $pdo){$this->root=dirname(__DIR__).'/config/customer-documents';}
    public function ready(): bool{try{return(bool)$this->pdo->query("SHOW TABLES LIKE 'gp_customer_documents'")->fetchColumn();}catch(Throwable){return false;}}
    public function customerIdForAccount(int $accountId): int
    {$q=$this->pdo->prepare('SELECT identity_document,contract_number FROM gp_finance_accounts WHERE id=? LIMIT 1');$q->execute([$accountId]);$a=$q->fetch();if(!$a)return 0;if(!empty($a['contract_number'])){$c=$this->pdo->prepare('SELECT customer_id FROM gp_contracts WHERE contract_number=? ORDER BY id DESC LIMIT 1');$c->execute([(string)$a['contract_number']]);$id=(int)($c->fetchColumn()?:0);if($id)return$id;}if(!empty($a['identity_document'])){$c=$this->pdo->prepare('SELECT id FROM gp_customers WHERE identity_document=? LIMIT 1');$c->execute([(string)$a['identity_document']]);return(int)($c->fetchColumn()?:0);}return 0;}
    public function listForAccount(int $accountId): array{$id=$this->customerIdForAccount($accountId);return$id?$this->listForCustomer($id):[];}
    public function listForCustomer(int $customerId): array
    {if(!$this->ready())return[];$q=$this->pdo->prepare("SELECT id,document_type,label,original_name,mime_type,file_size,uploaded_at,created_by FROM gp_customer_documents WHERE customer_id=? AND status='active' AND visible_to_customer=1 ORDER BY FIELD(document_type,'identity','driver_license','vehicle_registration','vehicle_title','vehicle_insurance','vehicle_other','other'),id DESC");$q->execute([$customerId]);return array_map([$this,'present'],$q->fetchAll());}
    public function listAdmin(int $accountId): array
    {$id=$this->customerIdForAccount($accountId);if(!$id)return[];$q=$this->pdo->prepare("SELECT id,document_type,label,original_name,mime_type,file_size,visible_to_customer,status,uploaded_at,created_by FROM gp_customer_documents WHERE customer_id=? AND status<>'deleted' ORDER BY id DESC");$q->execute([$id]);return array_map([$this,'present'],$q->fetchAll());}
    public function uploadForAccount(int $accountId,array $file,string $type,string $label,string $createdBy,bool $visible=true): array
    {$customerId=$this->customerIdForAccount($accountId);if(!$customerId)throw new InvalidArgumentException('Este expediente todavía no tiene una cuenta Mi GRANDPRIX vinculada. Formaliza/activa primero al cliente.');return $this->upload($customerId,$file,$type,$label,$createdBy,$visible);}
    public function upload(int $customerId,array $file,string $type,string $label,string $createdBy,bool $visible=true): array
    {
        if(!$this->ready())throw new RuntimeException('Ejecuta la actualización V20 antes de gestionar documentos.');
        $allowedTypes=['identity','driver_license','vehicle_registration','vehicle_title','vehicle_insurance','vehicle_other','other'];if(!in_array($type,$allowedTypes,true))$type='other';
        $error=(int)($file['error']??UPLOAD_ERR_NO_FILE);if($error!==UPLOAD_ERR_OK)throw new InvalidArgumentException('No fue posible recibir el documento.');$size=(int)($file['size']??0);if($size<1||$size>10*1024*1024)throw new InvalidArgumentException('Cada documento debe pesar máximo 10 MB.');$tmp=(string)($file['tmp_name']??'');if($tmp===''||!is_uploaded_file($tmp))throw new InvalidArgumentException('El archivo recibido no es válido.');$mime=(string)(new finfo(FILEINFO_MIME_TYPE))->file($tmp);$exts=['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];if(!isset($exts[$mime]))throw new InvalidArgumentException('El documento debe ser PDF, JPG, PNG o WEBP.');
        $dir=$this->root.'/'.$customerId;if(!is_dir($dir)&&!mkdir($dir,0750,true)&&!is_dir($dir))throw new RuntimeException('No fue posible preparar la carpeta privada de documentos.');$name=$type.'-'.bin2hex(random_bytes(12)).'.'.$exts[$mime];$dest=$dir.'/'.$name;if(!move_uploaded_file($tmp,$dest))throw new RuntimeException('No fue posible almacenar el documento.');@chmod($dest,0640);$original=mb_substr((string)($file['name']??'documento'),0,255);$label=trim($label)!==''?mb_substr(trim($label),0,160):$this->defaultLabel($type);
        $this->pdo->prepare("INSERT INTO gp_customer_documents (customer_id,document_type,label,original_name,stored_path,mime_type,file_size,visible_to_customer,status,created_by) VALUES (?,?,?,?,?,?,?,?, 'active',?)")->execute([$customerId,$type,$label,$original,$customerId.'/'.$name,$mime,$size,$visible?1:0,$createdBy]);$id=(int)$this->pdo->lastInsertId();
        if($visible)(new CustomerNotificationService($this->pdo))->create($customerId,'document','Nuevo documento disponible',$label.' ya está disponible en la sección Documentos de Mi GRANDPRIX.','gp_customer_documents',$id);
        return $this->document($id,$customerId,true)??[];
    }
    public function document(int $id,int $customerId,bool $admin=false): ?array
    {$sql='SELECT * FROM gp_customer_documents WHERE id=? AND status=\'active\''.($admin?'':' AND customer_id=? AND visible_to_customer=1').' LIMIT 1';$q=$this->pdo->prepare($sql);$q->execute($admin?[$id]:[$id,$customerId]);$r=$q->fetch();if(!$r)return null;$path=$this->root.'/'.ltrim((string)$r['stored_path'],'/');if(!is_file($path))return null;$x=$this->present($r);$x['path']=$path;return$x;}
    private function defaultLabel(string $type): string{return ['identity'=>'Cédula de identidad','driver_license'=>'Licencia de conducir','vehicle_registration'=>'Certificado / registro de la moto','vehicle_title'=>'Documento de propiedad de la moto','vehicle_insurance'=>'Seguro de la moto','vehicle_other'=>'Otros papeles de la moto','other'=>'Documento del cliente'][$type]??'Documento';}
    private function present(array $r): array{return['id'=>(int)$r['id'],'type'=>(string)$r['document_type'],'label'=>(string)$r['label'],'originalName'=>(string)$r['original_name'],'mime'=>(string)$r['mime_type'],'size'=>(int)$r['file_size'],'visible'=>!isset($r['visible_to_customer'])||(bool)$r['visible_to_customer'],'status'=>$r['status']??'active','uploadedAt'=>$r['uploaded_at']??null,'createdBy'=>$r['created_by']??null];}
}
