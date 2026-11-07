/* ULearn unified appearance preference — Light, Dark, System. */
(()=>{'use strict';
const key='ulearn-theme-preference';
const root=document.documentElement;
const media=window.matchMedia('(prefers-color-scheme: dark)');
const valid=v=>['light','dark','system'].includes(v);
const read=()=>{try{const v=localStorage.getItem(key);return valid(v)?v:'system'}catch(_){return 'system'}};
let pref=read();
const resolved=()=>pref==='system'?(media.matches?'dark':'light'):pref;
let applying=false;
function apply(){applying=true;root.setAttribute('data-theme',resolved());root.style.colorScheme=resolved();applying=false;}
function choose(v){if(!valid(v))return;pref=v;try{localStorage.setItem(key,v);localStorage.setItem('ulearn-theme',resolved())}catch(_){}apply();}
apply();
// Legacy page scripts can set a stale theme after loading; reconcile it without changing the saved preference.
const observer=new MutationObserver(()=>{if(!applying&&root.dataset.theme!==resolved())apply()});
observer.observe(root,{attributes:true,attributeFilter:['data-theme']});
media.addEventListener?.('change',()=>{if(pref==='system')apply()});
window.addEventListener('storage',e=>{if(e.key===key){pref=read();apply()}});
// Existing toggle buttons work across all workspaces. Capture the intended new preference
// before legacy handlers run, then reconcile after their click handlers have finished.
document.addEventListener('click',e=>{
 const btn=e.target.closest('#themeToggle,#theme-toggle');
 if(!btn)return;
 const next=resolved()==='dark'?'light':'dark';
 queueMicrotask(()=>choose(next));
},true);
// Profile radio choices are authoritative; System remains System (not a frozen dark/light value).
document.addEventListener('change',e=>{
 const t=e.target;if(t.matches('input[name="theme"]')&&valid(t.value))choose(t.value);
},true);
document.addEventListener('submit',e=>{
 const selected=e.target.querySelector('input[name="theme"]:checked');
 if(selected&&valid(selected.value))choose(selected.value);
},true);
// Restore the correct theme after inline legacy initialization scripts.
document.addEventListener('DOMContentLoaded',apply);
window.ULearnTheme={getPreference:()=>pref,setPreference:choose};
})();