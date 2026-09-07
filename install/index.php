<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$configDir = $root . '/config';
$lock = $configDir . '/install.lock';
$done = file_exists($lock);
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$done) {
    $company = trim($_POST['company'] ?? 'GRANDPRIX INVERSIONES');
    $email = trim($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $error = 'Escribe un correo válido.';
    elseif (strlen($password) < 8) $error = 'La contraseña debe tener al menos 8 caracteres.';
    elseif (!is_writable($configDir)) $error = 'La carpeta config no tiene permiso de escritura.';
    else {
        $data = "<?php\nreturn " . var_export(['company'=>$company,'admin_email'=>$email,'password_hash'=>password_hash($password, PASSWORD_DEFAULT),'installed_at'=>date('c')], true) . ";\n";
        if (file_put_contents($configDir . '/app.php', $data, LOCK_EX) === false || file_put_contents($lock, date('c'), LOCK_EX) === false) $error = 'No fue posible guardar la configuración.';
        else { header('Location: ../?installed=1'); exit; }
    }
}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Instalar GRANDPRIX GPS</title><style>
*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:radial-gradient(circle at 20% 10%,#174777,#071a31 55%);font-family:Inter,Arial;color:#17283e;padding:20px}.card{width:min(540px,100%);background:#fff;border-radius:24px;overflow:hidden;box-shadow:0 35px 90px #0007}.head{background:#091f3a;padding:25px 30px;color:#fff}.head img{width:220px;max-height:65px;object-fit:contain}.head p{margin:12px 0 0;color:#a8bdd4;font-size:13px}.body{padding:28px 30px}.step{display:flex;gap:10px;margin-bottom:20px}.step i{width:28px;height:28px;border-radius:50%;background:#1976ff;color:#fff;display:grid;place-items:center;font-style:normal;font-weight:800;font-size:12px}.step div{flex:1;border-bottom:1px solid #e7edf3;padding-bottom:15px}.step b,.step small{display:block}.step small{color:#7c8b9d;margin-top:3px}label{display:block;font-size:12px;font-weight:700;margin:14px 0 6px}input{width:100%;border:1px solid #d7e0e9;padding:12px 13px;border-radius:10px;outline:0}input:focus{border-color:#1976ff;box-shadow:0 0 0 3px #1976ff18}button,a.go{width:100%;border:0;border-radius:11px;background:linear-gradient(90deg,#1672f5,#249fe8);color:#fff;padding:14px;font-weight:800;margin-top:20px;cursor:pointer;text-decoration:none;display:block;text-align:center}.error{background:#ffe8eb;color:#c83046;padding:11px;border-radius:9px;font-size:12px}.ok{background:#e3faf4;color:#087e67;padding:15px;border-radius:10px}.check{display:flex;justify-content:space-between;background:#f5f8fb;padding:10px 12px;border-radius:9px;margin:7px 0;font-size:12px}.check b{color:#079678}
</style></head><body><main class="card"><div class="head"><img src="../assets/grandprix-logo.png"><p>Asistente de instalación · Control GPS de motos financiadas</p></div><div class="body">
<?php if($done): ?><div class="ok"><b>El sistema ya está instalado.</b><br>Puedes ejecutar la actualización técnica V7.2 sin borrar la configuración existente.</div><a class="go" href="actualizar-v7-2.php">Actualizar a V7.2</a><a class="go" href="../">Abrir plataforma</a>
<?php else: ?><div class="step"><i>1</i><div><b>Comprobación del servidor</b><small>Todo listo para Hostinger Web Business</small></div></div><div class="check"><span>PHP 8.0 o superior</span><b><?=version_compare(PHP_VERSION,'8.0','>=')?'CORRECTO':'REVISAR'?></b></div><div class="check"><span>Carpeta de configuración</span><b><?=is_writable($configDir)?'ESCRIBIBLE':'SIN PERMISO'?></b></div><?php if($error):?><p class="error"><?=htmlspecialchars($error)?></p><?php endif;?><form method="post"><label>Nombre de la empresa</label><input name="company" value="GRANDPRIX INVERSIONES" required><label>Correo del administrador</label><input type="email" name="email" placeholder="administracion@grandprix.com" required><label>Contraseña del administrador</label><input type="password" name="password" minlength="8" placeholder="Mínimo 8 caracteres" required><button>Instalar plataforma GPS</button></form><?php endif; ?></div></main></body></html>
