/* PWA installation helper */
(function(){
'use strict';
var current=document.currentScript;
var root=new URL('../../',current.src);
if(!document.querySelector('link[rel=manifest]')){var m=document.createElement('link');m.rel='manifest';m.href=new URL('manifest.webmanifest',root).href;document.head.appendChild(m);}
if('serviceWorker' in navigator && location.protocol==='https:'){window.addEventListener('load',function(){navigator.serviceWorker.register(new URL('sw.js',root).href,{scope:root.pathname}).catch(function(){});});}
var promptEvent=null;var b=document.createElement('button');b.type='button';b.className='pwa-install-button';b.textContent='نصب برنامه';b.hidden=false;b.addEventListener('click',function(){if(!promptEvent){alert('از منوی مرورگر گزینه «نصب برنامه» یا «افزودن به صفحه اصلی» را انتخاب کنید.');return;}promptEvent.prompt();promptEvent.userChoice.catch(function(){});promptEvent=null;b.hidden=true;});
function addButton(){var host=document.querySelector('.app-header')||document.querySelector('main')||document.body;if(host&&!b.isConnected)host.appendChild(b);}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',addButton);else addButton();
window.addEventListener('beforeinstallprompt',function(e){e.preventDefault();promptEvent=e;b.hidden=false;});window.addEventListener('appinstalled',function(){promptEvent=null;b.hidden=true;});
if(window.matchMedia('(display-mode: standalone)').matches||navigator.standalone===true)b.hidden=true;
})();
