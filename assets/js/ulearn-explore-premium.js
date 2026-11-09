(function(){'use strict';function init(){var input=document.getElementById('explore-module-search');var grid=document.getElementById('explore-available-grid');if(!input||!grid)return;var cards=Array.prototype.slice.call(grid.querySelectorAll('.card'));var count=document.getElementById('explore-search-count');var empty=document.getElementById('explore-no-results');function update(){var q=input.value.trim().toLocaleLowerCase();var shown=0;cards.forEach(function(card){var match=card.textContent.toLocaleLowerCase().includes(q);card.hidden=!match;if(match)shown++});if(count)count.textContent=q?shown+' of '+cards.length+' modules':'';if(empty)empty.hidden=shown!==0}input.addEventListener('input',update)}if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init()})();
(function(){
'use strict';
function init(){
 var body=document.body, html=document.documentElement;
 var toggle=document.getElementById('ex-theme-toggle');
 if(!body||!toggle)return;
 var icon=toggle.querySelector('.ex-theme-icon');
 var label=toggle.querySelector('.ex-theme-label');
 var mq=window.matchMedia('(prefers-color-scheme: dark)');
 var stored=null;
 try{stored=localStorage.getItem('ulearn-explore-theme')}catch(e){}
 function current(){
   var theme=html.getAttribute('data-theme')||'system';
   return theme==='dark'||(theme==='system'&&mq.matches)?'dark':'light';
 }
 function paint(mode){
   body.setAttribute('data-ex-theme',mode);
   // Keep the root in sync for third-party components; body tokens remain authoritative.
   html.setAttribute('data-theme',mode);
   if(icon)icon.textContent=mode==='dark'?'☀':'☾';
   if(label)label.textContent=mode==='dark'?'Light mode':'Dark mode';
   toggle.setAttribute('aria-label',mode==='dark'?'Switch to light mode':'Switch to dark mode');
   toggle.setAttribute('aria-pressed',String(mode==='dark'));
 }
 paint(stored==='light'||stored==='dark'?stored:current());
 toggle.addEventListener('click',function(){
   var next=body.getAttribute('data-ex-theme')==='dark'?'light':'dark';
   paint(next);
   try{localStorage.setItem('ulearn-explore-theme',next)}catch(e){}
 });
 // Animate cards on repeated viewport entry, with no invisible content when JS fails.
 if(!('IntersectionObserver' in window)||window.matchMedia('(prefers-reduced-motion: reduce)').matches)return;
 var cards=[].slice.call(document.querySelectorAll('.ex-module-card'));
 var obs=new IntersectionObserver(function(entries){
   entries.forEach(function(entry){
     var el=entry.target;
     if(entry.isIntersecting){
       requestAnimationFrame(function(){el.classList.add('ex-visible')});
     }else el.classList.remove('ex-visible');
   });
 },{threshold:.07,rootMargin:'0px 0px -10px 0px'});
 cards.forEach(function(el,i){
   el.style.setProperty('--ex-from-x',(i%2?72:-72)+'px');
   el.classList.add('ex-reveal');
   obs.observe(el);
 });
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);
else init();
})();
