/* V5.1: repeat left/right entrance on every re-entry, no one-shot unobserve. */
(function(){'use strict';
function init(){
 if(!('IntersectionObserver' in window) || (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches))return;
 var selector='.ul-overview-tile, .ul-launchpad-grid > a, .ul-identity, #academic > .panel, .module-card, .feature, .section-heading, .empty, #curriculum > .panel';
 var elements=Array.prototype.slice.call(document.querySelectorAll(selector));if(!elements.length)return;
 var lastY=window.scrollY||0, down=true;
 window.addEventListener('scroll',function(){var y=window.scrollY||0;if(Math.abs(y-lastY)>2)down=y>lastY;lastY=y;},{passive:true});
 var obs=new IntersectionObserver(function(entries){entries.forEach(function(entry){
  var el=entry.target;
  if(entry.isIntersecting){
   // In-view on load stays visible; later visits animate from the relevant side.
   if(el.dataset.ulEntered==='1')el.style.setProperty('--ul-v5-x',(Number(el.dataset.ulSide)*(down?1:-1)*88)+'px');
   requestAnimationFrame(function(){requestAnimationFrame(function(){el.classList.add('ul-v5-visible');el.dataset.ulEntered='1';});});
  }else{
   // Reset as soon as the element leaves the viewport, ready for the next pass.
   el.classList.remove('ul-v5-visible');el.style.setProperty('--ul-v5-delay','0ms');
  }
 });},{threshold:0.08,rootMargin:'0px 0px -12px 0px'});
 elements.forEach(function(el,i){var n=Array.prototype.indexOf.call(el.parentElement.children,el);var side=(n%2===0)?-1:1;
  el.dataset.ulSide=String(side);el.style.setProperty('--ul-v5-x',(side*88)+'px');
  el.style.setProperty('--ul-v5-delay',Math.min(n,3)*55+'ms');el.classList.add('ul-v5-reveal');
  if(el.getBoundingClientRect().top<innerHeight-15 && el.getBoundingClientRect().bottom>0){el.classList.add('ul-v5-visible');el.dataset.ulEntered='1';}
 });
 document.documentElement.classList.remove('ul-v5-motion');document.documentElement.classList.add('ul-v51-motion');
 elements.forEach(function(el){obs.observe(el);});
 window.addEventListener('pageshow',function(){elements.forEach(function(el){var r=el.getBoundingClientRect();if(r.top<innerHeight&&r.bottom>0)el.classList.add('ul-v5-visible');});});
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
