<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/Database.php';
require_once __DIR__ . '/lib/AdminAuth.php';
require_once __DIR__ . '/lib/EventAudit.php';
gp_start_session();
$nextInput = (string) ($_GET['next'] ?? $_POST['next'] ?? 'index.php');
$next = preg_match('~^(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+\.php$~', $nextInput) ? $nextInput : 'index.php';
if (gp_is_admin()) { header('Location: ' . $next); exit; }
$config = gp_app_config();
if (!$config) { header('Location: install/'); exit; }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $attempts = (int) ($_SESSION['login_attempts'] ?? 0);
    $blockedUntil = (int) ($_SESSION['login_blocked_until'] ?? 0);
    if ($blockedUntil > time()) {
        $error = 'Demasiados intentos. Espera unos minutos e inténtalo otra vez.';
    } else {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $profile = null;
        $multiuserReady = false;
        try {
            if (Database::configured()) {
                $multiuserReady = AdminAuth::tablesReady();
                if ($multiuserReady) $profile = AdminAuth::attempt($email, $password);
            }
        } catch (Throwable) {
            $profile = null;
        }
        // La cuenta heredada solo funciona antes de instalar el sistema multiusuario.
        // Una cuenta suspendida en V8 no puede volver a entrar por el login anterior.
        if (!$profile && !$multiuserReady) {
            $emailOk = hash_equals(strtolower((string) ($config['admin_email'] ?? '')), $email);
            $passOk = password_verify($password, (string) ($config['password_hash'] ?? ''));
            if ($emailOk && $passOk) {
                $profile = [
                    'id' => 0, 'name' => 'Administrador GRANDPRIX', 'email' => (string) $config['admin_email'],
                    'role' => 'Superadministrador', 'permissions' => ['*'],
                ];
            }
        }
        if ($profile) {
            session_regenerate_id(true);
            AdminAuth::hydrateSession($profile);
            $_SESSION['login_attempts'] = 0;
            gp_csrf_token();
            EventAudit::recordAdmin($profile,'auth','login_success','login','gp_admin_users',(int)($profile['id']??0),'Inicio de sesión administrativo exitoso.',['next'=>$next]);
            header('Location: ' . $next);
            exit;
        }
        EventAudit::record(['email'=>$email,'name'=>'','role'=>''],'admin','auth','login_failed','login',null,null,'Intento de inicio de sesión administrativo fallido.',['email_attempt'=>$email]);
        $attempts++;
        $_SESSION['login_attempts'] = $attempts;
        if ($attempts >= 5) {
            $_SESSION['login_blocked_until'] = time() + 300;
            $_SESSION['login_attempts'] = 0;
        }
        $error = 'Correo o contraseña incorrectos.';
    }
}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#061a31"><title>Acceso · GRANDPRIX Control 360</title><link rel="icon" href="assets/grandprix-logo.png"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"><style>
*{box-sizing:border-box}body{margin:0;min-height:100dvh;display:grid;grid-template-columns:minmax(340px,46%) 1fr;background:#061a31;font-family:Inter,Arial,sans-serif;color:#0b2038}.visual{position:relative;overflow:hidden;background:radial-gradient(circle at 55% 45%,#1477ff55,transparent 25%),linear-gradient(145deg,#061a31,#0b365d);display:grid;place-items:center;padding:45px}.visual:before,.visual:after{content:"";position:absolute;border:1px solid #31cde53b;border-radius:50%;width:520px;height:520px}.visual:after{width:750px;height:750px}.visual img{width:min(440px,90%);filter:drop-shadow(0 30px 45px #0008);position:relative;z-index:2}.copy{position:absolute;left:45px;right:45px;bottom:45px;color:#fff;z-index:3}.copy small{letter-spacing:3px;color:#4edcf2;font-weight:800}.copy h2{font-size:34px;margin:10px 0 7px}.copy p{color:#a9bfd2;max-width:520px}.login{background:#f6faff;display:grid;place-items:center;padding:30px}.card{width:min(445px,100%);background:#fff;border:1px solid #dce7ef;border-radius:26px;padding:36px;box-shadow:0 28px 80px #061a3120}.brand{width:210px;height:70px;object-fit:contain;background:#092f55;border-radius:13px;padding:8px 14px}.eyebrow{display:block;color:#1677ff;letter-spacing:2px;font-size:11px;font-weight:900;margin-top:27px}.card h1{font-size:30px;margin:8px 0}.card>p{color:#71849a;font-size:14px;margin-bottom:24px}label{display:block;font-size:12px;font-weight:800;margin:15px 0 6px}.input{position:relative}.input i{position:absolute;left:14px;top:14px;color:#8295a8}.input input{width:100%;border:1px solid #d7e3ed;border-radius:12px;padding:13px 14px 13px 42px;font-size:14px;outline:0}.input input:focus{border-color:#1677ff;box-shadow:0 0 0 4px #1677ff15}button{width:100%;border:0;border-radius:12px;padding:14px;background:linear-gradient(105deg,#126be8,#20b8e5);color:#fff;font-weight:900;margin-top:22px;cursor:pointer;box-shadow:0 12px 25px #1477ff34}.error{padding:11px 13px;border-radius:10px;background:#ffe8eb;color:#bd3146;font-size:12px}.secure{margin-top:18px;text-align:center;color:#8295a8;font-size:11px}.secure i{color:#13b994;margin-right:5px}@media(max-width:820px){body{display:block;background:#f6faff}.visual{height:230px;padding:20px}.visual img{width:220px}.copy{left:24px;right:24px;bottom:20px}.copy h2{font-size:22px}.copy p{display:none}.login{padding:18px;margin-top:-20px;position:relative;z-index:5;background:transparent}.card{padding:25px;border-radius:24px}}
</style></head><body><section class="visual"><img src="assets/moto-blue.png" alt="Motocicleta GRANDPRIX"><div class="copy"><small>CONTROL 360</small><h2>Financiamiento conectado.</h2><p>Seguridad GPS, cartera y operación en una sola plataforma.</p></div></section><main class="login"><form class="card" method="post" autocomplete="on"><input type="hidden" name="next" value="<?=htmlspecialchars($next)?>"><img class="brand" src="assets/grandprix-logo.png" alt="GRANDPRIX"><span class="eyebrow">ACCESO PROTEGIDO</span><h1>Bienvenido</h1><p>Ingresa con la cuenta administrativa configurada durante la instalación.</p><?php if($error):?><div class="error"><i class="fa-solid fa-triangle-exclamation"></i> <?=htmlspecialchars($error)?></div><?php endif;?><label>Correo administrativo</label><div class="input"><i class="fa-solid fa-envelope"></i><input name="email" type="email" required autocomplete="username"></div><label>Contraseña</label><div class="input"><i class="fa-solid fa-lock"></i><input name="password" type="password" required autocomplete="current-password"></div><button><i class="fa-solid fa-shield-halved"></i> Entrar a Control 360</button><div class="secure"><i class="fa-solid fa-lock"></i> Sesión cifrada y comandos GPS protegidos</div></form></main></body></html>
