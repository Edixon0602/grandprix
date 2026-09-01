(()=>{
'use strict';
const GP=window.GRANDPRIX||{};
const API=GP.paymentReconcileApi||'api/payment-reconcile-v26.php';
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
  document.querySelectorAll('.v26-toast').forEach(x=>x.remove());
  const d=document.createElement('div');d.className=`v26-toast ${type}`;d.textContent=msg;document.body.appendChild(d);setTimeout(()=>d.remove(),4200);
}
function statusLabel(s){return({paid:'Pagada',partial:'Abono parcial',overdue:'Vencida',pending:'Pendiente'})[s]||s;}
function close(){document.getElementById('v26PaymentOverlay')?.remove();state.customer=null;state.account=null;state.preview=null;}
function modal(){
  close();
  const o=document.createElement('div');o.className='v26-overlay';o.id='v26PaymentOverlay';
  o.innerHTML=`<section class="v26-modal" role="dialog" aria-modal="true"><header class="v26-head"><div><small>GRANDPRIX · FINANZAS</small><h2>Conciliar pago / registrar abono</h2></div><button class="v26-close" data-v26-close><i class="fa-solid fa-xmark"></i></button></header><div class="v26-body"><div class="v26-grid">
  <div><div class="v26-card"><h3>1. Buscar cliente</h3><div class="v26-search-row"><input class="v26-input" id="v26Search" placeholder="Nombre, cédula, contrato o placa" autocomplete="off"><button class="v26-btn" id="v26SearchBtn"><i class="fa-solid fa-magnifying-glass"></i></button></div><div id="v26Results" class="v26-results"><div class="v26-empty">Escribe al menos 2 caracteres para buscar.</div></div><div id="v26Customer" class="v26-customer"></div></div>
  <div class="v26-card" style="margin-top:16px"><h3>2. Datos del pago</h3><div class="v26-money-row"><div><label class="v26-label">Monto</label><input class="v26-input" id="v26Amount" inputmode="decimal" placeholder="0,00"></div><div><label class="v26-label">Moneda</label><select class="v26-select" id="v26Currency"><option value="USD">USD</option><option value="BS">BS</option></select></div></div><div id="v26RateBox" class="v26-rate"><label class="v26-label">Tasa Bs. por USD</label><input class="v26-input" id="v26Rate" inputmode="decimal" placeholder="Ej. 155,40"><div id="v26BsNote" class="v26-bs-note active">La deuda semanal se calcula en USD. Para un pago en Bs. la tasa permite convertir el monto y aplicar el abono correctamente.</div></div>
  <div class="v26-form-grid"><div><label class="v26-label">Fecha real del pago</label><input type="date" class="v26-input" id="v26Date"></div><div><label class="v26-label">Forma de pago</label><select class="v26-select" id="v26Method"><option>Transferencia</option><option>Pago móvil</option><option>Efectivo</option><option>Zelle</option><option>Otro</option></select></div><div><label class="v26-label">Banco / plataforma</label><input class="v26-input" id="v26Bank" placeholder="Banco o plataforma"></div><div><label class="v26-label">Referencia</label><input class="v26-input" id="v26Reference" placeholder="Referencia del pago"></div><div class="wide"><label class="v26-label">Observación</label><input class="v26-input" id="v26Notes" placeholder="Opcional"></div></div><p class="v26-note">El sistema aplica automáticamente el dinero a la semana pendiente más antigua. Si el monto no completa una semana, queda registrada como <b>abono parcial</b> con saldo pendiente.</p><div class="v26-actions"><button class="v26-btn primary" id="v26PreviewBtn" disabled>Calcular distribución</button></div></div></div>
  <div><div class="v26-card"><h3>Estado del financiamiento</h3><div id="v26Account"><div class="v26-empty">Selecciona un cliente para ver sus semanas y saldos.</div></div></div><div class="v26-card v26-preview" id="v26PreviewCard"><h3>Distribución automática del pago</h3><div id="v26Preview"></div><div id="v26Receipt"></div><div class="v26-actions"><button class="v26-btn" id="v26CancelPreview">Modificar</button><button class="v26-btn success" id="v26ReconcileBtn">Registrar y conciliar</button></div></div></div>
  </div></div></section>`;
  document.body.appendChild(o);
  const today=new Date();document.getElementById('v26Date').value=[today.getFullYear(),String(today.getMonth()+1).padStart(2,'0'),String(today.getDate()).padStart(2,'0')].join('-');
  o.querySelector('[data-v26-close]').onclick=close;o.addEventListener('click',e=>{if(e.target===o)close();});
  document.getElementById('v26SearchBtn').onclick=search;
  document.getElementById('v26Search').addEventListener('input',()=>{clearTimeout(state.searchTimer);state.searchTimer=setTimeout(search,320);});
  document.getElementById('v26Search').addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();search();}});
  document.getElementById('v26Currency').onchange=currencyChanged;
  document.getElementById('v26PreviewBtn').onclick=preview;
  document.getElementById('v26CancelPreview').onclick=()=>document.getElementById('v26PreviewCard').classList.remove('active');
  document.getElementById('v26ReconcileBtn').onclick=reconcile;
  setTimeout(()=>document.getElementById('v26Search')?.focus(),80);
}
function currencyChanged(){const bs=document.getElementById('v26Currency').value==='BS';document.getElementById('v26RateBox').classList.toggle('active',bs);}
async function search(){
  const q=document.getElementById('v26Search')?.value.trim()||'';const box=document.getElementById('v26Results');if(q.length<2){box.innerHTML='<div class="v26-empty">Escribe al menos 2 caracteres para buscar.</div>';return;}
  box.innerHTML='<div class="v26-empty"><i class="fa-solid fa-circle-notch fa-spin"></i> Buscando…</div>';
  try{const j=await request('search',{params:{q}});const rows=j.customers||[];if(!rows.length){box.innerHTML='<div class="v26-empty">No se encontraron clientes con ese criterio.</div>';return;}box.innerHTML=rows.map((c,i)=>`<button class="v26-result" data-i="${i}"><b>${esc(c.name||'Cliente')}</b><small>${esc(c.document||'Sin cédula')} · Contrato ${esc(c.contract||'—')} ${c.plate?'· '+esc(c.plate):''}</small></button>`).join('');box.querySelectorAll('.v26-result').forEach(b=>b.onclick=()=>selectCustomer(rows[Number(b.dataset.i)]));}catch(e){box.innerHTML=`<div class="v26-empty">${esc(e.message)}</div>`;}
}
async function selectCustomer(c){
  state.customer=c;state.preview=null;const card=document.getElementById('v26Customer');card.classList.add('active');card.innerHTML=`<b>${esc(c.name)}</b><span>${esc(c.document||'Sin cédula')} · Contrato ${esc(c.contract||'—')} ${c.plate?'· Placa '+esc(c.plate):''}</span>`;document.getElementById('v26Results').innerHTML='';
  const box=document.getElementById('v26Account');box.innerHTML='<div class="v26-empty"><i class="fa-solid fa-circle-notch fa-spin"></i> Cargando plan…</div>';
  try{const j=await request('account',{params:{customer_key:c.key}});state.account=j.account;renderAccount(j.account);document.getElementById('v26PreviewBtn').disabled=false;}catch(e){box.innerHTML=`<div class="v26-empty">${esc(e.message)}</div>`;document.getElementById('v26PreviewBtn').disabled=true;}
}
function renderAccount(a){
  const s=a.summary||{},w=a.weeks||[];const box=document.getElementById('v26Account');
  const rows=w.filter(x=>x.status!=='paid'||x.v26_paid_usd>0).slice(0,18);
  box.innerHTML=`<div class="v26-stats"><div class="v26-stat"><small>Cuota semanal</small><b>$${money(a.contract?.weekly_amount_usd)}</b></div><div class="v26-stat warn"><small>Saldo vencido</small><b>$${money(s.overdue_balance_usd)}</b></div><div class="v26-stat partial"><small>Abonado</small><b>$${money(s.partial_paid_usd)}</b></div><div class="v26-stat good"><small>Semanas pagadas</small><b>${Number(s.paid_weeks||0)}/50</b></div></div><div class="v26-weeks-wrap"><table class="v26-table"><thead><tr><th>Semana</th><th>Vence</th><th>Cuota</th><th>Abonado</th><th>Saldo</th><th>Estado</th></tr></thead><tbody>${rows.map(x=>`<tr><td><b>#${x.week_number}</b></td><td>${esc(x.due_date||'—')}</td><td>$${money(x.amount_usd)}</td><td>$${money(x.v26_paid_usd)}</td><td><b>$${money(x.balance_usd)}</b></td><td><span class="v26-badge ${esc(x.status)}">${statusLabel(x.status)}</span></td></tr>`).join('')||'<tr><td colspan="6">No hay semanas pendientes.</td></tr>'}</tbody></table></div>`;
}
function payload(){return{customer_key:state.customer?.key||'',amount:document.getElementById('v26Amount').value,currency:document.getElementById('v26Currency').value,exchange_rate:document.getElementById('v26Rate').value,payment_date:document.getElementById('v26Date').value,method:document.getElementById('v26Method').value,bank:document.getElementById('v26Bank').value,reference:document.getElementById('v26Reference').value,notes:document.getElementById('v26Notes').value};}
async function preview(){
  if(!state.customer)return;const card=document.getElementById('v26PreviewCard'),box=document.getElementById('v26Preview');card.classList.add('active');box.innerHTML='<div class="v26-empty"><i class="fa-solid fa-circle-notch fa-spin"></i> Calculando…</div>';document.getElementById('v26Receipt').innerHTML='';
  try{const j=await request('preview',{method:'POST',body:payload()});state.preview=j.preview;renderPreview(j.preview);}catch(e){state.preview=null;box.innerHTML=`<div class="v26-empty">${esc(e.message)}</div>`;}
}
function renderPreview(p){
  const box=document.getElementById('v26Preview'),m=p.money||{},alloc=p.allocations||[];const original=m.currency==='BS'?`Bs. ${money(m.amount_original)}`:`USD ${money(m.amount_original)}`;const eq=m.currency==='BS'?` · equivalente USD ${money(m.amount_usd)} a tasa ${money(m.exchange_rate)} Bs./USD`:'';
  box.innerHTML=`<div class="v26-remainder"><b>Pago recibido:</b> ${original}${eq}</div>${alloc.map(a=>`<div class="v26-allocation"><div><b>Semana ${a.week_number} ${a.completed?'· COMPLETA':'· ABONO'}</b><small>Saldo antes $${money(a.balance_before_usd)} → saldo después $${money(a.balance_after_usd)}</small></div><strong>+$${money(a.allocated_usd)}</strong></div>`).join('')||'<div class="v26-empty">No hay cuotas pendientes para distribuir.</div>'}<div class="v26-remainder ${Number(p.unapplied_usd)>0?'warn':''}"><b>Aplicado:</b> USD ${money(p.applied_usd)}${Number(p.unapplied_usd)>0?` · <b>Sin aplicar:</b> USD ${money(p.unapplied_usd)}`:''}</div>`;
}
async function reconcile(){
  if(!state.preview){toast('Primero calcula la distribución del pago.','bad');return;}const btn=document.getElementById('v26ReconcileBtn');btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-circle-notch fa-spin"></i> Conciliando…';
  try{const j=await request('reconcile',{method:'POST',body:payload()});const r=j.result;state.account=r.account;renderAccount(r.account);document.getElementById('v26Receipt').innerHTML=`<div class="v26-receipt"><b><i class="fa-solid fa-circle-check"></i> Pago conciliado · ${esc(r.receipt_number)}</b><br>Se aplicaron USD ${money(r.applied_usd)}. ${Number(r.unapplied_usd)>0?'Quedaron USD '+money(r.unapplied_usd)+' sin aplicar.':''}</div>`;state.preview=null;toast('Pago conciliado y saldos actualizados.','good');btn.textContent='Conciliado';}catch(e){toast(e.message,'bad');btn.disabled=false;btn.textContent='Registrar y conciliar';}
}
window.gpV26OpenPayment=modal;
window.gpQuickPayment=modal;
window.gpV26PaymentReconcile={open:modal,close,version:'26.0.0'};
})();
