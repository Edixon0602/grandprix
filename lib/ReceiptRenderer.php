<?php
declare(strict_types=1);

final class ReceiptRenderer
{
    private static function e(mixed $value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
    private static function money(mixed $value): string
    {
        if ($value === null || $value === '') return '—';
        return '$'.number_format((float)$value,2,',','.');
    }
    private static function paymentMoney(array $r): string
    {
        $currency=mb_strtoupper((string)($r['currency']??'USD'));
        $amount=$r['amount']??null;
        if($amount===null||$amount==='')return '—';
        if($currency==='BS')return 'Bs. '.number_format((float)$amount,2,',','.');
        return '$'.number_format((float)$amount,2,',','.');
    }
    private static function date(mixed $value): string
    {
        $raw=(string)($value??'');if($raw==='')return '—';$ts=strtotime($raw);return $ts?date('d/m/Y',$ts):$raw;
    }

    public static function html(array $r, bool $customerMode=false, string $pdfUrl=''): string
    {
        $paidWeeks=array_values(array_map('intval',(array)($r['paidWeeks']??[])));
        $pending=array_values(array_map('intval',(array)($r['pendingWeeks']??[])));
        $allocations=array_values((array)($r['allocations']??[]));
        $partial=array_values(array_filter($allocations,static fn(array $a):bool=>empty($a['completed'])&&(float)($a['allocated']??0)>0));
        sort($pending);
        $paidLabel=$paidWeeks?implode(', ',$paidWeeks):'Ninguna completada';
        $lastAllocation=$allocations?end($allocations):null;
        $primaryWeek=$paidWeeks?end($paidWeeks):(is_array($lastAllocation)?(int)($lastAllocation['weekNumber']??0):'—');
        $partialLabel='Sin abono parcial';
        if($partial){$x=$partial[count($partial)-1];$partialLabel='Semana '.(int)($x['weekNumber']??0).' · '.number_format((float)($x['paidPercentageAfter']??0),0).'% · saldo '.self::money($x['balanceAfter']??0);}
        $pendingHtml=$pending?implode('',array_map(static fn(int $w):string=>'<b>'.$w.'</b>',$pending)):'<span class="none">Sin semanas vencidas</span>';
        $allocationHtml=$allocations?implode('',array_map(static function(array $a):string{
            $done=!empty($a['completed']);$week=(int)($a['weekNumber']??0);$pct=(float)($a['paidPercentageAfter']??0);$allocated=self::money($a['allocated']??0);$balance=self::money($a['balanceAfter']??0);
            return '<article class="allocation '.($done?'paid':'partial').'"><span>Semana '.$week.'</span><b>'.($done?'PAGADA 100%':'ABONO '.number_format($pct,0).'%').'</b><small>'.$allocated.' aplicados'.($done?'':' · saldo '.$balance).'</small></article>';
        },$allocations)):'<span class="none">Sin distribución registrada</span>';
        $status=$pending?'CON CUOTAS PENDIENTES':'AL DÍA';
        $receiptNumber=self::e($r['receiptNumber']??'RECIBO GRANDPRIX');
        $logo='../assets/grandprix-inversiones-receipt.svg';
        $pdfButton=$pdfUrl!==''?'<a class="tool pdf" href="'.self::e($pdfUrl).'" download><i>↓</i><span>Descargar PDF</span></a>':'';
        $close=$customerMode?'':'<button class="tool secondary" onclick="window.close()"><i>×</i><span>Cerrar</span></button>';
        $statusText=$pending?'El pago fue aplicado, pero todavía existen semanas vencidas.':($partial?'No hay mora vencida; existe un abono parcial en la próxima cuota.':'No quedan semanas vencidas después de este pago.');
        $nextWeek=self::e($r['nextWeek']??'—');
        $phone=self::e($r['phone']??'—');

        return '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><title>'.$receiptNumber.' · GRANDPRIX</title><style>
        :root{--navy:#082b50;--navy2:#0f426f;--blue:#1268ce;--ink:#0a2440;--muted:#6b8298;--line:#d9e5ef;--bg:#eef4f9;--red:#bd1e3d;--green:#0d8b60;--amber:#a96a00}
        *{box-sizing:border-box}html{background:var(--bg)}body{margin:0;background:var(--bg);font-family:Inter,Arial,Helvetica,sans-serif;color:var(--ink);-webkit-font-smoothing:antialiased}.toolbar{position:sticky;top:0;z-index:10;display:flex;justify-content:center;gap:9px;padding:10px 12px;background:rgba(7,31,61,.96);backdrop-filter:blur(10px)}.tool{display:inline-flex;align-items:center;justify-content:center;gap:8px;border:0;border-radius:11px;background:#1478ff;color:#fff;font-weight:800;padding:10px 14px;cursor:pointer;text-decoration:none;font-size:12px}.tool.pdf{background:#fff;color:#0b3157}.tool.secondary{background:#163f68}.tool i{font-style:normal;font-size:14px}
        .sheet{width:min(760px,calc(100% - 22px));margin:14px auto 28px;background:#fff;border-radius:22px;overflow:hidden;box-shadow:0 18px 55px rgba(8,34,61,.14)}.head{display:flex;align-items:center;justify-content:space-between;gap:18px;padding:18px 24px;border-bottom:1px solid var(--line)}.head img{width:180px;max-height:48px;object-fit:contain}.head-meta{text-align:right}.head-meta small,.head-meta strong{display:block}.head-meta small{color:var(--muted);font-size:9px;font-weight:900;letter-spacing:.4px}.head-meta strong{margin-top:4px;font-size:12px}
        .hero{display:grid;grid-template-columns:130px 1fr;gap:14px;padding:18px 24px;background:linear-gradient(135deg,var(--navy),var(--navy2));color:#fff}.week{display:grid;place-items:center;align-content:center;min-height:108px;border-radius:16px;background:#072543;border:1px solid rgba(255,255,255,.12)}.week small{font-size:8px;font-weight:900;letter-spacing:.8px}.week strong{margin-top:4px;font-size:48px;line-height:1}.hero-info h1{margin:5px 0 5px;font-size:22px;line-height:1.05}.hero-info>p{margin:0;color:#cee0ef;font-size:11px}.hero-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:7px;margin-top:15px}.hero-grid span{padding:10px;border-radius:10px;background:rgba(255,255,255,.08)}.hero-grid small,.hero-grid b{display:block}.hero-grid small{font-size:7px;color:#bcd2e5;margin-bottom:4px}.hero-grid b{font-size:10px}
        .section{padding:15px 24px}.columns{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.card{border:1px solid var(--line);border-radius:14px;padding:13px;background:#fbfdff;min-width:0}.card h2{font-size:9px;margin:0 0 8px;text-transform:uppercase;letter-spacing:.5px;color:#1268ce;text-decoration:underline}.row{display:flex;justify-content:space-between;gap:10px;padding:6px 0;border-bottom:1px solid #e9eff4;font-size:8px}.row:last-child{border:0}.row span{color:var(--muted)}.row b{text-align:right;max-width:64%;overflow-wrap:anywhere}
        .allocations{margin-top:10px;padding:13px;border:1px solid #dce8f2;background:#f8fbfe;border-radius:13px}.allocations h3{margin:0 0 9px;font-size:8px;letter-spacing:.5px;color:var(--blue)}.allocation-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:7px}.allocation{display:grid;gap:3px;padding:10px;border-radius:10px;border:1px solid #dce8f2;background:#fff}.allocation span{font-size:7px;color:var(--muted);font-weight:800}.allocation b{font-size:9px}.allocation small{font-size:7px;color:var(--muted)}.allocation.paid{border-color:#bce8d5;background:#edf9f4;color:var(--green)}.allocation.partial{border-color:#f5d38b;background:#fff8e8;color:var(--amber)}
        .pending{margin-top:10px;padding:13px;border:1px solid #ffc7d1;background:#fff2f4;border-radius:13px}.pending-title{display:flex;justify-content:space-between;gap:10px;align-items:center;color:var(--red);font-weight:900;font-size:8px}.pending-chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:9px}.pending-chips b{display:grid;place-items:center;min-width:34px;padding:7px;border-radius:8px;background:#e43450;color:#fff}.pending-chips .none{padding:8px 11px;border-radius:8px;background:#e2f8ef;color:var(--green);font-weight:900;font-size:9px}
        .status{display:flex;align-items:center;gap:10px;margin-top:10px;padding:12px;border-radius:13px}.status.ok{background:#e7f8f1;color:var(--green)}.status.late{background:#fff4dc;color:var(--amber)}.status i{width:35px;height:35px;border-radius:50%;background:#fff;display:grid;place-items:center;font-style:normal;font-size:18px}.status b,.status small{display:block}.status b{font-size:10px}.status small{margin-top:2px;font-size:8px;font-weight:600}
        .foot{display:flex;justify-content:space-between;gap:16px;align-items:center;padding:15px 24px;background:#071f3d;color:#fff}.foot small{color:#a9bfd2;font-size:8px}.foot strong{display:block;margin-top:3px;font-size:10px}.foot-right{text-align:right}
        @media(max-width:640px){body{background:#fff}.toolbar{justify-content:stretch;display:grid;grid-template-columns:1fr 1fr;padding:8px}.tool{width:100%;padding:11px 8px}.tool.secondary{grid-column:1/-1}.sheet{width:100%;margin:0;border-radius:0;box-shadow:none}.head{padding:14px 15px;align-items:flex-start}.head img{width:145px}.head-meta small{font-size:7px}.head-meta strong{font-size:10px}.hero{grid-template-columns:92px 1fr;padding:14px 15px}.week{min-height:90px}.week strong{font-size:39px}.hero-info h1{font-size:17px}.hero-info>p{font-size:9px}.hero-grid{grid-template-columns:1fr;gap:5px;margin-top:10px}.hero-grid span{padding:7px 9px}.section{padding:12px 15px}.columns{grid-template-columns:1fr}.card{padding:12px}.allocation-grid{grid-template-columns:1fr}.pending-title{align-items:flex-start;flex-direction:column}.status{align-items:flex-start}.foot{align-items:flex-start;flex-direction:column;padding:14px 15px}.foot-right{text-align:left}.row{font-size:9px}}
        @media(max-width:380px){.hero{grid-template-columns:1fr}.week{min-height:74px}.week strong{font-size:34px}.head img{width:125px}}
        @media print{html,body{background:#fff}.toolbar{display:none}.sheet{width:148mm;max-width:none;margin:0;box-shadow:none;border-radius:0}.head{padding:7mm 8mm}.hero{padding:6mm 8mm}.section{padding:5mm 8mm}.foot{padding:5mm 8mm}.head,.hero,.section,.foot,.card,.allocations,.allocation,.pending,.status{break-inside:avoid}@page{size:A5 portrait;margin:0}}
        </style></head><body><div class="toolbar"><button class="tool" onclick="window.print()"><i>▣</i><span>Imprimir</span></button>'.$pdfButton.$close.'</div><main class="sheet"><header class="head"><img src="'.$logo.'" alt="GRANDPRIX Inversiones"><div class="head-meta"><small>RECIBO DE PAGO SEMANAL</small><strong>'.$receiptNumber.'</strong></div></header><section class="hero"><div class="week"><small>ÚLTIMA SEMANA APLICADA</small><strong>'.self::e($primaryWeek).'</strong></div><div class="hero-info"><h1>Pago registrado correctamente</h1><p>Comprobante oficial del pago aplicado al contrato GRANDPRIX.</p><div class="hero-grid"><span><small>Monto pagado</small><b>'.self::paymentMoney($r).'</b></span><span><small>Fecha de pago</small><b>'.self::date($r['paidAt']??null).'</b></span><span><small>Próxima cuota</small><b>'.self::date($r['nextDueDate']??null).'</b></span></div></div></section><section class="section"><div class="columns"><article class="card"><h2>Datos del cliente</h2><div class="row"><span>Cliente</span><b>'.self::e($r['clientName']??'—').'</b></div><div class="row"><span>Cédula</span><b>'.self::e($r['identityDocument']??'—').'</b></div><div class="row"><span>Teléfono</span><b>'.$phone.'</b></div><div class="row"><span>Dirección</span><b>'.self::e($r['address']??'—').'</b></div></article><article class="card"><h2>Datos de la moto</h2><div class="row"><span>Modelo</span><b>'.self::e($r['model']??'—').'</b></div><div class="row"><span>Placa</span><b>'.self::e($r['plate']??'—').'</b></div><div class="row"><span>Contrato</span><b>'.self::e($r['contractNumber']??'—').'</b></div><div class="row"><span>Plan</span><b>'.(int)($r['totalWeeks']??50).' semanas</b></div></article><article class="card"><h2>Detalle del pago</h2><div class="row"><span>Semana(s) completadas</span><b>'.self::e($paidLabel).'</b></div><div class="row"><span>Abono parcial</span><b>'.self::e($partialLabel).'</b></div><div class="row"><span>Forma de pago</span><b>'.self::e($r['paymentMethod']??'—').'</b></div><div class="row"><span>Banco / referencia</span><b>'.self::e(trim((string)($r['bank']??'—')).' · '.trim((string)($r['reference']??'—'))).'</b></div></article></div><div class="allocations"><h3>DISTRIBUCIÓN DEL PAGO</h3><div class="allocation-grid">'.$allocationHtml.'</div></div><div class="pending"><div class="pending-title"><span>SEMANAS PENDIENTES AL MOMENTO DEL PAGO</span><span>'.count($pending).' pendiente(s)</span></div><div class="pending-chips">'.$pendingHtml.'</div></div><div class="status '.($pending?'late':'ok').'"><i>'.($pending?'!':'✓').'</i><div><b>'.$status.'</b><small>'.$statusText.'</small></div></div></section><footer class="foot"><div><small>GRANDPRIX INVERSIONES</small><strong>Gracias por su pago.</strong></div><div class="foot-right"><small>Próxima semana: '.$nextWeek.'</small><strong>'.self::date($r['nextDueDate']??null).'</strong></div></footer></main></body></html>';
    }
}
