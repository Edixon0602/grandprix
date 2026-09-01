/* GRANDPRIX V7 · Satelite Pro / MapTiler + MapLibre
 * Posiciones, velocidad y telemetria proceden exclusivamente de Traccar.
 */
(()=>{
'use strict';

const endpoint=(window.GRANDPRIX&&window.GRANDPRIX.traccarApi)||'api/traccar.php';
const deviceMarkers=new Map();
const trailCoordinates=new Map();
let fleetDevices=[];
let selectedDeviceId=0;
let mapSettings={configured:false,key:'',defaultStyle:'hybrid'};
let selectedStyle='hybrid';
let geofenceFeatures=[];
let geofencesVisible=true;
let commandsAvailable=false;

const esc=value=>String(value??'').replace(/[&<>'"]/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[char]));
const num=value=>value!==null&&value!==undefined&&value!==''&&Number.isFinite(Number(value))?Number(value):null;
const validPosition=position=>position&&num(position.latitude)!==null&&num(position.longitude)!==null&&Math.abs(Number(position.latitude))<=90&&Math.abs(Number(position.longitude))<=180&&!(Number(position.latitude)===0&&Number(position.longitude)===0);
const compass=value=>['N','NE','E','SE','S','SO','O','NO'][Math.round((num(value)||0)/45)%8];
const format=value=>{if(!value)return 'Sin reporte';const date=new Date(value);return Number.isNaN(date.getTime())?'Sin reporte':date.toLocaleString('es-VE',{dateStyle:'short',timeStyle:'short'})};
const ago=value=>{if(!value)return 'Sin reporte';const date=new Date(value);if(Number.isNaN(date.getTime()))return 'Sin reporte';const seconds=Math.max(0,Math.round((Date.now()-date.getTime())/1000));if(seconds<45)return 'Ahora';if(seconds<3600)return `Hace ${Math.max(1,Math.round(seconds/60))} min`;if(seconds<86400)return `Hace ${Math.round(seconds/3600)} h`;return `Hace ${Math.round(seconds/86400)} d`};

async function json(url){
  const response=await fetch(url,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
  let payload={};
  try{payload=await response.json()}catch(_){payload={error:'Respuesta no valida del servidor.'}}
  if(!response.ok||payload.ok===false)throw new Error(payload.error||`Error HTTP ${response.status}`);
  return payload;
}

function notify(message,type='ok'){
  const toast=document.getElementById('toast');
  if(!toast)return;
  const icon=type==='error'?'fa-triangle-exclamation':type==='warn'?'fa-clock':'fa-circle-check';
  toast.innerHTML=`<i class="fa-solid ${icon}" style="color:${type==='error'?'#ff6b7e':type==='warn'?'#f6b94a':'#20d7ad'};margin-right:7px"></i>${esc(message)}`;
  toast.classList.add('show');setTimeout(()=>toast.classList.remove('show'),3200);
}

function normalizeStyle(style){
  if(style==='streets-v2')return 'streets-v4';
  if(style==='streets-v2-dark')return 'dataviz-dark';
  return ['hybrid','streets-v4','dataviz-dark'].includes(style)?style:'hybrid';
}

function fallbackStyle(dark=false){
  return {
    version:8,
    sources:{osm:{type:'raster',tiles:['https://a.tile.openstreetmap.org/{z}/{x}/{y}.png','https://b.tile.openstreetmap.org/{z}/{x}/{y}.png','https://c.tile.openstreetmap.org/{z}/{x}/{y}.png'],tileSize:256,attribution:'© OpenStreetMap contributors'}},
    layers:[{id:'osm-background',type:'background',paint:{'background-color':dark?'#071a31':'#edf3f7'}},{id:'osm-tiles',type:'raster',source:'osm',paint:{'raster-saturation':dark?-.65:0,'raster-brightness-max':dark?.48:1,'raster-contrast':dark?.35:0}}]
  };
}

function mapStyle(style){
  const normalized=normalizeStyle(style);
  if(!mapSettings.configured||!mapSettings.key)return fallbackStyle(normalized==='dataviz-dark');
  return `https://api.maptiler.com/maps/${normalized}/style.json?key=${encodeURIComponent(mapSettings.key)}`;
}

function freshness(device){
  const updated=(device.position&&device.position.fixTime)||device.lastUpdate;
  if(!updated)return {label:'Sin reporte',className:'bad',seconds:Infinity};
  const stamp=new Date(updated).getTime();
  if(!Number.isFinite(stamp))return {label:'Sin reporte',className:'bad',seconds:Infinity};
  const seconds=Math.max(0,(Date.now()-stamp)/1000);
  if(device.status==='offline'||seconds>600)return {label:'Reporte antiguo',className:'bad',seconds};
  if(seconds>60)return {label:'Con retraso',className:'warn',seconds};
  return {label:'Telemetria en vivo',className:'good',seconds};
}

function statusText(device){
  if(device.status==='offline')return 'Sin conexion';
  if(device.status==='online')return 'En linea';
  return 'Estado desconocido';
}

function markerState(device){
  const fresh=freshness(device);
  const speed=num(device.position&&device.position.speedKmh)||0;
  if(fresh.className==='bad')return 'offline';
  return speed>1?'moving':'parked';
}

function updateMapStatus(payload){
  const devices=payload.devices||[];
  const online=devices.filter(device=>device.status==='online').length;
  const label=document.querySelector('.central small');
  if(label)label.textContent=`${online} de ${devices.length} GPS en linea · Traccar`;
  const live=document.querySelector('.live');
  if(live)live.innerHTML='<i></i> WEBHOOK GPS EN VIVO';
}

function gaugeMarkup(speed){
  const value=num(speed);
  const bounded=Math.max(0,Math.min(160,value===null?0:value));
  const needle=-90+(bounded/160)*180;
  return `<div class="gp-speedometer" style="--gp-needle:${needle}deg;--gp-speed:${bounded/160}">
    <div class="gp-gauge-head"><span><i class="fa-solid fa-gauge-high"></i> VELOCIDAD GPS</span><em>0 — 160 km/h</em></div>
    <div class="gp-gauge"><div class="gp-gauge-scale"><i style="--i:0">0</i><i style="--i:1">40</i><i style="--i:2">80</i><i style="--i:3">120</i><i style="--i:4">160</i></div><div class="gp-needle"></div><div class="gp-gauge-value"><strong>${value===null?'—':Math.round(value)}</strong><small>km/h</small></div></div>
  </div>`;
}

function detailMarkup(device){
  const position=device.position||{};
  const fresh=freshness(device);
  const speed=num(position.speedKmh);
  const battery=num(position.battery);
  const satellites=num(position.satellites);
  const signal=num(position.signal);
  const distance=num(position.totalDistanceKm);
  const altitude=num(position.altitude);
  const accuracy=num(position.accuracy);
  const ignition=position.ignition===true?'ENCENDIDA':position.ignition===false?'APAGADA':'SIN DATO';
  const motion=position.motion===true?'En movimiento':position.motion===false?'Detenida':speed!==null&&speed>1?'En movimiento':'Sin dato';
  const updated=position.fixTime||device.lastUpdate;
  const canStop=commandsAvailable&&fresh.seconds<=120&&speed!==null&&speed<=1;
  return `<div class="production-unit gp-unit-premium">
    <div class="unit-top"><div><span class="eyebrow">UNIDAD SELECCIONADA · TRACCAR</span><h2>${esc(device.name||`GPS ${device.id}`)}</h2><p>${esc(device.model||device.category||'Motocicleta')} · ID ${esc(device.uniqueId||device.id)}</p></div><span class="tag-state ${fresh.className}">● ${esc(fresh.label)}</span></div>
    <div class="gp-moto-status"><div class="production-moto"><img src="assets/moto-blue.png" alt="Motocicleta asignada"></div><div><span><i class="fa-solid fa-satellite-dish"></i> ${esc(statusText(device))}</span><b>${esc(motion)}</b><small>${esc(ago(updated))}</small></div></div>
    ${gaugeMarkup(speed)}
    <div class="gp-ignition"><span><i class="fa-solid fa-key"></i><small>Ignicion</small><b>${ignition}</b></span><span><i class="fa-solid fa-location-arrow"></i><small>Rumbo</small><b>${num(position.course)===null?'Sin dato':`${esc(compass(position.course))} · ${Math.round(num(position.course))}°`}</b></span></div>
    <div class="telemetry-mini gp-telemetry">
      <span><i class="fa-solid fa-battery-three-quarters"></i><small>Bateria</small><b>${battery===null?'Sin dato':`${Math.round(battery)}%`}</b></span>
      <span><i class="fa-solid fa-satellite"></i><small>Satelites</small><b>${satellites===null?'Sin dato':Math.round(satellites)}</b></span>
      <span><i class="fa-solid fa-signal"></i><small>Senal RSSI</small><b>${signal===null?'Sin dato':Math.round(signal)}</b></span>
      <span><i class="fa-solid fa-road"></i><small>Odometro</small><b>${distance===null?'Sin dato':`${distance.toLocaleString('es-VE')} km`}</b></span>
      <span><i class="fa-solid fa-mountain"></i><small>Altitud</small><b>${altitude===null?'Sin dato':`${altitude.toLocaleString('es-VE')} m`}</b></span>
      <span><i class="fa-solid fa-crosshairs"></i><small>Precision</small><b>${accuracy===null?'Sin dato':`±${Math.round(accuracy)} m`}</b></span>
    </div>
    <div class="production-location"><i class="fa-solid fa-location-dot"></i><span><b>${esc(position.address||'Coordenadas GPS')}</b><small>${num(position.latitude)?.toFixed(5)||'—'}, ${num(position.longitude)?.toFixed(5)||'—'} · ${esc(format(updated))}</small></span></div>
    <div class="gp-live-note ${fresh.className}"><i class="fa-solid ${fresh.className==='good'?'fa-wave-square':'fa-clock'}"></i><span><b>${esc(fresh.label)}</b><small>Ultimo paquete: ${esc(ago(updated))}. Los campos sin dato no son simulados.</small></span></div>
    <div class="detail-actions"><button onclick="navigate('historial')"><i class="fa-solid fa-route"></i> Ver recorrido</button>${commandsAvailable?`<button class="danger" ${canStop?'onclick="openDanger()"':'disabled title="Requiere velocidad 0 km/h y una lectura menor a 2 minutos"'}><i class="fa-solid fa-power-off"></i> ${canStop?'Apagado remoto':'Apagado bloqueado'}</button>`:''}</div>
  </div>`;
}

function selectDevice(device,center=true){
  selectedDeviceId=Number(device.id);
  const panel=document.getElementById('unitDetail');
  if(panel)panel.innerHTML=detailMarkup(device);
  deviceMarkers.forEach((item,id)=>item.element.classList.toggle('selected',id===selectedDeviceId));
  if(center&&map&&validPosition(device.position))map.easeTo({center:[Number(device.position.longitude),Number(device.position.latitude)],zoom:Math.max(map.getZoom(),16),duration:850});
}

function markerElement(device){
  const element=document.createElement('button');
  element.type='button';
  element.className=`gp-moto-marker ${markerState(device)}`;
  element.setAttribute('aria-label',`Abrir ${device.name||`GPS ${device.id}`}`);
  element.innerHTML=`<span class="gp-marker-ring"></span><span class="gp-marker-icon"><i class="fa-solid fa-motorcycle"></i><em></em></span><b>${esc(device.name||`GPS ${device.id}`)}</b>`;
  element.addEventListener('click',()=>{
    const latest=fleetDevices.find(item=>Number(item.id)===Number(device.id))||device;
    selectDevice(latest);
  });
  return element;
}

function addOrUpdateDevice(device){
  if(!map||!validPosition(device.position))return;
  const id=Number(device.id);
  const position=device.position;
  const point=[Number(position.longitude),Number(position.latitude)];
  let item=deviceMarkers.get(id);
  if(!item){
    const element=markerElement(device);
    const marker=new maplibregl.Marker({element,anchor:'center'}).setLngLat(point).addTo(map);
    item={marker,element};deviceMarkers.set(id,item);markers.push(marker);
    trailCoordinates.set(id,[point]);
  }else{
    const current=item.marker.getLngLat();
    if(current.lng!==point[0]||current.lat!==point[1]){
      item.marker.setLngLat(point);
      const trail=trailCoordinates.get(id)||[];
      const previous=trail[trail.length-1];
      if(!previous||previous[0]!==point[0]||previous[1]!==point[1])trail.push(point);
      if(trail.length>240)trail.shift();
      trailCoordinates.set(id,trail);
      if(id===selectedDeviceId)map.easeTo({center:point,duration:900});
    }
    item.element.classList.remove('moving','parked','offline');item.element.classList.add(markerState(device));
  }
  item.element.style.setProperty('--heading',`${num(position.course)||0}deg`);
  item.element.querySelector('b').textContent=device.name||`GPS ${id}`;
}

function trailGeoJson(){
  return {type:'FeatureCollection',features:[...trailCoordinates.entries()].filter(([,points])=>points.length>1).map(([id,points])=>({type:'Feature',properties:{id,color:id===selectedDeviceId?'#22e4cf':'#36a3ff'},geometry:{type:'LineString',coordinates:points}}))};
}

function refreshTrailLayer(){
  if(!map||!map.getSource('gp-session-trails'))return;
  map.getSource('gp-session-trails').setData(trailGeoJson());
}

function addOperationalLayers(){
  if(!map||!map.isStyleLoaded())return;
  if(!map.getSource('gp-geofences'))map.addSource('gp-geofences',{type:'geojson',data:{type:'FeatureCollection',features:geofenceFeatures}});
  if(!map.getLayer('gp-geofence-fill'))map.addLayer({id:'gp-geofence-fill',type:'fill',source:'gp-geofences',layout:{visibility:geofencesVisible?'visible':'none'},paint:{'fill-color':['coalesce',['get','color'],'#18d7c2'],'fill-opacity':.16}});
  if(!map.getLayer('gp-geofence-line'))map.addLayer({id:'gp-geofence-line',type:'line',source:'gp-geofences',layout:{visibility:geofencesVisible?'visible':'none'},paint:{'line-color':['coalesce',['get','color'],'#28e8d1'],'line-width':2.3,'line-dasharray':[2,1.5]}});
  if(!map.getSource('gp-session-trails'))map.addSource('gp-session-trails',{type:'geojson',data:trailGeoJson()});
  if(!map.getLayer('gp-trail-glow'))map.addLayer({id:'gp-trail-glow',type:'line',source:'gp-session-trails',paint:{'line-color':['get','color'],'line-width':10,'line-opacity':.18,'line-blur':5}});
  if(!map.getLayer('gp-trail-line'))map.addLayer({id:'gp-trail-line',type:'line',source:'gp-session-trails',paint:{'line-color':['get','color'],'line-width':4,'line-opacity':.92},layout:{'line-cap':'round','line-join':'round'}});
}

function circleFeature(lat,lon,radius,properties){
  const coordinates=[];const steps=64;
  for(let i=0;i<=steps;i++){
    const angle=(i/steps)*Math.PI*2;
    const dx=(radius/111320)*Math.cos(angle)/Math.max(.2,Math.cos(lat*Math.PI/180));
    const dy=(radius/110540)*Math.sin(angle);
    coordinates.push([lon+dx,lat+dy]);
  }
  return {type:'Feature',properties,geometry:{type:'Polygon',coordinates:[coordinates]}};
}

function parseArea(area,properties){
  if(typeof area!=='string')return null;
  const circle=area.match(/^CIRCLE\s*\(\s*(-?\d+(?:\.\d+)?)\s+(-?\d+(?:\.\d+)?)\s*,\s*(\d+(?:\.\d+)?)\s*\)$/i);
  if(circle)return circleFeature(Number(circle[1]),Number(circle[2]),Number(circle[3]),properties);
  const polygon=area.match(/^POLYGON\s*\(\((.+)\)\)$/i);
  if(!polygon)return null;
  const coordinates=polygon[1].split(',').map(pair=>pair.trim().split(/\s+/).map(Number)).filter(pair=>pair.length>=2&&pair.every(Number.isFinite)).map(pair=>[pair[1],pair[0]]);
  if(coordinates.length<3)return null;
  if(coordinates[0][0]!==coordinates[coordinates.length-1][0]||coordinates[0][1]!==coordinates[coordinates.length-1][1])coordinates.push([...coordinates[0]]);
  return {type:'Feature',properties,geometry:{type:'Polygon',coordinates:[coordinates]}};
}

async function loadGeofences(){
  try{
    const payload=await json(`${endpoint}?action=geofences`);
    const palette=['#21dec9','#39a4ff','#ffb23e','#ff6178','#8c7dff'];
    geofenceFeatures=(payload.geofences||[]).map((item,index)=>parseArea(item.area,{id:item.id,name:item.name||`Geocerca ${item.id}`,color:palette[index%palette.length]})).filter(Boolean);
    if(map&&map.getSource('gp-geofences'))map.getSource('gp-geofences').setData({type:'FeatureCollection',features:geofenceFeatures});
  }catch(error){notify(`Geocercas: ${error.message}`,'warn')}
}

window.gpToggleGeofences=()=>{
  geofencesVisible=!geofencesVisible;
  ['gp-geofence-fill','gp-geofence-line'].forEach(id=>{if(map&&map.getLayer(id))map.setLayoutProperty(id,'visibility',geofencesVisible?'visible':'none')});
  const button=document.getElementById('gpGeoButton');if(button)button.classList.toggle('on',geofencesVisible);
  notify(geofencesVisible?'Geocercas visibles':'Geocercas ocultas');
};

window.gpSetMapStyle=style=>{
  selectedStyle=normalizeStyle(style);
  document.querySelectorAll('[data-map-style]').forEach(button=>button.classList.toggle('active',button.dataset.mapStyle===selectedStyle));
  if(!map)return;
  map.setStyle(mapStyle(selectedStyle));
  map.once('style.load',addOperationalLayers);
  if(!mapSettings.configured)notify('Agrega tu llave MapTiler para activar Satelite Pro. Se mantiene el mapa OSM de respaldo.','warn');
};

function bindSearch(){
  const input=document.querySelector('.satellite-tools input');if(!input)return;
  input.addEventListener('input',()=>{
    const query=input.value.trim().toLowerCase();if(query.length<2)return;
    const device=fleetDevices.find(item=>`${item.name||''} ${item.uniqueId||''} ${item.model||''}`.toLowerCase().includes(query));
    if(device)selectDevice(device);
  });
}

function mapSetup(payload){
  const valid=(payload.devices||[]).filter(device=>validPosition(device.position));
  if(!valid.length)throw new Error('Traccar esta conectado, pero ningun GPS ha enviado una posicion valida.');
  mapSettings=payload.mapConfig||mapSettings;
  selectedStyle=normalizeStyle(mapSettings.defaultStyle||'hybrid');
  document.querySelectorAll('[data-map-style]').forEach(button=>button.classList.toggle('active',button.dataset.mapStyle===selectedStyle));
  const preferred=valid.find(device=>String(device.name||'').toLowerCase().includes('gp-0248'))||valid[0];
  const node=document.getElementById('map');node.innerHTML='';
  map=new maplibregl.Map({container:'map',style:mapStyle(selectedStyle),center:[Number(preferred.position.longitude),Number(preferred.position.latitude)],zoom:15.8,pitch:48,bearing:-12,maxPitch:70,attributionControl:false,antialias:true});
  map.addControl(new maplibregl.NavigationControl({visualizePitch:true}),'bottom-left');
  map.addControl(new maplibregl.ScaleControl({maxWidth:110,unit:'metric'}),'bottom-left');
  map.addControl(new maplibregl.AttributionControl({compact:true,customAttribution:'GRANDPRIX Control 360'}),'bottom-right');
  markers=[];deviceMarkers.clear();trailCoordinates.clear();
  map.on('load',()=>{
    addOperationalLayers();fleetDevices.filter(device=>validPosition(device.position)).forEach(addOrUpdateDevice);refreshTrailLayer();
    if(valid.length>1){const bounds=new maplibregl.LngLatBounds();valid.forEach(device=>bounds.extend([Number(device.position.longitude),Number(device.position.latitude)]));map.fitBounds(bounds,{padding:{top:100,bottom:100,left:80,right:80},maxZoom:16,duration:0})}
    selectDevice(preferred,false);
  });
  map.on('error',event=>{if(event&&event.error&&String(event.error.message||'').includes('401'))notify('MapTiler rechazo la llave. Revisala en el configurador.','error')});
  if(!mapSettings.configured){
    const warning=document.createElement('a');warning.className='gp-map-key-warning';warning.href='install/conectar-traccar.php';warning.innerHTML='<i class="fa-solid fa-key"></i><span><b>Activar Satelite Pro</b><small>Agregar llave MapTiler</small></span>';node.appendChild(warning);
  }
  bindSearch();loadGeofences();
}

function renderError(error){
  if(window.gpRealtimeCleanup){window.gpRealtimeCleanup();window.gpRealtimeCleanup=null}if(map){map.remove();map=null}
  const node=document.getElementById('map');
  if(node)node.innerHTML=`<div class="production-error"><div class="production-error-icon"><i class="fa-solid fa-satellite-dish"></i></div><span class="eyebrow">TRACCAR · PRODUCCION</span><h2>Sin telemetria disponible</h2><p>${esc(error.message||error)}</p><div class="production-error-actions"><button onclick="initMap()"><i class="fa-solid fa-rotate"></i> Reintentar</button><a href="install/conectar-traccar.php"><i class="fa-solid fa-sliders"></i> Configurar</a></div><small>No se muestran ubicaciones ni valores simulados.</small></div>`;
  const panel=document.getElementById('unitDetail');if(panel)panel.innerHTML='<div class="production-unit empty"><span class="eyebrow">ESTADO OPERATIVO</span><div class="production-unit-empty"><i class="fa-solid fa-link-slash"></i><h3>Esperando datos reales</h3><p>Revisa Traccar, Device ID y la ultima posicion del GPS.</p></div></div>';
}

function applyRealtimeDevice(device){
  if(!device||!Number(device.id))return;
  const index=fleetDevices.findIndex(item=>Number(item.id)===Number(device.id));
  if(index>=0)fleetDevices[index]=device;else fleetDevices.push(device);
  updateMapStatus({devices:fleetDevices});addOrUpdateDevice(device);refreshTrailLayer();
  if(Number(device.id)===selectedDeviceId)selectDevice(device,false);
}

function realtimeStatus(state){
  const box=document.querySelector('.simulator');if(!box)return;
  if(state==='connected'){
    box.innerHTML='<span class="traccar-pulse"></span><span><b>Webhook + WebSocket activos</b><small>Movimiento push · cero polling</small></span>';
    return;
  }
  if(state==='disabled'){
    box.innerHTML='<span class="status-dot warn"></span><span><b>Webhook activo · WebSocket pendiente</b><small>Sin polling · usa Actualizar ahora hasta configurar Pusher</small></span>';
    return;
  }
  box.innerHTML='<span class="status-dot warn"></span><span><b>Conectando canal WebSocket</b><small>Los últimos datos reales permanecen visibles</small></span>';
}

function connectRealtime(config){
  if(window.gpRealtimeCleanup){window.gpRealtimeCleanup();window.gpRealtimeCleanup=null}
  if(!window.GrandprixRealtime){realtimeStatus('disabled');return}
  window.gpRealtimeCleanup=window.GrandprixRealtime.connect(config,{
    status:realtimeStatus,
    position:payload=>applyRealtimeDevice(payload.device),
    event:payload=>{if(payload.device)applyRealtimeDevice(payload.device)},
    error:()=>realtimeStatus('error')
  });
}

async function refreshMap(){
  try{
    const payload=await json(`${endpoint}?action=fleet`);
    if(payload.mode!=='production'||!Array.isArray(payload.devices))throw new Error('La fuente GPS no esta en produccion.');
    if(payload.polling!==false||payload.delivery!=='webhook-websocket')throw new Error('Actualizacion incompleta: frontend y API pertenecen a versiones diferentes. Reinstala GRANDPRIX V7.2.');
    fleetDevices=payload.devices;commandsAvailable=Boolean(payload.commandsEnabled);updateMapStatus(payload);
    fleetDevices.forEach(addOrUpdateDevice);refreshTrailLayer();
    const selected=fleetDevices.find(device=>Number(device.id)===selectedDeviceId);
    if(selected)selectDevice(selected,false);
    notify('Última memoria de telemetría actualizada.');
  }catch(error){notify(error.message,'warn')}
}

window.gpRefreshSnapshot=refreshMap;

initMap=async function(){
  if(window.gpRealtimeCleanup){window.gpRealtimeCleanup();window.gpRealtimeCleanup=null}if(map){map.remove();map=null}
  const node=document.getElementById('map');if(!node)return;
  node.innerHTML='<div class="production-loading"><span class="traccar-pulse"></span><b>Conectando Satelite Pro</b><small>MapTiler + MapLibre · telemetria Traccar real</small></div>';
  try{
    const payload=await json(`${endpoint}?action=fleet`);
    if(payload.mode!=='production'||!Array.isArray(payload.devices))throw new Error('La fuente GPS no esta en produccion.');
    if(payload.polling!==false||payload.delivery!=='webhook-websocket')throw new Error('Actualizacion incompleta: frontend y API pertenecen a versiones diferentes. Reinstala GRANDPRIX V7.2.');
    fleetDevices=payload.devices;commandsAvailable=Boolean(payload.commandsEnabled);updateMapStatus(payload);mapSetup(payload);
    connectRealtime(payload.realtime);
  }catch(error){renderError(error)}
};
})();
