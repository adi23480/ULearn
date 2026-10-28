<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$user = require_login();

if (!in_array((string)$user['role'], ['admin', 'main_admin'], true)) {
    http_response_code(403);
    exit('Access denied. Admin permission required.');
}

$pdo = db();
$error = '';
$notice = '';

if (isset($_SESSION['academic_notice'])) {
    $notice = (string)$_SESSION['academic_notice'];
    unset($_SESSION['academic_notice']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'degree') {
            $name = trim((string)($_POST['name'] ?? ''));
            $code = strtoupper(trim((string)($_POST['code'] ?? '')));

            if ($name === '' || $code === '' ||
                strlen($name) > 200 || strlen($code) > 30) {
                throw new RuntimeException('Enter a valid degree name and code.');
            }

            $stmt = $pdo->prepare(
                "INSERT INTO academic_degrees (name, code, is_active)
                 VALUES (?, ?, 1)"
            );
            $stmt->execute([$name, $code]);

            $_SESSION['academic_notice'] = 'Degree programme added successfully.';
            go('admin/academics.php');
        }

        if ($action === 'batch') {
            $batchCode = strtoupper(trim((string)($_POST['batch_code'] ?? '')));
            $year = filter_var($_POST['intake_year'] ?? null, FILTER_VALIDATE_INT);
            $number = filter_var($_POST['intake_number'] ?? null, FILTER_VALIDATE_INT);

            if ($batchCode === '' || strlen($batchCode) > 50 ||
                !$year || $year < 2000 || $year > 2100 ||
                !$number || $number < 1 || $number > 20) {
                throw new RuntimeException('Enter valid batch details.');
            }

            $stmt = $pdo->prepare(
                "INSERT INTO academic_batches
                 (batch_code, intake_year, intake_number, is_active)
                 VALUES (?, ?, ?, 1)"
            );
            $stmt->execute([$batchCode, $year, $number]);

            $_SESSION['academic_notice'] = 'University batch added successfully.';
            go('admin/academics.php');
        }

        if ($action === 'module') {
            $code = strtoupper(trim((string)($_POST['module_code'] ?? '')));
            $name = trim((string)($_POST['module_name'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));

            if ($code === '' || $name === '' ||
                strlen($code) > 30 || strlen($name) > 200 ||
                strlen($description) > 5000) {
                throw new RuntimeException('Enter valid module details.');
            }

            $stmt = $pdo->prepare(
                "INSERT INTO academic_modules
                 (module_code, module_name, description, is_active)
                 VALUES (?, ?, ?, 1)"
            );
            $stmt->execute([$code, $name, $description]);

            $_SESSION['academic_notice'] = 'Academic module added successfully.';
            go('admin/academics.php');
        }

        throw new RuntimeException('Invalid action.');

    } catch (PDOException $exception) {
        error_log('U Learn academic management error: ' . $exception->getMessage());
        $error = $exception->getCode() === '23000'
            ? 'This record may already exist. Check its code and try again.'
            : 'Unable to save the record. Please check the database structure.';
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }
}

$degrees = $pdo->query(
    "SELECT id, name, code
     FROM academic_degrees
     WHERE is_active = 1
     ORDER BY name"
)->fetchAll();

$batches = $pdo->query(
    "SELECT id, batch_code, intake_year, intake_number
     FROM academic_batches
     WHERE is_active = 1
     ORDER BY intake_year DESC, intake_number ASC"
)->fetchAll();

$modules = $pdo->query(
    "SELECT id, module_code, module_name
     FROM academic_modules
     WHERE is_active = 1
     ORDER BY module_code"
)->fetchAll();

$nameParts = preg_split('/\s+/', trim((string)$user['full_name']));
$firstName = $nameParts[0] ?: 'Admin';
$initial = strtoupper(substr($firstName, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Academic Management — U Learn</title>

<script>
(function () {
    const saved = localStorage.getItem('ulearn-theme');
    const theme = saved === 'light' || saved === 'dark'
        ? saved
        : (window.matchMedia('(prefers-color-scheme: dark)').matches
            ? 'dark' : 'light');
    document.documentElement.dataset.theme = theme;
})();
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
}

* { box-sizing: border-box; }

body {
    margin: 0;
    background: var(--bg);
    color: var(--text);
    font-family: Inter, "Segoe UI", Arial, sans-serif;
    line-height: 1.6;
}

a { color: inherit; text-decoration: none; }
button, input, textarea { font: inherit; }

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
    color: var(--muted);
    font-size: 11px;
    font-weight: 750;
    letter-spacing: 1.7px;
    text-transform: uppercase;
}

.nav-label { margin: 0 12px 14px; }

.nav { display: grid; gap: 6px; }

.nav a {
    display: block;
    padding: 12px 14px;
    border-radius: 12px;
    font-size: 14px;
    font-weight: 600;
}

.nav a:hover { background: var(--surface-alt); }

.nav a.active {
    background: var(--accent-light);
    color: var(--accent);
}

.sidebar-bottom {
    margin-top: auto;
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 16px;
    font-size: 13px;
}

.sidebar-bottom strong { display: block; }
.sidebar-bottom small { color: var(--muted); }

.main {
    min-width: 0;
    padding: 34px clamp(20px, 5vw, 76px) 80px;
}

.topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    margin-bottom: 55px;
}

.topbar-right {
    display: flex;
    align-items: center;
    gap: 16px;
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
}

.logout {
    background: transparent;
    border: 0;
    color: var(--muted);
    cursor: pointer;
    font-size: 13px;
    font-weight: 650;
}

h1, h2, h3, p { margin-top: 0; }

h1 {
    font-size: clamp(36px, 4vw, 56px);
    line-height: 1.15;
    letter-spacing: -2px;
    margin: 15px 0;
}

h1 span { color: var(--accent); }

.lead {
    color: var(--muted);
    font-size: 15px;
    max-width: 650px;
}

.section-heading {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 15px;
    margin: 46px 0 20px;
}

.section-heading h2 {
    margin: 0;
    font-size: 23px;
    letter-spacing: -.7px;
}

.section-heading small { color: var(--muted); }

.panel {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 20px;
    padding: 28px;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px;
}

.field { margin-bottom: 18px; }

label {
    display: block;
    font-size: 13px;
    font-weight: 700;
    margin-bottom: 9px;
}

input, textarea {
    width: 100%;
    padding: 13px 14px;
    border: 1px solid var(--border);
    border-radius: 11px;
    background: var(--surface-alt);
    color: var(--text);
    outline: none;
}

input:focus, textarea:focus {
    border-color: var(--accent);
}

textarea {
    resize: vertical;
    min-height: 95px;
}

.button {
    background: var(--text);
    color: var(--bg);
    border: 0;
    border-radius: 11px;
    padding: 13px 20px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
}

.button:hover { opacity: .86; }

.records {
    margin-top: 24px;
    border-top: 1px solid var(--border);
    padding-top: 20px;
}

.records h3 {
    font-size: 14px;
    margin-bottom: 12px;
}

.record {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    padding: 13px 0;
    border-bottom: 1px solid var(--border);
    font-size: 13px;
}

.record:last-child { border-bottom: 0; }

.record strong { display: block; }

.record small {
    display: block;
    color: var(--muted);
    margin-top: 3px;
}

.pill {
    background: var(--accent-light);
    color: var(--accent);
    border-radius: 100px;
    padding: 6px 11px;
    font-size: 11px;
    font-weight: 750;
    white-space: nowrap;
}

.message {
    padding: 14px 17px;
    border-radius: 12px;
    margin: 22px 0;
    font-size: 14px;
}

.success { background: #e9f8ef; color: #176b43; }
.error { background: #ffedf0; color: #a32143; }

.empty {
    color: var(--muted);
    font-size: 13px;
    padding: 14px 0;
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
    .form-grid { grid-template-columns: 1fr; }
    .panel { padding: 20px; }
    h1 { letter-spacing: -1.5px; }
}
</style>
</head>

<body>

<div class="layout">

    <aside class="sidebar">

        <a class="brand" href="<?= e(url('admin/index.php')) ?>">
            u learn<span>.</span>
        </a>

        <nav aria-label="Admin navigation">

            <p class="nav-label">Your workspace</p>

            <div class="nav">

                <a href="<?= e(url('admin/index.php')) ?>">
                    ◫ &nbsp; Overview
                </a>

                <a href="<?= e(url('admin/lecturers.php')) ?>">
                    ♙ &nbsp; Lecturers
                </a>

                <a class="active" href="<?= e(url('admin/academics.php')) ?>">
                    ▤ &nbsp; Academics
                </a>

            </div>

        </nav>

        <div class="sidebar-bottom">
            <small>Signed in as</small>
            <strong><?= e((string)$user['full_name']) ?></strong>
            <small><?= e((string)$user['email']) ?></small>
        </div>

    </aside>

    <main class="main">

        <header class="topbar">

            <span class="eyebrow">
                U LEARN / ACADEMIC MANAGEMENT
            </span>

            <div class="topbar-right">

                <div class="avatar"><?= e($initial) ?></div>

                <form method="post"
                      action="<?= e(url('auth/logout.php')) ?>">
                    <input type="hidden" name="csrf"
                           value="<?= e(csrf()) ?>">
                    <button type="submit" class="logout">
                        Log out ↗
                    </button>
                </form>

            </div>

        </header>

        <section>
            <div class="eyebrow">ADMINISTRATION WORKSPACE</div>
            <h1>Academic management<span>.</span></h1>
            <p class="lead">
                Build and organize the academic structure of U Learn.
                Add degree programmes, university intakes,
                and modules for your students.
            </p>
        </section>

        <?php if ($notice): ?>
            <div class="message success" role="status">
                <?= e($notice) ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="message error" role="alert">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <section id="degrees">

            <div class="section-heading">
                <h2>Degree programmes</h2>
                <small><?= count($degrees) ?> active degrees</small>
            </div>

            <div class="panel">

                <form method="post">

                    <input type="hidden" name="csrf"
                           value="<?= e(csrf()) ?>">
                    <input type="hidden" name="action"
                           value="degree">

                    <div class="form-grid">

                        <div class="field">
                            <label for="degree_name">Degree name</label>
                            <input id="degree_name" name="name"
                                   placeholder="BSc (Hons) in Computer Science"
                                   maxlength="200" required>
                        </div>

                        <div class="field">
                            <label for="degree_code">Degree code</label>
                            <input id="degree_code" name="code"
                                   placeholder="BSC-CS"
                                   maxlength="30" required>
                        </div>

                    </div>

                    <button type="submit" class="button">
                        Add degree programme ↗
                    </button>

                </form>

                <div class="records">
                    <h3>Existing degree programmes</h3>

                    <?php if (!$degrees): ?>
                        <div class="empty">No degrees added yet.</div>
                    <?php endif; ?>

                    <?php foreach ($degrees as $degree): ?>
                        <div class="record">
                            <div>
                                <strong><?= e($degree['name']) ?></strong>
                                <small><?= e($degree['code']) ?></small>
                            </div>
                            <span class="pill">Active</span>
                        </div>
                    <?php endforeach; ?>

                </div>

            </div>

        </section>

        <section id="batches">

            <div class="section-heading">
                <h2>University batches</h2>
                <small><?= count($batches) ?> active batches</small>
            </div>

            <div class="panel">

                <form method="post">

                    <input type="hidden" name="csrf"
                           value="<?= e(csrf()) ?>">
                    <input type="hidden" name="action"
                           value="batch">

                    <div class="form-grid">

                        <div class="field">
                            <label for="batch_code">Batch code</label>
                            <input id="batch_code" name="batch_code"
                                   placeholder="25.1"
                                   maxlength="50" required>
                        </div>

                        <div class="field">
                            <label for="intake_year">Intake year</label>
                            <input type="number" id="intake_year"
                                   name="intake_year" min="2000"
                                   max="2100" placeholder="2025" required>
                        </div>

                        <div class="field">
                            <label for="intake_number">Intake number</label>
                            <input type="number" id="intake_number"
                                   name="intake_number" min="1" max="20"
                                   placeholder="1" required>
                        </div>

                    </div>

                    <button type="submit" class="button">
                        Add university batch ↗
                    </button>

                </form>

                <div class="records">
                    <h3>Existing batches</h3>

                    <?php if (!$batches): ?>
                        <div class="empty">No batches added yet.</div>
                    <?php endif; ?>

                    <?php foreach ($batches as $batch): ?>
                        <div class="record">
                            <div>
                                <strong><?= e($batch['batch_code']) ?></strong>
                                <small>
                                    Intake <?= (int)$batch['intake_number'] ?>
                                    · <?= (int)$batch['intake_year'] ?>
                                </small>
                            </div>
                            <span class="pill">Active</span>
                        </div>
                    <?php endforeach; ?>

                </div>

            </div>

        </section>

        <section id="modules">

            <div class="section-heading">
                <h2>Academic modules</h2>
                <small><?= count($modules) ?> active modules</small>
            </div>

            <div class="panel">

                <form method="post">

                    <input type="hidden" name="csrf"
                           value="<?= e(csrf()) ?>">
                    <input type="hidden" name="action"
                           value="module">

                    <div class="form-grid">

                        <div class="field">
                            <label for="module_code">Module code</label>
                            <input id="module_code" name="module_code"
                                   placeholder="CS101"
                                   maxlength="30" required>
                        </div>

                        <div class="field">
                            <label for="module_name">Module name</label>
                            <input id="module_name" name="module_name"
                                   placeholder="Introduction to Programming"
                                   maxlength="200" required>
                        </div>

                    </div>

                    <div class="field">
                        <label for="description">Module description</label>
                        <textarea id="description" name="description"
                                  placeholder="Describe what students will learn..."
                                  maxlength="5000"></textarea>
                    </div>

                    <button type="submit" class="button">
                        Add academic module ↗
                    </button>

                </form>

                <div class="records">
                    <h3>Existing modules</h3>

                    <?php if (!$modules): ?>
                        <div class="empty">No modules added yet.</div>
                    <?php endif; ?>

                    <?php foreach ($modules as $module): ?>
                        <div class="record">
                            <div>
                                <strong><?= e($module['module_name']) ?></strong>
                                <small><?= e($module['module_code']) ?></small>
                            </div>
                            <span class="pill">Active</span>
                        </div>
                    <?php endforeach; ?>

                </div>

            </div>

        </section>

    </main>

</div>

</body>
</html>
