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
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#0B1D33"><title>Acceso · GRANDPRIX 360</title><link rel="icon" type="image/png" href="assets/grandprix-symbol.png"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" media="print" onload="this.media='all'"><noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"></noscript><style>
*{box-sizing:border-box}body{margin:0;min-height:100dvh;display:grid;grid-template-columns:minmax(340px,46%) 1fr;background:#061a31;font-family:Inter,Arial,sans-serif;color:#0b2038}.visual{position:relative;overflow:hidden;background:radial-gradient(circle at 55% 45%,#1477ff55,transparent 25%),linear-gradient(145deg,#061a31,#0b365d);display:grid;place-items:center;padding:45px}.visual:before,.visual:after{content:"";position:absolute;border:1px solid #31cde53b;border-radius:50%;width:520px;height:520px}.visual:after{width:750px;height:750px}.visual img{width:min(440px,90%);filter:drop-shadow(0 30px 45px #0008);position:relative;z-index:2}.copy{position:absolute;left:45px;right:45px;bottom:45px;color:#fff;z-index:3}.copy small{letter-spacing:3px;color:#4edcf2;font-weight:800}.copy h2{font-size:34px;margin:10px 0 7px}.copy p{color:#a9bfd2;max-width:520px}.login{background:#f6faff;display:grid;place-items:center;padding:30px}.card{width:min(445px,100%);background:#fff;border:1px solid #dce7ef;border-radius:26px;padding:36px;box-shadow:0 28px 80px #061a3120}.brand{width:250px;min-height:70px;background:#092f55;border-radius:13px;padding:10px 13px;display:flex;align-items:center}.brand .gp-brand-copy b{font-size:18px}.brand .gp-brand-copy small{font-size:7px}.brand .gp-brand-inline svg{width:42px;height:42px}.eyebrow{display:block;color:#1677ff;letter-spacing:2px;font-size:11px;font-weight:900;margin-top:27px}.card h1{font-size:30px;margin:8px 0}.card>p{color:#71849a;font-size:14px;margin-bottom:24px}label{display:block;font-size:12px;font-weight:800;margin:15px 0 6px}.input{position:relative}.input i{position:absolute;left:14px;top:14px;color:#8295a8}.input input{width:100%;border:1px solid #d7e3ed;border-radius:12px;padding:13px 14px 13px 42px;font-size:14px;outline:0}.input input:focus{border-color:#1677ff;box-shadow:0 0 0 4px #1677ff15}button{width:100%;border:0;border-radius:12px;padding:14px;background:linear-gradient(105deg,#126be8,#20b8e5);color:#fff;font-weight:900;margin-top:22px;cursor:pointer;box-shadow:0 12px 25px #1477ff34}.error{padding:11px 13px;border-radius:10px;background:#ffe8eb;color:#bd3146;font-size:12px}.secure{margin-top:18px;text-align:center;color:#8295a8;font-size:11px}.secure i{color:#13b994;margin-right:5px}@media(max-width:820px){body{display:block;background:#f6faff}.visual{height:230px;padding:20px}.visual img{width:220px}.copy{left:24px;right:24px;bottom:20px}.copy h2{font-size:22px}.copy p{display:none}.login{padding:18px;margin-top:-20px;position:relative;z-index:5;background:transparent}.card{padding:25px;border-radius:24px}}
.gp-brand-inline{display:flex;align-items:center;gap:10px;color:#fff}.gp-brand-inline svg{width:42px;height:42px}.gp-brand-copy b,.gp-brand-copy small{display:block}.gp-brand-copy b{font-weight:800;letter-spacing:.08em;color:#fff}.gp-brand-copy small{margin-top:5px;letter-spacing:.18em;color:#AFC3D5;font-weight:700;white-space:nowrap}</style><!-- GRANDPRIX PWA V46 DIRECT INSTALL -->
<!-- GRANDPRIX PWA V47 · instalación nativa directa cuando Chrome la habilita -->
<link rel="manifest" href="/index.php?gp_pwa=manifest&v=47">
<link rel="apple-touch-icon" href="/index.php?gp_pwa=icon&size=180">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="GRANDPRIX 360">
<meta name="application-name" content="GRANDPRIX 360">
<style id="gp-pwa-v47-style">
#gp-pwa-v47{position:fixed;left:12px;right:12px;bottom:calc(12px + env(safe-area-inset-bottom));z-index:2147483600;display:none;font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#0B1D33}
#gp-pwa-v47.gp-show{display:block;animation:gpPwa47Up .22s ease both}
#gp-pwa-v47 .gp-card{display:grid;grid-template-columns:48px minmax(0,1fr) auto;align-items:center;gap:10px;padding:10px 11px;background:rgba(255,255,255,.99);border:1px solid #DCE6F1;border-radius:18px;box-shadow:0 16px 48px rgba(5,35,65,.27)}
#gp-pwa-v47 .gp-icon{width:48px;height:48px;border-radius:14px;overflow:hidden;background:#0B1D33;display:grid;place-items:center}#gp-pwa-v47 .gp-icon svg{width:100%;height:100%;display:block}
#gp-pwa-v47 .gp-copy strong{display:block;font-size:13px;line-height:1.2;font-weight:900}#gp-pwa-v47 .gp-copy span{display:block;margin-top:3px;font-size:10.5px;line-height:1.35;color:#64748B}
#gp-pwa-v47 .gp-install{height:40px;padding:0 15px;border:0;border-radius:11px;background:#2563FF;color:#fff;font:inherit;font-size:11px;font-weight:900;cursor:pointer;box-shadow:0 8px 20px rgba(37,99,255,.26)}
#gp-pwa-v47 .gp-install:disabled{opacity:.6}
@keyframes gpPwa47Up{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:none}}
@media(min-width:901px){#gp-pwa-v47{display:none!important}}@media(display-mode:standalone){#gp-pwa-v47{display:none!important}}
</style>
<script id="gp-pwa-v47-script">(()=>{'use strict';
const NAME="GRANDPRIX 360",SW="/index.php?gp_pwa=sw&v=47",SCOPE="/";
const ua=navigator.userAgent||'',uad=navigator.userAgentData;const mobile=!!(uad&&uad.mobile)||/Android|iPhone|iPad|iPod|Mobile/i.test(ua)||((matchMedia('(pointer:coarse)').matches)&&innerWidth<=900);
const standalone=()=>matchMedia('(display-mode:standalone)').matches||navigator.standalone===true;
let deferred=null,box=null;
function hide(){if(box){box.remove();box=null;}}
function render(){if(!mobile||standalone()||!deferred||box)return;box=document.createElement('aside');box.id='gp-pwa-v47';box.innerHTML=`<div class="gp-card"><div class="gp-icon"><svg viewBox="0 0 128 128" aria-hidden="true" xmlns="http://www.w3.org/2000/svg"><defs><linearGradient id="gpPwa47B" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#0C58D8"/><stop offset="1" stop-color="#56A4FF"/></linearGradient></defs><rect width="128" height="128" rx="30" fill="#071D35"/><path fill="#fff" d="M18 27h63c8 0 14 7 12 15l-4 13H52l-4 13h29l-4 13H43c-15 0-23-13-19-27l4-13c4-9 12-14 23-14H18z"/><path fill="url(#gpPwa47B)" d="M67 27h42l-8 29H83l-5 17H61l6-46z"/><path fill="url(#gpPwa47B)" d="M91 61h18l-8 28H83l8-28z"/><path fill="#FF8A00" d="M22 88h13l-4 13H18z"/></svg></div><div class="gp-copy"><strong>Instalar ${NAME}</strong><span>Toca Instalar para abrir la instalación de Chrome.</span></div><button class="gp-install" type="button">Instalar</button></div>`;document.body.appendChild(box);const btn=box.querySelector('.gp-install');btn.addEventListener('click',async()=>{if(!deferred)return;const ev=deferred;deferred=null;btn.disabled=true;try{await ev.prompt();const choice=await ev.userChoice;hide();if(choice&&choice.outcome==='accepted')return;}catch(_e){hide();}});requestAnimationFrame(()=>box.classList.add('gp-show'));}
window.addEventListener('beforeinstallprompt',e=>{e.preventDefault();deferred=e;if(document.body)render();else document.addEventListener('DOMContentLoaded',render,{once:true});});
window.addEventListener('appinstalled',()=>{deferred=null;hide();});
if('serviceWorker'in navigator&&(location.protocol==='https:'||location.hostname==='localhost')){navigator.serviceWorker.register(SW,{scope:SCOPE,updateViaCache:'none'}).then(r=>r.update().catch(()=>{})).catch(()=>{});}
window.__GP_PWA_V47__={get installReady(){return !!deferred;},name:NAME};
})();</script></head><body><section class="visual"><img src="assets/moto-blue.png" alt="Motocicleta GRANDPRIX"><div class="copy"><small>CONTROL 360</small><h2>Financiamiento conectado.</h2><p>Seguridad GPS, cartera y operación en una sola plataforma.</p></div></section><main class="login"><form class="card" method="post" autocomplete="on"><input type="hidden" name="next" value="<?=htmlspecialchars($next)?>"><div class="brand brand-v41"><span class="gp-brand-inline"><svg viewBox="0 0 128 128" aria-hidden="true"><defs><linearGradient id="gpB" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#0C58D8"/><stop offset="1" stop-color="#56A4FF"/></linearGradient></defs><rect width="128" height="128" rx="30" fill="#071D35"/><path fill="#fff" d="M18 27h63c8 0 14 7 12 15l-4 13H52l-4 13h29l-4 13H43c-15 0-23-13-19-27l4-13c4-9 12-14 23-14H18z"/><path fill="url(#gpB)" d="M67 27h42l-8 29H83l-5 17H61l6-46z"/><path fill="url(#gpB)" d="M91 61h18l-8 28H83l8-28z"/><path fill="#FF8A00" d="M22 88h13l-4 13H18z"/></svg><span class="gp-brand-copy"><b>GRANDPRIX</b><small>FINANCIAMIENTO DE MOTOS</small></span></span></div><span class="eyebrow">ACCESO PROTEGIDO</span><h1>Bienvenido</h1><p>Ingresa con la cuenta administrativa configurada durante la instalación.</p><?php if($error):?><div class="error"><i class="fa-solid fa-triangle-exclamation"></i> <?=htmlspecialchars($error)?></div><?php endif;?><label>Correo administrativo</label><div class="input"><i class="fa-solid fa-envelope"></i><input name="email" type="email" required autocomplete="username"></div><label>Contraseña</label><div class="input"><i class="fa-solid fa-lock"></i><input name="password" type="password" required autocomplete="current-password"></div><button><i class="fa-solid fa-shield-halved"></i> Entrar a Control 360</button><div class="secure"><i class="fa-solid fa-lock"></i> Sesión cifrada y comandos GPS protegidos</div></form></main></body></html>
