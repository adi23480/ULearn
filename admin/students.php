<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$admin = require_login();
if (!in_array((string)($admin['role'] ?? ''), ['admin', 'main_admin'], true)) {
    http_response_code(403);
    exit('Access denied. Admin permission required.');
}

$pdo = db();
$adminId = (int)$admin['id'];

// Personal unread notifications for the signed-in administrator.
$notificationStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0"
);
$notificationStmt->execute([$adminId]);
$unreadNotifications = (int)$notificationStmt->fetchColumn();

$error = '';
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $action = (string)($_POST['action'] ?? '');
        $student = (int)($_POST['student_user_id'] ?? 0);
        $q = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'student'");
        $q->execute([$student]);
        if (!$q->fetchColumn()) {
            throw new RuntimeException('Student not found');
        }
        if (in_array($action, ['suspend', 'restore', 'revoke'], true)) {
            $pdo->beginTransaction();
            if ($action === 'suspend') {
                $sql = "UPDATE users SET account_status = 'suspended', session_version = session_version + 1 WHERE id = ?";
            } elseif ($action === 'restore') {
                $sql = "UPDATE users SET account_status = 'active', session_version = session_version + 1 WHERE id = ?";
            } else {
                $sql = "UPDATE users SET session_version = session_version + 1 WHERE id = ?";
            }
            $pdo->prepare($sql)->execute([$student]);
            $pdo->prepare('INSERT INTO student_account_audit(student_user_id, admin_user_id, action) VALUES (?, ?, ?)')->execute([$student, $adminId, $action]);
            $pdo->commit();
            $notice = 'Account action completed.';
        } elseif ($action === 'correct') {
            $degree = (int)($_POST['degree_id'] ?? 0);
            $batch = (int)($_POST['batch_id'] ?? 0);
            $q = $pdo->prepare('SELECT 1 FROM academic_degree_intakes adi JOIN academic_degrees d ON d.id = adi.degree_id JOIN awarding_universities au ON au.id = d.awarding_university_id JOIN academic_batches b ON b.id = adi.batch_id WHERE adi.degree_id = ? AND adi.batch_id = ? AND adi.is_active = 1 AND d.is_active = 1 AND au.is_active = 1 AND b.is_active = 1');
            $q->execute([$degree, $batch]);
            if (!$q->fetchColumn()) {
                throw new RuntimeException('Degree/intake combination is not active');
            }
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO student_academic_profiles(user_id, degree_id, batch_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE degree_id = VALUES(degree_id), batch_id = VALUES(batch_id)')->execute([$student, $degree, $batch]);
            $pdo->prepare('INSERT INTO student_account_audit(student_user_id, admin_user_id, action) VALUES (?, ?, ?)')->execute([$student, $adminId, 'correct_enrollment']);
            $pdo->commit();
            $notice = 'Academic profile updated.';
        }
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $ex instanceof RuntimeException ? $ex->getMessage() : 'Update failed; check server logs.';
        if (!($ex instanceof RuntimeException)) {
            error_log($ex->getMessage());
        }
    }
}

$students = $pdo->query("SELECT u.id, u.full_name, u.email, u.student_number, u.email_verified_at, u.account_status, u.created_at, d.name AS degree_name, au.name AS university_name, b.batch_code, p.degree_id, p.batch_id, ap.study_year, ap.semester FROM users u LEFT JOIN student_academic_profiles p ON p.user_id = u.id LEFT JOIN academic_degrees d ON d.id = p.degree_id LEFT JOIN awarding_universities au ON au.id = d.awarding_university_id LEFT JOIN academic_batches b ON b.id = p.batch_id LEFT JOIN academic_intake_progression ap ON ap.degree_id = p.degree_id AND ap.batch_id = p.batch_id WHERE u.role = 'student' ORDER BY u.id DESC")->fetchAll(PDO::FETCH_ASSOC);
$degrees = $pdo->query('SELECT d.id, d.name, au.name AS university_name FROM academic_degrees d JOIN awarding_universities au ON au.id = d.awarding_university_id WHERE d.is_active = 1 AND au.is_active = 1 ORDER BY au.name, d.name')->fetchAll(PDO::FETCH_ASSOC);
$links = $pdo->query('SELECT adi.degree_id, adi.batch_id, b.batch_code FROM academic_degree_intakes adi JOIN academic_batches b ON b.id = adi.batch_id WHERE adi.is_active = 1 AND b.is_active = 1')->fetchAll(PDO::FETCH_ASSOC);
$selected = (int)($_GET['id'] ?? 0);

$fullName = (string)($admin['full_name'] ?? 'Administrator');
$nameParts = preg_split('/\s+/', trim($fullName));
$firstName = $nameParts[0] ?: 'Administrator';
$initial = strtoupper(substr($firstName, 0, 1));
$roleLabel = $admin['role'] === 'main_admin' ? 'Main Administrator' : 'Administrator';
$theme = (string)($admin['theme'] ?? 'system');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Student Management — U Learn</title>
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
        --danger: #a9274c;
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
        --danger: #ff91ad;
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

    h1 {
        font-size: clamp(28px, 4vw, 44px);
        letter-spacing: -1.5px;
        margin: 6px 0 10px;
    }
    h1 span { color: var(--accent); }
    .lead { color: var(--muted); font-size: 14px; max-width: 680px; margin-bottom: 0; }

    .panel {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 20px;
        padding: 28px;
        margin-bottom: 20px;
        box-shadow: var(--shadow);
    }
    .grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 16px;
    }
    .student {
        border-top: 1px solid var(--border);
        padding: 18px 0;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 15px;
        flex-wrap: wrap;
    }
    .student:first-child { border-top: 0; padding-top: 0; }
    
    button, .btn {
        background: var(--accent);
        color: #fff;
        border: 0;
        padding: 11px 18px;
        border-radius: 12px;
        cursor: pointer;
        font-size: 13px;
        font-weight: 700;
        box-shadow: 0 8px 20px rgba(103,86,232,.2);
        transition: transform .18s ease, filter .18s ease;
    }
    button:hover, .btn:hover { filter: brightness(1.08); transform: translateY(-2px); }
    button.warn { background: #c43b54; box-shadow: 0 8px 20px rgba(196,59,84,.2); }
    button.soft { background: var(--surface-alt); color: var(--text); border: 1px solid var(--border); box-shadow: none; }
    button.soft:hover { border-color: var(--accent); color: var(--accent); }

    input, select {
        width: 100%;
        border: 1px solid var(--border);
        border-radius: 11px;
        padding: 12px 14px;
        background: var(--surface-alt);
        color: var(--text);
        outline: none;
        transition: border-color .18s ease;
    }
    input:focus, select:focus { border-color: var(--accent); }
    label { display: block; font-weight: 700; margin-bottom: 8px; font-size: 13px; }
    
    .message {
        padding: 14px 18px;
        border-radius: 14px;
        margin: 22px 0;
        font-size: 14px;
    }
    .success { background: var(--soft); color: var(--green); }
    .error { background: #ffedf0; color: #a32143; }
    
    form.inline { display: inline-block; margin: 4px; }
    small { display: block; color: var(--muted); font-size: 12px; margin-top: 3px; }
    
    .footer {
        margin-top: 50px;
        padding-top: 20px;
        border-top: 1px solid var(--border);
        font-size: 12px;
        color: var(--muted);
    }

    @media (max-width: 900px) {
        .layout { grid-template-columns: 1fr; }
        .sidebar { border-right: 0; border-bottom: 1px solid var(--border); padding: 18px; gap: 18px; }
        .nav { display: flex; flex-wrap: wrap; }
        .sidebar-bottom { display: none; }
        .main { padding-top: 25px; }
    }
    @media (max-width: 600px) {
        .main { padding: 24px 18px 60px; }
        .panel { padding: 20px; }
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
            <small><?= e((string)$admin['email']) ?></small>
        </div>
    </aside>

    <main class="main">

        <header class="topbar">
            <span class="eyebrow">
                U LEARN / ADMIN SPACE / STUDENT MANAGEMENT
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

        <section>
            <div class="eyebrow">ADMINISTRATION WORKSPACE</div>
            <h1>Student management<span>.</span></h1>
            <p class="lead">Student directory, academic profiles and security controls. <a href="<?= e(url('admin/intake-progression.php')) ?>">Manage intake progression →</a></p>
        </section>

        <?php if ($error !== ''): ?><div class="message error" role="alert"><?= e($error) ?></div><?php endif; ?>
        <?php if ($notice !== ''): ?><div class="message success" role="status"><?= e($notice) ?></div><?php endif; ?>

        <div class="panel">
            <div class="grid">
                <div>
                    <strong style="font-size: 28px; font-weight: 850; letter-spacing: -1px;"><?= count($students) ?></strong>
                    <small>Total students</small>
                </div>
                <div>
                    <strong style="font-size: 28px; font-weight: 850; letter-spacing: -1px;"><?= count(array_filter($students, fn($s) => $s['account_status'] === 'active')) ?></strong>
                    <small>Active accounts</small>
                </div>
                <div>
                    <strong style="font-size: 28px; font-weight: 850; letter-spacing: -1px;"><?= count(array_filter($students, fn($s) => $s['account_status'] === 'suspended')) ?></strong>
                    <small>Suspended</small>
                </div>
            </div>
        </div>

        <div class="panel">
            <div style="margin-bottom: 18px;">
                <label for="search">Search students</label>
                <input id="search" type="search" placeholder="Name, email, ID, university, degree or intake">
            </div>
            <div id="records">
                <?php foreach ($students as $s): ?>
                    <div class="student" data-search="<?= e(mb_strtolower(implode(' ', array_map('strval', [$s['full_name'], $s['email'], $s['student_number'], $s['university_name'], $s['degree_name'], $s['batch_code']])))) ?>">
                        <div>
                            <strong><?= e($s['full_name']) ?></strong>
                            <small><?= e($s['email']) ?> · Student ID <?= e((string)($s['student_number'] ?? 'Not set')) ?></small>
                            <small><?= e(($s['university_name'] ?? 'No university') . ' · ' . ($s['degree_name'] ?? 'No degree') . ' · Intake ' . ($s['batch_code'] ?? '—')) ?> · Year <?= (int)($s['study_year'] ?? 1) ?> / Semester <?= (int)($s['semester'] ?? 1) ?></small>
                            <small><?= $s['email_verified_at'] ? 'Email verified' : 'Email not verified' ?> · <?= e($s['account_status']) ?></small>
                        </div>
                        <a class="btn" href="?id=<?= (int)$s['id'] ?>" style="text-decoration:none; display:inline-block;">Manage profile</a>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php foreach ($students as $s): if ((int)$s['id'] !== $selected) continue; ?>
            <section class="panel">
                <h2><?= e($s['full_name']) ?> — Account controls</h2>
                <p style="color: var(--muted); font-size: 13px;"><?= e($s['email']) ?> · Student ID <?= e((string)($s['student_number'] ?? 'Not set')) ?></p>
                <p style="font-size: 13px; margin-bottom: 20px;"><strong>Status:</strong> <?= e($s['account_status']) ?> · <strong>Email:</strong> <?= $s['email_verified_at'] ? 'Verified' : 'Unverified' ?></p>

                <div style="margin-bottom: 25px;">
                    <?php foreach (['suspend' => 'Suspend account', 'restore' => 'Restore account', 'revoke' => 'Revoke all sessions'] as $action => $label): ?>
                        <form method="post" class="inline" onsubmit="return confirm('<?= e($label) ?>?')">
                            <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                            <input type="hidden" name="student_user_id" value="<?= (int)$s['id'] ?>">
                            <input type="hidden" name="action" value="<?= e($action) ?>">
                            <button class="<?= $action === 'suspend' ? 'warn' : 'soft' ?>" type="submit"><?= e($label) ?></button>
                        </form>
                    <?php endforeach; ?>
                </div>

                <h3 style="font-size: 18px; margin-bottom: 14px;">Correct academic profile</h3>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                    <input type="hidden" name="student_user_id" value="<?= (int)$s['id'] ?>">
                    <input type="hidden" name="action" value="correct">
                    <div class="grid">
                        <div>
                            <label>Degree</label>
                            <select name="degree_id" id="degree" required>
                                <option value="">Choose</option>
                                <?php foreach ($degrees as $d): ?>
                                    <option value="<?= (int)$d['id'] ?>" <?= (int)$d['id'] === (int)$s['degree_id'] ? 'selected' : '' ?>><?= e($d['university_name'] . ' — ' . $d['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label>Intake</label>
                            <select name="batch_id" id="batch" required></select>
                        </div>
                    </div>
                    <div style="margin-top: 20px;">
                        <button type="submit">Save academic correction</button>
                    </div>
                </form>
            </section>
            <script>
                const links = <?= json_encode($links, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                const deg = document.getElementById('degree'), batch = document.getElementById('batch');
                function sync() {
                    const prev = batch.value;
                    batch.innerHTML = '<option value="">Choose intake</option>';
                    links.filter(x => String(x.degree_id) === deg.value).forEach(x => batch.add(new Option(x.batch_code, x.batch_id)));
                    batch.value = prev || <?= json_encode((string)$s['batch_id']) ?>;
                }
                deg.addEventListener('change', () => { batch.value = ''; sync(); });
                sync();
            </script>
        <?php endforeach; ?>

        <footer class="footer">
            © <?= date('Y') ?> U Learn · Administration Workspace
        </footer>
    </main>
</div>

<script>
const search = document.getElementById('search');
if (search) {
    search.addEventListener('input', e => {
        const q = e.target.value.toLowerCase();
        document.querySelectorAll('.student').forEach(s => s.hidden = !s.dataset.search.includes(q));
    });
}
</script>


</body>
</html>
