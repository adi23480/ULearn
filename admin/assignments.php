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
        if ($action === 'assign') {
            $lecturerId = filter_var($_POST['lecturer_id'] ?? null, FILTER_VALIDATE_INT);
            $curriculumId = filter_var($_POST['curriculum_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$lecturerId || !$curriculumId || $lecturerId < 1 || $curriculumId < 1) {
                throw new RuntimeException('Choose a valid lecturer and curriculum entry.');
            }

            $pdo->beginTransaction();
            $check = $pdo->prepare("SELECT lp.id FROM lecturer_profiles lp JOIN users u ON u.id = lp.user_id WHERE lp.id = ? AND lp.is_active = 1 AND u.role = 'lecturer' AND u.email_verified_at IS NOT NULL LIMIT 1");
            $check->execute([$lecturerId]);
            if (!$check->fetchColumn()) {
                throw new RuntimeException('This lecturer is not active and verified.');
            }
            $check = $pdo->prepare('SELECT ac.id FROM academic_curriculum ac JOIN academic_degrees d ON d.id = ac.degree_id JOIN academic_batches b ON b.id = ac.batch_id JOIN academic_modules m ON m.id = ac.module_id WHERE ac.id = ? AND ac.is_active = 1 AND d.is_active = 1 AND b.is_active = 1 AND m.is_active = 1 LIMIT 1');
            $check->execute([$curriculumId]);
            if (!$check->fetchColumn()) {
                throw new RuntimeException('This curriculum entry is not active.');
            }
            $stmt = $pdo->prepare('INSERT INTO lecturer_module_assignments (lecturer_id, curriculum_id, is_active) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE is_active = 1');
            $stmt->execute([$lecturerId, $curriculumId]);
            $pdo->commit();
            $_SESSION['assignment_notice'] = 'Lecturer assignment saved successfully.';
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
$curricula = $pdo->query('SELECT ac.id, d.name AS degree_name, b.batch_code, m.module_code, m.module_name, ac.study_year, ac.semester FROM academic_curriculum ac JOIN academic_degrees d ON d.id = ac.degree_id JOIN academic_batches b ON b.id = ac.batch_id JOIN academic_modules m ON m.id = ac.module_id WHERE ac.is_active = 1 AND d.is_active = 1 AND b.is_active = 1 AND m.is_active = 1 ORDER BY d.name, b.batch_code, ac.study_year, ac.semester, m.module_code')->fetchAll(PDO::FETCH_ASSOC);
$assignments = $pdo->query('SELECT lma.id, lma.is_active, lma.created_at, lp.title, lp.is_active AS lecturer_active, u.full_name, u.role AS lecturer_role, d.name AS degree_name, d.is_active AS degree_active, b.batch_code, b.is_active AS batch_active, m.module_code, m.module_name, m.is_active AS module_active, ac.study_year, ac.semester, ac.is_active AS curriculum_active FROM lecturer_module_assignments lma JOIN lecturer_profiles lp ON lp.id = lma.lecturer_id JOIN users u ON u.id = lp.user_id JOIN academic_curriculum ac ON ac.id = lma.curriculum_id JOIN academic_degrees d ON d.id = ac.degree_id JOIN academic_batches b ON b.id = ac.batch_id JOIN academic_modules m ON m.id = ac.module_id ORDER BY lma.is_active DESC, u.full_name, d.name, b.batch_code, m.module_code')->fetchAll(PDO::FETCH_ASSOC);
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
</style>
</head>
<body>
<div class="layout">
<aside class="sidebar">
<a class="brand" href="<?= e(url('admin/index.php')) ?>">u learn<span>.</span></a>
<nav aria-label="Admin navigation"><p class="nav-label">Your workspace</p><div class="nav">
<a href="<?= e(url('admin/index.php')) ?>">◫ &nbsp; Overview</a>
<a href="<?= e(url('admin/lecturers.php')) ?>">♙ &nbsp; Lecturers</a>
<a href="<?= e(url('admin/academics.php')) ?>">▤ &nbsp; Academics</a>
<a class="active" href="<?= e(url('admin/assignments.php')) ?>" aria-current="page">↔ &nbsp; Assignments</a>
<a href="<?= e(url('admin/quizzes.php')) ?>">▣ &nbsp; Quiz Management</a>
</div></nav>
<div class="sidebar-bottom"><small>Signed in as</small><strong><?= e($fullName) ?></strong><small><?= e((string)$user['email']) ?></small></div>
</aside>
<main class="main">
<header class="topbar"><span class="eyebrow">U LEARN / LECTURER ASSIGNMENTS</span><div class="topbar-right"><div class="avatar"><?= e($initial) ?></div><form method="post" action="<?= e(url('auth/logout.php')) ?>"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><button type="submit" class="logout">Log out ↗</button></form></div></header>
<section><div class="eyebrow">ADMINISTRATION WORKSPACE</div><h1>Lecturer assignments<span>.</span></h1><p class="lead">Connect verified, active lecturers to specific degree programmes, batches, modules, study years, and semesters.</p></section>
<?php if ($notice !== ''): ?><div class="message success" role="status"><?= e($notice) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="message error" role="alert"><?= e($error) ?></div><?php endif; ?>
<section><div class="section-heading"><h2>Assign a lecturer</h2><small><?= count($lecturers) ?> eligible lecturers · <?= count($curricula) ?> active curriculum entries</small></div><div class="panel">
<?php if (!$lecturers): ?><p class="hint">No eligible lecturer profiles found. First approve a verified lecturer in <a href="<?= e(url('admin/lecturers.php')) ?>" style="color:var(--accent);font-weight:700">Lecturer Management</a>.</p><?php endif; ?>
<?php if (!$curricula): ?><p class="hint">No active curriculum entries found. Degrees, batches, and modules must be linked in the academic curriculum before they can be assigned to a lecturer. Your current Academic Management page does not yet create these links.</p><?php endif; ?>
<form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="assign"><div class="form-grid">
<div class="field"><label for="lecturer_id">Lecturer</label><select id="lecturer_id" name="lecturer_id" required <?= !$lecturers ? 'disabled' : '' ?>><option value="">Select a lecturer</option><?php foreach ($lecturers as $lecturer): ?><option value="<?= (int)$lecturer['id'] ?>"><?= e(trim((string)$lecturer['title'] . ' ' . (string)$lecturer['full_name'])) ?><?= $lecturer['department'] ? ' — ' . e((string)$lecturer['department']) : '' ?></option><?php endforeach; ?></select></div>
<div class="field"><label for="curriculum_id">Degree / batch / module / semester</label><select id="curriculum_id" name="curriculum_id" required <?= !$curricula ? 'disabled' : '' ?>><option value="">Select a curriculum entry</option><?php foreach ($curricula as $curriculum): ?><option value="<?= (int)$curriculum['id'] ?>"><?= e($curriculum['module_code'] . ' — ' . $curriculum['module_name'] . ' | ' . $curriculum['degree_name'] . ' | Batch ' . $curriculum['batch_code'] . ' | Year ' . $curriculum['study_year'] . ', Sem ' . $curriculum['semester']) ?></option><?php endforeach; ?></select></div>
</div><button type="submit" class="button" <?= (!$lecturers || !$curricula) ? 'disabled' : '' ?>>Save lecturer assignment ↗</button><p class="hint" style="margin:12px 0 0">Assigning the same lecturer to the same curriculum entry again reactivates their existing assignment. Multiple lecturers may teach the same entry.</p></form>
</div></section>
<section><div class="section-heading"><h2>Existing assignments</h2><small><?= $activeCount ?> active · <?= count($assignments) ?> total</small></div><div class="panel"><div class="records">
<?php if (!$assignments): ?><p class="empty">No lecturer assignments yet. Add your first assignment above once an eligible lecturer is available.</p><?php endif; ?>
<?php foreach ($assignments as $assignment): ?>
<div class="record"><div><strong><?= e(trim($assignment['title'] . ' ' . $assignment['full_name'])) ?> — <?= e($assignment['module_code']) ?></strong><small><?= e($assignment['module_name']) ?> · <?= e($assignment['degree_name']) ?> · Batch <?= e($assignment['batch_code']) ?></small><small>Year <?= (int)$assignment['study_year'] ?> · Semester <?= (int)$assignment['semester'] ?></small></div><div class="record-actions"><span class="pill <?= (int)$assignment['is_active'] ? '' : 'off' ?>"><?= (int)$assignment['is_active'] ? 'Active' : 'Inactive' ?></span>
<?php if ((int)$assignment['is_active'] === 1): ?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="deactivate"><input type="hidden" name="assignment_id" value="<?= (int)$assignment['id'] ?>"><button class="action" type="submit">Deactivate</button></form><?php else: ?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="reactivate"><input type="hidden" name="assignment_id" value="<?= (int)$assignment['id'] ?>"><button class="action" type="submit">Reactivate</button></form><?php endif; ?>
<form method="post" onsubmit="return confirm('Remove this lecturer assignment permanently?');"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="remove"><input type="hidden" name="assignment_id" value="<?= (int)$assignment['id'] ?>"><button class="action danger" type="submit">Remove</button></form></div></div>
<?php endforeach; ?>
</div></div></section>
<footer class="footer">© <?= date('Y') ?> U Learn · Administration Workspace</footer>
</main></div></body></html>
