<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';

// Only a freshly authenticated login may display this page.
$user = require_login();
$destinations = [
    'student' => 'dashboard/index.php',
    'lecturer' => 'lecturer/index.php',
    'admin' => 'admin/lecturers.php',
    'main_admin' => 'admin/lecturers.php',
];
$destination = $destinations[(string)$user['role']] ?? 'dashboard/index.php';
if (empty($_SESSION['ulearn_post_login_intro'])) {
    go($destination);
}
unset($_SESSION['ulearn_post_login_intro']);
$next = url($destination);
header('Cache-Control: no-store, private');
header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'; script-src \'unsafe-inline\'; img-src \'self\'; base-uri \'none\'; form-action \'none\'');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#0a1221">
<meta http-equiv="refresh" content="6;url=<?= e($next) ?>">
<title>Welcome to ULearn</title>
<style>
:root{color-scheme:dark;font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif}
*{box-sizing:border-box}html,body{margin:0;min-height:100%;background:#0a1221;color:#fff}
body{min-height:100svh;display:grid;place-items:center;overflow:hidden}
body::before{content:"";position:fixed;inset:-35%;background:radial-gradient(circle at 35% 48%,#6b53e833,transparent 33%),radial-gradient(circle at 70% 65%,#20a58a22,transparent 30%);animation:drift 6s ease-in-out infinite alternate;pointer-events:none}
.stage{position:relative;text-align:center;padding:40px 24px;width:min(100%,700px)}
.halo{width:164px;height:164px;margin:0 auto 26px;border:1px solid #9586ff50;border-radius:50%;display:grid;place-items:center;box-shadow:0 0 0 24px #725df00a,0 0 85px #7056e33d;animation:arrive .8s cubic-bezier(.2,.8,.2,1) both,pulse 2.5s ease-in-out infinite .8s}
.mark{width:91px;height:91px;border-radius:25px;background:linear-gradient(145deg,#8170ff,#5744d5);display:grid;place-items:center;box-shadow:0 15px 45px #5142c555;animation:markIn .9s cubic-bezier(.2,1.4,.3,1) both .2s}
.mark svg{width:53px;height:53px}
.eyebrow{font-size:11px;font-weight:800;letter-spacing:.28em;color:#c5baff;text-transform:uppercase;animation:rise .65s both .55s}
h1{font-size:clamp(46px,9vw,82px);line-height:1.08;letter-spacing:-.065em;margin:12px 0 14px;font-weight:800;animation:rise .75s both .72s}
h1 span{color:#aa9cff}p{margin:0;color:#aebbd0;font-size:clamp(14px,2vw,17px);animation:rise .7s both .95s}
.progress{height:3px;width:min(220px,75%);margin:42px auto 0;border-radius:10px;background:#ffffff1b;overflow:hidden;animation:rise .6s both 1s}.progress::before{content:"";display:block;width:100%;height:100%;background:linear-gradient(90deg,#6e59ef,#b7a7ff);transform-origin:left;animation:fill 2.6s linear both}
.skip{margin-top:25px;padding:9px 16px;background:transparent;color:#b8c6dc;border:1px solid #ffffff29;border-radius:999px;cursor:pointer;font:inherit;font-size:12px;transition:background .2s,color .2s}.skip:hover,.skip:focus-visible{background:#ffffff15;color:white;outline:2px solid #9d8cff;outline-offset:3px}
@keyframes arrive{from{opacity:0;transform:scale(.72)}to{opacity:1;transform:scale(1)}}@keyframes markIn{from{opacity:0;transform:scale(.5) rotate(-20deg)}to{opacity:1;transform:scale(1) rotate(0)}}@keyframes rise{from{opacity:0;transform:translateY(18px)}to{opacity:1;transform:translateY(0)}}@keyframes pulse{50%{box-shadow:0 0 0 32px #725df00d,0 0 100px #7056e34f}}@keyframes drift{to{transform:translate(3%,2%)}}@keyframes fill{from{transform:scaleX(0)}to{transform:scaleX(1)}}
@media(prefers-reduced-motion:reduce){*,*::before,*::after{animation:none!important;transition:none!important}.progress::before{transform:scaleX(1)}}
</style>
</head>
<body>
<main class="stage" aria-label="Login successful, opening your dashboard">
  <div class="halo" aria-hidden="true"><div class="mark"><svg viewBox="0 0 64 64" fill="none"><path d="M17 43 33 19" stroke="white" stroke-width="6" stroke-linecap="round"/><path d="M31 43 47 19" stroke="white" stroke-width="6" stroke-linecap="round"/></svg></div></div>
  <div class="eyebrow">Your space to go further</div>
  <h1>Welcome to <span>ULearn.</span></h1>
  <p>You're in. Let's make something remarkable.</p>
  <div class="progress" aria-hidden="true"></div>
  <button class="skip" type="button" id="continue">Continue to dashboard</button>
</main>
<script>
(function(){
  'use strict';
  const destination = <?= json_encode($next, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  let navigating = false;
  function proceed(){if(navigating)return;navigating=true;window.location.replace(destination);}
  document.getElementById('continue').addEventListener('click',proceed);
  const reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  window.setTimeout(proceed,reduced?500:2800);
})();
</script>
</body>
</html>
