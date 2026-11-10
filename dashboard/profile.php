<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$user = require_login();
if ((string)$user['role'] !== 'student') {
    http_response_code(403);
    exit('Access denied. Student account required.');
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
        if ($action === 'stage9_request') {
            $degree=(int)($_POST['degree_id']??0); $batch=(int)($_POST['batch_id']??0);
            $reason=trim((string)($_POST['reason']??''));
            if (mb_strlen($reason)<12 || mb_strlen($reason)>1000) throw new RuntimeException('Explain the requested change in 12–1000 characters.');
            $valid=$pdo->prepare('SELECT 1 FROM academic_degree_intakes WHERE degree_id=? AND batch_id=? AND is_active=1');
            $valid->execute([$degree,$batch]);if(!$valid->fetchColumn())throw new RuntimeException('Select a valid degree and intake.');
            $pending=$pdo->prepare("SELECT 1 FROM academic_change_requests WHERE student_user_id=? AND status='pending' LIMIT 1");
            $pending->execute([$userId]);if($pending->fetchColumn())throw new RuntimeException('You already have a pending academic change request.');
            $pdo->prepare('INSERT INTO academic_change_requests(student_user_id,requested_degree_id,requested_batch_id,reason) VALUES (?,?,?,?)')->execute([$userId,$degree,$batch,$reason]);
            $pdo->prepare("INSERT INTO notifications(user_id,actor_user_id,type,title,message,target_url,is_read) SELECT id,?,'system','Academic change request',?,'admin/change-requests.php',0 FROM users WHERE role IN ('admin','main_admin') AND account_status='active'")->execute([$userId,'A student requested a degree or intake change. Review in System Management.']);
            $notice='Request submitted. Your current academic details remain locked until an admin approves.';
        } elseif ($action === 'upload_profile_photo' || $action === 'remove_profile_photo') {
            require __DIR__.'/../includes/stage9-photo-actions.php';
        } elseif ($action === 'update_profile') {
            $name = trim((string)($_POST['full_name'] ?? ''));
            if ($name === '' || strlen($name) > 360 || preg_match_all('/./us', $name) > 120) {
                throw new RuntimeException('Please enter a name between 1 and 120 characters.');
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
?>
<!doctype html>
<html lang="en" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>My Profile & Settings | U Learn</title>
<script>
(function () {
  var saved = <?= json_encode($theme, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var local = localStorage.getItem('ulearn-theme');
  var pref = local === 'light' || local === 'dark' ? local : saved;
  var dark = pref === 'dark' || (pref === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
  document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
})();
</script>

<style>
    .ul-nav-icon {
    width: 20px;
    height: 20px;
    flex-shrink: 0;
}
.nav a {
    display: flex;
    align-items: center;
    gap: 12px;
}
:root{--bg:#f7f8fc;--card:#fff;--text:#1b1d2c;--muted:#777b90;--border:#e7e8ef;--accent:#6856e8;--soft:#f0edff;--input:#fff}
[data-theme="dark"]{--bg:#10121c;--card:#1b1e2b;--text:#f5f5fa;--muted:#a2a6b8;--border:#303344;--accent:#b1a4ff;--soft:#2c2745;--input:#171a26}
*{box-sizing:border-box}body{margin:0;font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:var(--bg);color:var(--text)}a{color:inherit;text-decoration:none}.layout{min-height:100vh;display:grid;grid-template-columns:245px minmax(0,1fr)}.sidebar{background:var(--card);border-right:1px solid var(--border);padding:32px 20px}.brand{display:block;font-size:28px;font-weight:850;letter-spacing:-1.4px;margin-bottom:40px}.brand span,.intro h1 span{color:var(--accent)}.nav-label,.eyebrow{font-size:11px;font-weight:800;letter-spacing:1.5px;color:var(--muted);text-transform:uppercase}.nav{display:grid;gap:7px;margin-top:16px}.nav a{padding:13px 15px;border-radius:12px;font-weight:650;color:var(--muted)}.nav a:hover,.nav a.active{background:var(--soft);color:var(--accent)}.main{max-width:1120px;width:100%;margin:auto;padding:35px 42px 80px}.topbar{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:50px}.top-actions{display:flex;align-items:center;gap:12px}.pill{padding:9px 13px;border:1px solid var(--border);border-radius:99px;font-size:12px;color:var(--muted)}.theme-btn{height:42px;width:42px;border:1px solid var(--border);border-radius:12px;background:var(--card);color:var(--text);cursor:pointer;font-size:19px}.logout{font-weight:700;font-size:13px;color:var(--accent)}.intro{margin-bottom:28px}.intro h1{font-size:clamp(32px,4vw,48px);letter-spacing:-1.8px;margin:12px 0}.intro p,.hint{color:var(--muted);line-height:1.65}.profile-head{display:flex;align-items:center;gap:16px;margin-bottom:24px}.avatar{height:64px;width:64px;display:grid;place-items:center;border-radius:19px;background:var(--soft);color:var(--accent);font-weight:800;font-size:28px}.profile-head strong{display:block;font-size:18px}.profile-head small{display:block;color:var(--muted);margin-top:5px}.cards{display:grid;grid-template-columns:1fr 1fr;gap:20px}.card{background:var(--card);border:1px solid var(--border);border-radius:20px;padding:26px}.card h2{margin:0 0 8px;font-size:21px}.card p{margin:0 0 22px}.card.wide{grid-column:1/-1}.field{margin-bottom:18px}.field label{display:block;font-size:13px;font-weight:700;margin-bottom:9px}.field input,.field select{width:100%;border:1px solid var(--border);border-radius:11px;padding:13px 14px;background:var(--input);color:var(--text);font:inherit}.field input:focus,.field select:focus{outline:2px solid var(--accent);outline-offset:1px}.field input[readonly]{opacity:.75;cursor:not-allowed}.btn{border:0;background:var(--accent);color:#fff;font:inherit;font-weight:750;padding:13px 19px;border-radius:11px;cursor:pointer}.btn:hover{filter:brightness(1.08)}.message{padding:14px 18px;border-radius:12px;margin-bottom:22px;border:1px solid var(--border)}.message.success{background:var(--soft);color:var(--accent)}.message.error{background:#fff0f0;color:#a32e36;border-color:#f4c5c5}[data-theme="dark"] .message.error{background:#3d242b;color:#ffb7bd;border-color:#75424d}.subtle{font-size:12px;color:var(--muted);margin-top:10px}@media(max-width:850px){.layout{display:block}.sidebar{border-right:0;border-bottom:1px solid var(--border);padding:18px}.brand{margin-bottom:14px}.nav{display:flex;overflow:auto;margin-top:10px}.nav a{white-space:nowrap}.main{padding:25px 18px 50px}.topbar{margin-bottom:30px}.cards{grid-template-columns:1fr}.card.wide{grid-column:auto}.card{padding:22px}.pill{display:none}}
</style>
<style>.stage9-card{background:var(--card,#fff);color:var(--text,#1c2040);border:1px solid var(--border,#e5e7ef);border-radius:20px;padding:22px;margin:20px 0;box-shadow:0 10px 35px #13172a09}.stage9-card h2{margin:0 0 8px;font-size:20px}.stage9-card p{color:var(--muted,#6d7486);margin:0 0 14px}.stage9-head{display:flex;align-items:center;gap:18px}.stage9-head img{width:86px;height:86px;border-radius:20px}.stage9-card label{display:block;font-size:13px;font-weight:650;margin:14px 0 6px}.stage9-card select,.stage9-card textarea{width:100%;max-width:440px;padding:11px;border-radius:10px;border:1px solid var(--border,#dce0eb);background:var(--card,#fff);color:var(--text,#222);font:inherit}.stage9-button{border:0;border-radius:10px;background:#7158d9;color:#fff;padding:11px 16px;cursor:pointer;margin:10px 0;font:inherit}.stage9-avatars{display:grid;grid-template-columns:repeat(auto-fill,minmax(60px,1fr));gap:10px;margin-top:14px}.stage9-avatars img{width:100%;border-radius:14px}.stage9-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px}.stage9-grid strong{display:block}.stage9-note{font-size:13px;color:var(--muted,#777)}@media(max-width:600px){.stage9-card{padding:16px}.stage9-head img{width:64px;height:64px}}</style>
<style>.appearance-title{display:flex;justify-content:space-between;align-items:start}.appearance-symbol{font-size:32px;color:var(--accent)}.theme-options{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin:22px 0}.theme-choice{display:flex;flex-direction:column;gap:7px;padding:13px;border:1.5px solid var(--border);border-radius:17px;cursor:pointer;transition:transform .18s,border-color .18s,box-shadow .18s;position:relative}.theme-choice:hover{transform:translateY(-2px)}.theme-choice:has(input:checked){border-color:var(--accent);box-shadow:0 0 0 3px color-mix(in srgb,var(--accent) 13%,transparent)}.theme-choice input{position:absolute;right:15px;top:15px;accent-color:#8069e8;width:17px;height:17px}.theme-choice strong{font-size:15px}.theme-choice small{font-size:12px;color:var(--muted)}.theme-art{height:92px;border-radius:11px;display:block;position:relative;overflow:hidden;border:1px solid #9ca1bc44}.theme-art i{position:absolute;top:15px;left:13px;width:22%;height:68%;border-radius:5px}.theme-art b{position:absolute;top:15px;left:39%;width:49%;height:22%;border-radius:5px;box-shadow:0 28px 0 -2px currentColor,0 48px 0 -2px currentColor}.theme-art-light{background:#f4f5fa;color:#dedff0}.theme-art-light i{background:#d5d8ec}.theme-art-dark{background:#111625;color:#333a58}.theme-art-dark i{background:#282f4c}.theme-art-system{background:linear-gradient(90deg,#f4f5fa 50%,#111625 50%);color:#8d8eaf}.theme-art-system i{background:linear-gradient(90deg,#d5d8ec 50%,#282f4c 50%)}.appearance-save{margin-top:4px}@media(max-width:650px){.theme-options{gap:8px}.theme-choice{padding:8px}.theme-art{height:65px}.theme-choice strong{font-size:12px}.theme-choice small{font-size:10px}}</style>
<script src="<?= htmlspecialchars(url('assets/js/theme-global.js?v=20261107-2'), ENT_QUOTES, 'UTF-8') ?>"></script>
<style id="ulearn-notification-contrast-fix">
#uln-settings-host{background:var(--card)!important;color:var(--text)!important;border-color:var(--border)!important}
#uln-settings-host h2,#uln-settings-host h3,#uln-settings-host label,#uln-settings-host #uln-panel{color:var(--text)!important}
#uln-settings-host p,#uln-settings-host #uln-permission-note{color:var(--muted)!important}
#uln-settings-host a{color:var(--accent)!important}
</style>
<link rel="stylesheet" href="<?= e(url('assets/css/ulearn-community-phase4.css?v=1')) ?>">
</head>
<body>
<div class="layout">
<aside class="sidebar" id="studentSidebar">
    <a class="brand" href="<?= e(url('dashboard/index.php')) ?>">
        u learn<span>.</span>
    </a>

    <nav aria-label="Student navigation">
        <p class="nav-label">Your workspace</p>

        <div class="nav ul-nav">
            <a href="<?= e(url('dashboard/index.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/></svg><span>Overview</span></a>
            <a href="<?= e(url('dashboard/index.php#modules')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 7v14M3 18V4a1 1 0 0 1 1-1h5a3 3 0 0 1 3 3 3 3 0 0 1 3-3h5a1 1 0 0 1 1 1v14h-6a3 3 0 0 0-3 3 3 3 0 0 0-3-3z"/></svg><span>My Modules</span></a>
            <a href="<?= e(url('dashboard/explore.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="m16 8-2 6-6 2 2-6z"/></svg><span>Explore & Enroll</span></a>
            <a href="<?= e(url('dashboard/notes-library.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="8" y="7" width="12" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h2"/></svg><span>Notes Library</span></a>
            <a href="<?= e(url('dashboard/notes.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V8m-4 4 4-4 4 4M4 17a5 5 0 0 1 1-9 7 7 0 0 1 13 0 5 5 0 0 1 2 9"/></svg><span>Upload Notes</span></a>
            <a href="<?= e(url('dashboard/quiz-history.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-7M3 3v6h6M12 7v5l3 2"/></svg><span>Quiz History</span></a>
            <a href="<?= e(url('dashboard/learning-insights.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="m3 17 6-6 4 4 8-8M15 7h6v6"/></svg><span>Learning Insights</span></a>
            <a href="<?= e(url('dashboard/analytics.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20V12h4v8M10 20V4h4v16M16 20v-9h4v9M2 20h20"/></svg><span>Performance Analytics</span></a>
            <a href="<?= e(url('dashboard/topic-analysis.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/></svg><span>Topic Weaknesses</span></a>
            <a href="<?= e(url('dashboard/ai-study.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="m12 3 1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8zM19 17l.7 1.3L21 19l-1.3.7L19 21l-.7-1.3L17 19l1.3-.7z"/></svg><span>AI Study Assistant</span></a>
            <a href="<?= e(url('dashboard/consultations.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M20 11V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v10l4-3h11a2 2 0 0 0 2-1zM9 16v3a2 2 0 0 0 2 2h8l3 2v-9"/></svg><span>Consultations</span></a>
            <a href="<?= e(url('dashboard/kuppiya.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3"/><path d="M3 20v-2a6 6 0 0 1 12 0v2M17 5a3 3 0 0 1 0 6M17 15a5 5 0 0 1 4 5"/></svg><span>Kuppiya Sessions</span></a>
            <a href="<?= e(url('dashboard/leaderboard.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M7 4h10v7a5 5 0 0 1-10 0zM7 6H4v3a4 4 0 0 0 4 4m9-7h3v3a4 4 0 0 1-4 4M12 16v4m-4 1h8"/></svg><span>Leaderboard</span></a>
            <a href="<?= e(url('dashboard/notifications.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg><span>Notifications</span></a>
            <a class="active" href="<?= e(url('dashboard/profile.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M4 17h16"/><circle cx="8" cy="7" r="2"/><circle cx="16" cy="17" r="2"/></svg><span>My Profile & Settings</span></a>
        </div>
    </nav>

    <div class="sidebar-bottom">
        <small>Signed in as</small>
        <strong><?= e((string)$profile['full_name']) ?></strong>
        <small><?= e((string)$profile['email']) ?></small>
    </div>
</aside>
<main class="main">
<header class="topbar"><div class="eyebrow">U LEARN / STUDENT SPACE</div><div class="top-actions"><span class="pill">Student account</span><button class="theme-btn" id="themeToggle" type="button" aria-label="Toggle light and dark mode">☾</button><form method="post" action="<?= e(url('auth/logout.php')) ?>" style="margin:0"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><button type="submit" class="logout" style="border:0;background:none;cursor:pointer">Log out ↗</button></form></div></header>
<section class="intro"><div class="eyebrow">Account management</div><h1>My Profile <span>&</span> Settings<span>.</span></h1><p>Manage your personal details, secure your account, and choose your preferred appearance.</p></section>
<div class="profile-head"><div class="avatar" aria-hidden="true"><?= e($initial) ?></div><div><strong><?= e((string)$profile['full_name']) ?></strong><small><?= e((string)$profile['email']) ?></small></div></div>
<?php if ($notice !== ''): ?><div class="message success" role="status"><?= e($notice) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="message error" role="alert"><?= e($error) ?></div><?php endif; ?>
<div class="cards">
<section class="card"><h2>Personal information</h2><p class="hint">Update how your name appears in ULearn.</p>
<form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="update_profile"><div class="field"><label for="full_name">Full name</label><input id="full_name" name="full_name" maxlength="120" required autocomplete="name" value="<?= e((string)$profile['full_name']) ?>"></div><div class="field"><label for="email">Email address</label><input id="email" type="email" readonly value="<?= e((string)$profile['email']) ?>"></div><button class="btn" type="submit">Save profile</button></form></section>
<section class="card"><h2>Change password</h2><p class="hint">Use at least 8 characters, one uppercase letter, one number and one symbol.</p>
<form method="post" autocomplete="on"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="change_password"><div class="field"><label for="current_password">Current password</label><input id="current_password" name="current_password" type="password" autocomplete="current-password" required></div><div class="field"><label for="new_password">New password</label><input id="new_password" name="new_password" type="password" autocomplete="new-password" minlength="8" maxlength="128" required></div><div class="field"><label for="confirm_password">Confirm new password</label><input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" minlength="8" maxlength="128" required></div><button class="btn" type="submit">Change password</button></form></section>
<section class="card wide appearance-modern"><div class="appearance-title"><div><h2>Appearance</h2><p class="hint">A space that feels right, day or night.</p></div><span class="appearance-symbol" aria-hidden="true">◐</span></div><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="update_theme"><div class="theme-options" role="radiogroup" aria-label="Theme preference"><label class="theme-choice"><input type="radio" name="theme" value="light" <?= $theme === 'light' ? 'checked' : '' ?>><span class="theme-art theme-art-light"><i></i><b></b></span><strong>☀ Light</strong><small>Bright and focused</small></label><label class="theme-choice"><input type="radio" name="theme" value="dark" <?= $theme === 'dark' ? 'checked' : '' ?>><span class="theme-art theme-art-dark"><i></i><b></b></span><strong>☾ Dark</strong><small>Comfortable at night</small></label><label class="theme-choice"><input type="radio" name="theme" value="system" <?= $theme === 'system' ? 'checked' : '' ?>><span class="theme-art theme-art-system"><i></i><b></b></span><strong>◐ System</strong><small>Match your device</small></label></div><button class="btn appearance-save" type="submit">Save preference</button></form></section>
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
  var selector = document.querySelector('input[name=theme]:checked');
  document.querySelectorAll('input[name=theme]').forEach(function(radio){ radio.addEventListener('change',function(){var dark=radio.value==='dark'||(radio.value==='system'&&window.matchMedia('(prefers-color-scheme: dark)').matches);document.documentElement.setAttribute('data-theme',dark?'dark':'light');updateIcon();}); });
  selector.form.addEventListener('submit', function () {
    var chosen = document.querySelector('input[name=theme]:checked');
    if (chosen) localStorage.setItem('ulearn-theme', chosen.value);
  });
})();
</script>
<?php
$stage9Academic=$pdo->prepare('SELECT d.name degree_name,b.batch_code,p.degree_id,p.batch_id FROM student_academic_profiles p LEFT JOIN academic_degrees d ON d.id=p.degree_id LEFT JOIN academic_batches b ON b.id=p.batch_id WHERE p.user_id=? LIMIT 1');
$stage9Academic->execute([$userId]);$stage9Academic=$stage9Academic->fetch(PDO::FETCH_ASSOC)?:[];
$stage9Degrees=$pdo->query('SELECT id,name FROM academic_degrees WHERE is_active=1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$stage9Intakes=$pdo->query('SELECT di.degree_id,di.batch_id,b.batch_code FROM academic_degree_intakes di JOIN academic_batches b ON b.id=di.batch_id WHERE di.is_active=1 AND b.is_active=1 ORDER BY b.batch_code DESC')->fetchAll(PDO::FETCH_ASSOC);
$stage9Requests=$pdo->prepare('SELECT r.status,r.reason,r.created_at,r.admin_note,d.name degree_name,b.batch_code FROM academic_change_requests r JOIN academic_degrees d ON d.id=r.requested_degree_id JOIN academic_batches b ON b.id=r.requested_batch_id WHERE r.student_user_id=? ORDER BY r.id DESC LIMIT 5');
$stage9Requests->execute([$userId]);$stage9Requests=$stage9Requests->fetchAll(PDO::FETCH_ASSOC);
?>
<section class="stage9-card"><h2>Academic identity <span aria-label="Locked">🔒</span></h2><p>Your programme and intake are locked after registration. Only an administrator can approve a correction.</p><div class="stage9-grid"><div><small>Degree programme</small><strong><?= e((string)($stage9Academic['degree_name']??'Not assigned')) ?></strong></div><div><small>Intake</small><strong><?= e((string)($stage9Academic['batch_code']??'Not assigned')) ?></strong></div></div>
<details style="margin-top:20px"><summary style="cursor:pointer;font-weight:650">Request a programme / intake change</summary><form method="post" style="margin-top:14px"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="stage9_request"><label for="stage9_degree">Requested programme</label><select id="stage9_degree" name="degree_id" required><option value="">Choose degree</option><?php foreach($stage9Degrees as $d): ?><option value="<?= (int)$d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach ?></select><label for="stage9_batch">Requested intake</label><select id="stage9_batch" name="batch_id" required><option value="">Select a programme first</option></select><label for="stage9_reason">Reason for the change</label><textarea id="stage9_reason" name="reason" minlength="12" maxlength="1000" rows="3" required></textarea><div><button class="stage9-button">Send request to admin</button></div></form></details>
<?php if($stage9Requests): ?><h3 style="margin-top:20px">My requests</h3><?php foreach($stage9Requests as $r): ?><p class="stage9-note"><strong><?= e(ucfirst($r['status'])) ?></strong> · <?= e($r['degree_name'].' / '.$r['batch_code']) ?> · <?= e($r['created_at']) ?><?php if($r['admin_note']): ?> · <?= e($r['admin_note']) ?><?php endif ?></p><?php endforeach ?><?php endif ?>
</section><script>const stage9Intakes=<?= json_encode($stage9Intakes,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;const stage9Deg=document.getElementById('stage9_degree'),stage9Bat=document.getElementById('stage9_batch');if(stage9Deg&&stage9Bat){stage9Deg.addEventListener('change',()=>{stage9Bat.replaceChildren(new Option('Choose intake',''));stage9Intakes.filter(i=>String(i.degree_id)===stage9Deg.value).forEach(i=>stage9Bat.add(new Option(i.batch_code,i.batch_id)))})}</script>

<?php require __DIR__.'/../includes/stage9-avatar.php'; ?>
<script src="<?= e(url('assets/js/notifications-live.js?v=20261107-profile-only-2')) ?>" data-base="<?= e(rtrim(url(), '/')) ?>" defer></script>
<?php require_once __DIR__ . '/../includes/student-bot.php'; ?></body>
</html>
