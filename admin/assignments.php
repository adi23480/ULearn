<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$user = require_login();
if (!in_array((string)($user['role'] ?? ''), ['admin', 'main_admin'], true)) {
    http_response_code(403);
    exit('Access denied. Admin permission required.');
}

$pdo = db();

// Personal unread notifications for the signed-in administrator.
$notificationStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0"
);
$notificationStmt->execute([(int)$user['id']]);
$unreadNotifications = (int)$notificationStmt->fetchColumn();

$error = '';
$notice = (string)($_SESSION['assignment_notice'] ?? '');
unset($_SESSION['assignment_notice']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $id = filter_var($_POST['assignment_id'] ?? null, FILTER_VALIDATE_INT);

    try {
        if ($action === 'degree_assign') {
            $lecturerId = filter_var($_POST['lecturer_id'] ?? null, FILTER_VALIDATE_INT);
            $degreeId = filter_var($_POST['degree_id'] ?? null, FILTER_VALIDATE_INT);
            $batchId = filter_var($_POST['batch_id'] ?? null, FILTER_VALIDATE_INT);
            $year = filter_var($_POST['study_year'] ?? null, FILTER_VALIDATE_INT);
            $semester = filter_var($_POST['semester'] ?? 0, FILTER_VALIDATE_INT);
            if (!$lecturerId || !$degreeId || !$batchId || !$year || $year < 1 || $year > 5 || $semester === false || $semester < 0 || $semester > 3) {
                throw new RuntimeException('Select a lecturer, degree, intake, and academic year.');
            }
            $pdo->beginTransaction();
            $check = $pdo->prepare("SELECT lp.id FROM lecturer_profiles lp JOIN users u ON u.id = lp.user_id WHERE lp.id = ? AND lp.is_active = 1 AND u.role = 'lecturer' AND u.email_verified_at IS NOT NULL LIMIT 1");
            $check->execute([$lecturerId]);
            if (!$check->fetchColumn()) { throw new RuntimeException('This lecturer is not active and verified.'); }
            $check = $pdo->prepare('SELECT id FROM academic_degrees WHERE id = ? AND is_active = 1 LIMIT 1');
            $check->execute([$degreeId]);
            if (!$check->fetchColumn()) { throw new RuntimeException('This degree is not active.'); }
            $check = $pdo->prepare('SELECT id FROM academic_batches WHERE id = ? AND is_active = 1 LIMIT 1');
            $check->execute([$batchId]);
            if (!$check->fetchColumn()) { throw new RuntimeException('This intake is not active.'); }
            // When degree-intake links exist, enforce the actual academic relationship.
            $linkCheck = $pdo->prepare('SELECT COUNT(*) FROM academic_degree_intakes WHERE degree_id = ? AND batch_id = ?');
            $linkCheck->execute([$degreeId, $batchId]);
            if (!(int)$linkCheck->fetchColumn()) { throw new RuntimeException('Link this intake to the degree in Academic Management first.'); }
            $stmt = $pdo->prepare('INSERT INTO lecturer_degree_assignments (lecturer_id, degree_id, batch_id, study_year, semester, is_active) VALUES (?, ?, ?, ?, ?, 1) ON DUPLICATE KEY UPDATE is_active = 1');
            $stmt->execute([$lecturerId, $degreeId, $batchId, $year, $semester]);
            $pdo->commit();
            $_SESSION['assignment_notice'] = 'Degree-level lecturer assignment saved. No module selection was required.';
        } elseif ($action === 'degree_deactivate' || $action === 'degree_reactivate' || $action === 'degree_remove') {
            if (!$id || $id < 1) { throw new RuntimeException('Invalid degree assignment.'); }
            if ($action === 'degree_remove') {
                $stmt = $pdo->prepare('DELETE FROM lecturer_degree_assignments WHERE id = ?');
            } else {
                $stmt = $pdo->prepare('UPDATE lecturer_degree_assignments SET is_active = ' . ($action === 'degree_reactivate' ? '1' : '0') . ' WHERE id = ?');
            }
            $stmt->execute([$id]);
            if (!$stmt->rowCount()) { throw new RuntimeException('Degree assignment not found or unchanged.'); }
            $_SESSION['assignment_notice'] = 'Degree-level assignment updated.';
        } elseif ($action === 'assign') {
            $lecturerId = filter_var($_POST['lecturer_id'] ?? null, FILTER_VALIDATE_INT);
            $rawIds = $_POST['curriculum_ids'] ?? [];
            if (!is_array($rawIds)) {
                throw new RuntimeException('Invalid module selection.');
            }
            $curriculumIds = array_values(array_unique(array_filter(array_map(
                static fn($value) => filter_var($value, FILTER_VALIDATE_INT), $rawIds
            ), static fn($value) => $value !== false && $value > 0)));
            if (!$lecturerId || $lecturerId < 1 || !$curriculumIds || count($curriculumIds) > 200) {
                throw new RuntimeException('Choose a lecturer and between 1 and 200 curriculum entries.');
            }
            $pdo->beginTransaction();
            $check = $pdo->prepare("SELECT lp.id FROM lecturer_profiles lp JOIN users u ON u.id = lp.user_id WHERE lp.id = ? AND lp.is_active = 1 AND u.role = 'lecturer' AND u.email_verified_at IS NOT NULL LIMIT 1");
            $check->execute([$lecturerId]);
            if (!$check->fetchColumn()) {
                throw new RuntimeException('This lecturer is not active and verified.');
            }
            $check = $pdo->prepare('SELECT ac.id FROM academic_curriculum ac JOIN academic_degrees d ON d.id = ac.degree_id JOIN academic_batches b ON b.id = ac.batch_id JOIN academic_modules m ON m.id = ac.module_id WHERE ac.id = ? AND ac.is_active = 1 AND d.is_active = 1 AND b.is_active = 1 AND m.is_active = 1 LIMIT 1');
            $insert = $pdo->prepare('INSERT INTO lecturer_module_assignments (lecturer_id, curriculum_id, is_active) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE is_active = 1');
            foreach ($curriculumIds as $curriculumId) {
                $check->execute([$curriculumId]);
                if (!$check->fetchColumn()) {
                    throw new RuntimeException('One of the selected curriculum entries is no longer active.');
                }
                $insert->execute([$lecturerId, $curriculumId]);
            }
            $pdo->commit();
            $_SESSION['assignment_notice'] = count($curriculumIds) . ' lecturer assignment(s) saved or reactivated.';
        } elseif ($action === 'deactivate' || $action === 'reactivate' || $action === 'remove') {
            if (!$id || $id < 1) {
                throw new RuntimeException('Invalid assignment.');
            }
            if ($action === 'reactivate') {
                $check = $pdo->prepare("SELECT lma.id FROM lecturer_module_assignments lma JOIN lecturer_profiles lp ON lp.id = lma.lecturer_id JOIN users u ON u.id = lp.user_id JOIN academic_curriculum ac ON ac.id = lma.curriculum_id JOIN academic_degrees d ON d.id = ac.degree_id JOIN academic_batches b ON b.id = ac.batch_id JOIN academic_modules m ON m.id = ac.module_id WHERE lma.id = ? AND lp.is_active = 1 AND u.role = 'lecturer' AND u.email_verified_at IS NOT NULL AND ac.is_active = 1 AND d.is_active = 1 AND b.is_active = 1 AND m.is_active = 1 LIMIT 1");
                $check->execute([$id]);
                if (!$check->fetchColumn()) {
                    throw new RuntimeException('Cannot reactivate: lecturer or curriculum is inactive.');
                }
            }
            if ($action === 'remove') {
                $stmt = $pdo->prepare('DELETE FROM lecturer_module_assignments WHERE id = ?');
                $stmt->execute([$id]);
                $_SESSION['assignment_notice'] = 'Assignment removed.';
            } else {
                $stmt = $pdo->prepare('UPDATE lecturer_module_assignments SET is_active = ? WHERE id = ?');
                $stmt->execute([$action === 'reactivate' ? 1 : 0, $id]);
                $_SESSION['assignment_notice'] = $action === 'reactivate' ? 'Assignment reactivated.' : 'Assignment deactivated.';
            }
            if ($stmt->rowCount() === 0) {
                unset($_SESSION['assignment_notice']);
                throw new RuntimeException('Assignment not found or already in the requested state.');
            }
        } else {
            throw new RuntimeException('Invalid action.');
        }
        go('admin/assignments.php');
        exit;
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('U Learn assignment error: ' . $exception->getMessage());
        $error = 'Unable to update the assignment. Please try again.';
    } catch (RuntimeException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $exception->getMessage();
    }
}

$lecturers = $pdo->query("SELECT lp.id, lp.title, u.full_name, lp.department FROM lecturer_profiles lp JOIN users u ON u.id = lp.user_id WHERE lp.is_active = 1 AND u.role = 'lecturer' AND u.email_verified_at IS NOT NULL ORDER BY u.full_name")->fetchAll(PDO::FETCH_ASSOC);
// University-to-degree relation is detected from the existing schema.
$degreeColumns = $pdo->query('SHOW COLUMNS FROM academic_degrees')->fetchAll(PDO::FETCH_COLUMN);
$universityColumn = in_array('awarding_university_id', $degreeColumns, true) ? 'awarding_university_id' : (in_array('university_id', $degreeColumns, true) ? 'university_id' : null);
$universities = $pdo->query('SELECT id, name FROM awarding_universities ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$degreesSql = 'SELECT id, name' . ($universityColumn !== null ? ', `' . $universityColumn . '` AS university_id' : ', NULL AS university_id') . ' FROM academic_degrees ORDER BY name';
$degrees = $pdo->query($degreesSql)->fetchAll(PDO::FETCH_ASSOC);
$batches = $pdo->query('SELECT id, batch_code FROM academic_batches ORDER BY batch_code DESC')->fetchAll(PDO::FETCH_ASSOC);
$modules = $pdo->query('SELECT id, module_code, module_name FROM academic_modules ORDER BY module_code')->fetchAll(PDO::FETCH_ASSOC);
$curriculumSql = 'SELECT ac.id, ac.degree_id, ac.batch_id, d.name AS degree_name, b.batch_code, m.module_code, m.module_name, ac.study_year, ac.semester' . ($universityColumn !== null ? ', d.`' . $universityColumn . '` AS university_id' : ', NULL AS university_id') . ' FROM academic_curriculum ac JOIN academic_degrees d ON d.id = ac.degree_id JOIN academic_batches b ON b.id = ac.batch_id JOIN academic_modules m ON m.id = ac.module_id WHERE ac.is_active = 1 AND d.is_active = 1 AND b.is_active = 1 AND m.is_active = 1 ORDER BY d.name, b.batch_code, ac.study_year, ac.semester, m.module_code';
$curricula = $pdo->query($curriculumSql)->fetchAll(PDO::FETCH_ASSOC);
$linkedModuleIds = array_map('intval', $pdo->query('SELECT DISTINCT module_id FROM academic_curriculum WHERE is_active = 1')->fetchAll(PDO::FETCH_COLUMN));
$linkedModuleLookup = array_fill_keys($linkedModuleIds, true);
$assignments = $pdo->query('SELECT lma.id, lma.is_active, lma.created_at, lp.title, lp.is_active AS lecturer_active, u.full_name, u.role AS lecturer_role, d.name AS degree_name, d.is_active AS degree_active, b.batch_code, b.is_active AS batch_active, m.module_code, m.module_name, m.is_active AS module_active, ac.study_year, ac.semester, ac.is_active AS curriculum_active FROM lecturer_module_assignments lma JOIN lecturer_profiles lp ON lp.id = lma.lecturer_id JOIN users u ON u.id = lp.user_id JOIN academic_curriculum ac ON ac.id = lma.curriculum_id JOIN academic_degrees d ON d.id = ac.degree_id JOIN academic_batches b ON b.id = ac.batch_id JOIN academic_modules m ON m.id = ac.module_id ORDER BY lma.is_active DESC, u.full_name, d.name, b.batch_code, m.module_code')->fetchAll(PDO::FETCH_ASSOC);

// FIXED QUERY: Join lecturer_degree_assignments via lecturer_id -> lecturer_profiles -> users
$degreeAssignments = $pdo->query('SELECT lda.id, lda.is_active, lda.study_year, lda.semester, lda.degree_id, lda.batch_id, lp.title, u.full_name, d.name AS degree_name, b.batch_code FROM lecturer_degree_assignments lda JOIN lecturer_profiles lp ON lp.id = lda.lecturer_id JOIN users u ON u.id = lp.user_id JOIN academic_degrees d ON d.id = lda.degree_id JOIN academic_batches b ON b.id = lda.batch_id ORDER BY lda.is_active DESC, u.full_name, d.name, b.batch_code')->fetchAll(PDO::FETCH_ASSOC);

$degreeIntakeLinks = $pdo->query('SELECT degree_id, batch_id FROM academic_degree_intakes')->fetchAll(PDO::FETCH_ASSOC);

$fullName = (string)($user['full_name'] ?? 'Administrator');
$nameParts = preg_split('/\s+/', trim($fullName));
$firstName = $nameParts[0] ?: 'Administrator';
$initial = strtoupper(substr($firstName, 0, 1));
$theme = (string)($user['theme'] ?? 'system');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Lecturer Assignments — U Learn</title>
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
    button, select, input { font: inherit; }
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

    .card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 20px;
        padding: 28px;
        margin-bottom: 18px;
        box-shadow: var(--shadow);
    }

    .form-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 18px;
    }
    .field { margin-bottom: 18px; }
    label { display: block; font-size: 13px; font-weight: 700; margin-bottom: 8px; }
    select, input[type="text"] {
        width: 100%;
        padding: 12px 14px;
        border: 1px solid var(--border);
        border-radius: 11px;
        background: var(--surface-alt);
        color: var(--text);
        outline: none;
        transition: border-color .18s ease;
    }
    select:focus, input[type="text"]:focus { border-color: var(--accent); }

    .btn-primary {
        background: var(--accent);
        color: #fff;
        border: 0;
        border-radius: 12px;
        padding: 13px 20px;
        font-size: 13px;
        font-weight: 700;
        cursor: pointer;
        box-shadow: 0 8px 20px rgba(103,86,232,.2);
        transition: transform .18s ease, filter .18s ease;
    }
    .btn-primary:disabled { opacity: .45; cursor: not-allowed; box-shadow: none; }
    .btn-primary:hover:not(:disabled) { filter: brightness(1.08); transform: translateY(-2px); }

    .message {
        padding: 14px 18px;
        border-radius: 14px;
        margin: 22px 0;
        font-size: 14px;
    }
    .success { background: var(--soft); color: var(--green); }
    .error { background: #ffedf0; color: #a32143; }
    .empty { color: var(--muted); font-size: 13px; padding: 14px 0; margin: 0; }
    .hint { color: var(--muted); font-size: 13px; margin-bottom: 18px; }

    .records { display: grid; gap: 12px; }
    .record {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 20px;
        padding: 18px;
        border: 1px solid var(--border);
        border-radius: 14px;
        background: var(--surface);
    }
    .record strong { display: block; font-size: 14px; }
    .record small { display: block; color: var(--muted); font-size: 12px; margin-top: 4px; }
    .record-actions {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
        justify-content: flex-end;
    }
    .pill {
        display: inline-block;
        border-radius: 100px;
        padding: 5px 11px;
        background: var(--soft);
        color: var(--green);
        font-size: 11px;
        font-weight: 750;
        white-space: nowrap;
    }
    .pill.off { background: var(--surface-alt); color: var(--muted); }
    .action {
        border: 1px solid var(--border);
        background: var(--surface-alt);
        color: var(--text);
        padding: 8px 12px;
        border-radius: 10px;
        cursor: pointer;
        font-size: 12px;
        font-weight: 650;
        transition: border-color .18s ease, color .18s ease;
    }
    .action.danger { color: #b43d50; }
    .action:hover { border-color: var(--accent); color: var(--accent); }
    .action.danger:hover { border-color: #b43d50; color: #b43d50; }

    .footer {
        margin-top: 50px;
        padding-top: 20px;
        border-top: 1px solid var(--border);
        font-size: 12px;
        color: var(--muted);
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
    @media (max-width: 650px) {
        .main { padding: 24px 18px 60px; }
        .form-grid { grid-template-columns: 1fr; gap: 0; }
        .card { padding: 20px; }
        .record { align-items: flex-start; flex-direction: column; }
        .record-actions { justify-content: flex-start; }
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
                <a class="active" href="<?= e(url('admin/assignments.php')) ?>">
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
            <small><?= e((string)$user['email']) ?></small>
        </div>
    </aside>

    <main class="main">

        <header class="topbar">
            <span class="eyebrow">
                U LEARN / ADMIN SPACE / ASSIGNMENTS
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

        <section>
            <div class="eyebrow">ADMINISTRATION WORKSPACE</div>
            <h1>Lecturer assignments<span>.</span></h1>
            <p class="lead">Assign lecturers to degree programmes without selecting modules. Specific module assignments are optional and managed separately.</p>
        </section>

        <?php if ($notice !== ''): ?><div class="message success" role="status"><?= e($notice) ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="message error" role="alert"><?= e($error) ?></div><?php endif; ?>

        <section>
            <div class="section-heading">
                <h2>Assign lecturer to a degree programme</h2>
                <small>No module required</small>
            </div>
            <div class="card">
                <p class="hint">Choose the degree, linked intake, and academic year. Semester is optional (All semesters). This grants a degree-level assignment; individual module assignments remain separate.</p>
                <form method="post" id="degreeAssignmentForm">
                    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                    <input type="hidden" name="action" value="degree_assign">
                    <div class="form-grid">
                        <div class="field">
                            <label for="degreeLecturer">Lecturer</label>
                            <select id="degreeLecturer" name="lecturer_id" required>
                                <option value="">Select lecturer</option>
                                <?php foreach ($lecturers as $lecturer): ?>
                                    <option value="<?= (int)$lecturer['id'] ?>"><?= e(trim((string)$lecturer['title'].' '.(string)$lecturer['full_name'])) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="degreeUniversity">University</label>
                            <select id="degreeUniversity">
                                <option value="">Select university</option>
                                <?php foreach ($universities as $uni): ?>
                                    <option value="<?= (int)$uni['id'] ?>"><?= e((string)$uni['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="degreeProgramme">Degree programme</label>
                            <select id="degreeProgramme" name="degree_id" required>
                                <option value="">Select degree</option>
                                <?php foreach ($degrees as $degree): ?>
                                    <option value="<?= (int)$degree['id'] ?>" data-university="<?= e((string)($degree['university_id'] ?? '')) ?>"><?= e((string)$degree['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="degreeIntake">Intake / Batch</label>
                            <select id="degreeIntake" name="batch_id" required>
                                <option value="">Select intake</option>
                                <?php foreach ($batches as $batch): ?>
                                    <option value="<?= (int)$batch['id'] ?>"><?= e((string)$batch['batch_code']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="degreeYear">Academic year</label>
                            <select id="degreeYear" name="study_year" required>
                                <option value="">Select year</option>
                                <?php for ($y=1;$y<=5;$y++): ?>
                                    <option value="<?= $y ?>">Year <?= $y ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="degreeSemester">Semester (optional)</label>
                            <select id="degreeSemester" name="semester">
                                <option value="0">All semesters</option>
                                <?php for ($sem=1;$sem<=3;$sem++): ?>
                                    <option value="<?= $sem ?>">Semester <?= $sem ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                    <button class="btn-primary" type="submit" <?= !$lecturers ? 'disabled' : '' ?>>Save degree-level assignment ↗</button>
                </form>
                <script>
                (function(){
                    const links = <?= json_encode($degreeIntakeLinks, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
                    const university = document.getElementById('degreeUniversity'), degree = document.getElementById('degreeProgramme'), intake = document.getElementById('degreeIntake');
                    function update(){
                        const selectedUniversity = university.value;
                        Array.from(degree.options).forEach(o => {
                            if (!o.value) return;
                            o.hidden = !!selectedUniversity && o.dataset.university !== selectedUniversity;
                            o.disabled = o.hidden;
                        });
                        if (degree.selectedOptions[0]?.disabled) degree.value = '';
                        const selectedDegree = Number(degree.value);
                        Array.from(intake.options).forEach(o => {
                            if (!o.value) return;
                            const allowed = !selectedDegree || links.some(l => Number(l.degree_id) === selectedDegree && Number(l.batch_id) === Number(o.value));
                            o.hidden = !allowed;
                            o.disabled = !allowed;
                        });
                        if (intake.selectedOptions[0]?.disabled) intake.value = '';
                    }
                    university.addEventListener('change', () => { degree.value = ''; update(); });
                    degree.addEventListener('change', () => { intake.value = ''; update(); });
                    update();
                })();
                </script>
            </div>
        </section>

        <section>
            <div class="section-heading">
                <h2>Degree-level assignments</h2>
                <small><?= count($degreeAssignments) ?> total</small>
            </div>
            <div class="card">
                <div class="records">
                    <?php if (!$degreeAssignments): ?>
                        <p class="empty">No degree-level lecturer assignments yet.</p>
                    <?php endif; ?>
                    <?php foreach ($degreeAssignments as $assignment): ?>
                        <div class="record">
                            <div>
                                <strong><?= e(trim((string)$assignment['title'].' '.(string)$assignment['full_name'])) ?> — <?= e((string)$assignment['degree_name']) ?></strong>
                                <small>Intake <?= e((string)$assignment['batch_code']) ?> · Year <?= (int)$assignment['study_year'] ?> · <?= (int)$assignment['semester'] === 0 ? 'All semesters' : 'Semester '.(int)$assignment['semester'] ?></small>
                            </div>
                            <div class="record-actions">
                                <a class="action" href="<?= e(url('admin/module-lecturers.php')) ?>?degree_id=<?= (int)$assignment['degree_id'] ?>&amp;batch_id=<?= (int)$assignment['batch_id'] ?>&amp;year=<?= (int)$assignment['study_year'] ?>&amp;semester=<?= (int)$assignment['semester'] ?>">Manage modules ↗</a>
                                <span class="pill <?= (int)$assignment['is_active'] ? '' : 'off' ?>"><?= (int)$assignment['is_active'] ? 'Active' : 'Inactive' ?></span>
                                <form method="post" style="margin:0;">
                                    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                                    <input type="hidden" name="assignment_id" value="<?= (int)$assignment['id'] ?>">
                                    <input type="hidden" name="action" value="<?= (int)$assignment['is_active'] ? 'degree_deactivate' : 'degree_reactivate' ?>">
                                    <button class="action" type="submit"><?= (int)$assignment['is_active'] ? 'Deactivate' : 'Reactivate' ?></button>
                                </form>
                                <form method="post" style="margin:0;" onsubmit="return confirm('Permanently remove this degree-level assignment?');">
                                    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                                    <input type="hidden" name="assignment_id" value="<?= (int)$assignment['id'] ?>">
                                    <input type="hidden" name="action" value="degree_remove">
                                    <button class="action danger" type="submit">Remove</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section>
            <div class="section-heading">
                <h2>Module lecturer assignments</h2>
            </div>
            <div class="card">
                <p class="lead" style="margin-bottom: 20px;">Manage lecturers for individual modules within their degree programme, intake, year and semester.</p>
                <a class="btn-primary" style="display:inline-block;" href="<?= e(url('admin/module-lecturers.php')) ?>">Open module lecturer management ↗</a>
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