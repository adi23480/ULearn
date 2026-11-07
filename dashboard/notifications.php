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
<script>
(function(){try{let saved=localStorage.getItem('ulearn-theme');let preferred=<?= json_encode($theme) ?>;let mode=(saved==='light'||saved==='dark')?saved:((preferred==='light'||preferred==='dark')?preferred:(matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'));document.documentElement.dataset.theme=mode;}catch(e){}})();
function toggleTheme(){let next=document.documentElement.dataset.theme==='dark'?'light':'dark';document.documentElement.dataset.theme=next;try{localStorage.setItem('ulearn-theme',next);}catch(e){}updateThemeButton();}
function updateThemeButton(){let b=document.getElementById('themeButton');if(b){let dark=document.documentElement.dataset.theme==='dark';b.textContent=dark?'☀':'☾';b.setAttribute('aria-label',dark?'Switch to light mode':'Switch to dark mode');}}
document.addEventListener('DOMContentLoaded',updateThemeButton);
</script>
<style>
:root{color-scheme:light;--bg:#f7f8fc;--surface:#fff;--surface-alt:#f1f2f8;--text:#171927;--muted:#777b90;--border:#e7e8f0;--accent:#6756e8;--accent-light:#f0edff;--shadow:0 10px 40px rgba(30,30,60,.04)}
:root[data-theme=dark]{color-scheme:dark;--bg:#0c0e16;--surface:#161924;--surface-alt:#202431;--text:#f4f4fa;--muted:#a0a4b6;--border:#2b2e3c;--accent:#a99aff;--accent-light:#292440;--shadow:none}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif;line-height:1.55}a{color:inherit;text-decoration:none}button{font:inherit;cursor:pointer}.layout{display:grid;grid-template-columns:250px minmax(0,1fr);min-height:100vh}.sidebar{background:var(--surface);border-right:1px solid var(--border);padding:32px 20px;display:flex;flex-direction:column;gap:38px}.brand{font-size:26px;font-weight:800;letter-spacing:-1.5px;padding-left:12px}.brand span{color:var(--accent)}.nav-label,.eyebrow{font-size:11px;font-weight:750;letter-spacing:1.7px;text-transform:uppercase;color:var(--muted)}.nav-label{margin:0 12px 14px}.nav{display:grid;gap:6px}.nav a{display:block;padding:12px 14px;border-radius:12px;font-size:14px;font-weight:600}.nav a:hover{background:var(--surface-alt)}.nav a.active{background:var(--accent-light);color:var(--accent)}.sidebar-bottom{margin-top:auto;border:1px solid var(--border);border-radius:16px;padding:16px;font-size:13px;overflow-wrap:anywhere}.sidebar-bottom small{color:var(--muted)}.sidebar-bottom strong{display:block}.main{min-width:0;padding:34px clamp(20px,5vw,76px) 80px;max-width:1200px;width:100%}.topbar{display:flex;align-items:center;justify-content:space-between;gap:18px;margin-bottom:46px}.topbar-right{display:flex;align-items:center;gap:14px}.theme-button{width:42px;height:42px;border:1px solid var(--border);border-radius:12px;background:var(--surface);color:var(--text);font-size:20px}.avatar{width:40px;height:40px;border-radius:50%;background:var(--accent-light);color:var(--accent);display:grid;place-items:center;font-weight:800}.eyebrow{display:block}h1{font-size:clamp(34px,4vw,52px);line-height:1.12;letter-spacing:-1.8px;margin:12px 0}h2{font-size:23px;letter-spacing:-.6px;margin:0 0 16px}.lead{color:var(--muted);margin:0 0 28px}.card{background:var(--surface);border:1px solid var(--border);border-radius:19px;padding:25px;box-shadow:var(--shadow)}.card+.card{margin-top:18px}.section{margin:34px 0 16px}.section-head{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}.muted{color:var(--muted)}.badge{display:inline-block;padding:5px 10px;border-radius:99px;background:var(--accent-light);color:var(--accent);font-size:12px;font-weight:700}.notification{padding:18px 0;border-bottom:1px solid var(--border);display:flex;align-items:flex-start;justify-content:space-between;gap:18px}.notification:last-child{border-bottom:0;padding-bottom:0}.notification:first-child{padding-top:0}.notification.unread{border-left:3px solid var(--accent);padding-left:15px}.notification h3{font-size:16px;margin:0 0 6px}.notification p,.announcement p{white-space:pre-wrap;overflow-wrap:anywhere;margin:0 0 9px;color:var(--muted);font-size:14px}.meta{color:var(--muted);font-size:12px}.actions{display:flex;gap:9px;flex-wrap:wrap;align-items:center}.button{display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--border);background:var(--surface-alt);color:var(--text);padding:9px 13px;border-radius:10px;font-size:13px;font-weight:700}.button.primary{background:var(--accent);color:white;border-color:var(--accent)}.button:hover,.theme-button:hover{filter:brightness(.96);transform:translateY(-1px)}.tabs{display:flex;gap:9px;flex-wrap:wrap;margin:20px 0}.tabs a{border:1px solid var(--border);border-radius:10px;padding:9px 14px;font-size:13px}.tabs a.active{background:var(--accent-light);color:var(--accent);border-color:var(--accent)}.empty{padding:28px;text-align:center;color:var(--muted);border:1px dashed var(--border);border-radius:13px}.flash{padding:13px 17px;border-radius:12px;background:var(--accent-light);color:var(--accent);margin-bottom:20px}.pagination{display:flex;justify-content:space-between;gap:10px;margin-top:18px}.announcement{border-top:1px solid var(--border);padding:16px 0}.announcement:first-of-type{border-top:0}.announcement h3{margin:0 0 7px;font-size:16px}.footer{margin-top:25px;color:var(--muted);font-size:12px}@media(max-width:900px){.layout{grid-template-columns:1fr}.sidebar{border-right:0;border-bottom:1px solid var(--border);gap:15px;padding:18px}.nav{display:flex;flex-wrap:wrap}.sidebar-bottom{display:none}.main{padding:24px 18px 55px}.topbar{margin-bottom:30px}}@media(max-width:580px){.notification{display:block}.notification .actions{margin-top:12px}.card{padding:19px}}
.thread-list{display:grid;gap:12px}.thread.card{padding:0;overflow:hidden}.thread-toggle{display:flex;align-items:center;gap:14px;width:100%;padding:20px 22px;background:transparent;border:0;text-align:left;color:var(--text)}.thread-toggle:hover{background:var(--surface-alt)}.thread-icon{width:42px;height:42px;border-radius:13px;background:var(--accent-light);color:var(--accent);display:grid;place-items:center;flex-shrink:0}.thread-info{display:grid;gap:3px;flex:1;min-width:0}.thread-info strong{font-size:15px}.thread-preview{color:var(--muted);font-size:13px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}.thread-info small{color:var(--muted);font-size:11px}.thread-badge{background:var(--accent-light);color:var(--accent);border-radius:99px;padding:5px 10px;font-size:12px;font-weight:750;white-space:nowrap}.chevron{font-size:20px;color:var(--muted);transition:transform .2s}.thread-toggle[aria-expanded=true] .chevron{transform:rotate(180deg)}.thread-detail{padding:0 22px 20px 78px}.thread-actions{display:flex;flex-wrap:wrap;gap:9px;margin:4px 0 16px}.thread-messages{border-top:1px solid var(--border);max-height:360px;overflow:auto}.thread-message{padding:13px 0;border-bottom:1px solid var(--border)}.thread-message:last-child{border:0}.thread-message p{font-size:13px;color:var(--muted);margin:5px 0;white-space:pre-wrap;overflow-wrap:anywhere}.message-heading{display:flex;gap:12px;justify-content:space-between;flex-wrap:wrap;font-size:13px}.filter.active{background:var(--accent-light);color:var(--accent);border-color:var(--accent)}[hidden]{display:none!important}@media(max-width:580px){.thread-toggle{padding:15px;gap:10px}.thread-detail{padding:0 15px 15px}.thread-icon{width:34px;height:34px}.thread-badge{font-size:10px}}
</style>

<script src="<?= htmlspecialchars(url('assets/js/theme-global.js?v=20261107-2'), ENT_QUOTES, 'UTF-8') ?>"></script>
<link rel="stylesheet" href="<?= e(url('assets/css/ulearn-community-phase4.css?v=1')) ?>">
</head>
<body>
<div class="layout">
<aside class="sidebar">
<a class="brand" href="<?= e(url()) ?>">u learn<span>.</span></a>
<nav aria-label="Navigation"><p class="nav-label">Your workspace</p><div class="nav">
<a href="<?= e(url($home)) ?>">◫ &nbsp; Dashboard</a>
<?php if ($role === 'student'): ?><a href="<?= e(url('dashboard/kuppiya.php')) ?>">♧ &nbsp; Kuppiya Sessions</a><a href="<?= e(url('dashboard/quiz-history.php')) ?>">◷ &nbsp; Quiz History</a><?php endif; ?>
<?php if ($role === 'lecturer'): ?><a href="<?= e(url('lecturer/consultations.php')) ?>">◷ &nbsp; Consultations</a><?php endif; ?>
<a class="active" href="<?= e(url('dashboard/notifications.php')) ?>">♧ &nbsp; Notifications</a>
</div></nav>
<div class="sidebar-bottom"><small>Signed in as</small><strong><?= e((string)($u['full_name'] ?? 'User')) ?></strong><small><?= e((string)($u['email'] ?? '')) ?></small></div>
</aside>
<main class="main">
<header class="topbar"><span class="eyebrow">U LEARN / NOTIFICATION CENTER</span><div class="topbar-right"><button id="themeButton" class="theme-button" type="button" onclick="toggleTheme()" aria-label="Toggle appearance">☾</button><div class="avatar"><?= e($initial) ?></div></div></header>
<span class="eyebrow">STAY IN THE LOOP</span><h1>Notifications<span style="color:var(--accent)">.</span></h1><p class="lead">Your updates, reminders and announcements in one place.</p>
<?php if ($notice !== ''): ?><div class="flash" role="status"><?= e($notice) ?></div><?php endif; ?>
<div class="section-head section"><h2>University announcements</h2><span class="badge"><?= count($announcements) ?> current</span></div>
<div class="card">
<?php if (!$announcements): ?><div class="empty">No published announcements for your account yet.</div>
<?php else: foreach ($announcements as $a): ?><article class="announcement"><h3><?= e($a['title']) ?></h3><p><?= e($a['body']) ?></p><span class="meta"><?= e((string)($a['published_at'] ?: $a['created_at'])) ?></span></article><?php endforeach; endif; ?>
</div>
<div class="section-head section"><h2>Personal notifications</h2><span class="badge" id="unreadBadge"><?= $unread ?> unread</span></div>
<div class="tabs"><button class="button filter active" type="button" data-filter="all">All</button><button class="button filter" type="button" data-filter="unread">Unread</button><button class="button" id="readAll" type="button" <?= !$unread ? 'hidden' : '' ?>>Mark all as read</button></div>
<?php if (!$groups): ?><div class="card empty">You're all caught up. New updates will appear here.</div><?php endif; ?>
<?php foreach ($groups as $category => $threads): ?>
<section class="topic-section" aria-label="<?= e($category) ?>">
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
<p class="footer">Announcements and notifications will populate as admins publish updates and ULearn actions are connected.</p>
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
