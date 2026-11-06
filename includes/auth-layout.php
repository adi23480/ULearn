<?php
// Set $title and $content before including this file.
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= e($title) ?> — U Learn</title><script>try{let t=localStorage.getItem('ulearn-theme');document.documentElement.dataset.theme=t==='dark'||t==='light'?t:(matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light')}catch(e){document.documentElement.dataset.theme='light'}</script><link rel="stylesheet" href="<?= e(url('assets/css/auth.css')) ?>?v=20261102-eye-fix2"></head><body><header class="top"><a class="brand" href="<?= e(url()) ?>"><span class="mark">∕∕</span> u learn<span class="dot">.</span></a><a class="back" href="<?= e(url()) ?>">← Back to website</a></header><main class="shell"><aside class="intro"><div class="kicker">A SPACE TO GO FURTHER</div><h1>Make room for<br><em>what's next.</em></h1><p>Your modules, practice and people. One considered space to learn better, together.</p><div class="accent-line"></div><div class="foot">BUILT FOR THE WAY YOU LEARN.</div></aside><section class="panel"><?= $content ?></section></main>
<script>
/*
 * U LEARN shared password visibility handler.
 * Event delegation means this works on both login and registration pages,
 * without depending on DOMContentLoaded timing or page-specific scripts.
 */
document.addEventListener('click', function (event) {
    const button = event.target.closest('button[data-password-toggle]');
    if (!button) return;

    event.preventDefault();

    const inputId = button.getAttribute('data-password-toggle');
    const passwordInput = document.getElementById(inputId);

    if (!passwordInput) return;

    const shouldShow = passwordInput.type === 'password';
    passwordInput.type = shouldShow ? 'text' : 'password';

    button.setAttribute('aria-pressed', shouldShow ? 'true' : 'false');
    button.setAttribute(
        'aria-label',
        shouldShow ? 'Hide password' : 'Show password'
    );

    button.innerHTML = shouldShow
        ? '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3l18 18"></path><path d="M10.6 10.6a2 2 0 002.8 2.8"></path><path d="M9.9 5.2A11 11 0 0112 5c6.4 0 10 7 10 7a13 13 0 01-3 3.8"></path><path d="M6.2 6.2C3.5 8 2 12 2 12s3.6 7 10 7c1.2 0 2.3-.2 3.3-.6"></path></svg>'
        : '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7z"></path><circle cx="12" cy="12" r="3"></circle></svg>';
});
</script>
<script src="<?= e(url('assets/js/theme.js')) ?>" defer></script></body></html>
