(function(){
'use strict';
var page=document.body&&document.body.getAttribute('data-current-page')||'dashboard';
var map={
 product:['product','تعریف کالا'],
 crm:['crm','مشتریان (CRM)'],
 purchase:['purchase','ثبت خرید'],
 sale:['sale','ثبت فروش'],
 settings:['settings','تنظیمات شرکت'],
 reports:['reports','گزارش خرید و فروش'],
 balances:['balances','تراز آزمایشی'],
 journal:['journal','دفتر روزنامه']
};
var nav=document.querySelector('.dashboard-nav');
var toolbar=document.getElementById('page-toolbar');
var title=document.getElementById('current-page-title');
document.documentElement.setAttribute('data-app-page',page);
if(page==='dashboard'){
 if(toolbar)toolbar.hidden=true;
 document.querySelectorAll('main > section').forEach(function(section){section.hidden=true;});
 return;
}
if(nav)nav.hidden=true;
document.querySelectorAll('main > section').forEach(function(section){section.hidden=true;});
if(map[page]){
 var section=document.getElementById(map[page][0]);
 if(section)section.hidden=false;
 if(title)title.textContent=map[page][1];
 if(toolbar)toolbar.hidden=false;
}
})();