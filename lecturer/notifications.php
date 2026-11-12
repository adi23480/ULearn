<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
$user = require_login();
if ((string)($user['role'] ?? '') !== 'lecturer') { http_response_code(403); exit('Lecturer access only.'); }
$pdo = db();
$userId = (int)$user['id'];
$notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'mark_all_read') {
        $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0');
        $stmt->execute([$userId]);
        header('Location: ' . url('lecturer/notifications.php'));
        exit;
    }
}
$stmt = $pdo->prepare('SELECT id, title, message, target_url, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 100');
$stmt->execute([$userId]);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
$unread = 0;
foreach ($notifications as $item) { if (!(int)$item['is_read']) $unread++; }
function lecturerNotificationTarget(string $raw): ?string {
    $raw = trim($raw);
    if ($raw === '' || preg_match('~[\x00-\x1f\x7f]~', $raw)) return null;
    // Notification targets must stay within this application, never external URLs.
    if (preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $raw)) return null;
    $parts = parse_url($raw);
    if ($parts === false || isset($parts['host']) || isset($parts['scheme'])) return null;
    $path = ltrim((string)($parts['path'] ?? ''), '/');
    $base = trim((string)parse_url(url(), PHP_URL_PATH), '/');
    if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
        $path = ltrim(substr($path, strlen($base)), '/');
    }
    if ($path === '' || preg_match('~(^|/)\.\.?(/|$)~', $path) || str_contains($path, '\\')) return null;
    // Restrict navigation to lecturer pages to avoid sending lecturers to student-only screens.
    if (!str_starts_with($path, 'lecturer/')) return null;
    $suffix = isset($parts['query']) ? '?' . $parts['query'] : '';
    return url($path) . $suffix;
}
$theme = (string)($user['theme'] ?? 'system');
if (!in_array($theme, ['light', 'dark', 'system'], true)) $theme = 'system';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Lecturer Notifications | ULearn</title>
<script>(function(){try{let p=localStorage.getItem('ulearn-theme')||<?=json_encode($theme)?>;if(p==='system')p=matchMedia('(prefers-color-scheme:dark)').matches?'dark':'light';document.documentElement.dataset.theme=p==='dark'?'dark':'light'}catch(e){document.documentElement.dataset.theme='light'}})();</script>
<style>
:root{--bg:#f7f8fc;--card:#fff;--text:#1b1d2c;--muted:#747a91;--border:#e6e8f0;--accent:#6856e8;--soft:#f0edff}
[data-theme=dark]{--bg:#10121c;--card:#1b1e2b;--text:#f5f5fa;--muted:#a2a6b8;--border:#303344;--accent:#b1a4ff;--soft:#2c2745}
*{box-sizing:border-box}body{margin:0;font-family:Inter,system-ui,-apple-system,'Segoe UI',sans-serif;background:var(--bg);color:var(--text)}a{color:inherit;text-decoration:none}.layout{min-height:100vh;display:grid;grid-template-columns:245px minmax(0,1fr)}.sidebar{background:var(--card);border-right:1px solid var(--border);padding:30px 20px}.brand{font-size:27px;font-weight:800;letter-spacing:-1.5px;margin-bottom:48px}.brand span{color:var(--accent)}.nav-label,.eyebrow{font-size:11px;font-weight:750;letter-spacing:1.5px;color:var(--muted)}.nav{display:grid;gap:6px;margin-top:18px}.nav a{display:block;padding:13px 15px;border-radius:12px;font-size:14px}.nav a:hover,.nav a.active{background:var(--soft);color:var(--accent)}.main{min-width:0;padding:40px clamp(20px,5vw,75px) 80px}.topbar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:60px}.topbar-right{display:flex;align-items:center;gap:12px}.theme-button,.button{border:1px solid var(--border);background:var(--card);color:var(--text);border-radius:12px;padding:11px 16px;font:inherit;cursor:pointer}.theme-button{font-size:19px}.button.primary{background:var(--accent);color:#fff;border-color:var(--accent)}.hero{background:linear-gradient(110deg,#063e39,#087d62 70%,#12677b);color:#fff;border-radius:24px;padding:35px;margin-bottom:28px}.hero h1{font-size:clamp(30px,4vw,48px);margin:10px 0}.hero p{opacity:.85;margin:0;line-height:1.6}.toolbar{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin:26px 0}.toolbar h2{margin:0}.card{background:var(--card);border:1px solid var(--border);border-radius:18px;padding:22px;margin-bottom:12px}.card.unread{border-left:4px solid var(--accent)}.card h3{margin:0 0 9px;font-size:17px}.card p{color:var(--muted);line-height:1.6;margin:0 0 12px;overflow-wrap:anywhere}.meta{font-size:12px;color:var(--muted)}.badge{display:inline-block;margin-left:8px;font-size:11px;padding:4px 8px;border-radius:20px;background:var(--soft);color:var(--accent)}.card-bottom{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}.empty{padding:32px;text-align:center;color:var(--muted)}.logout{border:0;background:transparent;color:var(--muted);cursor:pointer}.back{font-weight:700;color:var(--accent)}@media(max-width:850px){.layout{display:block}.sidebar{border-right:0;border-bottom:1px solid var(--border);padding:18px}.brand{margin-bottom:16px}.nav{display:flex;overflow-x:auto}.nav a{white-space:nowrap}.main{padding:24px 18px}.topbar{margin-bottom:28px}}
</style></head><body><div class="layout"><aside class="sidebar"><div class="brand">u learn<span>.</span></div><div class="nav-label">LECTURER WORKSPACE</div><nav class="nav" aria-label="Lecturer navigation">
<a href="<?=e(url('lecturer/index.php'))?>">◫ Overview</a><a href="<?=e(url('lecturer/materials.php'))?>">▣ Study Materials</a><a href="<?=e(url('lecturer/quizzes.php'))?>">☷ Quiz Management</a><a href="<?=e(url('lecturer/consultations.php'))?>">◷ Consultations</a><a class="active" aria-current="page" href="<?=e(url('lecturer/notifications.php'))?>">♧ Notifications</a><a href="<?=e(url('lecturer/profile.php'))?>">⚙ My Profile &amp; Settings</a></nav></aside>
<main class="main"><header class="topbar"><div class="eyebrow">U LEARN / LECTURER PORTAL / NOTIFICATIONS</div><div class="topbar-right"><button class="theme-button" id="themeButton" type="button" aria-label="Toggle appearance">☾</button><a class="back" href="<?=e(url('lecturer/index.php'))?>">Dashboard ↗</a><form method="post" action="<?=e(url('auth/logout.php'))?>"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><button class="logout" type="submit">Log out</button></form></div></header>
<section class="hero"><div class="eyebrow" style="color:#d8f5ec">YOUR TEACHING WORKSPACE</div><h1>Notification Center</h1><p>Important updates and alerts for your lecturer account.</p></section>
<div class="toolbar"><h2>Recent notifications <span class="badge"><?= $unread ?> unread</span></h2><?php if($unread):?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="action" value="mark_all_read"><button class="button primary" type="submit">Mark all as read</button></form><?php endif;?></div>
<?php if(!$notifications):?><div class="card empty">You're all caught up. New notifications will appear here.</div><?php else:foreach($notifications as $n):$target=lecturerNotificationTarget((string)($n['target_url']??''));?><article class="card <?=!(int)$n['is_read']?'unread':''?>"><h3><?=e($n['title'])?><?php if(!(int)$n['is_read']):?><span class="badge">New</span><?php endif;?></h3><p><?=e($n['message'])?></p><div class="card-bottom"><span class="meta"><?=e($n['created_at'])?></span><?php if($target):?><a class="button" href="<?=e($target)?>">View ↗</a><?php endif;?></div></article><?php endforeach;endif;?>
</main></div><script>(function(){const b=document.getElementById('themeButton');function update(){b.textContent=document.documentElement.dataset.theme==='dark'?'☀':'☾'}b.addEventListener('click',()=>{const t=document.documentElement.dataset.theme==='dark'?'light':'dark';document.documentElement.dataset.theme=t;try{localStorage.setItem('ulearn-theme',t)}catch(e){}update()});update()})();</script></body></html>