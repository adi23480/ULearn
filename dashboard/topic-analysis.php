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

$moduleId = ($moduleId !== false && $moduleId !== null && $moduleId > 0)

    ? $moduleId : null;

$theme = (string)($user['theme'] ?? 'dark');

$profile = null;

$moduleName = '';

$topics = [];

$mistakes = [];

$error = '';

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
        $moduleName = $historicalModule['module_code'] . ' · ' . $historicalModule['module_name'];
    }

    $scope = "
        FROM quiz_attempt_answers aa
        JOIN quiz_attempts a ON a.id = aa.attempt_id
        JOIN quizzes q ON q.id = a.quiz_id
        JOIN quiz_questions qq ON qq.id = aa.question_id AND qq.quiz_id = q.id
        JOIN academic_modules m ON m.id = q.module_id
        WHERE a.user_id = ?
          AND a.status IN ('submitted', 'timed_out')
          AND (? IS NULL OR q.module_id = ?)
    ";

    $params = [$userId, $moduleId, $moduleId];

    // Aggregate saved answers by question topic for this student and module.
    $topicSql = "
        SELECT
          COALESCE(NULLIF(TRIM(qq.topic), ''), 'General') AS topic,
          COUNT(*) AS recorded,
          SUM(CASE WHEN aa.option_id IS NOT NULL THEN 1 ELSE 0 END) AS answered,
          SUM(CASE WHEN aa.option_id IS NOT NULL AND aa.is_correct = 1
              THEN 1 ELSE 0 END) AS correct,
          SUM(CASE WHEN aa.option_id IS NOT NULL AND aa.is_correct = 0
              THEN 1 ELSE 0 END) AS incorrect,
          ROUND(100.0 * SUM(CASE WHEN aa.option_id IS NOT NULL
              AND aa.is_correct = 1 THEN 1 ELSE 0 END)
              / NULLIF(SUM(CASE WHEN aa.option_id IS NOT NULL
              THEN 1 ELSE 0 END), 0), 1) AS accuracy
        $scope
          AND aa.option_id IS NOT NULL
        GROUP BY COALESCE(NULLIF(TRIM(qq.topic), ''), 'General')
        ORDER BY accuracy ASC, answered DESC, topic ASC
    ";
    $stmt = $pdo->prepare($topicSql);
    $stmt->execute($params);
    $topics = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // The mistake-only condition must be part of the WHERE clause.

    // Build a separate, explicit query to avoid ambiguous joins.

    $mistakeSql = "

        SELECT

          COALESCE(NULLIF(TRIM(qq.topic), ''), 'General')

            AS topic,

          qq.question_text,

          qq.explanation,

          q.title AS quiz_title,

          a.submitted_at,

          selected.option_text AS selected_answer,

          (

            SELECT qo.option_text

            FROM quiz_options qo

            WHERE qo.question_id = qq.id

              AND qo.is_correct = 1

            ORDER BY qo.id LIMIT 1

          ) AS correct_answer

        FROM quiz_attempt_answers aa

        JOIN quiz_attempts a ON a.id = aa.attempt_id

        JOIN quizzes q ON q.id = a.quiz_id

        JOIN quiz_questions qq

          ON qq.id = aa.question_id

         AND qq.quiz_id = q.id

        JOIN academic_modules m ON m.id = q.module_id


        LEFT JOIN quiz_options selected

          ON selected.id = aa.option_id

         AND selected.question_id = qq.id

        WHERE a.user_id = ?

          AND a.status IN ('submitted', 'timed_out')


          AND aa.option_id IS NOT NULL

          AND aa.is_correct = 0

          AND (? IS NULL OR q.module_id = ?)

        ORDER BY a.submitted_at DESC, aa.id DESC

        LIMIT 8

    ";

    $stmt = $pdo->prepare($mistakeSql);

    $stmt->execute($params);

    $mistakes = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $exception) {

    error_log('ULearn topic analysis: ' . $exception->getMessage());

    $known = [

        'Please complete your academic profile first.',

        'No completed quiz history was found for this module.'

    ];

    $error = in_array($exception->getMessage(), $known, true)

        ? $exception->getMessage()

        : 'Unable to load topic analysis. Please contact the administrator.';

    $topics = [];

    $mistakes = [];

}

$total = array_sum(array_map(

    static fn($t) => (int)$t['answered'], $topics

));

$correct = array_sum(array_map(

    static fn($t) => (int)$t['correct'], $topics

));

$accuracy = $total > 0 ? $correct * 100 / $total : 0;

$firstName = explode(

    ' ', trim((string)($user['full_name'] ?? 'Student'))

)[0];

$focus = $topics[0] ?? null;

$priorities = array_slice($topics, 0, 4);

$analyticsUrl = url('dashboard/analytics.php')

    . ($moduleId !== null ? '?module_id=' . $moduleId : '');

function topicPriority(array $topic): string {

    $count = (int)$topic['answered'];

    $accuracy = (float)$topic['accuracy'];

    if ($count < 5) return 'BUILD DATA';

    if ($accuracy < 50) return 'HIGH PRIORITY';

    if ($accuracy < 75) return 'PRACTISE';

    return 'MAINTAIN';

}

?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Topic Weaknesses | ULearn</title>
<script>(function(){try{var v=localStorage.getItem('ulearn-theme');document.documentElement.dataset.theme=(v==='dark'||v==='light')?v:<?= json_encode($theme) ?>;}catch(e){document.documentElement.dataset.theme=<?= json_encode($theme) ?>}})();</script>
<link rel="stylesheet" href="<?= e(url('assets/css/ulearn-topic-premium.css?v=4')) ?>"><script defer src="<?= e(url('assets/js/ulearn-topic-premium.js?v=4')) ?>"></script></head>
<body><div class="ut-progress" id="utScroll"></div><div class="ut-shell"><header class="ut-header"><a class="ut-brand" href="<?= e(url('dashboard/index.php')) ?>" aria-label="ULearn Dashboard"><span class="ut-shield"><svg viewBox="0 0 32 38" aria-hidden="true"><path d="M16 2 29 7v11c0 9-5.6 14-13 18C8.6 32 3 27 3 18V7Z" fill="none" stroke="currentColor" stroke-width="2"/><path d="m9 14 7-4 7 4-7 4zM12 19v5c3 2 5 2 8 0v-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg></span><span>uLearn<span class="ut-dot">.</span><small>STUDENT LEARNING SPACE</small></span></a><nav class="ut-nav"><a href="<?= e(url('dashboard/ai-study.php')) ?>">✦ AI Study</a><a href="<?= e($analyticsUrl) ?>">Analytics</a><a href="<?= e(url('dashboard/index.php')) ?>">Dashboard</a><button type="button" id="utTheme" class="ut-theme" aria-label="Toggle light and dark theme">◐ <span>Theme</span></button></nav></header>
<main><section class="ut-hero ut-reveal"><div class="ut-hero-copy"><div class="ut-kicker"><span class="ut-live"></span> YOUR PERSONAL LEARNING INTELLIGENCE <span class="ut-kicker-line"></span></div><h1>Turn weaknesses into <em>strengths.</em></h1><p>Welcome back, <strong><?= e($firstName) ?></strong>. Discover the topics holding you back, revisit mistakes and build a smarter study routine — one concept at a time.</p><div class="ut-tags"><?php if($moduleName !== ''): ?><span class="ut-tag">◈ <?= e($moduleName) ?></span><?php endif; ?><?php if($profile): ?><span class="ut-tag">◉ <?= e((string)$profile['degree_name']) ?></span><span class="ut-tag">⌁ Batch <?= e((string)$profile['batch_code']) ?></span><?php endif; ?></div><div class="ut-hero-actions"><a class="ut-button ut-primary" href="#utTopics">Explore my topics <span>↗</span></a><a class="ut-button ut-ghost" href="#utMistakes">Review mistakes ↓</a></div></div><div class="ut-hero-art" aria-hidden="true"><div class="ut-orbit ut-orbit-a"></div><div class="ut-orbit ut-orbit-b"></div><div class="ut-center"><span>FOCUS SCORE</span><strong><?= $total ? number_format($accuracy,0).'%' : '—' ?></strong><small>Recorded accuracy</small></div><div class="ut-floating ut-float-one">✦ Learn smarter</div><div class="ut-floating ut-float-two">↗ Keep improving</div></div></section>
<?php if($error !== ''): ?><div class="ut-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<section class="ut-stats ut-reveal" aria-label="Learning statistics"><article class="ut-stat"><div class="ut-stat-top"><span>Answer accuracy</span><i>◎</i></div><strong class="ut-count" data-number="<?= $total ? round($accuracy,1) : 0 ?>" data-suffix="%" data-empty="<?= $total ? '0' : '1' ?>"><?= $total ? number_format($accuracy,1).'%' : '—' ?></strong><small><?= $correct ?> correct out of <?= $total ?> answered</small></article><article class="ut-stat"><div class="ut-stat-top"><span>Topics analysed</span><i>▦</i></div><strong class="ut-count" data-number="<?= count($topics) ?>"><?= count($topics) ?></strong><small>Based on recorded quiz topics</small></article><article class="ut-stat"><div class="ut-stat-top"><span>First focus topic</span><i>⌁</i></div><strong class="ut-stat-topic"><?= $focus ? e((string)$focus['topic']) : '—' ?></strong><small><?= $focus ? number_format((float)$focus['accuracy'],1).'%' : 'Complete a quiz to begin' ?> accuracy</small></article><article class="ut-stat"><div class="ut-stat-top"><span>Recent mistakes</span><i>↺</i></div><strong class="ut-count" data-number="<?= count($mistakes) ?>"><?= count($mistakes) ?></strong><small>Up to 8 latest incorrect answers</small></article></section>
<section class="ut-section ut-reveal" id="utTopics"><div class="ut-section-title"><div><div class="ut-eyebrow">01 / KNOW YOUR GAPS</div><h2>Topic performance <span>explorer</span></h2><p>All recorded topics, ordered by accuracy. Fewer than five answers means preliminary evidence.</p></div><span class="ut-section-icon">◈</span></div><div class="ut-explorer"><div class="ut-tools"><label class="ut-search"><span>⌕</span><input id="utTopicSearch" placeholder="Search topics..." autocomplete="off" aria-label="Search topics"></label><select id="utTopicFilter" aria-label="Filter topic priority"><option value="all">All priorities</option><option value="build">Build data</option><option value="high">High priority</option><option value="practice">Practise</option><option value="maintain">Maintain</option></select><select id="utTopicSort" aria-label="Sort topics"><option value="weak">Lowest accuracy</option><option value="strong">Highest accuracy</option><option value="name">Topic name</option><option value="volume">Most answers</option></select></div><div class="ut-topic-grid" id="utTopicGrid"><?php if(!$topics): ?><div class="ut-empty">No topic results yet. Complete a quiz to unlock your topic breakdown.</div><?php else: ?><?php foreach($topics as $i=>$topic): $value=max(0,min(100,(float)$topic['accuracy']));$priority=topicPriority($topic);$key=$priority==='BUILD DATA'?'build':($priority==='HIGH PRIORITY'?'high':($priority==='PRACTISE'?'practice':'maintain')); ?><article class="ut-topic-card" data-name="<?= e(strtolower((string)$topic['topic'])) ?>" data-score="<?= $value ?>" data-answers="<?= (int)$topic['answered'] ?>" data-priority="<?= $key ?>" style="--ut-score:<?= $value ?>%;--ut-delay:<?= min($i,12)*0.045 ?>s"><div class="ut-topic-head"><span class="ut-priority ut-priority-<?= $key ?>"><?= e($priority) ?></span><span class="ut-topic-index">#<?= str_pad((string)($i+1),2,'0',STR_PAD_LEFT) ?></span></div><h3><?= e((string)$topic['topic']) ?></h3><div class="ut-topic-meter"><strong><?= number_format($value,1) ?>%</strong><small>Accuracy</small></div><div class="ut-track"><div class="ut-fill"></div></div><div class="ut-topic-bottom"><span><?= (int)$topic['correct'] ?> / <?= (int)$topic['answered'] ?> correct</span><span><?= (int)$topic['answered']<5?'Limited data':'Recorded answers' ?></span></div><button type="button" class="ut-topic-action" data-topic="<?= e((string)$topic['topic']) ?>">Add to study plan <span>＋</span></button></article><?php endforeach; ?><?php endif; ?></div><div id="utTopicNone" class="ut-empty" hidden>No matching topics. Try another search or filter.</div><p class="ut-results" id="utTopicResults"></p></div></section>
<section class="ut-two ut-reveal"><article class="ut-panel"><div class="ut-panel-head"><div><div class="ut-eyebrow">02 / STUDY STRATEGICALLY</div><h2>Your focus priorities</h2><p>Suggested practice based on saved results — not AI-generated.</p></div><span class="ut-panel-symbol">◎</span></div><?php if(!$priorities): ?><div class="ut-empty">Your revision priorities appear after completing quizzes.</div><?php else: ?><?php foreach($priorities as $i=>$topic): ?><div class="ut-focus-row"><span class="ut-focus-number"><?= str_pad((string)($i+1),2,'0',STR_PAD_LEFT) ?></span><div><h3><?= e((string)$topic['topic']) ?></h3><p><?= (int)$topic['incorrect'] ?> incorrect of <?= (int)$topic['answered'] ?> answered. <?php if((int)$topic['answered']<5): ?>Practise more questions to establish a reliable pattern.<?php elseif((float)$topic['accuracy']<75): ?>Review this concept and retry related questions.<?php else: ?>Maintain your understanding through revision.<?php endif; ?></p></div><span class="ut-focus-score"><?= number_format((float)$topic['accuracy'],0) ?>%</span></div><?php endforeach; ?><?php endif; ?></article><article class="ut-panel ut-study-panel"><div class="ut-panel-head"><div><div class="ut-eyebrow">03 / TAKE ACTION</div><h2>My revision checklist</h2><p>Save topics to your personal checklist on this device.</p></div><span class="ut-panel-symbol">✓</span></div><div class="ut-check-progress"><div><strong id="utDone">0 of 0 complete</strong><small id="utDonePct">0%</small></div><div class="ut-track"><div class="ut-fill" id="utDoneFill"></div></div></div><div id="utChecklist" class="ut-checklist"><div class="ut-empty">Select “Add to study plan” on any topic to begin.</div></div><div class="ut-study-actions"><button type="button" class="ut-button ut-ghost" id="utAddPriority">+ Add focus topics</button><button type="button" class="ut-text-button" id="utClearDone">Clear completed</button></div><p class="ut-note">Checklist progress is saved locally in this browser, not to the university database.</p></article></section>
<section class="ut-section ut-reveal" id="utMistakes"><div class="ut-section-title"><div><div class="ut-eyebrow">04 / LEARN FROM EXPERIENCE</div><h2>Recent mistake <span>review</span></h2><p>Revisit up to eight incorrect answers from completed quizzes. Explanations come from your saved quiz content.</p></div><span class="ut-section-icon">↺</span></div><div class="ut-mistake-shell"><div class="ut-mistake-toolbar"><span><?= count($mistakes) ?> saved mistake<?= count($mistakes)===1?'':'s' ?> shown</span><div><button type="button" id="utExpand" class="ut-mini-button">Expand all</button><button type="button" id="utCollapse" class="ut-mini-button">Collapse all</button></div></div><?php if(!$mistakes): ?><div class="ut-empty">No incorrect answers found in your completed quizzes.</div><?php else: ?><?php foreach($mistakes as $i=>$mistake): ?><details class="ut-mistake"><summary><span class="ut-mistake-num"><?= str_pad((string)($i+1),2,'0',STR_PAD_LEFT) ?></span><span class="ut-mistake-summary"><span class="ut-mistake-meta"><b><?= e((string)$mistake['topic']) ?></b> · <?= e((string)$mistake['quiz_title']) ?> · <?= e(date('d M Y',strtotime((string)$mistake['submitted_at']))) ?></span><strong><?= e((string)$mistake['question_text']) ?></strong></span><span class="ut-chevron">⌄</span></summary><div class="ut-mistake-body"><div class="ut-answer-grid"><div class="ut-answer ut-wrong"><small>YOUR ANSWER</small><p><?= e((string)($mistake['selected_answer']??'Not available')) ?></p></div><div class="ut-answer ut-right"><small>CORRECT ANSWER</small><p><?= e((string)($mistake['correct_answer']??'Not available')) ?></p></div></div><?php if(trim((string)$mistake['explanation'])!==''): ?><div class="ut-explanation"><strong>✦ Explanation</strong><p><?= e((string)$mistake['explanation']) ?></p></div><?php endif; ?></div></details><?php endforeach; ?><?php endif; ?><div class="ut-bottom-actions"><a class="ut-button ut-primary" href="<?= e(url('dashboard/quizzes.php')) ?>">Practise quizzes ↗</a><a class="ut-button ut-ghost" href="<?= e(url('dashboard/learning-insights.php')) ?>">Quiz-by-quiz insights ↗</a><a class="ut-button ut-ghost" href="<?= e($analyticsUrl) ?>">Back to analytics ↗</a></div></div></section></main><footer class="ut-footer"><span>uLearn<span class="ut-dot">.</span> <small>Understand your weaknesses. Improve with practice.</small></span><a href="<?= e(url('dashboard/index.php')) ?>">Back to dashboard ↑</a></footer></div><?php require_once __DIR__ . '/../includes/student-bot.php'; ?></body></html>