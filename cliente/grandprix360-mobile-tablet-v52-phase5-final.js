(function(){
  const QUERY='(max-width: 1024px)';
  const q=(s,r=document)=>r.querySelector(s);
  const qa=(s,r=document)=>Array.from(r.querySelectorAll(s));
  let ob=null;
  const esc=v=>String(v==null?'':v).replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
  const active=()=>!!(window.matchMedia && window.matchMedia(QUERY).matches);

  function pageTitle(){return (q('#pageTitle')?.dataset?.gp48Orig||q('#pageTitle')?.textContent||'').trim();}
  function key(){
    const t=pageTitle().toLowerCase();
    if(document.body.classList.contains('workspace-monitor')) return 'gps';
    if(t.includes('dashboard')||t.includes('resumen')) return 'dashboard';
    if(t.includes('clientes')) return 'clientes';
    if(t.includes('pagos')) return 'pagos';
    if(t.includes('expediente')) return 'expediente';
    if(t.includes('reportes')) return 'reportes';
    if(t.includes('alert')) return 'alertas';
    if(t.includes('auditor')) return 'auditoria';
    if(t.includes('inventario')) return 'inventario';
    if(t.includes('solicitudes')) return 'solicitudes';
    if(t.includes('sitio')) return 'sitio';
    return 'dashboard';
  }

  function meta(){
    return {
      dashboard:{eyebrow:'Centro de control',title:'Operación del día',desc:'Indicadores clave, cobranzas, progreso semanal y accesos rápidos para supervisar la cartera.',badge:'Vista táctica'},
      clientes:{eyebrow:'Gestión comercial',title:'Cartera de clientes',desc:'Consulta clientes, motos, estados y accesos administrativos con una vista más clara.',badge:'Expedientes vivos'},
      pagos:{eyebrow:'Finanzas activas',title:'Pagos y conciliación',desc:'Revisión de movimientos, abonos parciales, recibos y conciliación desde una experiencia táctil.',badge:'Cobro inteligente'},
      expediente:{eyebrow:'Ficha 360',title:'Expediente integral',desc:'Documentos, historial, cuotas, GPS y trazabilidad del cliente en un solo flujo.',badge:'Visión completa'},
      reportes:{eyebrow:'Analítica',title:'Reportes ejecutivos',desc:'Exportaciones y lecturas rápidas para seguimiento financiero, operativo y administrativo.',badge:'Listo para exportar'},
      alertas:{eyebrow:'Seguimiento',title:'Prioridades operativas',desc:'Detecta casos críticos y próximos movimientos con una lectura mucho más ordenada.',badge:'Atención inmediata'},
      auditoria:{eyebrow:'Control interno',title:'Auditoría del sistema',desc:'Eventos, accesos y descargas con trazabilidad lista para revisión administrativa.',badge:'Registro seguro'},
      inventario:{eyebrow:'Flota activa',title:'Inventario y GPS',desc:'Motocicletas, placas, equipos GPS y asignaciones con visual más limpio en tablet y móvil.',badge:'Activo en campo'},
      solicitudes:{eyebrow:'Gestión de ingreso',title:'Solicitudes',desc:'Seguimiento comercial de ingresos, aprobaciones y datos clave para la operación.',badge:'Pipeline comercial'},
      sitio:{eyebrow:'Canal digital',title:'Sitio público',desc:'Controla la presencia pública y la relación entre modelos, solicitudes y experiencia comercial.',badge:'Canal activo'},
      gps:{eyebrow:'Monitoreo',title:'Mapa y control GPS',desc:'Operación en ruta, estado de unidades y lectura en tiempo real para supervisión móvil.',badge:'Tiempo real'}
    }[key()] || {eyebrow:'GRANDPRIX 360',title:'Operación',desc:'Interfaz móvil optimizada.',badge:'Móvil / Tablet'};
  }

  function setBody(){
    document.body.classList.toggle('gp52-phase5',active());
    document.body.dataset.gp52Screen=key();
  }

  function renderSectionIntro(){
    if(!active()) return qa('.gp52-section-intro').forEach(n=>n.remove());
    const root=document.body.classList.contains('workspace-monitor')?q('main'):q('#v8FinanceRoot');
    if(!root) return;
    const screen=key();
    let anchor=null;
    const anchors={
      dashboard:['.v8-panel','.panel','.v8-kpis'],
      clientes:['.v25-filterbar','.v20-module-filters','.filterbar','.v25-summary'],
      pagos:['.v20-module-filters','.filterbar','.v17-tabs','.gp51-payment-cards','.v17-receipt-grid'],
      expediente:['.v25-summary','.v25-filterbar','.filterbar','.v25-dossier-card'],
      reportes:['.v8-report-grid','.v8-panel'],
      alertas:['.v18-alert-summary','.v18-alert-list','.v8-panel'],
      auditoria:['.v18-audit-filters','.v18-audit-kpis','.v8-panel'],
      inventario:['.filterbar','.v8-panel'],
      solicitudes:['.filterbar','.v8-panel'],
      sitio:['.v8-public-admin','.v8-public-preview','.v8-panel'],
      gps:['.map-layout','.map-panel','.unit-detail']
    };
    (anchors[screen]||[]).some(sel=>{anchor=q(sel,root); return !!anchor;});
    if(!anchor) return;
    let intro=anchor.previousElementSibling;
    if(!(intro && intro.classList.contains('gp52-section-intro'))){
      intro=document.createElement('section');
      intro.className='gp52-section-intro';
      anchor.parentNode.insertBefore(intro,anchor);
    }
    const m=meta();
    intro.innerHTML=`<div><small>${esc(m.eyebrow)}</small><h3>${esc(m.title)}</h3><p>${esc(m.desc)}</p></div><span class="badge"><i class="fa-solid fa-sparkles"></i>${esc(m.badge)}</span>`;
  }

  function enhanceEmptyStates(){
    if(!active()){
      qa('.gp52-empty').forEach(node=>{
        if(node.dataset.gp52Wrapped==='1'){ const parent=node.parentNode; if(parent) parent.removeChild(node); }
      });
      return;
    }
    qa('.v8-empty').forEach(box=>{
      if(box.classList.contains('gp52-empty')) return;
      const txt=(box.textContent||'').trim()||'No hay información disponible';
      box.classList.add('gp52-empty');
      box.innerHTML=`<i class="fa-solid fa-inbox"></i><b>${esc(txt)}</b><span>Cuando existan registros, movimientos o resultados aparecerán aquí con el nuevo diseño optimizado para móvil y tablet.</span>`;
    });
  }

  function tuneActionLabels(){
    if(!active()) return;
    qa('.gp48-actions button, .gp50-shortcuts button').forEach(btn=>{
      btn.setAttribute('type','button');
      const span=q('span',btn);
      if(span && span.textContent.trim().length>18) span.textContent=span.textContent.trim().replace('Registrar ','');
    });
    qa('.mobile-nav button').forEach(btn=>{
      btn.setAttribute('type','button');
    });
  }

  function apply(){
    setBody();
    renderSectionIntro();
    enhanceEmptyStates();
    tuneActionLabels();
  }

  function observe(){
    const root=q('#view')||document.body;
    if(ob||!root) return;
    ob=new MutationObserver(()=>setTimeout(apply,35));
    ob.observe(root,{childList:true,subtree:true});
  }

  document.addEventListener('DOMContentLoaded',()=>{
    apply(); observe();
    window.addEventListener('resize',apply,{passive:true});
    const mq=window.matchMedia?window.matchMedia(QUERY):null;
    if(mq&&mq.addEventListener) mq.addEventListener('change',apply); else if(mq&&mq.addListener) mq.addListener(apply);
    setTimeout(apply,120); setTimeout(apply,480); setTimeout(apply,1100);
  });
})();
