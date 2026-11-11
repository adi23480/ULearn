<?php
// Shared authentication presentation. Callers supply $title and $content.
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title) ?> — ULearn</title>
<script>try{const t=localStorage.getItem('ulearn-theme');document.documentElement.dataset.theme=t==='light'||t==='dark'?t:(matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light')}catch(e){document.documentElement.dataset.theme='light'}</script>
<link rel="stylesheet" href="<?= e(url('assets/css/auth.css')) ?>?v=20261111">
</head><body>
<header class="top"><div class="brand" aria-label="ULearn"><span>U</span>Learn<span>.</span></div><button type="button" class="theme-toggle" aria-label="Switch colour theme">◐</button></header>
<main class="shell"><aside class="intro"><div class="orb" aria-hidden="true"></div><div class="kicker"><i></i> YOUR PERSONAL LEARNING SPACE</div><h1>A better way<br>to learn.<br><em>Every day.</em></h1><p>Your modules, practice and people.<br>One space to move forward, together.</p><div class="intro-tags"><span>Learn at your pace</span><span>Build your future</span></div><div class="foot">ULEARN · YOUR NEXT CHAPTER STARTS HERE</div></aside>
<section class="panel" aria-label="<?= e($title) ?>"><div class="eyebrow">WELCOME TO YOUR WORKSPACE</div><?= $content ?></section></main>
<footer class="page-foot">A little progress, every day.</footer>
<script src="<?= e(url('assets/js/auth-ui.js')) ?>?v=20261111" defer></script></body></html>
