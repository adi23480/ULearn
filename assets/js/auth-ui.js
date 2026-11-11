(() => {
  const theme = document.querySelector('.theme-toggle');
  theme?.addEventListener('click', () => {
    const next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
    document.documentElement.dataset.theme = next;
    try { localStorage.setItem('ulearn-theme', next); } catch (_) {}
  });
  document.querySelectorAll('input[type="password"]').forEach(input => {
    const wrap = document.createElement('div'); wrap.className = 'password-wrap';
    input.parentNode.insertBefore(wrap, input); wrap.append(input);
    const toggle = document.createElement('button'); toggle.type = 'button';
    toggle.className = 'password-toggle'; toggle.textContent = 'Show';
    toggle.setAttribute('aria-label', 'Show password'); toggle.setAttribute('aria-pressed', 'false');
    toggle.addEventListener('click', () => {
      const show = input.type === 'password'; input.type = show ? 'text' : 'password';
      toggle.textContent = show ? 'Hide' : 'Show'; toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password'); toggle.setAttribute('aria-pressed', String(show));
    }); wrap.append(toggle);
  });
  document.querySelectorAll('form').forEach(form => form.addEventListener('submit', event => {
    if (event.defaultPrevented || !form.checkValidity()) return;
    const button = event.submitter || form.querySelector('[type="submit"]');
    if (button) { button.dataset.originalLabel = button.textContent; button.textContent = 'Please wait…'; button.classList.add('is-loading'); button.disabled = true; }
    form.setAttribute('aria-busy', 'true');
  }));
  document.querySelectorAll('a[href]').forEach(link => link.addEventListener('click', event => {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || link.target || link.hasAttribute('download')) return;
    const target = new URL(link.href); if (target.origin !== location.origin || target.hash) return;
    document.body.classList.add('leaving');
  }));
  window.addEventListener('pageshow', () => {
    document.body.classList.remove('leaving');
    document.querySelectorAll('form').forEach(form => form.removeAttribute('aria-busy'));
    document.querySelectorAll('.is-loading').forEach(button => { button.textContent = button.dataset.originalLabel; button.disabled = false; button.classList.remove('is-loading'); });
  });
})();
