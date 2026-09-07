(function(){
  const QUERY='(max-width: 1024px)';
  const q=(s,r=document)=>r.querySelector(s);
  const qa=(s,r=document)=>Array.from(r.querySelectorAll(s));
  let observer=null;

  function active(){ return !!(window.matchMedia && window.matchMedia(QUERY).matches); }
  function esc(v){ return String(v==null?'':v).replace(/[&<>"']/g,m=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[m])); }
  function currentTitle(){
    const t=q('#pageTitle');
    return (t?.dataset?.gp48Orig || t?.textContent || '').trim();
  }
  function heroConfig(){
    const title=currentTitle();
    if(title==='Pagos y conciliación') return {kicker:'Conciliación móvil', title:'Comprobantes y pagos', text:'Controla ingresos, distribución por cuotas, abonos parciales y recibos conciliados con una vista más clara para operación en campo.'};
    if(title==='Expediente 360 de motocicletas') return {kicker:'Centro documental', title:'Expediente 360', text:'Consulta fichas, documentos, GPS, pagos y estatus del cliente en un formato visual más ejecutivo para móvil y tablet.'};
    if(title==='Monitoreo GPS en vivo') return {kicker:'Control en tiempo real', title:'GPS / Vehículos', text:'Supervisa las unidades en movimiento, valida disponibilidad y mantén el monitoreo de la flota desde cualquier dispositivo.'};
    return {kicker:'Operación inteligente', title:'Dashboard', text:'Una vista más limpia y premium para cartera, mora, completados y resumen financiero en GRANDPRIX 360.'};
  }
  function collectStats(){
    const cards=qa('.v8-kpis .v8-kpi, .kpis .kpi, .v30-analytics-kpis > article, .v25-summary > article').slice(0,4);
    return cards.map(card=>{
      const label=card.querySelector('small, .label, span')?.textContent?.trim()||'Indicador';
      const value=card.querySelector('strong, b')?.textContent?.trim()||'—';
      const sub=card.querySelector('span, small:last-child')?.textContent?.trim()||'';
      return {label,value,sub};
    }).filter(x=>x.value && x.value!=='—');
  }
  function mountHero(){
    if(!active()) return destroyHero();
    document.body.classList.add('gp49-phase2');
    const body=document.body;
    let mount=q('#v8FinanceRoot');
    if(body.classList.contains('workspace-monitor')) mount=q('main');
    if(!mount) return;
    const cfg=heroConfig();
    let hero=q('.gp49-page-hero', mount);
    const stats=collectStats();
    const statsHtml=stats.length?`<div class="gp49-hero-stats">${stats.map(s=>`<article><small>${esc(s.label)}</small><b>${esc(s.value)}</b><span>${esc(s.sub)}</span></article>`).join('')}</div>`:'';
    const html=`<div class="gp49-kicker"><i class="fa-solid fa-sparkles"></i><span>${esc(cfg.kicker)}</span></div><h3>${esc(cfg.title)}</h3><p>${esc(cfg.text)}</p>${statsHtml}`;
    if(!hero){
      hero=document.createElement('section');
      hero.className='gp49-page-hero';
      if(q('.gp48-mobile-hero', mount)) q('.gp48-mobile-hero', mount).insertAdjacentElement('afterend', hero);
      else mount.insertAdjacentElement('afterbegin', hero);
    }
    hero.innerHTML=html;
  }
  function destroyHero(){ qa('.gp49-page-hero').forEach(n=>n.remove()); document.body.classList.remove('gp49-phase2'); }

  function makePaymentCards(){
    if(!active()) return removePaymentCards();
    const title=currentTitle();
    const root=q('#v8FinanceRoot');
    if(!root) return;
    const table=q('.v8-table', root);
    if(!table) return removePaymentCards(root);
    const headings=qa('thead th',table).map(th=>th.textContent.trim().toUpperCase());
    if(!(title==='Pagos y conciliación' || headings.includes('BANCO / REFERENCIA'))) return removePaymentCards(root);
    const wrap=table.closest('.v8-table-wrap');
    if(wrap) wrap.classList.add('gp49-hide-table-mobile');
    let cards=q('.gp49-payment-cards', root);
    const rows=qa('tbody tr',table);
    if(!rows.length) return;
    const idx={
      fecha:headings.findIndex(t=>t.includes('FECHA')),
      cliente:headings.findIndex(t=>t.includes('CLIENTE')),
      monto:headings.findIndex(t=>t.includes('MONTO')),
      banco:headings.findIndex(t=>t.includes('BANCO')),
      cuotas:headings.findIndex(t=>t.includes('CUOTAS')),
      mora:headings.findIndex(t=>t.includes('MORA')),
      estado:headings.findIndex(t=>t.includes('ESTADO')),
      operador:headings.findIndex(t=>t.includes('OPERADOR')),
      acciones:headings.length-1
    };
    const content=rows.map(row=>{
      const cells=qa('td',row);
      const fecha=cells[idx.fecha]?.innerHTML||'—';
      const clienteHtml=cells[idx.cliente]?.innerHTML||'—';
      const amount=cells[idx.monto]?.innerHTML||'—';
      const bank=cells[idx.banco]?.innerHTML||'—';
      const cuotas=cells[idx.cuotas]?.innerHTML||'';
      const mora=cells[idx.mora]?.textContent?.trim()||'0';
      const estado=cells[idx.estado]?.innerHTML||'';
      const operador=cells[idx.operador]?.textContent?.trim()||'—';
      const acciones=cells[idx.acciones]?.innerHTML||'';
      return `<article class="gp49-payment-card"><div class="gp49-payment-top"><div><b>${clienteHtml}</b><small>${fecha}</small></div><div class="gp49-money"><strong>${amount.replace(/<br\s*\/?>[\s\S]*/i,'')}</strong><em>${strip(amount).split(/\s+/).pop()||''}</em></div></div><div class="gp49-payment-meta"><article><small>Banco / referencia</small><b>${bank}</b></article><article><small>Mora reducida</small><b>${esc(mora)} cuota(s)</b></article><article><small>Operador</small><b>${esc(operador)}</b></article><article><small>Estatus</small><b>${estado}</b></article></div><div class="gp49-payment-dist">${cuotas}</div><div class="gp49-payment-status"><div class="v8-inline">${estado}</div><div class="gp49-payment-actions">${acciones}</div></div></article>`;
    }).join('');
    if(!cards){ cards=document.createElement('div'); cards.className='gp49-payment-cards'; wrap?.insertAdjacentElement('afterend',cards); }
    cards.innerHTML=`<div class="gp49-section-title"><b>Movimientos recientes</b><span>${rows.length} registros</span></div>${content}`;
  }
  function removePaymentCards(scope=document){
    qa('.gp49-payment-cards',scope).forEach(n=>n.remove());
    qa('.gp49-hide-table-mobile',scope).forEach(n=>n.classList.remove('gp49-hide-table-mobile'));
  }
  function strip(html){ const d=document.createElement('div'); d.innerHTML=html; return (d.textContent||'').trim(); }

  function retuneExpedienteLabels(){
    if(!active()) return;
    if(currentTitle()!=='Expediente 360 de motocicletas') return;
    const panel=q('.v25-exec-panel'); if(!panel) return;
    let title=q('.gp49-section-title',panel);
    if(!title){
      title=document.createElement('div'); title.className='gp49-section-title';
      const target=q('.v25-filterbar',panel); if(target) panel.insertBefore(title,target);
    }
    const total=qa('.v25-exec-row',panel).length;
    title.innerHTML=`<b>Lista de expedientes</b><span>${total} visibles</span>`;
  }

  function retuneDashboardSectionTitles(){
    if(!active()) return;
    qa('.v8-panel .v8-panel-head, .v30-analytics-panel .v30-analytics-head, .v25-exec-panel .v25-exec-head').forEach(head=>{
      if(head.querySelector('.gp49-section-title')) return;
    });
  }

  function apply(){
    if(active()) document.body.classList.add('gp49-phase2'); else document.body.classList.remove('gp49-phase2');
    mountHero();
    makePaymentCards();
    retuneExpedienteLabels();
    retuneDashboardSectionTitles();
  }
  function observe(){
    const view=q('#view');
    if(!view || observer) return;
    observer=new MutationObserver(()=>setTimeout(apply,10));
    observer.observe(view,{childList:true,subtree:true});
  }
  document.addEventListener('DOMContentLoaded',()=>{
    apply();
    observe();
    window.addEventListener('resize',apply,{passive:true});
    const mq=window.matchMedia?window.matchMedia(QUERY):null;
    if(mq&&mq.addEventListener) mq.addEventListener('change',apply); else if(mq&&mq.addListener) mq.addListener(apply);
    setTimeout(apply,120); setTimeout(apply,600);
  });
})();
