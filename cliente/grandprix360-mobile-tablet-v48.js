(function(){
  const QUERY='(max-width: 1024px)';
  let observer=null;

  function isActive(){
    return !!(window.matchMedia && window.matchMedia(QUERY).matches);
  }

  function formatDate(){
    try{return new Date().toLocaleDateString('es-VE',{weekday:'long',day:'numeric',month:'short',year:'numeric'});}catch(_){return ''}
  }

  function setLogo(){
    document.querySelectorAll('.brand img').forEach(img=>{
      if(!img.dataset.gp48Orig){img.dataset.gp48Orig=img.getAttribute('src')||'';}
      if(isActive()) img.setAttribute('src','assets/grandprix-logo-light.png');
      else if(img.dataset.gp48Orig) img.setAttribute('src',img.dataset.gp48Orig);
    });
  }

  function setHeaderTitle(){
    const body=document.body;
    const title=document.getElementById('pageTitle');
    const crumb=document.getElementById('breadcrumb');
    if(!title||!crumb)return;
    if(!title.dataset.gp48Orig) title.dataset.gp48Orig=title.textContent||'';
    if(!crumb.dataset.gp48Orig) crumb.dataset.gp48Orig=crumb.textContent||'';
    if(!body.classList.contains('gp48-mobile-tablet')){
      title.textContent=title.dataset.gp48Orig||title.textContent;
      crumb.textContent=crumb.dataset.gp48Orig||crumb.textContent;
      return;
    }
    const txt=(title.dataset.gp48Orig||title.textContent||'').trim();
    if(txt==='Resumen ejecutivo'){
      title.textContent='Dashboard';
      crumb.textContent='GRANDPRIX 360 / Resumen';
    }else if(txt==='Expediente 360 de motocicletas'){
      title.textContent='Expediente 360';
      crumb.textContent='GRANDPRIX 360 / Expediente';
    }else if(txt==='Pagos y conciliación'){
      title.textContent='Pagos';
      crumb.textContent='GRANDPRIX 360 / Pagos';
    }else if(txt==='Monitoreo GPS en vivo'){
      title.textContent='GPS / Vehículos';
      crumb.textContent='GRANDPRIX 360 / GPS';
    }
  }

  function ensureMobileDockLabels(){
    const nav=document.querySelector('.mobile-nav');
    if(!nav) return;
    const labels={
      resumen:['Inicio','fa-house'],
      pagos:['Pagos','fa-money-bill-wave'],
      clientes:['Clientes','fa-users'],
      motos:['Expediente','fa-address-card'],
      mapa:['Mapa','fa-map-location-dot'],
      dispositivos:['GPS','fa-sim-card'],
      historial:['Historial','fa-route']
    };
    nav.querySelectorAll('button[data-go]').forEach(btn=>{
      const key=btn.getAttribute('data-go');
      const map=labels[key];
      if(!map) return;
      const span=btn.querySelector('span');
      const icon=btn.querySelector('i');
      if(span) span.textContent=map[0];
      if(icon) icon.className='fa-solid '+map[1];
    });
  }

  function injectDashboardEnhancements(){
    const body=document.body;
    if(!body.classList.contains('workspace-admin') || !body.classList.contains('gp48-mobile-tablet')) return;
    const root=document.getElementById('v8FinanceRoot');
    if(!root) return;
    const pageTitle=(document.getElementById('pageTitle')?.dataset.gp48Orig||document.getElementById('pageTitle')?.textContent||'').trim();
    if(pageTitle!=='Resumen ejecutivo'){
      root.querySelectorAll('.gp48-mobile-hero,.gp48-actions').forEach(n=>n.remove());
      return;
    }
    if(!root.querySelector('.gp48-mobile-hero')){
      const admin=(window.GRANDPRIX&&window.GRANDPRIX.admin)||{};
      const name=(admin.name||'Admin').split(/\s+/)[0]||'Admin';
      const hero=document.createElement('section');
      hero.className='gp48-mobile-hero';
      hero.innerHTML=`<small>GRANDPRIX 360</small><h2>Hola, ${escapeHtml(name)}</h2><p>La operación en movimiento. Visualiza cartera, pagos y clientes en una sola vista optimizada para móvil y tablet.</p><div class="gp48-status"><i class="fa-solid fa-circle"></i><span>${escapeHtml(formatDate())}</span></div>`;
      root.insertBefore(hero,root.firstChild);
    }
    if(!root.querySelector('.gp48-actions')){
      const actions=document.createElement('div');
      actions.className='gp48-actions';
      actions.innerHTML=[
        ['fa-user-plus','Nuevo cliente','if(window.gpFinanceNew)gpFinanceNew()',''],
        ['fa-money-bill-wave','Registrar pago','if(window.gpQuickPayment){gpQuickPayment()}else if(window.navigate){navigate("pagos")}', 'accent'],
        ['fa-address-card','Expediente','if(window.navigate)navigate("motos")','orange'],
        ['fa-chart-column','Reportes','if(window.navigate)navigate("reportes")','purple']
      ].map(a=>`<button class="${a[3]}" onclick="${a[2]}"><i class="fa-solid ${a[0]}"></i><span>${a[1]}</span></button>`).join('');
      const kpis=root.querySelector('.v8-kpis');
      if(kpis) root.insertBefore(actions,kpis); else root.appendChild(actions);
    }
  }

  function escapeHtml(v){
    return String(v==null?'':v).replace(/[&<>"']/g,m=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[m]));
  }

  function applyMode(){
    document.body.classList.toggle('gp48-mobile-tablet',isActive());
    setLogo();
    setHeaderTitle();
    ensureMobileDockLabels();
    injectDashboardEnhancements();
  }

  function bootObserver(){
    const view=document.getElementById('view');
    if(!view || observer) return;
    observer=new MutationObserver(()=>setTimeout(applyMode,10));
    observer.observe(view,{childList:true,subtree:true});
  }

  document.addEventListener('DOMContentLoaded',()=>{
    applyMode();
    bootObserver();
    window.addEventListener('resize',applyMode,{passive:true});
    const mq=window.matchMedia?window.matchMedia(QUERY):null;
    if(mq&&mq.addEventListener)mq.addEventListener('change',applyMode);
    else if(mq&&mq.addListener)mq.addListener(applyMode);
    setTimeout(applyMode,120);
    setTimeout(applyMode,500);
  });
})();
