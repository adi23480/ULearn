<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$u = require_login();

if (!in_array((string)$u['role'], ['admin', 'main_admin'], true)) {
    http_response_code(403);
    exit('Access denied. Admin permission required.');
}

$pdo = db();

// Personal unread notifications for the signed-in administrator.
$notificationStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0"
);
$notificationStmt->execute([(int)$u['id']]);
$unreadNotifications = (int)$notificationStmt->fetchColumn();

function adminDashboardCount(PDO $pdo, string $sql): int
{
    try {
        return (int)$pdo->query($sql)->fetchColumn();
    } catch (PDOException $exception) {
        error_log('U Learn dashboard count error: ' . $exception->getMessage());
        return 0;
    }
}

$stats = [
    'students' => adminDashboardCount($pdo, "SELECT COUNT(*) FROM users WHERE role = 'student'"),
    'lecturers' => adminDashboardCount($pdo, "SELECT COUNT(*) FROM lecturer_profiles WHERE is_active = 1"),
    'modules' => adminDashboardCount($pdo, "SELECT COUNT(*) FROM academic_modules WHERE is_active = 1"),
    'batches' => adminDashboardCount($pdo, "SELECT COUNT(*) FROM academic_batches WHERE is_active = 1"),
    'degrees' => adminDashboardCount($pdo, "SELECT COUNT(*) FROM academic_degrees WHERE is_active = 1"),
    'assignments' => adminDashboardCount($pdo, "SELECT COUNT(*) FROM lecturer_module_assignments WHERE is_active = 1")
];

$fullName = (string)($u['full_name'] ?? 'Administrator');
$nameParts = preg_split('/\s+/', trim($fullName));
$firstName = $nameParts[0] ?: 'Administrator';
$initial = strtoupper(substr($firstName, 0, 1));
$roleLabel = $u['role'] === 'main_admin' ? 'Main Administrator' : 'Administrator';
$theme = (string)($u['theme'] ?? 'system');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin Workspace — U Learn</title>
<script>
    try {
        const saved = localStorage.getItem('ulearn-theme') || <?= json_encode($theme, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const theme = saved === 'system'
            ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
            : saved;
        document.documentElement.dataset.theme = theme;
        localStorage.setItem('ulearn-theme', theme);
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
        transition: background .2s, color .2s;
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
    .sidebar-bottom strong {
        display: block;
        overflow-wrap: anywhere;
    }
    .sidebar-bottom small {
        color: var(--muted);
        overflow-wrap: anywhere;
    }
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
    .topbar-right {
        display: flex;
        align-items: center;
        gap: 16px;
    }
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
    .theme-toggle {
        border: 1px solid var(--border);
        background: var(--surface);
        color: var(--text);
        border-radius: 12px;
        width: 42px;
        height: 42px;
        display: grid;
        place-items: center;
        font-size: 18px;
        box-shadow: var(--shadow);
        transition: transform .18s ease, background .18s ease, border-color .18s ease;
    }
    .theme-toggle:hover {
        background: var(--accent-light);
        border-color: var(--accent);
        transform: translateY(-2px);
    }
    .avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: var(--accent-light);
        color: var(--accent);
        display: grid;
        place-items: center;
        font-weight: 800;
        transition: transform .18s ease;
    }
    .avatar:hover { transform: translateY(-2px); }
    .logout {
        background: transparent;
        border: 0;
        color: var(--muted);
        font-size: 13px;
        font-weight: 650;
        cursor: pointer;
    }
    .logout:hover { color: var(--text); }
    h1, h2, h3, p { margin-top: 0; }
    
    .hero {
        border-radius: 24px;
        padding: 32px;
        background: linear-gradient(115deg, #063e39, #087d62 70%, #12677b);
        color: white;
        margin-bottom: 30px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 20px;
    }
    .hero h1 { font-size: clamp(28px, 4vw, 44px); margin: 6px 0; letter-spacing: -1.5px; }
    .hero p { opacity: .88; margin-bottom: 0; max-width: 600px; }
    .hero .pill {
        display: inline-flex;
        align-items: center;
        padding: 8px 16px;
        border-radius: 100px;
        background: rgba(255, 255, 255, 0.15);
        backdrop-filter: blur(8px);
        color: #fff;
        font-size: 12px;
        font-weight: 750;
        white-space: nowrap;
        border: 1px solid rgba(255, 255, 255, 0.2);
    }
    
    .section-heading {
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        gap: 15px;
        margin: 40px 0 20px;
    }
    .section-heading h2 {
        margin: 0;
        font-size: 22px;
        letter-spacing: -.5px;
    }
    .section-heading small { color: var(--muted); font-weight: 600; }
    
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 16px;
    }
    .stat-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 20px;
        padding: 24px;
        min-height: 154px;
        box-shadow: var(--shadow);
        transition: transform .2s ease, border-color .2s ease;
    }
    .stat-card:hover {
        border-color: var(--accent);
        transform: translateY(-2px);
    }
    .stat-top {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
    }
    .stat-label {
        color: var(--muted);
        font-size: 13px;
        font-weight: 650;
    }
    .icon-box {
        width: 40px;
        height: 40px;
        border-radius: 12px;
        background: var(--accent-light);
        color: var(--accent);
        display: grid;
        place-items: center;
        flex-shrink: 0;
    }
    .icon-box svg { width: 20px; height: 20px; }
    .stat-number {
        font-size: 38px;
        font-weight: 850;
        letter-spacing: -1.5px;
        line-height: 1.2;
        margin-top: 14px;
    }
    .stat-description {
        color: var(--muted);
        font-size: 12px;
        margin-top: 4px;
    }
    
    .tools-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 16px;
    }
    .tool-card {
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 20px;
        padding: 28px;
        min-height: 215px;
        transition: border-color .2s ease, transform .2s ease;
        box-shadow: var(--shadow);
    }
    .tool-card:hover {
        border-color: var(--accent);
        transform: translateY(-2px);
    }
    .tool-card h3 {
        font-size: 18px;
        letter-spacing: -.5px;
        margin: 20px 0 8px;
    }
    .tool-card p {
        font-size: 13px;
        color: var(--muted);
        margin-bottom: 20px;
        max-width: 390px;
    }
    .tool-footer {
        margin-top: auto;
        font-size: 12px;
        font-weight: 750;
        color: var(--accent);
    }
    
    .card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 20px;
        padding: 28px;
        margin-bottom: 18px;
        box-shadow: var(--shadow);
    }
    
    .stage9-management {
        margin: 4px 0 12px;
        padding: 10px 12px;
        border: 1px solid var(--border);
        border-radius: 14px;
        background: var(--surface-alt);
    }
    .stage9-management summary {
        cursor: pointer;
        font-weight: 750;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 1.2px;
        color: var(--muted);
    }
    .stage9-management a {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 12px;
        margin-top: 6px;
        border-radius: 10px;
        color: var(--text);
        font-size: 13px;
        font-weight: 600;
    }
    .stage9-management a:hover {
        background: var(--accent-light);
        color: var(--accent);
    }
    
    .footer {
        margin-top: 50px;
        padding-top: 20px;
        border-top: 1px solid var(--border);
        font-size: 12px;
        color: var(--muted);
    }
    
    @media (max-width: 1000px) {
        .stats-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 900px) {
        .layout { grid-template-columns: 1fr; }
        .sidebar {
            border-right: 0;
            border-bottom: 1px solid var(--border);
            padding: 18px;
            gap: 18px;
        }
        .nav { display: flex; flex-wrap: wrap; }
        .sidebar-bottom { display: none; }
        .main { padding-top: 25px; }
        .topbar { margin-bottom: 35px; }
    }
    @media (max-width: 600px) {
        .main { padding: 24px 18px 60px; }
        .stats-grid, .tools-grid { grid-template-columns: 1fr; }
        .hero { flex-direction: column; align-items: flex-start; }
        .topbar { flex-wrap: wrap; }
    }
</style>

<script src="<?= htmlspecialchars(url('assets/js/theme-global.js?v=20261107-2'), ENT_QUOTES, 'UTF-8') ?>"></script>
<link rel="stylesheet" href="<?= e(url('assets/css/ulearn-design-system.css?v=1')) ?>">
<script defer src="<?= e(url('assets/js/ulearn-motion.js?v=1')) ?>"></script>
</head>

<body>

<div class="layout">

    <aside class="sidebar" id="adminSidebar">
        <a class="brand" href="<?= e(url('admin/index.php')) ?>">
            u learn<span>.</span>
        </a>

        <nav aria-label="Admin navigation">
            <p class="nav-label">System management</p>
            <details open class="stage9-management">
                <summary>System Access</summary>
                <a href="<?= e(url('admin/users.php')) ?>">
                    <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    All Registered Users
                </a>
                <a href="<?= e(url('admin/change-requests.php')) ?>">
                    <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                    Academic Requests
                </a>
            </details>

            <p class="nav-label" style="margin-top: 18px;">Your workspace</p>

            <div class="nav ul-nav">
                <a class="active" href="<?= e(url('admin/index.php')) ?>">
                    <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                    <span>Overview</span>
                </a>
                <a href="<?= e(url('admin/lecturers.php')) ?>">
                    <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    <span>Lecturers</span>
                </a>
                <a href="<?= e(url('admin/academics.php')) ?>">
                    <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                    <span>Academics</span>
                </a>
                <a href="<?= e(url('admin/universities.php')) ?>">
                     <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="m2 9 10-5 10 5-10 5z"/><path d="M6 12v5c4 3 8 3 12 0v-5M22 9v7"/></svg>
                     <span>Universities</span>
                </a>
                <a href="<?= e(url('admin/quizzes.php')) ?>">
                    <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    <span>Quizzes</span>
                </a>
                <a href="<?= e(url('admin/assignments.php')) ?>">
                    <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-2 2"/><path d="M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l2-2"/></svg>
                    <span>Assignments</span>
                </a>
                <a href="<?= e(url('admin/announcements.php')) ?>">
                    <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                    <span>Announcements</span>
                </a>
                <a href="<?= e(url('admin/platform-settings.php')) ?>">
                    <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                    <span>Platform Settings</span>
                </a>
                <a href="<?= e(url('admin/profile.php')) ?>">
                    <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <span>My Profile &amp; Settings</span>
                </a>
                <a href="<?= e(url('admin/consultations.php')) ?>">
                    <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <span>Consultations</span>
                </a>
                <a href="<?= e(url('admin/kuppiya.php')) ?>">
                    <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3"/><path d="M3 20v-2a6 6 0 0 1 12 0v2"/><path d="M17 5a3 3 0 0 1 0 6"/><path d="M18 15a5 5 0 0 1 3 5"/></svg>
                    <span>Kuppiya Sessions</span>
                </a>
            </div>
        </nav>

        <div class="sidebar-bottom">
            <small>Signed in as</small>
            <strong><?= e($fullName) ?></strong>
            <small><?= e((string)$u['email']) ?></small>
        </div>
    </aside>

    <main class="main" id="overview">

        <header class="topbar">
            <span class="eyebrow">
                U LEARN / ADMIN SPACE
            </span>

            <div class="topbar-right">
                <a class="notification-bell" href="<?= e(url('dashboard/notifications.php')) ?>"
                   aria-label="Notifications<?= $unreadNotifications ? ' (' . $unreadNotifications . ' unread)' : '' ?>"
                   title="Open notification center">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg>
                    <?php if ($unreadNotifications > 0): ?>
                        <span class="notification-count" aria-hidden="true"><?= $unreadNotifications > 99 ? '99+' : $unreadNotifications ?></span>
                    <?php endif; ?>
                </a>

                <button
                    type="button"
                    class="theme-toggle"
                    id="themeToggle"
                    aria-label="Switch appearance"
                    title="Switch appearance"
                >◐</button>

                <a class="avatar" href="<?= e(url('admin/profile.php')) ?>" aria-label="Open My Profile and Settings" title="My Profile & Settings">
                    <?= e($initial) ?>
                </a>

                <form method="post" action="<?= e(url('auth/logout.php')) ?>" style="margin:0;">
                    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                    <button type="submit" class="logout">
                        Log out ↗
                    </button>
                </form>
            </div>
        </header>

        <header class="hero">
            <div>
                <div style="font-size:12px; font-weight:750; text-transform:uppercase; letter-spacing:1.5px; opacity:0.8;">ULearn · Administration</div>
                <h1>Welcome back, <?= e($firstName) ?></h1>
                <p>Manage academic programmes, support lecturers, and oversee system settings for your platform.</p>
            </div>
            <span class="pill">
                <?= e($roleLabel) ?>
            </span>
        </header>

        <section id="overview-stats">
            <div class="section-heading">
                <h2>Platform overview</h2>
                <small>Live platform statistics</small>
            </div>

            <?php
            $statItems = [
                ['Students', $stats['students'], '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>', 'Registered student accounts'],
                ['Lecturers', $stats['lecturers'], '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>', 'Active teaching staff'],
                ['Modules', $stats['modules'], '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>', 'Active academic modules'],
                ['Degrees', $stats['degrees'], '<path d="m2 9 10-5 10 5-10 5z"/><path d="M6 12v5c4 3 8 3 12 0v-5M22 9v7"/>', 'Degree programmes'],
                ['Batches', $stats['batches'], '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>', 'Active university intakes'],
                ['Assignments', $stats['assignments'], '<path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-2 2"/><path d="M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l2-2"/>', 'Active lecturer assignments']
            ];
            ?>

            <div class="stats-grid">
                <?php foreach ($statItems as $item): ?>
                    <article class="stat-card">
                        <div class="stat-top">
                            <span class="stat-label">
                                <?= e($item[0]) ?>
                            </span>
                            <div class="icon-box">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                                    <?= $item[2] ?>
                                </svg>
                            </div>
                        </div>
                        <div class="stat-number">
                            <?= number_format((int)$item[1]) ?>
                        </div>
                        <div class="stat-description">
                            <?= e($item[3]) ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <section id="management">
            <div class="section-heading">
                <h2>Management workspace</h2>
                <small>Your administration tools</small>
            </div>

            <div class="tools-grid">
                <a class="tool-card" href="<?= e(url('admin/lecturers.php')) ?>">
                    <div class="icon-box">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                        </svg>
                    </div>
                    <h3>Lecturer Management</h3>
                    <p>Review lecturer accounts, approve teaching staff, and manage lecturer access to U Learn.</p>
                    <span class="tool-footer">Open lecturer management ↗</span>
                </a>

                <a class="tool-card" href="<?= e(url('admin/academics.php')) ?>">
                    <div class="icon-box">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                            <path d="m2 9 10-5 10 5-10 5z"/><path d="M6 12v5c4 3 8 3 12 0v-5M22 9v7"/>
                        </svg>
                    </div>
                    <h3>Academic Management</h3>
                    <p>Organize degrees, batches, modules, and the academic curriculum.</p>
                    <span class="tool-footer">Open academic management ↗</span>
                </a>
                         
                <a class="tool-card" href="<?= e(url('admin/universities.php')) ?>">
                    <div class="icon-box">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                            <path d="m2 9 10-5 10 5-10 5z"/><path d="M6 12v5c4 3 8 3 12 0v-5M22 9v7"/>
                        </svg>
                    </div>
                    <h3>University Management</h3>
                    <p>Add and manage awarding universities for ULearn degree programmes.</p>
                    <span class="tool-footer">Open university management ↗</span>
                </a>

                <a class="tool-card" href="<?= e(url('admin/quizzes.php')) ?>">
                    <div class="icon-box">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>
                        </svg>
                    </div>
                    <h3>Quiz Management</h3>
                    <p>Create and manage module quizzes, questions, imports, and publication.</p>
                    <span class="tool-footer">Open quiz management ↗</span>
                </a>

                <a class="tool-card" href="<?= e(url('admin/assignments.php')) ?>">
                    <div class="icon-box">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-2 2"/><path d="M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l2-2"/>
                        </svg>
                    </div>
                    <h3>Lecturer Assignments</h3>
                    <p>Connect lecturers to their assigned modules, degree programmes, and batches.</p>
                    <span class="tool-footer">Open lecturer assignments ↗</span>
                </a>

                <a class="tool-card" href="<?= e(url('admin/announcements.php')) ?>">
                    <div class="icon-box">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>
                        </svg>
                    </div>
                    <h3>Announcements</h3>
                    <p>Create, publish, and manage university announcements for students, lecturers, and admins.</p>
                    <span class="tool-footer">Manage announcements ↗</span>
                </a>

                <a class="tool-card" href="<?= e(url('admin/consultations.php')) ?>">
                    <div class="icon-box">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
                        </svg>
                    </div>
                    <h3>Consultation Management</h3>
                    <p>Review consultation requests, statuses, schedules and lecturer assignments.</p>
                    <span class="tool-footer">Open Consultation Management ↗</span>
                </a>

                <a class="tool-card" href="<?= e(url('admin/kuppiya.php')) ?>">
                    <div class="icon-box">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="9" cy="8" r="3"/><path d="M3 20v-2a6 6 0 0 1 12 0v2"/><path d="M17 5a3 3 0 0 1 0 6"/><path d="M18 15a5 5 0 0 1 3 5"/>
                        </svg>
                    </div>
                    <h3>Kuppiya Sessions</h3>
                    <p>Monitor peer-led sessions, hosts, participants and schedules.</p>
                    <span class="tool-footer">Open Kuppiya Sessions ↗</span>
                </a>

                <a class="tool-card" href="<?= e(url('admin/platform-settings.php')) ?>">
                    <div class="icon-box">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                        </svg>
                    </div>
                    <h3>Platform Settings</h3>
                    <p>Review platform configuration, manage your appearance and access administration tools.</p>
                    <span class="tool-footer">Open Platform Settings ↗</span>
                </a>
            </div>
        </section>

        <section style="margin-top: 30px;">
            <div class="section-heading">
                <h2>Platform activity</h2>
                <small>System status</small>
            </div>
            <div class="card">
                <h3 style="font-size: 17px; margin-bottom: 8px;">Your workspace is ready.</h3>
                <p style="color: var(--muted); font-size: 13px; margin-bottom: 0;">
                    Lecturer management is available. Academic management, consultation requests, and Kuppiya Sessions will be connected as we build the remaining modules.
                </p>
            </div>
        </section>

        <footer class="footer">
            © <?= date('Y') ?> U Learn · Administration Workspace
        </footer>
    </main>
</div>

<script>
const themeButton = document.getElementById('themeToggle');
function updateThemeButton() {
    const theme = document.documentElement.dataset.theme;
    themeButton.textContent = theme === 'dark' ? '☀' : '☾';
}
themeButton.addEventListener('click', function () {
    const current = document.documentElement.dataset.theme;
    const next = current === 'dark' ? 'light' : 'dark';
    document.documentElement.dataset.theme = next;
    localStorage.setItem('ulearn-theme', next);
    updateThemeButton();
});
updateThemeButton();
</script>

</body>
</html>