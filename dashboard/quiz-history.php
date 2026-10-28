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
<script>(function(){try{const saved=localStorage.getItem('ulearn-theme');const theme=saved==='light'||saved==='dark'?saved:(window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light');document.documentElement.dataset.theme=theme;}catch(e){}})();</script>
<style>
:root{color-scheme:light;--bg:#f7f8fc;--surface:#fff;--text:#171927;--muted:#777b90;--border:#e7e8f0;--accent:#6756e8;--soft:#f0edff}
:root[data-theme="dark"]{color-scheme:dark;--bg:#0c0e16;--surface:#161924;--text:#f4f4fa;--muted:#a0a4b6;--border:#2b2e3c;--accent:#a99aff;--soft:#292440}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif;line-height:1.55}a{color:inherit;text-decoration:none}.wrap{max-width:1050px;margin:0 auto;padding:32px 22px 70px}.top{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:32px}.eyebrow{font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);font-weight:800}.button{display:inline-flex;align-items:center;justify-content:center;padding:10px 15px;border:1px solid var(--border);border-radius:11px;background:var(--surface);font-weight:700;font-size:13px}.button.primary{background:var(--accent);border-color:var(--accent);color:#fff}.button:hover,.module:hover{border-color:var(--accent)}.hero{background:var(--surface);border:1px solid var(--border);border-radius:20px;padding:27px;margin-bottom:24px}h1{font-size:clamp(28px,4vw,42px);letter-spacing:-.04em;line-height:1.2;margin:8px 0}h2{font-size:19px;margin:0 0 8px}.muted{color:var(--muted)}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(265px,1fr));gap:15px}.module,.attempt,.empty{display:block;background:var(--surface);border:1px solid var(--border);border-radius:17px;padding:22px}.module{transition:border-color .15s}.code{font-size:12px;color:var(--accent);font-weight:800;letter-spacing:.06em}.pill{display:inline-block;padding:5px 10px;border-radius:99px;background:var(--soft);color:var(--accent);font-size:12px;font-weight:750}.stats{display:flex;gap:8px;flex-wrap:wrap;margin:16px 0}.attempts{display:grid;gap:12px}.attempt{display:flex;justify-content:space-between;align-items:center;gap:18px;flex-wrap:wrap}.attempt h2{margin:5px 0}.attempt .details{flex:1;min-width:200px}.score{font-size:22px;font-weight:800}.error{padding:14px 17px;border-radius:12px;background:#ffedf0;color:#a32143;margin-bottom:20px}.pager{display:flex;align-items:center;justify-content:center;gap:12px;flex-wrap:wrap;margin-top:25px}.pager .disabled{opacity:.45;pointer-events:none}.section-title{margin:28px 0 15px}@media(max-width:600px){.wrap{padding:20px 15px 55px}.hero{padding:20px}.attempt{align-items:flex-start}}
</style>
</head>
<body>
<main class="wrap">
<div class="top">
    <div><div class="eyebrow">ULearn / Student workspace</div><strong>My Quiz History</strong></div>
    <div style="display:flex;gap:9px;flex-wrap:wrap"><a class="button" href="quizzes.php">Quiz Arena</a><a class="button" href="index.php">← Dashboard</a></div>
</div>
<?php if ($error !== ''): ?><div class="error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if ($selectedModule): ?>
    <section class="hero">
        <a class="button" href="quiz-history.php">← All modules</a>
        <div class="eyebrow" style="margin-top:22px"><?= e((string)$selectedModule['module_code']) ?></div>
        <h1><?= e((string)$selectedModule['module_name']) ?></h1>
        <p class="muted">Your completed quiz attempts in this module. Choose one to review its answers and explanations.</p>
        <span class="pill"><?= $totalAttempts ?> completed <?= $totalAttempts === 1 ? 'attempt' : 'attempts' ?></span>
    </section>
    <h2 class="section-title">Completed quizzes</h2>
    <div class="attempts">
    <?php foreach ($attempts as $attempt): ?>
        <article class="attempt">
            <div class="details">
                <div class="eyebrow"><?= e((string)($attempt['status'] === 'timed_out' ? 'Time expired' : 'Completed')) ?> · <?= e((string)ucfirst((string)$attempt['quiz_type'])) ?></div>
                <h2><?= e((string)$attempt['title']) ?></h2>
                <div class="muted" style="font-size:13px"><?= !empty($attempt['submitted_at']) ? e(date('d M Y, H:i', strtotime((string)$attempt['submitted_at']))) : 'Date unavailable' ?> · Attempt #<?= (int)$attempt['id'] ?></div>
            </div>
            <div><div class="score"><?= e(historyScore($attempt['score'])) ?> / <?= e(historyScore($attempt['max_score'])) ?></div><div class="muted" style="font-size:12px">Score</div></div>
            <a class="button primary" href="quizzes.php?result=<?= (int)$attempt['id'] ?>">Review answers →</a>
        </article>
    <?php endforeach; ?>
    </div>
    <?php if ($totalPages > 1): ?>
    <nav class="pager" aria-label="Quiz history pages">
        <?php if ($page > 1): ?><a class="button" href="quiz-history.php?module_id=<?= (int)$moduleId ?>&amp;page=<?= $page - 1 ?>">← Previous</a><?php endif; ?>
        <span class="muted">Page <?= $page ?> of <?= $totalPages ?></span>
        <?php if ($page < $totalPages): ?><a class="button" href="quiz-history.php?module_id=<?= (int)$moduleId ?>&amp;page=<?= $page + 1 ?>">Next →</a><?php endif; ?>
    </nav>
    <?php endif; ?>
<?php else: ?>
    <section class="hero"><div class="eyebrow">Your learning progress</div><h1>My Completed Quizzes</h1><p class="muted">Your results are organized by module. Open a module to see its completed quizzes, then choose an attempt to review. No questions are loaded on this page.</p></section>
    <?php if (!$modules && $error === ''): ?>
        <div class="empty"><h2>No completed quizzes yet</h2><p class="muted">Once you submit a quiz, its result will appear here under the relevant module.</p><a class="button primary" href="quizzes.php">Go to Quiz Arena →</a></div>
    <?php else: ?>
        <h2 class="section-title">Modules with completed quizzes</h2>
        <div class="grid">
        <?php foreach ($modules as $module): ?>
            <a class="module" href="quiz-history.php?module_id=<?= (int)$module['module_id'] ?>">
                <div class="code"><?= e((string)$module['module_code']) ?></div>
                <h2 style="margin-top:9px"><?= e((string)$module['module_name']) ?></h2>
                <div class="stats"><span class="pill"><?= (int)$module['quiz_count'] ?> <?= (int)$module['quiz_count'] === 1 ? 'quiz' : 'quizzes' ?></span><span class="pill"><?= (int)$module['attempt_count'] ?> <?= (int)$module['attempt_count'] === 1 ? 'attempt' : 'attempts' ?></span></div>
                <div class="muted" style="font-size:13px">Last completed: <?= !empty($module['last_completed']) ? e(date('d M Y', strtotime((string)$module['last_completed']))) : 'Unknown' ?></div>
                <div style="color:var(--accent);font-weight:750;margin-top:18px;font-size:13px">View completed quizzes →</div>
            </a>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
</main>
</body>
</html>
