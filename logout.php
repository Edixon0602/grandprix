<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/EventAudit.php';
gp_start_session();
$actor=EventAudit::adminActorFromSession();
EventAudit::recordAdmin($actor,'auth','logout','logout','gp_admin_users',(int)($actor['id']??0),'Cierre de sesión administrativo.');
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) $params['secure'], (bool) $params['httponly']);
}
session_destroy();
header('Location: login.php');
exit;

