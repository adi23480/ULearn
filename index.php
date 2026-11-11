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
        $error = 'Academic identity is set at registration. Contact an administrator to request changes.';
    } elseif (false && $action === 'academic') {

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

            b.batch_code,
            au.name AS university_name, ap.study_year AS current_year, ap.semester AS current_semester

     FROM student_academic_profiles p

     JOIN academic_degrees d ON d.id = p.degree_id

     JOIN academic_batches b ON b.id = p.batch_id
     LEFT JOIN awarding_universities au ON au.id=d.awarding_university_id
     LEFT JOIN academic_intake_progression ap ON ap.degree_id=p.degree_id AND ap.batch_id=p.batch_id

     WHERE p.user_id = ?"

);

$stmt->execute([$userId]);

$profile = $stmt->fetch() ?: null;

$modules = [];

$availableYears = [];

$availableSemesters = [];

$selectedYear = $profile ? (int)($profile['current_year'] ?? 1) : null;

$selectedSemester = $profile ? (int)($profile['current_semester'] ?? 1) : null;

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


<script src="<?= htmlspecialchars(url('assets/js/theme-global.js?v=20261107-2'), ENT_QUOTES, 'UTF-8') ?>"></script>
<link rel="stylesheet" href="<?= e(url('assets/css/ulearn-design-system.css?v=1')) ?>">
<script defer src="<?= e(url('assets/js/ulearn-motion.js?v=1')) ?>"></script>
<link rel="stylesheet" href="<?= e(url('assets/css/ulearn-student-shell.css?v=2')) ?>">
<script defer src="<?= e(url('assets/js/ulearn-student-shell.js?v=2')) ?>"></script>
<link rel="stylesheet" href="<?= e(url('assets/css/ulearn-dashboard-v2.css?v=501')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/ulearn-dashboard-v5.css?v=511')) ?>">
<script defer src="<?= e(url('assets/js/ulearn-dashboard-v5.js?v=511')) ?>"></script>
<link rel="stylesheet" href="<?= e(url('assets/css/ulearn-dashboard-v51.css?v=511')) ?>">
<script>
    alert("ULearn TEST - NEW CODE LOADED");
</script>
</head>

<body>

<div class="layout">

    <aside class="sidebar" id="studentSidebar">

        <?php require __DIR__ . '/includes/brand-logo.php'; ?>

        <nav aria-label="Student navigation">

            <p class="nav-label">Your workspace</p>

<div class="nav ul-nav">
 <a class="active" href="#overview"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/></svg><span>Overview</span></a>
 <a href="#modules"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 7v14M3 18V4a1 1 0 0 1 1-1h5a3 3 0 0 1 3 3 3 3 0 0 1 3-3h5a1 1 0 0 1 1 1v14h-6a3 3 0 0 0-3 3 3 3 0 0 0-3-3z"/></svg><span>My Modules</span></a>
 <a href="<?= e(url('dashboard/explore.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="m16 8-2 6-6 2 2-6z"/></svg><span>Explore & Enroll</span></a>
 <a href="<?= e(url('dashboard/notes-library.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="8" y="7" width="12" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h2"/></svg><span>Notes Library</span></a>
 <a href="<?= e(url('dashboard/notes.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V8m-4 4 4-4 4 4M4 17a5 5 0 0 1 1-9 7 7 0 0 1 13 0 5 5 0 0 1 2 9"/></svg><span>Upload Notes</span></a>
 <a href="<?= e(url('dashboard/quiz-history.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-7M3 3v6h6M12 7v5l3 2"/></svg><span>Quiz History</span></a>
 <a href="<?= e(url('dashboard/learning-insights.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="m3 17 6-6 4 4 8-8M15 7h6v6"/></svg><span>Learning Insights</span></a>
 <a href="<?= e(url('dashboard/analytics.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20V12h4v8M10 20V4h4v16M16 20v-9h4v9M2 20h20"/></svg><span>Performance Analytics</span></a>
 <a href="<?= e(url('dashboard/topic-analysis.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/></svg><span>Topic Weaknesses</span></a>
 <a href="<?= e(url('dashboard/ai-study.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="m12 3 1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8zM19 17l.7 1.3L21 19l-1.3.7L19 21l-.7-1.3L17 19l1.3-.7z"/></svg><span>AI Study Assistant</span></a>
 <a href="<?= e(url('dashboard/consultations.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M20 11V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v10l4-3h11a2 2 0 0 0 2-1zM9 16v3a2 2 0 0 0 2 2h8l3 2v-9"/></svg><span>Consultations</span></a>
 <a href="<?= e(url('dashboard/kuppiya.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3"/><path d="M3 20v-2a6 6 0 0 1 12 0v2M17 5a3 3 0 0 1 0 6M17 15a5 5 0 0 1 4 5"/></svg><span>Kuppiya Sessions</span></a>
 <a href="<?= e(url('dashboard/leaderboard.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M7 4h10v7a5 5 0 0 1-10 0zM7 6H4v3a4 4 0 0 0 4 4m9-7h3v3a4 4 0 0 1-4 4M12 16v4m-4 1h8"/></svg><span>Leaderboard</span></a>
 <a href="<?= e(url('dashboard/notifications.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg><span>Notifications</span></a>
 <a href="<?= e(url('dashboard/profile.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M4 17h16"/><circle cx="8" cy="7" r="2"/><circle cx="16" cy="17" r="2"/></svg><span>My Profile & Settings</span></a>
</div>

        </nav>

        <div class="sidebar-bottom">

            <small>Signed in as</small>

            <strong><?= e($u['full_name']) ?></strong>

            <small><?= e($u['email']) ?></small><small style="display:block;margin-top:12px"><?= $notePoints ?> contribution points</small>

        </div>

    </aside>

    <main class="main" id="overview">

        <header class="topbar">
            <button type="button" class="ul-menu-toggle" id="ulMenuToggle" aria-expanded="false" aria-controls="studentSidebar" aria-label="Toggle navigation"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button>

            <span class="eyebrow">

                STUDENT WORKSPACE <span class="ul-breadcrumb-separator">/</span> OVERVIEW

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

        <section class="ul-welcome ul-hero-v2" aria-label="Welcome">
            <div class="ul-hero-content">
                <div class="ul-hero-kicker"><span class="ul-live-dot" aria-hidden="true"></span> YOUR PERSONAL LEARNING SPACE</div>
                <p class="ul-hero-pretitle">A better way to learn, every day.</p>
                <h1>Welcome back,<br><span><?= e($firstName) ?>.</span></h1>
                <p class="lead">Your academic journey, learning resources and next steps — all in one place.</p>
                <div class="ul-quick-actions">
                    <a class="ul-quick primary" href="<?= e(url('dashboard/explore.php')) ?>">Explore modules <span aria-hidden="true">→</span></a>
                    <a class="ul-quick" href="<?= e(url('dashboard/quiz-history.php')) ?>">Quiz history <span aria-hidden="true">→</span></a>
                </div>
            </div>
            <div class="ul-hero-visual" aria-hidden="true"><div class="ul-hero-visual-label">DISCOVER · PRACTICE · GROW</div></div>
        </section>

        <section class="ul-overview-grid" aria-label="Learning overview">
            <a class="ul-overview-tile" href="#modules">
                <span class="ul-tile-label">YOUR LEARNING</span>
                <strong><?= count($enrolledModules) ?></strong>
                <span class="ul-tile-bottom">Enrolled modules <span aria-hidden="true">↗</span></span>
            </a>
            <a class="ul-overview-tile" href="#curriculum">
                <span class="ul-tile-label">ACADEMIC PATH</span>
                <strong><?= count($modules) ?></strong>
                <span class="ul-tile-bottom">Curriculum modules <span aria-hidden="true">↗</span></span>
            </a>
            <a class="ul-overview-tile" href="<?= e(url('dashboard/notes-library.php')) ?>">
                <span class="ul-tile-label">YOUR CONTRIBUTION</span>
                <strong><?= $notePoints ?></strong>
                <span class="ul-tile-bottom">Note points <span aria-hidden="true">↗</span></span>
            </a>
            <a class="ul-overview-tile" href="<?= e(url('dashboard/notifications.php')) ?>">
                <span class="ul-tile-label">STAY CONNECTED</span>
                <strong><?= $unreadNotifications ?></strong>
                <span class="ul-tile-bottom">Unread notifications <span aria-hidden="true">↗</span></span>
            </a>
        </section>

        <section class="ul-launchpad" aria-label="Quick access">
            <div class="ul-launchpad-head"><div><div class="eyebrow">QUICK ACCESS</div><h2>Jump back into learning</h2></div><a href="<?= e(url('dashboard/explore.php')) ?>">Browse modules →</a></div>
            <div class="ul-launchpad-grid">
                <a href="<?= e(url('dashboard/quizzes.php')) ?>"><span class="ul-launchpad-mark">◇</span><strong>Quiz Arena</strong><small>Practice what you've learned</small><span class="ul-launchpad-arrow" aria-hidden="true">↗</span></a>
                <a href="<?= e(url('dashboard/notes-library.php')) ?>"><span class="ul-launchpad-mark">▤</span><strong>Notes Library</strong><small>Explore study resources</small><span class="ul-launchpad-arrow" aria-hidden="true">↗</span></a>
                <a href="<?= e(url('dashboard/learning-insights.php')) ?>"><span class="ul-launchpad-mark">↗</span><strong>Learning Insights</strong><small>Understand your progress</small><span class="ul-launchpad-arrow" aria-hidden="true">↗</span></a>
                <a href="<?= e(url('dashboard/ai-study.php')) ?>"><span class="ul-launchpad-mark">✧</span><strong>AI Study Assistant</strong><small>Get help when you need it</small><span class="ul-launchpad-arrow" aria-hidden="true">↗</span></a>
            </div>
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

        <section class="ul-identity" aria-label="Student identity">
            <div><div class="eyebrow">STUDENT IDENTITY</div><strong><?= e($u['full_name']) ?></strong>
                <p><?= $profile ? e(($profile['university_name'] ?? '').' · '.$profile['degree_name'].' · Intake '.$profile['batch_code']) : 'Academic profile not configured' ?></p>
            </div>
            <div class="ul-identity-meta"><span><?= e((string)($u['student_number'] ?: 'Student ID pending')) ?></span>
                <?php if ($profile): ?><span>Year <?= (int)($profile['current_year'] ?? 1) ?> · Semester <?= (int)($profile['current_semester'] ?? 1) ?></span><?php endif; ?>
            </div>
            <?php if(empty($u['email_verified_at'])): ?><p class="ul-verification">Email verification pending. <a href="<?= e(url('auth/verify.php')) ?>">Verify your email</a>.</p><?php endif; ?>
        </section>
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

                <div class="ul-academic-note">
                    <strong>Academic identity is protected</strong>
                    <p>Your degree and intake are managed by the university. Contact an administrator if any details need correcting.</p>
                    <a class="ul-quick" href="<?= e(url('dashboard/profile.php')) ?>">View my profile →</a>
                </div>
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

<a class="feature" href="<?= e(url('dashboard/group-peer/index.php')) ?>" style="display:block;text-decoration:none;color:inherit;cursor:pointer" aria-label="Open Group Peer and Q&amp;A">

                    <div class="symbol">◎</div>

                    <h3>Group Peer & Q&A</h3>

                    <p>

                        Connect with students and discuss

                        difficult topics.

                    </p>

                </a>

            </div>

        </section>

    </main>

</div>

<script src="<?= e(url('assets/js/notifications-live.js')) ?>" data-base="<?= e(rtrim(url(), '/')) ?>" defer></script>
</body>

</html>
