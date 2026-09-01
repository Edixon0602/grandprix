const C='mi-grandprix-v31-0-0';
const A=['assets/cliente.css?v=28.0.0','assets/gps-premium.css?v=7.2.1','assets/v72.css?v=7.2.1','assets/cliente.js?v=31.0.0','assets/v29-mobile.css?v=31.0.0','assets/gps-v2.js?v=7.2.1','assets/satellite-pro.js?v=7.2.1','../assets/satellite-pro.css?v=7.2.1','../assets/realtime.js?v=7.2.1','../assets/vendor/maplibre-gl.css?v=5.24.0','../assets/vendor/maplibre-gl.js?v=5.24.0','../assets/vendor/pusher.min.js?v=8.6.0','../assets/grandprix-logo.png','../assets/moto-blue.png','manifest.json'];
self.addEventListener('install',e=>e.waitUntil(caches.open(C).then(c=>c.addAll(A)).catch(()=>{})));
self.addEventListener('activate',e=>e.waitUntil(caches.keys().then(k=>Promise.all(k.filter(x=>x!==C).map(x=>caches.delete(x))))));
self.addEventListener('fetch',e=>{
  if(e.request.method!=='GET')return;
  const url=new URL(e.request.url);
  const isStatic=url.origin===self.location.origin&&(url.pathname.includes('/cliente/assets/')||url.pathname.endsWith('/cliente/manifest.json')||url.pathname.includes('/assets/vendor/')||url.pathname.includes('/assets/satellite-pro.css')||url.pathname.includes('/assets/grandprix-logo.png')||url.pathname.includes('/assets/moto-blue.png'));
  if(!isStatic)return;
  e.respondWith(fetch(e.request).then(r=>{const x=r.clone();caches.open(C).then(c=>c.put(e.request,x));return r}).catch(()=>caches.match(e.request)));
});
