<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$user = require_login();
if (!in_array((string)$user['role'], ['admin', 'main_admin'], true)) {
    http_response_code(403);
    exit('Access denied. Administrator account required.');
}

$pdo = db();
$userId = (int)$user['id'];
$notice = '';
$error = '';

function profileLoadUser(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT id, full_name, email, password_hash, theme FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(403);
        exit('Account unavailable.');
    }
    return $row;
}

$profile = profileLoadUser($pdo, $userId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'update_profile') {
            $name = trim((string)($_POST['full_name'] ?? ''));
            if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
                throw new RuntimeException('Enter your full name (2–120 characters).');
            }
            $stmt = $pdo->prepare('UPDATE users SET full_name = ? WHERE id = ?');
            $stmt->execute([$name, $userId]);
            $notice = 'Your profile has been updated.';
        } elseif ($action === 'change_password') {
            $current = (string)($_POST['current_password'] ?? '');
            $new = (string)($_POST['new_password'] ?? '');
            $confirm = (string)($_POST['confirm_password'] ?? '');
            if (!password_verify($current, (string)$profile['password_hash'])) {
                throw new RuntimeException('Your current password is incorrect.');
            }
            if (
                strlen($new) < 8 ||
                !preg_match('/[A-Z]/', $new) ||
                !preg_match('/[0-9]/', $new) ||
                !preg_match('/[^a-zA-Z0-9]/', $new)
            ) {
                throw new RuntimeException('Password needs at least 8 characters, an uppercase letter, a number and a symbol.');
            }
            if ($new !== $confirm) {
                throw new RuntimeException('The new passwords do not match.');
            }
            if (password_verify($new, (string)$profile['password_hash'])) {
                throw new RuntimeException('Choose a password different from your current password.');
            }
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $stmt->execute([$hash, $userId]);
            session_regenerate_id(true);
            $notice = 'Password changed successfully.';
        } elseif ($action === 'update_theme') {
            $theme = (string)($_POST['theme'] ?? 'system');
            if (!in_array($theme, ['light', 'dark', 'system'], true)) {
                throw new RuntimeException('Please select a valid appearance preference.');
            }
            $stmt = $pdo->prepare('UPDATE users SET theme = ? WHERE id = ?');
            $stmt->execute([$theme, $userId]);
            $notice = 'Appearance preference saved.';
        } else {
            throw new RuntimeException('Unknown action.');
        }
        $profile = profileLoadUser($pdo, $userId);
    } catch (RuntimeException $ex) {
        $error = $ex->getMessage();
    }
}

$theme = (string)($profile['theme'] ?? 'system');
if (!in_array($theme, ['light', 'dark', 'system'], true)) {
    $theme = 'system';
}
$initial = strtoupper(substr(trim((string)$profile['full_name']), 0, 1));
$roleLabel = (string)$user['role'] === 'main_admin' ? 'Main Administrator' : 'Administrator';
?>
<!doctype html>
<html lang="en" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin Profile & Settings | U Learn</title>
<script>
(function () {
  var saved = <?= json_encode($theme, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var local = localStorage.getItem('ulearn-theme');
  var pref = saved;
  var dark = pref === 'dark' || (pref === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
  document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
})();
</script>
<style>
:root{--bg:#f7f8fc;--card:#fff;--text:#1b1d2c;--muted:#777b90;--border:#e7e8ef;--accent:#6856e8;--soft:#f0edff;--input:#fff}
[data-theme="dark"]{--bg:#10121c;--card:#1b1e2b;--text:#f5f5fa;--muted:#a2a6b8;--border:#303344;--accent:#b1a4ff;--soft:#2c2745;--input:#171a26}
*{box-sizing:border-box}body{margin:0;font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:var(--bg);color:var(--text)}a{color:inherit;text-decoration:none}.layout{min-height:100vh;display:grid;grid-template-columns:245px minmax(0,1fr)}.sidebar{background:var(--card);border-right:1px solid var(--border);padding:32px 20px}.brand{display:block;font-size:28px;font-weight:850;letter-spacing:-1.4px;margin-bottom:40px}.brand span,.intro h1 span{color:var(--accent)}.nav-label,.eyebrow{font-size:11px;font-weight:800;letter-spacing:1.5px;color:var(--muted);text-transform:uppercase}.nav{display:grid;gap:7px;margin-top:16px}.nav a{padding:13px 15px;border-radius:12px;font-weight:650;color:var(--muted)}.nav a:hover,.nav a.active{background:var(--soft);color:var(--accent)}.main{max-width:1120px;width:100%;margin:auto;padding:35px 42px 80px}.topbar{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:50px}.top-actions{display:flex;align-items:center;gap:12px}.pill{padding:9px 13px;border:1px solid var(--border);border-radius:99px;font-size:12px;color:var(--muted)}.theme-btn{height:42px;width:42px;border:1px solid var(--border);border-radius:12px;background:var(--card);color:var(--text);cursor:pointer;font-size:19px}.logout{font-weight:700;font-size:13px;color:var(--accent)}.intro{margin-bottom:28px}.intro h1{font-size:clamp(32px,4vw,48px);letter-spacing:-1.8px;margin:12px 0}.intro p,.hint{color:var(--muted);line-height:1.65}.profile-head{display:flex;align-items:center;gap:16px;margin-bottom:24px}.avatar{height:64px;width:64px;display:grid;place-items:center;border-radius:19px;background:var(--soft);color:var(--accent);font-weight:800;font-size:28px}.profile-head strong{display:block;font-size:18px}.profile-head small{display:block;color:var(--muted);margin-top:5px}.cards{display:grid;grid-template-columns:1fr 1fr;gap:20px}.card{background:var(--card);border:1px solid var(--border);border-radius:20px;padding:26px}.card h2{margin:0 0 8px;font-size:21px}.card p{margin:0 0 22px}.card.wide{grid-column:1/-1}.field{margin-bottom:18px}.field label{display:block;font-size:13px;font-weight:700;margin-bottom:9px}.field input,.field select{width:100%;border:1px solid var(--border);border-radius:11px;padding:13px 14px;background:var(--input);color:var(--text);font:inherit}.field input:focus,.field select:focus{outline:2px solid var(--accent);outline-offset:1px}.field input[readonly]{opacity:.75;cursor:not-allowed}.btn{border:0;background:var(--accent);color:#fff;font:inherit;font-weight:750;padding:13px 19px;border-radius:11px;cursor:pointer}.btn:hover{filter:brightness(1.08)}.message{padding:14px 18px;border-radius:12px;margin-bottom:22px;border:1px solid var(--border)}.message.success{background:var(--soft);color:var(--accent)}.message.error{background:#fff0f0;color:#a32e36;border-color:#f4c5c5}[data-theme="dark"] .message.error{background:#3d242b;color:#ffb7bd;border-color:#75424d}.subtle{font-size:12px;color:var(--muted);margin-top:10px}@media(max-width:850px){.layout{display:block}.sidebar{border-right:0;border-bottom:1px solid var(--border);padding:18px}.brand{margin-bottom:14px}.nav{display:flex;overflow:auto;margin-top:10px}.nav a{white-space:nowrap}.main{padding:25px 18px 50px}.topbar{margin-bottom:30px}.cards{grid-template-columns:1fr}.card.wide{grid-column:auto}.card{padding:22px}.pill{display:none}}
.theme-options{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin:20px 0}.theme-option{position:relative;display:grid;gap:10px;cursor:pointer;border:1.5px solid var(--border);border-radius:16px;padding:13px;text-align:center}.theme-option:has(input:checked){border-color:var(--accent);box-shadow:0 0 0 3px #987ef633}.theme-option input{position:absolute;top:12px;right:12px;accent-color:var(--accent)}.theme-preview{height:75px;border-radius:10px;position:relative;display:block;overflow:hidden;background:#f7f7fc;border:1px solid #e2e0ea}.theme-preview.dark{background:#151723;border-color:#34354a}.theme-preview.system{background:linear-gradient(90deg,#f7f7fc 50%,#151723 50%)}.theme-preview i{position:absolute;left:0;top:0;bottom:0;width:26%;background:#dcd7f6}.theme-preview.dark i{background:#29243f}.theme-preview.system i{background:linear-gradient(90deg,#dcd7f6 50%,#29243f 50%)}.theme-preview b,.theme-preview em{position:absolute;left:35%;height:10px;background:#bbb5e9;border-radius:5px;top:24px;width:50%}.theme-preview em{top:43px;width:35%;opacity:.55}@media(max-width:550px){.theme-options{gap:7px}.theme-option{padding:7px}.theme-preview{height:55px}}
</style>
<link rel="stylesheet" href="<?= e(url('assets/css/stage9-profile-v3.css')) ?>">
<script src="<?= htmlspecialchars(url('assets/js/theme-global.js?v=20261107-2'), ENT_QUOTES, 'UTF-8') ?>"></script>
<link rel="stylesheet" href="<?= e(url('assets/css/admin-unified-v1.css?v=1')) ?>">
</head>
<body>
<div class="layout">
<aside class="sidebar">
<a class="brand" href="<?= e(url('admin/index.php')) ?>">u learn<span>.</span></a>
<div class="nav-label">Admin workspace</div>
<nav class="nav" aria-label="Admin navigation">
<a href="<?= e(url('admin/index.php')) ?>">◫ &nbsp; Overview</a>
<a href="<?= e(url('admin/lecturers.php')) ?>">♙ &nbsp; Lecturers</a>
<a href="<?= e(url('admin/academics.php')) ?>">▤ &nbsp; Academics</a>
<a href="<?= e(url('admin/quizzes.php')) ?>">☷ &nbsp; Quizzes</a>
<a href="<?= e(url('admin/assignments.php')) ?>">♧ &nbsp; Assignments</a>
<a href="<?= e(url('admin/announcements.php')) ?>">▣ &nbsp; Announcements</a>
<a href="<?= e(url('dashboard/notifications.php')) ?>">♢ &nbsp; Notifications</a>
<a class="active" href="<?= e(url('admin/profile.php')) ?>" aria-current="page">⚙ &nbsp; My Profile &amp; Settings</a>
</nav>
</aside>
<main class="main">
<header class="topbar"><div class="eyebrow">U LEARN / ADMIN SPACE</div><div class="top-actions"><span class="pill"><?= e($roleLabel) ?></span><button class="theme-btn" id="themeToggle" type="button" aria-label="Toggle light and dark mode">☾</button><form method="post" action="<?= e(url('auth/logout.php')) ?>" style="margin:0"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><button type="submit" class="logout" style="border:0;background:none;cursor:pointer">Log out ↗</button></form></div></header>
<section class="intro"><div class="eyebrow">Account management</div><h1>My Profile <span>&</span> Settings<span>.</span></h1><p>Manage your administrator profile, secure your account, and choose your preferred appearance.</p></section>
<div class="profile-head"><div class="avatar" aria-hidden="true"><?= e($initial) ?></div><div><strong><?= e((string)$profile['full_name']) ?></strong><small><?= e((string)$profile['email']) ?></small></div></div>
<?php if ($notice !== ''): ?><div class="message success" role="status"><?= e($notice) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="message error" role="alert"><?= e($error) ?></div><?php endif; ?>
<div class="cards">
<section class="card"><h2>Personal information</h2><p class="hint">Update how your name appears in ULearn.</p>
<form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="update_profile"><div class="field"><label for="full_name">Full name</label><input id="full_name" name="full_name" maxlength="120" required autocomplete="name" value="<?= e((string)$profile['full_name']) ?>"></div><div class="field"><label for="email">Email address</label><input id="email" type="email" readonly value="<?= e((string)$profile['email']) ?>"></div><button class="btn" type="submit">Save profile</button></form></section>
<section class="card"><h2>Account details</h2><p class="hint">Your administrative access is managed by the platform.</p>
<div class="field"><label>Account role</label><input readonly value="<?= e($roleLabel) ?>"></div>
<div class="field"><label>Email address</label><input readonly value="<?= e((string)$profile['email']) ?>"></div>
<p class="subtle">For security, your account role and email cannot be changed here.</p></section>
<section class="card"><h2>Change password</h2><p class="hint">Use at least 8 characters, one uppercase letter, one number and one symbol.</p>
<form method="post" autocomplete="on"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="change_password"><div class="field"><label for="current_password">Current password</label><input id="current_password" name="current_password" type="password" autocomplete="current-password" required></div><div class="field"><label for="new_password">New password</label><input id="new_password" name="new_password" type="password" autocomplete="new-password" minlength="8" maxlength="128" required></div><div class="field"><label for="confirm_password">Confirm new password</label><input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" minlength="8" maxlength="128" required></div><button class="btn" type="submit">Change password</button></form></section>
<section class="card wide"><h2>Appearance</h2><p class="hint">Choose how ULearn looks on this account.</p><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="update_theme"><div class="theme-options" role="radiogroup" aria-label="Theme preference"><?php foreach(['light'=>'☀ Light','dark'=>'☾ Dark','system'=>'◐ System'] as $key=>$title): ?><label class="theme-option"><input type="radio" name="theme" value="<?= e($key) ?>" <?= $theme===$key?'checked':'' ?>><span class="theme-preview <?= e($key) ?>"><i></i><b></b><em></em></span><strong><?= e($title) ?></strong></label><?php endforeach ?></div><button class="btn" type="submit">Save appearance</button><div class="subtle">The theme toggle in the header previews your preference instantly.</div></form></section>
</div>
</main>
</div>
<script>
(function () {
  var button = document.getElementById('themeToggle');
  function updateIcon() { button.textContent = document.documentElement.getAttribute('data-theme') === 'dark' ? '☀' : '☾'; }
  updateIcon();
  button.addEventListener('click', function () {
    var next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    localStorage.setItem('ulearn-theme', next);
    updateIcon();
  });
  var themeForm = document.querySelector('input[name=theme]').form;
  document.querySelectorAll('input[name=theme]').forEach(function (radio) {
    radio.addEventListener('change', function () {
      var next = radio.value === 'system' ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light') : radio.value;
      document.documentElement.setAttribute('data-theme', next);
      updateIcon();
    });
  });
  themeForm.addEventListener('submit', function () {
    var selected = document.querySelector('input[name=theme]:checked');
    if (selected) {
      var next = selected.value === 'system' ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light') : selected.value;
      localStorage.setItem('ulearn-theme', next);
    }
  });
})();
</script>
</body>
</html>
