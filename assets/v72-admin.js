/* GRANDPRIX V7.2 · Consola GT06 y administracion del portal cliente.
 * Carga bajo demanda y por acciones del operador. No usa setInterval ni polling.
 */
(()=>{
'use strict';

const commandApi=(window.GRANDPRIX&&window.GRANDPRIX.commandApi)||'api/commands.php';
const customerAdminApi=(window.GRANDPRIX&&window.GRANDPRIX.customerAdminApi)||'api/customer-admin.php';
const csrf=(window.GRANDPRIX&&window.GRANDPRIX.csrf)||'';
const commandState={fleet:[],selectedId:0,catalog:null,audit:[],category:'todos',loading:false};
const portalState={devices:[],customers:[],payments:[],financeCandidates:[],selectedId:0,loading:false};
const riskOrder={low:1,medium:2,high:3,critical:4};
const riskText={low:'Bajo',medium:'Controlado',high:'Alto',critical:'Critico'};
const categoryText={diagnostico:'Diagnostico',alarmas:'Alarmas',seguridad:'Seguridad',telemetria:'Telemetria',provisionamiento:'Provisionamiento'};
const statusText={online:'En linea',offline:'Sin conexion',unknown:'Sin estado',accepted:'Aceptado',acknowledged:'Confirmado',failed:'Fallido',dispatching:'Enviando'};
const q=selector=>document.querySelector(selector);
const qa=selector=>Array.from(document.querySelectorAll(selector));
const safe=value=>String(value??'').replace(/[&<>'"]/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[char]));
const number=value=>Number(value||0).toLocaleString('es-VE');
const currency=value=>'$'+Number(value||0).toLocaleString('es-VE',{minimumFractionDigits:2,maximumFractionDigits:2});
const dateTime=value=>{if(!value)return 'Sin fecha';const raw=String(value).replace(' ','T');const parsed=new Date(/[zZ]$|[+-]\d\d:\d\d$/.test(raw)?raw:raw+'Z');return Number.isNaN(parsed.getTime())?safe(value):parsed.toLocaleString('es-VE',{dateStyle:'short',timeStyle:'short'})};

async function apiRequest(url,options={}){
  const response=await fetch(url,{credentials:'same-origin',cache:'no-store',...options,headers:{Accept:'application/json',...(options.headers||{})}});
  let payload={};try{payload=await response.json()}catch(_){payload={error:'El servidor devolvio una respuesta no valida.'}}
  if(!response.ok||payload.ok===false)throw new Error(payload.error||`Error HTTP ${response.status}`);
  return payload;
}
async function post(url,body){return apiRequest(url,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(body)})}
function notify(message,type='ok'){
  const node=q('#toast');if(!node)return;
  node.innerHTML=`<i class="fa-solid ${type==='error'?'fa-triangle-exclamation':'fa-circle-check'}"></i>${safe(message)}`;
  node.classList.toggle('v72-error',type==='error');node.classList.add('show');setTimeout(()=>node.classList.remove('show'),3600);
}
function errorBox(message,retry){return `<div class="v72-state error"><i class="fa-solid fa-triangle-exclamation"></i><h3>No fue posible cargar el modulo</h3><p>${safe(message)}</p><button type="button" onclick="${retry}"><i class="fa-solid fa-rotate"></i> Reintentar</button></div>`}
function loadingBox(label='Conectando con GRANDPRIX'){return `<div class="v72-state"><span class="v72-spinner"></span><h3>${safe(label)}</h3><p>Lectura puntual bajo demanda · cero polling</p></div>`}
function modalOpen(id){q('#'+id)?.classList.add('show')}
function modalClose(id){q('#'+id)?.classList.remove('show')}
window.gpV72CloseModal=modalClose;

views.comandos=()=>`<div class="page v72-command-center">
  <section class="v72-command-hero">
    <div><span class="v72-eyebrow"><i class="fa-solid fa-shield-halved"></i> OPERACION GT06 PROTEGIDA</span><h2>Comandos reales, trazables y compatibles</h2><p>El sistema consulta a Traccar al abrir una unidad, construye la orden en PHP y nunca expone la clave tecnica al navegador.</p></div>
    <div class="v72-no-poll"><i class="fa-solid fa-bolt"></i><span><b>Webhook + WebSocket</b><small>Sin consultas repetitivas</small></span></div>
  </section>
  <section class="v72-mini-kpis"><article><i class="fa-solid fa-satellite-dish"></i><span><strong id="cmdFleetCount">—</strong><b>GPS visibles</b></span></article><article><i class="fa-solid fa-circle-check"></i><span><strong id="cmdOnlineCount">—</strong><b>En linea</b></span></article><article><i class="fa-solid fa-list-check"></i><span><strong id="cmdAvailableCount">—</strong><b>Ordenes listas</b></span></article><article class="danger"><i class="fa-solid fa-shield"></i><span><strong id="cmdCriticalCount">—</strong><b>Criticas auditadas</b></span></article></section>
  <div class="v72-command-grid">
    <aside class="panel v72-fleet-panel"><div class="panel-head"><div><h2>Flota conectada</h2><p>Selecciona el Device ID interno</p></div><button class="v72-icon-button" onclick="gpCommandRefresh()" title="Actualizar por solicitud"><i class="fa-solid fa-rotate"></i></button></div><label class="v72-search"><i class="fa-solid fa-magnifying-glass"></i><input id="cmdFleetSearch" placeholder="Nombre, codigo o IMEI"></label><div id="cmdFleet" class="v72-fleet-list">${loadingBox('Leyendo memoria de Traccar')}</div></aside>
    <section class="panel v72-command-workspace" id="cmdWorkspace">${loadingBox('Preparando catalogo seguro')}</section>
  </div>
  <section class="panel v72-audit-panel"><div class="panel-head"><div><h2>Auditoria de comandos</h2><p>Canal, riesgo, operador y resultado; la clave y el texto SMS no se almacenan</p></div><span class="v72-live-chip"><i></i> EVENTOS PUSH</span></div><div id="cmdAudit" class="v72-audit-list">${loadingBox('Cargando trazabilidad')}</div></section>
  <div class="v72-modal-backdrop" id="cmdConfigureModal"><div class="v72-modal wide"><button class="v72-modal-x" onclick="gpV72CloseModal('cmdConfigureModal')"><i class="fa-solid fa-xmark"></i></button><div id="cmdConfigureBody"></div></div></div>
  <div class="v72-modal-backdrop" id="cmdDispatchModal"><div class="v72-modal"><button class="v72-modal-x" onclick="gpV72CloseModal('cmdDispatchModal')"><i class="fa-solid fa-xmark"></i></button><div id="cmdDispatchBody"></div></div></div>
</div>`;

function commandDeviceLabel(device){return device.code||device.name||`GPS ${device.id}`}
function commandFresh(device){const time=device.position&&device.position.fixTime?new Date(device.position.fixTime).getTime():new Date(device.lastUpdate||0).getTime();return Number.isFinite(time)&&Date.now()-time<600000&&device.status!=='offline'}
function commandRenderFleet(filter=''){
  const node=q('#cmdFleet');if(!node)return;
  const term=filter.trim().toLowerCase();const list=commandState.fleet.filter(device=>[device.name,device.code,device.uniqueId,device.model].some(value=>String(value||'').toLowerCase().includes(term)));
  if(!list.length){node.innerHTML='<div class="v72-empty"><i class="fa-solid fa-satellite-dish"></i><b>No hay dispositivos</b><small>Revisa que Traccar haya enviado al menos una posicion.</small></div>';return}
  node.innerHTML=list.map(device=>`<button type="button" class="v72-device ${device.id===commandState.selectedId?'active':''}" onclick="gpCommandSelect(${device.id})"><span class="v72-device-icon"><i class="fa-solid fa-motorcycle"></i><em class="${commandFresh(device)?'online':'offline'}"></em></span><span><b>${safe(commandDeviceLabel(device))}</b><small>${safe(device.model||device.name||'GT06')} · ID ${device.id}</small></span><i class="fa-solid fa-chevron-right"></i></button>`).join('');
}
function commandRenderAudit(){
  const node=q('#cmdAudit');if(!node)return;
  if(!commandState.audit.length){node.innerHTML='<div class="v72-empty horizontal"><i class="fa-solid fa-fingerprint"></i><span><b>Sin comandos ejecutados</b><small>La primera orden autorizada aparecera aqui.</small></span></div>';return}
  const critical=commandState.audit.filter(event=>event.risk==='critical').length;const kpi=q('#cmdCriticalCount');if(kpi)kpi.textContent=String(critical);
  node.innerHTML=commandState.audit.map(event=>`<article class="v72-audit-row"><span class="v72-audit-icon risk-${safe(event.risk)}"><i class="fa-solid ${event.commandKey==='engine_stop'?'fa-power-off':'fa-terminal'}"></i></span><span><b>${safe(event.commandLabel)}</b><small>Device ID ${safe(event.deviceId)} · ${safe(event.requestedBy||'Administrador')}</small></span><span class="v72-channel ${safe(event.channel)}"><i class="fa-solid ${event.channel==='sms'?'fa-comment-sms':'fa-cloud-arrow-up'}"></i>${safe(String(event.channel||'').toUpperCase())}</span><span class="v72-command-status ${safe(event.status)}">${safe(statusText[event.status]||event.status)}</span><time>${dateTime(event.updatedAt||event.createdAt)}</time></article>`).join('');
}
function commandPositionCard(device){
  const position=device.position||{};const speed=position.speedKmh==null?'—':Math.round(Number(position.speedKmh));
  return `<div class="v72-device-summary"><div class="v72-bike-visual"><img src="assets/moto-blue.png" alt="Motocicleta"><span class="${commandFresh(device)?'online':'offline'}"><i></i>${commandFresh(device)?'Telemetria reciente':'Reporte antiguo'}</span></div><div class="v72-device-data"><span><small>Unidad</small><b>${safe(commandDeviceLabel(device))}</b></span><span><small>Device ID</small><b>${device.id}</b></span><span><small>Velocidad</small><b>${speed} km/h</b></span><span><small>Ignicion</small><b>${position.ignition===true?'Encendida':position.ignition===false?'Apagada':'Sin dato'}</b></span></div></div>`;
}
function commandRenderWorkspace(){
  const node=q('#cmdWorkspace');if(!node||!commandState.catalog)return;
  const catalog=commandState.catalog,device=catalog.device||{};const available=catalog.commands.filter(item=>item.available).length;
  const count=q('#cmdAvailableCount');if(count)count.textContent=String(available);
  const categories=['todos',...Array.from(new Set(catalog.commands.map(item=>item.category)))];
  const commands=catalog.commands.filter(item=>commandState.category==='todos'||item.category===commandState.category);
  const data=catalog.channels.data.available,sms=catalog.channels.sms.available,configuration=catalog.configuration;
  node.innerHTML=`<div class="v72-command-head"><div><span class="v72-eyebrow">UNIDAD SELECCIONADA</span><h2>${safe(commandDeviceLabel(device))}</h2><p>${safe(device.model||device.name||'Dispositivo GT06')} · Device ID ${device.id}</p></div><button class="v72-primary-soft" onclick="gpCommandConfigure()"><i class="fa-solid fa-sliders"></i> Configurar unidad</button></div>
    ${commandPositionCard(device)}
    <div class="v72-readiness"><article class="${data?'ready':'pending'}"><i class="fa-solid fa-cloud-arrow-up"></i><span><b>Canal de datos</b><small>${data?`${catalog.channels.data.types.length} tipos reportados por Traccar`:'No disponible para este firmware'}</small></span></article><article class="${sms?'ready':'pending'}"><i class="fa-solid fa-comment-sms"></i><span><b>Canal SMS</b><small>${sms?'Gateway y SIM listos':'Configura Phone/SMS en Traccar'}</small></span></article><article class="${configuration.relayVerified?'ready':'pending'}"><i class="fa-solid fa-plug-circle-check"></i><span><b>Relay</b><small>${configuration.relayVerified?'Verificado fisicamente':'Corte y restauracion bloqueados'}</small></span></article></div>
    <div class="v72-category-tabs">${categories.map(category=>`<button class="${commandState.category===category?'active':''}" onclick="gpCommandCategory('${safe(category)}')">${safe(category==='todos'?'Todos':categoryText[category]||category)}</button>`).join('')}</div>
    <div class="v72-command-cards">${commands.map(item=>`<button type="button" class="v72-command-card risk-${safe(item.risk)}" ${item.available?`onclick="gpCommandOpen('${safe(item.key)}')"`:'disabled'}><span class="v72-command-card-icon"><i class="fa-solid ${safe(item.icon)}"></i></span><span><b>${safe(item.label)}</b><small>${safe(item.description)}</small><em>${item.channels.map(channel=>`<i class="fa-solid ${channel==='sms'?'fa-comment-sms':'fa-cloud-arrow-up'}"></i>${safe(channel.toUpperCase())}`).join(' ')||safe(item.unavailableReason||'No disponible')}</em></span><span class="v72-risk">${safe(riskText[item.risk]||item.risk)}</span></button>`).join('')}</div>`;
}
function commandRenderConfigure(){
  const catalog=commandState.catalog,device=catalog.device,config=catalog.configuration;
  q('#cmdConfigureBody').innerHTML=`<span class="v72-eyebrow">CONFIGURACION TECNICA</span><h2>${safe(commandDeviceLabel(device))}</h2><p class="v72-modal-lead">Asocia la SIM y la clave GT06. Estos datos se procesan en PHP; el navegador nunca vuelve a recibirlos.</p><form id="cmdConfigureForm" class="v72-form"><div class="v72-form-grid"><label>Codigo interno<input name="code" value="${safe(config.code||commandDeviceLabel(device))}" required maxlength="40"></label><label>Modelo de moto<input name="model" value="${safe(config.model||device.model||'Motocicleta')}" required maxlength="120"></label><label>Telefono de la SIM<input name="simPhone" inputmode="tel" placeholder="58412... · actual ${safe(config.simPhone||'sin registrar')}"><small>Vacio conserva el numero existente.</small></label><label>Clave tecnica GT06<input name="commandPassword" type="password" inputmode="numeric" pattern="[0-9]{6}" placeholder="${config.commandSecretConfigured?'Configurada · dejar vacio para conservar':'6 digitos'}"></label><label class="v72-toggle"><input name="relayVerified" type="checkbox" ${config.relayVerified?'checked':''}><span><b>Relay verificado</b><small>Solo despues de una prueba fisica segura.</small></span></label><label class="v72-toggle"><input name="dataCommandsVerified" type="checkbox" ${config.dataCommandsVerified?'checked':''}><span><b>Comandos por datos probados</b><small>Marca tras confirmar respuesta real.</small></span></label><label class="v72-toggle"><input name="commandsEnabled" type="checkbox" ${config.commandsEnabled?'checked':''}><span><b>Permitir comandos</b><small>Interruptor maestro de esta unidad.</small></span></label><label class="v72-toggle"><input name="syncTraccarPhone" type="checkbox" checked><span><b>Sincronizar Phone en Traccar</b><small>Necesario para el gateway SMS.</small></span></label><label class="full">Contraseña administrativa<input name="adminPassword" type="password" required autocomplete="current-password"></label></div><div class="v72-safety-note"><i class="fa-solid fa-lock"></i><span><b>Credenciales protegidas</b><small>La clave del GPS se cifra en reposo y se oculta en registros y respuestas.</small></span></div><button class="v72-submit"><i class="fa-solid fa-floppy-disk"></i> Guardar configuracion</button></form>`;
  q('#cmdConfigureForm').onsubmit=commandSaveConfigure;
}
async function commandSaveConfigure(event){
  event.preventDefault();const form=event.currentTarget,button=form.querySelector('button[type="submit"],.v72-submit');button.disabled=true;
  const values=Object.fromEntries(new FormData(form));
  try{
    const result=await post(`${commandApi}?action=configure`,{deviceId:commandState.selectedId,code:values.code,model:values.model,simPhone:values.simPhone||'',preserveSimPhone:!values.simPhone,commandPassword:values.commandPassword||'',relayVerified:!!values.relayVerified,dataCommandsVerified:!!values.dataCommandsVerified,commandsEnabled:!!values.commandsEnabled,syncTraccarPhone:!!values.syncTraccarPhone,adminPassword:values.adminPassword});
    modalClose('cmdConfigureModal');notify(result.message||'Configuracion guardada.');await commandSelect(commandState.selectedId);
  }catch(error){notify(error.message,'error');button.disabled=false}
}
function commandRenderDispatch(command){
  const device=commandState.catalog.device;const high=riskOrder[command.risk]>=riskOrder.high;const critical=command.risk==='critical';
  const params=Object.entries(command.params||{}).map(([key,definition])=>`<label>${safe(definition.label||key)}<input name="param_${safe(key)}" type="${safe(definition.type||'text')}" ${definition.min!==undefined?`min="${safe(definition.min)}"`:''} ${definition.max!==undefined?`max="${safe(definition.max)}"`:''} ${definition.default!==undefined?`value="${safe(definition.default)}"`:''} ${definition.placeholder?`placeholder="${safe(definition.placeholder)}"`:''}></label>`).join('');
  q('#cmdDispatchBody').innerHTML=`<div class="v72-command-modal-icon risk-${safe(command.risk)}"><i class="fa-solid ${safe(command.icon)}"></i></div><span class="v72-eyebrow">ORDEN ${safe(riskText[command.risk].toUpperCase())}</span><h2>${safe(command.label)}</h2><p class="v72-modal-lead">${safe(command.description)}</p><form id="cmdDispatchForm" class="v72-form"><div class="v72-command-target"><i class="fa-solid fa-motorcycle"></i><span><small>Destino exacto</small><b>${safe(commandDeviceLabel(device))} · Device ID ${device.id}</b></span></div><div class="v72-form-grid">${params}<label>Canal de envio<select name="channel">${command.channels.includes('data')?'<option value="data">Datos · comando nativo</option>':''}${command.channels.includes('sms')?'<option value="sms">SMS · plantilla del manual</option>':''}${command.channels.length>1?'<option value="auto" selected>Automatico · priorizar datos</option>':''}</select></label>${high?'<label class="full">Motivo operativo<textarea name="reason" minlength="8" maxlength="300" required placeholder="Indica caso, autorizacion o incidencia"></textarea></label><label class="full">Contraseña administrativa<input name="adminPassword" type="password" required autocomplete="current-password"></label>':''}${critical?`<label class="full v72-critical-field">Frase de autorizacion<input name="authorizationPhrase" required autocomplete="off" placeholder="AUTORIZAR ${device.id}"><small>Escribe exactamente: AUTORIZAR ${device.id}</small></label>`:''}<label class="v72-toggle full"><input name="confirmed" type="checkbox" required><span><b>Confirmo el destino y el efecto de esta orden</b><small>La ejecucion quedara asociada a mi sesion e IP.</small></span></label></div>${command.key==='engine_stop'?'<div class="v72-stop-rule"><i class="fa-solid fa-shield-halved"></i><span><b>Corte protegido en servidor</b><small>Solo se envia con relay verificado, posicion menor a 30 segundos, 0 km/h y sin movimiento.</small></span></div>':''}<button class="v72-submit ${critical?'danger':''}"><i class="fa-solid fa-paper-plane"></i> Enviar orden a Traccar</button></form>`;
  q('#cmdDispatchForm').onsubmit=event=>commandDispatch(event,command);
}
async function commandDispatch(event,command){
  event.preventDefault();const form=event.currentTarget,button=form.querySelector('.v72-submit');button.disabled=true;button.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Enviando de forma segura';const values=Object.fromEntries(new FormData(form));const params={};Object.keys(command.params||{}).forEach(key=>{params[key]=values[`param_${key}`]??''});
  try{
    const result=await post(`${commandApi}?action=dispatch`,{deviceId:commandState.selectedId,commandKey:command.key,params,channel:values.channel||'auto',reason:values.reason||'',adminPassword:values.adminPassword||'',authorizationPhrase:values.authorizationPhrase||'',confirmation:'CONFIRMAR'});
    modalClose('cmdDispatchModal');notify(`${result.message} Canal ${String(result.channel||'').toUpperCase()}.`);commandState.audit.unshift({id:result.id,deviceId:commandState.selectedId,commandKey:command.key,commandLabel:command.label,risk:command.risk,channel:result.channel,status:result.status,requestedBy:'Sesion actual',updatedAt:new Date().toISOString()});commandRenderAudit();
  }catch(error){notify(error.message,'error');button.disabled=false;button.innerHTML='<i class="fa-solid fa-paper-plane"></i> Reintentar orden'}
}
async function commandSelect(deviceId){
  commandState.selectedId=Number(deviceId);commandState.category='todos';commandRenderFleet(q('#cmdFleetSearch')?.value||'');const node=q('#cmdWorkspace');if(node)node.innerHTML=loadingBox('Consultando compatibilidad en Traccar');
  try{commandState.catalog=await apiRequest(`${commandApi}?action=catalog&deviceId=${encodeURIComponent(deviceId)}`);commandRenderWorkspace();if(commandState.catalog.realtime&&window.GrandprixRealtime){if(window.gpRealtimeCleanup)window.gpRealtimeCleanup();window.gpRealtimeCleanup=window.GrandprixRealtime.connect(commandState.catalog.realtime,{command:payload=>{const event=commandState.audit.find(item=>Number(item.id)===Number(payload.id));if(event){event.status=payload.status;event.result=payload.result;event.updatedAt=payload.updatedAt;commandRenderAudit()}notify('Traccar confirmo el resultado del comando.')}})}}catch(error){if(node)node.innerHTML=errorBox(error.message,`gpCommandSelect(${Number(deviceId)})`)}
}
async function commandInit(force=false){
  if(commandState.loading)return;commandState.loading=true;
  try{
    const [fleet,audit]=await Promise.all([apiRequest(`${commandApi}?action=fleet`),apiRequest(`${commandApi}?action=audit`)]);commandState.fleet=fleet.devices||[];commandState.audit=audit.events||[];
    const fleetCount=q('#cmdFleetCount'),onlineCount=q('#cmdOnlineCount');if(fleetCount)fleetCount.textContent=String(commandState.fleet.length);if(onlineCount)onlineCount.textContent=String(commandState.fleet.filter(commandFresh).length);commandRenderFleet();commandRenderAudit();
    const preferred=commandState.fleet.some(device=>device.id===commandState.selectedId)?commandState.selectedId:(commandState.fleet[0]?.id||0);if(preferred)await commandSelect(preferred);else q('#cmdWorkspace').innerHTML=errorBox('Traccar aun no tiene dispositivos en la memoria local.','gpCommandRefresh()');
    const search=q('#cmdFleetSearch');if(search)search.oninput=()=>commandRenderFleet(search.value);
  }catch(error){const fleet=q('#cmdFleet');if(fleet)fleet.innerHTML=errorBox(error.message,'gpCommandRefresh()');const audit=q('#cmdAudit');if(audit)audit.innerHTML=errorBox(error.message,'gpCommandRefresh()')}
  finally{commandState.loading=false}
}
window.gpCommandRefresh=()=>commandInit(true);
window.gpCommandSelect=commandSelect;
window.gpCommandCategory=category=>{commandState.category=category;commandRenderWorkspace()};
window.gpCommandConfigure=()=>{commandRenderConfigure();modalOpen('cmdConfigureModal')};
window.gpCommandOpen=key=>{const command=commandState.catalog?.commands.find(item=>item.key===key);if(!command||!command.available)return;commandRenderDispatch(command);modalOpen('cmdDispatchModal')};

views.portal=()=>`<div class="page v72-portal-admin">
  <section class="v72-portal-hero"><div><span class="v72-eyebrow"><i class="fa-solid fa-mobile-screen"></i> MI GRANDPRIX</span><h2>Clientes, motos y 50 semanas</h2><p>Cada cuenta queda vinculada a un solo Device ID y nunca recibe funciones de comando.</p></div><div><button class="v72-primary" onclick="gpPortalNewCustomer()"><i class="fa-solid fa-user-check"></i> Activar cliente</button><button class="v72-primary-soft" onclick="gpPortalRefresh()"><i class="fa-solid fa-rotate"></i> Actualizar</button></div></section>
  <section class="v72-mini-kpis"><article><i class="fa-solid fa-users"></i><span><strong id="portalCustomerCount">—</strong><b>Clientes</b></span></article><article><i class="fa-solid fa-motorcycle"></i><span><strong id="portalAssignedCount">—</strong><b>Motos asignadas</b></span></article><article><i class="fa-solid fa-receipt"></i><span><strong id="portalPendingCount">—</strong><b>Pagos por conciliar</b></span></article><article><i class="fa-solid fa-lock"></i><span><strong>100%</strong><b>Solo lectura GPS</b></span></article></section>
  <div class="v72-portal-grid"><aside class="panel"><div class="panel-head"><div><h2>Cuentas del portal</h2><p>Acceso individual por contrato</p></div></div><label class="v72-search"><i class="fa-solid fa-magnifying-glass"></i><input id="portalSearch" placeholder="Nombre, cedula o moto"></label><div id="portalCustomers" class="v72-customer-list">${loadingBox('Cargando clientes')}</div></aside><section class="panel" id="portalDetail">${loadingBox('Preparando expediente')}</section></div>
  <section class="panel v72-payments-admin"><div class="panel-head"><div><h2>Conciliacion de pagos</h2><p>El cliente reporta; GRANDPRIX verifica y marca la semana pagada</p></div><span class="v72-live-chip"><i></i> COLA OPERATIVA</span></div><div id="portalPayments">${loadingBox('Cargando reportes')}</div></section>
  <div class="v72-modal-backdrop" id="portalCustomerModal"><div class="v72-modal wide"><button class="v72-modal-x" onclick="gpV72CloseModal('portalCustomerModal')"><i class="fa-solid fa-xmark"></i></button><div id="portalCustomerBody"></div></div></div>
</div>`;

function portalCustomerLabel(customer){return customer.full_name||'Cliente'}
function portalRenderCustomers(filter=''){
  const node=q('#portalCustomers');if(!node)return;const term=filter.trim().toLowerCase();const items=portalState.customers.filter(customer=>[customer.full_name,customer.identity_document,customer.code,customer.public_key].some(value=>String(value||'').toLowerCase().includes(term)));
  if(!items.length){node.innerHTML='<div class="v72-empty"><i class="fa-solid fa-user-lock"></i><b>Sin usuarios Mi GRANDPRIX activados</b><small>Selecciona un cliente existente y crea su usuario y clave.</small></div>';return}
  node.innerHTML=items.map(customer=>`<button class="v72-customer-item ${Number(customer.id)===portalState.selectedId?'active':''}" onclick="gpPortalSelect(${Number(customer.id)})"><span>${safe(String(customer.full_name||'C').split(/\s+/).slice(0,2).map(part=>part[0]||'').join('').toUpperCase())}</span><div><b>${safe(portalCustomerLabel(customer))}</b><small>${safe(customer.code||'Sin moto')} · ${Number(customer.paid_weeks||0)}/50 semanas</small></div><em class="${Number(customer.late_weeks||0)>0?'late':'ok'}">${Number(customer.late_weeks||0)>0?`${Number(customer.late_weeks)} vencida(s)`:'Al dia'}</em></button>`).join('');
}
function portalModelImage(customer){
  const text=`${customer?.inventory_brand||''} ${customer?.model||''}`.toLowerCase();
  const map=[[/\bleon\b/,'leon-silver.png'],[/\bsbr\b/,'sbr-blue.png'],[/\bbrf\b/,'brf-red.png'],[/veloz/,'veloz-white.png'],[/socialista/,'socialista-blue.png'],[/lovis/,'lovis-cream.png'],[/kadi/,'kadi-classic-red.png'],[/\bx1\b/,'x1-yellow.png'],[/aguila/,'aguila-black.png'],[/\bgbr\b/,'gbr-black.png'],[/express/,'express-blue.png']];
  return 'assets/inventory-models/'+(map.find(([r])=>r.test(text))?.[1]||'generic-default.png');
}
function portalRenderDetail(){
  const node=q('#portalDetail');if(!node)return;const customer=portalState.customers.find(item=>Number(item.id)===portalState.selectedId);if(!customer){node.innerHTML='<div class="v72-state"><i class="fa-solid fa-user-lock"></i><h3>Selecciona un cliente</h3><p>Verás su moto, GPS y plan financiero.</p></div>';return}
  const progress=Math.min(100,(Number(customer.paid_weeks||0)/50)*100),photo=portalModelImage(customer);
  node.innerHTML=`<div class="v72-portal-detail-head"><div><span class="v72-eyebrow">EXPEDIENTE DEL CLIENTE</span><h2>${safe(portalCustomerLabel(customer))}</h2><p>${safe(customer.identity_document)} · @${safe(customer.public_key)}</p></div><span class="v72-status ${safe(customer.status)}">${customer.status==='active'?'Cuenta activa':'Suspendida'}</span></div><div class="v72-client-bike v22-client-bike"><img src="${photo}" alt="${safe(customer.model||'Motocicleta')}" onerror="this.onerror=null;this.src='assets/inventory-models/generic-default.png'"><div><small>MOTO ASIGNADA DESDE INVENTARIO</small><h3>${safe(customer.code||customer.plate||'Sin unidad')}</h3><p>${safe([customer.inventory_brand,customer.model].filter(Boolean).join(' ')||'Modelo pendiente')} ${customer.plate?`· ${safe(customer.plate)}`:''}</p><span><i class="fa-solid fa-satellite-dish"></i> Device ID ${safe(customer.traccar_device_id||'sin asignar')}${customer.gps_unique_id?` · IMEI ${safe(customer.gps_unique_id)}`:''}</span></div></div><div class="v72-finance-progress"><div><span><small>Contrato</small><b>${safe(customer.contract_number||'Pendiente')}</b></span><span><small>Cuota semanal</small><b>${currency(customer.weekly_amount)}</b></span><span><small>Plan</small><b>${Number(customer.paid_weeks||0)} / 50</b></span></div><div class="v72-progress"><i style="width:${progress}%"></i></div></div><div class="v72-permission-card"><i class="fa-solid fa-eye"></i><span><b>Permiso del cliente: consulta de su propia unidad</b><small>Solo ve su motocicleta, su GPS, velocímetro, semanas, pagos, recibos y documentos. Nunca recibe comandos administrativos.</small></span></div><div class="v72-detail-actions"><button onclick="gpPortalEdit(${Number(customer.id)})"><i class="fa-solid fa-key"></i> Usuario / clave</button><button onclick="gpPortalPreview(${Number(customer.id)})"><i class="fa-solid fa-mobile-screen"></i> Abrir vista cliente</button></div>`;
}

function portalRenderPayments(){
  const node=q('#portalPayments');if(!node)return;if(!portalState.payments.length){node.innerHTML='<div class="v72-empty horizontal"><i class="fa-solid fa-circle-check"></i><span><b>Cola al dia</b><small>No hay transferencias pendientes de conciliacion.</small></span></div>';return}
  node.innerHTML=`<div class="v72-payment-table"><div class="v72-payment-head"><span>Cliente / moto</span><span>Transferencia</span><span>Monto</span><span>Evidencia</span><span>Decision</span></div>${portalState.payments.map(payment=>`<article><span><b>${safe(payment.full_name)}</b><small>${safe(payment.code)} · Semana ${Number(payment.week_number)}</small></span><span><b>${safe(payment.bank)}</b><small>Ref. ${safe(payment.reference_number)} · ${safe(payment.transfer_date)}</small></span><strong>${currency(payment.amount)}</strong><span>${Number(payment.has_proof)?`<a target="_blank" href="api/customer.php?action=proof&id=${Number(payment.id)}"><i class="fa-solid fa-paperclip"></i> Ver captura</a>`:'Sin adjunto'}</span><span class="v72-review-actions"><button onclick="gpPortalReview(${Number(payment.id)},'approved')"><i class="fa-solid fa-check"></i> Aprobar</button><button class="reject" onclick="gpPortalReview(${Number(payment.id)},'rejected')"><i class="fa-solid fa-xmark"></i></button></span></article>`).join('')}</div>`;
}
function portalAccessKey(candidate){
  const base=String(candidate?.full_name||'cliente').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,'');
  return (base||'cliente').slice(0,54)+'-'+String(candidate?.finance_account_id||'gp');
}
function portalCandidateMissing(c){
  if(!c)return ['cliente'];
  const missing=[];
  if(Number(c.inventory_id||0)<1||!String(c.plate||'').trim()||!String(c.model||'').trim())missing.push('moto de Inventario');
  if(Number(c.gps_device_id||0)<1)missing.push('GPS');
  if(String(c.identity_document||'').replace(/[^A-Za-z0-9]/g,'').length<5)missing.push('cédula');
  if(!String(c.contract_number||'').trim())missing.push('contrato');
  if(!String(c.start_date||'').trim())missing.push('fecha de inicio');
  if(Number(c.weekly_amount||0)<=0)missing.push('cuota semanal');
  return missing;
}
function portalCandidateReady(c){return !!c&&portalCandidateMissing(c).length===0}
function portalCandidatePreview(c){
  if(!c)return `<div class="v22-portal-candidate-empty"><i class="fa-solid fa-user-check"></i><span><b>Selecciona un cliente existente</b><small>La moto, placa, contrato y GPS se heredarán automáticamente de su expediente.</small></span></div>`;
  const missing=portalCandidateMissing(c),ready=missing.length===0,photo=portalModelImage({inventory_brand:c.brand,model:c.model});
  return `<article class="v22-portal-candidate ${ready?'ready':'missing'}"><img src="${photo}" alt="${safe(c.model||'Moto')}" onerror="this.onerror=null;this.src='assets/inventory-models/generic-default.png'"><div class="v22-candidate-copy"><span class="v72-eyebrow">CLIENTE EXISTENTE</span><h3>${safe(c.full_name)}</h3><p>${safe(c.identity_document||'Cédula pendiente')} · ${safe(c.phone||'Teléfono pendiente')}</p><div><span><small>PLACA</small><b>${safe(c.plate||'Sin asignar')}</b></span><span><small>MOTO</small><b>${safe([c.brand,c.model].filter(Boolean).join(' ')||'Sin asignar')}</b></span><span><small>GPS</small><b>${c.gps_device_id?'ID '+safe(c.gps_device_id):'Sin GPS'}</b></span><span><small>CONTRATO</small><b>${safe(c.contract_number||'Pendiente')}</b></span></div><em class="${ready?'ok':'warn'}"><i class="fa-solid ${ready?'fa-circle-check':'fa-triangle-exclamation'}"></i>${ready?'Listo para activar Mi GRANDPRIX':'Falta: '+safe(missing.join(', '))}</em></div></article>`;
}
function portalCustomerForm(customer=null){
  const editing=!!customer,linkedFinanceId=Number(customer?.finance_account_id||0),candidates=portalState.financeCandidates||[];
  const currentCandidate=candidates.find(c=>Number(c.finance_account_id)===linkedFinanceId)||null;
  const available=candidates.filter(c=>!c.portal_customer_id||Number(c.portal_customer_id)===Number(customer?.id||0));
  const options=available.map(c=>{const missing=portalCandidateMissing(c),suffix=missing.length?' · FALTA: '+missing.join(', '):' · LISTO';return `<option value="${Number(c.finance_account_id)}" ${Number(c.finance_account_id)===linkedFinanceId?'selected':''}>${safe(c.full_name)} · ${safe(c.plate||'sin moto')} · ${c.gps_device_id?'GPS '+safe(c.gps_device_id):'sin GPS'}${safe(suffix)}</option>`}).join('');
  q('#portalCustomerBody').innerHTML=`<span class="v72-eyebrow">${editing?'ACTUALIZAR ACCESO':'CREAR ACCESO MI GRANDPRIX'}</span><h2>${editing?safe(portalCustomerLabel(customer)):'Selecciona un cliente real'}</h2><p class="v72-modal-lead">Aquí no se vuelve a asignar una moto ni un GPS. Selecciona un cliente de <b>Clientes y créditos</b>; GRANDPRIX hereda automáticamente la unidad y el Device ID que ya tiene en Inventario.</p><form id="portalCustomerForm" class="v72-form"><input type="hidden" name="id" value="${editing?Number(customer.id):0}">${editing&&linkedFinanceId>0?`<input type="hidden" name="financeAccountId" value="${linkedFinanceId}">`:`<label class="full v22-portal-client-select">Cliente de Clientes y créditos<select name="financeAccountId" id="v22PortalFinance" required><option value="">Seleccionar cliente para ${editing?'vincular usuario':'crear usuario'}</option>${options}</select><small>Puedes seleccionar cualquier cliente real. GRANDPRIX te mostrará exactamente qué dato falta antes de habilitar la creación del usuario.</small></label>`}<div id="v22PortalCandidate" class="full">${portalCandidatePreview(currentCandidate)}</div><div class="v72-form-grid"><label>Usuario de acceso<input name="key" id="v22PortalKey" required pattern="[a-z0-9-]{3,80}" value="${safe(customer?.public_key||'')}" placeholder="nombre-apellido-123"></label><label>${editing?'Nueva clave (opcional)':'Clave inicial'}<input name="password" type="password" ${editing?'':'required'} minlength="8" autocomplete="new-password" placeholder="Mínimo 8 caracteres"></label><label class="full">Correo de acceso / contacto<input name="email" type="email" value="${safe(customer?.email||'')}" placeholder="Opcional"></label><label class="v72-toggle full"><input name="active" type="checkbox" ${!customer||customer.status==='active'?'checked':''}><span><b>Activar usuario inmediatamente</b><small>Al guardar, el cliente podrá iniciar sesión y solo verá la motocicleta y GPS ya asignados a su expediente.</small></span></label></div><button class="v72-submit" ${!editing&&!options?'disabled':''}><i class="fa-solid fa-user-shield"></i> ${editing?'Guardar usuario / clave':'Crear y activar usuario'}</button></form>`;
  const selector=q('#v22PortalFinance'),preview=q('#v22PortalCandidate'),key=q('#v22PortalKey');
  const submit=q('#portalCustomerForm .v72-submit');
  const syncCandidate=()=>{const c=candidates.find(x=>Number(x.finance_account_id)===Number(selector?.value||linkedFinanceId))||currentCandidate||null;if(preview)preview.innerHTML=portalCandidatePreview(c);if(c&&key&&!key.value)key.value=portalAccessKey(c);if(submit&&!editing)submit.disabled=!portalCandidateReady(c)};
  if(selector)selector.onchange=syncCandidate;
  syncCandidate();
  q('#portalCustomerForm').onsubmit=portalSaveCustomer;
}
async function portalSaveCustomer(event){event.preventDefault();const form=event.currentTarget,button=form.querySelector('.v72-submit');button.disabled=true;const values=Object.fromEntries(new FormData(form));try{const result=await post(`${customerAdminApi}?action=save-customer`,{id:Number(values.id||0),financeAccountId:Number(values.financeAccountId||0),key:values.key,password:values.password,email:values.email,active:!!values.active});modalClose('portalCustomerModal');notify(result.message);await portalInit(true);if(result.customer?.id)portalSelect(Number(result.customer.id))}catch(error){notify(error.message,'error');button.disabled=false}}

async function portalReview(id,decision){const verb=decision==='approved'?'conciliar':'rechazar';if(!window.confirm(`¿Confirmas ${verb} este reporte de pago?`))return;try{const result=await post(`${customerAdminApi}?action=review-payment`,{id,decision});notify(result.message);portalState.payments=portalState.payments.filter(payment=>Number(payment.id)!==Number(id));const pending=q('#portalPendingCount');if(pending)pending.textContent=String(portalState.payments.length);portalRenderPayments();await portalInit(true)}catch(error){notify(error.message,'error')}}
async function portalPreview(id){try{await post(`${customerAdminApi}?action=preview-customer`,{id});window.open('cliente/','_blank','noopener')}catch(error){notify(error.message,'error')}}
function portalSelect(id){portalState.selectedId=Number(id);portalRenderCustomers(q('#portalSearch')?.value||'');portalRenderDetail()}
async function portalInit(force=false){if(portalState.loading)return;portalState.loading=true;try{const result=await apiRequest(`${customerAdminApi}?action=overview`);portalState.devices=result.devices||[];portalState.customers=result.customers||[];portalState.payments=result.pendingPayments||[];portalState.financeCandidates=result.financeCandidates||[];const customerCount=q('#portalCustomerCount'),assignedCount=q('#portalAssignedCount'),pendingCount=q('#portalPendingCount');if(customerCount)customerCount.textContent=String(portalState.customers.length);if(assignedCount)assignedCount.textContent=String(portalState.customers.filter(item=>item.traccar_device_id).length);if(pendingCount)pendingCount.textContent=String(portalState.payments.length);if(!portalState.customers.some(item=>Number(item.id)===portalState.selectedId))portalState.selectedId=Number(portalState.customers[0]?.id||0);portalRenderCustomers();portalRenderDetail();portalRenderPayments();const search=q('#portalSearch');if(search)search.oninput=()=>portalRenderCustomers(search.value)}catch(error){const customers=q('#portalCustomers');if(customers)customers.innerHTML=errorBox(error.message,'gpPortalRefresh()');const detail=q('#portalDetail');if(detail)detail.innerHTML=errorBox(error.message,'gpPortalRefresh()');const payments=q('#portalPayments');if(payments)payments.innerHTML=errorBox(error.message,'gpPortalRefresh()')}finally{portalState.loading=false}}
window.gpPortalRefresh=()=>portalInit(true);
window.gpPortalSelect=portalSelect;
window.gpPortalNewCustomer=()=>{portalCustomerForm();modalOpen('portalCustomerModal')};
window.gpPortalEdit=id=>{const customer=portalState.customers.find(item=>Number(item.id)===Number(id));if(customer){portalCustomerForm(customer);modalOpen('portalCustomerModal')}};
window.gpPortalReview=portalReview;
window.gpPortalPreview=portalPreview;

const previousBindActions=bindActions;
bindActions=function(){previousBindActions();if(current==='comandos')commandInit();if(current==='portal')portalInit()};

// El acceso rapido de apagado conduce al centro auditado, nunca al endpoint legado.
window.openDanger=function(){navigate('comandos');notify('Selecciona la unidad y usa Corte protegido.')};
})();
