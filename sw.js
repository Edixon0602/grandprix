const CACHE='grandprix360-v53-clean';
const CORE=[
  'assets/app.css?v=11.0.0',
  'assets/premium.css?v=11.0.0',
  'assets/v72-admin.css?v=11.0.0',
  'assets/finance-users-v8.css?v=28.0.0',
  'assets/v29-mobile.css?v=31.0.0',
  'assets/v30-finance-analytics.css?v=31.0.0',
  'assets/v31-payments.css?v=33.0.0',
  'assets/grandprix-ui-v35.css?v=35.1.0',
  'assets/grandprix-ui-v36.css?v=36.0.0',
  'assets/grandprix360-mobile-tablet-v53-clean.css?v=53.0.0',
  'assets/grandprix360-mobile-tablet-v53-clean.js?v=53.0.0',
  'assets/grandprix-symbol.png',
  'assets/grandprix-logo-light.png',
  'manifest.json'
];
self.addEventListener('install',event=>{
  self.skipWaiting();
  event.waitUntil(caches.open(CACHE).then(cache=>cache.addAll(CORE)).catch(()=>{}));
});
self.addEventListener('activate',event=>{
  event.waitUntil((async()=>{
    const keys=await caches.keys();
    await Promise.all(keys.filter(k=>k!==CACHE && /^grandprix/i.test(k)).map(k=>caches.delete(k)));
    await self.clients.claim();
  })());
});
self.addEventListener('fetch',event=>{
  if(event.request.method!=='GET') return;
  const url=new URL(event.request.url);
  if(url.origin!==self.location.origin) return;
  const staticAsset=url.pathname.includes('/assets/') || url.pathname.endsWith('/manifest.json');
  if(!staticAsset) return;
  event.respondWith((async()=>{
    try{
      const response=await fetch(event.request,{cache:'no-store'});
      if(response && response.ok){
        const copy=response.clone();
        caches.open(CACHE).then(cache=>cache.put(event.request,copy)).catch(()=>{});
      }
      return response;
    }catch(_){
      return (await caches.match(event.request)) || Response.error();
    }
  })());
});
