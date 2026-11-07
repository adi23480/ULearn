<?php
/**
 * uLearn Buddy — floating chat bot for the student dashboard.
 * Include just before </body>:  <?php require __DIR__ . '/../includes/student-bot.php'; ?>
 * Uses (if present): $firstName, $enrolledModules, $u
 */
$ubCurrent = me();
if (!$ubCurrent || ($ubCurrent['role'] ?? '') !== 'student') return;
$ubName = isset($firstName) && $firstName !== '' ? (string)$firstName : explode(' ', trim((string)($ubCurrent['full_name'] ?? 'Student')))[0];
$ubUserId = (int)$ubCurrent['id'];
$ubModules = [];
foreach ((isset($enrolledModules) && is_array($enrolledModules)) ? $enrolledModules : [] as $m) {
    $ubModules[] = (string)($m['module_name'] ?? '');
}
$ubModules = array_values(array_filter($ubModules, 'strlen'));
$ubCfg = [
    'endpoint' => url('dashboard/ai-chat.php'),
    'csrf'     => csrf(),
    'name'     => $ubName,
    'userId'   => $ubUserId,
    'modules'  => array_slice($ubModules, 0, 20),
];
?>
<style>
.ub-fab{position:fixed;right:22px;bottom:22px;z-index:9998;width:60px;height:60px;border-radius:50%;border:0;background:linear-gradient(135deg,#087c57,#0d4d72);color:#fff;font-size:28px;cursor:pointer;box-shadow:0 10px 28px rgba(103,86,232,.4);transition:transform .2s}
.ub-fab:hover{transform:scale(1.07)}
.ub-fab .ub-dot{position:absolute;top:4px;right:4px;width:14px;height:14px;border-radius:50%;background:#ff5b6e;border:2px solid var(--surface,#fff);display:none}
.ub-fab.has-new .ub-dot{display:block}
.ub-panel{position:fixed;right:22px;bottom:96px;z-index:9999;width:380px;max-width:calc(100vw - 24px);height:560px;max-height:calc(100vh - 120px);display:none;flex-direction:column;background:var(--surface,#fff);color:var(--text,#171927);border:1px solid var(--border,#e7e8f0);border-radius:20px;box-shadow:0 24px 70px rgba(20,20,50,.28);overflow:hidden;font-family:inherit}
.ub-panel.open{display:flex;animation:ubPop .25s ease both}
@keyframes ubPop{from{opacity:0;transform:translateY(14px) scale(.97)}to{opacity:1;transform:none}}
.ub-head{display:flex;align-items:center;gap:10px;padding:14px 16px;background:linear-gradient(110deg,#087c57,#0d4d72);color:#fff}
.ub-head .ub-av{width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,.2);display:grid;place-items:center;font-size:20px}
.ub-head .ub-t{flex:1;line-height:1.2}.ub-head .ub-t b{display:block;font-size:15px}.ub-head .ub-t span{font-size:11px;opacity:.85}
.ub-head button{background:rgba(255,255,255,.18);border:0;color:#fff;width:30px;height:30px;border-radius:9px;cursor:pointer;font-size:15px}
.ub-head button:hover{background:rgba(255,255,255,.3)}
.ub-msgs{flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:10px;background:var(--bg,#f7f8fc)}
.ub-m{max-width:86%;padding:10px 13px;border-radius:16px;font-size:14px;line-height:1.55;word-wrap:break-word;overflow-wrap:anywhere}
.ub-m.bot{align-self:flex-start;background:var(--surface-alt,#f1f2f8);border:1px solid var(--border,#e7e8f0);border-bottom-left-radius:5px}
.ub-m.user{align-self:flex-end;background:linear-gradient(110deg,#087c57,#0d4d72);color:#fff;border-bottom-right-radius:5px;white-space:pre-wrap}
.ub-m.err{align-self:flex-start;background:var(--red-bg,#fff0f2);color:var(--red,#b4233c);border:1px solid var(--red,#b4233c)}
.ub-m ul,.ub-m ol{margin:6px 0 6px 18px;padding:0}.ub-m li{margin:2px 0}
.ub-m code{background:rgba(120,120,160,.18);padding:1px 5px;border-radius:5px;font-size:12.5px}
.ub-m pre{background:#14161f;color:#e8e8f4;padding:10px 12px;border-radius:10px;overflow-x:auto;margin:8px 0;font-size:12.5px}
.ub-m pre code{background:none;padding:0;color:inherit}
.ub-gap{height:6px}
.ub-chips{display:flex;flex-wrap:wrap;gap:8px;align-self:flex-start;max-width:96%}
.ub-chip{border:1px solid var(--accent,#087c57);background:var(--accent-light,#e8f7f0);color:var(--accent,#087c57);padding:7px 12px;border-radius:999px;font-size:12.5px;font-weight:700;cursor:pointer;font-family:inherit}
.ub-chip:hover{background:var(--accent,#087c57);color:#fff}
.ub-typing{display:none;align-self:flex-start;gap:4px;padding:12px 14px;background:var(--surface-alt,#f1f2f8);border:1px solid var(--border,#e7e8f0);border-radius:16px;border-bottom-left-radius:5px}
.ub-typing.on{display:flex}
.ub-typing i{width:7px;height:7px;border-radius:50%;background:var(--muted,#777b90);animation:ubB 1.2s infinite}
.ub-typing i:nth-child(2){animation-delay:.15s}.ub-typing i:nth-child(3){animation-delay:.3s}
@keyframes ubB{0%,60%,100%{transform:translateY(0);opacity:.5}30%{transform:translateY(-5px);opacity:1}}
.ub-form{display:flex;gap:8px;padding:12px;border-top:1px solid var(--border,#e7e8f0);background:var(--surface,#fff)}
.ub-form textarea{flex:1;resize:none;height:42px;max-height:110px;padding:10px 12px;border:1px solid var(--border,#e7e8f0);border-radius:12px;background:var(--surface-alt,#f1f2f8);color:var(--text,#171927);font:inherit;font-size:14px;outline:none}
.ub-form textarea:focus{border-color:var(--accent,#087c57)}
.ub-form button{width:44px;border:0;border-radius:12px;background:linear-gradient(110deg,#087c57,#0d4d72);color:#fff;font-size:18px;cursor:pointer}
.ub-form button:disabled{opacity:.5;cursor:wait}
@media(max-width:520px){.ub-panel{right:8px;left:8px;bottom:88px;width:auto;max-width:none}.ub-fab{right:14px;bottom:14px}}
@media(prefers-reduced-motion:reduce){.ub-panel.open{animation:none}.ub-typing i{animation:none}}

/* Premium animated Buddy launcher: sole floating control. */
.ub-fab{right:164px;bottom:22px;width:62px;height:62px;display:grid;place-items:center;isolation:isolate;background:linear-gradient(145deg,#0ab784 0%,#087c57 46%,#064a69 100%);box-shadow:0 12px 30px rgba(0,105,79,.27),inset 0 1px 1px rgba(255,255,255,.35);border:1px solid rgba(255,255,255,.22);transition:transform .25s ease,box-shadow .25s ease}
.ub-fab::before{content:"";position:absolute;inset:-5px;border:1px solid rgba(9,160,117,.38);border-radius:50%;animation:ubHalo 3.8s ease-in-out infinite;pointer-events:none}
.ub-fab:hover{transform:translateY(-4px) scale(1.05);box-shadow:0 18px 38px rgba(0,105,79,.35),inset 0 1px 1px rgba(255,255,255,.35)}
.ub-fab .ub-mark{width:33px;height:33px;display:grid;place-items:center;transform-style:preserve-3d;animation:ubFlip 6s cubic-bezier(.4,0,.2,1) infinite}
.ub-fab .ub-mark svg{width:33px;height:33px;stroke:#fff;stroke-width:1.75;fill:none;stroke-linecap:round;stroke-linejoin:round;filter:drop-shadow(0 1px 2px rgba(0,0,0,.18))}
.ub-fab .ub-dot{background:#a8f5d7;box-shadow:0 0 0 3px rgba(9,115,83,.25)}
.ub-panel{z-index:10005}
.ub-fab{z-index:10004}
@keyframes ubFlip{0%,73%,100%{transform:rotateY(0)}83%{transform:rotateY(180deg)}93%{transform:rotateY(360deg)}}
@keyframes ubHalo{0%,100%{opacity:.5;transform:scale(.98)}50%{opacity:1;transform:scale(1.08)}}
@media(max-width:600px){.ub-fab{right:154px;bottom:14px;width:56px;height:56px}.ub-panel{right:8px;left:8px;bottom:82px;max-height:calc(100dvh - 100px)}}
@media(prefers-reduced-motion:reduce){.ub-fab .ub-mark,.ub-fab::before{animation:none}}
/* Notifications now live in Profile & Settings: Buddy is the sole floating launcher. */
.ub-fab{right:22px;bottom:22px}
.ub-panel{right:22px;bottom:96px}
@media(max-width:600px){.ub-fab{right:14px;bottom:14px}.ub-panel{right:8px;left:8px;bottom:82px}}
/* Unified ULearn palette and accessible dark theme */
.ub-panel{--surface:#fff;--surface-alt:#f2f7f5;--bg:#f8fafc;--text:#13243b;--border:#dbe7e2;--muted:#607287;--accent:#087c57;--accent-light:#e8f7f0}
html[data-theme="dark"] .ub-panel,body.dark .ub-panel,body[data-theme="dark"] .ub-panel{--surface:#142238;--surface-alt:#20324b;--bg:#101b2d;--text:#edf5f8;--border:#33475e;--muted:#a6b9cb;--accent:#63dbab;--accent-light:#1b4039}
.ub-panel{background:var(--surface);color:var(--text);border-color:var(--border)}
.ub-msgs{background:var(--bg)}.ub-m.bot{background:var(--surface-alt);border-color:var(--border)}
.ub-form{background:var(--surface);border-color:var(--border)}.ub-form textarea{background:var(--surface-alt);color:var(--text);border-color:var(--border)}
.ub-fab:focus-visible,.ub-head button:focus-visible,.ub-form button:focus-visible,.ub-form textarea:focus-visible{outline:3px solid #7ee1b6;outline-offset:2px}
</style>

<button type="button" class="ub-fab" id="ubFab" aria-label="Open uLearn Buddy chat" aria-expanded="false"><span class="ub-mark" aria-hidden="true"><svg viewBox="0 0 32 32"><rect x="5" y="8" width="22" height="18" rx="7"/><path d="M11 8V5m10 3V5M10 26l-2 3m14-3 2 3"/><circle cx="12" cy="16" r="1.5" fill="white" stroke="none"/><circle cx="20" cy="16" r="1.5" fill="white" stroke="none"/><path d="M12 21q4 3 8 0"/></svg></span><span class="ub-dot"></span></button>

<section class="ub-panel" id="ubPanel" role="dialog" aria-label="uLearn Buddy chat">
    <div class="ub-head">
        <div class="ub-av">✦</div>
        <div class="ub-t"><b>uLearn Buddy</b><span>Your AI study helper</span></div>
        <button type="button" id="ubReset" title="New chat" aria-label="Start a new chat">↺</button>
        <button type="button" id="ubClose" title="Close" aria-label="Close chat">✕</button>
    </div>
    <div class="ub-msgs" id="ubMsgs" aria-live="polite">
        <div class="ub-typing" id="ubTyping"><i></i><i></i><i></i></div>
    </div>
    <div class="ub-form">
        <textarea id="ubInput" rows="1" placeholder="Type what you want to learn…" maxlength="2000"></textarea>
        <button type="button" id="ubSend" aria-label="Send">➤</button>
    </div>
</section>

<script>
(function () {
    var CFG = <?= json_encode($ubCfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
    var KEY = 'ulearn-buddy-chat-' + CFG.userId;
    var SEEN = 'ulearn-buddy-greeted-' + CFG.userId;

    var fab = document.getElementById('ubFab'), panel = document.getElementById('ubPanel');
    var msgsEl = document.getElementById('ubMsgs'), typing = document.getElementById('ubTyping');
    var input = document.getElementById('ubInput'), sendBtn = document.getElementById('ubSend');
    var chat = [];      // [{role:'bot'|'user', text:'...'}]
    var busy = false;

    function load() { try { chat = JSON.parse(sessionStorage.getItem(KEY) || '[]') || []; } catch (e) { chat = []; } }
    function save() { try { sessionStorage.setItem(KEY, JSON.stringify(chat.slice(-40))); } catch (e) {} }

    function esc(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }

    // Tiny, safe markdown: code blocks, inline code, bold, lists.
    function md(text) {
        var t = esc(text), blocks = [];
        t = t.replace(/```(?:\w+)?\n?([\s\S]*?)```/g, function (_, c) { blocks.push(c.replace(/\n$/, '')); return '\u0000' + (blocks.length - 1) + '\u0000'; });
        t = t.replace(/`([^`\n]+)`/g, '<code>$1</code>');
        t = t.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
        var html = '', list = null;
        function close() { if (list) { html += '</' + list + '>'; list = null; } }
        t.split('\n').forEach(function (l) {
            var b = l.match(/^\s*[-*\u2022]\s+(.*)$/), o = l.match(/^\s*\d+[.)]\s+(.*)$/);
            if (b) { if (list !== 'ul') { close(); html += '<ul>'; list = 'ul'; } html += '<li>' + b[1] + '</li>'; }
            else if (o) { if (list !== 'ol') { close(); html += '<ol>'; list = 'ol'; } html += '<li>' + o[1] + '</li>'; }
            else { close(); html += l.trim() === '' ? '<div class="ub-gap"></div>' : '<div>' + l + '</div>'; }
        });
        close();
        return html.replace(/\u0000(\d+)\u0000/g, function (_, i) { return '<pre><code>' + blocks[+i] + '</code></pre>'; });
    }

    function scrollDown() { msgsEl.scrollTop = msgsEl.scrollHeight; }

    function addBubble(role, text, cls) {
        var d = document.createElement('div');
        d.className = 'ub-m ' + (cls || role);
        if (role === 'bot') d.innerHTML = md(text); else d.textContent = text;
        msgsEl.insertBefore(d, typing);
        scrollDown();
    }

    function greeting() {
        var h = new Date().getHours();
        var part = h < 12 ? 'Good morning' : (h < 18 ? 'Good afternoon' : 'Good evening');
        return 'Hey ' + CFG.name + '! \uD83D\uDC4B ' + part + '! How\u2019s your day going?\n\nWhat would you like to learn today? Pick a topic below or just tell me.';
    }

    function addChips() {
        var old = document.getElementById('ubChips'); if (old) old.remove();
        var opts = [];
        CFG.modules.slice(0, 3).forEach(function (m) { opts.push('Help me understand ' + m); });
        opts.push('Quiz me on something', 'Make me a study plan');
        var wrap = document.createElement('div'); wrap.className = 'ub-chips'; wrap.id = 'ubChips';
        opts.forEach(function (o) {
            var b = document.createElement('button'); b.type = 'button'; b.className = 'ub-chip'; b.textContent = o;
            b.onclick = function () { send(o); };
            wrap.appendChild(b);
        });
        msgsEl.insertBefore(wrap, typing); scrollDown();
    }

    function render() {
        Array.prototype.slice.call(msgsEl.querySelectorAll('.ub-m,.ub-chips')).forEach(function (n) { n.remove(); });
        chat.forEach(function (m) { addBubble(m.role, m.text); });
        if (chat.length <= 1) addChips();
    }

    function openPanel() {
        var notificationPanel = document.getElementById('uln-panel');
        var notificationToggle = document.getElementById('uln-toggle');
        if (notificationPanel) notificationPanel.hidden = true;
        if (notificationToggle) notificationToggle.setAttribute('aria-expanded', 'false');
        fab.setAttribute('aria-expanded', 'true');
        panel.classList.add('open'); fab.classList.remove('has-new');
        setTimeout(function () { input.focus(); scrollDown(); }, 60);
    }
    function closePanel() { panel.classList.remove('open'); fab.setAttribute('aria-expanded', 'false'); }

    function start() {
        chat = [{ role: 'bot', text: greeting() }];
        save(); render();
    }

    async function send(text) {
        text = (text || '').trim();
        if (!text || busy) return;
        busy = true; sendBtn.disabled = true;
        var chips = document.getElementById('ubChips'); if (chips) chips.remove();

        // history sent to the server = everything before this message, minus the canned greeting
        var history = chat.slice(1).map(function (m) { return { role: m.role === 'user' ? 'user' : 'bot', text: m.text }; });

        chat.push({ role: 'user', text: text }); save();
        addBubble('user', text);
        input.value = ''; input.style.height = '42px';
        typing.classList.add('on'); scrollDown();

        try {
            var res = await fetch(CFG.endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ csrf: CFG.csrf, message: text, history: history, modules: CFG.modules })
            });
            var data;
            try { data = await res.json(); } catch (e) { throw new Error('Server returned an unexpected response (HTTP ' + res.status + ').'); }
            if (data.success) {
                chat.push({ role: 'bot', text: data.reply }); save();
                addBubble('bot', data.reply);
            } else {
                addBubble('bot', (data.message || 'Something went wrong.') + (data.debug ? '\n\n' + data.debug : ''), 'err');
                console.warn('uLearn Buddy:', data);
            }
        } catch (e) {
            addBubble('bot', 'Network problem: ' + e.message, 'err');
        } finally {
            typing.classList.remove('on'); busy = false; sendBtn.disabled = false; input.focus(); scrollDown();
        }
    }

    fab.addEventListener('click', function () { panel.classList.contains('open') ? closePanel() : openPanel(); });
    // Notifications are injected by another script. Event delegation works regardless of load order.
    document.addEventListener('click', function (event) {
        if (event.target.closest && event.target.closest('#uln-toggle')) closePanel();
    });
    document.getElementById('ubClose').addEventListener('click', closePanel);
    document.getElementById('ubReset').addEventListener('click', function () { start(); input.focus(); });
    sendBtn.addEventListener('click', function () { send(input.value); });
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(input.value); }
        if (e.key === 'Escape') closePanel();
    });
    input.addEventListener('input', function () { this.style.height = '42px'; this.style.height = Math.min(this.scrollHeight, 110) + 'px'; });

    // ---- startup ----
    load();
    if (!chat.length) start(); else render();

    // Avoid interrupting students on every page; show a notification dot instead.
    var greeted = false;
    try { greeted = sessionStorage.getItem(SEEN) === '1'; } catch (e) {}
    if (!greeted) {
        try { sessionStorage.setItem(SEEN, '1'); } catch (e) {}
        fab.classList.add('has-new');
    } else if (chat.length <= 1) {
        fab.classList.add('has-new');
    }
})();
</script>