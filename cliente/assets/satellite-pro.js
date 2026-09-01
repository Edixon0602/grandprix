/* Mi GRANDPRIX V7 · Satelite Pro para la motocicleta asignada.
 * No genera telemetria ni recorridos simulados.
 */
(()=>{
'use strict';

const endpoint='../api/traccar.php';
let mapSettings={configured:false,key:'',defaultStyle:'hybrid'};
let selectedStyle='hybrid';
let sessionTrail=[];
let speedSamples=[];
let markerElement=null;

const num=value=>value!==null&&value!==undefined&&value!==''&&Number.isFinite(Number(value))?Number(value):null;
const valid=position=>position&&num(position.latitude)!==null&&num(position.longitude)!==null&&!(Number(position.latitude)===0&&Number(position.longitude)===0);
const compass=value=>['N','NE','E','SE','S','SO','O','NO'][Math.round((num(value)||0)/45)%8];
const set=(id,value)=>{const node=document.getElementById(id);if(node)node.textContent=value};
const escapeText=value=>String(value??'').replace(/[<>]/g,'');
const ago=value=>{if(!value)return 'Sin reporte';const date=new Date(value);if(Number.isNaN(date.getTime()))return 'Sin reporte';const seconds=Math.max(0,Math.round((Date.now()-date.getTime())/1000));if(seconds<45)return 'Ahora';if(seconds<3600)return `Hace ${Math.max(1,Math.round(seconds/60))} min`;if(seconds<86400)return `Hace ${Math.round(seconds/3600)} h`;return `Hace ${Math.round(seconds/86400)} d`};

async function latest(){
  const response=await fetch(`${endpoint}?action=customer-position`,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
  let payload={};try{payload=await response.json()}catch(_){payload={error:'Respuesta GPS no valida.'}}
  if(!response.ok||payload.ok===false)throw new Error(payload.error||'No fue posible consultar el GPS asignado.');
  if(payload.mode!=='production'||!payload.device)throw new Error('El portal no esta conectado al entorno de produccion.');
  if(payload.polling!==false||payload.delivery!=='webhook-websocket')throw new Error('Actualizacion incompleta: el portal y la API no pertenecen a la misma version.');
  return payload;
}

function normalizeStyle(style){
  if(style==='streets-v2')return 'streets-v4';
  if(style==='streets-v2-dark')return 'dataviz-dark';
  return ['hybrid','streets-v4','dataviz-dark'].includes(style)?style:'hybrid';
}

function fallbackStyle(dark=false){
  return {version:8,sources:{osm:{type:'raster',tiles:['https://a.tile.openstreetmap.org/{z}/{x}/{y}.png','https://b.tile.openstreetmap.org/{z}/{x}/{y}.png','https://c.tile.openstreetmap.org/{z}/{x}/{y}.png'],tileSize:256,attribution:'© OpenStreetMap contributors'}},layers:[{id:'osm-bg',type:'background',paint:{'background-color':dark?'#071a31':'#eef4f8'}},{id:'osm',type:'raster',source:'osm',paint:{'raster-saturation':dark?-.65:0,'raster-brightness-max':dark?.48:1,'raster-contrast':dark?.35:0}}]};
}

function styleUrl(style){
  const normalized=normalizeStyle(style);
  if(!mapSettings.configured||!mapSettings.key)return fallbackStyle(normalized==='dataviz-dark');
  return `https://api.maptiler.com/maps/${normalized}/style.json?key=${encodeURIComponent(mapSettings.key)}`;
}

function freshness(device){
  const updated=(device.position&&device.position.fixTime)||device.lastUpdate;
  if(!updated)return {label:'Sin reporte',online:false,className:'bad'};
  const stamp=new Date(updated).getTime();const seconds=Number.isFinite(stamp)?Math.max(0,(Date.now()-stamp)/1000):Infinity;
  if(device.status==='offline'||seconds>600)return {label:'Reporte antiguo',online:false,className:'bad'};
  if(seconds>60)return {label:'Con retraso',online:true,className:'warn'};
  return {label:'En vivo',online:true,className:'good'};
}

function connection(device){
  const fresh=freshness(device);
  set('gpsDataMode',fresh.online?'● TRACCAR EN PRODUCCION':'● ULTIMO REPORTE');
  set('gpsOnline',`● ${fresh.label.toUpperCase()}`);set('gpsStatus',fresh.online?'● GPS conectado':'● GPS sin reporte reciente');
  document.querySelector('.head-right>span')?.classList.toggle('offline',!fresh.online);
  const badge=document.getElementById('gpsOnline');if(badge){badge.classList.remove('good','warn','bad');badge.classList.add(fresh.className)}
}

function updatePanel(device){
  const position=device.position||{};
  const speed=num(position.speedKmh);
  if(speed!==null){speedSamples.push(Math.max(0,speed));if(speedSamples.length>180)speedSamples.shift()}
  const maximum=speedSamples.length?Math.max(...speedSamples):null;
  const average=speedSamples.length?speedSamples.reduce((sum,value)=>sum+value,0)/speedSamples.length:null;
  const updated=position.fixTime||device.lastUpdate;
  set('gpsDeviceName',data.moto||'Mi motocicleta');set('gpsDeviceMeta',`${data.model||'Motocicleta asignada'}${data.plate?` · ${data.plate}`:''}`);
  set('gpsSpeed',speed===null?'—':Math.round(speed));set('gpsMaxSpeed',maximum===null?'Sin dato':`${Math.round(maximum)} km/h`);set('gpsAvgSpeed',average===null?'Sin dato':`${Math.round(average)} km/h`);
  set('gpsIgnition',position.ignition===false?'Apagada':position.ignition===true?'Encendida':'Sin dato');set('gpsCourse',num(position.course)===null?'Sin dato':`${compass(position.course)} · ${Math.round(num(position.course))}°`);
  const battery=num(position.battery);set('gpsBattery',battery===null?'Sin dato':`${Math.round(battery)}%`);
  const signal=num(position.signal);set('gpsSignal',signal===null?'Sin dato':String(Math.round(signal)));
  const satellites=num(position.satellites);set('gpsSatellites',satellites===null?'Sin dato':String(Math.round(satellites)));
  const distance=num(position.totalDistanceKm);set('gpsDistance',distance===null?'Sin dato':`${distance.toLocaleString('es-VE')} km`);
  const altitude=num(position.altitude);set('gpsAltitude',altitude===null?'Sin dato':`${altitude.toLocaleString('es-VE')} m`);
  const accuracy=num(position.accuracy);set('gpsAccuracy',accuracy===null?'Sin dato':`±${Math.round(accuracy)} m`);
  set('gpsLast',ago(updated));set('gpsLocation',position.address||'Coordenadas GPS');set('gpsLocationUpdated',`● ${Number(position.latitude).toFixed(5)}, ${Number(position.longitude).toFixed(5)} · ${ago(updated)}`);
  set('gpsRefresh','Webhook + WebSocket privado · sin polling');connection(device);
  const gauge=document.getElementById('gauge');
  if(gauge){const bounded=Math.max(0,Math.min(160,speed===null?0:speed));gauge.style.setProperty('--needle',`${-90+(bounded/160)*180}deg`);gauge.style.setProperty('--speed',String(bounded/160))}
  if(markerElement){markerElement.style.setProperty('--heading',`${num(position.course)||0}deg`);markerElement.classList.toggle('stale',!freshness(device).online)}
}

function trailData(){return {type:'FeatureCollection',features:sessionTrail.length>1?[{type:'Feature',properties:{},geometry:{type:'LineString',coordinates:sessionTrail}}]:[]}}

function addTrailLayers(){
  if(!map||!map.isStyleLoaded())return;
  if(!map.getSource('customer-live-route'))map.addSource('customer-live-route',{type:'geojson',data:trailData()});
  if(!map.getLayer('customer-route-glow'))map.addLayer({id:'customer-route-glow',type:'line',source:'customer-live-route',paint:{'line-color':'#21e4d0','line-width':11,'line-opacity':.2,'line-blur':5}});
  if(!map.getLayer('customer-route'))map.addLayer({id:'customer-route',type:'line',source:'customer-live-route',paint:{'line-color':'#35cfff','line-width':4,'line-opacity':.95},layout:{'line-cap':'round','line-join':'round'}});
}

function updateTrail(){if(map&&map.getSource('customer-live-route'))map.getSource('customer-live-route').setData(trailData())}

window.customerSetMapStyle=style=>{
  selectedStyle=normalizeStyle(style);
  document.querySelectorAll('[data-customer-map-style]').forEach(button=>button.classList.toggle('active',button.dataset.customerMapStyle===selectedStyle));
  if(!map)return;
  map.setStyle(styleUrl(selectedStyle));map.once('style.load',addTrailLayers);
  if(!mapSettings.configured)toast('Agrega la llave MapTiler para activar Satelite Pro');
};

function renderError(error,title='GPS temporalmente no disponible'){
  if(window.gpRealtimeCleanup){window.gpRealtimeCleanup();window.gpRealtimeCleanup=null}if(map){map.remove();map=null}
  const node=document.getElementById('map');
  if(node)node.innerHTML=`<div class="customer-gps-error"><span><i class="fa-solid fa-satellite-dish"></i></span><small>TRACCAR · PRODUCCION</small><h2>${title}</h2><p>${escapeText(error&&error.message?error.message:error)}</p><button onclick="initMap()"><i class="fa-solid fa-rotate"></i> Reintentar conexion</button><em>No se muestran ubicaciones ni valores simulados.</em></div>`;
  set('gpsDataMode','● GPS SIN CONEXION');set('gpsOnline','● SIN CONEXION');set('gpsStatus','● GPS sin conexion');set('gpsSpeed','—');set('gpsMaxSpeed','Sin dato');set('gpsAvgSpeed','Sin dato');set('gpsIgnition','Sin dato');set('gpsCourse','—');set('gpsBattery','Sin dato');set('gpsSignal','Sin dato');set('gpsSatellites','Sin dato');set('gpsDistance','Sin dato');set('gpsAltitude','Sin dato');set('gpsAccuracy','Sin dato');set('gpsLast','Sin reporte');
}

function applyDevice(device){
  const position=device&&device.position;if(!valid(position)){connection(device||{});return}
  const point=[Number(position.longitude),Number(position.latitude)];
  if(marker){
    const current=marker.getLngLat();
    if(current.lng!==point[0]||current.lat!==point[1]){
      marker.setLngLat(point);const last=sessionTrail[sessionTrail.length-1];
      if(!last||last[0]!==point[0]||last[1]!==point[1])sessionTrail.push(point);
      if(sessionTrail.length>240)sessionTrail.shift();updateTrail();map.easeTo({center:point,duration:900});
    }
  }
  updatePanel(device);
}

function realtimeStatus(state){
  if(state==='connected'){
    set('gpsDataMode','● WEBHOOK EN VIVO');set('gpsRefresh','WebSocket privado conectado · cero polling');return;
  }
  if(state==='disabled'){
    set('gpsDataMode','● ÚLTIMA POSICIÓN');set('gpsRefresh','Canal WebSocket pendiente · sin polling');return;
  }
  set('gpsDataMode','● CONECTANDO WEBSOCKET');
}

function connectRealtime(config){
  if(window.gpRealtimeCleanup){window.gpRealtimeCleanup();window.gpRealtimeCleanup=null}
  if(!window.GrandprixRealtime){realtimeStatus('disabled');return}
  window.gpRealtimeCleanup=window.GrandprixRealtime.connect(config,{
    status:realtimeStatus,
    position:payload=>{if(payload.device)applyDevice(payload.device)},
    event:payload=>{if(payload.device)applyDevice(payload.device)},
    error:()=>realtimeStatus('error')
  });
}

function createMarker(device){
  markerElement=document.createElement('div');markerElement.className='gp-customer-marker';markerElement.innerHTML='<span></span><i class="fa-solid fa-motorcycle"></i><b>MI MOTO</b>';
  markerElement.style.setProperty('--heading',`${num(device.position.course)||0}deg`);
  marker=new maplibregl.Marker({element:markerElement,anchor:'center'}).setLngLat([Number(device.position.longitude),Number(device.position.latitude)]).addTo(map);
}

function startMap(payload){
  const device=payload.device,position=device&&device.position;
  if(!valid(position)){renderError('El GPS esta asignado, pero aun no ha enviado una posicion valida a Traccar.','Esperando la primera posicion');return}
  mapSettings=payload.mapConfig||mapSettings;selectedStyle=normalizeStyle(mapSettings.defaultStyle||'hybrid');
  document.querySelectorAll('[data-customer-map-style]').forEach(button=>button.classList.toggle('active',button.dataset.customerMapStyle===selectedStyle));
  const point=[Number(position.longitude),Number(position.latitude)];sessionTrail=[point];speedSamples=[];
  const node=document.getElementById('map');node.innerHTML='';
  map=new maplibregl.Map({container:'map',style:styleUrl(selectedStyle),center:point,zoom:16.3,pitch:48,bearing:-10,maxPitch:70,attributionControl:false,antialias:true});
  map.addControl(new maplibregl.NavigationControl({showCompass:true,visualizePitch:true}),'bottom-left');
  map.addControl(new maplibregl.AttributionControl({compact:true,customAttribution:'Mi GRANDPRIX'}),'bottom-right');
  map.on('load',()=>{addTrailLayers();createMarker(device)});
  map.on('error',event=>{if(event&&event.error&&String(event.error.message||'').includes('401'))toast('MapTiler rechazo la llave configurada')});
  if(!mapSettings.configured){const warning=document.createElement('div');warning.className='gp-map-key-warning customer';warning.innerHTML='<i class="fa-solid fa-key"></i><span><b>Mapa de respaldo</b><small>MapTiler pendiente</small></span>';node.appendChild(warning)}
  updatePanel(device);connectRealtime(payload.realtime);
}

async function refresh(){
  try{
    const payload=await latest();applyDevice(payload.device);
  }catch(error){set('gpsDataMode','● RECONECTANDO');set('gpsOnline','● SIN CONEXION');set('gpsLast',error.message)}
}

window.customerRefreshGps=refresh;

initMap=async function(){
  if(window.gpRealtimeCleanup){window.gpRealtimeCleanup();window.gpRealtimeCleanup=null}if(map){map.remove();map=null}marker=null;markerElement=null;
  const node=document.getElementById('map');if(!node)return;
  node.innerHTML='<div class="customer-gps-loading"><span></span><b>Conectando Satelite Pro</b><small>MapTiler + MapLibre · posicion real de tu moto</small></div>';
  try{startMap(await latest())}catch(error){renderError(error)}
};
})();
