<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
$user = require_login();
if ((string)$user['role'] !== 'student') { http_response_code(403); exit('Student access only.'); }
$pdo = db();
$userId = (int)$user['id'];
$attemptId = filter_input(INPUT_GET, 'attempt_id', FILTER_VALIDATE_INT);
$moduleId = filter_input(INPUT_GET, 'module_id', FILTER_VALIDATE_INT);
$page = max(1, (int)(filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1));
$perPage = 12;
$selected = null; $attempts = []; $topics = []; $mistakes = []; $total = 0; $error = '';
try {
    $where = "a.user_id = :uid AND a.status IN ('submitted','timed_out') AND a.submitted_at IS NOT NULL";
    $args = [':uid'=>$userId];
    if ($moduleId && $moduleId > 0) { $where .= ' AND q.module_id = :mid'; $args[':mid']=$moduleId; }
    if ($attemptId && $attemptId > 0) {
        $stmt=$pdo->prepare("SELECT a.id,a.score,a.max_score,a.submitted_at,q.title,m.module_name,m.module_code,q.module_id
            FROM quiz_attempts a JOIN quizzes q ON q.id=a.quiz_id JOIN academic_modules m ON m.id=q.module_id
            WHERE a.id=:aid AND a.user_id=:uid AND a.status IN ('submitted','timed_out') AND a.submitted_at IS NOT NULL");
        $stmt->execute([':aid'=>$attemptId,':uid'=>$userId]);
        $selected=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!$selected) { http_response_code(404); $error='This completed quiz attempt was not found in your account.'; }
    } else {
        $stmt=$pdo->prepare("SELECT COUNT(*) FROM quiz_attempts a JOIN quizzes q ON q.id=a.quiz_id WHERE $where");
        $stmt->execute($args); $total=(int)$stmt->fetchColumn();
        $pages=max(1,(int)ceil($total/$perPage)); $page=min($page,$pages);
        $stmt=$pdo->prepare("SELECT a.id,a.score,a.max_score,a.submitted_at,q.title,m.module_name,m.module_code,q.module_id
            FROM quiz_attempts a JOIN quizzes q ON q.id=a.quiz_id JOIN academic_modules m ON m.id=q.module_id
            WHERE $where ORDER BY a.submitted_at DESC,a.id DESC LIMIT :limit OFFSET :offset");
        foreach ($args as $key=>$val) $stmt->bindValue($key,(int)$val,PDO::PARAM_INT);
        $stmt->bindValue(':limit',$perPage,PDO::PARAM_INT); $stmt->bindValue(':offset',($page-1)*$perPage,PDO::PARAM_INT);
        $stmt->execute(); $attempts=$stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    if ($selected) {
        $stmt=$pdo->prepare("SELECT COALESCE(NULLIF(TRIM(qq.topic),''),'General') topic,
            COUNT(*) answered,SUM(CASE WHEN aa.is_correct=1 THEN 1 ELSE 0 END) correct,
            SUM(CASE WHEN aa.is_correct=0 THEN 1 ELSE 0 END) incorrect
            FROM quiz_attempt_answers aa JOIN quiz_questions qq ON qq.id=aa.question_id
            JOIN quiz_attempts a ON a.id=aa.attempt_id AND a.quiz_id=qq.quiz_id
            WHERE a.id=? AND a.user_id=? AND aa.option_id IS NOT NULL
            GROUP BY COALESCE(NULLIF(TRIM(qq.topic),''),'General')
            ORDER BY (SUM(CASE WHEN aa.is_correct=1 THEN 1 ELSE 0 END)/COUNT(*)) ASC, answered DESC");
        $stmt->execute([$attemptId,$userId]); $topics=$stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt=$pdo->prepare("SELECT COALESCE(NULLIF(TRIM(qq.topic),''),'General') topic,qq.question_text,qq.explanation,
            selected.option_text selected_answer,
            (SELECT qo.option_text FROM quiz_options qo WHERE qo.question_id=qq.id AND qo.is_correct=1 ORDER BY qo.id LIMIT 1) correct_answer
            FROM quiz_attempt_answers aa JOIN quiz_questions qq ON qq.id=aa.question_id
            JOIN quiz_attempts a ON a.id=aa.attempt_id AND a.quiz_id=qq.quiz_id
            LEFT JOIN quiz_options selected ON selected.id=aa.option_id AND selected.question_id=qq.id
            WHERE a.id=? AND a.user_id=? AND aa.option_id IS NOT NULL AND aa.is_correct=0
            ORDER BY qq.id LIMIT 60");
        $stmt->execute([$attemptId,$userId]); $mistakes=$stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $ex) {
    error_log('ULearn learning insights: '.$ex->getMessage());
    $error='Unable to load learning insights. Please try again.';
}
function pct($correct,$total): int { return $total>0?(int)round(100*$correct/$total):0; }
function priority(int $p): string { return $p<50?'High':($p<80?'Medium':'Strong'); }
function steps(string $topic,int $p): array {
    if ($p>=80) return ['Review any missed question in this topic','Try a more challenging question when ready'];
    return ['Review the key concepts for '.$topic,'Read the explanations for missed questions','Practice questions focused on '.$topic];
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#087b57">
<title>Learning Insights | uLearn.</title>
<script>(function(){try{var v=localStorage.getItem('ulearn-theme')||localStorage.getItem('ulearn-premium-theme');document.documentElement.dataset.theme=(v==='dark'||v==='light')?v:(matchMedia('(prefers-color-scheme:dark)').matches?'dark':'light')}catch(e){document.documentElement.dataset.theme='light'}})();</script>
<link rel="stylesheet" href="<?= e(url('assets/css/ulearn-insights-rebuilt.css?v=2')) ?>">
<script defer src="<?= e(url('assets/js/ulearn-insights-rebuilt.js?v=2')) ?>"></script>
<script src="<?= htmlspecialchars(url('assets/js/theme-global.js?v=20261112-1'), ENT_QUOTES, 'UTF-8') ?>"></script>
</head><body class="ui-insights">
<div class="ui-progress" id="scrollProgress" aria-hidden="true"></div><div class="ui-cursor-glow" aria-hidden="true"></div>
<main class="ui-wrap" id="mainContent">
<header class="ui-nav ui-reveal"><a class="ui-brand" href="<?= e(url('dashboard/index.php')) ?>" aria-label="uLearn dashboard"><img src="<?= e(url('assets/images/nsbm-shield-only.png')) ?>" alt="NSBM" class="ui-shield"><span class="ui-wordmark">uLearn<span>.</span></span><span class="ui-brand-label">LEARNING ANALYTICS</span></a><nav class="ui-nav-actions" aria-label="Main navigation"><a class="ui-btn ui-btn-ghost" href="<?= e(url('dashboard/quiz-history.php')) ?>"><span aria-hidden="true">↶</span> Quiz History</a><a class="ui-btn ui-btn-ghost" href="<?= e(url('dashboard/index.php')) ?>">Dashboard</a><button class="ui-theme" type="button" id="themeToggle" aria-label="Toggle light or dark mode" title="Switch theme"><span id="themeIcon">☾</span></button></nav></header>
<section class="ui-hero ui-reveal"><div class="ui-hero-orb orb-one" aria-hidden="true"></div><div class="ui-hero-orb orb-two" aria-hidden="true"></div><div class="ui-hero-copy"><div class="ui-kicker"><span class="ui-live-dot"></span> ULEARN / STUDENT WORKSPACE</div><h1><?= $selected ? e((string)$selected['title']) : 'Your learning, <em>decoded.</em>' ?></h1><p><?= $selected ? 'Understand your performance, identify your next focus area and turn mistakes into progress.' : 'Your personal performance workspace. Explore completed quizzes, discover learning patterns and build a smarter study plan.' ?></p><div class="ui-hero-actions"><?php if ($selected): ?><a class="ui-btn ui-btn-primary" href="#topicAnalysis">Explore topic insights <span aria-hidden="true">↗</span></a><a class="ui-btn ui-btn-glass" href="<?= e(url('dashboard/quizzes.php?result='.(int)$selected['id'])) ?>">Review answers</a><?php else: ?><a class="ui-btn ui-btn-primary" href="#attemptLibrary">Explore my attempts <span aria-hidden="true">↗</span></a><a class="ui-btn ui-btn-glass" href="<?= e(url('dashboard/quizzes.php')) ?>">Quiz Arena</a><?php endif; ?></div></div><div class="ui-hero-art" aria-hidden="true"><div class="ui-orbit orbit-a"></div><div class="ui-orbit orbit-b"></div><div class="ui-art-center"><span>✦</span><small>GROWTH<br>STARTS HERE</small></div><div class="ui-floating-chip chip-one">↗ Insights</div><div class="ui-floating-chip chip-two">✧ Progress</div></div></section>
<?php if ($error !== ''): ?><div class="ui-alert" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if (!$selected && !$attemptId): ?>
<?php $attemptSum=0; $attemptCount=count($attempts); $bestScore=0; $uniqueModules=[]; foreach($attempts as $a){$v=pct((float)$a['score'],(float)$a['max_score']);$attemptSum+=$v;$bestScore=max($bestScore,$v);$uniqueModules[(int)$a['module_id']]=true;} $average=$attemptCount?(int)round($attemptSum/$attemptCount):0; ?>
<section class="ui-metrics ui-reveal" aria-label="Summary of visible attempts"><article class="ui-metric"><span class="ui-metric-icon">◈</span><span class="ui-metric-label">Completed attempts</span><strong data-count="<?= (int)$total ?>"><?= (int)$total ?></strong><small>All completed results<?= $moduleId?' in selected module':'' ?></small></article><article class="ui-metric"><span class="ui-metric-icon">◎</span><span class="ui-metric-label">Average score</span><strong data-count="<?= $average ?>" data-suffix="%"> <?= $average ?>%</strong><small>Current page only</small></article><article class="ui-metric"><span class="ui-metric-icon">✧</span><span class="ui-metric-label">Best score</span><strong data-count="<?= $bestScore ?>" data-suffix="%"> <?= $bestScore ?>%</strong><small>Current page only</small></article><article class="ui-metric"><span class="ui-metric-icon">▦</span><span class="ui-metric-label">Modules shown</span><strong data-count="<?= count($uniqueModules) ?>"><?= count($uniqueModules) ?></strong><small>Current page only</small></article></section>
<section class="ui-section" id="attemptLibrary"><div class="ui-section-heading ui-reveal"><div><span class="ui-section-kicker">YOUR LEARNING JOURNEY</span><h2>Completed quiz attempts <span class="ui-count-pill"><?= (int)$total ?></span></h2><p>Choose an attempt to explore its topic-level breakdown and improvement plan.</p></div><span class="ui-subtle-label">12 attempts per page</span></div>
<div class="ui-toolbar ui-reveal"><label class="ui-search"><span aria-hidden="true">⌕</span><input type="search" id="attemptSearch" placeholder="Search quiz or module..." autocomplete="off" aria-label="Search attempts on this page"><kbd>/</kbd></label><label class="ui-sort-wrap">Sort <select id="attemptSort" aria-label="Sort visible attempts"><option value="recent">Newest first</option><option value="score-high">Highest score</option><option value="score-low">Lowest score</option><option value="name">Quiz name A–Z</option></select></label><span class="ui-result-count" id="attemptCountLabel" aria-live="polite"></span></div>
<?php if (!$attempts && $error===''): ?><div class="ui-empty ui-reveal"><div class="ui-empty-icon">✦</div><h3>No completed quizzes yet</h3><p>Complete a quiz to unlock your personal insights.</p><a class="ui-btn ui-btn-primary" href="<?= e(url('dashboard/quizzes.php')) ?>">Go to Quiz Arena ↗</a></div><?php endif; ?>
<div class="ui-attempt-grid" id="attemptGrid"><?php foreach($attempts as $a): $p=pct((float)$a['score'],(float)$a['max_score']); ?><a class="ui-attempt-card ui-reveal ui-tilt" href="<?= e(url('dashboard/learning-insights.php?attempt_id='.(int)$a['id'])) ?>" data-search="<?= e(mb_strtolower((string)$a['title'].' '.(string)$a['module_name'].' '.(string)$a['module_code'])) ?>" data-score="<?= $p ?>" data-name="<?= e(mb_strtolower((string)$a['title'])) ?>" data-time="<?= e((string)$a['submitted_at']) ?>"><div class="ui-card-top"><span class="ui-module-tag"><?= e((string)$a['module_code']) ?></span><span class="ui-card-arrow">↗</span></div><h3><?= e((string)$a['title']) ?></h3><p class="ui-card-module"><?= e((string)$a['module_name']) ?></p><div class="ui-card-score"><strong><?= $p ?>%</strong><span>Score</span></div><div class="ui-bar" role="progressbar" aria-label="Quiz score" aria-valuenow="<?= $p ?>" aria-valuemin="0" aria-valuemax="100"><span style="--fill:<?= $p ?>%"></span></div><div class="ui-card-bottom"><span><?= e(date('d M Y',strtotime((string)$a['submitted_at']))) ?></span><span>Attempt #<?= (int)$a['id'] ?></span></div></a><?php endforeach; ?></div><div class="ui-empty ui-filter-empty" id="noMatches" hidden><h3>No matching attempts</h3><p>Try another search term.</p></div>
<?php $pages=max(1,(int)ceil($total/$perPage)); if($pages>1): ?><nav class="ui-pager ui-reveal" aria-label="Attempt pages"><?php if($page>1): ?><a class="ui-btn ui-btn-ghost" href="<?= e(url('dashboard/learning-insights.php?page='.($page-1).($moduleId?'&module_id='.(int)$moduleId:''))) ?>">← Previous</a><?php endif; ?><span>Page <b><?= $page ?></b> of <?= $pages ?></span><?php if($page<$pages): ?><a class="ui-btn ui-btn-ghost" href="<?= e(url('dashboard/learning-insights.php?page='.($page+1).($moduleId?'&module_id='.(int)$moduleId:''))) ?>">Next →</a><?php endif; ?></nav><?php endif; ?></section>
<?php elseif ($selected): $overall=pct((float)$selected['score'],(float)$selected['max_score']); $topicCount=count($topics); $mistakeCount=count($mistakes); $totalAnswered=0;$totalCorrect=0;$weakCount=0; foreach($topics as $t){$totalAnswered+=(int)$t['answered'];$totalCorrect+=(int)$t['correct'];if(pct((int)$t['correct'],(int)$t['answered'])<50)$weakCount++;} ?>
<div class="ui-context ui-reveal"><a class="ui-btn ui-btn-ghost" href="<?= e(url('dashboard/learning-insights.php')) ?>">← All insights</a><span><?= e((string)$selected['module_code']) ?> · <?= e((string)$selected['module_name']) ?></span></div>
<section class="ui-metrics ui-reveal" aria-label="Attempt metrics"><article class="ui-metric"><span class="ui-metric-icon">◉</span><span class="ui-metric-label">Overall quiz score</span><strong data-count="<?= $overall ?>" data-suffix="%"> <?= $overall ?>%</strong><small>From saved attempt score</small></article><article class="ui-metric"><span class="ui-metric-icon">▤</span><span class="ui-metric-label">Topics analysed</span><strong data-count="<?= $topicCount ?>"><?= $topicCount ?></strong><small>Answered questions with topic data</small></article><article class="ui-metric"><span class="ui-metric-icon">◇</span><span class="ui-metric-label">Mistakes recorded</span><strong data-count="<?= $mistakeCount ?>" data-suffix="<?= $mistakeCount===60?'+':'' ?>"><?= $mistakeCount ?><?= $mistakeCount===60?'+':'' ?></strong><small>Up to 60 displayed</small></article><article class="ui-metric"><span class="ui-metric-icon">↗</span><span class="ui-metric-label">Focus topics</span><strong data-count="<?= $weakCount ?>"><?= $weakCount ?></strong><small>Below 50% accuracy</small></article></section>
<section class="ui-analysis-layout" id="topicAnalysis"><div class="ui-analysis-main"><div class="ui-section-heading ui-reveal"><div><span class="ui-section-kicker">PERSONAL PERFORMANCE MAP</span><h2>Topic breakdown</h2><p>Real answers, real accuracy. Topics sorted by improvement priority.</p></div></div>
<?php if(!$topics): ?><div class="ui-empty ui-reveal"><h3>No topic data available</h3><p>There are no answered questions with saved topic information for this attempt.</p></div><?php endif; ?>
<div class="ui-topic-list" id="topicList"><?php foreach($topics as $i=>$t): $p=pct((int)$t['correct'],(int)$t['answered']); $level=priority($p); $related=array_values(array_filter($mistakes,static fn($m)=>$m['topic']===$t['topic'])); ?><article class="ui-topic ui-reveal" data-topic-index="<?= $i ?>" data-topic-name="<?= e((string)$t['topic']) ?>"><details <?= $i===0?'open':'' ?>><summary><div class="ui-topic-title"><span class="ui-topic-number"><?= str_pad((string)($i+1),2,'0',STR_PAD_LEFT) ?></span><div><h3><?= e((string)$t['topic']) ?></h3><span class="ui-topic-sub"><?= (int)$t['correct'] ?> of <?= (int)$t['answered'] ?> correct · <?= e($level) ?> <?= $level==='Strong'?'topic':'priority' ?></span></div></div><div class="ui-topic-right"><span class="ui-priority <?= $p<50?'ui-high':($p<80?'ui-medium':'ui-strong') ?>"><?= e($level) ?></span><strong><?= $p ?>%</strong><span class="ui-chevron">⌄</span></div><div class="ui-bar ui-topic-bar"><span style="--fill:<?= $p ?>%"></span></div></summary><div class="ui-topic-content"><p class="ui-advice"><?= (int)$t['answered']<5?'Small question sample: treat this as an early indication, not a definitive measure.':'Use this topic checklist to guide your next study session.' ?></p><div class="ui-check-heading"><h4>Recommended next steps</h4><span class="ui-check-count" data-progress-label>0 complete</span></div><div class="ui-checklist"><?php foreach(steps((string)$t['topic'],$p) as $j=>$step): ?><label class="ui-check"><input type="checkbox" data-check="<?= (int)$selected['id'] ?>-<?= $i ?>-<?= $j ?>"><span class="ui-check-box" aria-hidden="true">✓</span><span><?= e($step) ?></span></label><?php endforeach; ?></div><div class="ui-bar ui-check-bar"><span data-check-progress style="--fill:0%"></span></div>
<?php if($related): ?><details class="ui-mistake-details"><summary class="ui-btn ui-btn-ghost">Review <?= count($related) ?> missed <?= count($related)===1?'question':'questions' ?> <span>⌄</span></summary><div class="ui-mistakes"><?php foreach($related as $k=>$m): ?><article class="ui-mistake"><span class="ui-mistake-index">QUESTION <?= $k+1 ?></span><h4><?= e((string)$m['question_text']) ?></h4><div class="ui-answer ui-answer-wrong"><span>Your answer</span><p><?= e((string)($m['selected_answer']??'Not available')) ?></p></div><div class="ui-answer ui-answer-correct"><span>Correct answer</span><p><?= e((string)($m['correct_answer']??'Not available')) ?></p></div><?php if(trim((string)$m['explanation'])!==''): ?><div class="ui-explanation"><strong>Explanation</strong><p><?= e((string)$m['explanation']) ?></p></div><?php endif; ?></article><?php endforeach; ?></div></details><?php endif; ?><a class="ui-btn ui-btn-primary" href="<?= e(url('dashboard/quizzes.php')) ?>">Practice in Quiz Arena ↗</a><p class="ui-footnote">Quiz Arena is not automatically filtered to this topic. Checklist progress is stored in this browser only.</p></div></details></article><?php endforeach; ?></div></div>
<aside class="ui-analysis-side"><div class="ui-side-card ui-reveal"><div class="ui-side-kicker">YOUR PERFORMANCE</div><h3>Accuracy overview</h3><div class="ui-donut" style="--value:<?= $overall ?>"><div class="ui-donut-center"><strong><?= $overall ?>%</strong><span>Quiz score</span></div></div><div class="ui-side-line"><span>Attempt</span><b>#<?= (int)$selected['id'] ?></b></div><div class="ui-side-line"><span>Completed</span><b><?= e(date('d M Y',strtotime((string)$selected['submitted_at']))) ?></b></div><div class="ui-side-line"><span>Answered in topic data</span><b><?= $totalAnswered ?></b></div><div class="ui-side-line"><span>Correct in topic data</span><b><?= $totalCorrect ?></b></div><p class="ui-footnote">Overall quiz score and topic-answer counts may differ because they come from different saved fields.</p></div><div class="ui-side-card ui-reveal"><div class="ui-side-kicker">YOUR STUDY PLAN</div><h3>Action checklist</h3><div class="ui-study-progress"><strong id="globalChecklistValue">0%</strong><span id="globalChecklistText">0 of 0 steps completed</span></div><div class="ui-bar"><span id="globalChecklistBar" style="--fill:0%"></span></div><button class="ui-btn ui-btn-ghost ui-reset" type="button" id="resetChecklist">Reset checklist</button><p class="ui-footnote">Progress is saved locally on this device, not to your university account.</p></div><a class="ui-side-link ui-reveal" href="<?= e(url('dashboard/quizzes.php?result='.(int)$selected['id'])) ?>"><span><b>Review full answers</b><small>Return to your completed quiz</small></span><span>↗</span></a></aside></section>
<?php endif; ?>
<footer class="ui-footer"><span>© uLearn. · Built for better learning</span><span>Learn · Practice · Improve</span></footer></main>
<?php require_once __DIR__ . '/../includes/student-bot.php'; ?>

</body></html>
