<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$u = require_login();

if (!in_array((string)$u['role'], ['admin', 'main_admin'], true)) {
    http_response_code(403);
    exit('Access denied. Admin permission required.');
}

$pdo = db();

// Personal unread notifications for the signed-in administrator
$notificationStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0"
);
$notificationStmt->execute([(int)$u['id']]);
$unreadNotifications = (int)$notificationStmt->fetchColumn();

$error = '';
$notice = (string)($_SESSION['university_notice'] ?? '');
unset($_SESSION['university_notice']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save') {
            $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT);
            $name = trim((string)($_POST['name'] ?? ''));
            $code = strtoupper(trim((string)($_POST['code'] ?? '')));
            if ($name === '' || $code === '' || strlen($name) > 180 || strlen($code) > 40 || !preg_match('/^[A-Z0-9_-]+$/', $code)) {
                throw new RuntimeException('Enter a valid university name and code (A-Z, 0-9, hyphens or underscores).');
            }
            if ($id && $id > 0) {
                $stmt = $pdo->prepare('UPDATE awarding_universities SET name = ?, code = ? WHERE id = ?');
                $stmt->execute([$name, $code, $id]);
                if ($stmt->rowCount() === 0) {
                    $check = $pdo->prepare('SELECT id FROM awarding_universities WHERE id = ?');
                    $check->execute([$id]);
                    if (!$check->fetchColumn()) throw new RuntimeException('University not found.');
                }
                $_SESSION['university_notice'] = 'University updated successfully.';
            } else {
                $stmt = $pdo->prepare('INSERT INTO awarding_universities (name, code, is_active) VALUES (?, ?, 1)');
                $stmt->execute([$name, $code]);
                $_SESSION['university_notice'] = 'University added successfully.';
            }
        } elseif ($action === 'toggle') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id || $id < 1) throw new RuntimeException('Invalid university.');
            $stmt = $pdo->prepare('UPDATE awarding_universities SET is_active = IF(is_active = 1, 0, 1) WHERE id = ?');
            $stmt->execute([$id]);
            if (!$stmt->rowCount()) throw new RuntimeException('University not found.');
            $_SESSION['university_notice'] = 'University status updated.';
        } else {
            throw new RuntimeException('Unknown action.');
        }
        go('admin/universities.php');
    } catch (PDOException $ex) {
        error_log('ULearn universities: ' . $ex->getMessage());
        $error = (string)$ex->getCode() === '23000' ? 'This university code is already in use.' : 'Unable to save. Check your database migration.';
    } catch (RuntimeException $ex) {
        $error = $ex->getMessage();
    }
}

$universities = $pdo->query('SELECT u.id, u.name, u.code, u.is_active, COUNT(d.id) AS degree_count FROM awarding_universities u LEFT JOIN academic_degrees d ON d.awarding_university_id = u.id AND d.is_active = 1 GROUP BY u.id, u.name, u.code, u.is_active ORDER BY u.is_active DESC, u.name')->fetchAll(PDO::FETCH_ASSOC);
$degrees = $pdo->query('SELECT id, awarding_university_id, name, code FROM academic_degrees WHERE is_active = 1 AND awarding_university_id IS NOT NULL ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);

$byUniversity = [];
foreach ($degrees as $degree) {
    $byUniversity[(int)$degree['awarding_university_id']][] = $degree;
}

$activeCount = count(array_filter($universities, static fn($uItem) => (int)$uItem['is_active'] === 1));
$degreeCount = array_sum(array_map(static fn($uItem) => (int)$uItem['degree_count'], $universities));

$fullName = (string)($u['full_name'] ?? 'Administrator');
$nameParts = preg_split('/\s+/', trim($fullName));
$firstName = $nameParts[0] ?: 'Administrator';
$initial = strtoupper(substr($firstName, 0, 1));
$theme = (string)($u['theme'] ?? 'system');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>University Management — U Learn</title>
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
    button, input, select { font: inherit; }
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

    /* Page-specific layout elements */
    .heading {
        display: flex;
        align-items: flex-end;
        justify-content: space-between;
        gap: 20px;
        flex-wrap: wrap;
        margin-bottom: 28px;
    }
    h1 {
        font-size: clamp(28px, 4vw, 44px);
        letter-spacing: -1.5px;
        line-height: 1.15;
        margin: 6px 0 10px;
    }
    .heading p { margin: 0; color: var(--muted); max-width: 650px; }
    
    .btn-primary {
        background: var(--accent);
        color: #fff;
        border: 0;
        border-radius: 12px;
        padding: 13px 20px;
        font-weight: 700;
        box-shadow: 0 8px 20px rgba(103,86,232,.2);
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: transform .18s ease, filter .18s ease;
    }
    .btn-primary:hover {
        filter: brightness(1.08);
        transform: translateY(-2px);
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 16px;
        margin: 28px 0 34px;
    }
    .stat-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 20px;
        padding: 24px;
        box-shadow: var(--shadow);
        transition: transform .2s ease, border-color .2s ease;
    }
    .stat-card:hover {
        border-color: var(--accent);
        transform: translateY(-2px);
    }
    .stat-number {
        font-size: 36px;
        font-weight: 850;
        letter-spacing: -1.5px;
        line-height: 1.2;
        display: block;
    }
    .stat-label {
        color: var(--muted);
        font-size: 13px;
        font-weight: 650;
    }

    .sectionbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        flex-wrap: wrap;
        margin-bottom: 18px;
    }
    .sectionbar h2 {
        font-size: 22px;
        letter-spacing: -.5px;
        margin: 0;
    }
    .search {
        min-width: 235px;
        width: min(100%, 310px);
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 12px 14px;
        color: var(--text);
        outline: 0;
        box-shadow: var(--shadow);
    }
    .search:focus, input:focus {
        border-color: var(--accent);
        outline: 2px solid transparent;
    }

    .uni-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 16px;
    }
    .uni-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 20px;
        padding: 24px;
        box-shadow: var(--shadow);
        transition: transform .2s ease, border-color .2s ease;
    }
    .uni-card:hover {
        transform: translateY(-2px);
        border-color: var(--accent);
    }
    .uni-top {
        display: flex;
        align-items: flex-start;
        gap: 14px;
    }
    .icon-box-lg {
        width: 48px;
        height: 48px;
        border-radius: 14px;
        background: var(--accent-light);
        color: var(--accent);
        display: grid;
        place-items: center;
        flex-shrink: 0;
    }
    .icon-box-lg svg { width: 24px; height: 24px; }
    .uni-title { flex: 1; min-width: 0; }
    .uni-title h3 {
        font-size: 18px;
        line-height: 1.3;
        letter-spacing: -.5px;
        margin: 2px 0 4px;
        overflow-wrap: anywhere;
    }
    .code {
        color: var(--muted);
        font-size: 11px;
        font-weight: 750;
        letter-spacing: 1px;
    }
    .status {
        font-size: 11px;
        font-weight: 800;
        border-radius: 20px;
        padding: 4px 10px;
        background: var(--soft);
        color: var(--green);
        white-space: nowrap;
    }
    .status.off {
        background: var(--surface-alt);
        color: var(--muted);
    }
    .uni-desc {
        color: var(--muted);
        margin: 18px 0;
        font-size: 13px;
    }
    .uni-footer {
        border-top: 1px solid var(--border);
        padding-top: 16px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        flex-wrap: wrap;
    }
    .degree-count { font-size: 12px; font-weight: 750; }
    .actions { display: flex; gap: 8px; flex-wrap: wrap; }
    .btn-ghost {
        background: var(--surface-alt);
        border: 1px solid var(--border);
        color: var(--text);
        border-radius: 10px;
        padding: 8px 12px;
        font-weight: 700;
        font-size: 12px;
        transition: border-color .18s ease, color .18s ease;
    }
    .btn-ghost:hover {
        border-color: var(--accent);
        color: var(--accent);
    }
    .details {
        border-top: 1px solid var(--border);
        margin-top: 16px;
        padding-top: 14px;
    }
    .details summary {
        cursor: pointer;
        font-weight: 750;
        color: var(--accent);
        font-size: 12px;
    }
    .degree-list { padding: 11px 0 0; }
    .degree {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        padding: 10px 0;
        border-bottom: 1px solid var(--border);
        font-size: 12px;
    }
    .degree:last-child { border: 0; }
    .degree strong { display: block; font-size: 13px; }
    .degree small { color: var(--muted); }
    .degree a { color: var(--accent); font-weight: 750; white-space: nowrap; }

    .empty {
        padding: 40px;
        border: 1px dashed var(--border);
        border-radius: 20px;
        color: var(--muted);
        background: var(--surface);
        text-align: center;
    }
    .alert {
        padding: 14px 18px;
        border-radius: 14px;
        margin-bottom: 24px;
        background: var(--accent-light);
        color: var(--text);
        font-size: 14px;
    }
    .alert.error { background: #ffedf0; color: #a32143; }

    /* Modal / Overlay Drawer */
    .overlay {
        position: fixed;
        inset: 0;
        background: rgba(8,8,24,.52);
        z-index: 100;
        display: none;
        justify-content: flex-end;
    }
    .overlay.open { display: flex; }
    .drawer {
        background: var(--surface);
        height: 100%;
        width: min(100%, 470px);
        padding: 34px;
        overflow: auto;
        box-shadow: -18px 0 50px rgba(0,0,0,.15);
    }
    .drawer-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 15px;
    }
    .drawer h2 { font-size: 26px; letter-spacing: -1px; margin: 8px 0 6px; }
    .drawer p { color: var(--muted); margin: 0 0 28px; font-size: 13px; }
    .close-btn {
        background: var(--surface-alt);
        color: var(--text);
        border: 1px solid var(--border);
        border-radius: 10px;
        padding: 6px 12px;
        font-size: 18px;
    }
    .field { margin-bottom: 22px; }
    .field label { display: block; font-weight: 750; margin-bottom: 8px; font-size: 13px; }
    .field input {
        width: 100%;
        padding: 12px 14px;
        background: var(--surface-alt);
        border: 1px solid var(--border);
        border-radius: 11px;
        color: var(--text);
    }
    .hint { font-size: 11px; color: var(--muted); margin-top: 6px; }
    .drawer-actions { display: flex; gap: 10px; margin-top: 30px; }
    .drawer-actions .btn-primary { flex: 1; justify-content: center; }

    .footer {
        margin-top: 50px;
        padding-top: 20px;
        border-top: 1px solid var(--border);
        font-size: 12px;
        color: var(--muted);
    }
    .hidden { display: none !important; }

    @media (max-width: 1000px) {
        .uni-grid { grid-template-columns: 1fr; }
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
        .stats-grid { grid-template-columns: 1fr; }
        .drawer { padding: 24px; }
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
            <details class="stage9-management">
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
                <a class="active" href="<?= e(url('admin/universities.php')) ?>">
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

    <main class="main">

        <header class="topbar">
            <span class="eyebrow">
                U LEARN / ADMIN SPACE / UNIVERSITIES
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

                <?php require __DIR__ . '/../includes/theme-toggle-button.php'; ?>

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

        <section class="heading">
            <div>
                <div class="eyebrow">Academic structure / Level 01</div>
                <h1>Awarding universities<span style="color:var(--accent)">.</span></h1>
                <p>Build your academic ecosystem. Organize universities, explore their degree programmes, and manage each institution from one place.</p>
            </div>
            <button class="btn-primary" type="button" id="addUniversity">＋ Add university</button>
        </section>

        <?php if ($notice !== ''): ?><div class="alert" role="status"><?= e($notice) ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="alert error" role="alert"><?= e($error) ?></div><?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card">
                <span class="stat-number"><?= count($universities) ?></span>
                <span class="stat-label">Total universities</span>
            </div>
            <div class="stat-card">
                <span class="stat-number"><?= $activeCount ?></span>
                <span class="stat-label">Active universities</span>
            </div>
            <div class="stat-card">
                <span class="stat-number"><?= $degreeCount ?></span>
                <span class="stat-label">Linked active programmes</span>
            </div>
        </div>

        <section>
            <div class="sectionbar">
                <h2>University directory</h2>
                <input class="search" type="search" id="universitySearch" placeholder="Search universities or codes…" aria-label="Search universities">
            </div>
            
            <div class="uni-grid" id="universityGrid">
            <?php foreach ($universities as $university): 
                $id = (int)$university['id']; 
                $active = (int)$university['is_active'] === 1; 
            ?>
                <article class="uni-card" data-search="<?= e(strtolower((string)$university['name'].' '.$university['code'])) ?>">
                    <div class="uni-top">
                        <div class="icon-box-lg" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                                <path d="m2 9 10-5 10 5-10 5z"/><path d="M6 12v5c4 3 8 3 12 0v-5M22 9v7"/>
                            </svg>
                        </div>
                        <div class="uni-title">
                            <div class="code"><?= e((string)$university['code']) ?></div>
                            <h3><?= e((string)$university['name']) ?></h3>
                        </div>
                        <span class="status <?= $active ? '' : 'off' ?>"><?= $active ? 'Active' : 'Archived' ?></span>
                    </div>
                    
                    <p class="uni-desc">Awarding institution · Manage its details and associated academic programmes.</p>
                    
                    <div class="uni-footer">
                        <span class="degree-count"><?= (int)$university['degree_count'] ?> degree programme<?= (int)$university['degree_count'] === 1 ? '' : 's' ?></span>
                        <div class="actions">
                            <button class="btn-ghost edit" type="button" data-id="<?= $id ?>" data-name="<?= e((string)$university['name']) ?>" data-code="<?= e((string)$university['code']) ?>">Edit</button>
                            <form method="post" onsubmit="return confirm('<?= $active ? 'Archive' : 'Reactivate' ?> this university? Existing degree links will be kept.');">
                                <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= $id ?>">
                                <button class="btn-ghost" type="submit"><?= $active ? 'Archive' : 'Reactivate' ?></button>
                            </form>
                        </div>
                    </div>

                    <details class="details">
                        <summary>View degree programmes</summary>
                        <div class="degree-list">
                            <?php if (empty($byUniversity[$id])): ?>
                                <div class="hint">No active degree programmes linked yet.</div>
                            <?php else: foreach ($byUniversity[$id] as $degree): ?>
                                <div class="degree">
                                    <div>
                                        <strong><?= e((string)$degree['name']) ?></strong>
                                        <small><?= e((string)$degree['code']) ?></small>
                                    </div>
                                    <a href="<?= e(url('admin/academics.php')) ?>">Manage ↗</a>
                                </div>
                            <?php endforeach; endif; ?>
                        </div>
                    </details>
                </article>
            <?php endforeach; ?>
            </div>

            <div class="empty <?= $universities ? 'hidden' : '' ?>" id="emptyState">No universities found. Select “Add university” to create your first one.</div>
            <div class="empty hidden" id="noMatches">No universities match your search.</div>
        </section>

        <footer class="footer">
            © <?= date('Y') ?> U Learn · Academic Administration · University records are archived, not permanently deleted.
        </footer>
    </main>
</div>

<div class="overlay" id="overlay" role="presentation">
    <div class="drawer" role="dialog" aria-modal="true" aria-labelledby="drawerTitle">
        <div class="drawer-head">
            <div>
                <div class="eyebrow">University management</div>
                <h2 id="drawerTitle">Add university</h2>
            </div>
            <button class="close-btn" type="button" id="closeDrawer" aria-label="Close form">×</button>
        </div>
        <p id="drawerDescription">Create an awarding university to organize its degree programmes.</p>
        
        <form method="post" id="universityForm">
            <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="editId">
            
            <div class="field">
                <label for="universityName">University name</label>
                <input id="universityName" name="name" maxlength="180" placeholder="e.g. University of Plymouth" required>
                <div class="hint">Use the institution's official name.</div>
            </div>
            
            <div class="field">
                <label for="universityCode">University code</label>
                <input id="universityCode" name="code" maxlength="40" pattern="[A-Za-z0-9_-]+" placeholder="e.g. PLYMOUTH" required>
                <div class="hint">Unique code using letters, numbers, hyphens or underscores.</div>
            </div>
            
            <div class="drawer-actions">
                <button type="button" class="btn-ghost" id="cancelDrawer">Cancel</button>
                <button type="submit" class="btn-primary" id="saveButton">Create university →</button>
            </div>
        </form>
    </div>
</div>

<script>

const overlay = document.getElementById('overlay'),
      form = document.getElementById('universityForm'),
      nameInput = document.getElementById('universityName');
let previousFocus = null;

function openDrawer(button) {
    previousFocus = document.activeElement;
    form.reset();
    document.getElementById('editId').value = '';
    const editing = !!button;
    document.getElementById('drawerTitle').textContent = editing ? 'Edit university' : 'Add university';
    document.getElementById('drawerDescription').textContent = editing ? 'Update this awarding institution. Linked degree programmes will remain attached.' : 'Create an awarding university to organize its degree programmes.';
    document.getElementById('saveButton').textContent = editing ? 'Save changes →' : 'Create university →';
    
    if (editing) {
        document.getElementById('editId').value = button.dataset.id;
        nameInput.value = button.dataset.name;
        document.getElementById('universityCode').value = button.dataset.code;
    }
    overlay.classList.add('open');
    document.body.style.overflow = 'hidden';
    nameInput.focus();
}

function closeDrawer() {
    overlay.classList.remove('open');
    document.body.style.overflow = '';
    if (previousFocus) previousFocus.focus();
}

document.getElementById('addUniversity').addEventListener('click', () => openDrawer(null));
document.querySelectorAll('.edit').forEach(b => b.addEventListener('click', () => openDrawer(b)));
document.getElementById('closeDrawer').addEventListener('click', closeDrawer);
document.getElementById('cancelDrawer').addEventListener('click', closeDrawer);
overlay.addEventListener('click', e => { if (e.target === overlay) closeDrawer(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape' && overlay.classList.contains('open')) closeDrawer(); });

document.getElementById('universitySearch').addEventListener('input', e => {
    const q = e.target.value.trim().toLowerCase();
    let visible = 0;
    document.querySelectorAll('.uni-card').forEach(card => {
        const show = card.dataset.search.includes(q);
        card.classList.toggle('hidden', !show);
        if (show) visible++;
    });
    document.getElementById('noMatches').classList.toggle('hidden', visible !== 0 || document.querySelectorAll('.uni-card').length === 0);
});

<?php if ($error !== '' && ($_POST['action'] ?? '') === 'save'): ?>
openDrawer(null);
nameInput.value = <?= json_encode((string)($_POST['name'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
document.getElementById('universityCode').value = <?= json_encode((string)($_POST['code'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
document.getElementById('editId').value = <?= json_encode((string)($_POST['id'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
<?php endif; ?>
</script>


</body>
</html>
