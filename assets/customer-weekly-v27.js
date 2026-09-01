(()=>{
'use strict';
const GP=window.GRANDPRIX||{};
const API=GP.paymentReconcileApi||'api/payment-reconcile-v27.php';
const esc=s=>String(s??'').replace(/[&<>'"]/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[m]));
const money=n=>Number(n||0).toLocaleString('es-VE',{minimumFractionDigits:2,maximumFractionDigits:2});
let route='';
let timer=null;
let observer=null;
let mounting=false;
const state={customer:null,plan:null,searchTimer:null};

async function request(action,opt={}){
  const method=opt.method||'GET';
  let url=`${API}?action=${encodeURIComponent(action)}`;
  const init={method,headers:{Accept:'application/json'}};
  if(method==='GET'&&opt.params)url+='&'+new URLSearchParams(opt.params).toString();
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
  document.querySelectorAll('.v27-weekly-toast').forEach(x=>x.remove());
  const d=document.createElement('div');d.className=`v27-weekly-toast ${type}`;d.textContent=msg;document.body.appendChild(d);setTimeout(()=>d.remove(),4200);
}
function isClients(){
  if(route)return route==='clientes';
  const title=(document.getElementById('pageTitle')?.textContent||'').toLowerCase();
  return title.includes('cliente');
}
function scheduleMount(){clearTimeout(timer);timer=setTimeout(mount,80);setTimeout(mount,320);setTimeout(mount,900);}
function unmount(){document.getElementById('gpV27WeeklyManager')?.remove();state.customer=null;state.plan=null;}
function mount(){
  if(mounting||!isClients())return;
  const view=document.getElementById('view');if(!view)return;
  if(document.getElementById('gpV27WeeklyManager'))return;
  mounting=true;
  const card=document.createElement('section');card.id='gpV27WeeklyManager';card.className='v27-weekly-manager';
  card.innerHTML=`<div class="v27-weekly-top"><div class="v27-weekly-icon"><i class="fa-solid fa-calendar-check"></i></div><div><span>PLAN FINANCIERO DEL CLIENTE</span><h2>Cuota semanal por cliente</h2><p>Define el monto que este cliente debe pagar cada semana. Este valor será la base automática para distribuir pagos completos y abonos parciales.</p></div><div class="v27-weekly-example"><small>Ejemplo</small><b>$50 × semana</b><span>Pago $125 = 2 semanas + 50%</span></div></div><div class="v27-weekly-grid"><div><label>Buscar cliente</label><div class="v27-weekly-search"><input id="v27WeeklySearch" placeholder="Nombre, cédula, contrato o placa" autocomplete="off"><button id="v27WeeklySearchBtn"><i class="fa-solid fa-magnifying-glass"></i></button></div><div id="v27WeeklyResults" class="v27-weekly-results"><div class="v27-weekly-empty">Busca y selecciona el cliente que deseas configurar.</div></div></div><div><label>Cliente seleccionado</label><div id="v27WeeklySelected" class="v27-weekly-selected empty">Ningún cliente seleccionado.</div><div class="v27-weekly-amount"><div><label>Cuota semanal (USD)</label><select id="v27WeeklyPreset" disabled><option value="">Seleccionar monto</option><option value="40">$40</option><option value="45">$45</option><option value="50">$50</option><option value="55">$55</option><option value="60">$60</option><option value="65">$65</option><option value="70">$70</option><option value="75">$75</option><option value="80">$80</option><option value="90">$90</option><option value="100">$100</option><option value="custom">Otro monto…</option></select></div><div id="v27WeeklyCustomBox" class="v27-weekly-custom"><label>Monto personalizado</label><input id="v27WeeklyCustom" inputmode="decimal" placeholder="Ej. 52,50"></div></div><div id="v27WeeklyCurrent" class="v27-weekly-current"></div><button id="v27WeeklySave" class="v27-weekly-save" disabled><i class="fa-solid fa-floppy-disk"></i> Guardar cuota semanal</button></div></div>`;
  view.prepend(card);
  bind(card);
  mounting=false;
}
function bind(card){
  const input=card.querySelector('#v27WeeklySearch');
  card.querySelector('#v27WeeklySearchBtn').onclick=search;
  input.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();search();}});
  input.addEventListener('input',()=>{clearTimeout(state.searchTimer);state.searchTimer=setTimeout(search,350);});
  card.querySelector('#v27WeeklyPreset').onchange=()=>{const custom=card.querySelector('#v27WeeklyPreset').value==='custom';card.querySelector('#v27WeeklyCustomBox').classList.toggle('active',custom);};
  card.querySelector('#v27WeeklySave').onclick=save;
}
async function search(){
  const input=document.getElementById('v27WeeklySearch');const box=document.getElementById('v27WeeklyResults');if(!input||!box)return;
  const q=input.value.trim();if(q.length<2){box.innerHTML='<div class="v27-weekly-empty">Escribe al menos 2 caracteres.</div>';return;}
  box.innerHTML='<div class="v27-weekly-empty"><i class="fa-solid fa-circle-notch fa-spin"></i> Buscando…</div>';
  try{
    const j=await request('search',{params:{q}});const rows=j.customers||[];
    if(!rows.length){box.innerHTML='<div class="v27-weekly-empty">No se encontraron clientes.</div>';return;}
    box.innerHTML=rows.map((c,i)=>`<button class="v27-weekly-result" data-i="${i}"><span><b>${esc(c.name||'Cliente')}</b><small>${esc(c.document||'Sin cédula')} · ${c.contract?'Contrato '+esc(c.contract):'Sin contrato'} ${c.plate?'· Placa '+esc(c.plate):''}</small></span><strong>${Number(c.weekly_amount_usd)>0?'$'+money(c.weekly_amount_usd)+'/sem':'Configurar'}</strong></button>`).join('');
    box.querySelectorAll('.v27-weekly-result').forEach(b=>b.onclick=()=>select(rows[Number(b.dataset.i)]));
  }catch(e){box.innerHTML=`<div class="v27-weekly-empty">${esc(e.message)}</div>`;}
}
async function select(customer){
  state.customer=customer;state.plan=null;
  const selected=document.getElementById('v27WeeklySelected'),box=document.getElementById('v27WeeklyResults'),saveBtn=document.getElementById('v27WeeklySave');
  if(selected){selected.classList.remove('empty');selected.innerHTML=`<b>${esc(customer.name||'Cliente')}</b><span>${esc(customer.document||'Sin cédula')} ${customer.contract?'· Contrato '+esc(customer.contract):''} ${customer.plate?'· Placa '+esc(customer.plate):''}</span>`;}
  if(box)box.innerHTML='';if(saveBtn)saveBtn.disabled=true;
  const current=document.getElementById('v27WeeklyCurrent');if(current)current.innerHTML='<i class="fa-solid fa-circle-notch fa-spin"></i> Cargando cuota actual…';
  try{
    const j=await request('weekly-plan',{params:{customer_key:customer.key}});state.plan=j.plan;applyPlan(j.plan);if(saveBtn)saveBtn.disabled=false;
  }catch(e){if(current)current.textContent=e.message;}
}
function applyPlan(plan){
  const preset=document.getElementById('v27WeeklyPreset'),custom=document.getElementById('v27WeeklyCustom'),customBox=document.getElementById('v27WeeklyCustomBox'),current=document.getElementById('v27WeeklyCurrent');if(!preset)return;
  preset.disabled=false;const amount=Number(plan.weekly_amount_usd||0);const standard=['40','45','50','55','60','65','70','75','80','90','100'];
  if(amount>0&&standard.includes(String(amount))){preset.value=String(amount);customBox?.classList.remove('active');if(custom)custom.value='';}
  else if(amount>0){preset.value='custom';customBox?.classList.add('active');if(custom)custom.value=String(amount);}
  else{preset.value='';customBox?.classList.remove('active');if(custom)custom.value='';}
  if(current){
    if(amount>0)current.innerHTML=`<i class="fa-solid fa-circle-check"></i><span>Cuota vigente: <b>$${money(amount)} por semana</b>${plan.explicit_plan?' · configurada como cuota maestra':' · detectada del contrato actual'}</span>`;
    else current.innerHTML='<i class="fa-solid fa-triangle-exclamation"></i><span>Este cliente todavía no tiene una cuota semanal configurada.</span>';
  }
}
function selectedAmount(){
  const preset=document.getElementById('v27WeeklyPreset')?.value||'';
  if(preset==='custom')return document.getElementById('v27WeeklyCustom')?.value||'';
  return preset;
}
async function save(){
  if(!state.customer)return;
  const amount=selectedAmount();if(!amount){toast('Selecciona o escribe la cuota semanal.','bad');return;}
  const btn=document.getElementById('v27WeeklySave');btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-circle-notch fa-spin"></i> Guardando…';
  try{
    const j=await request('weekly-plan-save',{method:'POST',body:{customer_key:state.customer.key,weekly_amount_usd:amount}});state.plan=j.plan;applyPlan(j.plan);toast(`Cuota semanal guardada: $${money(j.plan.weekly_amount_usd)}.`, 'good');btn.innerHTML='<i class="fa-solid fa-check"></i> Cuota semanal guardada';setTimeout(()=>{if(btn){btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Guardar cuota semanal';}},1100);
  }catch(e){toast(e.message,'bad');btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-floppy-disk"></i> Guardar cuota semanal';}
}
function wrapNavigate(){
  if(typeof window.navigate!=='function'||window.navigate.__v27WeeklyWrapped)return false;
  const original=window.navigate;
  function wrapped(name,...args){route=String(name||'');const result=original.apply(this,[name,...args]);if(route==='clientes')scheduleMount();else unmount();return result;}
  wrapped.__v27WeeklyWrapped=true;wrapped.__original=original;window.navigate=wrapped;return true;
}
let attempts=0;const boot=setInterval(()=>{attempts++;if(wrapNavigate()||attempts>30)clearInterval(boot);},100);
observer=new MutationObserver(()=>{if(isClients())scheduleMount();});
const startObserve=()=>{const view=document.getElementById('view');if(view)observer.observe(view,{childList:true});};
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',()=>{startObserve();scheduleMount();});else{startObserve();scheduleMount();}
window.gpV27WeeklyPlan={mount,search,save,version:'27.0.0'};
})();
