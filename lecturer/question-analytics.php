<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
$user = require_login();
if ((string)$user['role'] !== 'lecturer') { http_response_code(403); exit('Lecturer access only.'); }
$pdo = db();
$profile = $pdo->prepare('SELECT id FROM lecturer_profiles WHERE user_id = ? AND is_active = 1 LIMIT 1');
$profile->execute([(int)$user['id']]);
$lecturerId = (int)$profile->fetchColumn();
if ($lecturerId < 1) { http_response_code(403); exit('An active lecturer profile is required.'); }

$modulesStmt = $pdo->prepare('SELECT DISTINCT m.id, m.module_code, m.module_name FROM lecturer_module_assignments a JOIN academic_curriculum c ON c.id = a.curriculum_id JOIN academic_modules m ON m.id = c.module_id JOIN academic_degrees d ON d.id = c.degree_id JOIN academic_batches b ON b.id = c.batch_id WHERE a.lecturer_id = ? AND a.is_active = 1 AND c.is_active = 1 AND m.is_active = 1 AND d.is_active = 1 AND b.is_active = 1 ORDER BY m.module_code');
$modulesStmt->execute([$lecturerId]);
$modules = $modulesStmt->fetchAll(PDO::FETCH_ASSOC);
$allowedIds = array_map('intval', array_column($modules, 'id'));
$moduleId = filter_input(INPUT_GET, 'module_id', FILTER_VALIDATE_INT) ?: 0;
$quizId = filter_input(INPUT_GET, 'quiz_id', FILTER_VALIDATE_INT) ?: 0;
$error = '';
$quizzes = [];
$questions = [];
$topics = [];
$overview = ['attempts' => 0, 'students' => 0, 'average' => null];
$selectedQuiz = null;

if ($moduleId > 0 && !in_array($moduleId, $allowedIds, true)) {
    http_response_code(403);
    $error = 'You are not assigned to this module.';
    $moduleId = 0;
    $quizId = 0;
}

try {
    if ($allowedIds && $error === '') {
        $placeholders = implode(',', array_fill(0, count($allowedIds), '?'));
        $sql = 'SELECT q.id, q.module_id, q.title, m.module_code FROM quizzes q JOIN academic_modules m ON m.id = q.module_id WHERE q.module_id IN (' . $placeholders . ')';
        $params = $allowedIds;
        if ($moduleId > 0) { $sql .= ' AND q.module_id = ?'; $params[] = $moduleId; }
        $sql .= ' ORDER BY m.module_code, q.title, q.id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $quizzes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($quizId > 0) {
            foreach ($quizzes as $q) { if ((int)$q['id'] === $quizId) { $selectedQuiz = $q; break; } }
            if ($selectedQuiz === null) {
                http_response_code(403);
                $error = 'This quiz is not available within your assigned modules.';
            } else {
                $stmt = $pdo->prepare("SELECT COUNT(*) AS attempts, COUNT(DISTINCT a.user_id) AS students, AVG(CASE WHEN a.max_score > 0 THEN 100.0 * a.score / a.max_score ELSE NULL END) AS average FROM quiz_attempts a WHERE a.quiz_id = ? AND a.status IN ('submitted', 'timed_out')");
                $stmt->execute([$quizId]);
                $overview = $stmt->fetch(PDO::FETCH_ASSOC) ?: $overview;

                // Denominator counts every completed attempt for which this question has a recorded answer.
                // This includes unanswered questions, which have a NULL option_id and is_correct = 0.
                $stmt = $pdo->prepare("SELECT qq.id, qq.question_text, qq.topic, qq.difficulty, COUNT(aa.attempt_id) AS responses, COALESCE(SUM(CASE WHEN aa.is_correct = 1 THEN 1 ELSE 0 END), 0) AS correct_count, COALESCE(SUM(CASE WHEN aa.option_id IS NULL THEN 1 ELSE 0 END), 0) AS unanswered_count FROM quiz_questions qq LEFT JOIN quiz_attempt_answers aa ON aa.question_id = qq.id AND EXISTS (SELECT 1 FROM quiz_attempts a WHERE a.id = aa.attempt_id AND a.quiz_id = ? AND a.status IN ('submitted','timed_out')) WHERE qq.quiz_id = ? GROUP BY qq.id, qq.question_text, qq.topic, qq.difficulty ORDER BY qq.id ASC");
                $stmt->execute([$quizId, $quizId]);
                $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $stmt = $pdo->prepare("SELECT qq.id AS question_id, o.id AS option_id, o.option_text, o.is_correct, COUNT(aa.attempt_id) AS selected_count FROM quiz_questions qq JOIN quiz_options o ON o.question_id = qq.id LEFT JOIN quiz_attempt_answers aa ON aa.question_id = qq.id AND aa.option_id = o.id AND EXISTS (SELECT 1 FROM quiz_attempts a WHERE a.id = aa.attempt_id AND a.quiz_id = ? AND a.status IN ('submitted','timed_out')) WHERE qq.quiz_id = ? GROUP BY qq.id, o.id, o.option_text, o.is_correct ORDER BY qq.id, o.id");
                $stmt->execute([$quizId, $quizId]);
                $optionsByQuestion = [];
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $option) {
                    $optionsByQuestion[(int)$option['question_id']][] = $option;
                }
                foreach ($questions as &$question) {
                    $question['options'] = $optionsByQuestion[(int)$question['id']] ?? [];
                    $key = trim((string)($question['topic'] ?? '')) ?: 'General';
                    if (!isset($topics[$key])) $topics[$key] = ['responses' => 0, 'correct' => 0, 'questions' => 0];
                    $topics[$key]['responses'] += (int)$question['responses'];
                    $topics[$key]['correct'] += (int)$question['correct_count'];
                    $topics[$key]['questions']++;
                }
                unset($question);
                uasort($topics, static function ($a, $b) {
                    $aRate = $a['responses'] ? $a['correct'] / $a['responses'] : 2;
                    $bRate = $b['responses'] ? $b['correct'] / $b['responses'] : 2;
                    return $aRate <=> $bRate;
                });
            }
        }
    }
} catch (Throwable $exception) {
    error_log('ULearn lecturer question analytics error: ' . $exception->getMessage());
    $error = 'Unable to load question analytics. Confirm the quiz_attempt_answers table exists and try again.';
}
function analyticsRate(int $correct, int $total): string { return $total > 0 ? number_format(100 * $correct / $total, 1) . '%' : 'No data'; }
$theme = (string)($user['theme'] ?? 'system');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Question Analytics — ULearn</title>
<script>(function(){try{const p=<?= json_encode($theme, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;const s=localStorage.getItem('ulearn-theme');document.documentElement.dataset.theme=(s==='light'||s==='dark')?s:((p==='light'||p==='dark')?p:(matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'));}catch(e){}})();function toggleTheme(){const t=document.documentElement.dataset.theme==='dark'?'light':'dark';document.documentElement.dataset.theme=t;try{localStorage.setItem('ulearn-theme',t);}catch(e){}document.getElementById('themeToggle').textContent=t==='dark'?'☀':'☾';}document.addEventListener('DOMContentLoaded',()=>{document.getElementById('themeToggle').textContent=document.documentElement.dataset.theme==='dark'?'☀':'☾';});</script>
<style>
:root{color-scheme:light;--bg:#f7f8fc;--surface:#fff;--text:#171927;--muted:#777b90;--border:#e7e8f0;--accent:#6756e8;--soft:#f0edff;--green:#18794e;--red:#b4233c;--track:#ecebf4}
:root[data-theme="dark"]{color-scheme:dark;--bg:#0c0e16;--surface:#161924;--text:#f4f4fa;--muted:#a0a4b6;--border:#2b2e3c;--accent:#a99aff;--soft:#292440;--green:#8ee0b8;--red:#ffb1bf;--track:#303345}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif;line-height:1.6}a{color:inherit;text-decoration:none}button,select{font:inherit}.layout{display:grid;grid-template-columns:250px minmax(0,1fr);min-height:100vh}.sidebar{background:var(--surface);border-right:1px solid var(--border);padding:28px 15px;display:flex;flex-direction:column;gap:28px}.brand{font-size:27px;font-weight:850;letter-spacing:-1.7px;padding:0 14px}.brand span{color:var(--accent)}.nav-label{padding:0 14px;color:var(--muted);font-size:10px;letter-spacing:1.7px;font-weight:850;text-transform:uppercase}.nav{display:grid;gap:7px}.nav a{display:flex;align-items:center;gap:12px;padding:12px 14px;border-radius:13px;color:var(--muted);font-size:13px;font-weight:700}.nav a:hover,.nav a.active{background:var(--soft);color:var(--accent)}.main{min-width:0;padding:28px clamp(18px,4vw,54px) 60px}.topbar{display:flex;justify-content:space-between;align-items:center;gap:15px;margin-bottom:46px}.crumb,.eyebrow{font-size:11px;letter-spacing:1.5px;text-transform:uppercase;color:var(--muted);font-weight:850}.actions{display:flex;gap:10px;align-items:center}.theme-btn,.btn{border:1px solid var(--border);border-radius:12px;background:var(--surface);color:var(--text);padding:10px 14px;font-size:12px;font-weight:800;cursor:pointer}.theme-btn{font-size:18px;width:43px;height:43px;padding:0}.intro{margin-bottom:27px}h1{font-size:clamp(34px,5vw,56px);letter-spacing:-.055em;line-height:1.12;margin:10px 0}h1 span{color:var(--accent)}h2{font-size:20px;letter-spacing:-.4px;margin:4px 0 12px}.subtitle,.muted{color:var(--muted)}.subtitle{font-size:15px;max-width:740px}.panel,.stat,.question{background:var(--surface);border:1px solid var(--border);border-radius:19px;padding:22px}.filters{display:grid;grid-template-columns:1fr 1fr auto;gap:14px;align-items:end;margin:24px 0}.field label{display:block;font-size:12px;font-weight:800;margin-bottom:7px}select{width:100%;min-height:45px;background:var(--surface);color:var(--text);border:1px solid var(--border);border-radius:12px;padding:10px}.primary{background:var(--accent);color:#fff;border-color:var(--accent)}.stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin:18px 0}.stat .number{font-size:30px;font-weight:850;letter-spacing:-1px}.stat .muted{font-size:12px}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-top:16px}.question{margin-top:14px}.question h3{font-size:15px;margin:8px 0;white-space:pre-wrap;overflow-wrap:anywhere}.pill{display:inline-block;background:var(--soft);color:var(--accent);padding:5px 10px;border-radius:100px;font-size:11px;font-weight:800}.bar{height:9px;background:var(--track);border-radius:99px;overflow:hidden;margin:9px 0}.bar>span{display:block;height:100%;background:var(--accent);border-radius:99px}.option{padding:9px 11px;background:var(--bg);border:1px solid var(--border);border-radius:10px;margin-top:7px;font-size:12px;display:flex;justify-content:space-between;gap:12px}.option.correct{border-color:var(--green)}.error{padding:14px;border-radius:12px;background:var(--soft);color:var(--red);margin:14px 0}.empty{padding:28px;border:1px dashed var(--border);border-radius:14px;text-align:center;color:var(--muted)}.fine{font-size:12px;color:var(--muted)}@media(max-width:900px){.layout{grid-template-columns:1fr}.sidebar{border-right:0;border-bottom:1px solid var(--border);gap:12px;padding:14px}.nav{display:flex;overflow:auto}.nav a{white-space:nowrap}.nav-label{display:none}.main{padding:20px 15px 48px}.topbar{margin-bottom:28px}.filters{grid-template-columns:1fr}.stats,.grid{grid-template-columns:1fr}}
</style>
</head>
<body><div class="layout">
<aside class="sidebar"><a class="brand" href="<?= e(url('lecturer/index.php')) ?>">u learn<span>.</span></a><div class="nav-label">Your workspace</div><nav class="nav"><a href="<?= e(url('lecturer/index.php')) ?>">▦ Overview</a><a href="<?= e(url('lecturer/index.php#modules')) ?>">▤ My modules</a><a href="<?= e(url('lecturer/materials.php')) ?>">♧ Study materials</a><a href="<?= e(url('lecturer/quizzes.php')) ?>">▣ Quiz management</a><a href="<?= e(url('lecturer/performance.php')) ?>">◉ Student performance</a><a class="active" href="<?= e(url('lecturer/question-analytics.php')) ?>">◇ Question analytics</a></nav></aside>
<main class="main"><header class="topbar"><div class="crumb">U Learn / Lecturer space</div><div class="actions"><button id="themeToggle" class="theme-btn" type="button" onclick="toggleTheme()" aria-label="Toggle theme">☾</button><a class="btn" href="<?= e(url('lecturer/index.php')) ?>">Dashboard</a></div></header>
<section class="intro"><div class="eyebrow">Assessment insights</div><h1>Question analytics<span>.</span></h1><p class="subtitle">See which questions and topics students find difficult, based on completed quiz attempts in your assigned modules.</p></section>
<?php if ($error !== ''): ?><div class="error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if (!$allowedIds): ?><div class="empty">No active module assignments found. Analytics will become available after an administrator assigns you a module.</div><?php else: ?>
<form class="panel filters" method="get"><div class="field"><label for="module_id">Assigned module</label><select id="module_id" name="module_id" onchange="this.form.submit()"><option value="">All assigned modules</option><?php foreach ($modules as $module): ?><option value="<?= (int)$module['id'] ?>" <?= $moduleId === (int)$module['id'] ? 'selected' : '' ?>><?= e((string)$module['module_code']) ?> — <?= e((string)$module['module_name']) ?></option><?php endforeach; ?></select></div><div class="field"><label for="quiz_id">Quiz</label><select id="quiz_id" name="quiz_id" required><option value="">Choose a quiz</option><?php foreach ($quizzes as $quiz): ?><option value="<?= (int)$quiz['id'] ?>" <?= $quizId === (int)$quiz['id'] ? 'selected' : '' ?>><?= e((string)$quiz['module_code']) ?> — <?= e((string)$quiz['title']) ?></option><?php endforeach; ?></select></div><button class="btn primary" type="submit">View analytics</button></form>
<?php if ($error === '' && $selectedQuiz): ?>
<div class="eyebrow"><?= e((string)$selectedQuiz['module_code']) ?> / <?= e((string)$selectedQuiz['title']) ?></div>
<div class="stats"><div class="stat"><div class="muted">Completed attempts</div><div class="number"><?= (int)$overview['attempts'] ?></div></div><div class="stat"><div class="muted">Students assessed</div><div class="number"><?= (int)$overview['students'] ?></div></div><div class="stat"><div class="muted">Average score</div><div class="number"><?= $overview['average'] === null ? '—' : e(number_format((float)$overview['average'], 1)) . '%' ?></div></div></div>
<div class="grid"><section class="panel"><div class="eyebrow">Learning gaps</div><h2>Topics needing attention</h2><p class="fine">Sorted from lowest to highest accuracy. Accuracy includes unanswered questions as incorrect.</p><?php if (!$topics): ?><div class="empty">No questions found.</div><?php else: foreach ($topics as $topic => $data): ?><div style="margin-top:16px"><div style="display:flex;justify-content:space-between;gap:10px"><strong><?= e($topic) ?></strong><span class="fine"><?= analyticsRate($data['correct'], $data['responses']) ?></span></div><div class="bar"><span style="width:<?= $data['responses'] ? max(0, min(100, 100 * $data['correct'] / $data['responses'])) : 0 ?>%"></span></div><div class="fine"><?= (int)$data['questions'] ?> questions · <?= (int)$data['responses'] ?> recorded responses</div></div><?php endforeach; endif; ?></section><section class="panel"><div class="eyebrow">How to interpret</div><h2>Answer accuracy</h2><p class="muted">Each completed attempt contributes one response per question when an answer record exists. Unanswered questions count as incorrect. Option counts show how often each choice was selected.</p><p class="fine">Results are aggregated across attempts, not unique students. If a question was edited after students submitted, the current question and option text may not match what they originally saw.</p></section></div>
<section style="margin-top:30px"><div class="eyebrow">Question breakdown</div><h2>Question-level performance</h2><?php if (!$questions): ?><div class="empty">This quiz has no questions yet.</div><?php else: foreach ($questions as $i => $question): $responses = (int)$question['responses']; $correct = (int)$question['correct_count']; ?><article class="question"><div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap"><span class="pill">Question <?= $i + 1 ?> · <?= e(trim((string)($question['topic'] ?? '')) ?: 'General') ?></span><strong><?= analyticsRate($correct, $responses) ?> correct</strong></div><h3><?= e((string)$question['question_text']) ?></h3><div class="bar"><span style="width:<?= $responses ? max(0, min(100, 100 * $correct / $responses)) : 0 ?>%"></span></div><p class="fine"><?= $correct ?> correct · <?= max(0, $responses - $correct) ?> incorrect/unanswered · <?= (int)$question['unanswered_count'] ?> unanswered · <?= $responses ?> recorded responses<?= !empty($question['difficulty']) ? ' · ' . e((string)$question['difficulty']) : '' ?></p><?php foreach ($question['options'] as $option): ?><div class="option <?= (int)$option['is_correct'] === 1 ? 'correct' : '' ?>"><span><?= (int)$option['is_correct'] === 1 ? '✓ ' : '' ?><?= e((string)$option['option_text']) ?></span><strong><?= (int)$option['selected_count'] ?> selected</strong></div><?php endforeach; ?></article><?php endforeach; endif; ?></section>
<?php elseif ($error === ''): ?><div class="empty">Select a quiz above to see question accuracy, answer choices and topic weaknesses.</div><?php endif; ?>
<?php endif; ?>
</main></div></body></html>
