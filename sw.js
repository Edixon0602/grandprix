const CACHE='grandprix-control-360-v7-2-0';
const CORE=['assets/app.css?v=7.2.0','assets/premium.css?v=7.2.0','assets/satellite-pro.css?v=7.2.0','assets/v72-admin.css?v=7.2.0','assets/vendor/maplibre-gl.css?v=5.24.0','assets/vendor/maplibre-gl.js?v=5.24.0','assets/vendor/pusher.min.js?v=8.6.0','assets/app.js?v=7.2.0','assets/realtime.js?v=7.2.0','assets/satellite-pro.js?v=7.2.0','assets/v72-admin.js?v=7.2.0','assets/grandprix-logo.png','assets/moto-blue.png','assets/moto-red.png','assets/moto-black.png','manifest.json'];
self.addEventListener('install',e=>e.waitUntil(caches.open(CACHE).then(c=>c.addAll(CORE)).catch(()=>{})));
self.addEventListener('activate',e=>e.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(k=>k!==CACHE).map(k=>caches.delete(k))))));
self.addEventListener('fetch',e=>{
  if(e.request.method!=='GET')return;
  const url=new URL(e.request.url);
  const isStatic=url.origin===self.location.origin&&(url.pathname.includes('/assets/')||url.pathname.endsWith('/manifest.json'));
  if(!isStatic)return;
  e.respondWith(fetch(e.request).then(r=>{const copy=r.clone();caches.open(CACHE).then(c=>c.put(e.request,copy));return r}).catch(()=>caches.match(e.request)));
});
