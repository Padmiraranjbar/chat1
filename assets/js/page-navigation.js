(function(){
'use strict';
function start(){
 var body=document.body;
 if(!body)return;
 var page=body.getAttribute('data-current-page')||'dashboard';
 var labels={product:'تعریف کالا',crm:'مشتریان (CRM)',purchase:'ثبت خرید',sale:'ثبت فروش',settings:'تنظیمات شرکت',reports:'گزارش خرید و فروش',balances:'تراز آزمایشی',journal:'دفتر روزنامه'};
 var nav=document.querySelector('.dashboard-nav');
 var toolbar=document.getElementById('page-toolbar');
 var title=document.getElementById('current-page-title');
 var sections=Array.prototype.slice.call(document.querySelectorAll('main > section'));
 document.documentElement.setAttribute('data-app-page',page);
 sections.forEach(function(s){s.hidden=true;});
 if(toolbar)toolbar.hidden=true;
 if(nav)nav.hidden=page!=='dashboard';
 if(page==='dashboard'||!labels[page])return;
 if(title)title.textContent=labels[page];
 if(toolbar)toolbar.hidden=false;
 var section=document.getElementById(page);
 if(!section)return;
 // Transaction entry screens open in a focused modal instead of being mixed into a long dashboard.
 if(['sale','purchase','product'].indexOf(page)!==-1 && !document.getElementById('result-dialog')){
   var dialog=document.createElement('dialog');
   dialog.className='module-dialog';
   dialog.setAttribute('aria-label',labels[page]);
   var bar=document.createElement('div');bar.className='module-dialog-toolbar';
   var heading=document.createElement('h2');heading.textContent=labels[page];
   var close=document.createElement('a');close.className='button-link';close.href='index.php';close.textContent='← برگشت به داشبورد';
   bar.append(heading,close);
   dialog.appendChild(bar);
   section.hidden=false;
   dialog.appendChild(section);
   var footer=document.createElement('div');footer.className='module-dialog-footer';
   var back=document.createElement('a');back.className='button-link module-back-button';back.href='index.php';back.textContent='← برگشت به داشبورد';
   footer.appendChild(back);dialog.appendChild(footer);
   document.querySelector('main').appendChild(dialog);
   if(typeof dialog.showModal==='function')dialog.showModal();else dialog.setAttribute('open','');
   close.addEventListener('click',function(){/* normal navigation returns to the dashboard */});
   dialog.addEventListener('click',function(e){if(e.target===dialog){window.location.href='index.php';}});
   return;
 }
 section.hidden=false;
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start);else start();
})();