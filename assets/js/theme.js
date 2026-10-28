(() => {
  const root = document.documentElement;
  const button = document.getElementById('theme-toggle');
  if (button) {
    const updateLabel = () => {
      const isDark = root.dataset.theme === 'dark';
      button.setAttribute('aria-label', isDark ? 'Switch to light theme' : 'Switch to dark theme');
      button.title = isDark ? 'Switch to light theme' : 'Switch to dark theme';
    };
    updateLabel();
    button.addEventListener('click', () => {
      root.dataset.theme = root.dataset.theme === 'dark' ? 'light' : 'dark';
      try { localStorage.setItem('ulearn-theme', root.dataset.theme); } catch (e) {}
      updateLabel();
    });
  }
  // Reading progress indicator, throttled by the browser.
  const progress = document.getElementById('scroll-progress');
  let scrollScheduled = false;
  const updateProgress = () => {
    const max = document.documentElement.scrollHeight - window.innerHeight;
    if (progress) progress.style.width = `${max > 0 ? (window.scrollY / max) * 100 : 0}%`;
    scrollScheduled = false;
  };
  window.addEventListener('scroll', () => {
    if (!scrollScheduled) { scrollScheduled = true; requestAnimationFrame(updateProgress); }
  }, { passive: true });
  window.addEventListener('resize', updateProgress);
  updateProgress();

  // Interactive story panel: actual accessible controls, no external animation library.
  const scenes = {
    modules: { index: '01 — 03', label: 'YOUR LIBRARY', title: 'Everything in<br>its place.', caption: 'A clearer path through every module.', bottom: 'DISCOVER / LEARN / REPEAT', width: '68%' },
    quiz: { index: '02 — 03', label: 'QUIZ ARENA', title: 'See how far<br>you’ve come.', caption: 'Turn every attempt into insight.', bottom: 'PRACTICE / REFLECT / IMPROVE', width: '86%' },
    peer: { index: '03 — 03', label: 'GROUP PEER', title: 'Better when<br>we connect.', caption: 'Learn with people who get it.', bottom: 'MEET / SHARE / GROW', width: '94%' }
  };
  const stage = document.getElementById('story-stage');
  document.querySelectorAll('.story-tab').forEach(tab => {
    tab.addEventListener('click', () => {
      const scene = scenes[tab.dataset.scene];
      if (!scene || !stage) return;
      stage.dataset.scene = tab.dataset.scene;
      document.querySelectorAll('.story-tab').forEach(item => {
        const active = item === tab;
        item.classList.toggle('is-active', active);
        item.setAttribute('aria-pressed', String(active));
      });
      document.getElementById('stage-index').textContent = scene.index;
      document.getElementById('stage-label').textContent = scene.label;
      document.getElementById('stage-title').innerHTML = scene.title;
      document.getElementById('stage-caption').textContent = scene.caption;
      document.getElementById('stage-bottom-text').textContent = scene.bottom;
      document.getElementById('stage-meter').style.width = scene.width;
    });
  });

  const elements = document.querySelectorAll('.reveal:not(.is-visible)');
  if ('IntersectionObserver' in window) {
    const observer = new IntersectionObserver(entries => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          entry.target.classList.add('is-visible');
          observer.unobserve(entry.target);
        }
      });
    }, { threshold: 0.12 });
    elements.forEach(element => observer.observe(element));
  } else {
    elements.forEach(element => element.classList.add('is-visible'));
  }
})();
