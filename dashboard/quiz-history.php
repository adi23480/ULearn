<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
$user = require_login();
if ((string)$user['role'] !== 'student') {
    http_response_code(403);
    exit('Student access only.');
}

$pdo = db();
$userId = (int)$user['id'];
$moduleId = filter_input(INPUT_GET, 'module_id', FILTER_VALIDATE_INT);
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
$page = ($page && $page > 0) ? min($page, 1000000) : 1;
$perPage = 12;
$error = '';
$modules = [];
$selectedModule = null;
$attempts = [];
$totalAttempts = 0;
$totalPages = 1;

try {
    // Historical attempts are independent of current enrollment, degree and batch.
    $stmt = $pdo->prepare("SELECT m.id AS module_id, m.module_code, m.module_name,
            COUNT(a.id) AS attempt_count, COUNT(DISTINCT q.id) AS quiz_count,
            MAX(a.submitted_at) AS last_completed
        FROM quiz_attempts a
        JOIN quizzes q ON q.id = a.quiz_id
        JOIN academic_modules m ON m.id = q.module_id
        WHERE a.user_id = ? AND a.status IN ('submitted', 'timed_out')
        GROUP BY m.id, m.module_code, m.module_name
        ORDER BY last_completed DESC, m.module_code ASC");
    $stmt->execute([$userId]);
    $modules = $stmt->fetchAll();

    if ($moduleId !== null && $moduleId !== false && $moduleId > 0) {
        foreach ($modules as $module) {
            if ((int)$module['module_id'] === (int)$moduleId) {
                $selectedModule = $module;
                break;
            }
        }
        if (!$selectedModule) {
            http_response_code(404);
            $error = 'No completed quiz history was found for this module.';
        } else {
            $totalAttempts = (int)$selectedModule['attempt_count'];
            $totalPages = max(1, (int)ceil($totalAttempts / $perPage));
            $page = min($page, $totalPages);
            $offset = ($page - 1) * $perPage;
            $stmt = $pdo->prepare("SELECT a.id, a.score, a.max_score, a.status,
                    a.submitted_at, q.title, q.quiz_type
                FROM quiz_attempts a
                JOIN quizzes q ON q.id = a.quiz_id
                WHERE a.user_id = :user_id AND q.module_id = :module_id
                    AND a.status IN ('submitted', 'timed_out')
                ORDER BY a.submitted_at DESC, a.id DESC
                LIMIT :limit OFFSET :offset");
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':module_id', (int)$moduleId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $attempts = $stmt->fetchAll();
        }
    }
} catch (Throwable $exception) {
    error_log('ULearn quiz history error: ' . $exception->getMessage());
    $error = 'Quiz history could not be loaded. Please try again.';
}

function historyScore($value): string {
    return rtrim(rtrim(number_format((float)$value, 2, '.', ''), '0'), '.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>My Quiz History — ULearn</title>
<meta name="theme-color" content="#0a7258">
<script>(function(){try{const saved=localStorage.getItem('ulearn-theme');const theme=saved==='light'||saved==='dark'?saved:(window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light');document.documentElement.dataset.theme=theme;}catch(e){}})();</script>
<style>
:root{color-scheme:light;--bg:#f7f8fc;--surface:#fff;--text:#171927;--muted:#777b90;--border:#e7e8f0;--accent:#6756e8;--soft:#f0edff}
:root[data-theme="dark"]{color-scheme:dark;--bg:#0c0e16;--surface:#161924;--text:#f4f4fa;--muted:#a0a4b6;--border:#2b2e3c;--accent:#a99aff;--soft:#292440}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif;line-height:1.55}a{color:inherit;text-decoration:none}.wrap{max-width:1050px;margin:0 auto;padding:32px 22px 70px}.top{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:32px}.eyebrow{font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);font-weight:800}.button{display:inline-flex;align-items:center;justify-content:center;padding:10px 15px;border:1px solid var(--border);border-radius:11px;background:var(--surface);font-weight:700;font-size:13px}.button.primary{background:var(--accent);border-color:var(--accent);color:#fff}.button:hover,.module:hover{border-color:var(--accent)}.hero{background:var(--surface);border:1px solid var(--border);border-radius:20px;padding:27px;margin-bottom:24px}h1{font-size:clamp(28px,4vw,42px);letter-spacing:-.04em;line-height:1.2;margin:8px 0}h2{font-size:19px;margin:0 0 8px}.muted{color:var(--muted)}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(265px,1fr));gap:15px}.module,.attempt,.empty{display:block;background:var(--surface);border:1px solid var(--border);border-radius:17px;padding:22px}.module{transition:border-color .15s}.code{font-size:12px;color:var(--accent);font-weight:800;letter-spacing:.06em}.pill{display:inline-block;padding:5px 10px;border-radius:99px;background:var(--soft);color:var(--accent);font-size:12px;font-weight:750}.stats{display:flex;gap:8px;flex-wrap:wrap;margin:16px 0}.attempts{display:grid;gap:12px}.attempt{display:flex;justify-content:space-between;align-items:center;gap:18px;flex-wrap:wrap}.attempt h2{margin:5px 0}.attempt-actions{display:flex;gap:8px;flex-wrap:wrap}.attempt .details{flex:1;min-width:200px}.score{font-size:22px;font-weight:800}.error{padding:14px 17px;border-radius:12px;background:#ffedf0;color:#a32143;margin-bottom:20px}.pager{display:flex;align-items:center;justify-content:center;gap:12px;flex-wrap:wrap;margin-top:25px}.pager .disabled{opacity:.45;pointer-events:none}.section-title{margin:28px 0 15px}@media(max-width:600px){.wrap{padding:20px 15px 55px}.hero{padding:20px}.attempt{align-items:flex-start}}
</style>


<link rel="stylesheet" href="../assets/css/ulearn-quiz-history-advanced.css?v=1">
<script defer src="../assets/js/ulearn-quiz-history-advanced.js?v=1"></script>
<script src="<?= htmlspecialchars(url('assets/js/theme-global.js?v=20261112-1'), ENT_QUOTES, 'UTF-8') ?>"></script>
</head>
<body class="qh-page">
<div class="qh-progress" aria-hidden="true"></div><div class="qh-page-wipe" aria-hidden="true"></div>
<main class="wrap">
<header class="top qh-top">
    <a class="qh-brand" href="index.php" aria-label="ULearn Dashboard"><img src="../assets/images/nsbm-shield-only.png" width="39" height="44" alt=""><span><b>u</b>Learn<i>.</i></span></a>
    <nav class="qh-topnav" aria-label="Main navigation"><a class="button" href="quizzes.php">✦ Quiz Arena</a><a class="button" href="index.php">← Dashboard</a><button type="button" class="button qh-theme" id="qh-theme" aria-label="Toggle dark mode"><span id="qh-theme-icon">☾</span> <span id="qh-theme-text">Dark mode</span></button></nav>
</header>
<?php if ($error !== ''): ?><div class="error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if ($selectedModule): ?>
    <section class="hero qh-hero">
        <a class="button" href="quiz-history.php">← All modules</a>
        <div class="eyebrow" style="margin-top:22px"><?= e((string)$selectedModule['module_code']) ?></div>
        <h1><?= e((string)$selectedModule['module_name']) ?></h1>
        <p class="muted">Your completed quiz attempts in this module. Choose one to review its answers and explanations.</p>
        <span class="pill"><?= $totalAttempts ?> completed <?= $totalAttempts === 1 ? 'attempt' : 'attempts' ?></span>
    </section>
    <div class="qh-detail-summary"><div><span class="qh-mini-icon">✓</span><strong><?= $totalAttempts ?></strong><span>Completed attempts in this module</span></div><div><span class="qh-mini-icon">▦</span><strong><?= (int)$selectedModule['quiz_count'] ?></strong><span>Different quizzes completed</span></div></div>
    <div class="qh-section-head"><div><div class="eyebrow">REVIEW YOUR JOURNEY</div><h2 class="section-title">Completed quizzes</h2></div><span class="pill">Page <?= $page ?> / <?= $totalPages ?></span></div>
    <div class="qh-tools"><label class="qh-search-label" for="qh-search"><span aria-hidden="true">⌕</span><input type="search" id="qh-search" placeholder="Find a quiz on this page..." autocomplete="off"></label><label class="qh-sort-label" for="qh-sort">Sort <select id="qh-sort"><option value="recent">Latest first</option><option value="score">Highest score %</option><option value="low-score">Lowest score %</option><option value="name">Quiz title</option></select></label><button class="button" type="button" id="qh-clear">Clear</button></div>
    <p class="qh-page-scope">Search and sorting apply to the <?= count($attempts) ?> attempts loaded on this page.</p>
    <div class="attempts qh-attempts" id="qh-attempts">
    <?php foreach ($attempts as $attempt): ?>
        <article class="attempt qh-attempt" data-search="<?= e(mb_strtolower((string)$attempt['title'].' '.(string)$attempt['quiz_type'])) ?>" data-name="<?= e(mb_strtolower((string)$attempt['title'])) ?>" data-score="<?= (float)$attempt['max_score'] > 0 ? round(100 * (float)$attempt['score'] / (float)$attempt['max_score'], 4) : 0 ?>" data-recent="<?= e((string)($attempt['submitted_at'] ?? '')) ?>">
            <div class="details">
                <div class="eyebrow"><?= e((string)($attempt['status'] === 'timed_out' ? 'Time expired' : 'Completed')) ?> · <?= e((string)ucfirst((string)$attempt['quiz_type'])) ?></div>
                <h2><?= e((string)$attempt['title']) ?></h2>
                <div class="muted" style="font-size:13px"><?= !empty($attempt['submitted_at']) ? e(date('d M Y, H:i', strtotime((string)$attempt['submitted_at']))) : 'Date unavailable' ?> · Attempt #<?= (int)$attempt['id'] ?></div>
            </div>
            <div class="qh-score-block"><div class="score"><?= e(historyScore($attempt['score'])) ?> <span>/ <?= e(historyScore($attempt['max_score'])) ?></span></div><div class="muted" style="font-size:12px">Score · <?= (float)$attempt['max_score'] > 0 ? e(historyScore(100 * (float)$attempt['score'] / (float)$attempt['max_score'])) . '%' : 'N/A' ?></div><div class="qh-score-track"><span style="--qh-score-width:<?= (float)$attempt['max_score'] > 0 ? max(0,min(100,100 * (float)$attempt['score'] / (float)$attempt['max_score'])) : 0 ?>%"></span></div></div>
            <div class="attempt-actions"><a class="button primary" href="learning-insights.php?attempt_id=<?= (int)$attempt['id'] ?>">Learning insights →</a><a class="button" href="quizzes.php?result=<?= (int)$attempt['id'] ?>">Review answers</a></div>
        </article>
    <?php endforeach; ?>
    </div><div class="qh-no-match" id="qh-no-match" hidden><strong>No matching quizzes</strong><p>Try a different title or clear the search.</p></div>
    <?php if ($totalPages > 1): ?>
    <nav class="pager" aria-label="Quiz history pages">
        <?php if ($page > 1): ?><a class="button" href="quiz-history.php?module_id=<?= (int)$moduleId ?>&amp;page=<?= $page - 1 ?>">← Previous</a><?php endif; ?>
        <span class="muted">Page <?= $page ?> of <?= $totalPages ?></span>
        <?php if ($page < $totalPages): ?><a class="button" href="quiz-history.php?module_id=<?= (int)$moduleId ?>&amp;page=<?= $page + 1 ?>">Next →</a><?php endif; ?>
    </nav>
    <?php endif; ?>
<?php else: ?>
    <section class="hero qh-hero"><div class="qh-hero-copy"><div class="eyebrow"><span class="qh-pulse"></span> YOUR LEARNING JOURNEY</div><h1>Every attempt.<br><span class="qh-gradient">Real progress.</span></h1><p class="muted">Track your completed quizzes across modules, revisit explanations and celebrate every step forward.</p><a class="button primary qh-hero-cta" href="quizzes.php">Continue learning <span aria-hidden="true">↗</span></a></div><div class="qh-hero-art" aria-hidden="true"><div class="qh-orbit qh-orbit-one"></div><div class="qh-orbit qh-orbit-two"></div><div class="qh-orb">★</div><div class="qh-floating qh-float-a">↗ Keep growing</div><div class="qh-floating qh-float-b">✓ Quiz completed</div></div></section>
<?php $allAttempts = array_sum(array_map(static fn(array $m): int => (int)$m['attempt_count'], $modules)); $allQuizzes = array_sum(array_map(static fn(array $m): int => (int)$m['quiz_count'], $modules)); ?>
<section class="qh-stats" aria-label="Learning activity overview"><div class="qh-stat"><span class="qh-stat-icon">▦</span><span class="qh-stat-value" data-count="<?= count($modules) ?>"><?= count($modules) ?></span><span class="qh-stat-label">Modules explored</span></div><div class="qh-stat"><span class="qh-stat-icon">✓</span><span class="qh-stat-value" data-count="<?= $allAttempts ?>"><?= $allAttempts ?></span><span class="qh-stat-label">Completed attempts</span></div><div class="qh-stat"><span class="qh-stat-icon">✦</span><span class="qh-stat-value" data-count="<?= $allQuizzes ?>"><?= $allQuizzes ?></span><span class="qh-stat-label">Module quiz counts*</span></div></section>
<p class="qh-stat-note">*Quiz counts are summed per module; they represent distinct quizzes within each module.</p>
    <?php if (!$modules && $error === ''): ?>
        <div class="empty"><h2>No completed quizzes yet</h2><p class="muted">Once you submit a quiz, its result will appear here under the relevant module.</p><a class="button primary" href="quizzes.php">Go to Quiz Arena →</a></div>
    <?php else: ?>
        <div class="qh-section-head"><div><div class="eyebrow">YOUR LEARNING LIBRARY</div><h2 class="section-title">Modules with completed quizzes</h2></div><span class="pill" id="qh-visible-count"><?= count($modules) ?> modules</span></div>
        <div class="qh-tools"><label class="qh-search-label" for="qh-search"><span aria-hidden="true">⌕</span><input type="search" id="qh-search" placeholder="Search module name or code..." autocomplete="off"></label><label class="qh-sort-label" for="qh-sort">Sort <select id="qh-sort"><option value="recent">Most recent</option><option value="name">Module name</option><option value="attempts">Most attempts</option><option value="quizzes">Most quizzes</option></select></label><button class="button" type="button" id="qh-clear">Clear</button></div>
        <div class="grid qh-module-grid" id="qh-module-grid">
        <?php foreach ($modules as $module): ?>
            <a class="module qh-module" href="quiz-history.php?module_id=<?= (int)$module['module_id'] ?>" data-search="<?= e(mb_strtolower((string)$module['module_code'].' '.(string)$module['module_name'])) ?>" data-name="<?= e(mb_strtolower((string)$module['module_name'])) ?>" data-attempts="<?= (int)$module['attempt_count'] ?>" data-quizzes="<?= (int)$module['quiz_count'] ?>" data-recent="<?= e((string)($module['last_completed'] ?? '')) ?>">
                <div class="qh-module-top"><div class="qh-module-icon" aria-hidden="true">▤</div><span class="qh-module-arrow" aria-hidden="true">↗</span></div><div class="code"><?= e((string)$module['module_code']) ?></div>
                <h2 style="margin-top:9px"><?= e((string)$module['module_name']) ?></h2>
                <div class="stats"><span class="pill"><?= (int)$module['quiz_count'] ?> <?= (int)$module['quiz_count'] === 1 ? 'quiz' : 'quizzes' ?></span><span class="pill"><?= (int)$module['attempt_count'] ?> <?= (int)$module['attempt_count'] === 1 ? 'attempt' : 'attempts' ?></span></div>
                <div class="muted" style="font-size:13px">Last completed: <?= !empty($module['last_completed']) ? e(date('d M Y', strtotime((string)$module['last_completed']))) : 'Unknown' ?></div>
                <div style="color:var(--accent);font-weight:750;margin-top:18px;font-size:13px">View completed quizzes →</div>
            </a>
        <?php endforeach; ?>
        </div><div class="qh-no-match" id="qh-no-match" hidden><strong>No matching modules</strong><p>Try another search term or clear the filters.</p></div>
    <?php endif; ?>
<?php endif; ?>
</main>
<?php require_once __DIR__ . '/../includes/student-bot.php'; ?></body>
</html>
