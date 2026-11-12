<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$user = require_login();

if (($user['role'] ?? '') !== 'student') {
    http_response_code(403);
    exit('Student access only.');
}

$pdo = db();
$userId = (int)$user['id'];

$rawModule = $_GET['module_id'] ?? null;
$moduleId = filter_var($rawModule, FILTER_VALIDATE_INT);
$moduleId = ($moduleId && $moduleId > 0) ? $moduleId : null;

$theme = (string)($user['theme'] ?? 'dark');
$error = '';
$profile = null;
$moduleLabel = '';
$attempts = [];
$topics = [];
$modules = [];
$totalAnswers = 0;
$correctAnswers = 0;

try {
    $stmt = $pdo->prepare("
        SELECT p.degree_id, p.batch_id,
               d.name AS degree_name, b.batch_code
        FROM student_academic_profiles p
        JOIN academic_degrees d ON d.id = p.degree_id
        JOIN academic_batches b ON b.id = p.batch_id
        WHERE p.user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($moduleId !== null) {
        // A student may analyse only a module with their own completed quiz history.
        $stmt = $pdo->prepare("
            SELECT m.module_code, m.module_name
            FROM quiz_attempts a
            JOIN quizzes q ON q.id = a.quiz_id
            JOIN academic_modules m ON m.id = q.module_id
            WHERE a.user_id = ? AND q.module_id = ?
              AND a.status IN ('submitted', 'timed_out')
            LIMIT 1
        ");
        $stmt->execute([$userId, $moduleId]);
        $historicalModule = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$historicalModule) {
            http_response_code(404);
            throw new RuntimeException('No completed quiz history was found for this module.');
        }
        $moduleLabel = $historicalModule['module_code'] . ' · ' . $historicalModule['module_name'];
    }

    $scope = "
        FROM quiz_attempts a
        JOIN quizzes q ON q.id = a.quiz_id
        JOIN academic_modules m ON m.id = q.module_id

        WHERE a.user_id = ?
          AND a.status IN ('submitted', 'timed_out')
          AND (? IS NULL OR q.module_id = ?)
    ";

    $params = [$userId, $moduleId, $moduleId];

    $stmt = $pdo->prepare("
        SELECT a.id, a.score, a.max_score,
               a.submitted_at, q.title,
               m.module_code, m.module_name,
               CASE WHEN a.max_score > 0
                 THEN a.score * 100 / a.max_score
                 ELSE 0 END AS percentage
        $scope
        ORDER BY a.submitted_at DESC, a.id DESC
    ");
    $stmt->execute($params);
    $attempts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT m.module_code, m.module_name,
               COUNT(a.id) AS attempt_count,
               AVG(CASE WHEN a.max_score > 0
                 THEN a.score * 100 / a.max_score
                 ELSE 0 END) AS average_score
        $scope
        GROUP BY m.id, m.module_code, m.module_name
        ORDER BY m.module_name
    ");
    $stmt->execute($params);
    $modules = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $answerScope = "
        FROM quiz_attempt_answers aa
        JOIN quiz_attempts a ON a.id = aa.attempt_id
        JOIN quiz_questions qq ON qq.id = aa.question_id
        JOIN quizzes q ON q.id = a.quiz_id
        JOIN academic_modules m ON m.id = q.module_id

        WHERE a.user_id = ?
          AND a.status IN ('submitted', 'timed_out')
          AND qq.quiz_id = q.id
          AND (? IS NULL OR q.module_id = ?)
    ";

    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total,
               COALESCE(SUM(
                 CASE WHEN aa.is_correct = 1
                 THEN 1 ELSE 0 END
               ), 0) AS correct
        $answerScope
    ");
    $stmt->execute($params);
    $answerTotals = $stmt->fetch(PDO::FETCH_ASSOC);

    $totalAnswers = (int)$answerTotals['total'];
    $correctAnswers = (int)$answerTotals['correct'];

    $stmt = $pdo->prepare("
        SELECT COALESCE(
                 NULLIF(TRIM(qq.topic), ''),
                 'General'
               ) AS topic,
               COUNT(*) AS total,
               SUM(CASE WHEN aa.is_correct = 1
                   THEN 1 ELSE 0 END) AS correct,
               ROUND(100 * SUM(
                 CASE WHEN aa.is_correct = 1
                 THEN 1 ELSE 0 END
               ) / COUNT(*), 1) AS accuracy
        $answerScope
        GROUP BY COALESCE(
            NULLIF(TRIM(qq.topic), ''), 'General'
        )
        ORDER BY accuracy ASC, total DESC, topic ASC
        LIMIT 6
    ");
    $stmt->execute($params);
    $topics = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $exception) {
    error_log('ULearn analytics: ' . $exception->getMessage());

    $knownErrors = [
        'Please complete your academic profile first.',
        'No completed quiz history was found for this module.'
    ];

    $error = in_array(
        $exception->getMessage(),
        $knownErrors,
        true
    ) ? $exception->getMessage()
      : 'Unable to load analytics. Please contact the administrator.';

    $attempts = [];
    $topics = [];
    $modules = [];
}

$count = count($attempts);
$percentages = array_map(
    static fn($a) => (float)$a['percentage'],
    $attempts
);

$average = $count ? array_sum($percentages) / $count : 0;
$best = $count ? max($percentages) : 0;
$accuracy = $totalAnswers
    ? $correctAnswers * 100 / $totalAnswers : 0;

$firstName = explode(
    ' ',
    trim((string)($user['full_name'] ?? 'Student'))
)[0];

$recent = array_slice($attempts, 0, 5);
$trend = array_reverse(array_slice($attempts, 0, 8));

$topicUrl = url('dashboard/topic-analysis.php')
    . ($moduleId !== null ? '?module_id=' . $moduleId : '');

?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Performance Analytics | uLearn.</title>
<script>(function(){try{var v=localStorage.getItem('ulearn-theme');var t=v==='light'||v==='dark'?v:(matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light');document.documentElement.dataset.theme=t}catch(e){document.documentElement.dataset.theme='light'}})();</script>
<link rel="stylesheet" href="<?= e(url('assets/css/ulearn-analytics-premium.css?v=3')) ?>">
<script defer src="<?= e(url('assets/js/ulearn-analytics-premium.js?v=3')) ?>"></script>
<script src="<?= htmlspecialchars(url('assets/js/theme-global.js?v=20261112-2'), ENT_QUOTES, 'UTF-8') ?>"></script>
</head><body class="upa-body"><div class="upa-progress" id="upaProgress" aria-hidden="true"></div><div class="upa-page" id="upaPage">
<header class="upa-header"><a class="upa-brand" href="<?= e(url('dashboard/index.php')) ?>"><img src="<?= e(url('assets/images/nsbm-shield-only.png')) ?>" alt="NSBM shield"><span>uLearn<span class="upa-dot">.</span></span></a><div class="upa-header-title"><span class="upa-kicker">STUDENT WORKSPACE</span><strong>Performance Analytics</strong></div><nav class="upa-nav" aria-label="Main navigation"><a class="upa-button upa-hide-mobile" href="<?= e(url('dashboard/quiz-history.php')) ?>">Quiz History</a><a class="upa-button upa-hide-mobile" href="<?= e($topicUrl) ?>">Topic Weaknesses <span aria-hidden="true">↗</span></a><button class="upa-button upa-theme" id="upaTheme" type="button" aria-label="Toggle dark mode" title="Toggle light and dark theme"><span id="upaThemeIcon">◐</span></button><a class="upa-button upa-button-main" href="<?= e(url('dashboard/index.php')) ?>">Dashboard <span aria-hidden="true">↗</span></a></nav></header>
<?php if ($error !== ''): ?><div class="upa-alert" role="alert"><?= e($error) ?></div><?php endif; ?>
<section class="upa-hero upa-reveal"><div class="upa-hero-glow"></div><div class="upa-hero-grid"></div><div class="upa-hero-content"><div class="upa-eyebrow"><span class="upa-live"></span> YOUR PERSONAL LEARNING INTELLIGENCE</div><h1>Make every result<br><span>your next advantage.</span></h1><p>Welcome back, <?= e($firstName) ?>. Explore your performance, spot learning gaps and turn your quiz history into measurable progress.</p><div class="upa-hero-tags"><?php if($moduleLabel !== ''): ?><span class="upa-chip"><?= e($moduleLabel) ?></span><?php endif; ?><?php if($profile): ?><span class="upa-chip"><?= e((string)$profile['degree_name']) ?></span><span class="upa-chip">Batch <?= e((string)$profile['batch_code']) ?></span><?php endif; ?><span class="upa-chip"><?= $count ?> completed <?= $count===1?'attempt':'attempts' ?></span></div><div class="upa-hero-actions"><a class="upa-button upa-button-main" href="<?= e(url('dashboard/learning-insights.php')) ?>">Explore Learning Insights <span aria-hidden="true">↗</span></a><a class="upa-button upa-hero-secondary" href="<?= e(url('dashboard/quizzes.php')) ?>">Take another quiz</a></div></div><div class="upa-hero-visual" aria-hidden="true"><div class="upa-orbit upa-orbit-a"></div><div class="upa-orbit upa-orbit-b"></div><div class="upa-orbit-center"><span>PERFORMANCE</span><strong><?= $count ? number_format($average,0).'%' : '—' ?></strong><small>Average score</small></div><div class="upa-floating upa-floating-a">↗ Insights</div><div class="upa-floating upa-floating-b">✦ Keep growing</div></div></section>
<section class="upa-stats" aria-label="Performance statistics">
<article class="upa-stat upa-reveal"><div class="upa-stat-top"><span class="upa-icon">▦</span><span class="upa-stat-index">01 / OVERVIEW</span></div><p>Completed quizzes</p><strong class="upa-counter" data-value="<?= $count ?>" data-suffix=""><?= $count ?></strong><small>All saved completed attempts</small></article>
<article class="upa-stat upa-reveal"><div class="upa-stat-top"><span class="upa-icon">↗</span><span class="upa-stat-index">02 / PERFORMANCE</span></div><p>Average score</p><strong class="upa-counter" data-value="<?= $count ? round($average) : 0 ?>" data-suffix="<?= $count?'%':'' ?>"><?= $count?number_format($average,0).'%':'—' ?></strong><small>Across completed quiz attempts</small></article>
<article class="upa-stat upa-reveal"><div class="upa-stat-top"><span class="upa-icon">✦</span><span class="upa-stat-index">03 / HIGHLIGHT</span></div><p>Personal best</p><strong class="upa-counter" data-value="<?= $count ? round($best) : 0 ?>" data-suffix="<?= $count?'%':'' ?>"><?= $count?number_format($best,0).'%':'—' ?></strong><small>Highest recorded percentage</small></article>
<article class="upa-stat upa-reveal"><div class="upa-stat-top"><span class="upa-icon">◎</span><span class="upa-stat-index">04 / ACCURACY</span></div><p>Answer accuracy</p><strong class="upa-counter" data-value="<?= $totalAnswers ? round($accuracy) : 0 ?>" data-suffix="<?= $totalAnswers?'%':'' ?>"><?= $totalAnswers?number_format($accuracy,0).'%':'—' ?></strong><small><?= $correctAnswers ?> correct of <?= $totalAnswers ?> recorded answers</small></article>
</section>
<div class="upa-section-head upa-reveal"><div><span class="upa-kicker">THE BIG PICTURE</span><h2>Understand your progress<span class="upa-dot">.</span></h2></div><button type="button" id="upaExport" class="upa-button">Export summary <span aria-hidden="true">↓</span></button></div>
<section class="upa-grid">
<article class="upa-panel upa-reveal"><div class="upa-panel-head"><div><span class="upa-kicker">PERFORMANCE OVER TIME</span><h3>Score trajectory</h3><p>Your latest <?= min(8,count($trend)) ?> completed quiz <?= count($trend)===1?'attempt':'attempts' ?>, oldest to newest.</p></div><span class="upa-panel-icon">↗</span></div><?php if(!$trend): ?><div class="upa-empty">Complete a quiz to unlock your performance trend.</div><?php elseif(count($trend)===1): $only=max(0,min(100,(float)$trend[0]['percentage'])); ?><div class="upa-single"><strong><?= number_format($only,0) ?>%</strong><div><p>Your first recorded result. Complete another quiz to see a trend.</p><div class="upa-track"><span style="--target:<?= $only ?>%"></span></div></div></div><?php else: ?><div class="upa-chart" role="img" aria-label="Score percentages for recent quiz attempts"><div class="upa-chart-grid" aria-hidden="true"><span>100%</span><span>75%</span><span>50%</span><span>25%</span><span>0%</span></div><div class="upa-chart-bars"><?php foreach($trend as $i=>$attempt): $v=max(0,min(100,(float)$attempt['percentage'])); ?><div class="upa-bar-column" tabindex="0" title="<?= e((string)$attempt['title']) ?>: <?= number_format($v,1) ?>%"><span class="upa-bar-value"><?= number_format($v,0) ?>%</span><div class="upa-bar" style="--height:<?= $v ?>%;--delay:<?= $i*90 ?>ms"></div><small><?= $i+1 ?></small></div><?php endforeach; ?></div></div><p class="upa-footnote">Hover or focus a bar to view the quiz title · <?= count($trend) ?> attempts shown</p><?php endif; ?></article>
<article class="upa-panel upa-reveal"><div class="upa-panel-head"><div><span class="upa-kicker">ACCURACY BREAKDOWN</span><h3>Answers at a glance</h3><p>Correct versus other recorded answers.</p></div><span class="upa-panel-icon">◎</span></div><div class="upa-donut-layout"><div class="upa-donut" style="--percent:<?= max(0,min(100,$accuracy)) ?>" role="img" aria-label="<?= number_format($accuracy,0) ?> percent answer accuracy"><div><strong><?= $totalAnswers?number_format($accuracy,0).'%':'—' ?></strong><span>Accuracy</span></div></div><div class="upa-legend"><div><span class="upa-legend-dot"></span><span>Correct answers</span><strong><?= $correctAnswers ?></strong></div><div><span class="upa-legend-dot upa-legend-muted"></span><span>Other answers</span><strong><?= max(0,$totalAnswers-$correctAnswers) ?></strong></div><div class="upa-legend-total"><span>Total recorded</span><strong><?= $totalAnswers ?></strong></div></div></div><p class="upa-footnote">Based on stored answers, not an estimated grade.</p></article>
<article class="upa-panel upa-reveal"><div class="upa-panel-head"><div><span class="upa-kicker">WHERE TO FOCUS</span><h3>Topics to practise</h3><p>Lowest-accuracy topics appear first.</p></div><a class="upa-small-link" href="<?= e($topicUrl) ?>">All topics ↗</a></div><?php if(!$topics): ?><div class="upa-empty">Topic insights will appear after completed quizzes.</div><?php else: ?><div class="upa-topic-list"><?php foreach($topics as $i=>$topic): $v=max(0,min(100,(float)$topic['accuracy'])); ?><div class="upa-topic-row"><div class="upa-row-top"><span class="upa-topic-name"><?= e((string)$topic['topic']) ?></span><strong><?= number_format($v,0) ?>%</strong></div><div class="upa-track"><span style="--target:<?= $v ?>%;--delay:<?= $i*100 ?>ms"></span></div><div class="upa-row-sub"><span><?= (int)$topic['correct'] ?> of <?= (int)$topic['total'] ?> correct</span><span><?= $v<50?'High priority':($v<80?'Keep practising':'Strong') ?></span></div></div><?php endforeach; ?></div><?php endif; ?><a class="upa-inline-action" href="<?= e(url('dashboard/learning-insights.php')) ?>">View personalized improvement plans <span>↗</span></a></article>
<article class="upa-panel upa-reveal"><div class="upa-panel-head"><div><span class="upa-kicker">ACADEMIC BREAKDOWN</span><h3>Module performance</h3><p>Average score for each module with completed attempts.</p></div><span class="upa-panel-icon">▤</span></div><?php if(!$modules): ?><div class="upa-empty">Your module results will appear here.</div><?php else: ?><div class="upa-module-list" id="upaModuleList"><?php foreach($modules as $i=>$module): $v=max(0,min(100,(float)$module['average_score'])); ?><div class="upa-module-row" data-search="<?= e(mb_strtolower((string)$module['module_code'].' '.(string)$module['module_name'])) ?>"><div class="upa-row-top"><div><strong><?= e((string)$module['module_code']) ?></strong><p><?= e((string)$module['module_name']) ?></p></div><span class="upa-score-tag"><?= number_format($v,0) ?>%</span></div><div class="upa-track"><span style="--target:<?= $v ?>%;--delay:<?= $i*70 ?>ms"></span></div><div class="upa-row-sub"><span><?= (int)$module['attempt_count'] ?> completed <?= (int)$module['attempt_count']===1?'attempt':'attempts' ?></span><span>Average score</span></div></div><?php endforeach; ?></div><?php endif; ?></article>
</section>
<section class="upa-results upa-reveal"><div class="upa-results-heading"><div><span class="upa-kicker">YOUR LEARNING JOURNEY</span><h2>Quiz results explorer<span class="upa-dot">.</span></h2><p>Search, filter and sort your saved attempts without changing the database.</p></div><span class="upa-count-pill" id="upaResultCount"><?= $count ?> attempts</span></div><div class="upa-toolbar"><label class="upa-search"><span aria-hidden="true">⌕</span><input id="upaSearch" type="search" placeholder="Search quiz or module..." aria-label="Search quiz results" autocomplete="off"><kbd>/</kbd></label><label class="upa-select-label"><span>Sort</span><select id="upaSort"><option value="recent">Most recent</option><option value="oldest">Oldest first</option><option value="high">Highest score</option><option value="low">Lowest score</option><option value="title">Quiz title A–Z</option></select></label><label class="upa-select-label"><span>Module</span><select id="upaFilter"><option value="all">All modules</option><?php foreach($modules as $module): ?><option value="<?= e((string)$module['module_code']) ?>"><?= e((string)$module['module_code']) ?></option><?php endforeach; ?></select></label><button type="button" class="upa-button" id="upaClear">Reset</button></div><div class="upa-table-wrap"><table class="upa-table"><thead><tr><th>QUIZ / MODULE</th><th>COMPLETED</th><th>RESULT</th><th>ACTIONS</th></tr></thead><tbody id="upaResultBody"><?php foreach($attempts as $attempt): $v=max(0,min(100,(float)$attempt['percentage'])); $ts=strtotime((string)$attempt['submitted_at']); ?><tr data-title="<?= e(mb_strtolower((string)$attempt['title'].' '.(string)$attempt['module_name'].' '.(string)$attempt['module_code'])) ?>" data-module="<?= e((string)$attempt['module_code']) ?>" data-score="<?= $v ?>" data-time="<?= $ts?:0 ?>" data-name="<?= e(mb_strtolower((string)$attempt['title'])) ?>"><td><strong><?= e((string)$attempt['title']) ?></strong><small><?= e((string)$attempt['module_code']) ?> · <?= e((string)$attempt['module_name']) ?></small></td><td><span class="upa-date"><?= $ts?e(date('d M Y',$ts)):'Unavailable' ?></span></td><td><span class="upa-table-score"><?= number_format($v,0) ?>%</span><div class="upa-mini-track"><span style="width:<?= $v ?>%"></span></div><small><?= number_format((float)$attempt['score'],1) ?> / <?= number_format((float)$attempt['max_score'],1) ?> marks</small></td><td><div class="upa-table-actions"><a href="<?= e(url('dashboard/learning-insights.php?attempt_id='.(int)$attempt['id'])) ?>">Insights ↗</a><a href="<?= e(url('dashboard/quizzes.php?result='.(int)$attempt['id'])) ?>">Review</a></div></td></tr><?php endforeach; ?></tbody></table><div class="upa-empty upa-no-results" id="upaNoResults" hidden>No quiz results match these filters. Try resetting your search.</div><?php if(!$attempts): ?><div class="upa-empty">No completed quizzes yet. <a href="<?= e(url('dashboard/quizzes.php')) ?>">Visit Quiz Arena ↗</a></div><?php endif; ?></div><div class="upa-results-footer"><span>Showing saved quiz attempts<?= $moduleLabel!==''?' for the selected module':'' ?>.</span><a href="<?= e(url('dashboard/quiz-history.php')) ?>">Open Quiz History ↗</a></div></section>
<footer class="upa-footer"><span><strong>uLearn<span class="upa-dot">.</span></strong> Built for better learning.</span><span>Insights reflect your actual saved quiz results.</span></footer></div><?php require_once __DIR__ . '/../includes/student-bot.php'; ?>
</body></html>