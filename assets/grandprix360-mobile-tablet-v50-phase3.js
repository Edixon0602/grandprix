(function(){
  const QUERY='(max-width: 1024px)';
  const q=(s,r=document)=>r.querySelector(s);
  const qa=(s,r=document)=>Array.from(r.querySelectorAll(s));
  let observer=null;
  function active(){return !!(window.matchMedia&&window.matchMedia(QUERY).matches)}
  function esc(v){return String(v==null?'':v).replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]))}
  function pageTitle(){ return (q('#pageTitle')?.dataset?.gp48Orig || q('#pageTitle')?.textContent || '').trim(); }
  function pageKey(){
    const t=pageTitle().toLowerCase();
    if(t.includes('dashboard')||t.includes('resumen ejecutivo')) return 'dashboard';
    if(t.includes('clientes')) return 'clientes';
    if(t.includes('pagos')) return 'pagos';
    if(t.includes('expediente')) return 'expediente';
    if(t.includes('reportes')) return 'reportes';
    if(t.includes('auditor')) return 'auditoria';
    if(t.includes('cobranza')) return 'cobranza';
    if(document.body.classList.contains('workspace-monitor')) return 'gps';
    return 'dashboard';
  }
  function addBodyClass(){ if(active()) document.body.classList.add('gp50-phase3'); else document.body.classList.remove('gp50-phase3'); }

  function injectShortcuts(){
    if(!active() || document.body.classList.contains('workspace-monitor')) return removeShortcuts();
    const root=q('#v8FinanceRoot'); if(!root) return;
    let holder=q('.gp50-shortcuts',root);
    const items=[
      ['dashboard','fa-chart-pie','Inicio'],['clientes','fa-users','Clientes'],['pagos','fa-money-check-dollar','Pagos'],['motos','fa-address-card','Expediente'],['cobranza','fa-hand-holding-dollar','Cobranza'],['reportes','fa-file-lines','Reportes']
    ];
    if(!holder){ holder=document.createElement('div'); holder.className='gp50-shortcuts';
      const anchor=q('.gp49-page-hero',root)||q('.gp48-mobile-hero',root)||root.firstElementChild; if(anchor) anchor.insertAdjacentElement('afterend',holder); else root.prepend(holder);
    }
    holder.innerHTML=items.map(([key,icon,label])=>`<button type="button" onclick="${key==='dashboard'?'navigate(\'resumen\')':`navigate('${key}')`}" aria-label="${esc(label)}"><i class="fa-solid ${icon}"></i><span>${esc(label)}</span></button>`).join('');
  }
  function removeShortcuts(){ qa('.gp50-shortcuts').forEach(n=>n.remove()) }

  function text(node,sel){ return node.querySelector(sel)?.textContent?.trim()||'' }
  function html(node,sel){ return node.querySelector(sel)?.innerHTML||'' }

  function convertPortfolioTables(){
    if(!active()) return removeConverted('.gp50-account-cards','.gp50-hide-original-mobile');
    const root=q('#v8FinanceRoot'); if(!root) return;
    qa('.v8-table.portfolio',root).forEach(table=>{
      const wrap=table.closest('.v8-table-wrap'); if(!wrap) return;
      wrap.classList.add('gp50-hide-original-mobile');
      let cards=wrap.nextElementSibling;
      if(!(cards&&cards.classList.contains('gp50-account-cards'))){ cards=document.createElement('div'); cards.className='gp50-account-cards'; wrap.insertAdjacentElement('afterend',cards); }
      const rows=qa('tbody tr',table);
      cards.innerHTML=rows.map(row=>{
        const aId=row.getAttribute('data-account-id')||'';
        const clientName=text(row,'td:nth-child(2) b');
        const clientSub=text(row,'td:nth-child(2) small');
        const moto=text(row,'td:nth-child(3) b');
        const motoSub=text(row,'td:nth-child(3) small');
        const img=row.querySelector('td:nth-child(3) img')?.getAttribute('src')||'assets/moto-blue.png';
        const plate=text(row,'td:nth-child(4) b')||text(row,'td:nth-child(4)');
        const paid=text(row,'td:nth-child(5) b');
        const prog=row.querySelector('td:nth-child(5) .v8-progress i')?.style.width||'0%';
        const late=text(row,'td:nth-child(6)');
        const future=text(row,'td:nth-child(7)');
        const advance=text(row,'td:nth-child(8)');
        const refers=text(row,'td:nth-child(9)');
        const gps=html(row,'td:nth-child(10)');
        const status=html(row,'td:nth-child(11)');
        const actionCell=row.querySelector('td:last-child');
        const actions=[];
        actions.push(`<button type="button" class="primary" onclick="gpAccountStatement(${Number(aId||0)})"><i class="fa-solid fa-address-card"></i> Expediente</button>`);
        if(actionCell?.querySelector('button[title="Editar"]')) actions.push(`<button type="button" onclick="gpFinanceEdit(${Number(aId||0)})"><i class="fa-solid fa-pen"></i> Editar</button>`);
        if(actionCell?.querySelector('button[title="Registrar pago"]')) actions.push(`<button type="button" onclick="gpPaymentNew(${Number(aId||0)})"><i class="fa-solid fa-money-bill-wave"></i> Pago</button>`);
        const initials=(clientName||'GP').split(/\s+/).slice(0,2).map(x=>x[0]||'').join('').toUpperCase();
        return `<article class="gp50-account-card"><div class="gp50-card-head"><div class="who"><span class="gp50-avatar">${esc(initials)}</span><span><b>${esc(clientName)}</b><small>${esc(clientSub)}</small></span></div><div class="gp50-moto-thumb"><img src="${esc(img)}" alt=""></div></div><div class="gp50-meta-grid"><article><small>Motocicleta</small><b>${esc(moto||'Sin modelo')}<br>${esc(motoSub||'')}</b></article><article><small>Placa</small><b>${esc(plate||'—')}</b></article><article><small>Pagadas / mora</small><b>${esc(paid||'0/50')} · ${esc(late||'0')} mora</b></article><article><small>Futuras / abono</small><b>${esc(future||'0')} · ${esc(advance||'—')}</b></article><article><small>Refiere</small><b>${esc(refers||'—')}</b></article><article><small>GPS</small><b>${gps}</b></article></div><div class="gp50-progress"><div class="bar"><i style="width:${esc(prog)}"></i></div><b>${esc(paid||'0/50')}</b></div><div class="gp50-card-foot"><div class="v8-pill-row">${status}</div><div class="gp50-actions">${actions.join('')}</div></div></article>`;
      }).join('') || '<div class="v8-empty">Sin registros para mostrar.</div>';
    });
  }

  function convertAuditTable(){
    if(!active()) return removeConverted('.gp50-audit-cards','.gp50-hide-original-mobile');
    const root=q('#v8AuditRoot'); if(!root) return;
    qa('.v18-audit-table',root).forEach(table=>{
      const wrap=table.closest('.v8-table-wrap'); if(!wrap) return;
      wrap.classList.add('gp50-hide-original-mobile');
      let cards=wrap.nextElementSibling;
      if(!(cards&&cards.classList.contains('gp50-audit-cards'))){ cards=document.createElement('div'); cards.className='gp50-audit-cards'; wrap.insertAdjacentElement('afterend',cards); }
      const rows=qa('tbody tr',table);
      cards.innerHTML=rows.map(row=>{
        const td=qa('td',row);
        return `<article class="gp50-audit-card"><div class="top"><span><b>${esc(td[4]?.textContent?.trim()||'Evento')}</b><small>${esc(td[0]?.textContent?.trim()||'Hora Venezuela')}</small></span><span class="v8-pill gray">${esc(td[2]?.textContent?.trim()||'Tipo')}</span></div><div class="gp50-audit-grid"><article><small>Usuario</small><b>${esc(td[1]?.textContent?.trim()||'—')}</b></article><article><small>Módulo</small><b>${esc(td[3]?.textContent?.trim()||'—')}</b></article><article><small>Entidad</small><b>${esc(td[5]?.textContent?.trim()||'—')}</b></article><article><small>IP / dispositivo</small><b>${esc(td[6]?.textContent?.trim()||'—')}</b></article></div><div class="gp50-audit-note">${esc(td[7]?.textContent?.trim()||'Sin resumen')}</div></article>`;
      }).join('') || '<div class="v8-empty">Sin eventos para mostrar.</div>';
    });
  }
  function removeConverted(cardSel,hiddenClass){ qa(cardSel).forEach(n=>n.remove()); qa('.'+hiddenClass.replace('.','')).forEach(n=>n.classList.remove(hiddenClass.replace('.',''))); }

  function enhanceMonitor(){
    if(!active() || !document.body.classList.contains('workspace-monitor')) return qa('.gp50-monitor-hero').forEach(n=>n.remove());
    const main=q('main'); if(!main) return;
    let hero=q('.gp50-monitor-hero',main);
    const kpis=[];
    qa('.v8-kpi strong, .kpi strong, .panel strong',main).slice(0,6).forEach((n,i)=>{
      const value=n.textContent.trim(); const label=n.parentElement?.querySelector('small,span')?.textContent?.trim() || ['Vehículos','En ruta','Alertas','GPS','Operación','Cobertura'][i] || 'Indicador';
      if(value) kpis.push({label,value,sub:'Tiempo real'});
    });
    if(!hero){ hero=document.createElement('section'); hero.className='gp50-monitor-hero'; main.prepend(hero); }
    hero.innerHTML=`<div class="gp49-kicker"><i class="fa-solid fa-satellite-dish"></i><span>Monitoreo táctico</span></div><h3>GPS en vivo</h3><p>Visualiza la operación en una sola vista, optimizada para supervisión rápida desde móvil y tablet.</p><div class="gp50-monitor-kpis">${(kpis.length?kpis:[{label:'Vehículos',value:'99',sub:'Operativos'},{label:'En línea',value:'87',sub:'Tiempo real'},{label:'Alertas',value:'12',sub:'Revisar'},{label:'Disponibles',value:'18',sub:'Listas'},{label:'Críticas',value:'3',sub:'Prioridad'},{label:'Cobertura',value:'100%',sub:'Flota'}]).map(k=>`<article><small>${esc(k.label)}</small><b>${esc(k.value)}</b><span>${esc(k.sub)}</span></article>`).join('')}</div>`;
  }

  function apply(){
    addBodyClass();
    injectShortcuts();
    convertPortfolioTables();
    convertAuditTable();
    enhanceMonitor();
  }

  function observe(){
    const root=q('#view') || document.body; if(observer||!root) return;
    observer=new MutationObserver(()=>setTimeout(apply,15));
    observer.observe(root,{childList:true,subtree:true});
  }

  document.addEventListener('DOMContentLoaded',()=>{
    apply(); observe();
    window.addEventListener('resize',apply,{passive:true});
    const mq=window.matchMedia?window.matchMedia(QUERY):null;
    if(mq&&mq.addEventListener) mq.addEventListener('change',apply); else if(mq&&mq.addListener) mq.addListener(apply);
    setTimeout(apply,100); setTimeout(apply,500); setTimeout(apply,1100);
  });
})();
