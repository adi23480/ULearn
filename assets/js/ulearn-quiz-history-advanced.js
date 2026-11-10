(function(){'use strict';function ready(){var body=document.body,html=document.documentElement;if(!body||!body.classList.contains('qh-page'))return;
var themeBtn=document.getElementById('qh-theme'),themeIcon=document.getElementById('qh-theme-icon'),themeText=document.getElementById('qh-theme-text');
function getStored(){try{return localStorage.getItem('ulearn-theme')}catch(e){return null}}
function setTheme(t,save){t=t==='dark'?'dark':'light';html.dataset.theme=t;body.dataset.qhTheme=t;if(themeIcon)themeIcon.textContent=t==='dark'?'☀':'☾';if(themeText)themeText.textContent=t==='dark'?'Light mode':'Dark mode';if(themeBtn){themeBtn.setAttribute('aria-label',t==='dark'?'Switch to light mode':'Switch to dark mode');themeBtn.setAttribute('aria-pressed',String(t==='dark'))}if(save)try{localStorage.setItem('ulearn-theme',t);localStorage.setItem('ulearn-premium-theme',t)}catch(e){}}
var saved=getStored();if(saved!=='dark'&&saved!=='light')try{saved=localStorage.getItem('ulearn-premium-theme')}catch(e){};setTheme(saved==='dark'||saved==='light'?saved:(html.dataset.theme==='dark'?'dark':'light'),false);if(themeBtn)themeBtn.addEventListener('click',function(){setTheme(body.dataset.qhTheme==='dark'?'light':'dark',true)});
var search=document.getElementById('qh-search'),sort=document.getElementById('qh-sort'),clear=document.getElementById('qh-clear'),container=document.getElementById('qh-module-grid')||document.getElementById('qh-attempts'),empty=document.getElementById('qh-no-match'),counter=document.getElementById('qh-visible-count');
if(container){var cards=Array.from(container.children);cards.forEach(function(card,i){card.dataset.originalIndex=String(i)});
function update(){var q=search?search.value.trim().toLocaleLowerCase():'';var visible=cards.filter(function(c){return(c.dataset.search||'').includes(q)});var mode=sort?sort.value:'recent';visible.sort(function(a,b){if(mode==='name')return(a.dataset.name||'').localeCompare(b.dataset.name||'');if(mode==='attempts'||mode==='quizzes')return Number(b.dataset[mode]||0)-Number(a.dataset[mode]||0);if(mode==='score')return Number(b.dataset.score||0)-Number(a.dataset.score||0);if(mode==='low-score')return Number(a.dataset.score||0)-Number(b.dataset.score||0);var date=(b.dataset.recent||'').localeCompare(a.dataset.recent||'');return date||Number(a.dataset.originalIndex)-Number(b.dataset.originalIndex)});cards.forEach(function(c){c.hidden=true;c.style.display='none'});visible.forEach(function(c){container.appendChild(c);c.hidden=false;c.style.display=''});if(empty)empty.hidden=visible.length!==0;if(counter)counter.textContent=visible.length+' '+(visible.length===1?'module':'modules')}
if(search)search.addEventListener('input',update);if(sort)sort.addEventListener('change',update);if(clear)clear.addEventListener('click',function(){if(search)search.value='';if(sort)sort.value='recent';update();if(search)search.focus()});update();
document.addEventListener('keydown',function(e){var tag=(e.target&&e.target.tagName||'').toLowerCase();if(e.key==='/'&&!e.ctrlKey&&!e.metaKey&&!e.altKey&&tag!=='input'&&tag!=='textarea'&&tag!=='select'){e.preventDefault();if(search)search.focus()}if(e.key==='Escape'&&document.activeElement===search){search.blur()}});
}
// Restore a fully visible page after browser Back/Forward Cache navigation.
// Browsers can freeze the page mid-exit animation and restore that exact frame.
function qhRestorePage(){
  var wipe=document.querySelector('.qh-page-wipe');
  if(wipe){wipe.classList.remove('qh-leaving');wipe.style.opacity='0';wipe.style.pointerEvents='none';}
  document.documentElement.classList.remove('page-leaving','is-leaving','page-exit');
  document.body.classList.remove('page-leaving','is-leaving','page-exit');
  document.body.style.opacity='';document.body.style.filter='';
  document.querySelectorAll('.qh-reveal').forEach(function(el){
    el.classList.add('qh-visible');
  });
  requestAnimationFrame(function(){if(wipe)wipe.style.opacity='';});
}
window.addEventListener('pageshow',qhRestorePage);
window.addEventListener('pagehide',function(){
  var wipe=document.querySelector('.qh-page-wipe');
  if(wipe)wipe.classList.remove('qh-leaving');
});
document.addEventListener('visibilitychange',function(){if(!document.hidden)qhRestorePage();});
var reduce=window.matchMedia&&window.matchMedia('(prefers-reduced-motion: reduce)').matches;
if(!reduce&&'IntersectionObserver'in window){var observer=new IntersectionObserver(function(entries){entries.forEach(function(entry){if(entry.isIntersecting){entry.target.classList.add('qh-visible')}else{entry.target.classList.remove('qh-visible')}})},{threshold:.06,rootMargin:'0px 0px -5% 0px'});document.querySelectorAll('.qh-module,.qh-attempt,.qh-stat,.qh-detail-summary>div').forEach(function(el,i){el.style.setProperty('--qh-from-x',i%2?'24px':'-24px');el.classList.add('qh-reveal');el.style.transition='opacity .65s ease,transform .65s cubic-bezier(.2,.8,.2,1),box-shadow .3s,border-color .3s';observer.observe(el)})}
var progress=document.querySelector('.qh-progress');if(progress){var pending=false;function render(){var max=Math.max(1,document.documentElement.scrollHeight-window.innerHeight);progress.style.width=Math.max(0,Math.min(100,100*window.scrollY/max))+'%';pending=false}function schedule(){if(!pending){pending=true;requestAnimationFrame(render)}}window.addEventListener('scroll',schedule,{passive:true});window.addEventListener('resize',schedule);render()}
if(!reduce){document.querySelectorAll('.qh-module').forEach(function(el){el.addEventListener('pointermove',function(e){if(e.pointerType==='touch')return;var r=el.getBoundingClientRect();el.style.setProperty('--qh-mx',((e.clientX-r.left)/r.width*100)+'%');el.style.setProperty('--qh-my',((e.clientY-r.top)/r.height*100)+'%')})});var wipe=document.querySelector('.qh-page-wipe');document.querySelectorAll('a[href]').forEach(function(link){link.addEventListener('click',function(e){if(e.defaultPrevented||e.button!==0||e.ctrlKey||e.metaKey||e.shiftKey||e.altKey||link.target==='_blank'||link.hasAttribute('download'))return;var dest=new URL(link.href,location.href);if(dest.origin!==location.origin||dest.pathname===location.pathname&&dest.search===location.search)return;if(wipe)wipe.classList.add('qh-leaving')})})}
var stats=document.querySelectorAll('.qh-stat-value[data-count]');if(!reduce&&stats.length&&'IntersectionObserver'in window){var io=new IntersectionObserver(function(entries){entries.forEach(function(entry){if(!entry.isIntersecting)return;io.unobserve(entry.target);var el=entry.target,n=Number(el.dataset.count||0),start=performance.now(),duration=850;function tick(now){var p=Math.min(1,(now-start)/duration),ease=1-Math.pow(1-p,3);el.textContent=Math.round(n*ease).toLocaleString();if(p<1)requestAnimationFrame(tick)}requestAnimationFrame(tick)})});stats.forEach(function(el){io.observe(el)})}
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',ready);else ready();})();