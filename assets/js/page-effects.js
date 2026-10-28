(function(){
'use strict';
if(window.matchMedia&&window.matchMedia('(prefers-reduced-motion: reduce)').matches)return;
if(document.body.classList.contains('no-page-effects'))return;
var key='dbuy1-company-logo';
function rememberLogo(){
 var logo=document.querySelector('.company-logo-preview,.letterhead-logo');
 if(logo&&logo.getAttribute('src')){
  try{var u=new URL(logo.getAttribute('src'),location.href);if(u.origin===location.origin)localStorage.setItem(key,u.href);}catch(e){}
 }
}
function playLogo(){
 var src='';
 try{src=localStorage.getItem(key)||'';}catch(e){}
 if(!src)return;
 try{if(new URL(src,location.href).origin!==location.origin)return;}catch(e){return;}
 var wrap=document.createElement('div');wrap.className='brand-flight-wrap';wrap.setAttribute('aria-hidden','true');
 var img=document.createElement('img');img.src=src;img.alt='';wrap.appendChild(img);document.body.appendChild(wrap);
 window.setTimeout(function(){wrap.remove();},2700);
}
function makeOverlay(kind){
 var overlay=document.createElement('div');overlay.className='page-turn-overlay '+(kind==='leave'?'is-leaving':'is-entering');overlay.setAttribute('aria-hidden','true');
 var sheet=document.createElement('div');sheet.className='page-turn-sheet';overlay.appendChild(sheet);document.body.appendChild(overlay);
 window.setTimeout(function(){overlay.remove();},kind==='leave'?760:900);
 return overlay;
}
rememberLogo();
playLogo();
makeOverlay('enter');
document.addEventListener('click',function(event){
 if(event.defaultPrevented||event.button!==0||event.metaKey||event.ctrlKey||event.shiftKey||event.altKey)return;
 var a=event.target&&event.target.closest?event.target.closest('a[href]'):null;
 if(!a||a.hasAttribute('download')||a.target&&a.target!=='_self'||a.dataset.noPageTurn!==undefined)return;
 var url;try{url=new URL(a.href,location.href);}catch(e){return;}
 if(url.origin!==location.origin||url.pathname===location.pathname&&url.search===location.search&&url.hash)return;
 if(url.protocol!=='http:'&&url.protocol!=='https:')return;
 if(a.href.indexOf('javascript:')===0)return;
 event.preventDefault();
 makeOverlay('leave');
 window.setTimeout(function(){location.href=url.href;},650);
},true);
document.addEventListener('submit',function(event){
 var form=event.target;
 if(!form||form.dataset.noPageTurn!==undefined||form.target&&form.target!=='_self')return;
 // Do not delay submissions: the next page will animate automatically on load.
});
})();
