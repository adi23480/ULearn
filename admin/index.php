<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$u = require_login();

if (!in_array((string)$u['role'], ['admin', 'main_admin'], true)) {

    http_response_code(403);

    exit('Access denied. Admin permission required.');

}

$pdo = db();



// Personal unread notifications for the signed-in administrator.

$notificationStmt = $pdo->prepare(

    "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0"

);

$notificationStmt->execute([(int)$u['id']]);

$unreadNotifications = (int)$notificationStmt->fetchColumn();



function adminDashboardCount(PDO $pdo, string $sql): int

{

    try {

        return (int)$pdo->query($sql)->fetchColumn();

    } catch (PDOException $exception) {

        error_log('U Learn dashboard count error: ' . $exception->getMessage());

        return 0;

    }

}

$stats = [

    'students' => adminDashboardCount($pdo, "SELECT COUNT(*) FROM users WHERE role = 'student'"),

    'lecturers' => adminDashboardCount($pdo, "SELECT COUNT(*) FROM lecturer_profiles WHERE is_active = 1"),

    'modules' => adminDashboardCount($pdo, "SELECT COUNT(*) FROM academic_modules WHERE is_active = 1"),

    'batches' => adminDashboardCount($pdo, "SELECT COUNT(*) FROM academic_batches WHERE is_active = 1"),

    'degrees' => adminDashboardCount($pdo, "SELECT COUNT(*) FROM academic_degrees WHERE is_active = 1"),

    'assignments' => adminDashboardCount($pdo, "SELECT COUNT(*) FROM lecturer_module_assignments WHERE is_active = 1")

];

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

<title>Admin Workspace — U Learn</title>

<script>

(function () {

    const preference = <?= json_encode(

        $theme,

        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT

    ) ?>;

    let theme = preference;

    if (theme === 'system') {

        theme = window.matchMedia('(prefers-color-scheme: dark)').matches

            ? 'dark'

            : 'light';

    }

    document.documentElement.dataset.theme = theme;

    localStorage.setItem('ulearn-theme', theme);

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

* { box-sizing: border-box; }

html { scroll-behavior: smooth; }

body {

    margin: 0;

    background: var(--bg);

    color: var(--text);

    font-family: Inter, -apple-system, BlinkMacSystemFont,

                 "Segoe UI", Arial, sans-serif;

    line-height: 1.6;

}

a { color: inherit; text-decoration: none; }

button { font: inherit; cursor: pointer; }

.layout {

    display: grid;

    grid-template-columns: 250px minmax(0,1fr);

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

    gap: 13px;

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

.nav a.disabled {

    color: var(--muted);

    opacity: .48;

    cursor: default;

}

.nav-icon {

    width: 18px;

    height: 18px;

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

    padding: 34px clamp(20px,5vw,76px) 80px;

}

.topbar {

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 20px;

    margin-bottom: 56px;

}

.topbar-right {

    display: flex;

    align-items: center;

    gap: 18px;

}

.avatar {
    text-decoration: none;
    transition: transform .18s ease;

    width: 40px;

    height: 40px;

    border-radius: 50%;

    background: var(--accent-light);

    color: var(--accent);

    display: grid;

    place-items: center;

    font-weight: 800;

}

.avatar:hover { transform: translateY(-2px); }
.avatar:focus-visible { outline: 3px solid var(--accent); outline-offset: 3px; }
.logout {

    border: 0;

    background: transparent;

    color: var(--muted);

    font-size: 13px;

    font-weight: 650;

}

.logout:hover { color: var(--text); }

h1,h2,h3,p { margin-top: 0; }

h1 {

    font-size: clamp(36px,4vw,60px);

    line-height: 1.13;

    letter-spacing: -2.5px;

    margin: 16px 0;

}

h1 span { color: var(--accent); }

.lead {

    color: var(--muted);

    font-size: 16px;

    max-width: 660px;

}

.section-heading {

    display: flex;

    align-items: baseline;

    justify-content: space-between;

    gap: 15px;

    margin: 45px 0 20px;

}

.section-heading h2 {

    margin: 0;

    font-size: 23px;

    letter-spacing: -.7px;

}

.section-heading small { color: var(--muted); }

.hero-panel {

    display: flex;

    justify-content: space-between;

    align-items: center;

    gap: 25px;

    background: var(--surface);

    border: 1px solid var(--border);

    border-radius: 20px;

    padding: 28px 32px;

}

.hero-panel h2 {

    margin: 10px 0 8px;

    font-size: 22px;

    letter-spacing: -.7px;

}

.hero-panel p {

    color: var(--muted);

    font-size: 13px;

    margin-bottom: 0;

    max-width: 520px;

}

.pill {

    display: inline-flex;

    align-items: center;

    padding: 8px 14px;

    border-radius: 100px;

    background: var(--accent-light);

    color: var(--accent);

    font-size: 12px;

    font-weight: 750;

    white-space: nowrap;

}

.stats-grid {

    display: grid;

    grid-template-columns: repeat(3,minmax(0,1fr));

    gap: 16px;

}

.stat-card {

    background: var(--surface);

    border: 1px solid var(--border);

    border-radius: 18px;

    padding: 24px;

    min-height: 154px;

}

.stat-top {

    display: flex;

    justify-content: space-between;

    align-items: center;

    gap: 12px;

}

.stat-label {

    color: var(--muted);

    font-size: 13px;

    font-weight: 650;

}

.icon-box {

    width: 38px;

    height: 38px;

    border-radius: 11px;

    background: var(--accent-light);

    color: var(--accent);

    display: grid;

    place-items: center;

    flex-shrink: 0;

}

.icon-box svg {

    width: 19px;

    height: 19px;

}

.stat-number {

    font-size: 36px;

    font-weight: 800;

    letter-spacing: -1.5px;

    line-height: 1.2;

    margin-top: 17px;

}

.stat-description {

    color: var(--muted);

    font-size: 12px;

    margin-top: 5px;

}

.tools-grid {

    display: grid;

    grid-template-columns: repeat(2,minmax(0,1fr));

    gap: 16px;

}

.tool-card {

    display: flex;

    flex-direction: column;

    align-items: flex-start;

    background: var(--surface);

    border: 1px solid var(--border);

    border-radius: 18px;

    padding: 27px;

    min-height: 215px;

    transition: border-color .2s, transform .2s;

}

a.tool-card:hover {

    border-color: var(--accent);

    transform: translateY(-2px);

}

.tool-card h3 {

    font-size: 18px;

    letter-spacing: -.5px;

    margin: 20px 0 8px;

}

.tool-card p {

    font-size: 13px;

    color: var(--muted);

    margin-bottom: 20px;

    max-width: 390px;

}

.tool-footer {

    margin-top: auto;

    font-size: 12px;

    font-weight: 750;

    color: var(--accent);

}

.tool-card.soon {

    opacity: .65;

}

.tool-card.soon .tool-footer {

    color: var(--muted);

}

.activity-panel {

    background: var(--surface);

    border: 1px solid var(--border);

    border-radius: 18px;

    padding: 28px;

}

.activity-panel h3 {

    font-size: 17px;

    margin-bottom: 8px;

}

.activity-panel p {

    color: var(--muted);

    font-size: 13px;

    margin-bottom: 0;

}

.theme-toggle {

    border: 1px solid var(--border);

    background: var(--surface);

    color: var(--text);

    border-radius: 11px;

    width: 38px;

    height: 38px;

    display: grid;

    place-items: center;

}

/* Stage 8: notification bell and standardized theme control. */

.theme-toggle { width:42px; height:42px; border-radius:12px; font-size:20px; }

.theme-toggle:hover { background: var(--surface-alt); }

.notification-bell { position:relative; display:grid; place-items:center; width:42px; height:42px;

    border:1px solid var(--border); border-radius:12px; background:var(--surface);

    color:var(--text); transition:transform .18s ease, background .18s ease, border-color .18s ease; }

.notification-bell:hover { background:var(--accent-light); border-color:var(--accent); transform:translateY(-2px); }

.notification-bell:focus-visible { outline:3px solid var(--accent); outline-offset:3px; }

.notification-bell svg { width:21px; height:21px; fill:none; stroke:currentColor; stroke-width:1.8; stroke-linecap:round; stroke-linejoin:round; }

.notification-count { position:absolute; top:-7px; right:-7px; min-width:19px; height:19px;

    padding:0 4px; display:grid; place-items:center; border-radius:20px; background:#e34b76;

    color:white; font-size:10px; font-weight:800; border:2px solid var(--surface); }



.footer {

    margin-top: 50px;

    padding-top: 20px;

    border-top: 1px solid var(--border);

    font-size: 12px;

    color: var(--muted);

}

@media (max-width: 1000px) {

    .stats-grid { grid-template-columns: repeat(2,minmax(0,1fr)); }

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

    .stats-grid, .tools-grid { grid-template-columns: 1fr; }

    .hero-panel { flex-direction: column; align-items: flex-start; }

    .topbar { flex-wrap: wrap; }

    h1 { letter-spacing: -1.5px; }

}

.stage9-management{margin:12px 0 22px;padding:12px;border:1px solid var(--border, #35364c);border-radius:14px}.stage9-management summary{cursor:pointer;font-weight:750;padding:6px;color:var(--text,#eee)}.stage9-management a{display:block;padding:11px 9px;margin-top:4px;border-radius:9px;color:var(--muted,#aaa);font-size:13px}.stage9-management a:hover{background:var(--surface-alt,#29283c);color:var(--accent,#a99cff)}
</style>


<script src="<?= htmlspecialchars(url('assets/js/theme-global.js?v=20261107-2'), ENT_QUOTES, 'UTF-8') ?>"></script>
<style id="ulearn-admin-feature-contrast-fix">
.tool-card.soon{opacity:1!important;filter:none!important}
.tool-card.soon h3{color:var(--text)!important}
.tool-card.soon p{color:var(--muted)!important}
.tool-card.soon .tool-footer{opacity:1!important;color:var(--muted)!important}
</style>
<link rel="stylesheet" href="<?= e(url('assets/css/admin-premium.css?v=3')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/admin-unified-v1.css?v=1')) ?>">
</head>

<body>

<!-- SVG icon library -->

<svg xmlns="http\\://www.w3.org/2000/svg"

     style="position:absolute;width:0;height:0;overflow:hidden"

     aria-hidden="true">

    <symbol id="i-grid" viewBox="0 0 24 24">

        <rect x="3" y="3" width="7" height="7" rx="1"/>

        <rect x="14" y="3" width="7" height="7" rx="1"/>

        <rect x="3" y="14" width="7" height="7" rx="1"/>

        <rect x="14" y="14" width="7" height="7" rx="1"/>

    </symbol>

    <symbol id="i-users" viewBox="0 0 24 24">

        <circle cx="9" cy="8" r="3"/>

        <path d="M3 20v-2a6 6 0 0 1 12 0v2"/>

        <path d="M17 5a3 3 0 0 1 0 6M18 15a5 5 0 0 1 3 5"/>

    </symbol>

    <symbol id="i-book" viewBox="0 0 24 24">

        <path d="M4 4h13a3 3 0 0 1 3 3v13H7a3 3 0 0 1-3-3z"/>

        <path d="M4 17a3 3 0 0 1 3-3h13M8 8h8"/>

    </symbol>

    <symbol id="i-calendar" viewBox="0 0 24 24">

        <rect x="3" y="5" width="18" height="16" rx="2"/>

        <path d="M7 3v4M17 3v4M3 10h18"/>

    </symbol>

    <symbol id="i-cap" viewBox="0 0 24 24">

        <path d="m2 9 10-5 10 5-10 5z"/>

        <path d="M6 12v5c4 3 8 3 12 0v-5M22 9v7"/>

    </symbol>

    <symbol id="i-link" viewBox="0 0 24 24">

        <path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-2 2"/>

        <path d="M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l2-2"/>

    </symbol>

    <symbol id="i-clock" viewBox="0 0 24 24">

        <circle cx="12" cy="12" r="9"/>

        <path d="M12 7v5l3 2"/>

    </symbol>

    <symbol id="i-settings" viewBox="0 0 24 24">

        <circle cx="12" cy="12" r="3"/>

        <path d="M12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M19 5l-2 2M7 17l-2 2"/>

    </symbol>

</svg>

<div class="layout">

    <aside class="sidebar">

        <?php require __DIR__ . '/../includes/brand-logo.php'; ?>

        <nav aria-label="Admin navigation">
            <details open class="stage9-management"><summary>⚙ System Management</summary><a href="<?= e(url('admin/users.php')) ?>">◉ All Registered Users</a><a href="<?= e(url('admin/change-requests.php')) ?>">◇ Academic Change Requests</a></details>


            <p class="nav-label">Your workspace</p>

            <div class="nav">

                <a class="active" href="<?= e(url('admin/index.php')) ?>">

                    <svg class="nav-icon" fill="none" stroke="currentColor"

                         stroke-width="1.8">

                        <use href="#i-grid"/>

                    </svg>

                    Overview

                </a>

                <a href="<?= e(url('admin/lecturers.php')) ?>">

                    <svg class="nav-icon" fill="none" stroke="currentColor"

                         stroke-width="1.8">

                        <use href="#i-users"/>

                    </svg>

                    Lecturers

                </a>

                <a href="<?= e(url('admin/academics.php')) ?>">

                    <svg class="nav-icon" fill="none" stroke="currentColor"

                         stroke-width="1.8">

                        <use href="#i-book"/>

                    </svg>

                    Academics

                </a>
                
               <a href="<?= e(url('admin/universities.php')) ?>">
                     <svg class="nav-icon" fill="none"
                         stroke="currentColor" stroke-width="1.8">
                       <use href="#i-cap"/>
                     </svg>
                   Universities
                </a>


                <a href="<?= e(url('admin/quizzes.php')) ?>">

                    <svg class="nav-icon" fill="none" stroke="currentColor" stroke-width="1.8">

                        <use href="#i-book"/>

                    </svg>

                    Quizzes

                </a>

                <a href="<?= e(url('admin/assignments.php')) ?>">

                    <svg class="nav-icon" fill="none" stroke="currentColor"

                         stroke-width="1.8">

                        <use href="#i-link"/>

                    </svg>

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



                <a href="<?= e(url('admin/consultations.php')) ?>"><svg class="nav-icon" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><use href="#i-calendar"/></svg> Consultations</a>

                <a href="<?= e(url('admin/kuppiya.php')) ?>"><svg class="nav-icon" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><use href="#i-users"/></svg> Kuppiya Sessions</a>

            </div>

        </nav>

        <div class="sidebar-bottom">

            <small>Signed in as</small>

            <strong><?= e($fullName) ?></strong>

            <small><?= e((string)$u['email']) ?></small>

        </div>

    </aside>

    <main class="main" id="overview">

        <header class="topbar">

            <span class="eyebrow">

                U LEARN / ADMIN SPACE

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

                <form method="post"

                      action="<?= e(url('auth/logout.php')) ?>">

                    <input type="hidden"

                           name="csrf"

                           value="<?= e(csrf()) ?>">

                    <button type="submit" class="logout">

                        Log out ↗

                    </button>

                </form>

            </div>

        </header>

        <section>

            <div class="eyebrow">

                YOUR ADMINISTRATION SPACE

            </div>

            <h1>

                Welcome back, <?= e($firstName) ?><span>.</span>

            </h1>

            <p class="lead">

                Your workspace to manage academic programmes,

                support lecturers, and build a better learning

                experience for everyone.

            </p>

        </section>

        <section style="margin-top:38px">

            <div class="hero-panel">

                <div>

                    <div class="eyebrow">PLATFORM CONTROL</div>

                    <h2>U Learn Administration</h2>

                    <p>

                        Manage the people, academic structure, and

                        learning resources that power your platform.

                    </p>

                </div>

                <span class="pill">

                    <?= e($roleLabel) ?>

                </span>

            </div>

        </section>

        <section id="overview-stats">

            <div class="section-heading">

                <h2>Platform overview</h2>

                <small>Live platform statistics</small>

            </div>

            <?php

            $statItems = [

                ['Students', $stats['students'], 'users', 'Registered student accounts'],

                ['Lecturers', $stats['lecturers'], 'users', 'Active teaching staff'],

                ['Modules', $stats['modules'], 'book', 'Active academic modules'],

                ['Degrees', $stats['degrees'], 'cap', 'Degree programmes'],

                ['Batches', $stats['batches'], 'calendar', 'Active university intakes'],

                ['Assignments', $stats['assignments'], 'link', 'Active lecturer assignments']

            ];

            ?>

            <div class="stats-grid">

                <?php foreach ($statItems as $item): ?>

                    <article class="stat-card">

                        <div class="stat-top">

                            <span class="stat-label">

                                <?= e($item[0]) ?>

                            </span>

                            <div class="icon-box">

                                <svg fill="none" stroke="currentColor"

                                     stroke-width="1.7"

                                     stroke-linecap="round"

                                     stroke-linejoin="round">

                                    <use href="#i-<?= e($item[2]) ?>"/>

                                </svg>

                            </div>

                        </div>

                        <div class="stat-number">

                            <?= number_format((int)$item[1]) ?>

                        </div>

                        <div class="stat-description">

                            <?= e($item[3]) ?>

                        </div>

                    </article>

                <?php endforeach; ?>

            </div>

        </section>

        <section id="management">

            <div class="section-heading">

                <h2>Management workspace</h2>

                <small>Your administration tools</small>

            </div>

            <div class="tools-grid">

                <a class="tool-card"

                   href="<?= e(url('admin/lecturers.php')) ?>">

                    <div class="icon-box">

                        <svg fill="none" stroke="currentColor"

                             stroke-width="1.7">

                            <use href="#i-users"/>

                        </svg>

                    </div>

                    <h3>Lecturer Management</h3>

                    <p>

                        Review lecturer accounts, approve teaching staff,

                        and manage lecturer access to U Learn.

                    </p>

                    <span class="tool-footer">

                        Open lecturer management ↗

                    </span>

                </a>

                <a class="tool-card" href="<?= e(url('admin/academics.php')) ?>">

                    <div class="icon-box">

                        <svg fill="none" stroke="currentColor"

                             stroke-width="1.7">

                            <use href="#i-cap"/>

                        </svg>

                    </div>

                    <h3>Academic Management</h3>

                    <p>

                        Organize degrees, batches, modules,

                        and the academic curriculum.

                    </p>

                    <span class="tool-footer">

                        Open academic management ↗

                    </span>

                </a>
                         
<a class="tool-card"
   href="<?= e(url('admin/universities.php')) ?>">

    <div class="icon-box">
        <svg fill="none" stroke="currentColor"
             stroke-width="1.7">
            <use href="#i-cap"/>
        </svg>
    </div>

    <h3>University Management</h3>

    <p>
        Add and manage awarding universities
        for ULearn degree programmes.
    </p>

    <span class="tool-footer">
        Open university management ↗
    </span>
</a>


                <a class="tool-card" href="<?= e(url('admin/quizzes.php')) ?>">

                    <div class="icon-box">

                        <svg fill="none" stroke="currentColor" stroke-width="1.7">

                            <use href="#i-book"/>

                        </svg>

                    </div>

                    <h3>Quiz Management</h3>

                    <p>Create and manage module quizzes, questions, imports, and publication.</p>

                    <span class="tool-footer">Open quiz management ↗</span>

                </a>

                <a class="tool-card" href="<?= e(url('admin/assignments.php')) ?>">

                    <div class="icon-box">

                        <svg fill="none" stroke="currentColor"

                             stroke-width="1.7">

                            <use href="#i-link"/>

                        </svg>

                    </div>

                    <h3>Lecturer Assignments</h3>

                    <p>

                        Connect lecturers to their assigned modules,

                        degree programmes, and batches.

                    </p>

                    <span class="tool-footer">

                        Open lecturer assignments ↗

                    </span>

                </a>

                <a class="tool-card" href="<?= e(url('admin/announcements.php')) ?>">

                    <div class="icon-box"><svg fill="none" stroke="currentColor" stroke-width="1.7"><use href="#i-book"/></svg></div>

                    <h3>Announcements</h3>

                    <p>Create, publish, and manage university announcements for students, lecturers, and admins.</p>

                    <span class="tool-footer">Manage announcements ↗</span>

                </a>



                <a class="tool-card" href="<?= e(url('admin/consultations.php')) ?>"><div class="icon-box"><svg fill="none" stroke="currentColor" stroke-width="1.7"><use href="#i-calendar"/></svg></div><h3>Consultation Management</h3><p>Review consultation requests, statuses, schedules and lecturer assignments.</p><span class="tool-footer">Open Consultation Management ↗</span></a>

                <a class="tool-card" href="<?= e(url('admin/kuppiya.php')) ?>"><div class="icon-box"><svg fill="none" stroke="currentColor" stroke-width="1.7"><use href="#i-users"/></svg></div><h3>Kuppiya Sessions</h3><p>Monitor peer-led sessions, hosts, participants and schedules.</p><span class="tool-footer">Open Kuppiya Sessions ↗</span></a>

                <a class="tool-card" href="<?= e(url('admin/platform-settings.php')) ?>">
                    <div class="icon-box"><svg fill="none" stroke="currentColor" stroke-width="1.7"><use href="#i-settings"/></svg></div>
                    <h3>Platform Settings</h3>
                    <p>Review platform configuration, manage your appearance and access administration tools.</p>
                    <span class="tool-footer">Open Platform Settings ↗</span>
                </a>

            </div>

        </section>

        <section>

            <div class="section-heading">

                <h2>Platform activity</h2>

                <small>System status</small>

            </div>

            <div class="activity-panel">

                <h3>Your workspace is ready.</h3>

                <p>

                    Lecturer management is available.

                    Academic management, consultation requests,

                    and Kuppiya Sessions will be connected as

                    we build the remaining modules.

                </p>

            </div>

        </section>

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
