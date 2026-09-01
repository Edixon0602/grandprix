<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/Database.php';
require_once dirname(__DIR__) . '/lib/AdminAuth.php';
require_once dirname(__DIR__) . '/lib/FinanceService.php';
require_once dirname(__DIR__) . '/lib/PublicApplicationService.php';
require_once dirname(__DIR__) . '/lib/PaymentReceiptService.php';
require_once dirname(__DIR__) . '/lib/ReceiptRenderer.php';
require_once dirname(__DIR__) . '/lib/ReceiptPdfRenderer.php';
require_once dirname(__DIR__) . '/lib/EventAudit.php';
require_once dirname(__DIR__) . '/lib/AlertCenter.php';
require_once dirname(__DIR__) . '/lib/CustomerDocumentService.php';
require_once dirname(__DIR__) . '/lib/InventoryService.php';
require_once dirname(__DIR__) . '/lib/ClientDossierService.php';

gp_start_session();
gp_require_admin(true);
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
if (!Database::configured()) gp_json(['ok'=>false,'error'=>'La base de datos no esta configurada.'],503);
$action = strtolower(trim((string) ($_GET['action'] ?? 'overview')));
$service = FinanceService::create();
$applicationService = PublicApplicationService::create();
$actor = gp_current_admin();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if ($action === 'overview') {
            if (!gp_user_can('finance.view') && !gp_user_can('finance.clients.view') && !gp_user_can('finance.payments.view')) {
                gp_json(['ok'=>false,'error'=>'No tienes permiso para consultar Finanzas.'],403);
            }
            $data = $service->overview();
            if (!gp_user_can('finance.gps.assign')) $data['gpsDevices'] = [];
            if (!gp_user_can('finance.payments.view') && !gp_user_can('finance.view')) { $data['payments'] = []; $data['paymentAnalytics'] = null; }
            if (!gp_user_can('finance.clients.view') && !gp_user_can('finance.view') && !gp_user_can('finance.payments.create')) $data['accounts'] = [];
            if (!gp_user_can('finance.view')) $data['referrers'] = [];
            gp_json(['ok'=>true,'version'=>'30.0.0'] + $data);
        }
        if ($action === 'payment-analytics') {
            if (!gp_user_can('finance.payments.view') && !gp_user_can('finance.view')) {
                gp_json(['ok'=>false,'error'=>'No tienes permiso para consultar la analítica de pagos.'],403);
            }
            $preset=trim((string)($_GET['preset']??'month'));
            $from=trim((string)($_GET['from']??''));
            $to=trim((string)($_GET['to']??''));
            gp_json(['ok'=>true,'analytics'=>$service->paymentAnalytics($from!==''?$from:null,$to!==''?$to:null,$preset,true)]);
        }
        if ($action === 'applications') {
            gp_require_permission('finance.applications.view', true);
            gp_json(['ok'=>true,'applications'=>$applicationService->adminList()]);
        }
        if ($action === 'application-detail') {
            gp_require_permission('finance.applications.view', true);
            $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            if ($id === false) throw new InvalidArgumentException('Solicitud inválida.');
            $application = $applicationService->adminDetail((int)$id);
            if (!$application) gp_json(['ok'=>false,'error'=>'Solicitud no encontrada.'],404);
            EventAudit::recordAdmin($actor,'applications','application_detail_view','view','gp_finance_applications',(int)$id,'Abrió el expediente de una solicitud.');
            gp_json(['ok'=>true,'application'=>$application]);
        }
        if ($action === 'application-formalization-data') {
            gp_require_permission('finance.applications.view', true);
            $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            if ($id === false) throw new InvalidArgumentException('Solicitud inválida.');
            $application = $applicationService->adminDetail((int)$id);
            if (!$application) gp_json(['ok'=>false,'error'=>'Solicitud no encontrada.'],404);
            $devices = gp_user_can('finance.gps.assign') ? $service->gpsDevices() : [];
            gp_json(['ok'=>true,'application'=>$application,'gpsDevices'=>$devices]);
        }
        if ($action === 'application-file') {
            gp_require_permission('finance.applications.view', true);
            $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            if ($id === false) throw new InvalidArgumentException('Documento inválido.');
            $file = $applicationService->documentFile((int)$id);
            if (!$file) gp_json(['ok'=>false,'error'=>'Documento no encontrado.'],404);
            header_remove('Content-Security-Policy');
            header('Content-Type: '.(string)$file['mime_type']);
            header('Content-Length: '.(string)filesize((string)$file['absolute_path']));
            header('Content-Disposition: inline; filename*=UTF-8\'\''.rawurlencode((string)$file['original_name']));
            header('X-Content-Type-Options: nosniff');
            EventAudit::recordAdmin($actor,'applications','application_document_view','view','gp_finance_application_documents',(int)$id,'Abrió un documento del expediente.',['filename'=>(string)$file['original_name']]);
            readfile((string)$file['absolute_path']); exit;
        }
        if ($action === 'account') {
            if (!gp_user_can('finance.clients.view') && !gp_user_can('finance.view')) {
                gp_json(['ok'=>false,'error'=>'No tienes permiso para consultar estados de cuenta.'],403);
            }
            $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            if ($id === false) throw new InvalidArgumentException('Cliente invalido.');
            $account = $service->account((int)$id);
            if (!$account) gp_json(['ok'=>false,'error'=>'Cliente no encontrado.'],404);
            EventAudit::recordAdmin($actor,'finance','account_view','view','gp_finance_accounts',(int)$id,'Abrió el expediente financiero de un cliente.');
            gp_json(['ok'=>true,'account'=>$account]);
        }
        if ($action === 'dossier-summaries') {
            if(!gp_user_can('finance.clients.view')&&!gp_user_can('finance.view'))gp_json(['ok'=>false,'error'=>'No tienes permiso para consultar expedientes.'],403);
            $dossier=new ClientDossierService(Database::connection());
            gp_json(['ok'=>true,'ready'=>$dossier->ready(),'summaries'=>$dossier->summaries()]);
        }
        if ($action === 'client-dossier') {
            if(!gp_user_can('finance.clients.view')&&!gp_user_can('finance.view'))gp_json(['ok'=>false,'error'=>'No tienes permiso para consultar el expediente documental.'],403);
            $accountId=filter_var($_GET['accountId']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if($accountId===false)throw new InvalidArgumentException('Cliente inválido.');
            $dossier=(new ClientDossierService(Database::connection()))->detail((int)$accountId,true);
            EventAudit::recordAdmin($actor,'clients','client_dossier_view','view','gp_finance_accounts',(int)$accountId,'Abrió el expediente documental 360 del cliente.');
            gp_json(['ok'=>true,'dossier'=>$dossier]);
        }
        if ($action === 'dossier-file') {
            if(!gp_user_can('finance.clients.view')&&!gp_user_can('finance.view'))gp_json(['ok'=>false,'error'=>'No tienes permiso para abrir documentos del expediente.'],403);
            $id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if($id===false)throw new InvalidArgumentException('Documento inválido.');
            $file=(new ClientDossierService(Database::connection()))->downloadable((int)$id);if(!$file)gp_json(['ok'=>false,'error'=>'Documento no encontrado.'],404);
            header_remove('Content-Security-Policy');
            header('Content-Type: '.(string)$file['mime']);
            header('Content-Length: '.(string)filesize((string)$file['path']));
            $disposition=($_GET['download']??'0')==='1'?'attachment':'inline';
            header('Content-Disposition: '.$disposition.'; filename*=UTF-8\'\''.rawurlencode((string)$file['originalName']));
            header('Cache-Control: no-store, private');
            header('X-Content-Type-Options: nosniff');
            EventAudit::recordAdmin($actor,'clients','client_dossier_file_view','view','gp_client_dossier_files',(int)$id,'Abrió un documento del expediente del cliente.',['label'=>$file['label']??'']);
            readfile((string)$file['path']);exit;
        }
        if ($action === 'inventory') {
            if(!gp_user_can('gps.vehicles.view')&&!gp_user_can('finance.clients.view')&&!gp_user_can('finance.view'))gp_json(['ok'=>false,'error'=>'No tienes permiso para consultar Inventario.'],403);
            $inventory=new InventoryService(Database::connection());
            gp_json(['ok'=>true,'units'=>$inventory->list()]);
        }
        if ($action === 'customer-documents') {
            if(!gp_user_can('finance.clients.view')&&!gp_user_can('finance.view'))gp_json(['ok'=>false,'error'=>'No tienes permiso para consultar documentos del cliente.'],403);
            $accountId=filter_var($_GET['accountId']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if($accountId===false)throw new InvalidArgumentException('Cliente inválido.');
            $docs=new CustomerDocumentService(Database::connection());
            gp_json(['ok'=>true,'documents'=>$docs->listAdmin((int)$accountId),'portalCustomerId'=>$docs->customerIdForAccount((int)$accountId)]);
        }
        if ($action === 'customer-document-file') {
            if(!gp_user_can('finance.clients.view')&&!gp_user_can('finance.view'))gp_json(['ok'=>false,'error'=>'No tienes permiso para abrir documentos del cliente.'],403);
            $id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if($id===false)throw new InvalidArgumentException('Documento inválido.');
            $doc=(new CustomerDocumentService(Database::connection()))->document((int)$id,0,true);if(!$doc)gp_json(['ok'=>false,'error'=>'Documento no encontrado.'],404);
            header_remove('Content-Security-Policy');header('Content-Type: '.$doc['mime']);header('Content-Length: '.filesize($doc['path']));header("Content-Disposition: inline; filename*=UTF-8''".rawurlencode($doc['originalName']));
            EventAudit::recordAdmin($actor,'clients','customer_document_view','view','gp_customer_documents',(int)$id,'Abrió un documento del cliente.',['label'=>$doc['label']??'']);readfile($doc['path']);exit;
        }
        if ($action === 'receipts') {
            gp_require_permission('finance.payments.view', true);
            $receiptService = new PaymentReceiptService(Database::connection());
            gp_json(['ok'=>true,'receipts'=>$receiptService->receipts(200)]);
        }
        if ($action === 'receipt') {
            gp_require_permission('finance.payments.view', true);
            $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            if ($id === false) throw new InvalidArgumentException('Recibo inválido.');
            $receipt = (new PaymentReceiptService(Database::connection()))->receipt((int)$id);
            if (!$receipt) gp_json(['ok'=>false,'error'=>'Recibo no encontrado.'],404);
            EventAudit::recordAdmin($actor,'receipts','receipt_view','view','gp_finance_receipts',(int)$id,'Abrió un recibo de pago.',['receipt_number'=>$receipt['receiptNumber']??'']);
            gp_json(['ok'=>true,'receipt'=>$receipt]);
        }
        if ($action === 'receipt-print') {
            gp_require_permission('finance.payments.view', true);
            $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            if ($id === false) throw new InvalidArgumentException('Recibo inválido.');
            $receipt = (new PaymentReceiptService(Database::connection()))->receipt((int)$id);
            if (!$receipt) { http_response_code(404); exit('Recibo no encontrado.'); }
            header_remove('Content-Security-Policy');
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store, private');
            EventAudit::recordAdmin($actor,'receipts','receipt_print_view','view','gp_finance_receipts',(int)$id,'Abrió la vista de impresión de un recibo.',['receipt_number'=>$receipt['receiptNumber']??'']);
            echo ReceiptRenderer::html($receipt, false, 'finance-v8.php?action=receipt-pdf&id='.(int)$id); exit;
        }

        if ($action === 'receipt-pdf') {
            gp_require_permission('finance.payments.view', true);
            $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            if ($id === false) throw new InvalidArgumentException('Recibo inválido.');
            $receipt = (new PaymentReceiptService(Database::connection()))->receipt((int)$id);
            if (!$receipt) { http_response_code(404); exit('Recibo no encontrado.'); }
            $pdf = ReceiptPdfRenderer::bytes($receipt);
            $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string)($receipt['receiptNumber'] ?? ('RECIBO-'.$id))).'.pdf';
            header_remove('Content-Security-Policy');
            header('Content-Type: application/pdf');
            header('Content-Length: '.strlen($pdf));
            header('Content-Disposition: attachment; filename="'.$filename.'"');
            header('Cache-Control: no-store, private');
            EventAudit::recordAdmin($actor,'receipts','receipt_pdf_download','download','gp_finance_receipts',(int)$id,'Descargó un recibo oficial en PDF.',['filename'=>$filename]);
            echo $pdf; exit;
        }
        if ($action === 'alerts') {
            gp_require_permission('finance.view', true);
            gp_json(['ok'=>true,'data'=>(new AlertCenter(Database::connection()))->overview()]);
        }
        if ($action === 'event-audit') {
            gp_require_permission('audit.view', true);
            $pdo=Database::connection();
            $from=trim((string)($_GET['from']??''));$to=trim((string)($_GET['to']??''));$user=trim((string)($_GET['user']??''));$module=trim((string)($_GET['module']??''));$type=trim((string)($_GET['type']??''));$search=trim((string)($_GET['search']??''));
            $where=['1=1'];$args=[];
            if($from!==''){$where[]='event_at>=?';$args[]=$from.' 00:00:00';}
            if($to!==''){$where[]='event_at<=?';$args[]=$to.' 23:59:59';}
            if($user!==''){$where[]='(user_email=? OR CAST(user_id AS CHAR)=?)';$args[]=$user;$args[]=$user;}
            if($module!==''){$where[]='module_key=?';$args[]=$module;}
            if($type!==''){$where[]='event_type=?';$args[]=$type;}
            if($search!==''){$where[]='(summary LIKE ? OR action_key LIKE ? OR entity_type LIKE ? OR user_name LIKE ? OR user_email LIKE ?)';for($i=0;$i<5;$i++)$args[]='%'.$search.'%';}
            $sql='SELECT id,event_at,timezone_name,actor_type,user_id,user_name,user_email,user_role,module_key,action_key,event_type,entity_type,entity_id,summary,http_method,route,ip_address,user_agent,metadata_json FROM gp_event_audit WHERE '.implode(' AND ',$where).' ORDER BY id DESC LIMIT 800';
            $stmt=$pdo->prepare($sql);$stmt->execute($args);$events=$stmt->fetchAll();
            $users=$pdo->query("SELECT DISTINCT user_email,user_name FROM gp_event_audit WHERE user_email IS NOT NULL AND user_email<>'' ORDER BY user_name,user_email")->fetchAll();
            $modules=$pdo->query("SELECT DISTINCT module_key FROM gp_event_audit WHERE module_key<>'' ORDER BY module_key")->fetchAll(PDO::FETCH_COLUMN);
            $types=$pdo->query("SELECT DISTINCT event_type FROM gp_event_audit WHERE event_type<>'' ORDER BY event_type")->fetchAll(PDO::FETCH_COLUMN);
            $today=EventAudit::now();$date=substr($today,0,10);
            $sum=$pdo->prepare("SELECT COUNT(*) total,SUM(event_type='login') logins,SUM(event_type='download') downloads,SUM(event_type IN ('create','update','delete','workflow')) changes FROM gp_event_audit WHERE event_at>=? AND event_at<=?");$sum->execute([$date.' 00:00:00',$date.' 23:59:59']);$summary=$sum->fetch()?:[];
            gp_json(['ok'=>true,'timezone'=>EventAudit::TIMEZONE,'events'=>$events,'users'=>$users,'modules'=>$modules,'types'=>$types,'summary'=>$summary]);
        }
        if ($action === 'audit') {
            gp_require_permission('audit.view', true);
            gp_json(['ok'=>true,'events'=>$service->audit(300)]);
        }
        if ($action === 'event-audit-export') {
            gp_require_permission('audit.view', true);
            $pdo=Database::connection();
            EventAudit::recordAdmin($actor,'audit','audit_export','download',null,null,'Exportó la auditoría de eventos a CSV.');
            $rows=$pdo->query('SELECT event_at,actor_type,user_name,user_email,user_role,module_key,event_type,action_key,entity_type,entity_id,summary,ip_address,route FROM gp_event_audit ORDER BY id DESC LIMIT 5000')->fetchAll();
            header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="grandprix-auditoria-eventos.csv"');echo "\xEF\xBB\xBF";$out=fopen('php://output','wb');fputcsv($out,['Fecha Venezuela','Tipo usuario','Usuario','Correo','Rol','Modulo','Tipo evento','Accion','Entidad','ID','Resumen','IP','Ruta'],';');foreach($rows as $r)fputcsv($out,$r,';');fclose($out);exit;
        }
        if ($action === 'export') {
            gp_require_permission('finance.reports.export', true);
            $rows = $service->accounts();
            EventAudit::recordAdmin($actor,'reports','portfolio_export','download',null,null,'Exportó la cartera financiera a CSV.',['rows'=>count($rows)]);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="grandprix-cartera-financiera.csv"');
            echo "\xEF\xBB\xBF";
            $out=fopen('php://output','wb');
            fputcsv($out,['#','Cliente','Cedula','Telefono','Direccion','Contrato','Fecha inicio','Cuota semanal USD','Monto financiado USD','Modelo','Placa','Cuotas pagas','Cuotas en mora','Pendientes futuras','Abono','Refiere','GPS Device ID','GPS etiqueta','Estado'], ';');
            foreach($rows as $row){
                fputcsv($out,[
                    $row['sourceRow'],$row['fullName'],$row['identityDocument'],$row['phone'],$row['address'],$row['contractNumber'],$row['startDate'],$row['weeklyAmount'],$row['financedAmount'],$row['model'],$row['plate'],$row['paid'],$row['late'],$row['future'],$row['advanceNote'],$row['referrer'],$row['gpsDeviceId'],$row['gpsLabel'],$row['status']
                ], ';');
            }
            fclose($out); exit;
        }
        gp_json(['ok'=>false,'error'=>'Accion financiera no reconocida.'],404);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') gp_json(['ok'=>false,'error'=>'Metodo no permitido.'],405);
    $csrf=$_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if(!gp_verify_csrf(is_string($csrf)?$csrf:null)) gp_json(['ok'=>false,'error'=>'Sesion de seguridad vencida.'],419);
    if($action==='dossier-upload'){
        gp_require_permission('finance.clients.edit',true);
        $accountId=filter_var($_POST['accountId']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if($accountId===false)throw new InvalidArgumentException('Cliente inválido.');
        $docKey=trim((string)($_POST['docKey']??''));if($docKey==='')throw new InvalidArgumentException('Selecciona el tipo de documento.');
        if(!isset($_FILES['file']))throw new InvalidArgumentException('Selecciona el archivo que deseas adjuntar.');
        $doc=(new ClientDossierService(Database::connection()))->upload((int)$accountId,$docKey,$_FILES['file'],(string)($actor['email']??$actor['name']??'admin'));
        EventAudit::recordAdmin($actor,'clients','client_dossier_upload','create','gp_client_dossier_files',(int)($doc['id']??0),'Adjuntó un documento al Expediente 360.',['account_id'=>(int)$accountId,'doc_key'=>$docKey,'label'=>$doc['label']??'']);
        gp_json(['ok'=>true,'message'=>'Documento adjuntado y organizado en la carpeta del cliente.','document'=>$doc,'dossier'=>(new ClientDossierService(Database::connection()))->detail((int)$accountId,false)]);
    }
    if($action==='customer-document-upload'){
        gp_require_permission('finance.clients.edit',true);
        $accountId=filter_var($_POST['accountId']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if($accountId===false)throw new InvalidArgumentException('Cliente inválido.');
        if(!isset($_FILES['file']))throw new InvalidArgumentException('Selecciona el documento.');
        $type=trim((string)($_POST['documentType']??'other'));$label=trim((string)($_POST['label']??''));$visible=($_POST['visible']??'1')!=='0';
        $doc=(new CustomerDocumentService(Database::connection()))->uploadForAccount((int)$accountId,$_FILES['file'],$type,$label,(string)($actor['email']??'admin'),$visible);
        EventAudit::recordAdmin($actor,'clients','customer_document_upload','create','gp_customer_documents',(int)($doc['id']??0),'Adjuntó un documento al portal del cliente.',['account_id'=>(int)$accountId,'label'=>$doc['label']??'']);
        gp_json(['ok'=>true,'message'=>'Documento adjuntado y disponible en Mi GRANDPRIX.','document'=>$doc]);
    }
    if($action==='application-document-upload'){
        gp_require_permission('finance.applications.edit',true);
        $applicationId=filter_var($_POST['applicationId']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $itemId=filter_var($_POST['itemId']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($applicationId===false||$itemId===false)throw new InvalidArgumentException('Recaudo inválido.');
        if(!isset($_FILES['file']))throw new InvalidArgumentException('Selecciona el archivo que deseas adjuntar.');
        $application=$applicationService->adminUploadDocument((int)$applicationId,(int)$itemId,$_FILES['file'],$actor);
        EventAudit::recordAdmin($actor,'applications','application_document_upload','create','gp_finance_applications',(int)$applicationId,'Administración adjuntó un recaudo al expediente.',['checklist_item_id'=>(int)$itemId]);
        gp_json(['ok'=>true,'message'=>'Documento adjuntado al expediente.','application'=>$application]);
    }
    $input=json_decode((string)file_get_contents('php://input'),true);
    if(!is_array($input)) gp_json(['ok'=>false,'error'=>'Solicitud invalida.'],400);

    if($action==='dossier-location'){
        gp_require_permission('finance.clients.edit',true);
        $accountId=filter_var($input['accountId']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if($accountId===false)throw new InvalidArgumentException('Cliente inválido.');
        $lat=filter_var($input['latitude']??null,FILTER_VALIDATE_FLOAT);$lng=filter_var($input['longitude']??null,FILTER_VALIDATE_FLOAT);
        if($lat===false||$lng===false)throw new InvalidArgumentException('Registra coordenadas GPS válidas.');
        $serviceDossier=new ClientDossierService(Database::connection());
        $location=$serviceDossier->saveLocation((int)$accountId,(float)$lat,(float)$lng,trim((string)($input['address']??'')),trim((string)($input['notes']??'')),(string)($actor['email']??$actor['name']??'admin'));
        EventAudit::recordAdmin($actor,'clients','client_home_location_save','update','gp_finance_accounts',(int)$accountId,'Registró/actualizó la ubicación GPS de la vivienda del cliente.',['latitude'=>(float)$lat,'longitude'=>(float)$lng]);
        gp_json(['ok'=>true,'message'=>'Ubicación GPS guardada en el expediente.','location'=>$location,'dossier'=>$serviceDossier->detail((int)$accountId,false)]);
    }
    if($action==='dossier-sync'){
        gp_require_permission('finance.clients.edit',true);
        $accountId=filter_var($input['accountId']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if($accountId===false)throw new InvalidArgumentException('Cliente inválido.');
        $serviceDossier=new ClientDossierService(Database::connection());$sync=$serviceDossier->syncAccountSources((int)$accountId);
        gp_json(['ok'=>true,'message'=>'Expediente sincronizado con documentos, visita y pagos existentes.','sync'=>$sync,'dossier'=>$serviceDossier->detail((int)$accountId,false)]);
    }
    if($action==='save-inventory'){
        if(!gp_user_can('gps.vehicles.view')&&!gp_user_can('finance.clients.edit'))gp_json(['ok'=>false,'error'=>'No tienes permiso para editar Inventario.'],403);
        $unit=(new InventoryService(Database::connection()))->save($input,$actor);
        EventAudit::recordAdmin($actor,'inventory','inventory_save','update','gp_motorcycle_inventory',(int)($unit['id']??0),'Guardó una motocicleta del inventario.',['plate'=>$unit['plate']??'']);
        gp_json(['ok'=>true,'message'=>'Motocicleta guardada en inventario.','unit'=>$unit]);
    }
    if($action==='sync-inventory-real'){
        if(!gp_user_can('gps.vehicles.view')&&!gp_user_can('finance.clients.edit'))gp_json(['ok'=>false,'error'=>'No tienes permiso para sincronizar Inventario.'],403);
        $result=(new InventoryService(Database::connection()))->syncRealData($actor);
        EventAudit::recordAdmin($actor,'inventory','inventory_real_sync','update','gp_motorcycle_inventory',null,'Sincronizó las placas reales de cartera con Inventario.',['created'=>$result['created']??0,'linked'=>$result['linked']??0,'conflicts'=>count($result['conflicts']??[])]);
        gp_json(['ok'=>true,'message'=>'Inventario sincronizado con las placas reales de GRANDPRIX.','result'=>$result,'units'=>(new InventoryService(Database::connection()))->list()]);
    }
    if($action==='audit-event'){
        $module=mb_substr(trim((string)($input['module']??'system')),0,80);$eventType=mb_substr(trim((string)($input['eventType']??'view')),0,40);$actionKey=mb_substr(trim((string)($input['action']??'ui_event')),0,100);$summary=mb_substr(trim((string)($input['summary']??'Evento de interfaz administrativa.')),0,500);
        EventAudit::recordAdmin($actor,$module,$actionKey,$eventType,null,null,$summary,['label'=>(string)($input['label']??'')]);
        gp_json(['ok'=>true]);
    }
    if($action==='save-application'){
        $id=(int)($input['id']??0);
        gp_require_permission($id>0?'finance.applications.edit':'finance.applications.create',true);
        $application=$service->saveApplication($input,$actor);
        if(!empty($application['id']))$applicationService->ensureChecklist((int)$application['id']);
        gp_json(['ok'=>true,'message'=>$id>0?'Solicitud actualizada.':'Solicitud registrada correctamente.','application'=>$application]);
    }
    if($action==='application-workflow'){
        gp_require_permission('finance.applications.edit',true);
        $id=filter_var($input['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($id===false)throw new InvalidArgumentException('Solicitud inválida.');
        $application=$applicationService->transition((int)$id,(string)($input['decision']??''),$input,$actor);
        gp_json(['ok'=>true,'message'=>'Flujo de solicitud actualizado.','application'=>$application]);
    }
    if($action==='application-checklist-save'){
        gp_require_permission('finance.applications.edit',true);
        $applicationId=filter_var($input['applicationId']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $itemId=(int)($input['itemId']??0);
        if($applicationId===false)throw new InvalidArgumentException('Solicitud inválida.');
        $application=$applicationService->updateChecklistItem((int)$applicationId,$itemId,$input,$actor);
        gp_json(['ok'=>true,'message'=>$itemId>0?'Checklist actualizado.':'Recaudo agregado al checklist.','application'=>$application]);
    }
    if($action==='application-checklist-delete'){
        gp_require_permission('finance.applications.edit',true);
        $applicationId=filter_var($input['applicationId']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $itemId=filter_var($input['itemId']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($applicationId===false||$itemId===false)throw new InvalidArgumentException('Recaudo inválido.');
        $application=$applicationService->deleteChecklistItem((int)$applicationId,(int)$itemId,$actor);
        gp_json(['ok'=>true,'message'=>'Recaudo personalizado eliminado.','application'=>$application]);
    }
    if($action==='application-formalize'){
        gp_require_permission('finance.applications.edit',true);
        gp_require_permission('finance.gps.assign',true);
        $applicationId=filter_var($input['applicationId']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($applicationId===false)throw new InvalidArgumentException('Solicitud inválida.');
        $application=$applicationService->formalizeApplication((int)$applicationId,$input,$actor);
        gp_json(['ok'=>true,'message'=>'Cliente formalizado: contrato, moto, GPS y plan de 50 cuotas preparados.','application'=>$application]);
    }
    if($action==='save-account'){
        $id=(int)($input['id']??0);
        gp_require_permission($id>0?'finance.clients.edit':'finance.clients.create',true);
        if(!gp_user_can('finance.gps.assign')){
            if($id>0){
                $existing=$service->account($id);
                if(!$existing) gp_json(['ok'=>false,'error'=>'Cliente no encontrado.'],404);
                // Un editor financiero sin permiso GPS conserva la relación existente sin poder alterarla.
                $input['gpsDeviceId']=$existing['gpsDeviceId'];
                $input['gpsLabel']=$existing['gpsLabel'];
            } elseif ((!empty($input['gpsDeviceId'])) || trim((string)($input['gpsLabel']??''))!=='') {
                gp_json(['ok'=>false,'error'=>'No tienes permiso para asignar GPS.'],403);
            }
        }
        $account=$service->saveAccount($input,$actor);
        $warnings=is_array($account['_saveWarnings']??null)?$account['_saveWarnings']:[];
        unset($account['_saveWarnings']);
        $message=$id>0?'Cliente actualizado correctamente.':'Cliente agregado correctamente.';
        if($warnings)$message.=' El expediente quedó guardado; '.implode(' · ',$warnings).'.';
        gp_json(['ok'=>true,'message'=>$message,'account'=>$account,'warnings'=>$warnings]);
    }
    if($action==='archive-account'){
        gp_require_permission('finance.clients.archive',true);
        $id=filter_var($input['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($id===false)throw new InvalidArgumentException('Cliente invalido.');
        $service->archiveAccount((int)$id,$actor);
        gp_json(['ok'=>true,'message'=>'Cliente archivado.']);
    }
    if($action==='record-payment'){
        gp_require_permission('finance.payments.create',true);
        if(!gp_user_can('finance.payments.reconcile')) $input['needsReview']=true;
        $result=$service->recordPayment($input,$actor);
        gp_json(['ok'=>true,'message'=>$result['status']==='review'?'Movimiento enviado a conciliación.':'Pago registrado, semanas actualizadas y recibo generado.','payment'=>$result]);
    }
    if($action==='reconcile-payment'){
        gp_require_permission('finance.payments.reconcile',true);
        $id=filter_var($input['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($id===false) throw new InvalidArgumentException('Movimiento invalido.');
        $result=$service->reconcilePayment((int)$id,(string)($input['decision']??''),$actor);
        gp_json(['ok'=>true,'message'=>$result['status']==='confirmed'?'Pago aprobado, semanas aplicadas y recibo generado.':'Movimiento rechazado sin alterar cuotas.','payment'=>$result]);
    }
    gp_json(['ok'=>false,'error'=>'Accion financiera no reconocida.'],404);
} catch (InvalidArgumentException $e) {
    gp_json(['ok'=>false,'error'=>$e->getMessage()],422);
} catch (PDOException $e) {
    $state=(string)$e->getCode();
    $driver=(int)($e->errorInfo[1]??0);
    $ref='DB-'.strtoupper(substr(hash('sha256',$state.'|'.$driver.'|'.$action.'|'.microtime(true)),0,8));
    error_log('[GRANDPRIX '.$ref.'] SQLSTATE='.$state.' DRIVER='.$driver.' ACTION='.$action.' MESSAGE='.$e->getMessage());
    if($state==='23000')$msg='El dato ingresado entra en conflicto con un registro existente. Revisa placa, GPS, contrato o identificadores duplicados.';
    elseif($state==='42S22'||$driver===1054)$msg='La estructura de Finanzas está incompleta. Ejecuta el reparador V20.8 y vuelve a intentar.';
    elseif($state==='42S02'||$driver===1146)$msg='Falta una tabla financiera requerida. Ejecuta el reparador V20.8 y vuelve a intentar.';
    elseif($state==='22001'||$driver===1406)$msg='Uno de los campos supera el tamaño permitido. Revisa textos largos y vuelve a intentar.';
    else $msg='No fue posible guardar en la base de datos. Código técnico '.$ref.'.';
    gp_json(['ok'=>false,'error'=>$msg,'reference'=>$ref],409);
} catch (Throwable $e) {
    $ref=gp_runtime_error('finance-v8',$e,['action'=>$action]);
    gp_json(['ok'=>false,'error'=>'No fue posible completar la operacion financiera. Ref. '.$ref],500);
}
