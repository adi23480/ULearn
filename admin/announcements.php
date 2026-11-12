<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$user = require_login();

if (!in_array((string)($user['role'] ?? ''), ['admin', 'main_admin'], true)) {
    http_response_code(403);
    exit('Access denied. Admin permission required.');
}

$pdo = db();
$uid = (int)$user['id'];
$error = '';
$flash = (string)($_SESSION['announcement_flash'] ?? '');
unset($_SESSION['announcement_flash']);

$allowedAudiences = ['all', 'student', 'lecturer', 'admin'];

function announce_redirect(): void {
    go('admin/announcements.php');
}

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

        if ($title === '' || mb_strlen($title) > 180) {
            $error = 'Enter a title of up to 180 characters.';
        } elseif ($body === '' || mb_strlen($body) > 20000) {
            $error = 'Enter an announcement of up to 20,000 characters.';
        } elseif (!in_array($audience, $allowedAudiences, true)) {
            $error = 'Choose a valid audience.';
        } elseif ($expiresInput !== '') {
            $dt = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $expiresInput);
            if (!$dt || $dt->format('Y-m-d\TH:i') !== $expiresInput) {
                $error = 'Enter a valid expiry date and time.';
            } else {
                $expires = $dt->format('Y-m-d H:i:s');
            }
        }

        if ($error === '' && $expires !== null && strtotime($expires) <= time()) {
            $error = 'Expiry must be in the future.';
        }
        if ($error === '' && $action === 'update' && (!$id || $id < 1)) {
            $error = 'Invalid announcement.';
        }

        if ($error === '') {
            if ($action === 'create') {
                $stmt = $pdo->prepare('INSERT INTO announcements (created_by, title, body, audience, expires_at) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([$uid, $title, $body, $audience, $expires]);
                $_SESSION['announcement_flash'] = 'Draft created. Publish it when ready.';
            } else {
                $stmt = $pdo->prepare('UPDATE announcements SET title = ?, body = ?, audience = ?, expires_at = ? WHERE id = ? AND is_published = 0');
                $stmt->execute([$title, $body, $audience, $expires, $id]);
                $_SESSION['announcement_flash'] = $stmt->rowCount() ? 'Draft updated.' : 'No editable draft was changed.';
            }
            announce_redirect();
        }
    } elseif (in_array($action, ['publish', 'unpublish', 'delete'], true) && $id && $id > 0) {
        if ($action === 'publish') {
            $stmt = $pdo->prepare('UPDATE announcements SET is_published = 1, published_at = NOW() WHERE id = ? AND is_published = 0');
        } elseif ($action === 'unpublish') {
            $stmt = $pdo->prepare('UPDATE announcements SET is_published = 0, published_at = NULL WHERE id = ? AND is_published = 1');
        } else {
            $stmt = $pdo->prepare('DELETE FROM announcements WHERE id = ?');
        }
        $stmt->execute([$id]);
        $_SESSION['announcement_flash'] = $stmt->rowCount() ? 'Announcement ' . ($action === 'publish' ? 'published' : ($action === 'unpublish' ? 'unpublished' : 'deleted')) . '.' : 'No announcement changed.';
        announce_redirect();
    } else {
        $error = 'Invalid action.';
    }
}

$editId = filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT);
$edit = null;
if ($editId && $editId > 0) {
    $s = $pdo->prepare('SELECT * FROM announcements WHERE id = ? AND is_published = 0');
    $s->execute([$editId]);
    $edit = $s->fetch() ?: null;
}

$filter = (string)($_GET['status'] ?? 'all');
if (!in_array($filter, ['all', 'published', 'draft'], true)) {
    $filter = 'all';
}
$where = $filter === 'published' ? 'WHERE a.is_published = 1' : ($filter === 'draft' ? 'WHERE a.is_published = 0' : '');
$rows = $pdo->query('SELECT a.*, u.full_name AS author FROM announcements a LEFT JOIN users u ON u.id = a.created_by ' . $where . ' ORDER BY a.created_at DESC, a.id DESC LIMIT 100')->fetchAll();
$counts = $pdo->query('SELECT COUNT(*) AS total, COALESCE(SUM(is_published = 1), 0) AS published FROM announcements')->fetch();

// Personal unread notifications for the signed-in administrator.
$notificationStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0"
);
$notificationStmt->execute([(int)$user['id']]);
$unreadNotifications = (int)$notificationStmt->fetchColumn();

$fullName = (string)($user['full_name'] ?? 'Administrator');
$nameParts = preg_split('/\s+/', trim($fullName));
$firstName = $nameParts[0] ?: 'Administrator';
$initial = strtoupper(substr($firstName, 0, 1));
$roleLabel = $user['role'] === 'main_admin' ? 'Main Administrator' : 'Administrator';
$theme = (string)($user['theme'] ?? 'system');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Manage Announcements — U Learn</title>
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
        --danger: #c43b54;
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
        --danger: #ff8a9b;
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
    button, select, input, textarea { font: inherit; }
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

    .grid {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(340px, 1.2fr);
        gap: 24px;
        align-items: start;
    }
    .card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 20px;
        padding: 28px;
        box-shadow: var(--shadow);
    }
    .card h2 {
        margin: 0 0 20px;
        font-size: 20px;
        letter-spacing: -.5px;
    }
    label {
        display: block;
        font-size: 13px;
        font-weight: 750;
        margin: 16px 0 7px;
    }
    input, textarea, select {
        display: block;
        width: 100%;
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 12px 13px;
        background: var(--surface-alt);
        color: var(--text);
        outline: none;
        transition: border-color .2s, box-shadow .2s;
    }
    input:focus, textarea:focus, select:focus {
        border-color: var(--accent);
        box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent) 18%, transparent);
    }
    textarea { resize: vertical; min-height: 140px; }
    .hint { font-size: 12px; color: var(--muted); margin: 6px 0 0; }
    
    .btn {
        border: 1px solid var(--border);
        background: var(--surface-alt);
        color: var(--text);
        border-radius: 12px;
        padding: 10px 15px;
        font-size: 13px;
        font-weight: 750;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        transition: transform .18s, border-color .18s;
    }
    .btn.primary {
        background: linear-gradient(110deg, #5d4ae0, #8571fa);
        border-color: transparent;
        color: #fff;
        box-shadow: 0 9px 22px rgba(103,86,232,.2);
    }
    .btn.danger { color: var(--danger); }
    .btn:hover { transform: translateY(-1px); border-color: var(--accent); }
    .buttons { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 20px; }
    
    .flash {
        padding: 13px 17px;
        background: var(--accent-light);
        color: var(--accent);
        border-radius: 12px;
        margin-bottom: 20px;
        font-size: 13px;
        font-weight: 650;
    }
    .flash.error { background: rgba(196,59,84,.1); color: var(--danger); }
    
    .stats { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 18px; }
    .pill {
        display: inline-flex;
        align-items: center;
        padding: 6px 12px;
        border-radius: 999px;
        background: var(--accent-light);
        color: var(--accent);
        font-size: 11px;
        font-weight: 850;
    }
    
    .filters { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 20px; }
    .filters a {
        border: 1px solid var(--border);
        padding: 8px 14px;
        border-radius: 11px;
        font-size: 13px;
        font-weight: 650;
        background: var(--surface-alt);
        color: var(--muted);
    }
    .filters a.active {
        background: var(--accent-light);
        border-color: var(--accent);
        color: var(--accent);
    }
    
    .item {
        padding: 20px 0;
        border-bottom: 1px solid var(--border);
    }
    .item:first-of-type { padding-top: 5px; }
    .item:last-child { border-bottom: 0; padding-bottom: 0; }
    .item h3 { margin: 8px 0; font-size: 16px; letter-spacing: -.3px; }
    .item p {
        white-space: pre-wrap;
        overflow-wrap: anywhere;
        color: var(--muted);
        font-size: 13px;
        max-height: 120px;
        overflow-y: auto;
        margin: 8px 0;
        line-height: 1.6;
    }
    .meta { color: var(--muted); font-size: 12px; }
    .item .buttons { margin-top: 12px; }
    .item form { display: inline-flex; margin: 0; }
    
    .empty {
        padding: 30px 18px;
        border: 1px dashed var(--border);
        border-radius: 16px;
        text-align: center;
        color: var(--muted);
        font-size: 13px;
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
        .grid { grid-template-columns: 1fr; }
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
        .hero { flex-direction: column; align-items: flex-start; }
        .topbar { flex-wrap: wrap; }
    }
</style>

<link rel="stylesheet" href="<?= e(url('assets/css/ulearn-design-system.css?v=1')) ?>">
<script defer src="<?= e(url('assets/js/ulearn-motion.js?v=1')) ?>"></script>
<script src="<?= htmlspecialchars(url('assets/js/theme-global.js?v=20261112-1'), ENT_QUOTES, 'UTF-8') ?>"></script>
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
                <a href="<?= e(url('admin/index.php')) ?>">
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
                <a class="active" href="<?= e(url('admin/announcements.php')) ?>">
                    <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                    <span>Announcements</span>
                </a>
                <a href="<?= e(url('admin/platform-settings.php')) ?>">
                    <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l-.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
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
            <small><?= e((string)$user['email']) ?></small>
        </div>
    </aside>

    <main class="main">
        <header class="topbar">
            <span class="eyebrow">
                U LEARN / ANNOUNCEMENTS SPACE
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

                <?php include __DIR__ . '/../includes/theme-toggle-button.php'; ?>

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
                <div style="font-size:12px; font-weight:750; text-transform:uppercase; letter-spacing:1.5px; opacity:0.8;">Administration / Communications</div>
                <h1>Announcements</h1>
                <p>Create drafts and publish university updates for the right audience.</p>
            </div>
            <span class="pill">
                <?= e($roleLabel) ?>
            </span>
        </header>

        <?php if ($flash): ?><div class="flash" role="status"><?= e($flash) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="flash error" role="alert"><?= e($error) ?></div><?php endif; ?>

        <div class="grid">
            <section class="card">
                <h2><?= $edit ? 'Edit draft' : 'Create announcement' ?></h2>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                    <input type="hidden" name="action" value="<?= $edit ? 'update' : 'create' ?>">
                    <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
                    
                    <label for="title">Title</label>
                    <input id="title" name="title" maxlength="180" required value="<?= e((string)($_POST['title'] ?? ($edit['title'] ?? ''))) ?>" placeholder="e.g. Semester registration opens">
                    
                    <label for="body">Message</label>
                    <textarea id="body" name="body" maxlength="20000" required placeholder="Write your announcement..."><?= e((string)($_POST['body'] ?? ($edit['body'] ?? ''))) ?></textarea>
                    
                    <label for="audience">Who should see it?</label>
                    <select id="audience" name="audience">
                        <?php 
                        $selectedAudience = (string)($_POST['audience'] ?? ($edit['audience'] ?? 'all'));
                        foreach ($allowedAudiences as $a): 
                        ?>
                            <option value="<?= e($a) ?>" <?= $selectedAudience === $a ? 'selected' : '' ?>>
                                <?= e(ucfirst($a === 'all' ? 'Everyone' : ($a === 'admin' ? 'Admins' : $a . 's'))) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <label for="expires_at">Expires at (optional)</label>
                    <input id="expires_at" name="expires_at" type="datetime-local" value="<?= e((string)($_POST['expires_at'] ?? (!empty($edit['expires_at']) ? date('Y-m-d\TH:i', strtotime($edit['expires_at'])) : ''))) ?>">
                    <p class="hint">Leave blank to keep the announcement visible until you unpublish it.</p>
                    
                    <div class="buttons">
                        <button class="btn primary" type="submit"><?= $edit ? 'Save changes' : 'Save as draft' ?></button>
                        <?php if ($edit): ?><a class="btn" href="<?= e(url('admin/announcements.php')) ?>">Cancel edit</a><?php endif; ?>
                    </div>
                </form>
            </section>

            <section class="card">
                <h2>Manage announcements</h2>
                <div class="stats">
                    <span class="pill"><?= (int)$counts['total'] ?> total</span>
                    <span class="pill"><?= (int)$counts['published'] ?> published</span>
                    <span class="pill"><?= (int)$counts['total'] - (int)$counts['published'] ?> drafts</span>
                </div>
                <nav class="filters" aria-label="Announcement filters">
                    <?php foreach (['all' => 'All', 'published' => 'Published', 'draft' => 'Drafts'] as $key => $label): ?>
                        <a class="<?= $filter === $key ? 'active' : '' ?>" href="<?= e(url('admin/announcements.php?status=' . $key)) ?>"><?= e($label) ?></a>
                    <?php endforeach; ?>
                </nav>

                <?php if (!$rows): ?>
                    <div class="empty">No announcements found. Create your first draft.</div>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <article class="item">
                            <div>
                                <span class="pill"><?= $r['is_published'] ? 'Published' : 'Draft' ?></span>
                                <span class="meta"><?= e(ucfirst((string)$r['audience'])) ?> · <?= e((string)$r['created_at']) ?></span>
                            </div>
                            <h3><?= e((string)$r['title']) ?></h3>
                            <p><?= e((string)$r['body']) ?></p>
                            <div class="meta">By <?= e((string)($r['author'] ?? 'Admin')) ?><?php if ($r['expires_at']): ?> · Expires <?= e((string)$r['expires_at']) ?><?php endif; ?></div>
                            <div class="buttons">
                                <?php if (!$r['is_published']): ?>
                                    <a class="btn" href="<?= e(url('admin/announcements.php?edit=' . (int)$r['id'])) ?>">Edit</a>
                                    <form method="post">
                                        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                        <input type="hidden" name="action" value="publish">
                                        <button class="btn primary" type="submit">Publish</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post">
                                        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                        <input type="hidden" name="action" value="unpublish">
                                        <button class="btn" type="submit">Unpublish</button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" onsubmit="return confirm('Delete this announcement permanently?')">
                                    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <button class="btn danger" type="submit">Delete</button>
                                </form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>
        </div>

        <footer class="footer">
            © <?= date('Y') ?> U Learn · Administration Workspace
        </footer>
    </main>
</div>

</body>
</html>
