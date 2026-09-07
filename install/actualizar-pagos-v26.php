<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/bootstrap.php';
require_once __DIR__.'/../lib/Database.php';
require_once __DIR__.'/../lib/PaymentReconcileV26.php';
gp_start_session();gp_require_admin(false);
$ok=false;$error='';
try{PaymentReconcileV26::ensureSchema();$ok=true;}catch(Throwable $e){$error=$e->getMessage();}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>GRANDPRIX · Actualizar pagos V26</title><style>body{margin:0;background:#f3f6fa;font-family:Inter,Arial,sans-serif;color:#10233d}.wrap{max-width:760px;margin:60px auto;padding:20px}.card{background:#fff;border-radius:24px;padding:34px;box-shadow:0 20px 60px #0a1c3420}.brand{font-weight:900;color:#0b2a4d;letter-spacing:.08em}.tag{display:inline-block;margin:18px 0 8px;padding:7px 11px;border-radius:999px;background:#eaf2ff;color:#1b4f91;font-weight:800;font-size:12px}.ok{background:#edfff5;border:1px solid #b8efd0;color:#0e6936;padding:18px;border-radius:16px}.bad{background:#fff1f2;border:1px solid #ffc7cc;color:#9d1c2b;padding:18px;border-radius:16px}code{background:#f3f6fa;padding:3px 7px;border-radius:7px}</style></head><body><div class="wrap"><div class="card"><div class="brand">GRANDPRIX CONTROL 360</div><span class="tag">PAGOS MULTIMONEDA + ABONOS · V26</span><h1>Actualización financiera</h1><?php if($ok):?><div class="ok"><b>Actualización completada.</b><br>Las tablas auxiliares de pagos y distribución de abonos ya están listas. Puedes volver a <code>index.php</code>.</div><?php else:?><div class="bad"><b>No se pudo completar:</b><br><?=htmlspecialchars($error)?></div><?php endif;?></div></div></body></html>
