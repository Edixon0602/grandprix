<?php
declare(strict_types=1);

$lock = dirname(__DIR__) . '/config/install.lock';
if (!is_file($lock)) { header('Location: ../install/'); exit; }
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/Database.php';
require_once dirname(__DIR__) . '/lib/CustomerPortal.php';

gp_start_session();
if (!Database::configured()) {
    http_response_code(503);
    exit('GRANDPRIX V7.2 necesita ejecutar install/actualizar-v7-2.php.');
}
$customer = null;
try {
    $portal = new CustomerPortal();
    if (!empty($_SESSION['grandprix_customer_id'])) {
        $customer = $portal->customer((int) $_SESSION['grandprix_customer_id']);
    } elseif (gp_is_admin()) {
        $customer = $portal->previewCustomer((int) ($_SESSION['grandprix_preview_customer_id'] ?? 0));
    }
} catch (Throwable $exception) {
    $reference = gp_runtime_error('customer-index', $exception, [
        'customerId' => (int) ($_SESSION['grandprix_customer_id'] ?? 0),
    ]);
    http_response_code(503);
    ?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Mi GRANDPRIX</title><style>*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:20px;background:radial-gradient(circle at 70% 0,#164a79,transparent 30%),#061a31;font-family:Inter,Arial,sans-serif;color:#102943}.error-card{width:min(520px,100%);background:#fff;border-radius:26px;padding:34px;text-align:center;box-shadow:0 28px 80px #0006}.icon{width:72px;height:72px;border-radius:22px;display:grid;place-items:center;margin:auto;background:#e8f2ff;color:#1477ff;font-size:30px}.error-card h1{font-size:25px;margin:20px 0 8px}.error-card p{color:#6c8196;line-height:1.6}.reference{display:block;background:#eef4f8;border-radius:12px;padding:12px;margin:18px 0;font-size:12px}.error-card a{display:inline-block;background:linear-gradient(135deg,#1477ff,#20b8df);color:#fff;text-decoration:none;border-radius:12px;padding:13px 18px;font-weight:800}</style></head><body><main class="error-card"><div class="icon">!</div><h1>No fue posible abrir Mi GRANDPRIX</h1><p>El servidor detectó un problema técnico y lo registró sin mostrar datos privados.</p><span class="reference">Referencia: <b><?=htmlspecialchars($reference)?></b></span><a href="login.php">Volver al acceso</a></main></body></html><?php
    exit;
}
if (!$customer) { header('Location: login.php'); exit; }
$name = (string) $customer['full_name'];
$parts = preg_split('/\s+/', trim($name)) ?: [];
$firstName = (string) ($parts[0] ?? 'Cliente');
$initials = mb_strtoupper(mb_substr($firstName, 0, 1) . mb_substr((string) ($parts[1] ?? ''), 0, 1));
$csrf = gp_csrf_token();
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#061a31"><meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<title>Mi GRANDPRIX - <?=htmlspecialchars($name)?></title>
<link rel="manifest" href="manifest.json"><link rel="icon" href="../assets/grandprix-logo.png">
<link rel="stylesheet" href="../assets/vendor/maplibre-gl.css?v=5.24.0">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="assets/cliente.css?v=28.0.0"><link rel="stylesheet" href="assets/gps-premium.css?v=7.2.1">
<link rel="stylesheet" href="../assets/satellite-pro.css?v=7.2.1"><link rel="stylesheet" href="assets/v72.css?v=7.2.1"><link rel="stylesheet" href="assets/v29-mobile.css?v=31.0.0">
</head>
<body>
<div class="app">
<aside id="side">
  <div class="logo"><img src="../assets/grandprix-logo.png" alt="GRANDPRIX"></div>
  <div class="customer"><span><?=htmlspecialchars($initials)?></span><div><small>Bienvenido</small><b><?=htmlspecialchars($name)?></b></div></div>
  <nav id="nav">
    <button data-view="inicio" class="active"><i class="fa-solid fa-house"></i>Resumen</button>
    <button data-view="moto"><i class="fa-solid fa-motorcycle"></i>Mi motocicleta</button>
    <button data-view="semanas"><i class="fa-solid fa-calendar-check"></i>Ruta de 50 semanas</button>
    <button data-view="gps"><i class="fa-solid fa-map-location-dot"></i>GPS en vivo</button>
    <button data-view="reportar"><i class="fa-solid fa-money-bill-transfer"></i>Reportar pago</button>
    <button data-view="pagos"><i class="fa-solid fa-receipt"></i>Pagos y recibos</button>
    <button data-view="documentos"><i class="fa-solid fa-folder-open"></i>Mis documentos</button>
    <button data-view="contrato"><i class="fa-solid fa-file-contract"></i>Mi contrato</button>
    <button data-view="soporte"><i class="fa-brands fa-whatsapp"></i>Soporte</button>
  </nav>
  <div class="secure"><i class="fa-solid fa-shield-halved"></i><span><b>Portal protegido</b><small>GPS exclusivo de tu contrato</small></span></div>
  <a class="logout" href="logout.php"><i class="fa-solid fa-right-from-bracket"></i>Cerrar sesion</a>
</aside>
<main>
  <header><button class="hamb" onclick="toggleMenu()"><i class="fa-solid fa-bars"></i></button><div><small>MI GRANDPRIX</small><h1 id="title">Hola, <?=htmlspecialchars($firstName)?> 👋</h1></div><div class="head-right"><span id="headerGps"><i></i> GPS ASIGNADO</span><button type="button"><i class="fa-solid fa-bell"></i><em id="alertCount">0</em></button><div class="avatar"><?=htmlspecialchars($initials)?></div></div></header>
  <div id="view"><div class="portal-loading"><span></span><b>Preparando Mi GRANDPRIX</b><small>Cargando contrato y semanas de forma segura</small></div></div>
</main></div>
<nav class="bottom"><button data-view="inicio" class="active"><i class="fa-solid fa-house"></i><span>Inicio</span></button><button data-view="documentos"><i class="fa-solid fa-folder-open"></i><span>Documentos</span></button><button data-view="gps"><i class="fa-solid fa-map-location-dot"></i><span>GPS</span></button><button data-view="pagos"><i class="fa-solid fa-receipt"></i><span>Pagos</span></button><button onclick="toggleMenu()"><i class="fa-solid fa-grip"></i><span>Más</span></button></nav>
<div id="toast"></div>
<script>window.GRANDPRIX={csrf:<?=json_encode($csrf)?>,version:<?=json_encode(gp_release())?>,customerApi:'../api/customer.php',customerName:<?=json_encode($name)?>};</script>
<script src="../assets/vendor/maplibre-gl.js?v=5.24.0"></script><script src="../assets/vendor/pusher.min.js?v=8.6.0"></script>
<script src="assets/cliente.js?v=31.0.0"></script><script src="assets/gps-v2.js?v=7.2.1"></script><script src="../assets/realtime.js?v=7.2.1"></script><script src="assets/satellite-pro.js?v=7.2.1"></script>
<script>if('serviceWorker' in navigator)navigator.serviceWorker.register('sw.js?v=29.0.0').catch(()=>{});</script>
</body></html>
