<?php

declare(strict_types=1);


require_once __DIR__ . '/../config/bootstrap.php';


$user = require_login();



// Only lecturers can access this dashboard.

if ($user['role'] !== 'lecturer') {

    http_response_code(403);

    exit('Access denied. Lecturer account required.');

}



$pdo = db();

$userId = (int)$user['id'];



// Personal unread notifications for the signed-in lecturer.

$unreadStmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');

$unreadStmt->execute([$userId]);

$unreadNotifications = (int)$unreadStmt->fetchColumn();



// Get lecturer profile.

$stmt = $pdo->prepare("

    SELECT id, title, department, designation, is_active

    FROM lecturer_profiles

    WHERE user_id = ?

    LIMIT 1

");

$stmt->execute([$userId]);

$lecturer = $stmt->fetch();



if (!$lecturer || (int)$lecturer['is_active'] !== 1) {

    http_response_code(403);

    exit('Your lecturer profile is not active. Contact an administrator.');

}



// Get assigned modules and academic groups.

$stmt = $pdo->prepare("

    SELECT

        m.module_code,

        m.module_name,

        d.name AS degree_name,

        b.batch_code,

        c.study_year,

        c.semester

    FROM lecturer_module_assignments lma

    INNER JOIN academic_curriculum c

        ON c.id = lma.curriculum_id

    INNER JOIN academic_modules m

        ON m.id = c.module_id

    INNER JOIN academic_degrees d

        ON d.id = c.degree_id

    INNER JOIN academic_batches b

        ON b.id = c.batch_id

    WHERE lma.lecturer_id = ?

      AND lma.is_active = 1

      AND c.is_active = 1

      AND m.is_active = 1

    ORDER BY c.study_year, c.semester, m.module_name

");

$stmt->execute([(int)$lecturer['id']]);

$modules = $stmt->fetchAll();

// Teaching groups exist independently of module assignments.
$groupStmt = $pdo->prepare("
    SELECT lda.id AS group_id, d.name AS degree_name, b.batch_code,
           lda.study_year, lda.semester
    FROM lecturer_degree_assignments lda
    INNER JOIN academic_degrees d ON d.id = lda.degree_id
    INNER JOIN academic_batches b ON b.id = lda.batch_id
    WHERE lda.lecturer_id = ? AND lda.is_active = 1
    ORDER BY d.name, b.batch_code, lda.study_year, lda.semester
");
$groupStmt->execute([(int)$lecturer['id']]);
$teachingGroups = $groupStmt->fetchAll(PDO::FETCH_ASSOC);




$fullName = (string)$user['full_name'];

$theme = (string)($user['theme'] ?? 'light');



if (!in_array($theme, ['light', 'dark', 'system'], true)) {

    $theme = 'light';

}

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Lecturer Dashboard | U Learn</title>



    <style>

        :root {

            --bg: #f7f8fc;

            --card: #ffffff;

            --text: #1b1d2c;

            --muted: #777b90;

            --border: #e7e8ef;

            --accent: #6856e8;

            --soft: #f0edff;

        }



        [data-theme="dark"] {

            --bg: #10121c;

            --card: #1b1e2b;

            --text: #f5f5fa;

            --muted: #a2a6b8;

            --border: #303344;

            --accent: #b1a4ff;

            --soft: #2c2745;

        }



        * {

            box-sizing: border-box;

        }



        body {

            margin: 0;

            font-family: Inter, -apple-system, BlinkMacSystemFont,

                         "Segoe UI", sans-serif;

            background: var(--bg);

            color: var(--text);

        }



        .layout {

            display: grid;

            grid-template-columns: 245px 1fr;

            min-height: 100vh;

        }



        .sidebar {

            background: var(--card);

            border-right: 1px solid var(--border);

            padding: 30px 20px;

        }



        .brand {

            font-size: 27px;

            font-weight: 800;

            letter-spacing: -1.5px;

            margin-bottom: 50px;

        }



        .brand span {

            color: var(--accent);

        }



        .nav-label {

            color: var(--muted);

            font-size: 11px;

            font-weight: 700;

            letter-spacing: 1.5px;

            margin-bottom: 15px;

        }



        .nav a {

            display: block;

            padding: 13px 15px;

            margin-bottom: 7px;

            border-radius: 12px;

            text-decoration: none;

            color: var(--text);

            font-size: 14px;

        }



        .nav a:hover,

        .nav a.active {

            background: var(--soft);

            color: var(--accent);

        }



        .main {

            padding: 35px clamp(20px, 5vw, 75px);

            min-width: 0;

        }



        .topbar {

            display: flex;

            justify-content: space-between;

            align-items: center;

            gap: 15px;

            margin-bottom: 65px;

        }



        .topbar-right {

            display: flex;

            align-items: center;

            gap: 14px;

        }



        .theme-button {

            width: 42px;

            height: 42px;

            border: 1px solid var(--border);

            border-radius: 12px;

            background: var(--card);

            color: var(--text);

            cursor: pointer;

            font-size: 20px;

        }



        .notification-button {

            position: relative;

            width: 42px;

            height: 42px;

            display: inline-grid;

            place-items: center;

            border: 1px solid var(--border);

            border-radius: 12px;

            background: var(--card);

            color: var(--text);

            text-decoration: none;

            transition: transform .18s ease, border-color .18s ease, background .18s ease;

        }

        .notification-button:hover, .theme-button:hover {

            transform: translateY(-2px);

            border-color: var(--accent);

            background: var(--soft);

        }

        .notification-button:focus-visible, .theme-button:focus-visible {

            outline: 3px solid var(--accent);

            outline-offset: 3px;

        }

        .notification-button svg {

            width: 21px;

            height: 21px;

            fill: none;

            stroke: currentColor;

            stroke-width: 1.9;

            stroke-linecap: round;

            stroke-linejoin: round;

        }

        .notification-count {

            position: absolute;

            top: -7px;

            right: -8px;

            min-width: 19px;

            height: 19px;

            padding: 0 4px;

            display: grid;

            place-items: center;

            border-radius: 999px;

            background: #e84d79;

            color: white;

            border: 2px solid var(--card);

            font-size: 10px;

            font-weight: 800;

            line-height: 1;

        }

        @media (prefers-reduced-motion: reduce) {

            .notification-button { transition: none; }

        }



        .avatar {

            width: 42px;

            height: 42px;

            border-radius: 50%;

            background: var(--soft);

            color: var(--accent);

            display: grid;

            place-items: center;

            font-weight: 800;
            text-decoration: none;
            transition: transform .18s ease, border-color .18s ease;

        }

        .avatar:hover { transform: translateY(-2px); outline: 1px solid var(--accent); }
        .avatar:focus-visible { outline: 3px solid var(--accent); outline-offset: 3px; }

        h1 {

            font-size: clamp(34px, 4vw, 54px);

            letter-spacing: -2px;

            margin: 12px 0;

        }



        h1 span {

            color: var(--accent);

        }



        .eyebrow {

            color: var(--muted);

            font-size: 11px;

            letter-spacing: 1.6px;

            font-weight: 750;

        }



        .subtitle {

            color: var(--muted);

            max-width: 600px;

            line-height: 1.7;

        }



        .stats {

            display: grid;

            grid-template-columns: repeat(3, 1fr);

            gap: 17px;

            margin: 40px 0;

        }



        .card {

            background: var(--card);

            border: 1px solid var(--border);

            border-radius: 19px;

            padding: 26px;

        }



        .stat-number {

            font-size: 35px;

            font-weight: 800;

            margin: 8px 0;

        }



        .stat-label {

            color: var(--muted);

            font-size: 13px;

        }



        .section-title {

            font-size: 23px;

            letter-spacing: -.6px;

            margin: 45px 0 20px;

        }



        .module-grid {

            display: grid;

            grid-template-columns: repeat(2, 1fr);

            gap: 17px;

        }



        .module-code {

            color: var(--accent);

            font-size: 12px;

            font-weight: 750;

        }



        .module-name {

            font-size: 19px;

            font-weight: 700;

            margin: 13px 0;

        }



        .module-meta {

            color: var(--muted);

            font-size: 13px;

            line-height: 1.8;

        }



        .empty {

            background: var(--card);

            border: 1px dashed var(--border);

            border-radius: 18px;

            padding: 35px;

            color: var(--muted);

        }



        .logout {

            border: none;

            background: transparent;

            color: var(--muted);

            cursor: pointer;

            font-size: 13px;

        }



        @media (max-width: 850px) {

            .layout {

                grid-template-columns: 1fr;

            }



            .sidebar {

                border-right: none;

                border-bottom: 1px solid var(--border);

            }



            .brand {

                margin-bottom: 20px;

            }



            .main {

                padding: 25px 20px;

            }



            .stats,

            .module-grid {

                grid-template-columns: 1fr;

            }

        }

    </style>



    <script>

        const savedTheme = <?= json_encode($theme) ?>;



        function preferredTheme() {

            if (savedTheme === 'dark' || savedTheme === 'light') {

                return savedTheme;

            }



            return window.matchMedia(

                '(prefers-color-scheme: dark)'

            ).matches ? 'dark' : 'light';

        }



        let currentTheme = localStorage.getItem('ulearn-theme');



        if (currentTheme !== 'dark' && currentTheme !== 'light') {

            currentTheme = preferredTheme();

        }



        document.documentElement.dataset.theme = currentTheme;



        function toggleTheme() {

            currentTheme = document.documentElement.dataset.theme

                === 'dark' ? 'light' : 'dark';



            document.documentElement.dataset.theme = currentTheme;

            localStorage.setItem('ulearn-theme', currentTheme);

            updateThemeButton();

        }



        function updateThemeButton() {

            const button = document.getElementById('themeButton');



            if (button) {

                button.textContent =

                    currentTheme === 'dark' ? '☀' : '☾';



                button.setAttribute(

                    'aria-label',

                    currentTheme === 'dark'

                        ? 'Switch to light mode'

                        : 'Switch to dark mode'

                );

            }

        }



        document.addEventListener('DOMContentLoaded', updateThemeButton);

    </script>


<script src="<?= htmlspecialchars(url('assets/js/theme-global.js?v=20261107-2'), ENT_QUOTES, 'UTF-8') ?>"></script>
<link rel="stylesheet" href="<?= e(url('assets/css/lecturer-premium-v5.css?v=1')) ?>">
<script defer src="<?= e(url('assets/js/lecturer-premium-v5.js?v=1')) ?>"></script>
</head>



<body>



<div class="layout">



    <aside class="sidebar">



        <div class="brand">

            u learn<span>.</span>

        </div>



        <div class="nav-label">LECTURER WORKSPACE</div>



        <nav class="nav">

            <a class="active" href="#overview">◫ Overview</a>

            <a href="#modules">▤ My Modules</a>

            <a href="<?= e(url('lecturer/materials.php')) ?>">▣ Study Materials</a>

            <a href="<?= e(url('lecturer/quizzes.php')) ?>">☷ Quiz Management</a>
            <a href="<?= e(url('lecturer/gemini-test.php')) ?>" style="margin-left:18px;font-size:12px">✦ Generate quiz with AI</a>

            <a href="<?= e(url('lecturer/consultations.php')) ?>">◷ Consultations</a>

            <a href="<?= e(url('dashboard/notifications.php')) ?>">♧ Notifications</a>

                <a href="<?= e(url('lecturer/profile.php')) ?>">⚙ &nbsp; My Profile &amp; Settings</a>

        </nav>



    </aside>



    <main class="main" id="overview">



        <header class="topbar">



            <div class="eyebrow">

                U LEARN / LECTURER PORTAL

            </div>



            <div class="topbar-right">

                <a class="notification-button" href="<?= e(url('dashboard/notifications.php')) ?>"

                   aria-label="Notifications<?= $unreadNotifications ? ': ' . $unreadNotifications . ' unread' : '' ?>"

                   title="Open Notification Center">

                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>

                    <?php if ($unreadNotifications > 0): ?>

                        <span class="notification-count" aria-hidden="true"><?= $unreadNotifications > 99 ? '99+' : $unreadNotifications ?></span>

                    <?php endif; ?>

                </a>



                <button

                    id="themeButton"

                    class="theme-button"

                    type="button"

                    onclick="toggleTheme()"

                    aria-label="Toggle appearance"

                    title="Change theme"

                >☾</button>



                <a class="avatar" href="<?= e(url('lecturer/profile.php')) ?>" title="My Profile &amp; Settings" aria-label="Open My Profile &amp; Settings">

                    <?= e(strtoupper(substr($fullName, 0, 1))) ?>

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



                    <button class="logout" type="submit">

                        Log out

                    </button>

                </form>



            </div>

        </header>



        <div class="eyebrow">YOUR TEACHING WORKSPACE</div>



        <h1>

            Welcome, <?= e($lecturer['title']) ?>

            <?= e($fullName) ?><span>.</span>

        </h1>



        <p class="subtitle">

            Manage your assigned modules, study materials, and quizzes,

            and connect with students from one place.

        </p>



        <nav class="ul-workspace-actions" aria-label="Quick lecturer actions"><a href="<?= e(url('lecturer/quizzes.php')) ?>">Create or manage quizzes →</a><a href="<?= e(url('lecturer/materials.php')) ?>">Study materials</a><a href="<?= e(url('lecturer/performance.php')) ?>">Student performance</a><a href="<?= e(url('lecturer/consultations.php')) ?>">Consultations</a></nav>
<section class="stats">



            <div class="card">

                <div class="eyebrow">ASSIGNED MODULES</div>

                <div class="stat-number">

                    <?= count($modules) ?>

                </div>

                <div class="stat-label">

                    Active teaching assignments

                </div>

            </div>



            <div class="card">

                <div class="eyebrow">CONSULTATION REQUESTS</div>

                <div class="stat-number">—</div>

                <div class="stat-label">

                    Available when booking is enabled

                </div>

            </div>



            <div class="card">

                <div class="eyebrow">NOTIFICATIONS</div>

                <div class="stat-number"><?= $unreadNotifications ?></div>

                <div class="stat-label">

                    <a href="<?= e(url('dashboard/notifications.php')) ?>" style="color:var(--accent)">Unread notifications ↗</a>

                </div>

            </div>



        </section>



        <section id="teaching-groups">
    <h2 class="section-title">My Teaching Groups</h2>
    <?php if (!$teachingGroups): ?>
        <div class="empty">No programme or intake teaching groups are currently assigned. Contact your administrator.</div>
    <?php else: ?>
        <div class="module-grid">
            <?php foreach ($teachingGroups as $group): ?>
                <a class="card" href="<?= e(url('lecturer/teaching-group.php')) ?>?id=<?= (int)$group['group_id'] ?>" style="display:block;text-decoration:none;color:var(--text);cursor:pointer;transition:transform .18s ease,border-color .18s ease" onmouseover="this.style.transform='translateY(-3px)'" onmouseout="this.style.transform=''">
                    <div class="module-name"><?= e($group['degree_name']) ?></div>
                    <div class="module-meta">
                        Intake <?= e($group['batch_code']) ?><br>
                        Year <?= (int)$group['study_year'] ?> ·
                        <?= (int)$group['semester'] === 0 ? 'All semesters' : 'Semester ' . (int)$group['semester'] ?>
                    </div>
                    <div style="margin-top:15px;color:var(--accent);font-weight:700">Open teaching group →</div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section id="modules">



            <h2 class="section-title">My Assigned Modules</h2>



            <?php if (!$modules): ?>



                <div class="empty">

                    No modules assigned yet.

                    An administrator will assign your

                    modules and academic batches.

                </div>



            <?php else: ?>



                <div class="module-grid">



                    <?php foreach ($modules as $module): ?>



                        <article class="card">



                            <div class="module-code">

                                <?= e($module['module_code']) ?>

                            </div>



                            <div class="module-name">

                                <?= e($module['module_name']) ?>

                            </div>



                            <div class="module-meta">

                                <?= e($module['degree_name']) ?><br>

                                Batch <?= e($module['batch_code']) ?><br>

                                Year <?= (int)$module['study_year'] ?>

                                · Semester <?= (int)$module['semester'] ?>

                            </div>



                        </article>



                    <?php endforeach; ?>



                </div>



            <?php endif; ?>



        </section>



        <section id="consultations">

            <h2 class="section-title">Consultation Requests</h2>



            <div class="empty">

                Students will be able to request consultation

                sessions for modules you teach.



                <br><br>



                Upcoming functionality:

                accept, decline, or suggest another time.

            </div>

        </section>



        <section id="notifications">

            <h2 class="section-title">Notifications</h2>



            <div class="empty">

                View your personal notifications and university announcements

                in the <a href="<?= e(url('dashboard/notifications.php')) ?>" style="color:var(--accent)">Notification Center ↗</a>.

                Automatic activity alerts will be connected in the next stage.

            </div>

        </section>



    </main>



</div>



    <script src="<?= e(url('assets/js/scroll-position.js')) ?>" defer></script>
<script src="<?= e(url('assets/js/notifications-live.js')) ?>" data-base="<?= e(rtrim(url(), '/')) ?>" defer></script>
</body>

</html>
