/* ULearn Stage 8 — optional in-site notifications, two tones and desktop alerts. */
(() => {
  'use strict';
  // Remove legacy floating notification UI even if an older copy was cached.
  document.querySelectorAll('#uln-toggle, body > #uln-panel').forEach(el => el.remove());
  // Permanently suppress the legacy floating Alerts UI on all student pages.
  const suppressFloatingAlerts = () => {
    document.querySelectorAll('#uln-toggle, body > #uln-panel').forEach(node => node.remove());
  };
  suppressFloatingAlerts();
  const alertsObserver = new MutationObserver(suppressFloatingAlerts);
  alertsObserver.observe(document.body, {childList:true});
  if (window.__ulearnLiveNotifications) return;
  window.__ulearnLiveNotifications = true;
  const base = document.currentScript?.dataset.base || '/ulearn';
  const feedUrl = base + '/dashboard/notification-feed.php';
  const settingsKey = 'ulearn-notification-preferences-v1';
  const seenKey = 'ulearn-notification-seen-v1';
  const defaults = { sound: false, desktop: false, toasts: true };
  let prefs;
  try { prefs = {...defaults, ...JSON.parse(localStorage.getItem(settingsKey) || '{}')}; }
  catch { prefs = {...defaults}; }
  let seen = Number(sessionStorage.getItem(seenKey) || 0);
  let initialized = seen > 0;
  let announcementSeen = Number(sessionStorage.getItem('ulearn-announcement-seen-v1') || 0);
  let announcementsInitialized = announcementSeen > 0;
  let busy = false;
  let audio;
  const save = () => localStorage.setItem(settingsKey, JSON.stringify(prefs));
  const isMessage = n => /message|chat|reply|kuppiya/i.test(n.type + ' ' + n.title);
  function playSound(message) {
    if (!prefs.sound) return;
    try {
      audio ||= new (window.AudioContext || window.webkitAudioContext)();
      if (audio.state === 'suspended') audio.resume();
      const now = audio.currentTime;
      const notes = message ? [587.33, 739.99] : [523.25, 659.25, 783.99];
      notes.forEach((freq, i) => {
        const osc = audio.createOscillator();
        const gain = audio.createGain();
        osc.type = 'sine'; osc.frequency.value = freq;
        const start = now + i * 0.145;
        gain.gain.setValueAtTime(0.0001, start);
        gain.gain.exponentialRampToValueAtTime(0.018, start + 0.025);
        gain.gain.exponentialRampToValueAtTime(0.0001, start + 0.22);
        osc.connect(gain).connect(audio.destination);
        osc.start(start); osc.stop(start + 0.23);
      });
    } catch (_) { /* Audio may be blocked until user interaction. */ }
  }
  const style = document.createElement('style');
  style.textContent = `
  #uln-toggle{display:none!important;position:fixed;right:22px;bottom:22px;z-index:10000;border:1px solid var(--border,#e7e8f0);border-radius:16px;background:var(--surface,#fff);color:var(--text,#242439);padding:12px 15px;box-shadow:0 10px 35px #16112b20;font:600 13px system-ui;cursor:pointer;display:flex;gap:8px;align-items:center}
  #uln-count{background:#7357dc;color:#fff;border-radius:30px;padding:2px 7px;font-size:11px}
  body > #uln-panel{display:none!important;position:fixed;right:22px;bottom:82px;z-index:10001;width:min(350px,calc(100vw - 28px));background:var(--surface,#fff);color:var(--text,#242439);border:1px solid var(--border,#e7e8f0);border-radius:20px;padding:20px;box-shadow:0 22px 70px #14102d35;font:14px system-ui}
  #uln-panel[hidden]{display:none}#uln-panel h3{font-size:16px;margin:0 0 6px}#uln-panel p{font-size:12px;line-height:1.6;color:var(--muted,#777);margin:0 0 14px}#uln-panel label{display:flex;justify-content:space-between;align-items:center;gap:15px;margin:15px 0;cursor:pointer}#uln-panel input{accent-color:#7357dc;width:17px;height:17px}#uln-panel a{color:var(--accent,#7357dc);text-decoration:none;font-weight:600}
  #uln-settings-host #uln-panel{display:block!important;position:static;width:100%;box-sizing:border-box;box-shadow:none;border:0;border-radius:0;padding:4px 0;background:transparent;color:inherit}
  #uln-settings-host #uln-panel label{font-size:14px}
  #uln-settings-host #uln-panel input{accent-color:#087f5b}
  #uln-toasts{position:fixed;top:18px;right:18px;z-index:10002;display:grid;gap:10px;width:min(340px,calc(100vw - 36px));pointer-events:none} .uln-toast{pointer-events:auto;display:block;text-decoration:none;color:var(--text,#242439);background:var(--surface,#fff);border:1px solid var(--border,#e7e8f0);border-left:3px solid #7357dc;border-radius:13px;padding:14px 16px;box-shadow:0 12px 40px #15102b20;font:13px system-ui}.uln-toast strong{display:block;margin-bottom:5px}.uln-toast span{display:block;color:var(--muted,#777);line-height:1.45}
  @media(max-width:600px){#uln-toggle{right:14px;bottom:14px}#uln-panel{right:14px;bottom:72px}}`;
  document.head.appendChild(style);
  const panel = document.createElement('section');
  panel.id = 'uln-panel'; panel.hidden = true; panel.setAttribute('aria-label', 'Notification preferences');
  panel.innerHTML = `<h3>Notification preferences</h3><p>Choose how ULearn notifies you. Sounds and desktop alerts are optional.</p>
    <label>In-site popups <input id="uln-toasts-check" type="checkbox"></label>
    <label>Notification sounds <input id="uln-sound-check" type="checkbox"></label>
    <label>Desktop notifications <input id="uln-desktop-check" type="checkbox"></label>
    <p id="uln-permission-note"></p><a href="${base}/dashboard/notifications.php">View all notifications →</a>`;
  const toastStack = document.createElement('div'); toastStack.id = 'uln-toasts'; toastStack.setAttribute('aria-live','polite');
  document.body.append(toastStack);
  document.querySelectorAll('body > #uln-panel, #uln-toggle').forEach(el => el.remove());
  // Remove any legacy floating notification launcher left by older scripts.
  document.querySelectorAll('#uln-toggle').forEach(el => el.remove());
  // The notification controls live inside Profile & Settings, not in a floating launcher.
  const isProfilePage = /\/dashboard\/profile\.php$/i.test(location.pathname);
  if (isProfilePage) {
    const settingsHost = document.createElement('section');
    settingsHost.id = 'uln-settings-host';
    settingsHost.style.cssText = 'margin:20px 0;padding:22px;border:1px solid var(--border,#dbe7e2);border-radius:18px;background:var(--surface,#fff);color:var(--text,#13243b)';
    settingsHost.innerHTML = '<h2 style="margin:0 0 8px;font-size:20px">Notification settings</h2><p style="margin:0 0 16px;color:var(--muted,#607287)">Choose which alerts you receive while studying.</p>';
    const intro = document.querySelector('.intro');
    if (intro && intro.parentNode) intro.insertAdjacentElement('afterend', settingsHost);
    else (document.querySelector('main') || document.body).prepend(settingsHost);
    settingsHost.append(panel);
    panel.hidden = false;
    settingsHost.insertAdjacentHTML('beforeend', '<p style="margin:12px 0 0;font-size:13px"><a href="' + base + '/dashboard/notifications.php">Open notification inbox</a></p>');
  } else {
    // Preferences only appear in Profile & Settings. No floating notification UI.
  }
  const checks = {toasts:panel.querySelector('#uln-toasts-check'), sound:panel.querySelector('#uln-sound-check'), desktop:panel.querySelector('#uln-desktop-check')};
  Object.entries(checks).forEach(([key, check]) => {
    check.checked = !!prefs[key];
    check.addEventListener('change', async () => {
      if (key === 'desktop' && check.checked) {
        if (!('Notification' in window) || !window.isSecureContext) { check.checked = false; panel.querySelector('#uln-permission-note').textContent = 'Desktop alerts need a supported browser and HTTPS (or localhost).'; }
        else { const permission = await Notification.requestPermission(); check.checked = permission === 'granted'; panel.querySelector('#uln-permission-note').textContent = check.checked ? 'Desktop alerts enabled.' : 'Browser permission was not granted.'; }
      }
      prefs[key] = check.checked; save();
      if (key === 'sound' && check.checked) playSound(false);
    });
  });
  function toast(title, body, href) {
    if (!prefs.toasts) return;
    const a = document.createElement('a'); a.className = 'uln-toast'; a.href = href || base + '/dashboard/notifications.php';
    const strong = document.createElement('strong'); strong.textContent = title;
    const span = document.createElement('span'); span.textContent = body;
    a.append(strong,span); toastStack.prepend(a);
    setTimeout(() => a.remove(), 6500);
  }
  function desktop(title, body, href) {
    if (!prefs.desktop || !('Notification' in window) || Notification.permission !== 'granted' || !window.isSecureContext) return;
    try { const n = new Notification(title, {body:body.slice(0,180), tag:'ulearn-new-notification'}); n.onclick=()=>{window.focus();window.location.href=href;}; } catch (_) {}
  }
  async function poll() {
    if (busy || document.visibilityState === 'hidden') return;
    busy = true;
    try {
      const res = await fetch(feedUrl, {credentials:'same-origin',cache:'no-store'});
      if (!res.ok) return;
      const data = await res.json();
      const items = Array.isArray(data.items) ? data.items : [];
      const latest = Math.max(seen, ...items.map(n => Number(n.id) || 0));
      if (initialized) {
        const fresh = items.filter(n => Number(n.id) > seen).sort((a,b)=>a.id-b.id).slice(-3);
        for (const n of fresh) { const message = isMessage(n); playSound(message); toast(n.title, n.message, n.url); desktop(n.title,n.message,n.url); }
      }
      seen = latest; initialized = true; sessionStorage.setItem(seenKey, String(seen));
      const announcements = Array.isArray(data.announcements) ? data.announcements : [];
      const latestAnnouncement = Math.max(announcementSeen, ...announcements.map(a => Number(a.id) || 0));
      if (announcementsInitialized) {
        for (const a of announcements.filter(a => Number(a.id) > announcementSeen).slice(-2)) {
          playSound(false);
          toast('ULearn · ' + a.title, a.body, base + '/dashboard/notifications.php');
          desktop('ULearn · ' + a.title, a.body, base + '/dashboard/notifications.php');
        }
      }
      announcementSeen = latestAnnouncement;
      announcementsInitialized = true;
      sessionStorage.setItem('ulearn-announcement-seen-v1', String(announcementSeen));
    } catch (_) { /* Retry on next poll. */ }
    finally { busy = false; }
  }
  poll(); setInterval(poll, 20000);
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') poll(); });
})();
