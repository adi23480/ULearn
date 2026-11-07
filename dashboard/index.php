<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$u = require_login();

$pdo = db();

$userId = (int)$u['id'];

$error = '';

$notice = '';

$degrees = $pdo->query(

    "SELECT id, name FROM academic_degrees

     WHERE is_active = 1 ORDER BY name"

)->fetchAll();

$batches = $pdo->query(

    "SELECT id, batch_code FROM academic_batches

     WHERE is_active = 1

     ORDER BY intake_year DESC, intake_number ASC"

)->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_check();

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'academic') {

        $degreeId = filter_var(

            $_POST['degree_id'] ?? null,

            FILTER_VALIDATE_INT

        );

        $batchId = filter_var(

            $_POST['batch_id'] ?? null,

            FILTER_VALIDATE_INT

        );

        $validDegrees = array_map(

            'intval',

            array_column($degrees, 'id')

        );

        $validBatches = array_map(

            'intval',

            array_column($batches, 'id')

        );

        if (

            !$degreeId ||

            !$batchId ||

            !in_array($degreeId, $validDegrees, true) ||

            !in_array($batchId, $validBatches, true)

        ) {

            $error = 'Please select a valid degree and batch.';

        } else {

            $stmt = $pdo->prepare(

                "INSERT INTO student_academic_profiles

                    (user_id, degree_id, batch_id)

                 VALUES (?, ?, ?)

                 ON DUPLICATE KEY UPDATE

                    degree_id = VALUES(degree_id),

                    batch_id = VALUES(batch_id)"

            );

            $stmt->execute([$userId, $degreeId, $batchId]);

            $_SESSION['dashboard_notice'] =

                'Academic preferences saved successfully.';

            go('dashboard/index.php');

        }

    }

    if ($action === 'theme') {

        $theme = (string)($_POST['theme'] ?? '');

        if (in_array($theme, ['light', 'dark', 'system'], true)) {

            $stmt = $pdo->prepare(

                "UPDATE users SET theme = ? WHERE id = ?"

            );

            $stmt->execute([$theme, $userId]);

            $_SESSION['dashboard_notice'] =

                'Appearance preference saved successfully.';

            go('dashboard/index.php');

        } else {

            $error = 'Invalid appearance setting.';

        }

    }

}

if (isset($_SESSION['dashboard_notice'])) {

    $notice = (string)$_SESSION['dashboard_notice'];

    unset($_SESSION['dashboard_notice']);

}

$stmt = $pdo->prepare(

    "SELECT p.degree_id, p.batch_id,

            d.name AS degree_name,

            b.batch_code

     FROM student_academic_profiles p

     JOIN academic_degrees d ON d.id = p.degree_id

     JOIN academic_batches b ON b.id = p.batch_id

     WHERE p.user_id = ?"

);

$stmt->execute([$userId]);

$profile = $stmt->fetch() ?: null;

$modules = [];

$availableYears = [];

$availableSemesters = [];

$selectedYear = (isset($_GET['year']) && $_GET['year'] !== '') ? filter_var($_GET['year'], FILTER_VALIDATE_INT) : null;

$selectedSemester = (isset($_GET['semester']) && $_GET['semester'] !== '') ? filter_var($_GET['semester'], FILTER_VALIDATE_INT) : null;

if ($profile) {

    $stmt = $pdo->prepare(

        "SELECT m.id AS module_id,

                m.module_code,

                m.module_name,

                m.description,

                c.study_year,

                c.semester

         FROM academic_curriculum c

         JOIN academic_modules m ON m.id = c.module_id

         WHERE c.degree_id = ?

           AND c.batch_id = ?

           AND c.is_active = 1

           AND m.is_active = 1

         ORDER BY c.study_year, c.semester, m.module_name"

    );

    $stmt->execute([

        $profile['degree_id'],

        $profile['batch_id']

    ]);

    $allModules = $stmt->fetchAll();

    foreach ($allModules as $module) {

        $yearValue = (int)$module['study_year'];

        $semesterValue = (int)$module['semester'];

        if (!in_array($yearValue, $availableYears, true)) $availableYears[] = $yearValue;

        if (!in_array($semesterValue, $availableSemesters, true)) $availableSemesters[] = $semesterValue;

    }

    sort($availableYears);

    sort($availableSemesters);

    if (!in_array($selectedYear, $availableYears, true)) $selectedYear = null;

    if (!in_array($selectedSemester, $availableSemesters, true)) $selectedSemester = null;

    $modules = array_values(array_filter($allModules, static function (array $module) use ($selectedYear, $selectedSemester): bool {

        if ($selectedYear !== null && (int)$module['study_year'] !== $selectedYear) return false;

        if ($selectedSemester !== null && (int)$module['semester'] !== $selectedSemester) return false;

        return true;

    }));

}

// Voluntary module enrollments are independent of the academic curriculum.

$enrolledStmt = $pdo->prepare(

    "SELECT m.id AS module_id, m.module_code, m.module_name, m.description,

            e.enrolled_at

     FROM student_module_enrollments e

     JOIN academic_modules m ON m.id = e.module_id

     WHERE e.user_id = ?

     ORDER BY e.enrolled_at DESC, m.module_name"

);

$enrolledStmt->execute([$userId]);

$enrolledModules = $enrolledStmt->fetchAll();

// Stage 8: Count personal unread notifications for this student.

$unreadNotificationsStmt = $pdo->prepare(

    'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0'

);

$unreadNotificationsStmt->execute([$userId]);

$unreadNotifications = (int)$unreadNotificationsStmt->fetchColumn();

$pointsStmt = $pdo->prepare('SELECT COALESCE(SUM(points), 0) FROM student_note_points WHERE user_id = ?');
$pointsStmt->execute([$userId]);
$notePoints = (int)$pointsStmt->fetchColumn();

$theme = (string)($u['theme'] ?? 'system');

$nameParts = preg_split(

    '/\s+/',

    trim((string)$u['full_name'])

);

$firstName = $nameParts[0] ?? 'Student';

if ($firstName === '') {

    $firstName = 'Student';

}

$initial = strtoupper(substr($firstName, 0, 1));

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Dashboard — U Learn</title>

    <script>

        try {

            const saved = <?= json_encode(

                $theme,

                JSON_HEX_TAG | JSON_HEX_AMP |

                JSON_HEX_APOS | JSON_HEX_QUOT

            ) ?>;

            const selected = saved === 'system'

                ? null

                : saved;

            const theme = selected === 'light' || selected === 'dark'

                ? selected

                : (

                    window.matchMedia(

                        '(prefers-color-scheme: dark)'

                    ).matches ? 'dark' : 'light'

                );

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

        }

        * {

            box-sizing: border-box;

        }

        html {

            scroll-behavior: smooth;

        }

        body {

            margin: 0;

            background: var(--bg);

            color: var(--text);

            font-family: Inter, -apple-system, BlinkMacSystemFont,

                "Segoe UI", Arial, sans-serif;

            line-height: 1.6;

        }

        a {

            color: inherit;

            text-decoration: none;

        }

        button, select {

            font: inherit;

        }

        button {

            cursor: pointer;

        }

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

        .brand span {

            color: var(--accent);

        }

        .nav-label, .eyebrow {

            font-size: 11px;

            font-weight: 750;

            letter-spacing: 1.7px;

            text-transform: uppercase;

            color: var(--muted);

        }

        .nav-label {

            margin: 0 12px 14px;

        }

        .nav {

            display: grid;

            gap: 6px;

        }

        .nav a {

            display: block;

            padding: 12px 14px;

            border-radius: 12px;

            font-size: 14px;

            font-weight: 600;

        }

        .nav a:hover {

            background: var(--surface-alt);

        }

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

            margin-bottom: 60px;

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

        .notification-bell:focus-visible { outline: 3px solid var(--accent); outline-offset: 3px; }

        .notification-bell svg { width: 21px; height: 21px; stroke: currentColor; fill: none; stroke-width: 1.9; stroke-linecap: round; stroke-linejoin: round; }

        .notification-count {

            position: absolute; top: -8px; right: -9px;

            min-width: 20px; height: 20px; padding: 0 5px;

            display: inline-grid; place-items: center;

            border-radius: 999px; background: #e34878; color: #fff;

            border: 2px solid var(--bg); font-size: 10px; font-weight: 800;

            line-height: 1;

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

            font-size: 13px;

            font-weight: 650;

        }

        h1, h2, h3, p {

            margin-top: 0;

        }

        h1 {

            font-size: clamp(36px, 4vw, 60px);

            line-height: 1.13;

            letter-spacing: -2.5px;

            margin: 16px 0;

        }

        h1 span {

            color: var(--accent);

        }

        .lead {

            color: var(--muted);

            font-size: 16px;

            max-width: 640px;

        }

        .section-heading {

            display: flex;

            align-items: baseline;

            justify-content: space-between;

            gap: 15px;

            margin: 50px 0 20px;

        }

        .section-heading h2 {

            margin: 0;

            font-size: 23px;

            letter-spacing: -.7px;

        }

        .section-heading small {

            color: var(--muted);

        }

        .panel {

            background: var(--surface);

            border: 1px solid var(--border);

            border-radius: 20px;

            padding: 28px;

            box-shadow: var(--shadow);

        }

        .profile-summary {

            display: flex;

            justify-content: space-between;

            align-items: center;

            flex-wrap: wrap;

            gap: 20px;

        }

        .profile-summary h2 {

            font-size: 24px;

            letter-spacing: -.6px;

            margin: 8px 0;

        }

        .muted {

            color: var(--muted);

        }

        .pill {

            display: inline-block;

            background: var(--accent-light);

            color: var(--accent);

            padding: 8px 14px;

            border-radius: 100px;

            font-size: 12px;

            font-weight: 750;

        }

        .fields {

            display: grid;

            grid-template-columns: repeat(2, minmax(0, 1fr));

            gap: 18px;

            margin: 24px 0;

        }

        label {

            display: block;

            font-size: 13px;

            font-weight: 700;

            margin-bottom: 9px;

        }

        select {

            width: 100%;

            padding: 13px 14px;

            border: 1px solid var(--border);

            border-radius: 11px;

            background: var(--surface-alt);

            color: var(--text);

        }

        .button {

            display: inline-flex;

            justify-content: center;

            align-items: center;

            background: var(--text);

            color: var(--bg);

            border: 0;

            border-radius: 11px;

            padding: 13px 20px;

            font-size: 13px;

            font-weight: 700;

        }

        .button:disabled {

            opacity: .5;

            cursor: not-allowed;

        }

        .message {

            padding: 14px 17px;

            border-radius: 12px;

            margin: 20px 0;

            font-size: 14px;

        }

        .success {

            background: #e9f8ef;

            color: #176b43;

        }

        .error {

            background: #ffedf0;

            color: #a32143;

        }

        .module-grid {

            display: grid;

            grid-template-columns: repeat(2, minmax(0, 1fr));

            gap: 16px;

        }

        .module-card {

            background: var(--surface);

            border: 1px solid var(--border);

            border-radius: 18px;

            padding: 25px;

        }

        .module-icon {

            width: 46px;

            height: 46px;

            display: grid;

            place-items: center;

            border-radius: 12px;

            background: var(--accent-light);

            color: var(--accent);

            font-size: 22px;

            margin-bottom: 24px;

        }

        .module-code {

            font-size: 11px;

            font-weight: 750;

            letter-spacing: 1px;

            color: var(--muted);

        }

        .module-card h3 {

            margin: 8px 0;

            font-size: 19px;

            letter-spacing: -.5px;

        }

        .module-card p {

            color: var(--muted);

            font-size: 13px;

        }

        .empty {

            background: var(--surface);

            border: 1px dashed var(--border);

            border-radius: 18px;

            padding: 35px;

        }

        .empty h3 {

            margin-bottom: 8px;

        }

        .empty p {

            color: var(--muted);

            margin-bottom: 0;

        }

        .feature-grid {

            display: grid;

            grid-template-columns: repeat(3, minmax(0, 1fr));

            gap: 16px;

        }

        .feature {

            background: var(--surface);

            border: 1px solid var(--border);

            border-radius: 18px;

            padding: 23px;

        }

        .feature .symbol {

            color: var(--accent);

            font-size: 26px;

        }

        .feature h3 {

            margin: 22px 0 8px;

            font-size: 17px;

        }

        .feature p {

            color: var(--muted);

            font-size: 13px;

        }

        .settings {

            max-width: 550px;

        }

        @media (max-width: 900px) {

            .layout {

                grid-template-columns: 1fr;

            }

            .sidebar {

                border-right: 0;

                border-bottom: 1px solid var(--border);

                padding: 18px;

                gap: 18px;

            }

            .nav {

                display: flex;

                flex-wrap: wrap;

            }

            .sidebar-bottom {

                display: none;

            }

            .main {

                padding-top: 25px;

            }

            .topbar {

                margin-bottom: 35px;

            }

        }

        @media (max-width: 600px) {

            .fields,

            .module-grid,

            .feature-grid {

                grid-template-columns: 1fr;

            }

            .main {

                padding: 24px 18px 60px;

            }

            .panel {

                padding: 20px;

            }

            h1 {

                letter-spacing: -1.5px;

            }

        }

        /* Header theme control */

        .theme-toggle-form { margin: 0; display: flex; }

        .theme-toggle {

            width: 42px; height: 42px; display: inline-grid; place-items: center;

            border: 1px solid var(--border); border-radius: 12px;

            background: var(--surface); color: var(--accent);

            box-shadow: var(--shadow); cursor: pointer;

            transition: transform .18s ease, background .18s ease, border-color .18s ease;

        }

        .theme-toggle:hover { transform: translateY(-2px); background: var(--accent-light); border-color: var(--accent); }

        .theme-toggle:focus-visible { outline: 3px solid var(--accent); outline-offset: 3px; }

        .theme-icon { font-size: 23px; line-height: 1; }

    </style>

<script>

        document.addEventListener('DOMContentLoaded', function () {

            const button = document.getElementById('themeToggle');

            const icon = document.getElementById('themeIcon');

            const next = document.getElementById('nextTheme');

            if (!button || !icon || !next) return;

            const current = document.documentElement.dataset.theme === 'dark' ? 'dark' : 'light';

            icon.textContent = current === 'dark' ? '☀' : '☾';

            button.setAttribute('aria-label', current === 'dark' ? 'Switch to light mode' : 'Switch to dark mode');

            button.title = current === 'dark' ? 'Switch to light mode' : 'Switch to dark mode';

            next.value = current === 'dark' ? 'light' : 'dark';

            document.getElementById('themeToggleForm').addEventListener('submit', function () {

                document.documentElement.dataset.theme = next.value;

                try { localStorage.setItem('ulearn-theme', next.value); } catch (e) {}

            });

        });

    </script>

</head>

<body>

<div class="layout">

    <aside class="sidebar">

        <a class="brand" href="<?= e(url()) ?>">

            u learn<span>.</span>

        </a>

        <nav aria-label="Student navigation">

            <p class="nav-label">Your workspace</p>

            <div class="nav">

                <a class="active" href="#overview">

                    ◫ &nbsp; Overview

                </a>

                <a href="#modules">

                    ▤ &nbsp; My Modules

                </a>

                <a href="<?= e(url('dashboard/explore.php')) ?>">

                    ⊕ &nbsp; Explore & Enroll

                </a>

                <a href="<?= e(url('dashboard/notifications.php')) ?>">

                    ♧ &nbsp; Notifications

                </a>

                <a href="<?= e(url('dashboard/notes-library.php')) ?>">▦ &nbsp; Notes Library</a>
                <a href="<?= e(url('dashboard/notes.php')) ?>">↥ &nbsp; Upload My Notes</a>
                <a href="<?= e(url('dashboard/kuppiya.php')) ?>">

                    ♧ &nbsp; Kuppiya Sessions

                </a>

                <a href="<?= e(url('dashboard/quiz-history.php')) ?>">

                    ◷ &nbsp; My Quiz History

                </a>

                <a href="<?= e(url('dashboard/analytics.php')) ?>">

                    ◉ &nbsp; Performance Analytics

                </a>

                <a href="<?= e(url('dashboard/topic-analysis.php')) ?>">

                    ◈ &nbsp; Topic Weaknesses

                </a>

                <a href="<?= e(url('dashboard/profile.php')) ?>">

                    ◉ &nbsp; My Profile &amp; Settings

                </a>

                <a href="#academic">

                    ◇ &nbsp; Academic Profile

                </a>

                <a href="#features">

                    ◎ &nbsp; Learning Tools

                </a>

            </div>

        </nav>

        <div class="sidebar-bottom">

            <small>Signed in as</small>

            <strong><?= e($u['full_name']) ?></strong>

            <small><?= e($u['email']) ?></small><small style="display:block;margin-top:12px">🏆 <?= $notePoints ?> contribution points</small>

        </div>

    </aside>

    <main class="main" id="overview">

        <header class="topbar">

            <span class="eyebrow">

                U LEARN / STUDENT SPACE

            </span>

            <div class="topbar-right">

                <a class="notification-bell" href="<?= e(url('dashboard/notifications.php')) ?>"

                   aria-label="Notifications: <?= $unreadNotifications ?> unread"

                   title="<?= $unreadNotifications ?> unread notifications">

                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg>

                    <?php if ($unreadNotifications > 0): ?>

                        <span class="notification-count" aria-hidden="true"><?= $unreadNotifications > 99 ? '99+' : $unreadNotifications ?></span>

                    <?php endif; ?>

                </a>

                <form method="post" action="<?= e(url('dashboard/index.php')) ?>" class="theme-toggle-form" id="themeToggleForm">

                    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">

                    <input type="hidden" name="action" value="theme">

                    <input type="hidden" name="theme" id="nextTheme" value="<?= $theme === 'dark' ? 'light' : 'dark' ?>">

                    <button type="submit" class="theme-toggle" id="themeToggle" title="Switch appearance" aria-label="Toggle dark mode">

                        <span class="theme-icon" id="themeIcon" aria-hidden="true">☾</span>

                    </button>

                </form>

                <a class="avatar" href="<?= e(url('dashboard/profile.php')) ?>" title="My Profile & Settings" aria-label="My Profile & Settings">

                    <?= e($initial) ?>

                </a>

                <form

                    method="post"

                    action="<?= e(url('auth/logout.php')) ?>"

                >

                    <input

                        type="hidden"

                        name="csrf"

                        value="<?= e(csrf()) ?>"

                    >

                    <button type="submit" class="logout">

                        Log out ↗

                    </button>

                </form>

            </div>

        </header>

        <section>

            <div class="eyebrow">

                YOUR LEARNING SPACE

            </div>

            <h1>

                Welcome back,

                <?= e($firstName) ?><span>.</span>

            </h1>

            <p class="lead">

                Your own space to discover modules,

                organize resources, and learn

                alongside your peers.

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

        <section id="academic">

            <div class="section-heading">

                <h2>Your academic identity</h2>

                <small>Personalized to your intake</small>

            </div>

            <div class="panel">

                <?php if ($profile): ?>

                    <div class="profile-summary">

                        <div>

                            <div class="eyebrow">

                                CURRENT ACADEMIC PROFILE

                            </div>

                            <h2>

                                <?= e($profile['degree_name']) ?>

                            </h2>

                            <p class="muted">

                                Your saved degree and intake

                            </p>

                        </div>

                        <span class="pill">

                            Batch <?= e($profile['batch_code']) ?>

                        </span>

                    </div>

                <?php else: ?>

                    <div class="eyebrow">

                        FIRST-TIME SETUP

                    </div>

                    <h2 style="margin:12px 0">

                        Let's set up your learning space.

                    </h2>

                    <p class="muted">

                        Select your degree and batch

                        to see the right modules.

                    </p>

                <?php endif; ?>

                <form method="post" action="#academic">

                    <input

                        type="hidden"

                        name="csrf"

                        value="<?= e(csrf()) ?>"

                    >

                    <input

                        type="hidden"

                        name="action"

                        value="academic"

                    >

                    <div class="fields">

                        <div>

                            <label for="degree_id">

                                Degree Programme

                            </label>

                            <select

                                id="degree_id"

                                name="degree_id"

                                required

                            >

                                <option value="">

                                    Choose your degree

                                </option>

                                <?php foreach ($degrees as $degree): ?>

                                    <option

                                        value="<?= (int)$degree['id'] ?>"

                                        <?= $profile &&

                                            (int)$profile['degree_id'] ===

                                            (int)$degree['id']

                                            ? 'selected' : '' ?>

                                    >

                                        <?= e($degree['name']) ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <div>

                            <label for="batch_id">

                                University Batch / Intake

                            </label>

                            <select

                                id="batch_id"

                                name="batch_id"

                                required

                            >

                                <option value="">

                                    Choose your batch

                                </option>

                                <?php foreach ($batches as $batch): ?>

                                    <option

                                        value="<?= (int)$batch['id'] ?>"

                                        <?= $profile &&

                                            (int)$profile['batch_id'] ===

                                            (int)$batch['id']

                                            ? 'selected' : '' ?>

                                    >

                                        <?= e($batch['batch_code']) ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                    </div>

                    <button

                        type="submit"

                        class="button"

                        <?= !$degrees || !$batches ? 'disabled' : '' ?>

                    >

                        <?= $profile

                            ? 'Update academic profile ↗'

                            : 'Save as my default ↗' ?>

                    </button>

                </form>

                <p class="muted"

                   style="font-size:12px;margin:18px 0 0">

                    Your selection is saved to your account.

                    You can change it later.

                </p>

            </div>

        </section>

        <section id="modules">

            <div class="section-heading">

                <h2>My Enrolled Modules</h2>

                <small><?= count($enrolledModules) ?> enrolled</small>

            </div>

            <p class="muted">Modules you chose to study, including modules outside your assigned curriculum.</p>

            <?php if (!$enrolledModules): ?>

                <div class="empty">

                    <h3>No voluntary enrollments yet.</h3>

                    <p>Explore available modules and enroll to add them here.</p>

                </div>

            <?php else: ?>

                <div class="module-grid">

                    <?php foreach ($enrolledModules as $enrolledModule): ?>

                        <article class="module-card">

                            <div class="module-icon">▤</div>

                            <div class="module-code"><?= e($enrolledModule['module_code']) ?></div>

                            <h3><a href="<?= e(url('dashboard/module.php?module_id=' . (int)$enrolledModule['module_id'])) ?>" style="color:inherit;text-decoration:none" title="Open module learning page"><?= e($enrolledModule['module_name']) ?> ↗</a></h3>

                            <p><?= e($enrolledModule['description'] ?: 'Your learning module.') ?></p>

                            <span class="pill">Enrolled ✓</span>

                            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:16px">

                                <a class="button" href="<?= e(url('dashboard/module.php?module_id=' . (int)$enrolledModule['module_id'])) ?>">Open Learning Page ↗</a>

                                <a class="button" href="<?= e(url('dashboard/analytics.php?module_id=' . (int)$enrolledModule['module_id'])) ?>">Performance ↗</a>

                                <a class="button" href="<?= e(url('dashboard/topic-analysis.php?module_id=' . (int)$enrolledModule['module_id'])) ?>">Topic weaknesses ↗</a>
                                    <a class="button" href="<?= e(url('dashboard/notes-library.php?module_id=' . (int)$enrolledModule['module_id'])) ?>">Study Notes ↗</a>

                            </div>

                        </article>

                    <?php endforeach; ?>

                </div>

            <?php endif; ?>

            <p style="margin-top:20px">

                <a class="button" href="<?= e(url('dashboard/explore.php')) ?>">Explore & Enroll in Modules ↗</a>

            </p>

        </section>

        <section id="curriculum">

            <div class="section-heading">

                <h2>My Modules</h2>

                <small>

                    <?= count($modules) ?>

                    <?= count($modules) === 1

                        ? 'module' : 'modules' ?>

                </small>

            </div>

            <?php if ($profile && !empty($allModules)): ?>

                <form method="get" action="<?= e(url('dashboard/index.php')) ?>" class="panel" style="margin-bottom:18px;padding:20px">

                    <div class="eyebrow">Filter your curriculum</div>

                    <div class="fields" style="margin:16px 0">

                        <div>

                            <label for="year">Academic year</label>

                            <select id="year" name="year" onchange="this.form.submit()">

                                <option value="">All academic years</option>

                                <?php foreach ($availableYears as $year): ?>

                                    <option value="<?= (int)$year ?>" <?= $selectedYear === $year ? 'selected' : '' ?>>Year <?= (int)$year ?></option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <div>

                            <label for="semester">Semester</label>

                            <select id="semester" name="semester" onchange="this.form.submit()">

                                <option value="">All semesters</option>

                                <?php foreach ($availableSemesters as $semester): ?>

                                    <option value="<?= (int)$semester ?>" <?= $selectedSemester === $semester ? 'selected' : '' ?>>Semester <?= (int)$semester ?></option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                    </div>

                    <a class="button" href="<?= e(url('dashboard/index.php')) ?>">Clear filters</a>

                </form>

            <?php endif; ?>

            <?php if (!$profile): ?>

                <div class="empty">

                    <h3>Your modules are waiting.</h3>

                    <p>

                        Choose your degree and batch

                        to load your academic curriculum.

                    </p>

                </div>

            <?php elseif (!$modules): ?>

                <div class="empty">

                    <h3>No modules assigned yet.</h3>

                    <p>

                        Your academic profile is saved.

                        An administrator needs to assign

                        modules to

                        <?= e($profile['degree_name']) ?>,

                        batch <?= e($profile['batch_code']) ?>.

                    </p>

                </div>

            <?php else: ?>

                <div class="module-grid">

                    <?php foreach ($modules as $module): ?>

                        <article class="module-card">

                            <div class="module-icon">

                                ▤

                            </div>

                            <div class="module-code">

                                <?= e($module['module_code']) ?>

                                · YEAR <?= (int)$module['study_year'] ?>

                                · SEMESTER <?= (int)$module['semester'] ?>

                            </div>

                            <h3>

                                <?= e($module['module_name']) ?>

                            </h3>

                            <p>

                                <?= e(

                                    $module['description']

                                    ?: 'Your academic module.'

                                ) ?>

                            </p>

                            <span class="pill">Curriculum module</span>

                            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:16px">

                                <a class="button" href="<?= e(url('dashboard/analytics.php?module_id=' . (int)$module['module_id'])) ?>">Performance ↗</a>

                                <a class="button" href="<?= e(url('dashboard/topic-analysis.php?module_id=' . (int)$module['module_id'])) ?>">Topic weaknesses ↗</a>
                                    <a class="button" href="<?= e(url('dashboard/notes-library.php?module_id=' . (int)$module['module_id'])) ?>">Study Notes ↗</a>

                            </div>

                        </article>

                    <?php endforeach; ?>

                </div>

            <?php endif; ?>

        </section>

        <section id="features">

            <div class="section-heading">

                <h2>Your learning toolkit</h2>

                <small>Practice, review, and keep learning</small>

            </div>

            <div class="feature-grid">

                <a class="feature" href="<?= e(url('dashboard/notes-library.php')) ?>" style="display:block;text-decoration:none;color:inherit">
                    <div class="symbol">▤</div>
                    <h3>Study Notes Library</h3>
                    <p>Browse approved notes, preview documents, download originals and rate resources.</p>
                    <span class="pill">Explore Notes ↗</span>
                </a>
                <a class="feature" href="<?= e(url('dashboard/notes.php')) ?>" style="display:block;text-decoration:none;color:inherit">
                    <div class="symbol">↥</div>
                    <h3>Upload &amp; Contribute</h3>
                    <p>Upload your study notes for admin approval and earn points when published.</p>
                    <span class="pill">Upload Notes ↗</span>
                </a>

                <a class="feature" href="<?= e(url('dashboard/kuppiya.php')) ?>" style="display:block;text-decoration:none;color:inherit">

                    <div class="symbol">♧</div>

                    <h3>Kuppiya Sessions</h3>

                    <p>Create a peer study group, discover upcoming sessions, or open private chats in My Kuppiyas.</p>

                    <span class="pill">Explore Kuppiyas ↗</span>

                </a>

                <a class="feature" href="<?= e(url('dashboard/quizzes.php')) ?>" style="display:block;text-decoration:none;color:inherit">

                    <div class="symbol">◇</div>

                    <h3>Quiz Arena</h3>

                    <p>

                        Practice module-based questions

                        and track real quiz results.

                    </p>

                    <span class="pill">Open Quiz Arena ↗</span>

                </a>

                                <a class="feature" href="<?= e(url('dashboard/quiz-history.php')) ?>" style="display:block;text-decoration:none;color:inherit">

                    <div class="symbol">◷</div>

                    <h3>My Quiz History</h3>

                    <p>Browse completed quizzes by module, check your scores, and review your answers.</p>

                    <span class="pill">View Quiz History ↗</span>

                </a>

<a class="feature"
   href="<?= e(url('dashboard/qa/index.php')) ?>"
   style="display:block;text-decoration:none;color:inherit">

    <div class="symbol">◎</div>

    <h3>Group Peer & Q&A</h3>

    <p>
        Connect with students, ask questions,
        share answers and learn together.
    </p>

    <span class="pill">Open Q&A ↗</span>

</a>

            </div>

        </section>

    </main>

</div>

</body>

</html>
