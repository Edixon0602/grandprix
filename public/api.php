<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/Database.php';
require_once dirname(__DIR__) . '/lib/PublicApplicationService.php';

session_name('grandprix_public');
if(session_status()!==PHP_SESSION_ACTIVE)session_start(['cookie_httponly'=>true,'cookie_samesite'=>'Lax','cookie_secure'=>(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')]);
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function out(array $data,int $status=200):never{http_response_code($status);header('Content-Type: application/json; charset=utf-8');echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function csrf_ok():bool{$given=(string)($_SERVER['HTTP_X_CSRF_TOKEN']??'');$saved=(string)($_SESSION['gp_public_csrf']??'');return $given!==''&&$saved!==''&&hash_equals($saved,$given);}

if(!Database::configured())out(['ok'=>false,'error'=>'El sistema de solicitudes está temporalmente fuera de servicio.'],503);
$service=PublicApplicationService::create();
$action=strtolower(trim((string)($_GET['action']??'catalog')));
try{
    if($_SERVER['REQUEST_METHOD']==='GET'){
        if($action==='catalog')out(['ok'=>true,'models'=>$service->catalog(),'plan'=>['totalInstallments'=>50,'label'=>'Plan base 50 cuotas']]);
        if($action==='status'){
            $accessCode=trim((string)($_GET['accessCode']??''));
            if($accessCode!=='')out(['ok'=>true,'application'=>$service->statusByAccessCode($accessCode)]);
            $code=(string)($_GET['code']??'');$token=(string)($_GET['token']??'');
            out(['ok'=>true,'application'=>$service->status($code,$token)]);
        }
        out(['ok'=>false,'error'=>'Acción no reconocida.'],404);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST')out(['ok'=>false,'error'=>'Método no permitido.'],405);
    if(!csrf_ok())out(['ok'=>false,'error'=>'La sesión del formulario venció. Recarga la página e inténtalo nuevamente.'],419);
    if($action==='submit')out(['ok'=>true]+$service->submit($_POST,$_FILES));
    if($action==='documents'){
        $accessCode=trim((string)($_POST['accessCode']??''));
        if($accessCode!=='')out(['ok'=>true,'application'=>$service->addDocumentsByAccessCode($accessCode,$_FILES)]);
        $code=(string)($_POST['code']??'');$token=(string)($_POST['token']??'');
        out(['ok'=>true,'application'=>$service->addDocuments($code,$token,$_FILES)]);
    }
    if($action==='visit'){
        $accessCode=trim((string)($_POST['accessCode']??''));
        if($accessCode!=='')out(['ok'=>true,'application'=>$service->submitVisitByAccessCode($accessCode,$_POST,$_FILES)]);
        $code=(string)($_POST['code']??'');$token=(string)($_POST['token']??'');
        out(['ok'=>true,'application'=>$service->submitVisit($code,$token,$_POST,$_FILES)]);
    }
    if($action==='activate-portal'){
        $accessCode=trim((string)($_POST['accessCode']??''));
        if($accessCode!=='')out(['ok'=>true,'application'=>$service->activatePortalAccountByAccessCode($accessCode,$_POST)]);
        $code=(string)($_POST['code']??'');$token=(string)($_POST['token']??'');
        out(['ok'=>true,'application'=>$service->activatePortalAccount($code,$token,$_POST)]);
    }
    out(['ok'=>false,'error'=>'Acción no reconocida.'],404);
}catch(InvalidArgumentException $e){out(['ok'=>false,'error'=>$e->getMessage()],422);}catch(PDOException $e){$ref=strtoupper(substr(hash('sha256',$e->getMessage().'|'.microtime(true)),0,8));error_log('[GRANDPRIX PUBLIC PDO]['.$ref.'] action='.$action.' sqlstate='.$e->getCode().' message='.$e->getMessage());out(['ok'=>false,'error'=>'No fue posible guardar la solicitud en este momento. Código técnico: '.$ref],409);}catch(Throwable $e){$ref=strtoupper(substr(hash('sha256',$e->getMessage().'|'.microtime(true)),0,8));error_log('[GRANDPRIX PUBLIC]['.$ref.'] action='.$action.' '.$e->getMessage());out(['ok'=>false,'error'=>'Ocurrió un error al procesar la solicitud. Código técnico: '.$ref],500);}
