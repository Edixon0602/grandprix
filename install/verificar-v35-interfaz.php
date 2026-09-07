<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
$base = dirname(__DIR__);
$files = [
    'assets/grandprix-ui-v35.css',
    'assets/grandprix-auth-v35.css',
    'assets/grandprix-logo-light.svg',
    'assets/grandprix-logo-dark.svg',
    'assets/grandprix-symbol.svg',
    'assets/grandprix-icon-512.png',
    'cliente/assets/grandprix-ui-v35.css',
    'index.php',
    'monitor.php',
    'login.php',
    'cliente/index.php',
    'cliente/login.php',
];
$rows = [];
$ok = true;
foreach ($files as $file) {
    $exists = is_file($base . '/' . $file) && filesize($base . '/' . $file) > 0;
    $rows[] = [$file, $exists];
    if (!$exists) $ok = false;
}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#0B1D33"><title>GRANDPRIX · Verificar V35 UI</title><style>
*{box-sizing:border-box}body{margin:0;background:#f2f4f7;color:#10243d;font-family:Inter,Arial,sans-serif}.wrap{width:min(900px,calc(100% - 28px));margin:40px auto}.head{background:linear-gradient(135deg,#0b1d33,#123d6d);color:#fff;border-radius:22px;padding:26px;box-shadow:0 18px 45px #0b1d3328}.head h1{margin:5px 0 8px}.head p{margin:0;color:#c7d7e7}.tag{color:#ff9d33;font-size:11px;font-weight:900;letter-spacing:.16em}.card{margin-top:16px;background:#fff;border:1px solid #e3e9f0;border-radius:18px;padding:18px;box-shadow:0 12px 32px #0b1d3310}.row{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:11px 3px;border-bottom:1px solid #edf1f5}.row:last-child{border:0}.row code{font-size:12px}.ok{color:#147451;font-weight:900}.bad{color:#b43743;font-weight:900}.result{font-size:18px;font-weight:900;color:<?= $ok ? '#147451' : '#b43743' ?>}.actions{display:flex;gap:10px;margin-top:16px;flex-wrap:wrap}.actions a{display:inline-flex;align-items:center;justify-content:center;padding:12px 16px;border-radius:11px;text-decoration:none;font-weight:900;font-size:13px;background:#2563ff;color:#fff}.actions a.secondary{background:#0b1d33}@media(max-width:600px){.wrap{margin:18px auto}.head{padding:20px}.row{align-items:flex-start;flex-direction:column;gap:4px}}
</style></head><body><main class="wrap"><section class="head"><span class="tag">GRANDPRIX UI V35</span><h1>Verificación de interfaz</h1><p>Este verificador no modifica la base de datos ni la lógica de GPS. Solo confirma que los archivos visuales fueron cargados correctamente.</p></section><section class="card"><div class="result"><?= $ok ? '✓ Interfaz V35 instalada correctamente' : '⚠ Faltan archivos de la actualización' ?></div><?php foreach($rows as [$file,$exists]): ?><div class="row"><code><?=htmlspecialchars($file)?></code><span class="<?=$exists?'ok':'bad'?>"><?=$exists?'OK':'FALTA'?></span></div><?php endforeach; ?><div class="actions"><a href="../index.php">Abrir Administración</a><a class="secondary" href="../monitor.php">Abrir Monitoreo</a></div></section></main></body></html>
