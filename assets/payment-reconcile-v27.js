(()=>{
'use strict';
const GP=window.GRANDPRIX||{};
const API=GP.paymentReconcileApi||'api/payment-reconcile-v27.php';
const esc=s=>String(s??'').replace(/[&<>'"]/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[m]));
const money=n=>Number(n||0).toLocaleString('es-VE',{minimumFractionDigits:2,maximumFractionDigits:2});
const state={customer:null,account:null,preview:null,searchTimer:null};

async function request(action,opt={}){
  const method=opt.method||'GET';
  let url=`${API}?action=${encodeURIComponent(action)}`;
  const init={method,headers:{'Accept':'application/json'}};
  if(method==='GET'&&opt.params){url+='&'+new URLSearchParams(opt.params).toString();}
  if(method==='POST'){
    init.headers['Content-Type']='application/json';
    init.headers['X-CSRF-Token']=GP.csrf||'';
    init.body=JSON.stringify({...opt.body,csrf:GP.csrf||''});
  }
  const r=await fetch(url,init);let j={};
  try{j=await r.json();}catch(e){throw new Error('Respuesta inválida del servidor.');}
  if(!r.ok||!j.ok)throw new Error(j.error||'No se pudo completar la operación.');
  return j;
}
function toast(msg,type=''){
  document.querySelectorAll('.v27-toast').forEach(x=>x.remove());
  const d=document.createElement('div');d.className=`v27-toast ${type}`;d.textContent=msg;document.body.appendChild(d);setTimeout(()=>d.remove(),4200);
}
function statusLabel(s){return({paid:'Pagada',partial:'Abono parcial',overdue:'Vencida',pending:'Pendiente'})[s]||s;}
function close(){document.getElementById('v27PaymentOverlay')?.remove();state.customer=null;state.account=null;state.preview=null;}
function modal(){
  close();
  const o=document.createElement('div');o.className='v27-overlay';o.id='v27PaymentOverlay';
  o.innerHTML=`<section class="v27-modal" role="dialog" aria-modal="true"><header class="v27-head"><div><small>GRANDPRIX · FINANZAS</small><h2>Conciliar pago / registrar abono</h2></div><button class="v27-close" data-v27-close><i class="fa-solid fa-xmark"></i></button></header><div class="v27-body"><div class="v27-grid">
  <div><div class="v27-card"><h3>1. Buscar cliente</h3><div class="v27-search-row"><input class="v27-input" id="v27Search" placeholder="Nombre, cédula, contrato o placa" autocomplete="off"><button class="v27-btn" id="v27SearchBtn"><i class="fa-solid fa-magnifying-glass"></i></button></div><div id="v27Results" class="v27-results"><div class="v27-empty">Escribe al menos 2 caracteres para buscar.</div></div><div id="v27Customer" class="v27-customer"></div></div>
  <div class="v27-card" style="margin-top:16px"><h3>2. Datos del pago</h3><div class="v27-money-row"><div><label class="v27-label">Monto</label><input class="v27-input" id="v27Amount" inputmode="decimal" placeholder="0,00"></div><div><label class="v27-label">Moneda</label><select class="v27-select" id="v27Currency"><option value="USD">USD</option><option value="BS">BS</option></select></div></div><div id="v27RateBox" class="v27-rate"><label class="v27-label">Tasa Bs. por USD</label><input class="v27-input" id="v27Rate" inputmode="decimal" placeholder="Ej. 155,40"><div id="v27BsNote" class="v27-bs-note active">La deuda semanal se calcula en USD. Para un pago en Bs. la tasa permite convertir el monto y aplicar el abono correctamente.</div></div>
  <div class="v27-form-grid"><div><label class="v27-label">Fecha real del pago</label><input type="date" class="v27-input" id="v27Date"></div><div><label class="v27-label">Forma de pago</label><select class="v27-select" id="v27Method"><option>Transferencia</option><option>Pago móvil</option><option>Efectivo</option><option>Zelle</option><option>Otro</option></select></div><div><label class="v27-label">Banco / plataforma</label><input class="v27-input" id="v27Bank" placeholder="Banco o plataforma"></div><div><label class="v27-label">Referencia</label><input class="v27-input" id="v27Reference" placeholder="Referencia del pago"></div><div class="wide"><label class="v27-label">Observación</label><input class="v27-input" id="v27Notes" placeholder="Opcional"></div></div><p class="v27-note">El sistema aplica automáticamente el dinero a la semana pendiente más antigua. Si el monto no completa una semana, queda registrada como <b>abono parcial</b> con saldo pendiente.</p><div class="v27-actions"><button class="v27-btn primary" id="v27PreviewBtn" disabled>Calcular distribución</button></div></div></div>
  <div><div class="v27-card"><h3>Estado del financiamiento</h3><div id="v27Account"><div class="v27-empty">Selecciona un cliente para ver sus semanas y saldos.</div></div></div><div class="v27-card v27-preview" id="v27PreviewCard"><h3>Distribución automática del pago</h3><div id="v27Preview"></div><div id="v27Receipt"></div><div class="v27-actions"><button class="v27-btn" id="v27CancelPreview">Modificar</button><button class="v27-btn success" id="v27ReconcileBtn">Registrar y conciliar</button></div></div></div>
  </div></div></section>`;
  document.body.appendChild(o);
  const today=new Date();document.getElementById('v27Date').value=[today.getFullYear(),String(today.getMonth()+1).padStart(2,'0'),String(today.getDate()).padStart(2,'0')].join('-');
  o.querySelector('[data-v27-close]').onclick=close;o.addEventListener('click',e=>{if(e.target===o)close();});
  document.getElementById('v27SearchBtn').onclick=search;
  document.getElementById('v27Search').addEventListener('input',()=>{clearTimeout(state.searchTimer);state.searchTimer=setTimeout(search,320);});
  document.getElementById('v27Search').addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();search();}});
  document.getElementById('v27Currency').onchange=currencyChanged;
  document.getElementById('v27PreviewBtn').onclick=preview;
  document.getElementById('v27CancelPreview').onclick=()=>document.getElementById('v27PreviewCard').classList.remove('active');
  document.getElementById('v27ReconcileBtn').onclick=reconcile;
  setTimeout(()=>document.getElementById('v27Search')?.focus(),80);
}
function currencyChanged(){const bs=document.getElementById('v27Currency').value==='BS';document.getElementById('v27RateBox').classList.toggle('active',bs);}
async function search(){
  const q=document.getElementById('v27Search')?.value.trim()||'';const box=document.getElementById('v27Results');if(q.length<2){box.innerHTML='<div class="v27-empty">Escribe al menos 2 caracteres para buscar.</div>';return;}
  box.innerHTML='<div class="v27-empty"><i class="fa-solid fa-circle-notch fa-spin"></i> Buscando…</div>';
  try{const j=await request('search',{params:{q}});const rows=j.customers||[];if(!rows.length){box.innerHTML='<div class="v27-empty">No se encontraron clientes con ese criterio.</div>';return;}box.innerHTML=rows.map((c,i)=>`<button class="v27-result" data-i="${i}"><b>${esc(c.name||'Cliente')}</b><small>${esc(c.document||'Sin cédula')} · Contrato ${esc(c.contract||'—')} ${c.plate?'· '+esc(c.plate):''}${Number(c.weekly_amount_usd)>0?' · Cuota $'+money(c.weekly_amount_usd):' · Cuota sin configurar'}</small></button>`).join('');box.querySelectorAll('.v27-result').forEach(b=>b.onclick=()=>selectCustomer(rows[Number(b.dataset.i)]));}catch(e){box.innerHTML=`<div class="v27-empty">${esc(e.message)}</div>`;}
}
async function selectCustomer(c){
  state.customer=c;state.preview=null;const card=document.getElementById('v27Customer');card.classList.add('active');card.innerHTML=`<b>${esc(c.name)}</b><span>${esc(c.document||'Sin cédula')} · Contrato ${esc(c.contract||'—')} ${c.plate?'· Placa '+esc(c.plate):''}</span>`;document.getElementById('v27Results').innerHTML='';
  const box=document.getElementById('v27Account');box.innerHTML='<div class="v27-empty"><i class="fa-solid fa-circle-notch fa-spin"></i> Cargando plan…</div>';
  try{const j=await request('account',{params:{customer_key:c.key}});state.account=j.account;renderAccount(j.account);document.getElementById('v27PreviewBtn').disabled=false;}catch(e){box.innerHTML=`<div class="v27-empty">${esc(e.message)}</div>`;document.getElementById('v27PreviewBtn').disabled=true;}
}
function renderAccount(a){
  const s=a.summary||{},w=a.weeks||[];const box=document.getElementById('v27Account');
  const rows=w.filter(x=>x.status!=='paid'||x.v27_paid_usd>0).slice(0,18);
  const source=a.contract?.weekly_source==='client_plan'?'Cuota maestra del cliente':'Cuota detectada del contrato';
  box.innerHTML=`<div class="v27-plan-source"><i class="fa-solid fa-circle-check"></i><span><b>${source}: $${money(a.contract?.weekly_amount_usd)} por semana</b><small>Todos los pagos se distribuyen automáticamente usando este valor.</small></span></div><div class="v27-stats"><div class="v27-stat"><small>Cuota semanal</small><b>$${money(a.contract?.weekly_amount_usd)}</b></div><div class="v27-stat warn"><small>Saldo vencido</small><b>$${money(s.overdue_balance_usd)}</b></div><div class="v27-stat partial"><small>Abonado</small><b>$${money(s.partial_paid_usd)}</b></div><div class="v27-stat good"><small>Semanas pagadas</small><b>${Number(s.paid_weeks||0)}/50</b></div></div><div class="v27-weeks-wrap"><table class="v27-table"><thead><tr><th>Semana</th><th>Vence</th><th>Cuota</th><th>Abonado</th><th>Avance</th><th>Saldo</th><th>Estado</th></tr></thead><tbody>${rows.map(x=>`<tr><td><b>#${x.week_number}</b></td><td>${esc(x.due_date||'—')}</td><td>$${money(x.amount_usd)}</td><td>$${money(x.v27_paid_usd)}</td><td><div class="v27-mini-progress"><span style="width:${Math.min(100,Number(x.paid_percentage||0))}%"></span></div><small>${money(x.paid_percentage)}%</small></td><td><b>$${money(x.balance_usd)}</b></td><td><span class="v27-badge ${esc(x.status)}">${x.status==='partial'?'Abono '+money(x.paid_percentage)+'%':statusLabel(x.status)}</span></td></tr>`).join('')||'<tr><td colspan="7">No hay semanas pendientes.</td></tr>'}</tbody></table></div>`;
}
function payload(){return{customer_key:state.customer?.key||'',amount:document.getElementById('v27Amount').value,currency:document.getElementById('v27Currency').value,exchange_rate:document.getElementById('v27Rate').value,payment_date:document.getElementById('v27Date').value,method:document.getElementById('v27Method').value,bank:document.getElementById('v27Bank').value,reference:document.getElementById('v27Reference').value,notes:document.getElementById('v27Notes').value};}
async function preview(){
  if(!state.customer)return;const card=document.getElementById('v27PreviewCard'),box=document.getElementById('v27Preview');card.classList.add('active');box.innerHTML='<div class="v27-empty"><i class="fa-solid fa-circle-notch fa-spin"></i> Calculando…</div>';document.getElementById('v27Receipt').innerHTML='';
  try{const j=await request('preview',{method:'POST',body:payload()});state.preview=j.preview;renderPreview(j.preview);}catch(e){state.preview=null;box.innerHTML=`<div class="v27-empty">${esc(e.message)}</div>`;}
}
function renderPreview(p){
  const box=document.getElementById('v27Preview'),m=p.money||{},alloc=p.allocations||[];const original=m.currency==='BS'?`Bs. ${money(m.amount_original)}`:`USD ${money(m.amount_original)}`;const eq=m.currency==='BS'?` · equivalente USD ${money(m.amount_usd)} a tasa ${money(m.exchange_rate)} Bs./USD`:'';
  const partial=p.partial_week||null;const summary=partial?`${Number(p.full_weeks_from_payment||0)} semana${Number(p.full_weeks_from_payment||0)===1?'':'s'} completa${Number(p.full_weeks_from_payment||0)===1?'':'s'} + abono hasta ${money(partial.paid_percentage_after)}% de la semana ${partial.week_number}`:`${Number(p.completed_weeks||0)} semana${Number(p.completed_weeks||0)===1?'':'s'} completada${Number(p.completed_weeks||0)===1?'':'s'}`;
  box.innerHTML=`<div class="v27-payment-summary"><div><small>Cuota del cliente</small><b>$${money(p.weekly_amount_usd)} semanal</b></div><div><small>Equivalencia del pago</small><b>${Number(p.equivalent_weeks||0).toLocaleString('es-VE',{maximumFractionDigits:2})} semanas</b></div><div class="wide"><small>Resultado automático</small><b>${esc(summary)}</b></div></div><div class="v27-remainder"><b>Pago recibido:</b> ${original}${eq}</div>${alloc.map(a=>`<div class="v27-allocation"><div><b>Semana ${a.week_number} ${a.completed?'· COMPLETA':'· ABONO '+money(a.paid_percentage_after)+'%'}</b><small>Aplicado $${money(a.allocated_usd)} · saldo $${money(a.balance_before_usd)} → $${money(a.balance_after_usd)}</small>${!a.completed?`<div class="v27-progress"><span style="width:${Math.min(100,Number(a.paid_percentage_after||0))}%"></span></div>`:''}</div><strong>+$${money(a.allocated_usd)}</strong></div>`).join('')||'<div class="v27-empty">No hay cuotas pendientes para distribuir.</div>'}<div class="v27-remainder ${Number(p.unapplied_usd)>0?'warn':''}"><b>Aplicado:</b> USD ${money(p.applied_usd)}${Number(p.unapplied_usd)>0?` · <b>Sin aplicar:</b> USD ${money(p.unapplied_usd)}`:''}</div>`;
}
async function reconcile(){
  if(!state.preview){toast('Primero calcula la distribución del pago.','bad');return;}const btn=document.getElementById('v27ReconcileBtn');btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-circle-notch fa-spin"></i> Conciliando…';
  try{const j=await request('reconcile',{method:'POST',body:payload()});const r=j.result;state.account=r.account;renderAccount(r.account);document.getElementById('v27Receipt').innerHTML=`<div class="v27-receipt"><b><i class="fa-solid fa-circle-check"></i> Pago conciliado · ${esc(r.receipt_number)}</b><br>Se aplicaron USD ${money(r.applied_usd)}. ${Number(r.unapplied_usd)>0?'Quedaron USD '+money(r.unapplied_usd)+' sin aplicar.':''}</div>`;state.preview=null;toast('Pago conciliado y saldos actualizados.','good');btn.textContent='Conciliado';}catch(e){toast(e.message,'bad');btn.disabled=false;btn.textContent='Registrar y conciliar';}
}
window.gpV27OpenPayment=modal;
window.gpQuickPayment=modal;
window.gpV27PaymentReconcile={open:modal,close,version:'27.0.0'};
})();
