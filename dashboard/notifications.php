<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
$user = require_login();
if ((string)$user['role'] !== 'student') { http_response_code(403); exit('Student access only.'); }
$pdo = db();
$me = (int)$user['id'];

$error = '';
$notice = '';

// Handle actions like marking notifications as read if needed
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'mark_all_read') {
        $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?');
        $stmt->execute([$me]);
        $notice = 'All notifications marked as read.';
    }
}

// Fetch notifications for the user
$stmt = $pdo->prepare('SELECT id, title, message, target_url, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50');
$stmt->execute([$me]);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Count unread notifications for badge consistency
$unreadNotificationsStmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
$unreadNotificationsStmt->execute([$me]);
$unreadNotifications = (int)$unreadNotificationsStmt->fetchColumn();

// Fetch note contribution points for sidebar bottom display
$notePointsStmt = $pdo->prepare('SELECT COALESCE(SUM(points), 0) FROM student_note_points WHERE user_id = ?');
$notePointsStmt->execute([$me]);
$notePoints = (int)$notePointsStmt->fetchColumn();

function esc($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Notifications — U Learn</title>
<script>
    try {
        const saved = localStorage.getItem('ulearn-theme') || 'system';
        const theme = saved === 'system'
            ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
            : saved;
        document.documentElement.dataset.theme = theme;
    } catch (error) {}
</script>
<style>
        :root {
            color-scheme: light;
            --bg: #f7f8fc;
            --surface: #ffffff;
            --surface-alt: #f1f2f8;
            --text: #171927;
            --muted: #777b90;
            --border: #e7e8f0;
            --accent: #6756e8;
            --accent-light: #f0edff;
            --shadow: 0 10px 40px rgba(30,30,60,.04);
            --green: #087e60;
            --soft: #e7f7ef;
        }
        :root[data-theme="dark"] {
            color-scheme: dark;
            --bg: #0c0e16;
            --surface: #161924;
            --surface-alt: #202431;
            --text: #f4f4fa;
            --muted: #a0a4b6;
            --border: #2b2e3c;
            --accent: #a99aff;
            --accent-light: #292440;
            --shadow: none;
            --green: #5ce3b5;
            --soft: #153d34;
        }
        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif;
            line-height: 1.6;
        }
        a { color: inherit; text-decoration: none; }
        button, select { font: inherit; }
        button { cursor: pointer; }
        .layout {
            display: grid;
            grid-template-columns: 250px minmax(0, 1fr);
            min-height: 100vh;
        }
        .sidebar {
            background: var(--surface);
            border-right: 1px solid var(--border);
            padding: 32px 20px;
            display: flex;
            flex-direction: column;
            gap: 40px;
        }
        .brand {
            font-size: 26px;
            font-weight: 800;
            letter-spacing: -1.5px;
            padding-left: 12px;
        }
        .brand span { color: var(--accent); }
        .nav-label, .eyebrow {
            font-size: 11px;
            font-weight: 750;
            letter-spacing: 1.7px;
            text-transform: uppercase;
            color: var(--muted);
        }
        .nav-label { margin: 0 12px 14px; }
        .nav { display: grid; gap: 6px; }
        .nav a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 14px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 600;
        }
        .nav a:hover { background: var(--surface-alt); }
        .nav a.active {
            background: var(--accent-light);
            color: var(--accent);
        }
        .ul-nav-icon {
            width: 20px;
            height: 20px;
            flex-shrink: 0;
        }
        .sidebar-bottom {
            margin-top: auto;
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 16px;
            font-size: 13px;
        }
        .sidebar-bottom strong { display: block; overflow-wrap: anywhere; }
        .sidebar-bottom small { color: var(--muted); overflow-wrap: anywhere; }
        .main {
            min-width: 0;
            padding: 34px clamp(20px, 5vw, 76px) 80px;
        }
        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 40px;
        }
        .topbar-right { display: flex; align-items: center; gap: 16px; }
        .notification-bell {
            width: 42px; height: 42px; position: relative;
            display: inline-grid; place-items: center;
            background: var(--surface); color: var(--text);
            border: 1px solid var(--border); border-radius: 12px;
            box-shadow: var(--shadow);
            transition: transform .18s ease, background .18s ease, border-color .18s ease;
        }
        .notification-bell:hover { transform: translateY(-2px); background: var(--accent-light); border-color: var(--accent); }
        .notification-bell svg { width: 21px; height: 21px; stroke: currentColor; fill: none; stroke-width: 1.9; stroke-linecap: round; stroke-linejoin: round; }
        .notification-count {
            position: absolute; top: -8px; right: -9px;
            min-width: 20px; height: 20px; padding: 0 5px;
            display: inline-grid; place-items: center;
            border-radius: 999px; background: #e34878; color: #fff;
            border: 2px solid var(--bg); font-size: 10px; font-weight: 800;
            line-height: 1;
        }
        .avatar {
            width: 40px; height: 40px; border-radius: 50%;
            background: var(--accent-light); color: var(--accent);
            display: grid; place-items: center; font-weight: 800;
        }
        .logout {
            background: transparent; border: 0; color: var(--muted);
            font-size: 13px; font-weight: 650; cursor: pointer;
        }
        h1, h2, h3, p { margin-top: 0; }
        .hero {
            border-radius: 24px; padding: 32px;
            background: linear-gradient(115deg, #063e39, #087d62 70%, #12677b);
            color: white; margin-bottom: 20px;
        }
        .hero h1 { font-size: clamp(28px, 4vw, 44px); margin: 6px 0; }
        .hero p { opacity: .88; }
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: 20px; padding: 28px; margin-bottom: 18px; box-shadow: var(--shadow); }
        .muted { color: var(--muted); }
        .message { padding: 14px 17px; border-radius: 12px; margin-bottom: 20px; font-size: 14px; }
        .success { background: #e9f8ef; color: #176b43; }
        .error { background: #ffedf0; color: #a32143; border: 1px solid #e9a4a4; }
        
        /* Notifications List Specific Styling */
        .notif-item {
            padding: 16px 0;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            gap: 15px;
            align-items: flex-start;
        }
        .notif-item:last-child { border-bottom: 0; }
        .notif-item.unread { font-weight: bold; }
        .notif-badge { font-size: 10px; background: var(--accent-light); color: var(--accent); padding: 2px 8px; border-radius: 99px; text-transform: uppercase; letter-spacing: 0.5px; }

        @media (max-width: 900px) {
            .layout { grid-template-columns: 1fr; }
            .sidebar { border-right: 0; border-bottom: 1px solid var(--border); padding: 18px; gap: 18px; }
            .nav { display: flex; flex-wrap: wrap; }
            .sidebar-bottom { display: none; }
            .main { padding-top: 25px; }
            .topbar { margin-bottom: 35px; }
        }
</style>
<script src="<?= htmlspecialchars(url('assets/js/theme-global.js?v=20261107-2'), ENT_QUOTES, 'UTF-8') ?>"></script>
<link rel="stylesheet" href="<?= e(url('assets/css/ulearn-design-system.css?v=1')) ?>">
<script defer src="<?= e(url('assets/js/ulearn-motion.js?v=1')) ?>"></script>
<link rel="stylesheet" href="<?= e(url('assets/css/ulearn-student-shell.css?v=2')) ?>">
<script defer src="<?= e(url('assets/js/ulearn-student-shell.js?v=2')) ?>"></script>
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
                <a class="active" href="<?= e(url('dashboard/notifications.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg><span>Notifications</span></a>
                <a href="<?= e(url('dashboard/profile.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M4 17h16"/><circle cx="8" cy="7" r="2"/><circle cx="16" cy="17" r="2"/></svg><span>My Profile & Settings</span></a>
            </div>
        </nav>

        <div class="sidebar-bottom">
            <small>Signed in as</small>
            <strong><?= esc($user['full_name']) ?></strong>
            <small><?= esc($user['email'] ?? '') ?></small>
            <small style="display:block;margin-top:12px"><?= $notePoints ?> contribution points</small>
        </div>
    </aside>

    <main class="main">

        <header class="topbar">
            <button type="button" class="ul-menu-toggle" id="ulMenuToggle" aria-expanded="false" aria-controls="studentSidebar" aria-label="Toggle navigation"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button>

            <span class="eyebrow">
                U LEARN / NOTIFICATIONS
            </span>

            <div class="topbar-right">
                <a class="notification-bell" href="<?= e(url('dashboard/notifications.php')) ?>"
                   aria-label="Notifications: <?= $unreadNotifications ?> unread"
                   title="<?= $unreadNotifications ?> unread notifications">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg>
                    <?php if ($unreadNotifications > 0): ?>
                        <span class="notification-count" aria-hidden="true"><?= $unreadNotifications > 99 ? '99+' : $unreadNotifications ?></span>
                    <?php endif; ?>
                </a>

                <a class="avatar" href="<?= e(url('dashboard/profile.php')) ?>" title="My Profile & Settings" aria-label="My Profile & Settings">
                    <?= strtoupper(substr(trim($user['full_name'] ?? 'S'), 0, 1)) ?>
                </a>

                <form method="post" action="<?= e(url('auth/logout.php')) ?>">
                    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                    <button type="submit" class="logout">
                        Log out ↗
                    </button>
                </form>
            </div>
        </header>

        <header class="hero">
            <div>ULearn · Updates & Alerts</div>
            <h1>Your Notifications</h1>
            <p>Stay updated with academic alerts, module activities, and system announcements.</p>
        </header>

        <?php if($notice): ?>
            <div class="message success"><?= esc($notice) ?></div>
        <?php endif; ?>

        <div class="card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2>Recent Alerts</h2>
                <?php if ($unreadNotifications > 0): ?>
                    <form method="post" style="margin: 0;">
                        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                        <input type="hidden" name="action" value="mark_all_read">
                        <button type="submit" style="background: var(--surface-alt); border: 1px solid var(--border); padding: 8px 14px; border-radius: 8px; font-weight: 600; cursor: pointer; color: var(--text);">Mark all as read</button>
                    </form>
                <?php endif; ?>
            </div>

            <?php if(!$notifications): ?>
                <p class="muted">You have no notifications right now.</p>
            <?php else: ?>
                <div class="notif-list">
                    <?php foreach($notifications as $n): ?>
                        <div class="notif-item <?= $n['is_read'] == 0 ? 'unread' : '' ?>">
                            <div>
                                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 4px;">
                                    <strong><?= esc($n['title']) ?></strong>
                                    <?php if($n['is_read'] == 0): ?>
                                        <span class="notif-badge">New</span>
                                    <?php endif; ?>
                                </div>
                                <p class="muted" style="margin: 0; font-size: 14px;"><?= esc($n['message']) ?></p>
                                <small class="muted" style="display: block; margin-top: 6px;"><?= esc($n['created_at']) ?></small>
                            </div>
                            <?php if(!empty($n['target_url'])): ?>
                                <div>
                                    <a href="<?= e(url($n['target_url'])) ?>" style="background: var(--accent); color: #fff; padding: 6px 12px; border-radius: 8px; font-size: 12px; font-weight: 600; display: inline-block;">View ↗</a>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    </main>
</div>

</body>
</html>