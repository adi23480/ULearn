adhithya lokuda???

<?php
declare(strict_types=1);
$projectName = 'U Learn';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#f8f9fc">
  <meta name="description" content="U Learn brings your courses, practice and study community together.">
  <title><?= htmlspecialchars($projectName, ENT_QUOTES, 'UTF-8') ?> — A better way to learn together</title>
  <script>try{const t=localStorage.getItem('ulearn-theme');document.documentElement.dataset.theme=t==='dark'||t==='light'?t:(matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light')}catch(e){document.documentElement.dataset.theme='light'}</script>
  <link rel="stylesheet" href="assets/css/style.css">
  <script src="assets/js/theme.js" defer></script>
</head>
<body>
  <div class="scroll-progress" id="scroll-progress" aria-hidden="true"></div>
  <header class="site-header">
    <nav class="nav container" aria-label="Main navigation">
      <a class="brand" href="./" aria-label="U Learn home"><span class="brand-mark" aria-hidden="true"><span></span><span></span><span></span></span><span>u learn<span class="brand-period">.</span></span></a>
      <div class="nav-links"><a href="#platform">Platform</a><a href="#experience">Experience</a><a href="#community">Community</a></div>
      <div class="nav-actions"><button class="theme-button" id="theme-toggle" type="button" aria-label="Switch color theme" title="Switch color theme"><svg class="sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42M2 12h2m16 0h2M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42"/></svg><svg class="moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><path d="M20.5 14.3A8.5 8.5 0 0 1 9.7 3.5 8.5 8.5 0 1 0 20.5 14.3Z"/></svg></button><a class="nav-login" href="auth/login.php">Log in</a><a class="nav-cta" href="auth/register.php">Get started <span aria-hidden="true">↗</span></a></div>
    </nav>
  </header>
  <main>
    <section class="hero container" id="top" aria-labelledby="hero-title">
      <div class="hero-copy reveal is-visible"><div class="eyebrow"><span class="eyebrow-dot"></span> A NEW WAY TO LEARN / BUILT FOR STUDENTS</div><h1 id="hero-title">Learning looks<br><em>different here.</em></h1><p class="hero-subtitle">The space between studying harder and understanding more. Your modules, practice, and people — all in one considered experience.</p><div class="hero-actions"><a class="button button-primary" href="auth/register.php">Get started <span aria-hidden="true">↗</span></a><a class="text-link" href="#experience">See how it works <span aria-hidden="true">↓</span></a></div><div class="hero-footnote"><span class="line"></span> DESIGNED FOR THE NEXT GENERATION OF LEARNERS</div></div>
      <div class="hero-art reveal is-visible" aria-label="Illustrative preview of U Learn student dashboard"><div class="orbit orbit-one"></div><div class="orbit orbit-two"></div><div class="float-label float-one"><span class="mini-symbol">✦</span> Progress, in focus</div><div class="float-label float-two"><span class="status-pulse"></span> Your study space</div>
        <div class="mock-window"><div class="mock-top"><div class="mock-brand"><span class="mini-mark">u.</span> u learn</div><span class="mock-search">⌕ &nbsp; Search your learning space</span><span class="mock-avatar">JD</span></div><div class="mock-content"><div class="mock-sidebar"><span class="side-active">◫ <span>Overview</span></span><span>▤ <span>My modules</span></span><span>◇ <span>Quiz arena</span></span><span>♧ <span>Group peer</span></span><div class="side-bottom">YOUR SPACE<br><strong>Keep exploring →</strong></div></div><div class="mock-main"><div class="mock-kicker">MONDAY, OCTOBER 20</div><div class="mock-heading">Good morning, Jordan<span class="heading-period">.</span></div><div class="mock-caption">A little progress every day adds up.</div><div class="stat-grid"><div class="stat-card"><small>Learning streak</small><strong>07 <span>days</span></strong><div class="tiny-bars"><i></i><i></i><i></i><i></i><i></i><i></i><i></i></div></div><div class="stat-card stat-accent"><small>Quiz accuracy</small><strong>86<span>%</span></strong><div class="stat-trend">↗ &nbsp; +12% this month</div></div></div><div class="section-label">CONTINUE LEARNING <span>View all →</span></div><div class="course-row"><div class="course-icon">{ }</div><div><strong>Programming Fundamentals</strong><small>Module 01 · 8 of 12 topics</small></div><span class="course-arrow">↗</span></div><div class="course-row"><div class="course-icon second">▦</div><div><strong>Database Systems</strong><small>Module 02 · 4 of 10 topics</small></div><span class="course-arrow">↗</span></div></div></div></div>
      </div>
    </section>
    <section class="trust-strip" aria-label="Learning approach"><div class="container trust-inner"><span>YOUR CAMPUS. YOUR PACE. YOUR SPACE.</span><span>DISCOVER <b>·</b> PRACTICE <b>·</b> CONNECT <b>·</b> GROW</span></div></section>
    <section class="section container" id="platform"><div class="section-head reveal"><div><span class="section-kicker">01 / THE PLATFORM</span><h2>Everything you need.<br><span>Nothing you don't.</span></h2></div><p>Thoughtfully designed tools that help you spend less time organizing and more time learning.</p></div><div class="feature-grid"><article class="feature-card reveal"><div class="feature-top"><span class="feature-number">01</span><span class="feature-glyph">▤</span></div><div class="feature-visual visual-modules"><div class="visual-folder"><span>YOUR LIBRARY</span><strong>One place for<br>every topic.</strong><div class="visual-tags"><i>Algorithms</i><i>Databases</i><i>Web dev</i></div></div></div><h3>Learning, organized.</h3><p>Find your modules, notes and resources without digging through scattered links.</p></article><article class="feature-card reveal"><div class="feature-top"><span class="feature-number">02</span><span class="feature-glyph">◈</span></div><div class="feature-visual visual-quiz"><div class="quiz-visual"><span>YOUR WEEKLY PROGRESS</span><strong>86<small>%</small></strong><div class="progress-track"><div></div></div><span class="quiz-foot">You're building momentum ↗</span></div></div><h3>Practice with purpose.</h3><p>Sharpen your skills with quizzes and discover exactly where to improve.</p></article><article class="feature-card reveal"><div class="feature-top"><span class="feature-number">03</span><span class="feature-glyph">◎</span></div><div class="feature-visual visual-peers"><div class="peer-circles"><span>AS</span><span>RM</span><span>TK</span><span>+5</span></div><div class="peer-note">Next study session <strong>Algorithms · Group Peer</strong></div></div><h3>Better, together.</h3><p>Learn alongside people who share your curiosity, goals, and challenges.</p></article></div></section>
    <section class="story-section" aria-labelledby="story-heading">
      <div class="container story-grid">
        <div class="story-intro reveal">
          <span class="section-kicker">02 / MADE FOR MOMENTUM</span>
          <h2 id="story-heading">Not another tab.<br><em>A new rhythm.</em></h2>
          <p>From your first lecture to your final revision, move through learning with a little more clarity.</p>
          <div class="story-tabs" role="group" aria-label="Explore learning experiences">
            <button type="button" class="story-tab is-active" data-scene="modules" aria-pressed="true"><span>01</span> Find your focus <span aria-hidden="true">↗</span></button>
            <button type="button" class="story-tab" data-scene="quiz" aria-pressed="false"><span>02</span> Know your progress <span aria-hidden="true">↗</span></button>
            <button type="button" class="story-tab" data-scene="peer" aria-pressed="false"><span>03</span> Grow with others <span aria-hidden="true">↗</span></button>
          </div>
        </div>
        <div class="story-stage reveal" id="story-stage" data-scene="modules" aria-live="polite">
          <div class="stage-top"><span>U LEARN / THE EXPERIENCE</span><span class="stage-index" id="stage-index">01 — 03</span></div>
          <div class="stage-art" aria-hidden="true"><div class="stage-disc disc-one"></div><div class="stage-disc disc-two"></div><div class="stage-card"><span id="stage-label">YOUR LIBRARY</span><strong id="stage-title">Everything in<br>its place.</strong><div class="stage-meter"><span id="stage-meter"></span></div><small id="stage-caption">A clearer path through every module.</small></div></div>
          <div class="stage-bottom"><span id="stage-bottom-text">DISCOVER / LEARN / REPEAT</span><span>↗</span></div>
        </div>
      </div>
    </section>
    <section class="statement-section" id="experience"><div class="container statement-inner reveal"><span class="section-kicker">03 / THE EXPERIENCE</span><h2>Learning should feel<br><em>like progress.</em><br>Not pressure.</h2><p>A calmer, more connected approach to university life. Designed to help you find your rhythm.</p><a href="#community" class="text-link">Discover Group Peer <span aria-hidden="true">↗</span></a></div></section>
    <section class="community-section container" id="community"><div class="community-panel reveal"><div><span class="section-kicker">04 / COMMUNITY</span><h2>Good ideas grow<br>in good company.</h2><p>Ask a question. Share a resource. Join a session. U Learn brings the people and knowledge around you closer together.</p><a href="auth/register.php" class="button button-primary">Be part of it <span aria-hidden="true">↗</span></a></div><div class="community-graphic" aria-hidden="true"><div class="graphic-ring ring-a"></div><div class="graphic-ring ring-b"></div><div class="graphic-node node-a">A</div><div class="graphic-node node-b">S</div><div class="graphic-node node-c">K</div><div class="graphic-center">u<span>.</span></div></div></div></section>
    <section class="join-section container" id="join"><div class="join-inner reveal"><span class="section-kicker">THE NEXT CHAPTER</span><h2>Your next breakthrough<br>starts here.</h2><p>We're building U Learn for students who believe learning can be better.</p><a class="button button-primary" href="auth/register.php">Create your account <span aria-hidden="true">↗</span></a></div></section>
  </main>
  <footer class="footer"><div class="container footer-inner"><a class="brand" href="./"><span class="brand-mark" aria-hidden="true"><span></span><span></span><span></span></span><span>u learn<span class="brand-period">.</span></span></a><span>© <?= date('Y') ?> U Learn. Built to learn together.</span><a href="#top">Back to top ↑</a></div></footer>
</body>
</html>
