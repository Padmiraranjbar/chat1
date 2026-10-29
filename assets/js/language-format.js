(function(){
'use strict';
function getLanguage(){
 var l=document.documentElement.dataset.language;
 return ['fa','en','ar'].includes(l)?l:'fa';
}
function digits(value,lang){
 var chars=lang==='fa'?'۰۱۲۳۴۵۶۷۸۹':lang==='ar'?'٠١٢٣٤٥٦٧٨٩':'0123456789';
 return String(value).replace(/[0-9]/g,function(d){return chars[Number(d)];});
}
function formatText(text,lang){
 // Amounts are formatted with thousands separators in PHP. This function only localizes digits,
 // so dates, phone numbers, account codes and already-grouped amounts keep their original shape.
 return text.replace(/[0-9][0-9,]*(?:\.[0-9]+)?/g,function(token){return digits(token,lang);});
}
function applyLanguage(lang){
 if(!['fa','en','ar'].includes(lang))lang='fa';
 document.documentElement.dataset.language=lang;
 document.documentElement.lang=lang;
 document.documentElement.dir=lang==='en'?'ltr':'rtl';
 var walker=document.createTreeWalker(document.body,NodeFilter.SHOW_TEXT),nodes=[];
 while(walker.nextNode()){
  var n=walker.currentNode;
  if(!n.parentElement||n.parentElement.closest('script,style,textarea,input,select,option,canvas,[data-no-number-format]'))continue;
  if(/[0-9]/.test(n.nodeValue))nodes.push(n);
 }
 nodes.forEach(function(n){
  if(!n.dataset.originalNumberText)n.dataset.originalNumberText=n.nodeValue;
  n.nodeValue=formatText(n.dataset.originalNumberText,lang);
 });
}
function run(){applyLanguage(getLanguage());}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',run);else run();
document.addEventListener('change',function(e){
 if(e.target&&e.target.name==='language'){
  // Preview immediately; the saved database choice is applied on the next page load.
  applyLanguage(e.target.value);
 }
});
})();