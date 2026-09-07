(()=>{
'use strict';
const meta=(name,fallback='')=>document.querySelector(`meta[name="${name}"]`)?.content||fallback;
const cfg={
 name:meta('grandprix-pwa-name','GRANDPRIX'),
 label:meta('grandprix-pwa-install-label','Instalar app'),
 sw:meta('grandprix-pwa-sw','sw.js'),
 icon:meta('grandprix-pwa-icon',''),
 storage:meta('grandprix-pwa-storage','gpPwaInstalled')
};
const isSecure=location.protocol==='https:'||location.hostname==='localhost'||location.hostname==='127.0.0.1';
const isiOS=/iphone|ipad|ipod/i.test(navigator.userAgent);
const isAndroid=/android/i.test(navigator.userAgent);
const coarse=window.matchMedia?.('(pointer: coarse)').matches===true;
const narrow=window.matchMedia?.('(max-width: 900px)').matches===true;
const isMobile=()=>/iphone|ipad|ipod|android|mobile/i.test(navigator.userAgent)||(coarse&&narrow);
const isStandalone=()=>window.matchMedia?.('(display-mode: standalone)').matches||window.navigator.standalone===true;
const storedInstalled=()=>{try{return localStorage.getItem(cfg.storage)==='1'}catch(_e){return false}};
const markInstalled=()=>{try{localStorage.setItem(cfg.storage,'1')}catch(_e){}};
const dismissed=()=>{try{return sessionStorage.getItem(cfg.storage+':dismissed')==='1'}catch(_e){return false}};
const markDismissed=()=>{try{sessionStorage.setItem(cfg.storage+':dismissed','1')}catch(_e){}};
const canOffer=()=>isMobile()&&!isStandalone()&&!storedInstalled()&&!dismissed();
let deferredPrompt=null,box=null,iosConfirm=false;

function hide(){if(box)box.classList.remove('show')}
function ensureBox(){
 if(box)return box;
 box=document.createElement('aside');
 box.id='gp-pwa-install';
 box.setAttribute('role','dialog');
 box.setAttribute('aria-live','polite');
 box.innerHTML=`<div class="gp-pwa-card"><div class="gp-pwa-icon"><img alt="Logo GRANDPRIX" src="${cfg.icon}"></div><div class="gp-pwa-copy"><strong>${cfg.label}</strong><span>Instala la app en tu teléfono para entrar más rápido.</span></div><div class="gp-pwa-actions"><button type="button" class="gp-pwa-install-btn">Instalar</button><button type="button" class="gp-pwa-close" aria-label="Cerrar">×</button></div></div>`;
 document.body.appendChild(box);
 const installBtn=box.querySelector('.gp-pwa-install-btn');
 box.querySelector('.gp-pwa-close').addEventListener('click',()=>{hide();markDismissed();});
 installBtn.addEventListener('click',async()=>{
   if(deferredPrompt){
     deferredPrompt.prompt();
     try{
       const choice=await deferredPrompt.userChoice;
       if(choice&&choice.outcome==='accepted'){markInstalled();hide();}
     }catch(_e){}
     deferredPrompt=null;
     return;
   }
   if(isiOS){
     if(iosConfirm){markInstalled();hide();return;}
     iosConfirm=true;
     box.classList.add('ios-help');
     box.querySelector('.gp-pwa-copy strong').textContent=`Instalar ${cfg.name}`;
     box.querySelector('.gp-pwa-copy span').textContent='En iPhone: toca Compartir → “Añadir a pantalla de inicio”. Cuando termines, pulsa “Ya la instalé”.';
     installBtn.textContent='Ya la instalé';
     return;
   }
   box.querySelector('.gp-pwa-copy span').textContent='Abre el menú ⋮ de Chrome y toca “Instalar aplicación” o “Añadir a pantalla principal”.';
   installBtn.textContent='Instalar';
 });
 return box;
}
function show(message){
 if(!canOffer())return;
 const b=ensureBox();
 if(message)b.querySelector('.gp-pwa-copy span').textContent=message;
 requestAnimationFrame(()=>b.classList.add('show'));
}

// El Service Worker puede registrarse en cualquier equipo; el aviso visual solo existe en móvil.
if(isSecure&&'serviceWorker'in navigator){window.addEventListener('load',()=>navigator.serviceWorker.register(cfg.sw).catch(err=>console.warn('GRANDPRIX PWA SW:',err)));}
window.addEventListener('appinstalled',()=>{markInstalled();deferredPrompt=null;hide();});
window.addEventListener('beforeinstallprompt',e=>{
 e.preventDefault();
 deferredPrompt=e;
 show(`Instala ${cfg.name} en tu teléfono y ábrela como una app.`);
});

document.addEventListener('DOMContentLoaded',()=>{
 if(!isMobile()||isStandalone()||storedInstalled())return;
 // iOS no expone beforeinstallprompt; mostramos la instrucción nativa de Safari.
 if(isiOS){setTimeout(()=>show(`Instala ${cfg.name} en tu iPhone desde la pantalla de inicio.`),700);return;}
 // En Android, damos tiempo a Chrome para emitir beforeinstallprompt. Si no llega, mantenemos una ayuda de instalación.
 if(isAndroid||coarse){setTimeout(()=>{if(!deferredPrompt)show(`Instala ${cfg.name} en tu teléfono para usarla como app.`)},1800);}
});
})();
