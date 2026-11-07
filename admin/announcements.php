<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
$user = require_login();
if (!in_array((string)($user['role'] ?? ''), ['admin', 'main_admin'], true)) { http_response_code(403); exit('Access denied.'); }
$pdo = db();
$uid = (int)$user['id'];
$error = '';
$flash = (string)($_SESSION['announcement_flash'] ?? '');
unset($_SESSION['announcement_flash']);
$allowedAudiences = ['all', 'student', 'lecturer', 'admin'];
function announce_redirect(): void { go('admin/announcements.php'); }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    if ($action === 'create' || $action === 'update') {
        $title = trim((string)($_POST['title'] ?? ''));
        $body = trim((string)($_POST['body'] ?? ''));
        $audience = (string)($_POST['audience'] ?? 'all');
        $expiresInput = trim((string)($_POST['expires_at'] ?? ''));
        $expires = null;
        if ($title === '' || mb_strlen($title) > 180) $error = 'Enter a title of up to 180 characters.';
        elseif ($body === '' || mb_strlen($body) > 20000) $error = 'Enter an announcement of up to 20,000 characters.';
        elseif (!in_array($audience, $allowedAudiences, true)) $error = 'Choose a valid audience.';
        elseif ($expiresInput !== '') {
            $dt = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $expiresInput);
            if (!$dt || $dt->format('Y-m-d\TH:i') !== $expiresInput) $error = 'Enter a valid expiry date and time.';
            else $expires = $dt->format('Y-m-d H:i:s');
        }
        if ($error === '' && $expires !== null && strtotime($expires) <= time()) $error = 'Expiry must be in the future.';
        if ($error === '' && $action === 'update' && (!$id || $id < 1)) $error = 'Invalid announcement.';
        if ($error === '') {
            if ($action === 'create') {
                $stmt = $pdo->prepare('INSERT INTO announcements (created_by,title,body,audience,expires_at) VALUES (?,?,?,?,?)');
                $stmt->execute([$uid, $title, $body, $audience, $expires]);
                $_SESSION['announcement_flash'] = 'Draft created. Publish it when ready.';
            } else {
                $stmt = $pdo->prepare('UPDATE announcements SET title=?,body=?,audience=?,expires_at=? WHERE id=? AND is_published=0');
                $stmt->execute([$title, $body, $audience, $expires, $id]);
                $_SESSION['announcement_flash'] = $stmt->rowCount() ? 'Draft updated.' : 'No editable draft was changed.';
            }
            announce_redirect();
        }
    } elseif (in_array($action, ['publish','unpublish','delete'], true) && $id && $id > 0) {
        if ($action === 'publish') {
            $stmt = $pdo->prepare('UPDATE announcements SET is_published=1,published_at=NOW() WHERE id=? AND is_published=0');
        } elseif ($action === 'unpublish') {
            $stmt = $pdo->prepare('UPDATE announcements SET is_published=0,published_at=NULL WHERE id=? AND is_published=1');
        } else {
            $stmt = $pdo->prepare('DELETE FROM announcements WHERE id=?');
        }
        $stmt->execute([$id]);
        $_SESSION['announcement_flash'] = $stmt->rowCount() ? 'Announcement ' . ($action === 'publish' ? 'published' : ($action === 'unpublish' ? 'unpublished' : 'deleted')) . '.' : 'No announcement changed.';
        announce_redirect();
    } else $error = 'Invalid action.';
}
$editId = filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT);
$edit = null;
if ($editId && $editId > 0) {
    $s = $pdo->prepare('SELECT * FROM announcements WHERE id=? AND is_published=0');
    $s->execute([$editId]); $edit = $s->fetch() ?: null;
}
$filter = (string)($_GET['status'] ?? 'all');
if (!in_array($filter, ['all','published','draft'], true)) $filter = 'all';
$where = $filter === 'published' ? 'WHERE a.is_published=1' : ($filter === 'draft' ? 'WHERE a.is_published=0' : '');
$rows = $pdo->query('SELECT a.*, u.full_name AS author FROM announcements a LEFT JOIN users u ON u.id=a.created_by ' . $where . ' ORDER BY a.created_at DESC,a.id DESC LIMIT 100')->fetchAll();
$counts = $pdo->query('SELECT COUNT(*) total, COALESCE(SUM(is_published=1),0) published FROM announcements')->fetch();
$theme = (string)($user['theme'] ?? 'system');
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Manage Announcements — ULearn</title>
<script>(function(){try{const s=localStorage.getItem('ulearn-theme');const p=<?= json_encode($theme) ?>;document.documentElement.dataset.theme=(s==='light'||s==='dark')?s:((p==='light'||p==='dark')?p:(matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'));}catch(e){}})();function toggleTheme(){const n=document.documentElement.dataset.theme==='dark'?'light':'dark';document.documentElement.dataset.theme=n;try{localStorage.setItem('ulearn-theme',n)}catch(e){}themeIcon()}function themeIcon(){const b=document.getElementById('themeButton');if(b){const d=document.documentElement.dataset.theme==='dark';b.textContent=d?'☀':'☾';b.setAttribute('aria-label',d?'Switch to light mode':'Switch to dark mode')}}document.addEventListener('DOMContentLoaded',themeIcon);</script>
<style>:root{color-scheme:light;--bg:#f7f8fc;--panel:#fff;--alt:#f2f2f9;--ink:#171927;--muted:#73778d;--border:#e6e7f0;--accent:#6857e8;--soft:#efecff;--danger:#c43b54}:root[data-theme=dark]{color-scheme:dark;--bg:#0c0e16;--panel:#161924;--alt:#202431;--ink:#f4f4fa;--muted:#a0a4b6;--border:#2b2e3c;--accent:#a99aff;--soft:#292440;--danger:#ff8a9b}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.55 Inter,-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif}a{color:inherit;text-decoration:none}button,input,textarea,select{font:inherit}button{cursor:pointer}.layout{display:grid;grid-template-columns:250px minmax(0,1fr);min-height:100vh}.sidebar{padding:32px 20px;background:var(--panel);border-right:1px solid var(--border)}.brand{font-size:27px;font-weight:850;letter-spacing:-1.4px;margin:0 12px 50px;display:block}.brand span{color:var(--accent)}.small{font-size:11px;text-transform:uppercase;letter-spacing:1.7px;color:var(--muted);font-weight:800}.nav{display:grid;gap:6px;margin-top:14px}.nav a{padding:12px 14px;border-radius:12px;font-weight:650}.nav a:hover{background:var(--alt)}.nav .active{background:var(--soft);color:var(--accent)}.main{max-width:1250px;width:100%;padding:34px clamp(20px,5vw,75px) 90px}.top{display:flex;align-items:center;justify-content:space-between;gap:20px;margin-bottom:50px}.theme-button{height:42px;width:42px;border-radius:12px;border:1px solid var(--border);background:var(--panel);color:var(--ink);font-size:20px}.eyebrow{font-size:11px;letter-spacing:1.8px;color:var(--muted);font-weight:800}.heading{font-size:clamp(34px,4vw,50px);line-height:1.1;letter-spacing:-1.5px;margin:12px 0}.heading span{color:var(--accent)}.lead{color:var(--muted);margin:0 0 32px}.grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(320px,1fr);gap:24px;align-items:start}.card{background:var(--panel);border:1px solid var(--border);border-radius:20px;padding:25px}.card h2{margin:0 0 20px;font-size:22px;letter-spacing:-.5px}label{display:block;font-size:13px;font-weight:750;margin:16px 0 7px}input,textarea,select{display:block;width:100%;border:1px solid var(--border);border-radius:11px;padding:11px 13px;background:var(--bg);color:var(--ink);outline:none}input:focus,textarea:focus,select:focus{border-color:var(--accent)}textarea{resize:vertical;min-height:155px}.hint{font-size:12px;color:var(--muted);margin:6px 0 0}.btn{border:1px solid var(--border);background:var(--alt);color:var(--ink);border-radius:10px;padding:9px 13px;font-size:13px;font-weight:750;display:inline-flex;align-items:center;justify-content:center}.btn.primary{background:var(--accent);border-color:var(--accent);color:#fff}.btn.danger{color:var(--danger)}.btn:hover{transform:translateY(-1px);filter:brightness(.97)}.buttons{display:flex;flex-wrap:wrap;gap:8px;margin-top:20px}.flash{padding:13px 17px;background:var(--soft);color:var(--accent);border-radius:12px;margin-bottom:20px}.error{background:rgba(220,60,80,.1);color:var(--danger)}.stats{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px}.pill{padding:6px 11px;border-radius:99px;background:var(--soft);color:var(--accent);font-size:12px;font-weight:750}.filters{display:flex;gap:9px;flex-wrap:wrap;margin-bottom:18px}.filters a{border:1px solid var(--border);padding:8px 12px;border-radius:10px;font-size:13px}.filters a.active{background:var(--soft);border-color:var(--accent);color:var(--accent)}.item{padding:18px 0;border-bottom:1px solid var(--border)}.item:last-child{border-bottom:0}.item h3{margin:6px 0;font-size:16px}.item p{white-space:pre-wrap;overflow-wrap:anywhere;color:var(--muted);font-size:13px;max-height:115px;overflow:auto;margin:8px 0}.meta{color:var(--muted);font-size:12px}.item .buttons{margin-top:12px}.item form{display:inline-flex}.empty{padding:25px;border:1px dashed var(--border);border-radius:12px;text-align:center;color:var(--muted)}@media(max-width:1000px){.grid{grid-template-columns:1fr}.layout{grid-template-columns:1fr}.sidebar{padding:18px;border-right:0;border-bottom:1px solid var(--border)}.brand{margin:0 0 12px}.nav{display:flex;flex-wrap:wrap;margin-top:7px}.main{padding:24px 18px 55px}.top{margin-bottom:35px}}@media(prefers-reduced-motion:reduce){*{transition:none!important}}</style>
<script src="<?= htmlspecialchars(url('assets/js/theme-global.js?v=20261107-2'), ENT_QUOTES, 'UTF-8') ?>"></script>
<link rel="stylesheet" href="<?= e(url('assets/css/admin-unified-v1.css?v=1')) ?>">
</head>
<body><div class="layout"><aside class="sidebar"><a class="brand" href="<?= e(url('admin/index.php')) ?>">u learn<span>.</span></a><div class="small">Admin workspace</div><nav class="nav"><a href="<?= e(url('admin/index.php')) ?>">◫ &nbsp; Dashboard</a><a class="active" href="<?= e(url('admin/announcements.php')) ?>">♧ &nbsp; Announcements</a><a href="<?= e(url('dashboard/notifications.php')) ?>">♧ &nbsp; Notification Center</a></nav></aside><main class="main"><header class="top"><span class="eyebrow">U LEARN / ADMIN / ANNOUNCEMENTS</span><button class="theme-button" id="themeButton" type="button" onclick="toggleTheme()" aria-label="Toggle appearance">☾</button></header><div class="eyebrow">KEEP EVERYONE INFORMED</div><h1 class="heading">Announcements<span>.</span></h1><p class="lead">Create drafts and publish university updates for the right audience.</p>
<?php if ($flash): ?><div class="flash" role="status"><?= e($flash) ?></div><?php endif; ?><?php if ($error): ?><div class="flash error" role="alert"><?= e($error) ?></div><?php endif; ?>
<div class="grid"><section class="card"><h2><?= $edit ? 'Edit draft' : 'Create announcement' ?></h2><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="<?= $edit ? 'update' : 'create' ?>"><?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
<label for="title">Title</label><input id="title" name="title" maxlength="180" required value="<?= e((string)($_POST['title'] ?? ($edit['title'] ?? ''))) ?>" placeholder="e.g. Semester registration opens">
<label for="body">Message</label><textarea id="body" name="body" maxlength="20000" required placeholder="Write your announcement..."><?= e((string)($_POST['body'] ?? ($edit['body'] ?? ''))) ?></textarea>
<label for="audience">Who should see it?</label><select id="audience" name="audience"><?php $selectedAudience=(string)($_POST['audience'] ?? ($edit['audience'] ?? 'all'));foreach ($allowedAudiences as $a): ?><option value="<?= e($a) ?>" <?= $selectedAudience === $a ? 'selected' : '' ?>><?= e(ucfirst($a === 'all' ? 'Everyone' : ($a === 'admin' ? 'Admins' : $a.'s'))) ?></option><?php endforeach; ?></select>
<label for="expires_at">Expires at (optional)</label><input id="expires_at" name="expires_at" type="datetime-local" value="<?= e((string)($_POST['expires_at'] ?? (!empty($edit['expires_at']) ? date('Y-m-d\TH:i', strtotime($edit['expires_at'])) : ''))) ?>"><p class="hint">Leave blank to keep the announcement visible until you unpublish it.</p>
<div class="buttons"><button class="btn primary" type="submit"><?= $edit ? 'Save changes' : 'Save as draft' ?></button><?php if ($edit): ?><a class="btn" href="<?= e(url('admin/announcements.php')) ?>">Cancel edit</a><?php endif; ?></div></form></section>
<section class="card"><h2>Manage announcements</h2><div class="stats"><span class="pill"><?= (int)$counts['total'] ?> total</span><span class="pill"><?= (int)$counts['published'] ?> published</span><span class="pill"><?= (int)$counts['total']-(int)$counts['published'] ?> drafts</span></div><nav class="filters" aria-label="Announcement filters"><?php foreach (['all'=>'All','published'=>'Published','draft'=>'Drafts'] as $key=>$label): ?><a class="<?= $filter===$key?'active':'' ?>" href="<?= e(url('admin/announcements.php?status='.$key)) ?>"><?= e($label) ?></a><?php endforeach; ?></nav>
<?php if (!$rows): ?><div class="empty">No announcements found. Create your first draft.</div><?php else: foreach ($rows as $r): ?><article class="item"><div><span class="pill"><?= $r['is_published'] ? 'Published' : 'Draft' ?></span> <span class="meta"><?= e(ucfirst((string)$r['audience'])) ?> · <?= e((string)$r['created_at']) ?></span></div><h3><?= e((string)$r['title']) ?></h3><p><?= e((string)$r['body']) ?></p><div class="meta">By <?= e((string)($r['author'] ?? 'Admin')) ?><?php if ($r['expires_at']): ?> · Expires <?= e((string)$r['expires_at']) ?><?php endif; ?></div><div class="buttons">
<?php if (!$r['is_published']): ?><a class="btn" href="<?= e(url('admin/announcements.php?edit='.(int)$r['id'])) ?>">Edit</a><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="action" value="publish"><button class="btn primary" type="submit">Publish</button></form><?php else: ?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="action" value="unpublish"><button class="btn" type="submit">Unpublish</button></form><?php endif; ?>
<form method="post" onsubmit="return confirm('Delete this announcement permanently?')"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="action" value="delete"><button class="btn danger" type="submit">Delete</button></form></div></article><?php endforeach; endif; ?></section></div></main></div></body></html>
