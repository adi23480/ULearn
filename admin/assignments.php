<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$user = require_login();
if (!in_array((string)($user['role'] ?? ''), ['admin', 'main_admin'], true)) {
    http_response_code(403);
    exit('Access denied. Admin permission required.');
}

$pdo = db();
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
$degreeAssignments = $pdo->query('SELECT lda.id, lda.is_active, lda.study_year, lda.semester, lda.degree_id, lda.batch_id, lp.title, u.full_name, d.name AS degree_name, b.batch_code FROM lecturer_degree_assignments lda JOIN lecturer_profiles lp ON lp.id = lda.lecturer_id JOIN users u ON u.id = lp.user_id JOIN academic_degrees d ON d.id = lda.degree_id JOIN academic_batches b ON b.id = lda.batch_id ORDER BY lda.is_active DESC, u.full_name, d.name, b.batch_code')->fetchAll(PDO::FETCH_ASSOC);
$degreeIntakeLinks = $pdo->query('SELECT degree_id, batch_id FROM academic_degree_intakes')->fetchAll(PDO::FETCH_ASSOC);
$activeCount = 0;
foreach ($assignments as $assignment) {
    if ((int)$assignment['is_active'] === 1) {
        $activeCount++;
    }
}
$fullName = (string)($user['full_name'] ?? 'Administrator');
$firstName = explode(' ', trim($fullName))[0] ?: 'Administrator';
$initial = strtoupper(substr($firstName, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Lecturer Assignments — U Learn</title>
<script>
(function(){const saved=localStorage.getItem('ulearn-theme');document.documentElement.dataset.theme=saved==='dark'||saved==='light'?saved:(window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light');})();
</script>
<style>
:root{color-scheme:light;--bg:#f7f8fc;--surface:#fff;--surface-alt:#f1f2f8;--text:#171927;--muted:#777b90;--border:#e7e8f0;--accent:#6756e8;--accent-light:#f0edff}
:root[data-theme="dark"]{color-scheme:dark;--bg:#0c0e16;--surface:#161924;--surface-alt:#202431;--text:#f4f4fa;--muted:#a0a4b6;--border:#2b2e3c;--accent:#a99aff;--accent-light:#292440}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,"Segoe UI",Arial,sans-serif;line-height:1.6}a{color:inherit;text-decoration:none}button,select{font:inherit}.layout{display:grid;grid-template-columns:250px minmax(0,1fr);min-height:100vh}.sidebar{background:var(--surface);border-right:1px solid var(--border);padding:32px 20px;display:flex;flex-direction:column;gap:40px}.brand{font-size:26px;font-weight:800;letter-spacing:-1.5px;padding-left:12px}.brand span{color:var(--accent)}.nav-label,.eyebrow{color:var(--muted);font-size:11px;font-weight:750;letter-spacing:1.7px;text-transform:uppercase}.nav-label{margin:0 12px 14px}.nav{display:grid;gap:6px}.nav a{display:block;padding:12px 14px;border-radius:12px;font-size:14px;font-weight:600}.nav a:hover{background:var(--surface-alt)}.nav a.active{background:var(--accent-light);color:var(--accent)}.sidebar-bottom{margin-top:auto;border:1px solid var(--border);border-radius:16px;padding:16px;font-size:13px;overflow-wrap:anywhere}.sidebar-bottom strong{display:block}.sidebar-bottom small{color:var(--muted)}.main{min-width:0;padding:34px clamp(20px,5vw,76px) 80px}.topbar{display:flex;align-items:center;justify-content:space-between;gap:20px;margin-bottom:55px}.topbar-right{display:flex;align-items:center;gap:16px}.avatar{width:40px;height:40px;border-radius:50%;background:var(--accent-light);color:var(--accent);display:grid;place-items:center;font-weight:800}.logout{background:transparent;border:0;color:var(--muted);cursor:pointer;font-size:13px;font-weight:650}h1,h2,h3,p{margin-top:0}h1{font-size:clamp(36px,4vw,56px);line-height:1.15;letter-spacing:-2px;margin:15px 0}h1 span{color:var(--accent)}.lead{color:var(--muted);font-size:15px;max-width:680px}.section-heading{display:flex;align-items:baseline;justify-content:space-between;gap:15px;margin:42px 0 20px}.section-heading h2{margin:0;font-size:23px;letter-spacing:-.7px}.section-heading small{color:var(--muted)}.panel{background:var(--surface);border:1px solid var(--border);border-radius:20px;padding:28px}.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}.field{margin-bottom:18px}label{display:block;font-size:13px;font-weight:700;margin-bottom:9px}select{width:100%;padding:13px 14px;border:1px solid var(--border);border-radius:11px;background:var(--surface-alt);color:var(--text);outline:none}select:focus{border-color:var(--accent)}.button{background:var(--text);color:var(--bg);border:0;border-radius:11px;padding:13px 20px;font-size:13px;font-weight:700;cursor:pointer}.button:disabled{opacity:.45;cursor:not-allowed}.button:hover:not(:disabled){opacity:.86}.message{padding:14px 17px;border-radius:12px;margin:22px 0;font-size:14px}.success{background:#e9f8ef;color:#176b43}.error{background:#ffedf0;color:#a32143}.empty{color:var(--muted);font-size:13px;padding:14px 0}.hint{color:var(--muted);font-size:13px}.records{display:grid;gap:12px}.record{display:flex;align-items:center;justify-content:space-between;gap:20px;padding:18px;border:1px solid var(--border);border-radius:14px}.record strong{display:block;font-size:14px}.record small{display:block;color:var(--muted);font-size:12px;margin-top:4px}.record-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:flex-end}.pill{display:inline-block;border-radius:100px;padding:6px 11px;background:var(--accent-light);color:var(--accent);font-size:11px;font-weight:750;white-space:nowrap}.pill.off{background:var(--surface-alt);color:var(--muted)}.action{border:1px solid var(--border);background:var(--surface-alt);color:var(--text);padding:8px 12px;border-radius:9px;cursor:pointer;font-size:12px;font-weight:650}.action.danger{color:#b43d50}.action:hover{border-color:var(--accent)}.footer{margin-top:48px;padding-top:20px;border-top:1px solid var(--border);color:var(--muted);font-size:12px}@media(max-width:900px){.layout{grid-template-columns:1fr}.sidebar{border-right:0;border-bottom:1px solid var(--border);padding:18px;gap:18px}.nav{display:flex;flex-wrap:wrap}.sidebar-bottom{display:none}.main{padding-top:25px}.topbar{margin-bottom:35px}}@media(max-width:650px){.main{padding:24px 18px 60px}.form-grid{grid-template-columns:1fr;gap:0}.panel{padding:20px}.record{align-items:flex-start;flex-direction:column}.record-actions{justify-content:flex-start}.topbar{flex-wrap:wrap}h1{letter-spacing:-1.5px}}

.catalog-modules{border:1px solid var(--border);border-radius:12px;padding:16px;margin:16px 0}.catalog-modules summary{cursor:pointer;font-weight:750}.catalog-modules-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px;margin-top:14px}.catalog-module{border:1px solid var(--border);border-radius:10px;padding:12px}.catalog-module strong{display:block;font-size:13px}.catalog-module small{color:var(--muted);display:block;font-size:12px}.catalog-module .status{font-size:11px;color:var(--accent);font-weight:700}@media(max-width:650px){.catalog-modules-list{grid-template-columns:1fr}}.filters{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin:18px 0}.filters label{font-size:12px}.search{width:100%;padding:13px;border:1px solid var(--border);border-radius:11px;background:var(--surface-alt);color:var(--text)}.module-list{max-height:420px;overflow:auto;border:1px solid var(--border);border-radius:12px;margin:14px 0}.module-row{display:flex;align-items:center;gap:12px;padding:13px 15px;border-bottom:1px solid var(--border);cursor:pointer}.module-row:last-child{border-bottom:0}.module-row:hover{background:var(--surface-alt)}.module-row input{width:18px;height:18px;accent-color:var(--accent)}.module-row span{flex:1}.module-row small{display:block;color:var(--muted)}.tools{display:flex;gap:12px;align-items:center;flex-wrap:wrap}.tools button{cursor:pointer;border:1px solid var(--border);background:var(--surface-alt);color:var(--text);border-radius:9px;padding:9px 12px}.catalog{display:flex;gap:12px;flex-wrap:wrap;margin:16px 0}.catalog span{background:var(--surface-alt);border-radius:9px;padding:8px 12px;font-size:13px}.manage-links{display:flex;gap:14px;flex-wrap:wrap;margin:16px 0}.manage-links a{color:var(--accent);font-weight:700;font-size:13px}@media(max-width:1100px){.filters{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:650px){.filters{grid-template-columns:1fr 1fr}}
</style>

<script src="<?= htmlspecialchars(url('assets/js/theme-global.js?v=20261107-2'), ENT_QUOTES, 'UTF-8') ?>"></script>
<link rel="stylesheet" href="<?= e(url('assets/css/admin-unified-v1.css?v=1')) ?>">
</head>
<body>
<div class="layout">
<aside class="sidebar">
<a class="brand" href="<?= e(url('admin/index.php')) ?>">u learn<span>.</span></a>
<nav aria-label="Admin navigation"><p class="nav-label">Your workspace</p><div class="nav">
<a href="<?= e(url('admin/index.php')) ?>">◫ &nbsp; Overview</a>
<a href="<?= e(url('admin/lecturers.php')) ?>">♙ &nbsp; Lecturers</a>
<a href="<?= e(url('admin/academics.php')) ?>">▤ &nbsp; Academics</a>
<a class="active" href="<?= e(url('admin/assignments.php')) ?>" aria-current="page">↔ &nbsp; Degree Assignments</a>
<a href="<?= e(url('admin/module-lecturers.php')) ?>">▤ &nbsp; Module Lecturers</a>
<a href="<?= e(url('admin/quizzes.php')) ?>">▣ &nbsp; Quiz Management</a>
</div></nav>
<div class="sidebar-bottom"><small>Signed in as</small><strong><?= e($fullName) ?></strong><small><?= e((string)$user['email']) ?></small></div>
</aside>
<main class="main">
<header class="topbar"><span class="eyebrow">U LEARN / LECTURER ASSIGNMENTS</span><div class="topbar-right"><div class="avatar"><?= e($initial) ?></div><form method="post" action="<?= e(url('auth/logout.php')) ?>"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><button type="submit" class="logout">Log out ↗</button></form></div></header>
<section><div class="eyebrow">ADMINISTRATION WORKSPACE</div><h1>Lecturer assignments<span>.</span></h1><p class="lead">Assign lecturers to degree programmes without selecting modules. Specific module assignments are optional and managed separately.</p></section>
<?php if ($notice !== ''): ?><div class="message success" role="status"><?= e($notice) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="message error" role="alert"><?= e($error) ?></div><?php endif; ?>
<section><div class="section-heading"><h2>Assign lecturer to a degree programme</h2><small>No module required</small></div><div class="panel">
<p class="hint">Choose the degree, linked intake, and academic year. Semester is optional (All semesters). This grants a degree-level assignment; individual module assignments remain separate.</p>
<form method="post" id="degreeAssignmentForm">
<input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="degree_assign">
<div class="form-grid">
<div class="field"><label for="degreeLecturer">Lecturer</label><select id="degreeLecturer" name="lecturer_id" required><option value="">Select lecturer</option><?php foreach ($lecturers as $lecturer): ?><option value="<?= (int)$lecturer['id'] ?>"><?= e(trim((string)$lecturer['title'].' '.(string)$lecturer['full_name'])) ?></option><?php endforeach; ?></select></div>
<div class="field"><label for="degreeUniversity">University</label><select id="degreeUniversity"><option value="">Select university</option><?php foreach ($universities as $uni): ?><option value="<?= (int)$uni['id'] ?>"><?= e((string)$uni['name']) ?></option><?php endforeach; ?></select></div>
<div class="field"><label for="degreeProgramme">Degree programme</label><select id="degreeProgramme" name="degree_id" required><option value="">Select degree</option><?php foreach ($degrees as $degree): ?><option value="<?= (int)$degree['id'] ?>" data-university="<?= e((string)($degree['university_id'] ?? '')) ?>"><?= e((string)$degree['name']) ?></option><?php endforeach; ?></select></div>
<div class="field"><label for="degreeIntake">Intake / Batch</label><select id="degreeIntake" name="batch_id" required><option value="">Select intake</option><?php foreach ($batches as $batch): ?><option value="<?= (int)$batch['id'] ?>"><?= e((string)$batch['batch_code']) ?></option><?php endforeach; ?></select></div>
<div class="field"><label for="degreeYear">Academic year</label><select id="degreeYear" name="study_year" required><option value="">Select year</option><?php for ($y=1;$y<=5;$y++): ?><option value="<?= $y ?>">Year <?= $y ?></option><?php endfor; ?></select></div>
<div class="field"><label for="degreeSemester">Semester (optional)</label><select id="degreeSemester" name="semester"><option value="0">All semesters</option><?php for ($sem=1;$sem<=3;$sem++): ?><option value="<?= $sem ?>">Semester <?= $sem ?></option><?php endfor; ?></select></div>
</div>
<button class="button" type="submit" <?= !$lecturers ? 'disabled' : '' ?>>Save degree-level assignment ↗</button>
</form>
<script>
(function(){
const links=<?= json_encode($degreeIntakeLinks, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
const university=document.getElementById('degreeUniversity'), degree=document.getElementById('degreeProgramme'), intake=document.getElementById('degreeIntake');
function update(){
const selectedUniversity=university.value;
Array.from(degree.options).forEach(o=>{if(!o.value)return;o.hidden=!!selectedUniversity&&o.dataset.university!==selectedUniversity;o.disabled=o.hidden;});
if(degree.selectedOptions[0]?.disabled)degree.value='';
const selectedDegree=Number(degree.value);
Array.from(intake.options).forEach(o=>{if(!o.value)return;const allowed=!selectedDegree||links.some(l=>Number(l.degree_id)===selectedDegree&&Number(l.batch_id)===Number(o.value));o.hidden=!allowed;o.disabled=!allowed;});
if(intake.selectedOptions[0]?.disabled)intake.value='';
}
university.addEventListener('change',()=>{degree.value='';update();});degree.addEventListener('change',()=>{intake.value='';update();});update();
})();
</script>
</div></section>
<section><div class="section-heading"><h2>Degree-level assignments</h2><small><?= count($degreeAssignments) ?> total</small></div><div class="panel"><div class="records">
<?php if (!$degreeAssignments): ?><p class="empty">No degree-level lecturer assignments yet.</p><?php endif; ?>
<?php foreach ($degreeAssignments as $assignment): ?>
<div class="record"><div><strong><?= e(trim((string)$assignment['title'].' '.(string)$assignment['full_name'])) ?> — <?= e((string)$assignment['degree_name']) ?></strong><small>Intake <?= e((string)$assignment['batch_code']) ?> · Year <?= (int)$assignment['study_year'] ?> · <?= (int)$assignment['semester'] === 0 ? 'All semesters' : 'Semester '.(int)$assignment['semester'] ?></small></div>
<div class="record-actions"><a class="action" href="<?= e(url('admin/module-lecturers.php')) ?>?degree_id=<?= (int)$assignment['degree_id'] ?>&amp;batch_id=<?= (int)$assignment['batch_id'] ?>&amp;year=<?= (int)$assignment['study_year'] ?>&amp;semester=<?= (int)$assignment['semester'] ?>">Manage modules ↗</a><span class="pill <?= (int)$assignment['is_active'] ? '' : 'off' ?>"><?= (int)$assignment['is_active'] ? 'Active' : 'Inactive' ?></span>
<form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="assignment_id" value="<?= (int)$assignment['id'] ?>"><input type="hidden" name="action" value="<?= (int)$assignment['is_active'] ? 'degree_deactivate' : 'degree_reactivate' ?>"><button class="action" type="submit"><?= (int)$assignment['is_active'] ? 'Deactivate' : 'Reactivate' ?></button></form>
<form method="post" onsubmit="return confirm('Permanently remove this degree-level assignment?');"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="assignment_id" value="<?= (int)$assignment['id'] ?>"><input type="hidden" name="action" value="degree_remove"><button class="action danger" type="submit">Remove</button></form></div></div>
<?php endforeach; ?>
</div></div></section>
<section><div class="section-heading"><h2>Module lecturer assignments</h2></div><div class="panel"><p class="lead">Manage lecturers for individual modules within their degree programme, intake, year and semester.</p><a class="button" style="display:inline-block" href="<?= e(url('admin/module-lecturers.php')) ?>">Open module lecturer management ↗</a></div></section>
<footer class="footer">© <?= date('Y') ?> U Learn · Administration Workspace</footer>
</main></div></body></html>
