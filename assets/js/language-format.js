(function(){
'use strict';
function getLanguage(){
 var l=document.documentElement.dataset.language;
 try{var s=localStorage.getItem('dbuy1-language');if(s&&['fa','en','ar'].includes(s))return s;}catch(e){}
 return ['fa','en','ar'].includes(l)?l:'fa';
}
function digits(value,lang){
 var chars=lang==='fa'?'۰۱۲۳۴۵۶۷۸۹':lang==='ar'?'٠١٢٣٤٥٦٧٨٩':'0123456789';
 return String(value).replace(/[0-9]/g,function(d){return chars[Number(d)];});
}
function formatText(text,lang){
 return text.replace(/\d[\d,]*(?:\.\d+)?/g,function(token){
  var clean=token.replace(/,/g,'');
  if(!/^\d+(?:\.\d+)?$/.test(clean))return token;
  var parts=clean.split('.');
  if(parts[0].length>=4)parts[0]=Number(parts[0]).toLocaleString(lang==='fa'?'fa-IR':lang==='ar'?'ar':'en-US',{useGrouping:true,maximumFractionDigits:0});
  return digits(parts.join('.'),lang);
 });
}
function run(){
 var lang=getLanguage();
 document.documentElement.dataset.language=lang;
 document.documentElement.lang=lang;
 document.documentElement.dir=lang==='en'?'ltr':'rtl';
 var walker=document.createTreeWalker(document.body,NodeFilter.SHOW_TEXT),nodes=[];
 while(walker.nextNode()){
  var n=walker.currentNode;
  if(!n.parentElement||n.parentElement.closest('script,style,textarea,input,select,option,canvas,[data-no-number-format]'))continue;
  if(/\d/.test(n.nodeValue))nodes.push(n);
 }
 nodes.forEach(function(n){n.nodeValue=formatText(n.nodeValue,lang);});
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',run);else run();
document.addEventListener('change',function(e){
 if(e.target&&e.target.name==='language'){
  try{localStorage.setItem('dbuy1-language',e.target.value);}catch(err){}
  document.documentElement.dataset.language=e.target.value;
  document.documentElement.lang=e.target.value;
  document.documentElement.dir=e.target.value==='en'?'ltr':'rtl';
  window.location.reload();
 }
});
})();