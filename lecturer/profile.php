<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$user = require_login();
if ((string)$user['role'] !== 'lecturer') {
    http_response_code(403);
    exit('Access denied. Lecturer account required.');
}

$pdo = db();
$userId = (int)$user['id'];
$notice = '';
$error = '';

function profileLoadUser(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT id, full_name, email, username, password_hash, theme FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(403);
        exit('Account unavailable.');
    }
    return $row;
}

$lecturerStmt = $pdo->prepare('SELECT title, department, designation, is_active FROM lecturer_profiles WHERE user_id = ? LIMIT 1');
$lecturerStmt->execute([$userId]);
$lecturer = $lecturerStmt->fetch(PDO::FETCH_ASSOC);
if (!$lecturer || (int)$lecturer['is_active'] !== 1) {
    http_response_code(403);
    exit('Your lecturer profile is not active. Contact an administrator.');
}

$profile = profileLoadUser($pdo, $userId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'upload_profile_photo' || $action === 'remove_profile_photo') {
            require __DIR__.'/../includes/stage9-photo-actions.php';
        } elseif ($action === 'update_profile') {
            $name = trim((string)($_POST['full_name'] ?? ''));
            if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
                throw new RuntimeException('Enter your full name (2–120 characters).');
            }
            $username=trim((string)($_POST['username'] ?? $profile['username'] ?? ''));
            if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_.]{2,39}$/',$username)) throw new RuntimeException('Username must be 3–40 characters and start with a letter.');
            $unique=$pdo->prepare('SELECT id FROM users WHERE username=? AND id<>? LIMIT 1');$unique->execute([$username,$userId]);
            if ($unique->fetch()) throw new RuntimeException('Username already taken.');
            $stmt = $pdo->prepare('UPDATE users SET full_name = ?, username = ? WHERE id = ?');
            $stmt->execute([$name,$username,$userId]);
            $notice = 'Your profile has been updated.';
        } elseif ($action === 'change_password') {
            $current = (string)($_POST['current_password'] ?? '');
            $new = (string)($_POST['new_password'] ?? '');
            $confirm = (string)($_POST['confirm_password'] ?? '');
            if (!password_verify($current, (string)$profile['password_hash'])) {
                throw new RuntimeException('Your current password is incorrect.');
            }
            if (
                strlen($new) < 8 || strlen($new) > 128 ||
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
        $lecturerStmt = $pdo->prepare('SELECT title, department, designation, is_active FROM lecturer_profiles WHERE user_id = ? LIMIT 1');
$lecturerStmt->execute([$userId]);
$lecturer = $lecturerStmt->fetch(PDO::FETCH_ASSOC);
if (!$lecturer || (int)$lecturer['is_active'] !== 1) {
    http_response_code(403);
    exit('Your lecturer profile is not active. Contact an administrator.');
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
?>
<!doctype html>
<html lang="en" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Lecturer Profile & Settings | U Learn</title>
<script>
(function(){var pref=<?= json_encode($theme) ?>;try{var local=localStorage.getItem('ulearn-theme');if(['light','dark','system'].includes(local))pref=local;}catch(e){}var dark=pref==='dark'||(pref==='system'&&matchMedia('(prefers-color-scheme: dark)').matches);document.documentElement.dataset.theme=dark?'dark':'light';window.profileTheme=pref;})();
</script>
<style>
:root{--bg:#f7f8fc;--card:#fff;--text:#1b1d2c;--muted:#777b90;--border:#e7e8ef;--accent:#6856e8;--soft:#f0edff;--input:#fff}
[data-theme="dark"]{--bg:#10121c;--card:#1b1e2b;--text:#f5f5fa;--muted:#a2a6b8;--border:#303344;--accent:#b1a4ff;--soft:#2c2745;--input:#171a26}
*{box-sizing:border-box}body{margin:0;font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:var(--bg);color:var(--text)}a{color:inherit;text-decoration:none}.layout{min-height:100vh;display:grid;grid-template-columns:245px minmax(0,1fr)}.sidebar{background:var(--card);border-right:1px solid var(--border);padding:32px 20px}.brand{display:block;font-size:28px;font-weight:850;letter-spacing:-1.4px;margin-bottom:40px}.brand span,.intro h1 span{color:var(--accent)}.nav-label,.eyebrow{font-size:11px;font-weight:800;letter-spacing:1.5px;color:var(--muted);text-transform:uppercase}.nav{display:grid;gap:7px;margin-top:16px}.nav a{padding:13px 15px;border-radius:12px;font-weight:650;color:var(--muted)}.nav a:hover,.nav a.active{background:var(--soft);color:var(--accent)}.main{max-width:1120px;width:100%;margin:auto;padding:35px 42px 80px}.topbar{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:50px}.top-actions{display:flex;align-items:center;gap:12px}.pill{padding:9px 13px;border:1px solid var(--border);border-radius:99px;font-size:12px;color:var(--muted)}.theme-btn{height:42px;width:42px;border:1px solid var(--border);border-radius:12px;background:var(--card);color:var(--text);cursor:pointer;font-size:19px}.logout{font-weight:700;font-size:13px;color:var(--accent)}.intro{margin-bottom:28px}.intro h1{font-size:clamp(32px,4vw,48px);letter-spacing:-1.8px;margin:12px 0}.intro p,.hint{color:var(--muted);line-height:1.65}.profile-head{display:flex;align-items:center;gap:16px;margin-bottom:24px}.avatar{height:64px;width:64px;display:grid;place-items:center;border-radius:19px;background:var(--soft);color:var(--accent);font-weight:800;font-size:28px}.profile-head strong{display:block;font-size:18px}.profile-head small{display:block;color:var(--muted);margin-top:5px}.cards{display:grid;grid-template-columns:1fr 1fr;gap:20px}.card{background:var(--card);border:1px solid var(--border);border-radius:20px;padding:26px}.card h2{margin:0 0 8px;font-size:21px}.card p{margin:0 0 22px}.card.wide{grid-column:1/-1}.field{margin-bottom:18px}.field label{display:block;font-size:13px;font-weight:700;margin-bottom:9px}.field input,.field select{width:100%;border:1px solid var(--border);border-radius:11px;padding:13px 14px;background:var(--input);color:var(--text);font:inherit}.field input:focus,.field select:focus{outline:2px solid var(--accent);outline-offset:1px}.field input[readonly]{opacity:.75;cursor:not-allowed}.btn{border:0;background:var(--accent);color:#fff;font:inherit;font-weight:750;padding:13px 19px;border-radius:11px;cursor:pointer}.btn:hover{filter:brightness(1.08)}.message{padding:14px 18px;border-radius:12px;margin-bottom:22px;border:1px solid var(--border)}.message.success{background:var(--soft);color:var(--accent)}.message.error{background:#fff0f0;color:#a32e36;border-color:#f4c5c5}[data-theme="dark"] .message.error{background:#3d242b;color:#ffb7bd;border-color:#75424d}.subtle{font-size:12px;color:var(--muted);margin-top:10px}@media(max-width:850px){.layout{display:block}.sidebar{border-right:0;border-bottom:1px solid var(--border);padding:18px}.brand{margin-bottom:14px}.nav{display:flex;overflow:auto;margin-top:10px}.nav a{white-space:nowrap}.main{padding:25px 18px 50px}.topbar{margin-bottom:30px}.cards{grid-template-columns:1fr}.card.wide{grid-column:auto}.card{padding:22px}.pill{display:none}}
</style>
<style>.stage9-card{background:var(--card,#fff);color:var(--text,#1c2040);border:1px solid var(--border,#e5e7ef);border-radius:20px;padding:22px;margin:20px 0;box-shadow:0 10px 35px #13172a09}.stage9-card h2{margin:0 0 8px;font-size:20px}.stage9-card p{color:var(--muted,#6d7486);margin:0 0 14px}.stage9-head{display:flex;align-items:center;gap:18px}.stage9-head img{width:86px;height:86px;border-radius:20px}.stage9-card label{display:block;font-size:13px;font-weight:650;margin:14px 0 6px}.stage9-card select,.stage9-card textarea{width:100%;max-width:440px;padding:11px;border-radius:10px;border:1px solid var(--border,#dce0eb);background:var(--card,#fff);color:var(--text,#222);font:inherit}.stage9-button{border:0;border-radius:10px;background:#7158d9;color:#fff;padding:11px 16px;cursor:pointer;margin:10px 0;font:inherit}.stage9-avatars{display:grid;grid-template-columns:repeat(auto-fill,minmax(60px,1fr));gap:10px;margin-top:14px}.stage9-avatars img{width:100%;border-radius:14px}.stage9-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px}.stage9-grid strong{display:block}.stage9-note{font-size:13px;color:var(--muted,#777)}@media(max-width:600px){.stage9-card{padding:16px}.stage9-head img{width:64px;height:64px}}</style>
<style>.appearance-title{display:flex;justify-content:space-between;align-items:start}.appearance-symbol{font-size:32px;color:var(--accent)}.theme-options{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin:22px 0}.theme-choice{display:flex;flex-direction:column;gap:7px;padding:13px;border:1.5px solid var(--border);border-radius:17px;cursor:pointer;transition:transform .18s,border-color .18s,box-shadow .18s;position:relative}.theme-choice:hover{transform:translateY(-2px)}.theme-choice:has(input:checked){border-color:var(--accent);box-shadow:0 0 0 3px color-mix(in srgb,var(--accent) 13%,transparent)}.theme-choice input{position:absolute;right:15px;top:15px;accent-color:#8069e8;width:17px;height:17px}.theme-choice strong{font-size:15px}.theme-choice small{font-size:12px;color:var(--muted)}.theme-art{height:92px;border-radius:11px;display:block;position:relative;overflow:hidden;border:1px solid #9ca1bc44}.theme-art i{position:absolute;top:15px;left:13px;width:22%;height:68%;border-radius:5px}.theme-art b{position:absolute;top:15px;left:39%;width:49%;height:22%;border-radius:5px;box-shadow:0 28px 0 -2px currentColor,0 48px 0 -2px currentColor}.theme-art-light{background:#f4f5fa;color:#dedff0}.theme-art-light i{background:#d5d8ec}.theme-art-dark{background:#111625;color:#333a58}.theme-art-dark i{background:#282f4c}.theme-art-system{background:linear-gradient(90deg,#f4f5fa 50%,#111625 50%);color:#8d8eaf}.theme-art-system i{background:linear-gradient(90deg,#d5d8ec 50%,#282f4c 50%)}.appearance-save{margin-top:4px}@media(max-width:650px){.theme-options{gap:8px}.theme-choice{padding:8px}.theme-art{height:65px}.theme-choice strong{font-size:12px}.theme-choice small{font-size:10px}}</style>

">

<style>
:root{--bg:#f5f5fb;--card:#fff;--text:#22243c;--muted:#666b83;--border:#e5e5f0;--accent:#6955e8;--soft:#efecff;--input:#fafaff;--shadow:0 8px 30px #24204d05;color-scheme:light}
[data-theme="dark"]{--bg:#0c1720;--card:#14222e;--text:#f2f3fc;--muted:#a6b0c5;--border:#2a3a4b;--accent:#b0a3ff;--soft:#2b2949;--input:#101d29;--shadow:0 8px 30px #00000012;color-scheme:dark}
html{scroll-behavior:smooth}body{line-height:1.5}button,input,select{font:inherit}button,a,input{touch-action:manipulation}button,a,input,select,.card,.theme-choice{transition:background-color .2s,color .2s,border-color .2s,box-shadow .2s,transform .2s}a:focus-visible,button:focus-visible{outline:3px solid var(--accent);outline-offset:4px}.layout{grid-template-columns:250px minmax(0,1fr)}.sidebar{position:sticky;top:0;height:100dvh;overflow:auto;padding:30px 20px}.brand{display:flex;gap:11px;align-items:center;font-size:25px;letter-spacing:-1px;margin-bottom:36px}.brand svg{width:39px;height:39px;flex:none}.brand span{color:var(--text)}.nav a{display:flex;align-items:center;gap:12px;font-size:13px;padding:12px;line-height:1.4}.nav a svg{width:19px;height:19px;flex:none}.nav a:hover{transform:translateX(3px)}.main{max-width:1240px;padding:30px 40px 64px}.topbar{margin-bottom:28px}.eyebrow{font-size:10px;letter-spacing:1.6px}.theme-btn{display:grid;place-items:center;font-size:20px}.intro{position:relative;overflow:hidden;background:linear-gradient(120deg,#292456,#6655ed);color:white;border-radius:25px;padding:32px 36px;margin-bottom:22px}.intro:after{content:'✦';position:absolute;right:32px;top:-30px;font-size:200px;color:#ffffff0c;pointer-events:none}.intro .eyebrow{color:#d3ccff}.intro h1{font-size:clamp(28px,3.5vw,42px);margin:12px 0;letter-spacing:-1.4px;position:relative;z-index:1}.intro h1 span{color:#d6ceff}.intro p{color:#eeeaff;max-width:570px;margin-bottom:0;font-size:14px;position:relative;z-index:1}.profile-head{padding:20px 24px;background:var(--card);border:1px solid var(--border);border-radius:19px;box-shadow:var(--shadow)}.profile-head>div:last-child{min-width:0}.profile-head strong,.profile-head small{overflow-wrap:anywhere}.avatar{width:54px;height:54px;flex:none;font-size:23px;border-radius:16px}.cards{align-items:start;gap:20px}.card{padding:26px;border-radius:20px;box-shadow:var(--shadow);min-width:0}.card h2{font-size:18px;letter-spacing:-.3px}.card .hint{font-size:13px;margin-bottom:23px}.card input{font-size:14px;min-width:0}.field input[readonly]{opacity:1;background:var(--bg);color:var(--muted)}.btn,.stage9-button{background:#6955e8;color:#fff;min-height:44px;font-size:13px;font-weight:700;box-shadow:0 4px 10px #6955e820}.btn:hover,.stage9-button:hover{transform:translateY(-1px);background:#5945d5}.btn:active{transform:translateY(0)}.card.wide{grid-column:auto}.theme-options{gap:9px}.theme-choice{padding:10px;min-width:0}.theme-choice strong{font-size:12px}.theme-choice small{font-size:10px}.theme-choice input{top:16px;right:14px}.theme-art{height:75px}.password-wrap{position:relative}.password-wrap input{padding-right:64px}.password-toggle{position:absolute;right:6px;top:5px;bottom:5px;padding:0 9px;border:0;background:transparent;color:var(--accent);font-size:11px;font-weight:700;cursor:pointer}.password-rules{font-size:12px;color:var(--muted);margin:-5px 0 18px}.password-rules.valid{color:var(--accent)}.stage9-card{box-shadow:var(--shadow)}.stage9-card input[type=file]{max-width:100%;color:var(--muted)}.stage9-card img{object-fit:cover}.message{overflow-wrap:anywhere}.card,.intro,.profile-head{animation:enter .4s ease both}.card:nth-child(2){animation-delay:.04s}.card:nth-child(3){animation-delay:.08s}.card:nth-child(4){animation-delay:.12s}@keyframes enter{from{opacity:0;transform:translateY(9px)}to{opacity:1;transform:none}}
@media(min-width:1600px){.main{padding-top:40px}}@media(max-width:1100px){.layout{grid-template-columns:220px minmax(0,1fr)}.sidebar{padding:28px 14px}.main{padding:26px}.cards{grid-template-columns:1fr}.theme-art{height:90px}.card.wide{grid-column:auto}}@media(max-width:850px){.layout{display:block}.sidebar{position:relative;height:auto;padding:18px;border-bottom:1px solid var(--border)}.brand{margin:0 0 16px}.nav-label{display:none}.nav{display:flex;gap:5px;padding-bottom:5px;overflow-x:auto}.nav a{flex:none;padding:10px 13px}.nav a:hover{transform:none}.main{padding:22px 18px 40px}.topbar{margin-bottom:22px}.intro{padding:26px}.cards{grid-template-columns:1fr 1fr}.card.wide{grid-column:auto}.topbar .eyebrow{max-width:140px}.top-actions{gap:10px}.pill{display:none}}@media(max-width:650px){.cards{grid-template-columns:1fr}.card{padding:22px}.intro{padding:25px 22px;border-radius:20px}.intro:after{right:-20px}.intro h1{font-size:30px}.profile-head{padding:18px}.profile-head strong{font-size:16px}.profile-head small{font-size:12px}.theme-choice strong{font-size:12px}.theme-choice small{font-size:10px}.theme-art{height:80px}.topbar .eyebrow{font-size:9px}.theme-btn{width:38px;height:38px}.logout{font-size:12px}.theme-options{gap:8px}.stage9-head{flex-wrap:wrap}}@media(prefers-reduced-motion:reduce){*,*:before,*:after{animation:none!important;transition:none!important;scroll-behavior:auto!important}}
</style>
<link rel="stylesheet" href="<?= e(url('assets/css/lecturer-purple-premium.css')) ?>?v=20261111">
</head>
<body>
<div class="layout">
<aside class="sidebar">
<?php require __DIR__ . '/../includes/brand-logo.php'; ?>
<div class="nav-label">Lecturer workspace</div>
<nav class="nav" aria-label="Lecturer navigation">
<a href="<?= e(url('lecturer/index.php')) ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>Overview</a>
<a href="<?= e(url('lecturer/index.php#modules')) ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4h16v16H4zM8 8h8M8 12h8M8 16h5"/></svg>My Modules</a>
<a href="<?= e(url('lecturer/materials.php')) ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 4h7l2 2 2-2h7v16h-7l-2 2-2-2H3zM12 6v16"/></svg>Study Materials</a>
<a href="<?= e(url('lecturer/quizzes.php')) ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 3h14v18H5zM9 8h6M9 12h6M9 16h4"/></svg>Quiz Management</a>
<a href="<?= e(url('lecturer/performance.php')) ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20V12m5 8V7m5 13v-9m5 9V4"/></svg>Student Performance</a>
<a href="<?= e(url('lecturer/question-analytics.php')) ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="10" cy="10" r="6"/><path d="m15 15 6 6M7 10h6M10 7v6"/></svg>Question Analytics</a>
<a href="<?= e(url('lecturer/consultations.php')) ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4h16v13H9l-5 4zM8 9h8M8 13h5"/></svg>Consultations</a>
<a href="<?= e(url('dashboard/notifications.php')) ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9a6 6 0 0 1 12 0c0 6 3 7 3 8H3c0-1 3-2 3-8M10 21h4"/></svg>Notifications</a>
<a class="active" href="<?= e(url('lecturer/profile.php')) ?>" aria-current="page"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-7 16-7 16 0"/></svg>My Profile & Settings</a>
</nav>
</aside>
<main class="main">
<header class="topbar"><div class="eyebrow">U LEARN / LECTURER PORTAL</div><div class="top-actions"><span class="pill">Lecturer account</span><button class="theme-btn" id="themeToggle" type="button" aria-label="Toggle light and dark mode">☾</button><form method="post" action="<?= e(url('auth/logout.php')) ?>" style="margin:0"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><button type="submit" class="logout" style="border:0;background:none;cursor:pointer">Log out ↗</button></form></div></header>
<section class="intro"><div class="eyebrow">Account management</div><h1>My Profile <span>&</span> Settings<span>.</span></h1><p>Manage your teaching profile, secure your account, and choose your preferred appearance.</p></section>
<div class="profile-head"><div class="avatar" aria-hidden="true"><?= e($initial) ?></div><div><strong><?= e((string)$profile['full_name']) ?></strong><small><?= e((string)$profile['email']) ?></small></div></div>
<?php if ($notice !== ''): ?><div class="message success" role="status"><?= e($notice) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="message error" role="alert"><?= e($error) ?></div><?php endif; ?>
<div class="cards">
<section class="card"><h2>Personal information</h2><p class="hint">Update how your name appears in ULearn.</p>
<form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="update_profile"><div class="field"><label for="full_name">Full name</label><input id="full_name" name="full_name" maxlength="120" required autocomplete="name" value="<?= e((string)$profile['full_name']) ?>"></div><div class="field"><label for="username">Username</label><input id="username" name="username" maxlength="40" minlength="3" pattern="[a-zA-Z][a-zA-Z0-9_.]{2,39}" autocomplete="username" required value="<?= e((string)($profile['username'] ?? '')) ?>"></div><div class="field"><label for="email">Email address</label><input id="email" type="email" readonly value="<?= e((string)$profile['email']) ?>"></div><button class="btn" type="submit">Save profile</button></form></section>
<section class="card"><h2>Professional details</h2><p class="hint">Your teaching profile is managed by an administrator.</p>
<div class="field"><label>Title</label><input readonly value="<?= e((string)($lecturer['title'] ?? '')) ?>"></div>
<div class="field"><label>Designation</label><input readonly value="<?= e((string)($lecturer['designation'] ?? '')) ?>"></div>
<div class="field"><label>Department</label><input readonly value="<?= e((string)($lecturer['department'] ?? '')) ?>"></div></section>
<section class="card"><h2>Change password</h2><p class="hint">Use at least 8 characters, one uppercase letter, one number and one symbol.</p>
<form method="post" autocomplete="on"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="change_password"><div class="field"><label for="current_password">Current password</label><input id="current_password" name="current_password" type="password" autocomplete="current-password" required></div><div class="field"><label for="new_password">New password</label><input id="new_password" name="new_password" type="password" autocomplete="new-password" minlength="8" maxlength="128" required></div><div class="field"><label for="confirm_password">Confirm new password</label><input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" minlength="8" maxlength="128" required></div><button class="btn" type="submit">Change password</button></form></section>
<section class="card wide appearance-modern"><div class="appearance-title"><div><h2>Appearance</h2><p class="hint">A space that feels right, day or night.</p></div><span class="appearance-symbol" aria-hidden="true">◐</span></div><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="update_theme"><div class="theme-options" role="radiogroup" aria-label="Theme preference"><label class="theme-choice"><input type="radio" name="theme" value="light" <?= $theme === 'light' ? 'checked' : '' ?>><span class="theme-art theme-art-light"><i></i><b></b></span><strong>☀ Light</strong><small>Bright and focused</small></label><label class="theme-choice"><input type="radio" name="theme" value="dark" <?= $theme === 'dark' ? 'checked' : '' ?>><span class="theme-art theme-art-dark"><i></i><b></b></span><strong>☾ Dark</strong><small>Comfortable at night</small></label><label class="theme-choice"><input type="radio" name="theme" value="system" <?= $theme === 'system' ? 'checked' : '' ?>><span class="theme-art theme-art-system"><i></i><b></b></span><strong>◐ System</strong><small>Match your device</small></label></div><button class="btn appearance-save" type="submit">Save preference</button></form></section>
</div>
</main>
</div>
<script>
(function(){'use strict';
const root=document.documentElement,button=document.getElementById('themeToggle'),media=matchMedia('(prefers-color-scheme: dark)');let preference=window.profileTheme||'system';
const radios=[...document.querySelectorAll('input[name="theme"]')];
function apply(value,persist){preference=value;const dark=value==='dark'||(value==='system'&&media.matches);root.dataset.theme=dark?'dark':'light';button.textContent=dark?'☀':'☾';button.setAttribute('aria-label',dark?'Switch to light mode':'Switch to dark mode');radios.forEach(r=>r.checked=r.value===value);if(persist)try{localStorage.setItem('ulearn-theme',value);}catch(e){}}
apply(preference,false);button.addEventListener('click',()=>apply(root.dataset.theme==='dark'?'light':'dark',true));radios.forEach(r=>r.addEventListener('change',()=>apply(r.value,false)));media.addEventListener('change',()=>{if(preference==='system')apply(preference,false);});window.addEventListener('storage',e=>{if(e.key==='ulearn-theme'&&['light','dark','system'].includes(e.newValue))apply(e.newValue,false);});
const appearance=radios[0]?.form;if(appearance)appearance.addEventListener('submit',()=>{const selected=radios.find(r=>r.checked);if(selected)apply(selected.value,true);});
document.querySelectorAll('input[type="password"]').forEach(input=>{const wrap=document.createElement('div');wrap.className='password-wrap';input.before(wrap);wrap.append(input);const toggle=document.createElement('button');toggle.type='button';toggle.className='password-toggle';toggle.textContent='Show';toggle.setAttribute('aria-label','Show '+input.labels[0].textContent.toLowerCase());toggle.setAttribute('aria-pressed','false');toggle.addEventListener('click',()=>{const show=input.type==='password';input.type=show?'text':'password';toggle.textContent=show?'Hide':'Show';toggle.setAttribute('aria-pressed',String(show));toggle.setAttribute('aria-label',(show?'Hide ':'Show ')+input.labels[0].textContent.toLowerCase());});wrap.append(toggle);});
const password=document.getElementById('new_password'),confirm=document.getElementById('confirm_password');const rules=document.createElement('div');rules.className='password-rules';rules.setAttribute('aria-live','polite');password.closest('.field').after(rules);function validate(){const valid=password.value.length>=8&&/[A-Z]/.test(password.value)&&/[0-9]/.test(password.value)&&/[^a-zA-Z0-9]/.test(password.value);password.setCustomValidity(password.value&&!valid?'Use 8+ characters, an uppercase letter, a number and a symbol.':'');confirm.setCustomValidity(confirm.value&&confirm.value!==password.value?'The new passwords do not match.':'');rules.textContent=password.value?(valid?'Password meets the requirements.':'Use 8+ characters, an uppercase letter, a number and a symbol.'):'';rules.classList.toggle('valid',valid);}password.addEventListener('input',validate);confirm.addEventListener('input',validate);
})();
</script>
<?php require __DIR__.'/../includes/stage9-avatar.php'; ?>
<script src="<?= e(url('assets/js/lecturer-purple-premium.js')) ?>?v=20261111" defer></script>
</body>
</html>
