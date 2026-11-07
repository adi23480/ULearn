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
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Learning Insights — ULearn</title>
<script>(function(){try{var v=localStorage.getItem('ulearn-theme');document.documentElement.dataset.theme=v==='dark'||v==='light'?v:(matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light')}catch(e){}})()</script>
<style>
:root{--bg:#f5f8f7;--card:#fff;--ink:#132b35;--muted:#647780;--line:#dfe8e5;--green:#087d58;--wash:#eaf6f0;--navy:#122d48}
:root[data-theme=dark]{color-scheme:dark;--bg:#0c1720;--card:#142633;--ink:#eaf5f1;--muted:#a2b7bb;--line:#29434a;--green:#4cdb9c;--wash:#183b34;--navy:#b8d7e9}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.55 Inter,system-ui,-apple-system,Segoe UI,sans-serif}a{color:inherit;text-decoration:none}.wrap{max-width:1060px;margin:auto;padding:30px 20px 90px}.top,.flex{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap}.eyebrow{font-size:11px;font-weight:800;letter-spacing:.13em;text-transform:uppercase;color:var(--green)}h1{font-size:clamp(29px,4vw,42px);letter-spacing:-.045em;margin:8px 0}h2{font-size:20px;margin:0 0 8px}h3{font-size:16px;margin:0}.muted{color:var(--muted)}.card{background:var(--card);border:1px solid var(--line);border-radius:20px;padding:23px;margin:15px 0;box-shadow:0 5px 22px #00000006}.hero{padding:30px;background:linear-gradient(125deg,var(--card),var(--wash))}.btn{display:inline-flex;padding:10px 15px;border-radius:11px;border:1px solid var(--line);font-weight:700;font-size:13px;background:var(--card);align-items:center;gap:7px}.btn.primary{background:#087d58;color:white;border-color:#087d58}.btn:hover,.card-link:hover{border-color:var(--green)}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(235px,1fr));gap:13px}.card-link{display:block;transition:transform .18s,border-color .18s}.card-link:hover{transform:translateY(-2px)}.score{font-size:25px;font-weight:800;color:var(--green)}.pill{border-radius:30px;padding:5px 10px;background:var(--wash);color:var(--green);font-size:12px;font-weight:750}.bar{height:8px;border-radius:10px;background:var(--line);overflow:hidden;margin-top:10px}.bar>span{display:block;height:100%;border-radius:10px;background:var(--green)}.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px}.stat{background:var(--wash);padding:15px;border-radius:14px}.stat strong{font-size:25px;display:block}.topic{border:1px solid var(--line);border-radius:15px;padding:17px;margin:12px 0}details>summary{cursor:pointer;list-style:none}details>summary::-webkit-details-marker{display:none}.check{display:flex;gap:10px;margin:12px 0;align-items:flex-start}.check input{accent-color:#087d58;margin-top:5px}.error{background:#fff0f0;color:#a32735;padding:14px;border-radius:12px}.mistake{border-top:1px solid var(--line);padding:15px 0}.small{font-size:12px}.pager{display:flex;gap:12px;justify-content:center;align-items:center;margin:25px 0;flex-wrap:wrap}@media(max-width:650px){.wrap{padding:18px 13px 70px}.card{padding:17px}.hero{padding:21px}}
</style><link rel="stylesheet" href="../assets/css/learning-ui-v2.css?v=2"><script src="../assets/js/theme-global.js?v=20261107-2"></script></head><body><main class="wrap">
<div class="top"><div><div class="eyebrow">ULearn / Student workspace</div><strong>Learning Insights</strong></div><div class="flex"><a class="btn" href="quiz-history.php">Quiz history</a><a class="btn" href="index.php">Dashboard</a></div></div>
<section class="card hero"><div class="eyebrow">Personal learning coach</div><h1><?= $selected ? e((string)$selected['title']) : 'My Quiz Analytics' ?></h1>
<p class="muted"><?= $selected ? 'A focused improvement plan based only on your saved answers in this attempt.' : 'Explore your completed quizzes. Open one attempt to see its weak topics, mistakes, and next study steps.' ?></p></section>
<?php if ($error): ?><div class="error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if (!$selected && !$attemptId): ?>
<div class="flex"><h2>Completed quiz attempts <span class="pill"><?= $total ?></span></h2><span class="muted small">12 per page · newest first</span></div>
<?php if (!$attempts): ?><section class="card">No completed quizzes found. <a href="quizzes.php">Go to Quiz Arena</a>.</section><?php endif; ?>
<div class="grid"><?php foreach($attempts as $a): $p=pct((float)$a['score'],(float)$a['max_score']); ?>
<a class="card card-link" href="learning-insights.php?attempt_id=<?= (int)$a['id'] ?>"><div class="eyebrow"><?= e((string)$a['module_code']) ?></div><h2><?= e((string)$a['title']) ?></h2><div class="muted small"><?= e((string)$a['module_name']) ?> · <?= e(date('d M Y',strtotime((string)$a['submitted_at']))) ?></div><div class="flex" style="margin-top:15px"><span class="score"><?= $p ?>%</span><span class="pill">Attempt #<?= (int)$a['id'] ?></span></div><div class="bar"><span style="width:<?= $p ?>%"></span></div><p class="muted small">Open topic analysis and improvement plan →</p></a>
<?php endforeach; ?></div>
<?php $pages=max(1,(int)ceil($total/$perPage)); if($pages>1): ?><nav class="pager"><?php if($page>1): ?><a class="btn" href="?page=<?= $page-1 ?><?= $moduleId?'&amp;module_id='.(int)$moduleId:'' ?>">← Previous</a><?php endif; ?><span>Page <?= $page ?> of <?= $pages ?></span><?php if($page<$pages): ?><a class="btn" href="?page=<?= $page+1 ?><?= $moduleId?'&amp;module_id='.(int)$moduleId:'' ?>">Next →</a><?php endif; ?></nav><?php endif; ?>
<?php elseif ($selected): $overall=pct((float)$selected['score'],(float)$selected['max_score']); ?>
<div class="flex"><a class="btn" href="learning-insights.php">← All quiz insights</a><a class="btn" href="quizzes.php?result=<?= (int)$selected['id'] ?>">Review all answers →</a></div>
<section class="card"><div class="eyebrow"><?= e((string)$selected['module_code']) ?> · <?= e((string)$selected['module_name']) ?></div><div class="stats" style="margin-top:12px"><div class="stat"><span class="muted small">Quiz score</span><strong><?= $overall ?>%</strong></div><div class="stat"><span class="muted small">Topics analysed</span><strong><?= count($topics) ?></strong></div><div class="stat"><span class="muted small">Mistakes recorded</span><strong><?= count($mistakes) ?><?= count($mistakes)===60?'+':'' ?></strong></div></div><p class="muted small">Attempt #<?= (int)$selected['id'] ?> · <?= e(date('d M Y, H:i',strtotime((string)$selected['submitted_at']))) ?></p></section>
<section class="card"><h2>Topic improvement plan</h2><p class="muted">Topics are ordered from lowest to highest accuracy. Recommendations are based on topic scores, not AI-generated diagnoses.</p>
<?php if(!$topics): ?><p class="muted">No answered questions with saved topic data were found for this attempt.</p><?php endif; ?>
<?php foreach($topics as $i=>$t): $p=pct((int)$t['correct'],(int)$t['answered']); $level=priority($p); ?>
<details class="topic" <?= $i===0?'open':'' ?>><summary><div class="flex"><div><h3><?= e((string)$t['topic']) ?></h3><span class="muted small"><?= (int)$t['correct'] ?> / <?= (int)$t['answered'] ?> correct · <?= e($level) ?> <?= $level==='Strong'?'topic':'priority' ?></span></div><div class="score"><?= $p ?>% <span class="muted small">⌄</span></div></div><div class="bar"><span style="width:<?= $p ?>%"></span></div></summary>
<p class="muted small"><?= (int)$t['answered']<5?'Small question sample — treat this result as an early indication.':'Review this topic using the checklist below.' ?></p>
<h3>Recommended next steps</h3>
<?php foreach(steps((string)$t['topic'],$p) as $step): ?><label class="check"><input type="checkbox"><span><?= e($step) ?></span></label><?php endforeach; ?>
<?php $related=array_values(array_filter($mistakes,static fn($m)=>$m['topic']===$t['topic'])); if($related): ?>
<details><summary class="btn">Review <?= count($related) ?> missed <?= count($related)===1?'question':'questions' ?> ↓</summary>
<?php foreach($related as $m): ?><div class="mistake"><strong><?= e((string)$m['question_text']) ?></strong><p class="muted small">Your answer: <?= e((string)($m['selected_answer']??'Not available')) ?></p><p class="small">Correct answer: <?= e((string)($m['correct_answer']??'Not available')) ?></p><?php if(trim((string)$m['explanation'])!==''): ?><p class="muted small"><?= e((string)$m['explanation']) ?></p><?php endif; ?></div><?php endforeach; ?>
</details><?php endif; ?>
<a class="btn primary" href="quizzes.php">Practice in Quiz Arena →</a><p class="muted small">Practice is not automatically filtered to this topic. Checklist selections are not saved yet.</p>
</details><?php endforeach; ?></section>
<?php endif; ?>
</main><?php require_once __DIR__ . '/../includes/student-bot.php'; ?></body></html>
