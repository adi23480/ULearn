<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
$user = require_login();
if ((string)$user['role'] !== 'lecturer') { http_response_code(403); exit('Lecturer access only.'); }
$pdo = db();
$profile = $pdo->prepare('SELECT id FROM lecturer_profiles WHERE user_id=? AND is_active=1 LIMIT 1');
$profile->execute([(int)$user['id']]);
$lecturerId = (int)$profile->fetchColumn();
if (!$lecturerId) { http_response_code(403); exit('Active lecturer profile required.'); }
$modulesStmt = $pdo->prepare('SELECT DISTINCT m.id, m.module_code, m.module_name FROM lecturer_module_assignments a JOIN academic_curriculum c ON c.id=a.curriculum_id JOIN academic_modules m ON m.id=c.module_id JOIN academic_degrees d ON d.id=c.degree_id JOIN academic_batches b ON b.id=c.batch_id WHERE a.lecturer_id=? AND a.is_active=1 AND c.is_active=1 AND m.is_active=1 AND d.is_active=1 AND b.is_active=1 ORDER BY m.module_code');
$modulesStmt->execute([$lecturerId]);
$modules = $modulesStmt->fetchAll(PDO::FETCH_ASSOC);
$moduleIds = array_map('intval', array_column($modules, 'id'));
$requestedModule = filter_input(INPUT_GET, 'module_id', FILTER_VALIDATE_INT) ?: 0;
$requestedQuiz = filter_input(INPUT_GET, 'quiz_id', FILTER_VALIDATE_INT) ?: 0;
$error = '';
if ($requestedModule && !in_array($requestedModule, $moduleIds, true)) { http_response_code(403); $error = 'You are not assigned to this module.'; $requestedModule = -1; }
$quizzes = []; $attempts = []; $summary = ['total'=>0,'students'=>0,'average'=>null,'highest'=>null];
try {
    if ($moduleIds && $requestedModule !== -1) {
        $placeholders = implode(',', array_fill(0, count($moduleIds), '?'));
        $filter = 'q.module_id IN (' . $placeholders . ')';
        $args = $moduleIds;
        if ($requestedModule) { $filter .= ' AND q.module_id=?'; $args[] = $requestedModule; }
        $quizStmt = $pdo->prepare('SELECT q.id,q.title,q.module_id,m.module_code FROM quizzes q JOIN academic_modules m ON m.id=q.module_id WHERE ' . $filter . ' ORDER BY m.module_code,q.title');
        $quizStmt->execute($args);
        $quizzes = $quizStmt->fetchAll(PDO::FETCH_ASSOC);
        $quizIds = array_map('intval', array_column($quizzes, 'id'));
        if ($requestedQuiz && !in_array($requestedQuiz, $quizIds, true)) { http_response_code(403); $error = 'Quiz not available for your assigned modules.'; }
        else {
            $where = $filter . " AND a.status IN ('submitted','timed_out')";
            $params = $args;
            if ($requestedQuiz) { $where .= ' AND q.id=?'; $params[] = $requestedQuiz; }
            $base = ' FROM quiz_attempts a JOIN quizzes q ON q.id=a.quiz_id JOIN academic_modules m ON m.id=q.module_id JOIN users u ON u.id=a.user_id WHERE ' . $where;
            $summaryStmt = $pdo->prepare('SELECT COUNT(*) total, COUNT(DISTINCT a.user_id) students, AVG(CASE WHEN a.max_score>0 THEN 100.0*a.score/a.max_score ELSE NULL END) average, MAX(CASE WHEN a.max_score>0 THEN 100.0*a.score/a.max_score ELSE NULL END) highest' . $base);
            $summaryStmt->execute($params); $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: $summary;
            $attemptStmt = $pdo->prepare('SELECT a.id,a.status,a.score,a.max_score,a.started_at,a.submitted_at,u.full_name,u.email,q.title quiz_title,m.module_code,m.module_name' . $base . ' ORDER BY a.submitted_at DESC,a.id DESC LIMIT 200');
            $attemptStmt->execute($params); $attempts = $attemptStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch (Throwable $e) { error_log('ULearn lecturer performance: '.$e->getMessage()); $error = 'Unable to load performance data. Check your quiz database tables.'; }
$theme = (string)($user['theme'] ?? 'system');
function performancePercent($score,$max): string { return (float)$max > 0 ? number_format(100*(float)$score/(float)$max,1).'%' : 'N/A'; }
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Student Performance — ULearn</title>
<script>(function(){try{let t=localStorage.getItem('ulearn-theme');if(t!=='light'&&t!=='dark'){let p=<?= json_encode($theme, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;t=(p==='light'||p==='dark')?p:(matchMedia('(prefers-color-scheme:dark)').matches?'dark':'light');}document.documentElement.dataset.theme=t;}catch(e){}})();function toggleTheme(){let t=document.documentElement.dataset.theme==='dark'?'light':'dark';document.documentElement.dataset.theme=t;try{localStorage.setItem('ulearn-theme',t);}catch(e){}}</script>
<style>:root{color-scheme:light;--bg:#f7f8fc;--surface:#fff;--text:#171927;--muted:#777b90;--border:#e7e8f0;--accent:#6756e8;--soft:#f0edff}:root[data-theme=dark]{color-scheme:dark;--bg:#0c0e16;--surface:#161924;--text:#f4f4fa;--muted:#a0a4b6;--border:#2b2e3c;--accent:#a99aff;--soft:#292440}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,Segoe UI,Arial,sans-serif;line-height:1.5}a{color:inherit;text-decoration:none}.layout{display:grid;grid-template-columns:250px minmax(0,1fr);min-height:100vh}.sidebar{background:var(--surface);border-right:1px solid var(--border);padding:28px 15px}.brand{font-size:27px;font-weight:850;letter-spacing:-1.7px;padding:0 14px}.brand span{color:var(--accent)}.nav{display:grid;gap:7px;margin-top:35px}.nav a{padding:12px 14px;border-radius:13px;color:var(--muted);font-size:13px;font-weight:700}.nav a:hover,.nav a.active{background:var(--soft);color:var(--accent)}main{min-width:0;padding:30px clamp(18px,4vw,54px) 60px}.top{display:flex;align-items:center;justify-content:space-between;gap:15px}.eyebrow{font-size:11px;text-transform:uppercase;letter-spacing:1.5px;font-weight:800;color:var(--muted)}h1{font-size:clamp(36px,5vw,58px);letter-spacing:-.06em;line-height:1.1;margin:32px 0 10px}h1 span{color:var(--accent)}p{color:var(--muted)}.card{background:var(--surface);border:1px solid var(--border);border-radius:20px;padding:23px;margin-top:20px}.stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-top:26px}.stat{background:var(--surface);border:1px solid var(--border);border-radius:17px;padding:19px}.stat strong{display:block;font-size:30px;margin-top:8px}.stat small{color:var(--muted)}.filters{display:grid;grid-template-columns:1fr 1fr auto;align-items:end;gap:12px}label{display:block;font-size:12px;font-weight:750;margin-bottom:7px}select,button{font:inherit}select{width:100%;min-height:44px;border:1px solid var(--border);border-radius:10px;padding:10px;background:var(--bg);color:var(--text)}button,.btn{background:var(--accent);color:white;border:0;border-radius:10px;padding:12px 16px;cursor:pointer;font-weight:700}.theme{background:var(--surface);color:var(--text);border:1px solid var(--border)}.tablewrap{overflow-x:auto}table{border-collapse:collapse;width:100%;min-width:780px}th,td{text-align:left;padding:13px 12px;border-bottom:1px solid var(--border);font-size:13px}th{color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.7px}td small{display:block;color:var(--muted)}.notice{padding:14px;border-radius:12px;background:var(--soft);color:var(--text);margin-top:18px}.score{font-weight:800;color:var(--accent)}@media(max-width:900px){.layout{grid-template-columns:1fr}.sidebar{border-right:0;border-bottom:1px solid var(--border)}.nav{display:flex;flex-wrap:wrap;margin-top:12px}.stats{grid-template-columns:repeat(2,1fr)}.filters{grid-template-columns:1fr}}@media(max-width:450px){.stats{grid-template-columns:1fr 1fr}main{padding:20px 15px}}</style>
<link rel="stylesheet" href="<?= e(url('assets/css/lecturer-premium-v5.css?v=1')) ?>">
<script defer src="<?= e(url('assets/js/lecturer-premium-v5.js?v=1')) ?>"></script>
<script src="<?= htmlspecialchars(url('assets/js/theme-global.js?v=20261112-1'), ENT_QUOTES, 'UTF-8') ?>"></script>
</head>
<body><div class="layout"><aside class="sidebar"><a class="brand" href="<?= e(url('lecturer/index.php')) ?>">u learn<span>.</span></a><nav class="nav"><a href="<?= e(url('lecturer/index.php')) ?>">Overview</a><a href="<?= e(url('lecturer/index.php#modules')) ?>">My modules</a><a href="<?= e(url('lecturer/materials.php')) ?>">Study materials</a><a href="<?= e(url('lecturer/quizzes.php')) ?>">Quiz management</a><a class="active" href="<?= e(url('lecturer/performance.php')) ?>">Student performance</a></nav></aside><main><div class="top"><div class="eyebrow">U Learn / Lecturer space</div><div><button class="theme" type="button" onclick="toggleTheme()" aria-label="Toggle theme">☾ / ☀</button> <a class="btn" href="<?= e(url('lecturer/quizzes.php')) ?>">Quiz management</a></div></div><h1>Student performance<span>.</span></h1><p>Review completed quiz attempts in modules assigned to you. Scores are based on the points earned out of the maximum available.</p>
<?php if($error!==''): ?><div class="notice" role="alert"><?= e($error) ?></div><?php endif; ?>
<form class="card filters" method="get"><div><label for="module_id">Assigned module</label><select id="module_id" name="module_id"><option value="">All assigned modules</option><?php foreach($modules as $m): ?><option value="<?= (int)$m['id'] ?>" <?= $requestedModule===(int)$m['id']?'selected':'' ?>><?= e($m['module_code'].' — '.$m['module_name']) ?></option><?php endforeach; ?></select></div><div><label for="quiz_id">Quiz</label><select id="quiz_id" name="quiz_id"><option value="">All quizzes</option><?php foreach($quizzes as $q): ?><option value="<?= (int)$q['id'] ?>" <?= $requestedQuiz===(int)$q['id']?'selected':'' ?>><?= e($q['module_code'].' — '.$q['title']) ?></option><?php endforeach; ?></select></div><button type="submit">Apply filters</button></form>
<?php if(!$modules): ?><div class="notice">No active module assignments found. This page will show results once your lecturer account has an active curriculum assignment.</div><?php endif; ?>
<div class="stats"><div class="stat"><small>Completed attempts</small><strong><?= number_format((int)$summary['total']) ?></strong></div><div class="stat"><small>Students assessed</small><strong><?= number_format((int)$summary['students']) ?></strong></div><div class="stat"><small>Average score</small><strong><?= $summary['average']!==null?e(number_format((float)$summary['average'],1).'%'):'—' ?></strong></div><div class="stat"><small>Highest score</small><strong><?= $summary['highest']!==null?e(number_format((float)$summary['highest'],1).'%'):'—' ?></strong></div></div>
<section class="card"><h2>Recent completed attempts</h2><p>Latest 200 submissions, including timed-out attempts. Summary figures cover all matching completed attempts.</p><?php if(!$attempts): ?><div class="notice">No completed quiz attempts found for the selected filters.</div><?php else: ?><div class="tablewrap"><table><thead><tr><th>Student</th><th>Module</th><th>Quiz</th><th>Score</th><th>Percentage</th><th>Status</th><th>Submitted</th></tr></thead><tbody><?php foreach($attempts as $a): ?><tr><td><?= e((string)$a['full_name']) ?><small><?= e((string)$a['email']) ?></small></td><td><?= e((string)$a['module_code']) ?></td><td><?= e((string)$a['quiz_title']) ?></td><td><?= e((string)$a['score']) ?> / <?= e((string)$a['max_score']) ?></td><td class="score"><?= e(performancePercent($a['score'],$a['max_score'])) ?></td><td><?= e(str_replace('_',' ',(string)$a['status'])) ?></td><td><?= e((string)($a['submitted_at']??'—')) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section></main></div>
</body></html>
