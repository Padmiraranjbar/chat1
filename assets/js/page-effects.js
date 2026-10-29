(function(){
'use strict';
if(window.matchMedia&&window.matchMedia('(prefers-reduced-motion: reduce)').matches)return;
if(document.body.classList.contains('no-page-effects'))return;
function shineLogo(){
 var logo=document.querySelector('.company-logo-preview,.letterhead-logo,header .logo img,.app-logo img,.brand-logo img');
 if(!logo)return;
 var rect=logo.getBoundingClientRect();
 if(!rect.width||!rect.height)return;
 var shine=document.createElement('span');
 shine.className='logo-shine-overlay';
 shine.setAttribute('aria-hidden','true');
 shine.style.left=(rect.left+window.scrollX)+'px';
 shine.style.top=(rect.top+window.scrollY)+'px';
 shine.style.width=rect.width+'px';
 shine.style.height=rect.height+'px';
 document.body.appendChild(shine);
 window.setTimeout(function(){shine.remove();},1500);
}
function makeOverlay(kind){
 var overlay=document.createElement('div');overlay.className='page-turn-overlay '+(kind==='leave'?'is-leaving':'is-entering');overlay.setAttribute('aria-hidden','true');
 var sheet=document.createElement('div');sheet.className='page-turn-sheet';overlay.appendChild(sheet);document.body.appendChild(overlay);
 window.setTimeout(function(){overlay.remove();},kind==='leave'?760:900);
 return overlay;
}
shineLogo();
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
});
})();