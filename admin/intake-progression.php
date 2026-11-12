<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$u = require_login();
if (!in_array($u['role'], ['admin', 'main_admin'], true)) {
    http_response_code(403);
    exit('Forbidden');
}

$pdo = db();
$error = '';
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $degree = (int)($_POST['degree_id'] ?? 0);
    $batch = (int)($_POST['batch_id'] ?? 0);
    $year = (int)($_POST['study_year'] ?? 0);
    $sem = (int)($_POST['semester'] ?? 0);
    
    $q = $pdo->prepare('SELECT d.duration_years FROM academic_degree_intakes adi JOIN academic_degrees d ON d.id=adi.degree_id JOIN academic_batches b ON b.id=adi.batch_id WHERE adi.degree_id=? AND adi.batch_id=? AND adi.is_active=1 AND d.is_active=1 AND b.is_active=1');
    $q->execute([$degree, $batch]);
    $duration = (int)$q->fetchColumn();
    
    if (!$duration || $year < 1 || $year > $duration || !in_array($sem, [1, 2], true)) {
        $error = 'Invalid intake, year or semester.';
    } else {
        $pdo->prepare('INSERT INTO academic_intake_progression(degree_id,batch_id,study_year,semester) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE study_year=VALUES(study_year),semester=VALUES(semester)')->execute([$degree, $batch, $year, $sem]);
        $notice = 'Intake progression updated for all students in this degree and intake.';
    }
}

$rows = $pdo->query('SELECT adi.degree_id,adi.batch_id,d.name degree_name,d.duration_years,b.batch_code,au.name university_name,COALESCE(p.study_year,1) study_year,COALESCE(p.semester,1) semester FROM academic_degree_intakes adi JOIN academic_degrees d ON d.id=adi.degree_id JOIN awarding_universities au ON au.id=d.awarding_university_id JOIN academic_batches b ON b.id=adi.batch_id LEFT JOIN academic_intake_progression p ON p.degree_id=adi.degree_id AND p.batch_id=adi.batch_id WHERE adi.is_active=1 ORDER BY au.name,d.name,b.batch_code')->fetchAll(PDO::FETCH_ASSOC);

// Personal unread notifications for the signed-in administrator.
$notificationStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0"
);
$notificationStmt->execute([(int)$u['id']]);
$unreadNotifications = (int)$notificationStmt->fetchColumn();

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
<title>Academic Intake Progression — U Learn</title>
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
    button, select { font: inherit; }
    button { cursor: pointer; }
    
    .layout {
        display: block;
        min-height: 100vh;
    }
    .main {
        max-width: 1100px;
        margin: 0 auto;
        padding: 34px clamp(20px, 5vw, 40px) 80px;
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

    .card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 20px;
        padding: 24px;
        margin-bottom: 16px;
        box-shadow: var(--shadow);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 20px;
        flex-wrap: wrap;
    }
    .card strong { font-size: 15px; letter-spacing: -.3px; display: block; margin-bottom: 12px; width: 100%; }

    form {
        display: flex;
        gap: 14px;
        flex-wrap: wrap;
        align-items: center;
        width: 100%;
    }
    label {
        font-size: 13px;
        font-weight: 750;
        display: flex;
        flex-direction: column;
        gap: 6px;
    }
    select {
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 10px 14px;
        background: var(--surface-alt);
        color: var(--text);
        outline: none;
        transition: border-color .2s, box-shadow .2s;
    }
    select:focus {
        border-color: var(--accent);
        box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent) 18%, transparent);
    }
    .btn {
        border: 1px solid var(--border);
        background: var(--surface-alt);
        color: var(--text);
        border-radius: 12px;
        padding: 11px 16px;
        font-size: 13px;
        font-weight: 750;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        cursor: pointer;
        transition: transform .18s, border-color .18s;
        margin-top: 22px;
    }
    .btn.primary {
        background: linear-gradient(110deg, #5d4ae0, #8571fa);
        border-color: transparent;
        color: #fff;
        box-shadow: 0 9px 22px rgba(103,86,232,.2);
    }
    .btn:hover { transform: translateY(-1px); border-color: var(--accent); }
    .btn.primary:hover { color: #fff; }

    .message {
        padding: 13px 17px;
        background: var(--accent-light);
        color: var(--accent);
        border-radius: 12px;
        margin-bottom: 20px;
        font-size: 13px;
        font-weight: 650;
    }
    .message.error { background: rgba(196,59,84,.1); color: var(--danger); }

    .footer {
        margin-top: 50px;
        padding-top: 20px;
        border-top: 1px solid var(--border);
        font-size: 12px;
        color: var(--muted);
    }

    @media (max-width: 600px) {
        .main { padding: 24px 18px 60px; }
        .hero { flex-direction: column; align-items: flex-start; }
        .topbar { flex-wrap: wrap; }
    }
</style>
<link rel="stylesheet" href="<?= e(url('assets/css/ulearn-design-system.css?v=1')) ?>">

<script src="<?= htmlspecialchars(url('assets/js/theme-global.js?v=20261112-1'), ENT_QUOTES, 'UTF-8') ?>"></script>
</head>
<body>

<div class="layout">
    <main class="main">
        <header class="topbar">
            <span class="eyebrow">
                U LEARN / ACADEMIC PROGRESSION SPACE
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

                <form method="post" action="<?= e(url('auth/logout.php')) ?>" style="margin:0;display:inline;">
                    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                    <button type="submit" class="logout">
                        Log out ↗
                    </button>
                </form>
            </div>
        </header>

        <header class="hero">
            <div>
                <div style="font-size:12px; font-weight:750; text-transform:uppercase; letter-spacing:1.5px; opacity:0.8;">Administration / Academics</div>
                <h1>Academic intake progression</h1>
                <p>Set the current year and semester once per degree + intake. Students do not select these during registration.</p>
            </div>
            <span class="pill">
                <?= e($roleLabel) ?>
            </span>
        </header>

        <?php if ($error !== ''): ?>
            <div class="message error" role="alert"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if ($notice !== ''): ?>
            <div class="message" role="status"><?= e($notice) ?></div>
        <?php endif; ?>

        <?php foreach ($rows as $r): ?>
            <article class="card">
                <strong><?= e($r['university_name'] . ' — ' . $r['degree_name'] . ' — Intake ' . $r['batch_code']) ?></strong>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                    <input type="hidden" name="degree_id" value="<?= (int)$r['degree_id'] ?>">
                    <input type="hidden" name="batch_id" value="<?= (int)$r['batch_id'] ?>">
                    
                    <label>Year
                        <select name="study_year">
                            <?php for ($i = 1; $i <= (int)$r['duration_years']; $i++): ?>
                                <option value="<?= $i ?>" <?= $i === (int)$r['study_year'] ? 'selected' : '' ?>><?= $i ?></option>
                            <?php endfor; ?>
                        </select>
                    </label>
                    
                    <label>Semester
                        <select name="semester">
                            <option value="1" <?= (int)$r['semester'] === 1 ? 'selected' : '' ?>>1</option>
                            <option value="2" <?= (int)$r['semester'] === 2 ? 'selected' : '' ?>>2</option>
                        </select>
                    </label>
                    
                    <button type="submit" class="btn primary">Update intake</button>
                </form>
            </article>
        <?php endforeach; ?>

        <?php if (!$rows): ?>
            <div class="card" style="text-align: center; color: var(--muted); font-size: 13px; display: block;">No active degree intakes found.</div>
        <?php endif; ?>

        <footer class="footer">
            © <?= date('Y') ?> U Learn · Administration Workspace
        </footer>
    </main>
</div>

</body>
</html>
