<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
$user = require_login();
if ((string)$user['role'] !== 'student') { http_response_code(403); exit('Student access only.'); }
$pdo = db();
$me = (int)$user['id'];
$period = (($_GET['period'] ?? '') === 'week') ? 'week' : 'all';
$when = $period === 'week' ? 'AND t.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)' : '';
$rows=[]; $podium=[]; $myRank=null; $myScore=0; $myQuiz=0; $myNotes=0; $history=[]; $error='';
$search=trim((string)($_GET['q'] ?? '')); $search=mb_substr($search,0,80);
$page=max(1,min(2000,(int)($_GET['page'] ?? 1))); $perPage=25; $total=0; $pages=1;
try {
  // Aggregate once per request. Rank is assigned BEFORE search and pagination.
  $quizWhere=$period==='week' ? 'WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)' : '';
  $notesJoin=$period==='week' ? '' : 'LEFT JOIN (SELECT user_id,SUM(points) note_xp FROM student_note_points GROUP BY user_id) n ON n.user_id=u.id';
  $notesValue=$period==='week' ? '0' : 'COALESCE(n.note_xp,0)';
  $base="SELECT u.id,u.full_name,COALESCE(s.quiz_xp,0) quiz_xp,$notesValue note_xp,
     COALESCE(s.quiz_xp,0)+$notesValue total_xp FROM users u
     LEFT JOIN (SELECT student_id,SUM(points) quiz_xp FROM score_transactions $quizWhere GROUP BY student_id) s ON s.student_id=u.id
     $notesJoin WHERE u.role='student'";
  $ranked="SELECT ROW_NUMBER() OVER (ORDER BY total_xp DESC,full_name ASC,id ASC) rank_position, ranked_base.* FROM ($base) ranked_base";
  $countStmt=$pdo->prepare("SELECT COUNT(*) FROM ($ranked) r WHERE r.full_name LIKE ?");
  $countStmt->execute(['%'.$search.'%']); $total=(int)$countStmt->fetchColumn();
  $pages=max(1,(int)ceil($total/$perPage)); $page=min($page,$pages);
  $listStmt=$pdo->prepare("SELECT * FROM ($ranked) r WHERE r.full_name LIKE ? ORDER BY rank_position LIMIT $perPage OFFSET ".(($page-1)*$perPage));
  $listStmt->execute(['%'.$search.'%']); $rows=$listStmt->fetchAll(PDO::FETCH_ASSOC);
  $podium=$pdo->query("SELECT * FROM ($ranked) r WHERE rank_position<=3 ORDER BY rank_position")->fetchAll(PDO::FETCH_ASSOC);
  $meStmt=$pdo->prepare("SELECT * FROM ($ranked) r WHERE r.id=? LIMIT 1");
  $meStmt->execute([$me]); $self=$meStmt->fetch(PDO::FETCH_ASSOC);
  if($self){$myRank=(int)$self['rank_position'];$myScore=(int)$self['total_xp'];$myQuiz=(int)$self['quiz_xp'];$myNotes=(int)$self['note_xp'];}
  $allStudents=(int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='student'")->fetchColumn();
  $stmt=$pdo->prepare("SELECT activity_type,points,description,created_at FROM score_transactions WHERE student_id=? ORDER BY created_at DESC,id DESC LIMIT 8");
  $stmt->execute([$me]); $history=$stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(Throwable $e){error_log('ULearn RankUp: '.$e->getMessage());$error='Leaderboard could not load. Check the scoring migration and database tables.';}
function esc($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function rankUrl(string $period,string $search,int $page):string{return '?'.http_build_query(['period'=>$period,'q'=>$search,'page'=>$page]);}

// Count unread notifications for badge consistency with index
$unreadNotificationsStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0'
);
$unreadNotificationsStmt->execute([$me]);
$unreadNotifications = (int)$unreadNotificationsStmt->fetchColumn();

$notePointsStmt = $pdo->prepare('SELECT COALESCE(SUM(points), 0) FROM student_note_points WHERE user_id = ?');
$notePointsStmt->execute([$me]);
$notePoints = (int)$notePointsStmt->fetchColumn();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Leaderboard — U Learn</title>
<script>
    try {
        const saved = localStorage.getItem('ulearn-theme') || 'system';
        const theme = saved === 'system'
            ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
            : saved;
        document.documentElement.dataset.theme = theme;
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
            --green: #087e60;
            --soft: #e7f7ef;
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
            display: flex;
            align-items: center;
            gap: 12px;
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
        .ul-nav-icon {
            width: 20px;
            height: 20px;
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
            padding: 34px clamp(20px, 5vw, 76px) 80px;
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
            cursor: pointer;
        }
        h1, h2, h3, p {
            margin-top: 0;
        }
        .hero{border-radius:24px;padding:32px;background:linear-gradient(115deg,#063e39,#087d62 70%,#12677b);color:white;margin-bottom:20px}.hero h1{font-size:clamp(28px,4vw,44px);margin:6px 0}.hero p{opacity:.88}
        .grid{display:grid;grid-template-columns:1.1fr 1.9fr;gap:18px}.card{background:var(--surface);border:1px solid var(--border);border-radius:20px;padding:28px;margin-bottom:18px;box-shadow:var(--shadow)}
        .muted{color:var(--muted)}.big{font-size:48px;font-weight:850;letter-spacing:-2px}.stat{display:flex;justify-content:space-between;border-top:1px solid var(--border);padding:12px 0}
        .tabs{display:flex;gap:9px;margin-bottom:18px;flex-wrap:wrap}.tabs a{padding:10px 15px;border:1px solid var(--border);border-radius:12px;text-decoration:none;font-weight:700;background:var(--surface)}.tabs a.active{background:var(--accent);color:#fff;border-color:var(--accent)}
        .person{display:grid;grid-template-columns:42px minmax(0,1fr) auto;align-items:center;gap:12px;border-top:1px solid var(--border);padding:13px 0}.person:first-of-type{border-top:0}
        .rank{border-radius:12px;background:var(--accent-light);color:var(--accent);text-align:center;padding:9px;font-weight:850}.name{font-weight:700;overflow-wrap:anywhere}.you{background:var(--accent-light);margin:0 -10px;padding:13px 10px;border-radius:12px}.xp{font-weight:850;font-variant-numeric:tabular-nums}.badge{font-size:12px;background:var(--accent-light);color:var(--accent);border-radius:99px;padding:4px 9px}
        .timeline{border-top:1px solid var(--border);padding:10px 0}.error{border:1px solid #e9a4a4;padding:15px;border-radius:14px;background:#ffedf0;color:#a32143}
        .podium{margin-bottom:20px}.podium h2{font-size:20px;margin:0 0 12px}.podium-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.podium-card{background:var(--surface);border:1px solid var(--border);border-radius:18px;padding:20px 14px;display:flex;flex-direction:column;gap:8px;align-items:center;text-align:center;min-width:0;overflow-wrap:anywhere}.podium-card strong{font-size:16px}.podium-card span{color:var(--muted);font-weight:700}.podium-medal{font-weight:900;font-size:26px;color:var(--accent)}.podium-1{border-color:#e6b84b;box-shadow:0 8px 28px #e6b84b22}.podium-1 .podium-medal{color:#b98a21}.podium-2 .podium-medal{color:#8394a8}.podium-3 .podium-medal{color:#b77c57}
        .rank-search{display:flex;gap:8px;margin:15px 0 8px}.rank-search input{flex:1;min-width:0;padding:12px 14px;background:var(--surface-alt);color:var(--text);border:1px solid var(--border);border-radius:12px;font:inherit}.rank-search button{background:var(--text);color:var(--bg);border:0;border-radius:12px;padding:10px 18px;font-weight:750;cursor:pointer}.pager{display:flex;align-items:center;justify-content:space-between;gap:8px;padding-top:20px;flex-wrap:wrap}.pager a{border:1px solid var(--border);border-radius:10px;padding:9px 12px;text-decoration:none;font-weight:700;background:var(--surface)}.pager a.disabled{opacity:.35;pointer-events:none}.sr-only{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)}
        
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
        @media(max-width:550px){.podium-grid{gap:6px}.podium-card{padding:12px 6px}.podium-card strong{font-size:12px}.podium-card span{font-size:11px}.rank-search{flex-wrap:wrap}.rank-search button{width:100%}.person{grid-template-columns:40px minmax(0,1fr) auto}.xp{font-size:12px}}
</style>
<script src="<?= htmlspecialchars(url('assets/js/theme-global.js?v=20261107-2'), ENT_QUOTES, 'UTF-8') ?>"></script>
<link rel="stylesheet" href="<?= e(url('assets/css/ulearn-design-system.css?v=1')) ?>">
<script defer src="<?= e(url('assets/js/ulearn-motion.js?v=1')) ?>"></script>
<link rel="stylesheet" href="<?= e(url('assets/css/ulearn-student-shell.css?v=2')) ?>">
<script defer src="<?= e(url('assets/js/ulearn-student-shell.js?v=2')) ?>"></script>
</head>
<body>

<div class="layout">

    <aside class="sidebar" id="studentSidebar">

        <a class="brand" href="<?= e(url()) ?>">

            u learn<span>.</span>

        </a>

        <nav aria-label="Student navigation">

            <p class="nav-label">Your workspace</p>

            <div class="nav ul-nav">
                <a href="<?= e(url('dashboard/index.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/></svg><span>Overview</span></a>
                <a href="<?= e(url('dashboard/index.php#modules')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 7v14M3 18V4a1 1 0 0 1 1-1h5a3 3 0 0 1 3 3 3 3 0 0 1 3-3h5a1 1 0 0 1 1 1v14h-6a3 3 0 0 0-3 3 3 3 0 0 0-3-3z"/></svg><span>My Modules</span></a>
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
                <a class="active" href="<?= e(url('dashboard/leaderboard.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M7 4h10v7a5 5 0 0 1-10 0zM7 6H4v3a4 4 0 0 0 4 4m9-7h3v3a4 4 0 0 1-4 4M12 16v4m-4 1h8"/></svg><span>Leaderboard</span></a>
                <a href="<?= e(url('dashboard/notifications.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg><span>Notifications</span></a>
                <a href="<?= e(url('dashboard/profile.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M4 17h16"/><circle cx="8" cy="7" r="2"/><circle cx="16" cy="17" r="2"/></svg><span>My Profile & Settings</span></a>
            </div>

        </nav>

        <div class="sidebar-bottom">

            <small>Signed in as</small>

            <strong><?= esc($user['full_name']) ?></strong>

            <small><?= esc($user['email'] ?? '') ?></small>
            <small style="display:block;margin-top:12px"><?= $notePoints ?> contribution points</small>

        </div>

    </aside>

    <main class="main">

        <header class="topbar">
            <button type="button" class="ul-menu-toggle" id="ulMenuToggle" aria-expanded="false" aria-controls="studentSidebar" aria-label="Toggle navigation"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button>

            <span class="eyebrow">
                U LEARN / LEADERBOARD
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

                <a class="avatar" href="<?= e(url('dashboard/profile.php')) ?>" title="My Profile & Settings" aria-label="My Profile & Settings">
                    <?= strtoupper(substr(trim($user['full_name'] ?? 'S'), 0, 1)) ?>
                </a>

                <form method="post" action="<?= e(url('auth/logout.php')) ?>">
                    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                    <button type="submit" class="logout">
                        Log out ↗
                    </button>
                </form>
            </div>
        </header>

        <header class="hero">
            <div>ULearn · Learning rewards</div>
            <h1>Student Leaderboard</h1>
            <p>Earn XP by completing quizzes and sharing approved notes. Your effort counts.</p>
        </header>

        <?php if($error): ?>
            <p class="error"><?=esc($error)?></p>
        <?php else: ?>
            <div class="tabs">
                <a class="<?=$period==='all'?'active':''?>" href="<?=esc(rankUrl('all',$search,1))?>">All-time</a>
                <a class="<?=$period==='week'?'active':''?>" href="<?=esc(rankUrl('week',$search,1))?>">Last 7 days · Quiz XP</a>
                <span class="muted" style="margin:auto 0 auto auto">Updates every 30 seconds</span>
            </div>

            <section class="podium" aria-label="Top three learners">
                <h2>Top Learners</h2>
                <div class="podium-grid">
                    <?php foreach($podium as $p): ?>
                        <div class="podium-card podium-<?=esc($p['rank_position'])?>">
                            <div class="podium-medal"><?=esc('#'.$p['rank_position'])?></div>
                            <strong><?=esc($p['full_name'])?></strong>
                            <span><?=number_format((int)$p['total_xp'])?> XP</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <div class="grid">
                <section>
                    <div class="card">
                        <span class="badge">Your progress</span>
                        <h2>Your Rank</h2>
                        <div class="big"><?=$myRank===null?'—':'#'.number_format($myRank)?></div>
                        <p class="muted">Among <?=number_format($allStudents)?> registered students</p>
                        <div class="stat"><span>Total XP</span><strong><?=number_format($myScore)?></strong></div>
                        <div class="stat"><span>Quiz XP</span><strong><?=number_format($myQuiz)?></strong></div>
                        <?php if($period==='all'):?>
                            <div class="stat"><span>Notes contribution</span><strong><?=number_format($myNotes)?></strong></div>
                        <?php endif;?>
                    </div>
                    
                    <div class="card">
                        <h3>Recent XP</h3>
                        <?php if(!$history):?>
                            <p class="muted">Complete a quiz to start earning XP.</p>
                        <?php endif;?>
                        <?php foreach($history as $h):?>
                            <div class="timeline">
                                <strong>+<?=esc($h['points'])?> XP</strong>
                                <div><?=esc($h['description'] ?: str_replace('_',' ',(string)$h['activity_type']))?></div>
                                <small class="muted"><?=esc($h['created_at'])?></small>
                            </div>
                        <?php endforeach;?>
                    </div>
                </section>

                <section>
                    <div class="card">
                        <div class="list-head">
                            <div>
                                <h2>All Student Rankings</h2>
                                <p class="muted"><?=number_format($total)?> matching students · 25 per page</p>
                            </div>
                        </div>

                        <form method="get" class="rank-search">
                            <input type="hidden" name="period" value="<?=esc($period)?>">
                            <label class="sr-only" for="rank-search">Search students by name</label>
                            <input id="rank-search" type="search" name="q" value="<?=esc($search)?>" placeholder="Search students by name…" maxlength="80">
                            <button type="submit">Search</button>
                        </form>

                        <?php if(!$rows):?>
                            <p class="muted">No students match your search.</p>
                        <?php endif;?>

                        <?php foreach($rows as $r):?>
                            <div class="person <?=((int)$r['id']===$me)?'you':''?>">
                                <div class="rank">#<?=number_format((int)$r['rank_position'])?></div>
                                <div>
                                    <div class="name"><?=esc($r['full_name'])?> <?=((int)$r['id']===$me)?'<span class="badge">You</span>':''?></div>
                                    <small class="muted">Quiz <?=number_format((int)$r['quiz_xp'])?><?php if($period==='all'):?> · Notes <?=number_format((int)$r['note_xp'])?><?php endif;?></small>
                                </div>
                                <div class="xp"><?=number_format((int)$r['total_xp'])?> XP</div>
                            </div>
                        <?php endforeach;?>

                        <div class="pager">
                            <a class="<?=$page<=1?'disabled':''?>" href="<?=esc(rankUrl($period,$search,max(1,$page-1)))?>" aria-label="Previous ranking page">← Previous</a>
                            <span>Page <?=number_format($page)?> of <?=number_format($pages)?></span>
                            <a class="<?=$page>=$pages?'disabled':''?>" href="<?=esc(rankUrl($period,$search,min($pages,$page+1)))?>" aria-label="Next ranking page">Next →</a>
                        </div>
                    </div>
                </section>
            </div>

            <script>
                document.addEventListener('visibilitychange', () => { if (!document.hidden) window.location.reload() });
                setInterval(() => { if (!document.hidden) window.location.reload() }, 30000);
            </script>
        <?php endif;?>

    </main>
</div>

</body>
</html>