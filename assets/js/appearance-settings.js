(function(){
'use strict';
var KEY='dbuy1-appearance-v1';
var defaults={background:'#f3f5f8',text:'#202938',button:'#174ea6',card:'#ffffff',shadow:8,dark:false};
var current=read();
function read(){try{return Object.assign({},defaults,JSON.parse(localStorage.getItem(KEY)||'{}'));}catch(e){return Object.assign({},defaults);}}
function save(){try{localStorage.setItem(KEY,JSON.stringify(current));}catch(e){}}
function apply(){
 var root=document.documentElement;
 root.dataset.appearanceTheme=current.dark?'dark':'light';
 root.style.setProperty('--user-bg',current.dark?'#111827':current.background);
 root.style.setProperty('--user-text',current.dark?'#e5e7eb':current.text);
 root.style.setProperty('--user-button',current.dark?'#2563eb':current.button);
 root.style.setProperty('--user-card',current.dark?'#1f2937':current.card);
 root.style.setProperty('--user-shadow','0 3px 14px rgba(0,0,0,'+(Math.max(0,Math.min(30,Number(current.shadow)||0))/100)+')');
}
function el(tag,attrs,text){var n=document.createElement(tag);Object.keys(attrs||{}).forEach(function(k){if(k==='class')n.className=attrs[k];else if(k==='type'||k==='id'||k==='value'||k==='title'||k==='aria-label')n.setAttribute(k,attrs[k]);else n[k]=attrs[k];});if(text!==undefined)n.textContent=text;return n;}
function init(){
 apply();
 if(!document.body||document.getElementById('appearance-settings-button'))return;
 var open=el('button',{id:'appearance-settings-button',type:'button',class:'appearance-settings-button','aria-label':'تنظیمات ظاهر',title:'تنظیمات ظاهر'},'⚙ تنظیمات ظاهر');
 document.body.appendChild(open);
 var dialog=el('dialog',{id:'appearance-settings-dialog',class:'appearance-settings-dialog'});
 var form=el('div',{class:'appearance-settings-form'});
 var heading=el('h2',{},'تنظیمات ظاهر');
 var intro=el('p',{class:'appearance-settings-intro'},'رنگ‌ها در همین مرورگر ذخیره می‌شوند و با باز کردن دوباره سایت باقی می‌مانند.');
 dialog.append(heading,intro,form);
 var fields=[
  {key:'background',label:'رنگ پس‌زمینه',type:'color'},
  {key:'card',label:'رنگ کارت‌ها و پنل‌ها',type:'color'},
  {key:'text',label:'رنگ نوشته‌ها',type:'color'},
  {key:'button',label:'رنگ دکمه‌ها',type:'color'}
 ];
 fields.forEach(function(f){
  var row=el('label',{class:'appearance-color-row'});row.appendChild(el('span',{},f.label));
  var input=el('input',{type:'color',value:current[f.key]});input.value=current[f.key];
  input.addEventListener('input',function(){current[f.key]=input.value;current.dark=false;dark.checked=false;apply();save();});
  row.appendChild(input);form.appendChild(row);
 });
 var shadowRow=el('label',{class:'appearance-range-row'});shadowRow.appendChild(el('span',{},'شدت سایه کارت‌ها'));
 var shadow=el('input',{type:'range',value:String(current.shadow),min:'0',max:'30',step:'1'});shadow.value=current.shadow;
 var shadowValue=el('output',{},current.shadow+'%');
 shadow.addEventListener('input',function(){current.shadow=Number(shadow.value);shadowValue.value=current.shadow+'%';apply();save();});
 shadowRow.append(shadow,shadowValue);form.appendChild(shadowRow);
 var darkRow=el('label',{class:'appearance-dark-row'});var dark=el('input',{type:'checkbox'});dark.checked=!!current.dark;
 darkRow.append(dark,el('span',{},'حالت تاریک (Dark mode)'));dark.addEventListener('change',function(){current.dark=dark.checked;apply();save();});form.appendChild(darkRow);
 var actions=el('div',{class:'appearance-settings-actions'});
 var reset=el('button',{type:'button',class:'appearance-reset'},'بازنشانی رنگ‌ها');
 var close=el('button',{type:'button'},'بستن');
 reset.addEventListener('click',function(){current=Object.assign({},defaults);save();apply();form.querySelectorAll('input[type=color]').forEach(function(input,i){input.value=current[fields[i].key];});shadow.value=current.shadow;shadowValue.value=current.shadow+'%';dark.checked=current.dark;});
 close.addEventListener('click',function(){dialog.close();});
 actions.append(reset,close);dialog.appendChild(actions);document.body.appendChild(dialog);
 open.addEventListener('click',function(){if(typeof dialog.showModal==='function')dialog.showModal();else dialog.setAttribute('open','');});
 dialog.addEventListener('click',function(e){if(e.target===dialog)dialog.close();});
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();