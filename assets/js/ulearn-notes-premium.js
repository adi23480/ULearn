(function(){'use strict';
function init(){
 var body=document.body,html=document.documentElement,btn=document.getElementById('notes-theme-toggle');
 if(!body||!btn)return;
 var icon=document.getElementById('notes-theme-icon'),label=document.getElementById('notes-theme-label');
 var mq=window.matchMedia('(prefers-color-scheme: dark)'),stored=null;
 try{stored=localStorage.getItem('ulearn-premium-theme')}catch(e){}
 function theme(){var t=html.getAttribute('data-theme');return t==='dark'||(t!=='light'&&mq.matches)?'dark':'light'}
 function setTheme(t){body.setAttribute('data-notes-theme',t);html.setAttribute('data-theme',t);if(icon)icon.textContent=t==='dark'?'☀':'☾';if(label)label.textContent=t==='dark'?'Light mode':'Dark mode';btn.setAttribute('aria-label',t==='dark'?'Switch to light mode':'Switch to dark mode');btn.setAttribute('aria-pressed',String(t==='dark'))}
 setTheme(stored==='light'||stored==='dark'?stored:theme());
 btn.addEventListener('click',function(){var next=body.getAttribute('data-notes-theme')==='dark'?'light':'dark';setTheme(next);try{localStorage.setItem('ulearn-premium-theme',next)}catch(e){}});
 if(!('IntersectionObserver' in window)||window.matchMedia('(prefers-reduced-motion: reduce)').matches)return;
 var cards=[].slice.call(document.querySelectorAll('.notes-card'));
 var observer=new IntersectionObserver(function(entries){entries.forEach(function(entry){if(entry.isIntersecting)entry.target.classList.add('nl-visible');else entry.target.classList.remove('nl-visible')})},{threshold:.05,rootMargin:'0px 0px -8px 0px'});
 cards.forEach(function(card,i){card.style.setProperty('--nl-x',(i%2?50:-50)+'px');card.classList.add('nl-enter');observer.observe(card)});
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
(function(){'use strict';
function ready(){
 var body=document.body;if(!body||!body.classList.contains('ul3-notes'))return;
 var reduced=window.matchMedia('(prefers-reduced-motion: reduce)').matches;
 var progress=document.getElementById('nl-scroll-progress');
 var ticking=false;
 function updateProgress(){ticking=false;if(!progress)return;var max=document.documentElement.scrollHeight-window.innerHeight;progress.style.width=(max>0?Math.min(100,Math.max(0,window.scrollY/max*100)):100)+'%'}
 window.addEventListener('scroll',function(){if(!ticking){ticking=true;requestAnimationFrame(updateProgress)}},{passive:true});
 window.addEventListener('resize',updateProgress,{passive:true});updateProgress();
 if(!reduced&&'IntersectionObserver' in window){
  var targets=[].slice.call(document.querySelectorAll('.nl-animate-in'));
  var reveal=new IntersectionObserver(function(entries){entries.forEach(function(entry){if(entry.isIntersecting)entry.target.classList.add('nl-visible');else entry.target.classList.remove('nl-visible')})},{threshold:.07});
  targets.forEach(function(el){el.classList.add('nl-prepare');reveal.observe(el)});
 }
 if(!reduced&&window.matchMedia('(hover: hover) and (pointer: fine)').matches){
  [].forEach.call(document.querySelectorAll('.notes-card'),function(card){
   card.addEventListener('pointermove',function(ev){var r=card.getBoundingClientRect();card.style.setProperty('--nl-mouse-x',((ev.clientX-r.left)/r.width*100)+'%');card.style.setProperty('--nl-mouse-y',((ev.clientY-r.top)/r.height*100)+'%')},{passive:true});
  });
 }
 var loader=document.getElementById('nl-page-loader');
 // Show a loader only for real page navigations or submitted forms; browser unload clears it.
 if(loader){
  [].forEach.call(document.querySelectorAll('form'),function(form){form.addEventListener('submit',function(){if(form.checkValidity())loader.classList.add('is-loading')})});
  [].forEach.call(document.querySelectorAll('a.nl-open-note'),function(link){link.addEventListener('click',function(ev){if(ev.button===0&&!ev.metaKey&&!ev.ctrlKey&&!ev.shiftKey&&!ev.altKey)loader.classList.add('is-loading')})});
  window.addEventListener('pageshow',function(){loader.classList.remove('is-loading')});
 }
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',ready);else ready();
})();
