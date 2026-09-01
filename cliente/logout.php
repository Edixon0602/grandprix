<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/EventAudit.php';
gp_start_session();
$customerId=(int)($_SESSION['grandprix_customer_id']??0);
if($customerId>0){$actor=EventAudit::customerActor($customerId);EventAudit::recordCustomer($actor,'auth','logout','logout','gp_customers',$customerId,'Cierre de sesión de cliente.');}
unset(
    $_SESSION['grandprix_customer_id'],
    $_SESSION['grandprix_customer_key'],
    $_SESSION['grandprix_customer_name'],
    $_SESSION['grandprix_csrf']
);
session_regenerate_id(true);
header('Location: login.php');
exit;
