/* ULearn shared motion; navigation progress is visual only, never intercepts routing. */
(()=>{'use strict';
 const start=()=>{document.body.classList.add('ul-ready');
 const bar=document.createElement('div');bar.className='ul-page-progress';bar.setAttribute('aria-hidden','true');document.body.appendChild(bar);
 document.addEventListener('click',e=>{
  const a=e.target.closest('a[href]');if(!a||e.defaultPrevented||e.button!==0||e.metaKey||e.ctrlKey||e.altKey||e.shiftKey||a.hasAttribute('download')||a.target==='_blank')return;
  const href=a.getAttribute('href');if(!href||href.startsWith('#')||href.startsWith('javascript:')||href.startsWith('mailto:')||href.startsWith('tel:'))return;
  let dest;try{dest=new URL(a.href,location.href)}catch(_){return}
  if(dest.origin!==location.origin||dest.pathname===location.pathname&&dest.search===location.search&&dest.hash)return;
  bar.classList.remove('ul-complete');bar.classList.add('ul-loading');
 },{capture:true});
 window.addEventListener('pageshow',()=>{bar.classList.remove('ul-loading');bar.classList.add('ul-complete')});
 };
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});else start();
})();
