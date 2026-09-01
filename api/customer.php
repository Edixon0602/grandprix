<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/Database.php';
require_once dirname(__DIR__) . '/lib/CustomerPortal.php';
require_once dirname(__DIR__) . '/lib/PaymentReceiptService.php';
require_once dirname(__DIR__) . '/lib/ReceiptRenderer.php';
require_once dirname(__DIR__) . '/lib/ReceiptPdfRenderer.php';
require_once dirname(__DIR__) . '/lib/EventAudit.php';
require_once dirname(__DIR__) . '/lib/ClientDossierService.php';

gp_start_session();
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, private');

if (!Database::configured()) gp_json(['ok' => false, 'error' => 'El portal financiero aun no ha sido configurado.'], 503);
$portal = new CustomerPortal();
$customerId = (int) ($_SESSION['grandprix_customer_id'] ?? 0);
if ($customerId < 1 && gp_is_admin()) {
    $preview = $portal->previewCustomer((int) ($_SESSION['grandprix_preview_customer_id'] ?? 0));
    $customerId = (int) ($preview['id'] ?? 0);
}
if ($customerId < 1) gp_json(['ok' => false, 'error' => 'Sesion de cliente requerida.'], 401);
$action = strtolower(trim((string) ($_GET['action'] ?? 'dashboard')));
$customerActor=EventAudit::customerActor($customerId);

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'dashboard') {
        EventAudit::recordCustomer($customerActor,'portal','dashboard_view','view','gp_customers',$customerId,'Cliente consultó Mi GRANDPRIX.');
        gp_json(['ok' => true, 'version' => gp_release(), 'polling' => false, 'data' => $portal->dashboard($customerId)]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'dossier') {
        $dossier = new ClientDossierService(Database::connection());
        if (!$dossier->ready()) gp_json(['ok'=>false,'error'=>'Expediente documental no disponible. Ejecuta la actualización V25.1.'],503);
        $data = $dossier->portalDetail($customerId);
        EventAudit::recordCustomer($customerActor,'portal','dossier_view','view','gp_client_dossier_meta',(int)($data['accountId']??0),'Cliente consultó sus documentos de Expediente 360.');
        gp_json(['ok'=>true,'data'=>$data]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'dossier-file') {
        $fileId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if ($fileId === false) gp_json(['ok'=>false,'error'=>'Documento inválido.'],422);
        $dossier = new ClientDossierService(Database::connection());
        $file = $dossier->downloadableForPortalCustomer($customerId,(int)$fileId);
        if (!$file) gp_json(['ok'=>false,'error'=>'Documento no encontrado o no pertenece a tu expediente.'],404);
        $mime = trim((string)($file['mime'] ?? 'application/octet-stream'));
        if (!preg_match('#^(application/pdf|application/json|image/(jpeg|png|webp))$#i',$mime)) $mime='application/octet-stream';
        $name = trim((string)($file['originalName'] ?? 'documento')) ?: 'documento';
        header_remove('Content-Security-Policy');
        header('Content-Type: '.$mime);
        header('Content-Length: '.filesize((string)$file['path']));
        header("Content-Disposition: attachment; filename*=UTF-8''".rawurlencode($name));
        header('Cache-Control: no-store, private');
        EventAudit::recordCustomer($customerActor,'portal','dossier_file_download','download','gp_client_dossier_files',(int)$fileId,'Cliente descargó un archivo de su Expediente 360.',['label'=>$file['label']??'','docKey'=>$file['docKey']??'']);
        readfile((string)$file['path']);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'proof') {
        $reportId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($reportId === false) gp_json(['ok' => false, 'error' => 'Comprobante invalido.'], 422);
        $proof = $portal->proof($customerId, (int) $reportId, gp_is_admin());
        if (!$proof) gp_json(['ok' => false, 'error' => 'Comprobante no encontrado.'], 404);
        header('Content-Type: ' . $proof['mime']);
        header('Content-Length: ' . filesize($proof['path']));
        header('Content-Disposition: inline; filename="comprobante-' . (int) $reportId . '"');
        EventAudit::recordCustomer($customerActor,'portal','payment_proof_view','view','gp_payment_reports',(int)$reportId,'Cliente abrió un comprobante de pago.');
        readfile($proof['path']);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'receipt') {
        $receiptId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($receiptId === false) gp_json(['ok'=>false,'error'=>'Recibo inválido.'],422);
        $receipt = (new PaymentReceiptService(Database::connection()))->portalReceiptForCustomer((int)$receiptId,$customerId);
        if (!$receipt) gp_json(['ok'=>false,'error'=>'Recibo no encontrado para este cliente.'],404);
        header_remove('Content-Security-Policy');
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, private');
        EventAudit::recordCustomer($customerActor,'portal','receipt_view','view','gp_finance_receipts',(int)$receiptId,'Cliente abrió su recibo oficial GRANDPRIX.',['receipt_number'=>$receipt['receiptNumber']??'']);
        echo ReceiptRenderer::html($receipt,true,'customer.php?action=receipt-pdf&id='.(int)$receiptId); exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'receipt-pdf') {
        $receiptId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($receiptId === false) gp_json(['ok'=>false,'error'=>'Recibo inválido.'],422);
        $receipt = (new PaymentReceiptService(Database::connection()))->portalReceiptForCustomer((int)$receiptId,$customerId);
        if (!$receipt) gp_json(['ok'=>false,'error'=>'Recibo no encontrado para este cliente.'],404);
        $pdf = ReceiptPdfRenderer::bytes($receipt);
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string)($receipt['receiptNumber'] ?? ('RECIBO-'.$receiptId))).'.pdf';
        header_remove('Content-Security-Policy');
        header('Content-Type: application/pdf');
        header('Content-Length: '.strlen($pdf));
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        header('Cache-Control: no-store, private');
        EventAudit::recordCustomer($customerActor,'portal','receipt_pdf_download','download','gp_finance_receipts',(int)$receiptId,'Cliente descargó un recibo oficial en PDF.',['filename'=>$filename]);
        echo $pdf; exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'document') {
        $documentId=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($documentId===false)gp_json(['ok'=>false,'error'=>'Documento inválido.'],422);
        $document=$portal->customerDocument($customerId,(int)$documentId);
        if(!$document)gp_json(['ok'=>false,'error'=>'Documento no encontrado.'],404);
        header_remove('Content-Security-Policy');
        header('Content-Type: '.(string)$document['mime']);
        header('Content-Length: '.filesize((string)$document['path']));
        header("Content-Disposition: attachment; filename*=UTF-8''".rawurlencode((string)$document['originalName']));
        header('Cache-Control: no-store, private');
        EventAudit::recordCustomer($customerActor,'portal','customer_document_download','download','gp_customer_documents',(int)$documentId,'Cliente descargó un documento de su expediente.',['label'=>$document['label']??'']);
        readfile((string)$document['path']);exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'report-payment') {
        $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? null);
        if (!gp_verify_csrf(is_string($csrf) ? $csrf : null)) gp_json(['ok' => false, 'error' => 'Sesion de seguridad vencida.'], 419);
        $result = $portal->reportPayment($customerId, $_POST, is_array($_FILES['proof'] ?? null) ? $_FILES['proof'] : null);
        EventAudit::recordCustomer($customerActor,'portal','report_payment','create','gp_payment_reports',(int)($result['id']??0),'Cliente reportó un pago para conciliación.',['week'=>(string)($_POST['weekNumber']??$_POST['week_number']??''),'amount'=>(string)($_POST['amount']??'')]);
        gp_json(['ok' => true, 'message' => 'Pago reportado. Al conciliarlo, la semana se marcará pagada y tu recibo PDF aparecerá automáticamente en Mi GRANDPRIX.', 'report' => $result], 201);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'mark-notifications-read') {
        $csrf=$_SERVER['HTTP_X_CSRF_TOKEN']??null;if(!gp_verify_csrf(is_string($csrf)?$csrf:null))gp_json(['ok'=>false,'error'=>'Sesion de seguridad vencida.'],419);
        $portal->markNotificationsRead($customerId);
        EventAudit::recordCustomer($customerActor,'portal','notifications_read','update','gp_customer_notifications',null,'Cliente marcó sus notificaciones como leídas.');
        gp_json(['ok'=>true]);
    }
    gp_json(['ok' => false, 'error' => 'Accion del portal no reconocida.'], 404);
} catch (InvalidArgumentException $error) {
    gp_json(['ok' => false, 'error' => $error->getMessage()], 422);
} catch (RuntimeException $error) {
    gp_json(['ok' => false, 'error' => $error->getMessage()], 409);
} catch (Throwable $error) {
    $reference = gp_runtime_error('customer-api', $error, ['action' => $action, 'customerId' => $customerId]);
    gp_json([
        'ok' => false,
        'error' => 'No fue posible procesar la solicitud del portal. Referencia: ' . $reference . '.',
    ], 500);
}
