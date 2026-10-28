<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$u = require_login();
$pdo = db();
$userId = (int)$u['id'];
$error = '';
$notice = '';

if (($u['role'] ?? '') !== 'student') {
    http_response_code(403);
    exit('This page is for student accounts only.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $moduleId = filter_var($_POST['module_id'] ?? null, FILTER_VALIDATE_INT);

    if ($action !== 'enroll' || $moduleId === false || $moduleId < 1) {
        $error = 'Invalid enrollment request.';
    } else {
        $check = $pdo->prepare('SELECT id FROM academic_modules WHERE id = ? AND is_active = 1');
        $check->execute([$moduleId]);
        if (!$check->fetchColumn()) {
            $error = 'This module is not available for enrollment.';
        } else {
            $stmt = $pdo->prepare(
                'INSERT IGNORE INTO student_module_enrollments (user_id, module_id) VALUES (?, ?)'
            );
            $stmt->execute([$userId, $moduleId]);
            $_SESSION['explore_notice'] = $stmt->rowCount() > 0
                ? 'You enrolled in the module successfully.'
                : 'You are already enrolled in this module.';
            go('dashboard/explore.php');
        }
    }
}

if (isset($_SESSION['explore_notice'])) {
    $notice = (string)$_SESSION['explore_notice'];
    unset($_SESSION['explore_notice']);
}

$stmt = $pdo->prepare(
    'SELECT m.id, m.module_code, m.module_name, m.description,
            CASE WHEN sme.id IS NULL THEN 0 ELSE 1 END AS is_enrolled
     FROM academic_modules m
     LEFT JOIN student_module_enrollments sme
       ON sme.module_id = m.id AND sme.user_id = ?
     WHERE m.is_active = 1
     ORDER BY m.module_name, m.module_code'
);
$stmt->execute([$userId]);
$modules = $stmt->fetchAll(PDO::FETCH_ASSOC);

$enrolled = [];
$available = [];
foreach ($modules as $module) {
    if ((int)$module['is_enrolled'] === 1) {
        $enrolled[] = $module;
    } else {
        $available[] = $module;
    }
}

$theme = (string)($u['theme'] ?? 'system');
if (!in_array($theme, ['light', 'dark', 'system'], true)) {
    $theme = 'system';
}
?>
<!doctype html>
<html lang="en" data-theme="<?= e($theme) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Explore &amp; Enroll — ULearn</title>
    <style>
        :root { color-scheme: light; --bg:#f7f8fc; --surface:#fff; --text:#171927; --muted:#777b90; --border:#e7e8f0; --accent:#6756e8; --soft:#f0edff; }
        :root[data-theme="dark"] { color-scheme: dark; --bg:#0c0e16; --surface:#161924; --text:#f4f4fa; --muted:#a0a4b6; --border:#2b2e3c; --accent:#a99aff; --soft:#292440; }
        @media (prefers-color-scheme: dark) { :root[data-theme="system"] { color-scheme:dark; --bg:#0c0e16; --surface:#161924; --text:#f4f4fa; --muted:#a0a4b6; --border:#2b2e3c; --accent:#a99aff; --soft:#292440; } }
        * { box-sizing:border-box; }
        body { margin:0; font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif; background:var(--bg); color:var(--text); line-height:1.55; }
        a { color:var(--accent); text-decoration:none; }
        .container { max-width:1100px; margin:0 auto; padding:36px 22px 90px; }
        .top { display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap; }
        .brand { font-weight:800; font-size:24px; letter-spacing:-1px; color:var(--text); }
        .brand span { color:var(--accent); }
        .back { font-size:14px; font-weight:700; }
        h1 { font-size:clamp(30px,5vw,48px); letter-spacing:-1.6px; margin:48px 0 10px; }
        h2 { margin:38px 0 16px; font-size:24px; }
        .lead,.hint { color:var(--muted); }
        .lead { max-width:760px; }
        .grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:16px; }
        .card { background:var(--surface); border:1px solid var(--border); border-radius:18px; padding:24px; min-width:0; }
        .code { font-size:12px; color:var(--muted); font-weight:800; letter-spacing:1px; }
        .card h3 { margin:8px 0; font-size:20px; overflow-wrap:anywhere; }
        .card p { color:var(--muted); font-size:14px; overflow-wrap:anywhere; }
        .pill { display:inline-block; border-radius:100px; background:var(--soft); color:var(--accent); padding:6px 12px; font-size:12px; font-weight:800; }
        button { border:0; background:var(--accent); color:#fff; padding:11px 18px; border-radius:10px; cursor:pointer; font-weight:750; font-size:14px; }
        .message { border-radius:12px; padding:14px 18px; margin-top:22px; background:var(--soft); }
        .error { background:#ffedf0; color:#a32143; }
        .empty { padding:25px; border:1px dashed var(--border); border-radius:16px; color:var(--muted); }
        .count { color:var(--muted); font-size:14px; font-weight:500; }
        @media (max-width:650px) { .grid { grid-template-columns:1fr; } }
    </style>
</head>
<body>
<main class="container">
    <header class="top">
        <a class="brand" href="<?= e(url('dashboard/index.php')) ?>">u learn<span>.</span></a>
        <a class="back" href="<?= e(url('dashboard/index.php')) ?>">← Back to dashboard</a>
    </header>
    <h1>Explore &amp; Enroll</h1>
    <p class="lead">Discover available modules across programmes and enroll to add them to your learning list. Enrollment here is for self-directed learning; it does not change your official degree, batch, or academic progression.</p>

    <?php if ($notice !== ''): ?>
        <div class="message" role="status"><?= e($notice) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="message error" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <section aria-labelledby="enrolled-heading">
        <h2 id="enrolled-heading">My Enrolled Modules <span class="count">(<?= count($enrolled) ?>)</span></h2>
        <?php if (!$enrolled): ?>
            <div class="empty">You haven't enrolled in any modules yet. Explore the modules below to get started.</div>
        <?php else: ?>
            <div class="grid">
                <?php foreach ($enrolled as $module): ?>
                    <article class="card">
                        <div class="code"><?= e($module['module_code']) ?></div>
                        <h3><?= e($module['module_name']) ?></h3>
                        <p><?= e($module['description'] ?: 'Learning module') ?></p>
                        <span class="pill">Enrolled ✓</span>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section aria-labelledby="explore-heading">
        <h2 id="explore-heading">Explore Available Modules <span class="count">(<?= count($available) ?>)</span></h2>
        <p class="hint">Only active modules appear here. Semester information will be added when we connect curriculum listings in the next step.</p>
        <?php if (!$available): ?>
            <div class="empty">No additional active modules are available to enroll in right now.</div>
        <?php else: ?>
            <div class="grid">
                <?php foreach ($available as $module): ?>
                    <article class="card">
                        <div class="code"><?= e($module['module_code']) ?></div>
                        <h3><?= e($module['module_name']) ?></h3>
                        <p><?= e($module['description'] ?: 'Learning module') ?></p>
                        <form method="post" action="<?= e(url('dashboard/explore.php')) ?>">
                            <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                            <input type="hidden" name="action" value="enroll">
                            <input type="hidden" name="module_id" value="<?= (int)$module['id'] ?>">
                            <button type="submit">Enroll in module</button>
                        </form>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
