(function(){
  'use strict';
  const MQ='(max-width:1024px)';
  const q=(s,r=document)=>r.querySelector(s);
  const qa=(s,r=document)=>Array.from(r.querySelectorAll(s));
  let observer=null;
  let scheduled=false;

  function active(){return !!(window.matchMedia&&window.matchMedia(MQ).matches)}
  function esc(v){return String(v==null?'':v).replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]))}
  function text(el){return el?String(el.textContent||'').trim():''}
  function initials(name){return String(name||'GP').trim().split(/\s+/).slice(0,2).map(x=>x[0]||'').join('').toUpperCase()||'GP'}

  function screenKey(){
    if(document.body.classList.contains('workspace-monitor')) return 'gps';
    const title=text(q('#pageTitle')).toLowerCase();
    if(title.includes('solicitud')) return 'solicitudes';
    if(title.includes('cliente')) return 'clientes';
    if(title.includes('cobran')) return 'cobranza';
    if(title.includes('pago')) return 'pagos';
    if(title.includes('expediente')) return 'expediente';
    if(title.includes('auditor')) return 'auditoria';
    if(title.includes('alerta')) return 'alertas';
    if(title.includes('reporte')) return 'reportes';
    if(title.includes('inventario')) return 'inventario';
    return 'inicio';
  }

  function setMode(){
    document.body.classList.toggle('gp53-mobile',active());
    document.body.dataset.gp53Screen=screenKey();
  }

  function normalizeHeader(){
    if(!active()) return;
    const mark=q('header .gp-mobile-mark');
    if(mark){
      mark.removeAttribute('width'); mark.removeAttribute('height');
      mark.style.removeProperty('width'); mark.style.removeProperty('height');
    }
  }

  function updateBottomNav(){
    if(!active()) return;
    const key=screenKey();
    const map={inicio:'resumen',solicitudes:'solicitudes',clientes:'clientes',cobranza:'cobranza',pagos:'cobranza',expediente:'clientes'};
    const target=map[key];
    const nav=q('.mobile-nav'); if(!nav||!target)return;
    qa('button',nav).forEach(b=>b.classList.toggle('active',b.dataset.go===target));
  }

  function cloneActions(cell){
    if(!cell) return '';
    return qa('button,a',cell).map(el=>el.outerHTML).join('');
  }

  function replaceCardSet(wrap,type,html){
    if(!wrap) return;
    wrap.classList.add('gp53-hide-mobile-table');
    let cards=wrap.nextElementSibling;
    if(!(cards&&cards.classList.contains('gp53-cards')&&cards.dataset.gp53Type===type)){
      cards=document.createElement('div');
      cards.className='gp53-cards';
      cards.dataset.gp53Type=type;
      wrap.insertAdjacentElement('afterend',cards);
    }
    if(cards.dataset.gp53Html!==html){cards.innerHTML=html;cards.dataset.gp53Html=html;}
  }

  function convertPortfolioTables(){
    if(!active()) return;
    qa('.v8-table.portfolio').forEach(table=>{
      const wrap=table.closest('.v8-table-wrap');
      const rows=qa('tbody tr',table);
      if(!wrap||!rows.length) return;
      const html=rows.map(row=>{
        const td=qa('td',row);
        const accountId=Number(row.dataset.accountId||0);
        const name=text(td[1]?.querySelector('b'))||'Cliente';
        const sub=text(td[1]?.querySelector('small'));
        const moto=text(td[2]?.querySelector('b'))||'Sin modelo';
        const motoSub=text(td[2]?.querySelector('small'));
        const img=td[2]?.querySelector('img')?.getAttribute('src')||'assets/moto-blue.png';
        const plate=text(td[3])||'—';
        const paid=text(td[4]?.querySelector('b'))||text(td[4])||'0/50';
        const progress=td[4]?.querySelector('.v8-progress i')?.style.width||'0%';
        const late=text(td[5])||'0';
        const future=text(td[6])||'0';
        const advance=text(td[7])||'—';
        const referrer=text(td[8])||'—';
        const gps=td[9]?.innerHTML||'<span class="v8-pill gray">Pendiente</span>';
        const status=td[10]?.innerHTML||'';
        const actions=cloneActions(td[td.length-1]);
        return `<article class="gp53-card"><div class="gp53-card-head"><div class="identity"><span class="gp53-avatar">${esc(initials(name))}</span><div><b>${esc(name)}</b><small>${esc(sub)}</small></div></div><span class="gp53-bike"><img src="${esc(img)}" alt=""></span></div><div class="gp53-grid"><div><small>Motocicleta</small><b>${esc(moto)}${motoSub?` · ${esc(motoSub)}`:''}</b></div><div><small>Placa</small><b>${esc(plate)}</b></div><div><small>Pagadas / mora</small><b>${esc(paid)} · ${esc(late)} vencidas</b></div><div><small>Futuras / abono</small><b>${esc(future)} · ${esc(advance)}</b></div><div><small>Refiere</small><b>${esc(referrer)}</b></div><div><small>GPS</small><b>${gps}</b></div></div><div class="gp53-progress"><i style="width:${esc(progress)}"></i></div><div class="gp53-dist">${status}</div>${actions?`<div class="gp53-actions">${actions}</div>`:(accountId?`<div class="gp53-actions"><button onclick="gpAccountStatement(${accountId})"><i class="fa-solid fa-address-card"></i> Expediente</button></div>`:'')}</article>`;
      }).join('');
      replaceCardSet(wrap,'portfolio',html);
    });
  }

  function convertPaymentTables(){
    if(!active()) return;
    qa('.v17-payment-table').forEach(table=>{
      const wrap=table.closest('.v8-table-wrap');
      const rows=qa('tbody tr',table);
      if(!wrap||!rows.length)return;
      const html=rows.map(row=>{
        const td=qa('td',row);
        const date=text(td[0]?.querySelector('b'))||text(td[0]);
        const operator=text(td[0]?.querySelector('small'));
        const name=text(td[1]?.querySelector('b'))||'Cliente';
        const bike=text(td[1]?.querySelector('small'));
        const amount=text(td[2]?.querySelector('b'))||text(td[2]);
        const equiv=text(td[2]?.querySelector('small'));
        const dist=td[3]?.innerHTML||'<span class="v8-pill gray">Sin aplicación</span>';
        const method=td[4]?.childNodes?.[0]?.textContent?.trim()||text(td[4]);
        const reference=text(td[4]?.querySelector('small'));
        const status=td[5]?.innerHTML||'';
        const receipt=td[6]?.innerHTML||'<span class="v8-pill gray">Pendiente</span>';
        const actions=cloneActions(td[7]);
        return `<article class="gp53-card"><div class="gp53-card-head"><div class="identity"><span class="gp53-avatar"><i class="fa-solid fa-receipt"></i></span><div><b>${esc(name)}</b><small>${esc(bike)}${date?` · ${esc(date)}`:''}</small></div></div><strong class="gp53-amount">${esc(amount)}</strong></div>${equiv?`<div class="gp53-dist"><span class="v8-pill blue">${esc(equiv)}</span></div>`:''}<div class="gp53-dist">${dist}</div><div class="gp53-grid"><div><small>Forma de pago</small><b>${esc(method||'—')}</b></div><div><small>Referencia</small><b>${esc(reference||'Sin referencia')}</b></div><div><small>Estado</small><b>${status}</b></div><div><small>Recibo</small><b>${receipt}</b></div>${operator?`<div><small>Operador</small><b>${esc(operator)}</b></div>`:''}</div>${actions?`<div class="gp53-actions">${actions}</div>`:''}</article>`;
      }).join('');
      replaceCardSet(wrap,'payments',html);
    });
  }

  function convertAuditTables(){
    if(!active()) return;
    qa('.v18-audit-table').forEach(table=>{
      const wrap=table.closest('.v8-table-wrap');
      const rows=qa('tbody tr',table);
      if(!wrap||!rows.length)return;
      const html=rows.map(row=>{
        const td=qa('td',row);
        const date=text(td[0]);
        const user=text(td[1]);
        const type=td[2]?.innerHTML||'';
        const module=td[3]?.innerHTML||'';
        const action=text(td[4]);
        const entity=text(td[5]);
        const device=text(td[6]);
        const summary=text(td[7]);
        return `<article class="gp53-card"><div class="gp53-card-head"><div class="identity"><span class="gp53-avatar"><i class="fa-solid fa-fingerprint"></i></span><div><b>${esc(action||'Evento')}</b><small>${esc(date)}</small></div></div>${type}</div><div class="gp53-grid"><div><small>Usuario</small><b>${esc(user||'—')}</b></div><div><small>Módulo</small><b>${module}</b></div><div><small>Entidad</small><b>${esc(entity||'—')}</b></div><div><small>IP / dispositivo</small><b>${esc(device||'—')}</b></div></div>${summary?`<div class="gp53-grid"><div style="grid-column:1/-1"><small>Resumen</small><b>${esc(summary)}</b></div></div>`:''}</article>`;
      }).join('');
      replaceCardSet(wrap,'audit',html);
    });
  }

  function cleanOrphanCards(){
    if(!active()){
      qa('.gp53-cards').forEach(n=>n.remove());
      qa('.gp53-hide-mobile-table').forEach(n=>n.classList.remove('gp53-hide-mobile-table'));
    }
  }

  function apply(){
    scheduled=false;
    setMode();
    if(!active()){cleanOrphanCards();return;}
    normalizeHeader();
    updateBottomNav();
    convertPortfolioTables();
    convertPaymentTables();
    convertAuditTables();
  }

  function schedule(){
    if(scheduled)return;
    scheduled=true;
    requestAnimationFrame(()=>setTimeout(apply,10));
  }

  function boot(){
    apply();
    const root=q('#view')||document.body;
    observer=new MutationObserver(schedule);
    observer.observe(root,{childList:true,subtree:true});
    window.addEventListener('resize',schedule,{passive:true});
    const mq=window.matchMedia?window.matchMedia(MQ):null;
    if(mq&&mq.addEventListener)mq.addEventListener('change',schedule);
    else if(mq&&mq.addListener)mq.addListener(schedule);
    setTimeout(apply,200);
    setTimeout(apply,700);
  }

  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot);
  else boot();
})();
