<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
$u = require_login();
$pdo = db();
$userId = (int)$u['id'];
$role = (string)($u['role'] ?? 'student');
$theme = (string)($u['theme'] ?? 'system');
$home = $role === 'lecturer' ? 'lecturer/index.php' : (in_array($role, ['admin','main_admin'], true) ? 'admin/index.php' : 'dashboard/index.php');
$notice = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ajax = ($_POST['ajax'] ?? '') === '1';
    if ($ajax) header('Content-Type: application/json; charset=utf-8');
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $ids = [];
    if ($action === 'read_one') {
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
        if ($id && $id > 0) $ids = [$id];
    } elseif ($action === 'read_group') {
        $raw = $_POST['ids'] ?? [];
        if (is_array($raw)) foreach (array_slice($raw, 0, 500) as $id) {
            $id = filter_var($id, FILTER_VALIDATE_INT);
            if ($id && $id > 0) $ids[] = $id;
        }
        $ids = array_values(array_unique($ids));
    } elseif ($action === 'read_all') {
        $pdo->prepare('UPDATE notifications SET is_read = 1, read_at = COALESCE(read_at, NOW()) WHERE user_id = ? AND is_read = 0')->execute([$userId]);
    }
    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("UPDATE notifications SET is_read = 1, read_at = COALESCE(read_at, NOW()) WHERE user_id = ? AND id IN ($placeholders)")->execute(array_merge([$userId], $ids));
    }
    $q = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $q->execute([$userId]);
    $remaining = (int)$q->fetchColumn();
    if ($ajax) { echo json_encode(['ok'=>true,'unread'=>$remaining]); exit; }
    go('dashboard/notifications.php');
}
$unreadStmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
$unreadStmt->execute([$userId]);
$unread = (int)$unreadStmt->fetchColumn();
// Load the latest 500 records and group by their actual destination/conversation.
$stmt = $pdo->prepare('SELECT id, type, title, message, target_url, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 500');
$stmt->execute([$userId]);
$notifications = $stmt->fetchAll();
function notification_group_key(array $n): array {
    $path = (string)($n['target_url'] ?? '');
    $type = strtolower((string)($n['type'] ?? ''));
    $title = trim((string)($n['title'] ?? ''));
    $query = parse_url($path, PHP_URL_QUERY);
    parse_str(is_string($query) ? $query : '', $params);
    $id = (int)($params['id'] ?? $params['session_id'] ?? $params['consultation_id'] ?? 0);
    if (str_contains($path, 'consultation')) {
        return ['Consultations', 'consultation:' . ($id ?: $path), 'Consultation', $path];
    }
    if (str_contains($path, 'kuppiya') || str_contains($type, 'kuppiya')) {
        return ['Kuppiya Sessions', 'kuppiya:' . ($id ?: $path), 'Kuppiya session', $path];
    }
    if (str_contains($type, 'message') || str_contains($type, 'chat')) {
        return ['Messages', 'message:' . ($id ?: $path ?: $title), 'Conversation', $path];
    }
    if (str_contains($type, 'quiz') || str_contains($path, 'quiz')) {
        return ['Academic Updates', 'quiz:' . ($path ?: $title), 'Quiz update', $path];
    }
    if (str_contains($type, 'material') || str_contains($path, 'material')) {
        return ['Academic Updates', 'material:' . ($path ?: $title), 'Study material', $path];
    }
    return ['Other Updates', 'other:' . ($path ?: $title), $title ?: 'Update', $path];
}
$groups = [];
foreach ($notifications as $n) {
    [$category, $key, $label, $path] = notification_group_key($n);
    if (!isset($groups[$category][$key])) {
        $groups[$category][$key] = ['category'=>$category, 'label'=>$label, 'title'=>(string)$n['title'], 'latest'=>(string)$n['message'], 'date'=>(string)$n['created_at'], 'path'=>$path, 'items'=>[], 'unread'=>0];
    }
    $groups[$category][$key]['items'][] = $n;
    if (!(int)$n['is_read']) $groups[$category][$key]['unread']++;
}
// Announcements are shown directly; this avoids creating one notification row per account.
$audience = in_array($role, ['student','lecturer'], true) ? $role : 'admin';
$annStmt = $pdo->prepare("SELECT a.id, a.title, a.body, a.published_at, a.created_at
    FROM announcements a
    WHERE a.is_published = 1
      AND a.audience IN ('all', ?)
      AND (a.published_at IS NULL OR a.published_at <= NOW())
      AND (a.expires_at IS NULL OR a.expires_at > NOW())
    ORDER BY COALESCE(a.published_at, a.created_at) DESC LIMIT 5");
$annStmt->execute([$audience]);
$announcements = $annStmt->fetchAll();

// Only permit relative paths within this application, never external or protocol-relative URLs.
function notification_destination(?string $path): ?string {
    if ($path === null || $path === '' || strlen($path) > 500) return null;
    if (!preg_match('~^(dashboard|lecturer|admin)/[a-zA-Z0-9_./?=&%+-]+$~D', $path)) return null;
    if (str_contains($path, '..') || str_contains($path, '//') || str_contains($path, '%')) return null;
    return url($path);
}
$firstName = trim(explode(' ', trim((string)($u['full_name'] ?? 'Student')))[0]);
$initial = strtoupper(substr($firstName !== '' ? $firstName : 'U', 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Notifications & Announcements — ULearn</title>





<script>(function(){try{var t=localStorage.getItem('ulearn-theme');if(t!=='light'&&t!=='dark'){var p=<?= json_encode($theme) ?>;t=(p==='light'||p==='dark')?p:(matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light')}document.documentElement.setAttribute('data-theme',t)}catch(e){document.documentElement.setAttribute('data-theme','light')}})();</script>
<link rel="stylesheet" href="<?= e(url('assets/css/ulearn-notifications-premium.css?v=1')) ?>">
<script src="<?= e(url('assets/js/ulearn-notifications-premium.js?v=1')) ?>" defer></script>
</head>
<body>
<div class="page-progress" id="pageProgress" aria-hidden="true"></div><button class="mobile-menu" id="mobileMenu" type="button" aria-label="Open navigation" aria-expanded="false">☰</button><div class="sidebar-scrim" id="sidebarScrim"></div><div class="layout">
<aside class="sidebar" id="premiumSidebar">
<a class="brand" href="<?= e(url()) ?>"><img class="brand-logo" src="<?= e(url('assets/images/nsbm-shield-only.png')) ?>" alt="NSBM shield"><span class="brand-word">uLearn<span>.</span><small>STUDENT EXPERIENCE</small></span></a>
<nav aria-label="Navigation"><p class="nav-label">Your workspace</p><div class="nav">
<a href="<?= e(url($home)) ?>">◫ &nbsp; Dashboard</a>
<?php if ($role === 'student'): ?><a href="<?= e(url('dashboard/kuppiya.php')) ?>">♧ &nbsp; Kuppiya Sessions</a><a href="<?= e(url('dashboard/quiz-history.php')) ?>">◷ &nbsp; Quiz History</a><?php endif; ?>
<?php if ($role === 'lecturer'): ?><a href="<?= e(url('lecturer/consultations.php')) ?>">◷ &nbsp; Consultations</a><?php endif; ?>
<a class="active" href="<?= e(url('dashboard/notifications.php')) ?>">♧ &nbsp; Notifications</a>
</div></nav>
<div class="sidebar-bottom"><small>Signed in as</small><strong><?= e((string)($u['full_name'] ?? 'User')) ?></strong><small><?= e((string)($u['email'] ?? '')) ?></small></div>
</aside>
<main class="main">
<header class="topbar"><div class="topbar-location"><span class="eyebrow">U LEARN / NOTIFICATION CENTER</span><span class="topbar-sub">Your campus, connected.</span></div><div class="topbar-right"><span class="timezone-chip">● LIVE UPDATES</span><button id="themeButton" class="theme-button" type="button" aria-label="Switch color theme" title="Switch light / dark theme"><span class="theme-glyph" id="themeGlyph">☾</span></button><a class="avatar" href="<?= e(url('dashboard/profile.php')) ?>" aria-label="Open profile"><?= e($initial) ?></a></div></header>
<section class="hero" aria-label="Notification center"><div class="hero-glow hero-glow-a"></div><div class="hero-glow hero-glow-b"></div><div class="hero-content"><span class="hero-kicker"><span class="pulse-dot"></span> STAY IN THE LOOP</span><h1>Never miss a <em>moment.</em></h1><p class="lead">Everything that matters across your learning journey. Announcements, conversations and updates — beautifully organized.</p><div class="hero-meta"><span>✦ ULearn Campus</span><span>↗ Always in sync</span></div></div><div class="hero-art" aria-hidden="true"><div class="hero-orbit orbit-one"></div><div class="hero-orbit orbit-two"></div><div class="hero-bell"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg></div><span class="float-dot dot-a">✦</span><span class="float-dot dot-b">●</span></div></section><div class="overview-strip"><div class="overview-stat"><span class="overview-icon">◈</span><div><strong id="premiumUnreadCount"><?= (int)$unread ?></strong><small>Unread updates</small></div></div><div class="overview-stat"><span class="overview-icon">▣</span><div><strong><?= count($announcements) ?></strong><small>Announcements</small></div></div><div class="overview-stat"><span class="overview-icon">◎</span><div><strong><?= array_sum(array_map('count', $groups)) ?></strong><small>Conversations</small></div></div></div>
<?php if ($notice !== ''): ?><div class="flash" role="status"><?= e($notice) ?></div><?php endif; ?>
<div class="section-head section reveal"><div><span class="section-kicker">FROM YOUR CAMPUS</span><h2>University announcements</h2></div><span class="badge"><?= count($announcements) ?> current</span></div>
<div class="card announcements-card reveal">
<?php if (!$announcements): ?><div class="empty">No published announcements for your account yet.</div>
<?php else: foreach ($announcements as $a): ?><article class="announcement"><h3><?= e($a['title']) ?></h3><p><?= e($a['body']) ?></p><span class="meta"><?= e((string)($a['published_at'] ?: $a['created_at'])) ?></span></article><?php endforeach; endif; ?>
</div>
<div class="section-head section reveal"><div><span class="section-kicker">JUST FOR YOU</span><h2>Personal notifications</h2></div><span class="badge" id="unreadBadge"><?= $unread ?> unread</span></div>
<div class="tabs reveal"><button class="button filter active" type="button" data-filter="all">All</button><button class="button filter" type="button" data-filter="unread">Unread</button><button class="button" id="readAll" type="button" <?= !$unread ? 'hidden' : '' ?>>Mark all as read</button></div>
<?php if (!$groups): ?><div class="card empty">You're all caught up. New updates will appear here.</div><?php endif; ?>
<?php foreach ($groups as $category => $threads): ?>
<section class="topic-section reveal" aria-label="<?= e($category) ?>">
<div class="section-head section"><h2><?= e($category) ?></h2><span class="badge topic-count"><?= count($threads) ?> topics</span></div>
<div class="thread-list">
<?php foreach ($threads as $thread): $destination = notification_destination($thread['path']); $ids = array_map(static fn($n)=>(int)$n['id'], $thread['items']); ?>
<article class="thread card" data-unread="<?= (int)$thread['unread'] ?>" data-ids="<?= e(implode(',', $ids)) ?>">
<div class="thread-top"><button type="button" class="thread-toggle" aria-expanded="false"><span class="thread-icon">✦</span><span class="thread-info"><strong><?= e($thread['title']) ?></strong><span class="thread-preview"><?= e($thread['latest']) ?></span><small><?= e($thread['date']) ?> · <?= count($thread['items']) ?> updates</small></span><span class="thread-badge" <?= !$thread['unread'] ? 'hidden' : '' ?>><?= (int)$thread['unread'] ?> new</span><span class="chevron">⌄</span></button></div>
<div class="thread-detail" hidden>
<div class="thread-actions"><?php if ($destination): ?><a class="button primary" href="<?= e($destination) ?>">Open <?= e($thread['label']) ?> ↗</a><?php endif; ?><button type="button" class="button read-thread" <?= !$thread['unread'] ? 'hidden' : '' ?>>Mark this topic read</button></div>
<div class="thread-messages">
<?php foreach ($thread['items'] as $n): ?><div class="thread-message" data-id="<?= (int)$n['id'] ?>" data-read="<?= (int)$n['is_read'] ?>"><div class="message-heading"><strong><?= e($n['title']) ?></strong><span class="meta"><?= e((string)$n['created_at']) ?></span></div><p><?= e($n['message']) ?></p></div><?php endforeach; ?>
</div></div></article>
<?php endforeach; ?></div></section>
<?php endforeach; ?>
<p class="footer reveal">Announcements and notifications will populate as admins publish updates and ULearn actions are connected.</p>
</main></div><script src="<?= e(url('assets/js/notifications-live.js?v=20261107-profile-only-2')) ?>" data-base="<?= e(rtrim(url(), '/')) ?>" defer></script>
<script>
(()=>{
const endpoint=<?= json_encode(url('dashboard/notifications.php')) ?>;
const csrf=<?= json_encode(csrf()) ?>;
const badge=document.getElementById('unreadBadge');
let filter='all';
function refresh(){let total=0;document.querySelectorAll('.thread').forEach(t=>{const n=Number(t.dataset.unread)||0;total+=n;t.querySelector('.thread-badge').hidden=!n;if(n)t.querySelector('.thread-badge').textContent=n+' new';t.querySelector('.read-thread').hidden=!n;t.hidden=filter==='unread'&&!n;});badge.textContent=total+' unread';document.getElementById('readAll').hidden=!total;document.querySelectorAll('.topic-section').forEach(s=>{s.hidden=![...s.querySelectorAll('.thread')].some(t=>!t.hidden);});}
async function mark(action,ids=[]){const data=new FormData();data.set('ajax','1');data.set('csrf',csrf);data.set('action',action);ids.forEach(id=>data.append('ids[]',id));const res=await fetch(endpoint,{method:'POST',body:data,credentials:'same-origin',headers:{'Accept':'application/json'}});if(!res.ok)throw Error('Unable to save changes');const result=await res.json();if(!result.ok)throw Error('Unable to save changes');return result;}
function markUI(t){t.dataset.unread='0';t.querySelectorAll('.thread-message').forEach(m=>m.dataset.read='1');refresh();}
document.querySelectorAll('.thread').forEach(t=>{t.querySelector('.thread-toggle').addEventListener('click',()=>{const detail=t.querySelector('.thread-detail');detail.hidden=!detail.hidden;t.querySelector('.thread-toggle').setAttribute('aria-expanded',String(!detail.hidden));});t.querySelector('.read-thread').addEventListener('click',async e=>{const btn=e.currentTarget;btn.disabled=true;btn.textContent='Saving…';try{await mark('read_group',t.dataset.ids.split(','));markUI(t);}catch(err){btn.textContent='Retry marking read';alert(err.message);}finally{btn.disabled=false;}});});
document.getElementById('readAll').addEventListener('click',async e=>{const btn=e.currentTarget;btn.disabled=true;btn.textContent='Saving…';try{await mark('read_all');document.querySelectorAll('.thread').forEach(markUI);}catch(err){alert(err.message);}finally{btn.disabled=false;btn.textContent='Mark all as read';}});
document.querySelectorAll('.filter').forEach(b=>b.addEventListener('click',()=>{filter=b.dataset.filter;document.querySelectorAll('.filter').forEach(x=>x.classList.toggle('active',x===b));refresh();}));
refresh();
})();
</script>
<?php require_once __DIR__ . '/../includes/student-bot.php'; ?></body></html>
