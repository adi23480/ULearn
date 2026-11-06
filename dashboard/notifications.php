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
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'read_one') {
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
        if ($id && $id > 0) {
            $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1, read_at = COALESCE(read_at, NOW()) WHERE id = ? AND user_id = ?');
            $stmt->execute([$id, $userId]);
            $_SESSION['notification_flash'] = 'Notification marked as read.';
        }
    } elseif ($action === 'read_all') {
        $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1, read_at = COALESCE(read_at, NOW()) WHERE user_id = ? AND is_read = 0');
        $stmt->execute([$userId]);
        $_SESSION['notification_flash'] = 'All notifications marked as read.';
    } else {
        $_SESSION['notification_flash'] = 'Unknown action.';
    }
    go('dashboard/notifications.php');
}
if (isset($_SESSION['notification_flash'])) {
    $notice = (string)$_SESSION['notification_flash'];
    unset($_SESSION['notification_flash']);
}
$filter = (string)($_GET['filter'] ?? 'all');
if (!in_array($filter, ['all','unread'], true)) $filter = 'all';
$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
$page = $page && $page > 0 ? min($page, 10000) : 1;
$perPage = 15;
$unreadStmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
$unreadStmt->execute([$userId]);
$unread = (int)$unreadStmt->fetchColumn();
$countSql = 'SELECT COUNT(*) FROM notifications WHERE user_id = ?' . ($filter === 'unread' ? ' AND is_read = 0' : '');
$countStmt = $pdo->prepare($countSql);
$countStmt->execute([$userId]);
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$sql = 'SELECT id, type, title, message, target_url, is_read, created_at FROM notifications WHERE user_id = ?' . ($filter === 'unread' ? ' AND is_read = 0' : '') . ' ORDER BY created_at DESC, id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset;
$stmt = $pdo->prepare($sql);
$stmt->execute([$userId]);
$notifications = $stmt->fetchAll();

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
</style>
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
<header class="topbar"><span class="eyebrow">U LEARN / NOTIFICATION CENTER</span><div class="topbar-right"><button id="themeButton" class="theme-button" type="button" onclick="toggleTheme()" aria-label="Toggle appearance">☾</button>

<?php
// Link to the lecturer's profile page.
?>
<a class="avatar"
   href="<?= e(url('lecturer/profile.php')) ?>"
   title="My Profile">
    <?= e($initial) ?>
</a>
</div></header>
<span class="eyebrow">STAY IN THE LOOP</span><h1>Notifications<span style="color:var(--accent)">.</span></h1><p class="lead">Your updates, reminders and announcements in one place.</p>
<?php if ($notice !== ''): ?><div class="flash" role="status"><?= e($notice) ?></div><?php endif; ?>
<div class="section-head section"><h2>University announcements</h2><span class="badge"><?= count($announcements) ?> current</span></div>
<div class="card">
<?php if (!$announcements): ?><div class="empty">No published announcements for your account yet.</div>
<?php else: foreach ($announcements as $a): ?><article class="announcement"><h3><?= e($a['title']) ?></h3><p><?= e($a['body']) ?></p><span class="meta"><?= e((string)($a['published_at'] ?: $a['created_at'])) ?></span></article><?php endforeach; endif; ?>
</div>
<div class="section-head section"><h2>Personal notifications</h2><span class="badge"><?= $unread ?> unread</span></div>
<div class="tabs"><a class="<?= $filter === 'all' ? 'active' : '' ?>" href="<?= e(url('dashboard/notifications.php')) ?>">All</a><a class="<?= $filter === 'unread' ? 'active' : '' ?>" href="<?= e(url('dashboard/notifications.php?filter=unread')) ?>">Unread (<?= $unread ?>)</a>
<?php if ($unread): ?><form method="post" action="<?= e(url('dashboard/notifications.php')) ?>"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="read_all"><button class="button" type="submit">Mark all as read</button></form><?php endif; ?></div>
<div class="card">
<?php if (!$notifications): ?><div class="empty"><?= $filter === 'unread' ? 'You have no unread notifications.' : 'No personal notifications yet. New updates will appear here when connected features send them.' ?></div>
<?php else: foreach ($notifications as $n): $destination = notification_destination($n['target_url']); ?>
<article class="notification <?= (int)$n['is_read'] === 0 ? 'unread' : '' ?>"><div><h3><?= e($n['title']) ?> <?php if (!(int)$n['is_read']): ?><span class="badge">New</span><?php endif; ?></h3><p><?= e($n['message']) ?></p><span class="meta"><?= e((string)$n['created_at']) ?> · <?= e(str_replace('_',' ',(string)$n['type'])) ?></span></div><div class="actions">
<?php if ($destination): ?><a class="button primary" href="<?= e($destination) ?>">Open ↗</a><?php endif; ?>
<?php if (!(int)$n['is_read']): ?><form method="post" action="<?= e(url('dashboard/notifications.php')) ?>"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="read_one"><input type="hidden" name="id" value="<?= (int)$n['id'] ?>"><button class="button" type="submit">Mark read</button></form><?php endif; ?>
</div></article>
<?php endforeach; endif; ?>
</div>
<?php if ($totalPages > 1): ?><div class="pagination"><div><?php if ($page > 1): ?><a class="button" href="<?= e(url('dashboard/notifications.php?filter='.$filter.'&page='.($page-1))) ?>">← Previous</a><?php endif; ?></div><span class="meta">Page <?= $page ?> of <?= $totalPages ?></span><div><?php if ($page < $totalPages): ?><a class="button" href="<?= e(url('dashboard/notifications.php?filter='.$filter.'&page='.($page+1))) ?>">Next →</a><?php endif; ?></div></div><?php endif; ?>
<p class="footer">Announcements and notifications will populate as admins publish updates and ULearn actions are connected.</p>
</main></div></body></html>
