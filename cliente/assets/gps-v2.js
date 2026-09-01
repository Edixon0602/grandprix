views.gps=()=>`<div class="page gps-layout">
  <section class="card map-card">
    <div id="map" class="map"></div>
    <div class="map-label"><b id="gpsLocation">Conectando con Traccar</b><small id="gpsLocationUpdated">Esperando una posición real</small></div>
    <div class="map-live" id="gpsDataMode"><i class="fa-solid fa-satellite-dish"></i> CONECTANDO</div>
    <div class="gp-style-switch customer-map-switch" aria-label="Estilo de mapa"><button class="active" data-customer-map-style="hybrid" onclick="customerSetMapStyle('hybrid')"><i class="fa-solid fa-satellite"></i><span>Satélite</span></button><button data-customer-map-style="streets-v4" onclick="customerSetMapStyle('streets-v4')"><i class="fa-solid fa-road"></i><span>Calles</span></button><button data-customer-map-style="dataviz-dark" onclick="customerSetMapStyle('dataviz-dark')"><i class="fa-solid fa-moon"></i><span>Noche</span></button></div>
  </section>
  <aside class="card gps-side">
    <div class="gps-identity"><div><h2 id="gpsDeviceName">${data.moto}</h2><small id="gpsDeviceMeta">${data.model} · ${data.plate}</small></div><span class="online" id="gpsOnline">● CONECTANDO</span></div>
    <div class="gps-moto"><img src="../assets/moto-blue.png" alt="${data.model}"></div>
    <div class="speedometer">
      <div class="gauge" id="gauge"><div class="gauge-value"><strong id="gpsSpeed">—</strong> <small>km/h</small></div></div>
      <div class="speed-meta">
        <span><small>Máxima sesión</small><b id="gpsMaxSpeed">Sin dato</b></span>
        <span><small>Promedio sesión</small><b id="gpsAvgSpeed">Sin dato</b></span>
        <span><small>Ignición</small><b id="gpsIgnition">Sin dato</b></span>
        <span><small>Dirección</small><b id="gpsCourse">—</b></span>
      </div>
    </div>
    <div class="telemetry">
      <span><i class="fa-solid fa-battery-three-quarters"></i><div><small>Batería GPS</small><b id="gpsBattery">Sin dato</b></div></span>
      <span><i class="fa-solid fa-signal"></i><div><small>Señal</small><b id="gpsSignal">Conectando</b></div></span>
      <span><i class="fa-solid fa-satellite"></i><div><small>Satélites</small><b id="gpsSatellites">Sin dato</b></div></span>
      <span><i class="fa-solid fa-road"></i><div><small>Distancia total</small><b id="gpsDistance">Sin dato</b></div></span>
      <span><i class="fa-solid fa-mountain"></i><div><small>Altitud</small><b id="gpsAltitude">Sin dato</b></div></span>
      <span><i class="fa-solid fa-crosshairs"></i><div><small>Precisión</small><b id="gpsAccuracy">Sin dato</b></div></span>
      <span><i class="fa-solid fa-clock"></i><div><small>Última señal</small><b id="gpsLast">Sin reporte</b></div></span>
    </div>
    <div class="credit-mini"><div><b>Progreso del crédito</b><span>${dashboard.contract.paidWeeks} de ${dashboard.contract.totalWeeks} semanas</span></div><div class="progress"><i style="width:${dashboard.contract.progress}%"></i></div></div>
    <div class="signal"><b id="gpsStatus">● Conectando al GPS</b><span id="gpsRefresh">Datos exclusivos de Traccar</span></div>
    <div class="privacy"><i class="fa-solid fa-lock"></i> Solo puedes visualizar la motocicleta asignada a tu contrato.</div>
    <div class="gps-actions"><button class="light" onclick="go('semanas')"><i class="fa-solid fa-calendar-check"></i> Ver semanas</button><button class="light" onclick="go('moto')"><i class="fa-solid fa-motorcycle"></i> Ver detalles</button><button class="primary" onclick="go('reportar')"><i class="fa-solid fa-money-bill-transfer"></i> Reportar pago</button></div>
  </aside>
</div>`;

initMap=function(){const node=$('#map');if(node)node.innerHTML='<div class="customer-gps-loading"><span></span><b>Cargando integración GPS</b><small>Se requieren datos reales de Traccar.</small></div>'};
