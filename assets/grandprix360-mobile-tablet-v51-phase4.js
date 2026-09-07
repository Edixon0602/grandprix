(function(){
  const QUERY='(max-width: 1024px)';
  const q=(s,r=document)=>r.querySelector(s);
  const qa=(s,r=document)=>Array.from(r.querySelectorAll(s));
  let ob=null;
  const esc=v=>String(v==null?'':v).replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
  const active=()=>!!(window.matchMedia&&window.matchMedia(QUERY).matches);
  const getNum=text=>{const m=String(text||'').replace(/\./g,'').match(/-?\d+(?:,\d+)?/);return m?m[0]:text||'0'};
  function pageTitle(){return (q('#pageTitle')?.dataset?.gp48Orig || q('#pageTitle')?.textContent || '').trim();}
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
  function addBody(){ if(active()) document.body.classList.add('gp51-phase4'); else document.body.classList.remove('gp51-phase4'); }

  function heroData(){
    const map={
      dashboard:{icon:'fa-chart-line',tag:'Operación 360',title:'Dashboard táctico',desc:'Visión rápida de cartera, cobranzas, mora, progreso semanal y accesos clave para supervisar GRANDPRIX 360 desde móvil y tablet.'},
      clientes:{icon:'fa-users',tag:'Clientes y cartera',title:'Expedientes en movimiento',desc:'Administra clientes, motocicletas, estados de pago, GPS y acciones rápidas con una interfaz pensada para lectura ágil.'},
      pagos:{icon:'fa-money-check-dollar',tag:'Pagos y conciliación',title:'Cobro inteligente',desc:'Registra pagos, revisa conciliaciones, distribuye abonos parciales y controla recibos de forma clara desde cualquier dispositivo.'},
      expediente:{icon:'fa-address-card',tag:'Expediente 360',title:'Ficha integral del cliente',desc:'Consulta perfil, documentos, créditos, historial y estado operativo del expediente con navegación táctil mejorada.'},
      reportes:{icon:'fa-file-lines',tag:'Reportes',title:'Exportación y control',desc:'Descarga reportes estratégicos y accede a resúmenes útiles para seguimiento financiero y administrativo.'},
      alertas:{icon:'fa-bell',tag:'Centro de alertas',title:'Seguimiento prioritario',desc:'Ubica clientes críticos, pagos por conciliar y eventos de atención inmediata con jerarquía visual más clara.'},
      auditoria:{icon:'fa-fingerprint',tag:'Auditoría',title:'Trazabilidad total',desc:'Supervisa accesos, cambios y descargas del sistema con lectura optimizada para móvil y tablet.'},
      inventario:{icon:'fa-motorcycle',tag:'Inventario',title:'Motocicletas y GPS',desc:'Administra unidades, placas, asignaciones y sincronización GPS con foco táctil y lectura compacta.'},
      solicitudes:{icon:'fa-file-signature',tag:'Solicitudes',title:'Pipeline comercial',desc:'Revisa solicitudes y acciones comerciales con una capa visual más ordenada para seguimiento administrativo.'},
      sitio:{icon:'fa-globe',tag:'Sitio público',title:'Canal comercial',desc:'Controla la experiencia pública, los modelos visibles y el enlace entre solicitudes y administración.'},
      gps:{icon:'fa-satellite-dish',tag:'Monitoreo GPS',title:'Flota en tiempo real',desc:'Mapa, alertas, estado de vehículos y operación en movimiento en una sola vista optimizada para supervisión móvil.'}
    };
    return map[key()] || map.dashboard;
  }
  function metricCandidates(){
    const list=[];
    if(window.state?.finance?.metrics){
      const m=window.state.finance.metrics;
      list.push(['Cartera',m.total_records??m.active_accounts??'—','registros']);
      list.push(['Al día',m.current_accounts??m.current??'—','sin mora']);
      list.push(['En mora',m.late_accounts??m.late??'—','seguimiento']);
      list.push(['Completados',m.completed_accounts??m.completed??'—','cierres']);
      list.push(['Pagos',m.total_payments??'—','movimientos']);
      list.push(['GPS',m.gps_assigned??'—','asignados']);
    }
    const cards=qa('.kpi-card, .v8-kpi, .v18-alert-kpis article, .v17-compact-kpis article');
    cards.forEach(card=>{
      const small=card.querySelector('small')?.textContent?.trim();
      const strong=card.querySelector('b,strong')?.textContent?.trim();
      const sub=card.querySelector('em,span')?.textContent?.trim();
      if(small&&strong) list.push([small,strong,sub||'']);
    });
    return list.slice(0,6);
  }
  function renderHero(){
    if(!active()) return qa('.gp51-module-hero').forEach(n=>n.remove());
    const root=document.body.classList.contains('workspace-monitor')?q('main'):q('#v8FinanceRoot');
    if(!root) return;
    const data=heroData();
    let hero=q('.gp51-module-hero',root);
    if(!hero){ hero=document.createElement('section'); hero.className='gp51-module-hero'; root.prepend(hero); }
    const stats=metricCandidates();
    hero.innerHTML=`<div class="top"><div><span class="tag"><i class="fa-solid ${data.icon}"></i>${esc(data.tag)}</span><h2>${esc(data.title)}</h2><p>${esc(data.desc)}</p></div></div><div class="gp51-hero-stats">${(stats.length?stats:[['Cartera','94','expedientes'],['Al día','22','cuentas'],['En mora','72','clientes'],['GPS','99','asignados'],['Alertas','12','activas'],['Cuotas','370','vencidas']]).map(([a,b,c])=>`<article><small>${esc(a)}</small><b>${esc(getNum(b))}</b><span>${esc(c)}</span></article>`).join('')}</div>`;
  }

  function convertPaymentsTable(){
    if(!active()) return cleanup('.gp51-payment-cards','.gp51-hide-original-mobile');
    const table=q('.v17-payment-table'); if(!table) return;
    const wrap=table.closest('.v8-table-wrap'); if(!wrap) return;
    wrap.classList.add('gp51-hide-original-mobile');
    let cards=wrap.nextElementSibling;
    if(!(cards&&cards.classList.contains('gp51-payment-cards'))){ cards=document.createElement('div'); cards.className='gp51-payment-cards'; wrap.insertAdjacentElement('afterend',cards); }
    const rows=qa('tbody tr',table);
    cards.innerHTML=rows.map(row=>{
      const td=qa('td',row);
      const name=td[1]?.querySelector('b')?.textContent?.trim()||'Cliente';
      const moto=td[1]?.querySelector('small')?.textContent?.trim()||'Sin moto';
      const amount=td[2]?.querySelector('b')?.textContent?.trim()||'—';
      const equiv=td[2]?.querySelector('small')?.textContent?.trim()||'';
      const dist=td[3]?.innerHTML||'<span class="v8-pill gray">Sin distribución</span>';
      const method=td[4]?.childNodes?.[0]?.textContent?.trim()||td[4]?.textContent?.trim()||'—';
      const ref=td[4]?.querySelector('small')?.textContent?.trim()||'Sin referencia';
      const status=td[5]?.innerHTML||'';
      const receipt=td[6]?.innerHTML||'';
      const actions=td[7]?.innerHTML||'';
      const when=td[0]?.querySelector('b')?.textContent?.trim()||'—';
      const user=td[0]?.querySelector('small')?.textContent?.trim()||'';
      return `<article class="gp51-payment-card"><div class="gp51-payment-head"><div><b>${esc(name)}</b><small>${esc(moto)} · ${esc(when)}${user?` · ${esc(user)}`:''}</small></div><div class="gp51-payment-amount"><small>Monto</small><b>${esc(amount)}</b>${equiv?`<small>${esc(equiv)}</small>`:''}</div></div><div class="gp51-payment-distribution">${dist}</div><div class="gp51-payment-meta"><article><small>Método</small><b>${esc(method)}</b></article><article><small>Referencia</small><b>${esc(ref)}</b></article><article><small>Estado</small><b>${status}</b></article><article><small>Recibo</small><b>${receipt||'Pendiente'}</b></article></div>${actions?`<div class="gp51-payment-actions">${actions}</div>`:''}</article>`;
    }).join('') || '<div class="v8-empty">No hay movimientos para mostrar.</div>';
  }

  function cleanup(cardSel,hiddenClass){ qa(cardSel).forEach(n=>n.remove()); const c=hiddenClass.replace('.',''); qa('.'+c).forEach(n=>n.classList.remove(c)); }

  function polishRootClasses(){
    const k=key();
    document.body.dataset.gp51Page=k;
    const financeRoot=q('#v8FinanceRoot'); if(financeRoot) financeRoot.dataset.gp51Page=k;
  }

  function apply(){
    addBody(); polishRootClasses(); renderHero(); convertPaymentsTable();
  }
  function observe(){
    const root=q('#view')||document.body; if(ob||!root) return;
    ob=new MutationObserver(()=>setTimeout(apply,25)); ob.observe(root,{childList:true,subtree:true});
  }
  document.addEventListener('DOMContentLoaded',()=>{
    apply(); observe();
    window.addEventListener('resize',apply,{passive:true});
    const mq=window.matchMedia?window.matchMedia(QUERY):null;
    if(mq&&mq.addEventListener) mq.addEventListener('change',apply); else if(mq&&mq.addListener) mq.addListener(apply);
    setTimeout(apply,120); setTimeout(apply,520); setTimeout(apply,1200);
  });
})();
