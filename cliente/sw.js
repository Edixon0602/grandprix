const VERSION='v46';
const CACHE=`grandprix-cliente-pwa-${VERSION}`;
const PREFIX='grandprix-cliente-pwa-';
const CORE=['/cliente/manifest.json','/cliente/offline.html','/cliente/grandprix-cliente-icon-256.png','/cliente/grandprix-cliente-icon-512.png'];
self.addEventListener('install',event=>{self.skipWaiting();event.waitUntil(caches.open(CACHE).then(c=>Promise.all(CORE.map(u=>c.add(u).catch(()=>null)))));});
self.addEventListener('activate',event=>{event.waitUntil(Promise.all([self.clients.claim(),caches.keys().then(keys=>Promise.all(keys.filter(k=>k.startsWith(PREFIX)&&k!==CACHE).map(k=>caches.delete(k))))]));});
self.addEventListener('fetch',event=>{const u=new URL(event.request.url);if(event.request.mode!=='navigate'||u.origin!==location.origin)return;event.respondWith(fetch(event.request,{cache:'no-store'}).catch(()=>caches.match('/cliente/offline.html')));});
