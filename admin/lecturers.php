<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$admin = require_login();

if (!in_array($admin['role'], ['admin', 'main_admin'], true)) {
    http_response_code(403);
    exit('Access denied. Admin permission required.');
}

$pdo = db();
$error = '';
$success = '';

function lecturerRedirect(): never
{
    go('admin/lecturers.php');
}

if (isset($_SESSION['lecturer_success'])) {
    $success = (string)$_SESSION['lecturer_success'];
    unset($_SESSION['lecturer_success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'invite') {
            require_once __DIR__ . '/../config/otp-mailer.php';
            $email = strtolower(trim((string)($_POST['email'] ?? '')));
            $title = (string)($_POST['title'] ?? 'Mr.');
            $department = trim((string)($_POST['department'] ?? ''));
            $designation = trim((string)($_POST['designation'] ?? ''));
            $universityId=(int)($_POST['university_id']??0); $degreeId=(int)($_POST['degree_id']??0);
            $batchId=(int)($_POST['batch_id']??0); $studyYear=(int)($_POST['study_year']??0);
            $semester=(int)($_POST['semester']??0);
            if (!$universityId || !$degreeId || !$batchId || $studyYear<1 || $studyYear>6 || !in_array($semester,[1,2],true)) throw new RuntimeException('Select university, programme, intake, year and semester.');
            if (!$department || !$designation) throw new RuntimeException('Select a department and designation.');
            $scope=$pdo->prepare('SELECT COUNT(*) FROM academic_degrees d JOIN academic_degree_intakes adi ON adi.degree_id=d.id JOIN academic_batches b ON b.id=adi.batch_id WHERE d.id=? AND d.awarding_university_id=? AND b.id=? AND adi.is_active=1 AND d.is_active=1 AND b.is_active=1 AND d.duration_years>=?');
            $scope->execute([$degreeId,$universityId,$batchId,$studyYear]);
            if (!(int)$scope->fetchColumn()) throw new RuntimeException('This programme/intake/year combination is unavailable.');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid email.');
            if (!in_array($title, ['Mr.','Mrs.','Ms.','Dr.','Prof.'], true)) throw new RuntimeException('Invalid title.');
            if (mb_strlen($department)>150 || mb_strlen($designation)>150) throw new RuntimeException('Department/designation too long.');
            $exists=$pdo->prepare('SELECT id FROM users WHERE email=? LIMIT 1');$exists->execute([$email]);
            if ($exists->fetchColumn()) throw new RuntimeException('An account already uses this email. Invite a different email; existing accounts are never silently promoted.');
            $q=$pdo->prepare('SELECT sent_at FROM lecturer_invitations WHERE email=? LIMIT 1');$q->execute([$email]);$previous=$q->fetchColumn();
            if ($previous && strtotime((string)$previous)>time()-60) throw new RuntimeException('Wait 60 seconds before resending.');
            $code=(string)random_int(100000,999999);
            $hash=password_hash($code,PASSWORD_DEFAULT);
            $q=$pdo->prepare('INSERT INTO lecturer_invitations(email,code_hash,title,department,designation,invited_by,university_id,degree_id,batch_id,study_year,semester,expires_at,sent_at,attempts) VALUES (?,?,?,?,?,?,?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 24 HOUR),NOW(),0) ON DUPLICATE KEY UPDATE code_hash=VALUES(code_hash),title=VALUES(title),department=VALUES(department),designation=VALUES(designation),invited_by=VALUES(invited_by),university_id=VALUES(university_id),degree_id=VALUES(degree_id),batch_id=VALUES(batch_id),study_year=VALUES(study_year),semester=VALUES(semester),expires_at=VALUES(expires_at),sent_at=NOW(),attempts=0,used_at=NULL');
            $q->execute([$email,$hash,$title,$department,$designation,(int)$admin['id'],$universityId,$degreeId,$batchId,$studyYear,$semester]);
            try { sendUlearnLecturerInvitation($email, $code, ulearnLecturerActivationUrl()); }
            catch(Throwable $mailError) { error_log('Lecturer invitation delivery failed: '.$mailError->getMessage());throw new RuntimeException('Invitation could not be emailed. Check mail settings and try again.'); }
            $_SESSION['lecturer_success']='Invitation email requested for '.$email.'. It includes the activation code and setup link. Check inbox or spam.';
            lecturerRedirect();
        }

        if ($action === 'toggle') {
            $lecturerId = filter_var(
                $_POST['lecturer_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            if (!$lecturerId || $lecturerId < 1) {
                throw new RuntimeException('Invalid lecturer.');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                "SELECT lp.id, lp.is_active, u.full_name, u.role
                 FROM lecturer_profiles lp
                 JOIN users u ON u.id = lp.user_id
                 WHERE lp.id = ?
                 LIMIT 1
                 FOR UPDATE"
            );

            $stmt->execute([$lecturerId]);
            $lecturer = $stmt->fetch();

            if (!$lecturer || $lecturer['role'] !== 'lecturer') {
                throw new RuntimeException('Lecturer not found.');
            }

            $newStatus = (int)$lecturer['is_active'] === 1 ? 0 : 1;

            $stmt = $pdo->prepare(
                "UPDATE lecturer_profiles
                 SET is_active = ?
                 WHERE id = ?"
            );

            $stmt->execute([$newStatus, $lecturerId]);

            $pdo->commit();

            $_SESSION['lecturer_success'] =
                $lecturer['full_name'] .
                ($newStatus ? ' activated.' : ' suspended.');

            lecturerRedirect();
        }

        throw new RuntimeException('Unknown action.');

    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($exception instanceof RuntimeException) {
            $error = $exception->getMessage();
        } else {
            error_log('Lecturer management error: ' . $exception->getMessage());
            $error = 'Something went wrong. Please try again.';
        }
    }
}

$stmt = $pdo->query(
    "SELECT
        lp.id,
        lp.title,
        lp.department,
        lp.designation,
        lp.is_active,
        u.full_name,
        u.email,
        u.email_verified_at,
        (
            SELECT COUNT(*)
            FROM lecturer_module_assignments lma
            WHERE lma.lecturer_id = lp.id
              AND lma.is_active = 1
        ) AS module_count
     FROM lecturer_profiles lp
     JOIN users u ON u.id = lp.user_id
     WHERE u.role = 'lecturer'
     ORDER BY lp.is_active DESC, u.full_name ASC"
);

$lecturers = $stmt->fetchAll();
$activeCount = count(array_filter(
    $lecturers,
    fn($item) => (int)$item['is_active'] === 1
));

$universities=$pdo->query('SELECT id,name FROM awarding_universities ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$degrees=$pdo->query('SELECT id,name,awarding_university_id AS university_id,duration_years FROM academic_degrees WHERE is_active=1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$intakes=$pdo->query('SELECT adi.degree_id,adi.batch_id,b.batch_code FROM academic_degree_intakes adi JOIN academic_batches b ON b.id=adi.batch_id WHERE adi.is_active=1 AND b.is_active=1 ORDER BY b.batch_code DESC')->fetchAll(PDO::FETCH_ASSOC);
$departments=array_values(array_unique(array_merge(['Computing','Engineering','Business','Information Technology'],array_filter($pdo->query("SELECT DISTINCT department FROM lecturer_profiles WHERE department IS NOT NULL AND department<>''")->fetchAll(PDO::FETCH_COLUMN)))));
$designations=array_values(array_unique(array_merge(['Assistant Lecturer','Lecturer','Senior Lecturer','Visiting Lecturer','Professor'],array_filter($pdo->query("SELECT DISTINCT designation FROM lecturer_profiles WHERE designation IS NOT NULL AND designation<>''")->fetchAll(PDO::FETCH_COLUMN)))));
sort($departments);sort($designations);

$fullName = (string)($admin['full_name'] ?? 'Administrator');
$nameParts = preg_split('/\s+/', trim($fullName));
$firstName = $nameParts[0] ?: 'Administrator';
$initial = strtoupper(substr($firstName, 0, 1));
$roleLabel = $admin['role'] === 'main_admin' ? 'Main Administrator' : 'Administrator';
$theme = (string)($admin['theme'] ?? 'system');

$notificationStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0"
);
$notificationStmt->execute([(int)$admin['id']]);
$unreadNotifications = (int)$notificationStmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Manage Lecturers — U Learn</title>
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
    
    .grid {
        display: grid;
        grid-template-columns: minmax(320px, .9fr) minmax(0, 1.2fr);
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
    .form-top {
        display: flex;
        align-items: flex-start;
        gap: 15px;
        margin-bottom: 20px;
    }
    .form-icon {
        width: 40px;
        height: 40px;
        border-radius: 12px;
        background: var(--accent-light);
        color: var(--accent);
        display: grid;
        place-items: center;
        flex-shrink: 0;
    }
    .form-icon svg { width: 20px; height: 20px; }
    .card h2 {
        font-size: 18px;
        letter-spacing: -.5px;
        margin: 0 0 4px;
    }
    .hint {
        color: var(--muted);
        font-size: 13px;
        margin: 0;
    }
    .kicker {
        display: block;
        color: var(--accent);
        font-size: 11px;
        letter-spacing: 1.5px;
        font-weight: 750;
        text-transform: uppercase;
        margin-bottom: 4px;
    }
    .divider {
        height: 1px;
        background: var(--border);
        margin: 20px 0;
    }
    .field { margin-top: 16px; }
    label {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        color: var(--text);
        font-size: 12px;
        font-weight: 750;
        margin: 0 0 6px;
    }
    .optional {
        color: var(--muted);
        font-size: 10px;
        font-weight: 650;
        text-transform: uppercase;
    }
    .input-wrap { position: relative; }
    .input-wrap svg {
        position: absolute;
        left: 14px;
        top: 50%;
        width: 17px;
        height: 17px;
        transform: translateY(-50%);
        color: var(--muted);
        pointer-events: none;
    }
    input, select {
        display: block;
        width: 100%;
        min-height: 46px;
        background: var(--surface-alt);
        color: var(--text);
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 10px 14px;
        outline: none;
        transition: border-color .2s, box-shadow .2s;
    }
    .input-wrap input { padding-left: 40px; }
    input:focus, select:focus {
        border-color: var(--accent);
        box-shadow: 0 0 0 3px var(--accent-light);
        background: var(--surface);
    }
    select { padding-right: 36px; }
    .field-note {
        color: var(--muted);
        font-size: 11px;
        margin: 6px 0 0;
    }
    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px;
    }
    .primary {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        width: 100%;
        min-height: 48px;
        margin-top: 22px;
        border: 0;
        border-radius: 12px;
        background: var(--accent);
        color: #fff;
        font-size: 13px;
        font-weight: 750;
        cursor: pointer;
        transition: background .2s, transform .2s;
    }
    .primary:hover {
        filter: brightness(1.05);
        transform: translateY(-1px);
    }
    .primary svg { width: 16px; height: 16px; }
    .security {
        display: flex;
        align-items: flex-start;
        gap: 8px;
        color: var(--muted);
        font-size: 11px;
        line-height: 1.5;
        margin-top: 14px;
    }
    .security svg {
        flex-shrink: 0;
        width: 14px;
        height: 14px;
        margin-top: 2px;
        color: var(--accent);
    }
    .directory-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 15px;
        margin-bottom: 15px;
    }
    .count {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        white-space: nowrap;
        padding: 6px 10px;
        border-radius: 999px;
        background: var(--accent-light);
        color: var(--accent);
        font-size: 11px;
        font-weight: 800;
    }
    .summary {
        display: flex;
        gap: 10px;
        align-items: center;
        margin-bottom: 20px;
        padding: 12px 14px;
        border: 1px solid var(--border);
        background: var(--surface-alt);
        border-radius: 12px;
        color: var(--muted);
        font-size: 12px;
    }
    .summary svg {
        width: 16px;
        height: 16px;
        color: var(--accent);
        flex-shrink: 0;
    }
    .lecturer {
        padding: 16px 0;
        border-top: 1px solid var(--border);
    }
    .lecturer:first-of-type { border-top: none; }
    .lecturer-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
    }
    .lecturer-name {
        font-size: 14px;
        font-weight: 750;
    }
    .status {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 8px;
        border-radius: 999px;
        background: #e8fbf2;
        color: #176b49;
        font-size: 10px;
        font-weight: 800;
    }
    .status.off {
        background: #fff0f2;
        color: #a52b47;
    }
    .details {
        color: var(--muted);
        font-size: 12px;
        line-height: 1.6;
        margin: 6px 0 10px;
        overflow-wrap: anywhere;
    }
    .small-btn {
        padding: 6px 12px;
        border: 1px solid var(--border);
        border-radius: 8px;
        background: var(--surface);
        color: var(--text);
        font-size: 11px;
        font-weight: 700;
        cursor: pointer;
        transition: background .2s, border-color .2s;
    }
    .small-btn:hover {
        border-color: var(--accent);
        color: var(--accent);
    }
    .empty {
        padding: 36px 20px;
        text-align: center;
        border: 1px dashed var(--border);
        border-radius: 14px;
        background: var(--surface-alt);
    }
    .empty-icon {
        width: 48px;
        height: 48px;
        margin: 0 auto 12px;
        display: grid;
        place-items: center;
        border-radius: 14px;
        background: var(--accent-light);
        color: var(--accent);
    }
    .empty-icon svg { width: 24px; height: 24px; }
    .empty strong { display: block; font-size: 14px; }
    .empty p { max-width: 320px; margin: 6px auto 0; color: var(--muted); font-size: 12px; }

    .message {
        padding: 12px 16px;
        border-radius: 12px;
        margin-bottom: 24px;
        font-size: 13px;
        border: 1px solid transparent;
    }
    .success { background: #e8fbf2; color: #176b49; border-color: rgba(23,107,73,.15); }
    .error { background: #fff0f2; color: #a52b47; border-color: rgba(165,43,71,.15); }

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
        .grid { grid-template-columns: 1fr; }
        .topbar { flex-wrap: wrap; }
        .form-row { grid-template-columns: 1fr; }
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
                <a href="<?= e(url('admin/index.php')) ?>">
                    <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                    <span>Overview</span>
                </a>
                <a class="active" href="<?= e(url('admin/lecturers.php')) ?>">
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
            <small><?= e((string)$admin['email']) ?></small>
        </div>
    </aside>

    <main class="main" id="lecturers-main">
        <header class="topbar">
            <span class="eyebrow">
                U LEARN / LECTURERS SPACE
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
                <div style="font-size:12px; font-weight:750; text-transform:uppercase; letter-spacing:1.5px; opacity:0.8;">Administration / People</div>
                <h1>Manage lecturers</h1>
                <p>Invite lecturers, manage teaching profiles and control access.</p>
            </div>
            <span class="pill">
                <?= e($roleLabel) ?>
            </span>
        </header>

        <?php if ($success): ?>
            <div class="message success" role="status"><?= e($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="message error" role="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <div class="grid">
            <section class="card form-card">
                <div class="form-top">
                    <div class="form-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/>
                        </svg>
                    </div>
                    <div>
                        <span class="kicker">Lecturer access</span>
                        <h2>Invite a lecturer</h2>
                        <p class="hint">Invite new lecturers to create their teaching accounts.</p>
                    </div>
                </div>
                <div class="divider"></div>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                    <input type="hidden" name="action" value="invite">

                    <div class="field">
                        <label for="email">Lecturer email <span class="optional">Required</span></label>
                        <div class="input-wrap">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>
                            <input id="email" name="email" type="email" placeholder="name@university.edu" maxlength="254" autocomplete="email" required>
                        </div>
                        <p class="field-note">We will email a one-time activation code to this address.</p>
                    </div>

                    <div class="form-row">
                        <div class="field">
                            <label for="title">Professional title</label>
                            <select id="title" name="title"><option>Mr.</option><option>Mrs.</option><option>Ms.</option><option>Dr.</option><option>Prof.</option></select>
                        </div>
                        <div class="field">
                            <label for="university_id">University *</label>
                            <select id="university_id" name="university_id" required><option value="">Choose university</option><?php foreach($universities as $u): ?><option value="<?= (int)$u['id'] ?>"><?= e($u['name']) ?></option><?php endforeach; ?></select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="field">
                            <label for="department">Department *</label>
                            <select id="department" name="department" required><option value="">Choose department</option><?php foreach($departments as $d): ?><option value="<?= e($d) ?>"><?= e($d) ?></option><?php endforeach; ?></select>
                        </div>
                        <div class="field">
                            <label for="designation">Designation *</label>
                            <select id="designation" name="designation" required><option value="">Choose designation</option><?php foreach($designations as $d): ?><option value="<?= e($d) ?>"><?= e($d) ?></option><?php endforeach; ?></select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="field">
                            <label for="degree_id">Degree programme *</label>
                            <select id="degree_id" name="degree_id" required><option value="">Choose programme</option></select>
                        </div>
                        <div class="field">
                            <label for="batch_id">Intake *</label>
                            <select id="batch_id" name="batch_id" required><option value="">Choose intake</option></select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="field">
                            <label for="study_year">Academic year *</label>
                            <select id="study_year" name="study_year" required><option value="">Choose year</option></select>
                        </div>
                        <div class="field">
                            <label for="semester">Semester *</label>
                            <select id="semester" name="semester" required><option value="">Choose semester</option></select>
                        </div>
                    </div>

                    <p class="field-note">The first programme/intake/year/semester assignment activates after account setup. Assign modules later from Lecturer Assignments.</p>
                    
                    <script>
                    const degreeData=<?= json_encode($degrees,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
                    const intakeData=<?= json_encode($intakes,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
                    const fields=['university_id','degree_id','batch_id','study_year','semester'].map(id=>document.getElementById(id));
                    function fillSelect(el,options,label){
                      const previous=el.value;
                      el.replaceChildren(new Option(label,''));
                      options.forEach(([value,name])=>el.add(new Option(name,String(value))));
                      if([...el.options].some(o=>o.value===previous)) el.value=previous;
                    }
                    function updateScope(){
                      const [u,d,b,y,se]=fields;
                      const ds=degreeData.filter(x=>String(x.university_id)===u.value);
                      fillSelect(d,ds.map(x=>[x.id,x.name]),'Choose programme');
                      const bs=intakeData.filter(x=>String(x.degree_id)===d.value);
                      fillSelect(b,bs.map(x=>[x.batch_id,x.batch_code]),'Choose intake');
                      const chosen=ds.find(x=>String(x.id)===d.value);
                      const duration=chosen ? Math.min(6,Math.max(0,Number(chosen.duration_years)||0)) : 0;
                      const years=b.value ? Array.from({length:duration},(_,i)=>[i+1,'Year '+(i+1)]) : [];
                      fillSelect(y,years,'Choose year');
                      fillSelect(se,y.value ? [[1,'Semester 1'],[2,'Semester 2']] : [],'Choose semester');
                    }
                    fields.slice(0,4).forEach(el=>el.addEventListener('change',updateScope));
                    updateScope();
                    </script>

                    <button class="primary" type="submit">
                        Send lecturer invitation 
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                    </button>
                    
                    <div class="security">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 1 1 8 0v3M9 15l2 2 4-4"/></svg>
                        <span>Lecturers create their own accounts using the emailed activation code.</span>
                    </div>
                </form>
            </section>

            <section class="card directory">
                <p style="margin-bottom: 16px;"><a href="<?= e(url('admin/module-lecturers.php')) ?>" style="color:var(--accent);font-weight:750">Manage programme, intake and module assignments →</a></p>
                <div class="directory-head">
                    <div>
                        <span class="kicker">People &amp; permissions</span>
                        <h2>Lecturer directory</h2>
                        <p class="hint">Manage approved lecturers and their current access status.</p>
                    </div>
                    <span class="count"><?= $activeCount ?> active</span>
                </div>
                <div class="summary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                    <span>Changes to lecturer access are reflected here after the action is saved.</span>
                </div>

                <?php if (!$lecturers): ?>
                    <div class="empty">
                        <div class="empty-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M16 11h6"/></svg>
                        </div>
                        <strong>Your lecturer directory is ready</strong>
                        <p>No activated lecturers yet. Invite a lecturer to get started.</p>
                    </div>
                <?php endif; ?>

                <?php foreach ($lecturers as $lecturer): ?>
                    <article class="lecturer">
                        <div class="lecturer-head">
                            <div class="lecturer-name"><?= e($lecturer['title']) ?> <?= e($lecturer['full_name']) ?></div>
                            <span class="status <?= (int)$lecturer['is_active'] === 1 ? '' : 'off' ?>"><?= (int)$lecturer['is_active'] === 1 ? 'Active' : 'Suspended' ?></span>
                        </div>
                        <div class="details">
                            <?= e($lecturer['email']) ?><br>
                            <?= e($lecturer['department'] ?: 'No department') ?> · <?= e($lecturer['designation'] ?: 'Lecturer') ?><br>
                            <?= (int)$lecturer['module_count'] ?> teaching assignments
                        </div>
                        <form method="post" style="margin:0;">
                            <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="lecturer_id" value="<?= (int)$lecturer['id'] ?>">
                            <button class="small-btn" type="submit" onclick="return confirm('Change lecturer access for this account?')"><?= (int)$lecturer['is_active'] === 1 ? 'Suspend access' : 'Reactivate access' ?></button>
                        </form>
                    </article>
                <?php endforeach; ?>
            </section>
        </div>

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