<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/Database.php';
require_once dirname(__DIR__) . '/lib/AdminAuth.php';

gp_start_session();
gp_require_admin(true);
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
if (!Database::configured()) gp_json(['ok'=>false,'error'=>'La base de datos no esta configurada.'],503);
$action=strtolower(trim((string)($_GET['action']??'overview')));
$actor=gp_current_admin();

try{
    if($_SERVER['REQUEST_METHOD']==='GET' && $action==='overview'){
        gp_require_permission('users.view',true);
        gp_json(['ok'=>true,'version'=>'8.0.0','current'=>$actor] + AdminAuth::overview());
    }
    if($_SERVER['REQUEST_METHOD']!=='POST')gp_json(['ok'=>false,'error'=>'Metodo no permitido.'],405);
    $csrf=$_SERVER['HTTP_X_CSRF_TOKEN']??null;
    if(!gp_verify_csrf(is_string($csrf)?$csrf:null))gp_json(['ok'=>false,'error'=>'Sesion de seguridad vencida.'],419);
    $input=json_decode((string)file_get_contents('php://input'),true);
    if(!is_array($input))gp_json(['ok'=>false,'error'=>'Solicitud invalida.'],400);

    if($action==='save-user'){
        $id=(int)($input['id']??0);
        gp_require_permission($id>0?'users.edit':'users.create',true);
        if(!empty($input['customizePermissions']) && !gp_user_can('users.permissions')) gp_json(['ok'=>false,'error'=>'No tienes permiso para personalizar permisos.'],403);
        if(!gp_user_can('users.permissions')) $input['_preservePermissions']=true;
        $result=AdminAuth::saveUser($input,$actor);
        gp_json(['ok'=>true,'message'=>$id>0?'Usuario actualizado.':'Usuario creado correctamente.','user'=>$result]);
    }
    if($action==='save-role'){
        gp_require_permission('users.permissions',true);
        $result=AdminAuth::saveRole($input,$actor);
        gp_json(['ok'=>true,'message'=>(int)($input['id']??0)>0?'Rol actualizado.':'Rol creado.','role'=>$result]);
    }
    gp_json(['ok'=>false,'error'=>'Accion de usuarios no reconocida.'],404);
}catch(InvalidArgumentException $e){
    gp_json(['ok'=>false,'error'=>$e->getMessage()],422);
}catch(Throwable $e){
    $ref=gp_runtime_error('admin-users-v8',$e,['action'=>$action]);
    gp_json(['ok'=>false,'error'=>'No fue posible completar la operacion de usuarios. Ref. '.$ref],500);
}
