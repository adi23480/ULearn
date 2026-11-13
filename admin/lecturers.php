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

// Personal unread notifications for the signed-in administrator.
$notificationStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0"
);
$notificationStmt->execute([(int)$admin['id']]);
$unreadNotifications = (int)$notificationStmt->fetchColumn();

$fullName = (string)($admin['full_name'] ?? 'Administrator');
$nameParts = preg_split('/\s+/', trim($fullName));
$firstName = $nameParts[0] ?: 'Administrator';
$initial = strtoupper(substr($firstName, 0, 1));
$roleLabel = $admin['role'] === 'main_admin' ? 'Main Administrator' : 'Administrator';
$theme = (string)($admin['theme'] ?? 'system');

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
            $universityId = (int)($_POST['university_id'] ?? 0);
            $degreeId = (int)($_POST['degree_id'] ?? 0);
            $batchId = (int)($_POST['batch_id'] ?? 0);
            $studyYear = (int)($_POST['study_year'] ?? 0);
            $semester = (int)($_POST['semester'] ?? 0);

            if (!$universityId || !$degreeId || !$batchId || $studyYear < 1 || $studyYear > 6 || !in_array($semester, [1, 2], true)) {
                throw new RuntimeException('Select university, programme, intake, year and semester.');
            }
            if (!$department || !$designation) {
                throw new RuntimeException('Select a department and designation.');
            }

            $scope = $pdo->prepare('SELECT COUNT(*) FROM academic_degrees d JOIN academic_degree_intakes adi ON adi.degree_id=d.id JOIN academic_batches b ON b.id=adi.batch_id WHERE d.id=? AND d.awarding_university_id=? AND b.id=? AND adi.is_active=1 AND d.is_active=1 AND b.is_active=1 AND d.duration_years>=?');
            $scope->execute([$degreeId, $universityId, $batchId, $studyYear]);

            if (!(int)$scope->fetchColumn()) {
                throw new RuntimeException('This programme/intake/year combination is unavailable.');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Enter a valid email.');
            }
            if (!in_array($title, ['Mr.', 'Mrs.', 'Ms.', 'Dr.', 'Prof.'], true)) {
                throw new RuntimeException('Invalid title.');
            }
            if (mb_strlen($department) > 150 || mb_strlen($designation) > 150) {
                throw new RuntimeException('Department/designation too long.');
            }

            $exists = $pdo->prepare('SELECT id FROM users WHERE email=? LIMIT 1');
            $exists->execute([$email]);
            if ($exists->fetchColumn()) {
                throw new RuntimeException('An account already uses this email. Invite a different email; existing accounts are never silently promoted.');
            }

            $q = $pdo->prepare('SELECT sent_at FROM lecturer_invitations WHERE email=? LIMIT 1');
            $q->execute([$email]);
            $previous = $q->fetchColumn();

            if ($previous && strtotime((string)$previous) > time() - 60) {
                throw new RuntimeException('Wait 60 seconds before resending.');
            }

            $code = (string)random_int(100000, 999999);
            $hash = password_hash($code, PASSWORD_DEFAULT);
            $q = $pdo->prepare('INSERT INTO lecturer_invitations(email,code_hash,title,department,designation,invited_by,university_id,degree_id,batch_id,study_year,semester,expires_at,sent_at,attempts) VALUES (?,?,?,?,?,?,?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 24 HOUR),NOW(),0) ON DUPLICATE KEY UPDATE code_hash=VALUES(code_hash),title=VALUES(title),department=VALUES(department),designation=VALUES(designation),invited_by=VALUES(invited_by),university_id=VALUES(university_id),degree_id=VALUES(degree_id),batch_id=VALUES(batch_id),study_year=VALUES(study_year),semester=VALUES(semester),expires_at=VALUES(expires_at),sent_at=NOW(),attempts=0,used_at=NULL');
            $q->execute([$email, $hash, $title, $department, $designation, (int)$admin['id'], $universityId, $degreeId, $batchId, $studyYear, $semester]);

            try {
                sendUlearnLecturerInvitation($email, $code, ulearnLecturerActivationUrl());
            } catch (Throwable $mailError) {
                error_log('Lecturer invitation delivery failed: ' . $mailError->getMessage());
                throw new RuntimeException('Invitation could not be emailed. Check mail settings and try again.');
            }

            $_SESSION['lecturer_success'] = 'Invitation email requested for ' . $email . '. It includes the activation code and setup link. Check inbox or spam.';
            lecturerRedirect();
        }

        if ($action === 'toggle') {
            $lecturerId = filter_var($_POST['lecturer_id'] ?? null, FILTER_VALIDATE_INT);
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

            $stmt = $pdo->prepare("UPDATE lecturer_profiles SET is_active = ? WHERE id = ?");
            $stmt->execute([$newStatus, $lecturerId]);
            $pdo->commit();

            $_SESSION['lecturer_success'] = $lecturer['full_name'] . ($newStatus ? ' activated.' : ' suspended.');
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
$activeCount = count(array_filter($lecturers, fn($item) => (int)$item['is_active'] === 1));

$universities = $pdo->query('SELECT id,name FROM awarding_universities ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$degrees = $pdo->query('SELECT id,name,awarding_university_id AS university_id,duration_years FROM academic_degrees WHERE is_active=1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$intakes = $pdo->query('SELECT adi.degree_id,adi.batch_id,b.batch_code FROM academic_degree_intakes adi JOIN academic_batches b ON b.id=adi.batch_id WHERE adi.is_active=1 AND b.is_active=1 ORDER BY b.batch_code DESC')->fetchAll(PDO::FETCH_ASSOC);

$departments = array_values(array_unique(array_merge(['Computing', 'Engineering', 'Business', 'Information Technology'], array_filter($pdo->query("SELECT DISTINCT department FROM lecturer_profiles WHERE department IS NOT NULL AND department<>''")->fetchAll(PDO::FETCH_COLUMN)))));
$designations = array_values(array_unique(array_merge(['Assistant Lecturer', 'Lecturer', 'Senior Lecturer', 'Visiting Lecturer', 'Professor'], array_filter($pdo->query("SELECT DISTINCT designation FROM lecturer_profiles WHERE designation IS NOT NULL AND designation<>''")->fetchAll(PDO::FETCH_COLUMN)))));
sort($departments);
sort($designations);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Manage Lecturers — ULearn</title>
<script>
(function () {
    const preference = <?= json_encode($theme, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    let theme = preference;
    if (theme === 'system') {
        theme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }
    document.documentElement.dataset.theme = theme;
    localStorage.setItem('ulearn-theme', theme);
})();
</script>
<style>
:root {
    color-scheme: light;
    /* Navigation & Global */
    --bg: #f7f8fc;
    --surface: #ffffff;
    --surface-alt: #f1f2f8;
    --text: #171927;
    --muted: #777b90;
    --border: #e7e8f0;
    --accent: #6756e8;
    --accent-light: #f0edff;
    --shadow: 0 10px 40px rgba(30,30,60,.04);
    
    /* Lecturer Card Components */
    --card: rgba(255,255,255,.88);
    --solid: #fff;
    --line: rgba(111,116,150,.18);
    --accent2: #5d43e6;
    --soft: #f0edff;
    --input: #f8f8fd;
    --okbg: #e8fbf2;
    --ok: #176b49;
    --badbg: #fff0f2;
    --bad: #a52b47;
}
:root[data-theme="dark"] {
    color-scheme: dark;
    /* Navigation & Global */
    --bg: #0c0e16;
    --surface: #161924;
    --surface-alt: #202431;
    --text: #f4f4fa;
    --muted: #a0a4b6;
    --border: #2b2e3c;
    --accent: #a99aff;
    --accent-light: #292440;
    --shadow: none;
    
    /* Lecturer Card Components */
    --card: rgba(24,26,39,.9);
    --solid: #181a27;
    --line: rgba(169,171,207,.15);
    --accent2: #8d75ff;
    --soft: #29233f;
    --input: #202231;
    --okbg: #142b25;
    --ok: #9be8c4;
    --badbg: #321d28;
    --bad: #ffb0c0;
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
button { font: inherit; cursor: pointer; }

/* === Layout & Navigation === */
.layout { display: grid; grid-template-columns: 250px minmax(0,1fr); min-height: 100vh; }
.sidebar { background: var(--surface); border-right: 1px solid var(--border); padding: 32px 20px; display: flex; flex-direction: column; gap: 40px; }
.brand { font-size: 26px; font-weight: 800; letter-spacing: -1.5px; padding-left: 12px; }
.brand span { color: var(--accent); }
.nav-label, .eyebrow { font-size: 11px; font-weight: 750; letter-spacing: 1.7px; text-transform: uppercase; color: var(--muted); }
.nav-label { margin: 0 12px 14px; }
.nav { display: grid; gap: 6px; }
.nav a { display: flex; align-items: center; gap: 13px; padding: 12px 14px; border-radius: 12px; font-size: 14px; font-weight: 600; transition: background .2s, color .2s; }
.nav a:hover { background: var(--surface-alt); }
.nav a.active { background: var(--accent-light); color: var(--accent); }
.nav a.disabled { color: var(--muted); opacity: .48; cursor: default; }
.nav-icon { width: 18px; height: 18px; flex-shrink: 0; }
.sidebar-bottom { margin-top: auto; border: 1px solid var(--border); border-radius: 16px; padding: 16px; font-size: 13px; }
.sidebar-bottom strong { display: block; overflow-wrap: anywhere; }
.sidebar-bottom small { color: var(--muted); overflow-wrap: anywhere; }

.main { min-width: 0; padding: 34px clamp(20px,5vw,76px) 80px; }
.topbar { display: flex; align-items: center; justify-content: space-between; gap: 20px; margin-bottom: 56px; }
.topbar-right { display: flex; align-items: center; gap: 18px; }
.avatar { text-decoration: none; transition: transform .18s ease; width: 40px; height: 40px; border-radius: 50%; background: var(--accent-light); color: var(--accent); display: grid; place-items: center; font-weight: 800; }
.avatar:hover { transform: translateY(-2px); }
.avatar:focus-visible { outline: 3px solid var(--accent); outline-offset: 3px; }
.logout { border: 0; background: transparent; color: var(--muted); font-size: 13px; font-weight: 650; }
.logout:hover { color: var(--text); }
.theme-toggle { border: 1px solid var(--border); background: var(--surface); color: var(--text); border-radius: 12px; width: 42px; height: 42px; display: grid; place-items: center; font-size: 20px; transition: background .18s; }
.theme-toggle:hover { background: var(--surface-alt); }
.notification-bell { position:relative; display:grid; place-items:center; width:42px; height:42px; border:1px solid var(--border); border-radius:12px; background:var(--surface); color:var(--text); transition:transform .18s ease, background .18s ease, border-color .18s ease; }
.notification-bell:hover { background:var(--accent-light); border-color:var(--accent); transform:translateY(-2px); }
.notification-bell:focus-visible { outline:3px solid var(--accent); outline-offset:3px; }
.notification-bell svg { width:21px; height:21px; fill:none; stroke:currentColor; stroke-width:1.8; stroke-linecap:round; stroke-linejoin:round; }
.notification-count { position:absolute; top:-7px; right:-7px; min-width:19px; height:19px; padding:0 4px; display:grid; place-items:center; border-radius:20px; background:#e34b76; color:white; font-size:10px; font-weight:800; border:2px solid var(--surface); }

.stage9-management { margin:12px 0 22px; padding:12px; border:1px solid var(--border, #35364c); border-radius:14px; }
.stage9-management summary { cursor:pointer; font-weight:750; padding:6px; color:var(--text,#eee); }
.stage9-management a { display:block; padding:11px 9px; margin-top:4px; border-radius:9px; color:var(--muted,#aaa); font-size:13px; }
.stage9-management a:hover { background:var(--surface-alt,#29283c); color:var(--accent,#a99cff); }

/* === Lecturers Specific Styles === */
.intro { margin: 0 0 34px; max-width: 820px; animation: rise .55s ease both; }
.intro .eyebrow { display: flex; align-items: center; gap: 10px; }
.intro .eyebrow:before { content: ""; width: 25px; height: 2px; background: var(--accent); border-radius: 9px; }
h1 { font-size: clamp(40px,6.3vw,70px); letter-spacing: -.065em; line-height: 1.02; margin: 18px 0 17px; font-weight: 820; }
h1 span { color: var(--accent); }
.subtitle { color: var(--muted); max-width: 660px; font-size: clamp(15px,1.7vw,18px); margin: 0; line-height: 1.8; }

.grid { display: grid; grid-template-columns: minmax(310px,.82fr) minmax(0,1.35fr); gap: 24px; align-items: start; }
.card { position: relative; min-width: 0; padding: clamp(22px,3vw,32px); background: var(--card); border: 1px solid var(--line); border-radius: 26px; box-shadow: var(--shadow); backdrop-filter: blur(18px); overflow: hidden; animation: rise .65s ease both; }
.card:before { content: ""; position: absolute; inset: 0 0 auto; height: 1px; background: linear-gradient(90deg,transparent,rgba(170,152,255,.7),transparent); pointer-events: none; }
.directory { animation-delay: .1s; }
.form-top { display: flex; align-items: flex-start; gap: 15px; margin-bottom: 25px; }
.form-icon { width: 52px; height: 52px; flex: 0 0 52px; display: grid; place-items: center; border-radius: 17px; color: white; background: linear-gradient(145deg,#8c78ff,#6245e8); box-shadow: 0 12px 26px rgba(113,87,242,.28); }
.form-icon svg { width: 24px; height: 24px; }
.card h2 { font-size: 21px; margin: 0 0 7px; letter-spacing: -.7px; font-weight: 800; }
.hint { color: var(--muted); font-size: 13px; margin: 0; line-height: 1.75; }
.kicker { display: block; color: var(--accent); font-size: 10px; letter-spacing: 1.7px; font-weight: 850; text-transform: uppercase; margin-bottom: 4px; }
.divider { height: 1px; background: var(--line); margin: 22px 0 20px; }
.field { margin-top: 18px; }
label { display: flex; align-items: center; justify-content: space-between; gap: 8px; color: var(--text); font-size: 12px; font-weight: 750; margin: 0 0 8px; }
.optional { color: var(--muted); font-size: 10px; font-weight: 650; letter-spacing: .4px; text-transform: uppercase; }
.input-wrap { position: relative; }
.input-wrap svg { position: absolute; left: 15px; top: 50%; width: 17px; height: 17px; transform: translateY(-50%); color: var(--muted); pointer-events: none; }
input, select { display: block; width: 100%; min-height: 52px; background: var(--input); color: var(--text); border: 1px solid var(--line); border-radius: 14px; padding: 13px 15px; outline: none; transition: border-color .2s, box-shadow .2s, background .2s; }
.input-wrap input { padding-left: 44px; }
input::placeholder { color: #85899d; opacity: .85; }
input:focus, select:focus { border-color: var(--accent); box-shadow: 0 0 0 4px rgba(113,87,242,.15); background: var(--solid); }
select { padding-right: 42px; }
.field-note { color: var(--muted); font-size: 11px; margin: 7px 0 0; }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.primary { display: flex; align-items: center; justify-content: center; gap: 11px; width: 100%; min-height: 54px; margin-top: 26px; padding: 14px 18px; border: 1px solid rgba(255,255,255,.14); border-radius: 15px; background: linear-gradient(110deg,var(--accent2),var(--accent)); color: #fff; font-size: 13px; font-weight: 800; box-shadow: 0 13px 28px rgba(105,78,232,.23); transition: transform .2s, box-shadow .2s, filter .2s; }
.primary:hover { transform: translateY(-2px); box-shadow: 0 17px 32px rgba(105,78,232,.32); filter: saturate(1.08); }
.primary svg { width: 17px; height: 17px; transition: transform .2s; }
.primary:hover svg { transform: translateX(3px); }
.security { display: flex; align-items: flex-start; gap: 9px; color: var(--muted); font-size: 11px; line-height: 1.6; margin-top: 16px; }
.security svg { flex: 0 0 15px; width: 15px; height: 15px; margin-top: 2px; color: var(--accent); }
.directory-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 18px; margin-bottom: 9px; }
.count { display: inline-flex; align-items: center; gap: 8px; white-space: nowrap; padding: 8px 12px; border: 1px solid rgba(113,87,242,.2); border-radius: 999px; background: var(--soft); color: var(--accent); font-size: 11px; font-weight: 800; }
.count:before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: currentColor; box-shadow: 0 0 0 4px rgba(113,87,242,.12); }
.summary { display: flex; gap: 10px; align-items: center; margin: 20px 0 23px; padding: 13px 15px; border: 1px solid var(--line); background: var(--input); border-radius: 15px; color: var(--muted); font-size: 12px; }
.summary svg { width: 17px; height: 17px; color: var(--accent); flex: 0 0 17px; }
.lecturer { padding: 20px 0; border-top: 1px solid var(--line); }
.lecturer:first-of-type { border-top: none; }
.lecturer-name { font-size: 15px; font-weight: 800; letter-spacing: -.2px; }
.status { display: inline-flex; align-items: center; gap: 7px; padding: 6px 10px; border-radius: 999px; background: var(--okbg); color: var(--ok); font-size: 10px; font-weight: 850; }
.status:before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
.status.off { background: var(--badbg); color: var(--bad); }
.details { color: var(--muted); font-size: 12px; line-height: 1.9; margin: 10px 0 14px; overflow-wrap: anywhere; }
.small-btn { padding: 9px 13px; border: 1px solid var(--line); border-radius: 11px; background: var(--input); color: var(--text); font-size: 11px; font-weight: 750; transition: .2s; }
.small-btn:hover { border-color: var(--accent); color: var(--accent); }
.empty { padding: 42px 20px; text-align: center; border: 1px dashed var(--line); border-radius: 18px; background: rgba(127,127,127,.025); }
.empty-icon { width: 56px; height: 56px; margin: 0 auto 15px; display: grid; place-items: center; border-radius: 18px; background: var(--soft); color: var(--accent); }
.empty-icon svg { width: 27px; height: 27px; }
.empty strong { display: block; font-size: 14px; }
.empty p { max-width: 340px; margin: 7px auto 0; color: var(--muted); font-size: 12px; line-height: 1.8; }
.message { padding: 14px 17px; border-radius: 14px; margin: 0 0 22px; font-size: 13px; border: 1px solid transparent; animation: rise .3s ease both; }
.success { background: var(--okbg); color: var(--ok); border-color: rgba(23,107,73,.15); }
.error { background: var(--badbg); color: var(--bad); border-color: rgba(165,43,71,.15); }

@keyframes rise { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
@media (prefers-reduced-motion: reduce) { *, *:before, *:after { animation-duration: .01ms !important; transition-duration: .01ms !important; scroll-behavior: auto !important; } }

@media (max-width: 1000px) {
    .layout { grid-template-columns: 1fr; }
    .sidebar { border-right: 0; border-bottom: 1px solid var(--border); padding: 18px; gap: 18px; }
    .nav { display: flex; flex-wrap: wrap; }
    .sidebar-bottom { display: none; }
    .main { padding-top: 25px; }
    .topbar { margin-bottom: 35px; }
}
@media (max-width: 900px) { .grid { grid-template-columns: 1fr; } .directory { order: 2; } .form-card { order: 1; } }
@media (max-width: 600px) { .main { padding: 24px 18px 60px; } .topbar { flex-wrap: wrap; } .form-row { grid-template-columns: 1fr; gap: 0; } }
</style>

<script src="<?= htmlspecialchars(url('assets/js/theme-global.js?v=20261107-2'), ENT_QUOTES, 'UTF-8') ?>"></script>
<link rel="stylesheet" href="<?= e(url('assets/css/admin-unified-v1.css?v=1')) ?>">
</head>

<body>
<!-- SVG icon library from index -->
<svg xmlns="http://www.w3.org/2000/svg" style="position:absolute;width:0;height:0;overflow:hidden" aria-hidden="true">
    <symbol id="i-grid" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></symbol>
    <symbol id="i-users" viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"/><path d="M3 20v-2a6 6 0 0 1 12 0v2"/><path d="M17 5a3 3 0 0 1 0 6M18 15a5 5 0 0 1 3 5"/></symbol>
    <symbol id="i-book" viewBox="0 0 24 24"><path d="M4 4h13a3 3 0 0 1 3 3v13H7a3 3 0 0 1-3-3z"/><path d="M4 17a3 3 0 0 1 3-3h13M8 8h8"/></symbol>
    <symbol id="i-calendar" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4M17 3v4M3 10h18"/></symbol>
    <symbol id="i-cap" viewBox="0 0 24 24"><path d="m2 9 10-5 10 5-10 5z"/><path d="M6 12v5c4 3 8 3 12 0v-5M22 9v7"/></symbol>
    <symbol id="i-link" viewBox="0 0 24 24"><path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-2 2"/><path d="M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l2-2"/></symbol>
    <symbol id="i-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></symbol>
    <symbol id="i-settings" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M19 5l-2 2M7 17l-2 2"/></symbol>
</svg>

<div class="layout">
    <aside class="sidebar">
        <?php require __DIR__ . '/../includes/brand-logo.php'; ?>
        <nav aria-label="Admin navigation">
            <details open class="stage9-management"><summary>⚙ System Management</summary><a href="<?= e(url('admin/users.php')) ?>">◉ All Registered Users</a><a href="<?= e(url('admin/change-requests.php')) ?>">◇ Academic Change Requests</a></details>

            <p class="nav-label">Your workspace</p>
            <div class="nav">
                <a href="<?= e(url('admin/index.php')) ?>">
                    <svg class="nav-icon" fill="none" stroke="currentColor" stroke-width="1.8"><use href="#i-grid"/></svg>
                    Overview
                </a>
                <a class="active" href="<?= e(url('admin/lecturers.php')) ?>">
                    <svg class="nav-icon" fill="none" stroke="currentColor" stroke-width="1.8"><use href="#i-users"/></svg>
                    Lecturers
                </a>
                <a href="<?= e(url('admin/academics.php')) ?>">
                    <svg class="nav-icon" fill="none" stroke="currentColor" stroke-width="1.8"><use href="#i-book"/></svg>
                    Academics
                </a>
               <a href="<?= e(url('admin/universities.php')) ?>">
                     <svg class="nav-icon" fill="none" stroke="currentColor" stroke-width="1.8"><use href="#i-cap"/></svg>
                   Universities
                </a>
                <a href="<?= e(url('admin/quizzes.php')) ?>">
                    <svg class="nav-icon" fill="none" stroke="currentColor" stroke-width="1.8"><use href="#i-book"/></svg>
                    Quizzes
                </a>
                <a href="<?= e(url('admin/assignments.php')) ?>">
                    <svg class="nav-icon" fill="none" stroke="currentColor" stroke-width="1.8"><use href="#i-link"/></svg>
                    Assignments
                </a>
                <a href="<?= e(url('admin/announcements.php')) ?>">
                    <svg class="nav-icon" fill="none" stroke="currentColor" stroke-width="1.8"><use href="#i-book"/></svg>
                    Announcements
                </a>
                <a href="<?= e(url('admin/platform-settings.php')) ?>">
                    <svg class="nav-icon" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><use href="#i-settings"/></svg>
                    Platform Settings
                </a>
                <a href="<?= e(url('admin/profile.php')) ?>">
                    <svg class="nav-icon" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><use href="#i-settings"/></svg>
                    My Profile &amp; Settings
                </a>
                <a href="<?= e(url('dashboard/notifications.php')) ?>">
                    <svg class="nav-icon" fill="none" stroke="currentColor" stroke-width="1.8"><use href="#i-clock"/></svg>
                    Notifications<?php if ($unreadNotifications > 0): ?> (<?= $unreadNotifications ?>)<?php endif; ?>
                </a>
                <a href="<?= e(url('admin/consultations.php')) ?>">
                    <svg class="nav-icon" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><use href="#i-calendar"/></svg> Consultations
                </a>
                <a href="<?= e(url('admin/kuppiya.php')) ?>">
                    <svg class="nav-icon" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><use href="#i-users"/></svg> Kuppiya Sessions
                </a>
            </div>
        </nav>
        <div class="sidebar-bottom">
            <small>Signed in as</small>
            <strong><?= e($fullName) ?></strong>
            <small><?= e((string)$admin['email']) ?></small>
        </div>
    </aside>

    <main class="main" id="lecturers">
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
                <button type="button" class="theme-toggle" id="themeToggle" aria-label="Switch appearance" title="Switch appearance">◐</button>
                <a class="avatar" href="<?= e(url('admin/profile.php')) ?>" aria-label="Open My Profile and Settings" title="My Profile & Settings">
                    <?= e($initial) ?>
                </a>
                <form method="post" action="<?= e(url('auth/logout.php')) ?>">
                    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                    <button type="submit" class="logout">
                        Log out ↗
                    </button>
                </form>
            </div>
        </header>

        <section class="intro">
            <div class="eyebrow">Administration / People</div>
            <h1>Manage lecturers<span>.</span></h1>
            <p class="subtitle">Invite lecturers, manage teaching profiles and control access.</p>
        </section>

        <?php if ($success): ?><div class="message success" role="status"><?= e($success) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="message error" role="alert"><?= e($error) ?></div><?php endif; ?>

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
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>
                            </svg>
                            <input id="email" name="email" type="email" placeholder="name@university.edu" maxlength="254" autocomplete="email" required>
                        </div>
                        <p class="field-note">We will email a one-time activation code to this address.</p>
                    </div>
                    
                    <div class="form-row">
                        <div class="field">
                            <label for="title">Professional title</label>
                            <select id="title" name="title">
                                <option>Mr.</option><option>Mrs.</option><option>Ms.</option><option>Dr.</option><option>Prof.</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="university_id">University *</label>
                            <select id="university_id" name="university_id" required>
                                <option value="">Choose university</option>
                                <?php foreach($universities as $u): ?><option value="<?= (int)$u['id'] ?>"><?= e($u['name']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="field">
                            <label for="department">Department *</label>
                            <select id="department" name="department" required>
                                <option value="">Choose department</option>
                                <?php foreach($departments as $d): ?><option value="<?= e($d) ?>"><?= e($d) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="designation">Designation *</label>
                            <select id="designation" name="designation" required>
                                <option value="">Choose designation</option>
                                <?php foreach($designations as $d): ?><option value="<?= e($d) ?>"><?= e($d) ?></option><?php endforeach; ?>
                            </select>
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
                    
                    <button class="primary" type="submit">Send lecturer invitation <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M12 5l7 7-7 7"/></svg></button>
                    <div class="security">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 1 1 8 0v3M9 15l2 2 4-4"/></svg>
                        <span>Lecturers create their own accounts using the emailed activation code.</span>
                    </div>
                </form>
            </section>
            
            <section class="card directory">
                <p><a href="<?= e(url('admin/module-lecturers.php')) ?>" style="color:var(--accent);font-weight:750">Manage programme, intake and module assignments →</a></p>
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
                    <form method="post">
                        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="lecturer_id" value="<?= (int)$lecturer['id'] ?>">
                        <button class="small-btn" type="submit" onclick="return confirm('Change lecturer access for this account?')"><?= (int)$lecturer['is_active'] === 1 ? 'Suspend access' : 'Reactivate access' ?></button>
                    </form>
                </article>
                <?php endforeach; ?>
            </section>
        </div>
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

