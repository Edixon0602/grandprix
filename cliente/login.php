<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/Database.php';
require_once dirname(__DIR__) . '/lib/CustomerPortal.php';
require_once dirname(__DIR__) . '/lib/EventAudit.php';
gp_start_session();
if (!empty($_SESSION['grandprix_customer_id'])) { header('Location: index.php'); exit; }
$error = '';
$csrf = gp_csrf_token();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $blockedUntil = (int) ($_SESSION['customer_login_blocked_until'] ?? 0);
    if (!gp_verify_csrf((string) ($_POST['csrf'] ?? ''))) {
        $error = 'La sesion de seguridad vencio. Recarga la pagina.';
    } elseif ($blockedUntil > time()) {
        $error = 'Demasiados intentos. Espera diez minutos.';
    } elseif (!Database::configured()) {
        $error = 'El portal V7.2 aun no ha sido instalado.';
    } else {
        try {
            $customer = (new CustomerPortal())->authenticate(
                (string) ($_POST['login'] ?? ''),
                (string) ($_POST['password'] ?? '')
            );
            if ($customer) {
                session_regenerate_id(true);
                $_SESSION['grandprix_customer_id'] = (int) $customer['id'];
                $_SESSION['grandprix_customer_key'] = (string) $customer['public_key'];
                $_SESSION['grandprix_customer_name'] = (string) $customer['full_name'];
                $_SESSION['customer_login_attempts'] = 0;
                gp_csrf_token();
                EventAudit::recordCustomer(['id'=>(int)$customer['id'],'name'=>(string)$customer['full_name'],'email'=>(string)($customer['email']??''),'role'=>'Cliente'],'auth','login_success','login','gp_customers',(int)$customer['id'],'Inicio de sesión de cliente exitoso.');
                header('Location: index.php');
                exit;
            }
            EventAudit::record(['email'=>(string)($_POST['login']??''),'name'=>'','role'=>'Cliente'],'customer','auth','login_failed','login',null,null,'Intento de inicio de sesión de cliente fallido.',['login_attempt'=>(string)($_POST['login']??'')]);
            $attempts = (int) ($_SESSION['customer_login_attempts'] ?? 0) + 1;
            if ($attempts >= 5) {
                $_SESSION['customer_login_blocked_until'] = time() + 600;
                $attempts = 0;
            }
            $_SESSION['customer_login_attempts'] = $attempts;
            $error = 'Usuario, cedula, correo o contraseña incorrectos.';
        } catch (Throwable $exception) {
            $reference = gp_runtime_error('customer-login', $exception);
            $error = 'No fue posible abrir el portal en este momento. Referencia tecnica: ' . $reference . '.';
        }
    }
}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#061a31"><title>Mi GRANDPRIX - Acceso</title><link rel="icon" href="../assets/grandprix-logo.png"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"><style>
*{box-sizing:border-box}body{margin:0;min-height:100dvh;display:grid;grid-template-columns:1.05fr .95fr;background:#edf4fa;font-family:Inter,Arial,sans-serif;color:#102943}.visual{position:relative;overflow:hidden;background:radial-gradient(circle at 50% 45%,#1477ff65,transparent 28%),linear-gradient(145deg,#04162b,#0b3b66);display:grid;place-items:center;padding:40px}.visual:before{content:"";position:absolute;width:680px;height:680px;border:1px solid #31d7e842;border-radius:50%;box-shadow:0 0 0 90px #ffffff05,0 0 0 180px #ffffff03}.visual img{width:min(620px,95%);position:relative;z-index:2;filter:drop-shadow(0 35px 32px #0008)}.visual-copy{position:absolute;z-index:3;left:48px;bottom:45px;color:#fff}.visual-copy small{color:#3dd9ec;letter-spacing:.2em;font-weight:900}.visual-copy h2{font-size:35px;margin:8px 0}.visual-copy p{color:#acc2d6;max-width:520px}.login{display:grid;place-items:center;padding:28px}.card{width:min(455px,100%);background:#fff;border:1px solid #d9e6ef;border-radius:28px;padding:36px;box-shadow:0 30px 80px #0a294225}.brand{width:230px;height:74px;object-fit:contain;background:#092f55;border-radius:15px;padding:10px 16px}.eyebrow{display:block;margin-top:25px;color:#1477ff;font-size:11px;font-weight:900;letter-spacing:.15em}.card h1{font-size:30px;margin:8px 0}.card>p{color:#71849a;line-height:1.5}label{display:block;font-size:12px;font-weight:800;margin:16px 0 6px}.field{position:relative}.field i{position:absolute;left:14px;top:15px;color:#7d91a5}.field input{width:100%;border:1px solid #d6e3ec;border-radius:12px;padding:13px 13px 13px 42px;font-size:14px;outline:0}.field input:focus{border-color:#1477ff;box-shadow:0 0 0 4px #1477ff16}button{width:100%;border:0;border-radius:12px;padding:14px;background:linear-gradient(100deg,#1477ff,#20b7df);color:#fff;font-weight:900;margin-top:22px;cursor:pointer;box-shadow:0 14px 28px #1477ff36}.error{background:#ffe7ec;color:#bd3148;padding:12px;border-radius:11px;font-size:12px}.privacy{margin-top:18px;background:#eef5fa;border-radius:11px;padding:12px;color:#647b91;font-size:11px;line-height:1.5}.privacy i{color:#12ae88;margin-right:5px}@media(max-width:800px){body{display:block}.visual{height:245px}.visual img{width:260px}.visual-copy{left:23px;bottom:20px}.visual-copy h2{font-size:23px}.visual-copy p{display:none}.login{padding:15px;margin-top:-24px;position:relative;z-index:5}.card{padding:25px;border-radius:24px}}
</style></head><body><section class="visual"><img src="../assets/moto-blue.png" alt="Motocicleta GRANDPRIX"><div class="visual-copy"><small>MI GRANDPRIX</small><h2>Tu moto y tu credito, contigo.</h2><p>Consulta el GPS asignado, tus 50 semanas y reporta transferencias desde un portal protegido.</p></div></section><main class="login"><form class="card" method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>"><img class="brand" src="../assets/grandprix-logo.png" alt="GRANDPRIX"><span class="eyebrow">PORTAL DE CLIENTES</span><h1>Bienvenido</h1><p>Ingresa con tu usuario, cedula o correo registrado.</p><?php if($error):?><div class="error"><i class="fa-solid fa-triangle-exclamation"></i> <?=htmlspecialchars($error)?></div><?php endif;?><label>Usuario, cedula o correo</label><div class="field"><i class="fa-solid fa-user"></i><input name="login" required autocomplete="username"></div><label>Contraseña</label><div class="field"><i class="fa-solid fa-lock"></i><input name="password" type="password" required autocomplete="current-password"></div><button><i class="fa-solid fa-shield-halved"></i> Entrar a Mi GRANDPRIX</button><div class="privacy"><i class="fa-solid fa-lock"></i> Tu cuenta solo permite consultar la motocicleta asociada a tu contrato. No contiene comandos remotos.</div></form></main></body></html>
