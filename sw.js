/* Cache only static shell assets. Never cache PHP pages or financial data. */
'use strict';
var CACHE='accounting-shell-v1';
var ASSETS=['./assets/css/app.css','./assets/images/app-icon.svg'];
self.addEventListener('install',function(e){e.waitUntil(caches.open(CACHE).then(function(c){return c.addAll(ASSETS);}).then(function(){return self.skipWaiting();}));});
self.addEventListener('activate',function(e){e.waitUntil(caches.keys().then(function(keys){return Promise.all(keys.filter(function(k){return k!==CACHE;}).map(function(k){return caches.delete(k);}));}).then(function(){return self.clients.claim();}));});
self.addEventListener('fetch',function(e){var r=e.request;var u=new URL(r.url);if(r.method!=='GET'||u.origin!==self.location.origin||r.mode==='navigate'||/\\.php(?:$|\\?)/i.test(u.pathname+u.search))return;if(u.pathname.endsWith('/assets/css/app.css')||u.pathname.endsWith('/assets/images/app-icon.svg'))e.respondWith(caches.match(r).then(function(c){return c||fetch(r);}));});
