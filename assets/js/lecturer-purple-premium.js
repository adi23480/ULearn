(() => {
  'use strict';
  const root = document.documentElement;
  // Existing pages keep their own theme preferences and handlers.
  if (!['dark', 'light'].includes(root.dataset.theme)) {
    let preference = 'system';
    try { preference = localStorage.getItem('ulearn-theme') || 'system'; } catch (_) {}
    root.dataset.theme = preference === 'dark' || (preference !== 'light' && matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
  }
  if (typeof window.toggleTheme !== 'function') window.toggleTheme = () => {
    root.dataset.theme = root.dataset.theme === 'dark' ? 'light' : 'dark';
    try { localStorage.setItem('ulearn-theme', root.dataset.theme); } catch (_) {}
  };
  const motion = !matchMedia('(prefers-reduced-motion: reduce)').matches;
  const main = document.querySelector('main, .main, .content');
  if (motion && main) main.classList.add('lp-enter');
  if (motion && 'IntersectionObserver' in window) {
    const observer = new IntersectionObserver(entries => entries.forEach(entry => {
      if (!entry.isIntersecting) return;
      entry.target.classList.add('lp-reveal'); observer.unobserve(entry.target);
    }), {threshold: .04});
    document.querySelectorAll('.module-grid > .card, .stats > *, .cards > .card, .ai-card, .quiz-item, .tool-link').forEach((card, i) => {
      card.style.setProperty('--lp-delay', `${Math.min(i % 4, 3) * 45}ms`); observer.observe(card);
    });
  }
  let progress, timer;
  const reset = () => {
    progress?.remove(); progress = null; clearTimeout(timer);
    document.querySelectorAll('.lp-busy').forEach(node => { node.classList.remove('lp-busy'); node.removeAttribute('aria-busy'); });
  };
  const start = () => {
    if (progress) return;
    progress = document.createElement('div'); progress.className = 'lp-progress'; progress.setAttribute('aria-hidden', 'true'); document.body.append(progress);
    timer = setTimeout(reset, 10000);
  };
  document.addEventListener('click', event => {
    const link = event.target.closest('a[href]');
    if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.altKey || event.shiftKey || link.target || link.hasAttribute('download')) return;
    const url = new URL(link.href, location.href);
    if (url.origin !== location.origin || url.protocol !== location.protocol || url.hash || /(?:download|export)/i.test(url.pathname + url.search)) return;
    start();
  });
  document.addEventListener('submit', event => {
    if (event.defaultPrevented) return;
    const form = event.target;
    if (form.target && form.target !== '_self') return;
    // Keep submit buttons enabled: decision/action names must reach PHP intact.
    if (event.submitter) { event.submitter.classList.add('lp-busy'); event.submitter.setAttribute('aria-busy', 'true'); }
    start();
  });
  window.addEventListener('pageshow', reset);
})();
