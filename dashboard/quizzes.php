<?php



declare(strict_types=1);







require_once __DIR__ . '/../config/bootstrap.php';



$user = require_login();



if ((string)$user['role'] !== 'student') {



    http_response_code(403);



    exit('Student access only. Please sign in with a student account.');



}



$pdo = db();



$userId = (int)$user['id'];



$error = '';



$notice = '';



$attempt = null;



$questions = [];



$result = null;



$resultQuestions = [];

// Optional module-specific Quiz Arena view. All other views remain unchanged.
$moduleFilterId = null;
$moduleFilter = null;
if (isset($_GET['module_id']) && $_GET['module_id'] !== '') {
    $moduleFilterId = filter_var($_GET['module_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($moduleFilterId === false) {
        http_response_code(400);
        exit('Invalid module ID.');
    }
    $filterStmt = $pdo->prepare('SELECT m.id, m.module_code, m.module_name FROM academic_modules m JOIN student_module_enrollments e ON e.module_id=m.id AND e.user_id=? WHERE m.id=? AND m.is_active=1 LIMIT 1');
    $filterStmt->execute([$userId, $moduleFilterId]);
    $moduleFilter = $filterStmt->fetch() ?: null;
    if (!$moduleFilter) {
        http_response_code(403);
        exit('This module is not in your enrollments. Enroll first through Explore & Enroll.');
    }
}








function studentQuizRedirect(string $query = ''): never {



    go('dashboard/quizzes.php' . ($query !== '' ? '?' . $query : ''));



}



function studentQuizAvailable(array $quiz): bool {



    $now = time();



    if (!empty($quiz['available_from']) && strtotime((string)$quiz['available_from']) > $now) return false;



    if (!empty($quiz['available_until']) && strtotime((string)$quiz['available_until']) < $now) return false;



    return true;



}







try {







    if ($_SERVER['REQUEST_METHOD'] === 'POST') {



        csrf_check();



        $action = (string)($_POST['action'] ?? '');







        if ($action === 'start') {





            $quizId = filter_var($_POST['quiz_id'] ?? null, FILTER_VALIDATE_INT);



            if (!$quizId) throw new RuntimeException('Invalid quiz selected.');



            $stmt = $pdo->prepare("SELECT q.*, m.module_code, m.module_name,



                    (SELECT COUNT(*) FROM quiz_questions qq WHERE qq.quiz_id=q.id) AS question_count



                FROM quizzes q



                LEFT JOIN academic_modules m ON m.id=q.module_id
                WHERE q.id=? AND q.is_published=1 AND (q.module_id IS NULL AND EXISTS (SELECT 1 FROM quiz_teaching_targets qt JOIN student_enrollments se ON se.degree_id=qt.degree_id AND se.batch_id=qt.batch_id WHERE qt.quiz_id=q.id AND se.student_id=? AND se.is_active=1) OR q.module_id IS NOT NULL AND EXISTS (SELECT 1 FROM student_module_enrollments sme WHERE sme.module_id=q.module_id AND sme.user_id=? AND (NOT EXISTS (SELECT 1 FROM quiz_teaching_targets qt0 WHERE qt0.quiz_id=q.id) OR EXISTS (SELECT 1 FROM quiz_teaching_targets qt JOIN student_enrollments se ON se.student_id=sme.user_id AND se.degree_id=qt.degree_id AND se.batch_id=qt.batch_id AND (qt.study_year=0 OR qt.study_year=se.study_year) AND (qt.semester=0 OR qt.semester=se.semester) AND se.is_active=1 WHERE qt.quiz_id=q.id)))) LIMIT 1");



            $stmt->execute([$quizId, $userId, $userId]);



            $quiz = $stmt->fetch();



            if (!$quiz) throw new RuntimeException('Enroll in this module through Explore & Enroll before starting its quiz.');



            if (!studentQuizAvailable($quiz)) throw new RuntimeException('This quiz is outside its availability window.');



            if ((int)$quiz['question_count'] < 1) throw new RuntimeException('This quiz has no questions yet.');



            $stmt = $pdo->prepare("INSERT INTO quiz_attempts (quiz_id, user_id, started_at, status, score, max_score) VALUES (?, ?, NOW(), 'in_progress', 0, ?)");



            $stmt->execute([$quizId, $userId, (int)$quiz['question_count'] * (float)$quiz['points_per_question']]);



            studentQuizRedirect('attempt=' . (int)$pdo->lastInsertId());



        }







        if ($action === 'submit') {



            $attemptId = filter_var($_POST['attempt_id'] ?? null, FILTER_VALIDATE_INT);



            if (!$attemptId) throw new RuntimeException('Invalid quiz attempt.');



            $stmt = $pdo->prepare("SELECT a.*, q.duration_minutes, q.points_per_question, q.title



                FROM quiz_attempts a JOIN quizzes q ON q.id=a.quiz_id



                WHERE a.id=? AND a.user_id=? LIMIT 1");



            $stmt->execute([$attemptId, $userId]);



            $attemptRow = $stmt->fetch();



            if (!$attemptRow) throw new RuntimeException('Quiz attempt not found.');



            if ($attemptRow['status'] !== 'in_progress') studentQuizRedirect('result=' . $attemptId);







            $elapsed = time() - strtotime((string)$attemptRow['started_at']);



            $timedOut = $elapsed > ((int)$attemptRow['duration_minutes'] * 60 + 15);



            $qStmt = $pdo->prepare('SELECT id FROM quiz_questions WHERE quiz_id=? ORDER BY id ASC');



            $qStmt->execute([(int)$attemptRow['quiz_id']]);



            $questionIds = array_map('intval', array_column($qStmt->fetchAll(), 'id'));



            $pointsPerQuestion = (float)$attemptRow['points_per_question'];



            $score = 0.0;



            $maxScore = count($questionIds) * $pointsPerQuestion;







            $pdo->beginTransaction();



            try {



                $answerStmt = $pdo->prepare('INSERT INTO quiz_attempt_answers (attempt_id, question_id, option_id, is_correct, points_awarded) VALUES (?, ?, ?, ?, ?)');



                $checkOption = $pdo->prepare('SELECT id, is_correct FROM quiz_options WHERE id=? AND question_id=?');



                foreach ($questionIds as $questionId) {



                    $selected = 0;



                    if (!$timedOut) {



                        $posted = $_POST['answers'][$questionId] ?? null;



                        if (is_scalar($posted)) $selected = (int)$posted;



                    }



                    $isCorrect = 0;



                    $optionId = null;



                    if ($selected > 0) {



                        $checkOption->execute([$selected, $questionId]);



                        $option = $checkOption->fetch();



                        if ($option) {



                            $optionId = (int)$option['id'];



                            $isCorrect = (int)$option['is_correct'] === 1 ? 1 : 0;



                        }



                    }



                    $awarded = $isCorrect ? $pointsPerQuestion : 0.0;



                    $score += $awarded;



                    $answerStmt->execute([$attemptId, $questionId, $optionId, $isCorrect, $awarded]);



                }



                $status = $timedOut ? 'timed_out' : 'submitted';



                $update = $pdo->prepare('UPDATE quiz_attempts SET submitted_at=NOW(), status=?, score=?, max_score=? WHERE id=? AND user_id=? AND status=\'in_progress\'');



                $update->execute([$status, $score, $maxScore, $attemptId, $userId]);



                $pdo->commit();
                // XP awards are idempotent. Quiz results must remain saved even if scoring fails.
                try {
                    require_once __DIR__ . '/../scoring/scoring_engine.php';
                    awardQuizXp($pdo, $userId, $attemptId);
                } catch (Throwable $xpError) {
                    error_log('ULearn leaderboard XP: '.$xpError->getMessage());
                }




            } catch (Throwable $e) {



                if ($pdo->inTransaction()) $pdo->rollBack();



                throw $e;



            }



            studentQuizRedirect('result=' . $attemptId);



        }







        throw new RuntimeException('Unknown quiz action.');



    }







    if (isset($_GET['attempt'])) {



        $attemptId = filter_var($_GET['attempt'], FILTER_VALIDATE_INT);



        $stmt = $pdo->prepare("SELECT a.*, q.title, q.description, q.duration_minutes, q.points_per_question, m.module_code, m.module_name



            FROM quiz_attempts a JOIN quizzes q ON q.id=a.quiz_id LEFT JOIN academic_modules m ON m.id=q.module_id



            WHERE a.id=? AND a.user_id=? LIMIT 1");



        $stmt->execute([(int)$attemptId, $userId]);



        $attempt = $stmt->fetch() ?: null;



        if (!$attempt) throw new RuntimeException('Quiz attempt not found.');



        if ($attempt['status'] !== 'in_progress') studentQuizRedirect('result=' . (int)$attempt['id']);



        $elapsed = max(0, time() - strtotime((string)$attempt['started_at']));



        if ($elapsed > (int)$attempt['duration_minutes'] * 60 + 15) {



            // Let the student submit the timed-out attempt; the server records it as timed out.



            $notice = 'Time is up. Submit now to record your attempt.';



        }



        $stmt = $pdo->prepare('SELECT id, question_text, topic, difficulty FROM quiz_questions WHERE quiz_id=? ORDER BY id ASC');



        $stmt->execute([(int)$attempt['quiz_id']]);



        $questions = $stmt->fetchAll();



        $optionsStmt = $pdo->prepare('SELECT id, option_text FROM quiz_options WHERE question_id=? ORDER BY id ASC');



        foreach ($questions as &$question) {



            $optionsStmt->execute([(int)$question['id']]);



            $question['options'] = $optionsStmt->fetchAll();



        }



        unset($question);



    } elseif (isset($_GET['result'])) {



        $resultId = filter_var($_GET['result'], FILTER_VALIDATE_INT);



        $stmt = $pdo->prepare("SELECT a.*, q.title, q.duration_minutes, q.points_per_question, m.module_code, m.module_name



            FROM quiz_attempts a JOIN quizzes q ON q.id=a.quiz_id LEFT JOIN academic_modules m ON m.id=q.module_id



            WHERE a.id=? AND a.user_id=? LIMIT 1");



        $stmt->execute([(int)$resultId, $userId]);



        $result = $stmt->fetch() ?: null;



        if (!$result || $result['status'] === 'in_progress') throw new RuntimeException('Submitted result not found.');



        $stmt = $pdo->prepare("SELECT q.id, q.question_text, q.topic, q.difficulty, q.explanation AS question_explanation,



                aa.option_id AS selected_option_id, aa.is_correct, aa.points_awarded,



                correct.id AS correct_option_id, correct.option_text AS correct_option_text,



                selected.option_text AS selected_option_text, aa.points_awarded



            FROM quiz_questions q



            LEFT JOIN quiz_attempt_answers aa ON aa.question_id=q.id AND aa.attempt_id=?



            LEFT JOIN quiz_options correct ON correct.question_id=q.id AND correct.is_correct=1



            LEFT JOIN quiz_options selected ON selected.id=aa.option_id



            WHERE q.quiz_id=? ORDER BY q.id ASC");



        $stmt->execute([(int)$resultId, (int)$result['quiz_id']]);



        $resultQuestions = $stmt->fetchAll();



        $resultOptionsStmt = $pdo->prepare('SELECT id, option_text, is_correct, explanation FROM quiz_options WHERE question_id=? ORDER BY id ASC');



        foreach ($resultQuestions as &$rq) {



            $resultOptionsStmt->execute([(int)$rq['id']]);



            $rq['options'] = $resultOptionsStmt->fetchAll();



        }



        unset($rq);



    }







    $availableQuizzes = [];





    if (!$attempt && !$result) {



        $stmt = $pdo->prepare("SELECT q.id, q.title, q.description, q.quiz_type, q.duration_minutes, q.points_per_question,



                q.available_from, q.available_until, m.module_code, m.module_name,



                (SELECT COUNT(*) FROM quiz_questions qq WHERE qq.quiz_id=q.id) AS question_count



            FROM quizzes q LEFT JOIN academic_modules m ON m.id=q.module_id



            WHERE ((q.module_id IS NULL AND EXISTS (SELECT 1 FROM quiz_teaching_targets qt JOIN student_enrollments se ON se.degree_id=qt.degree_id AND se.batch_id=qt.batch_id WHERE qt.quiz_id=q.id AND se.student_id=? AND se.is_active=1)) OR (q.module_id IS NOT NULL AND EXISTS (SELECT 1 FROM student_module_enrollments sme WHERE sme.module_id=q.module_id AND sme.user_id=? AND (NOT EXISTS (SELECT 1 FROM quiz_teaching_targets qt0 WHERE qt0.quiz_id=q.id) OR EXISTS (SELECT 1 FROM quiz_teaching_targets qt JOIN student_enrollments se ON se.student_id=sme.user_id AND se.degree_id=qt.degree_id AND se.batch_id=qt.batch_id AND (qt.study_year=0 OR qt.study_year=se.study_year) AND (qt.semester=0 OR qt.semester=se.semester) AND se.is_active=1 WHERE qt.quiz_id=q.id)))))
            AND q.is_published=1 AND (q.module_id IS NULL OR m.is_active=1)" . ($moduleFilterId !== null ? " AND m.id=?" : "") . "



            ORDER BY m.module_code, q.title");



        $stmt->execute($moduleFilterId !== null ? [$userId, $userId, $moduleFilterId] : [$userId, $userId]);



        foreach ($stmt->fetchAll() as $quiz) {



            if (studentQuizAvailable($quiz) && (int)$quiz['question_count'] > 0) $availableQuizzes[] = $quiz;



        }





    }



} catch (Throwable $e) {



    if ($e instanceof RuntimeException) $error = $e->getMessage();



    else {



        error_log('ULearn student quiz error: ' . $e->getMessage());



        $error = 'The quiz area could not load. Confirm the quiz-attempt migration has been applied and try again.';



    }



}







$firstName = preg_split('/\s+/', trim((string)$user['full_name']))[0] ?? 'Student';



$elapsedSeconds = $attempt ? max(0, time() - strtotime((string)$attempt['started_at'])) : 0;



$remainingSeconds = $attempt ? max(0, (int)$attempt['duration_minutes'] * 60 - $elapsedSeconds) : 0;



?>



<!DOCTYPE html>



<html lang="en">



<head>



<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">



<title>Quiz Arena — ULearn</title>



<script>(function(){const saved=localStorage.getItem('ulearn-theme');const theme=saved==='light'||saved==='dark'?saved:(window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light');document.documentElement.dataset.theme=theme;})();</script>



<link rel="stylesheet" href="../assets/css/style.css">



<style>



:root{--quiz-bg:#f7f8fc;--quiz-card:#fff;--quiz-text:#171927;--quiz-muted:#777b90;--quiz-border:#e7e8f0;--quiz-accent:#6756e8;--quiz-soft:#f0edff}



:root[data-theme="dark"]{--quiz-bg:#0c0e16;--quiz-card:#161924;--quiz-text:#f4f4fa;--quiz-muted:#a0a4b6;--quiz-border:#2b2e3c;--quiz-accent:#a99aff;--quiz-soft:#292440}



body{background:var(--quiz-bg);color:var(--quiz-text)}.quiz-wrap{max-width:1080px;margin:0 auto;padding:32px 22px 64px}.quiz-top{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:24px}.quiz-top a,.quiz-btn{display:inline-flex;align-items:center;justify-content:center;text-decoration:none;border:1px solid var(--quiz-border);border-radius:12px;padding:10px 15px;color:var(--quiz-text);background:var(--quiz-card);font-weight:650;cursor:pointer}.quiz-btn.primary{background:var(--quiz-accent);color:#fff;border-color:transparent}.quiz-hero{padding:26px;border:1px solid var(--quiz-border);border-radius:22px;background:var(--quiz-card);margin-bottom:22px}.quiz-hero h1{font-size:clamp(28px,4vw,40px);line-height:1.1;margin:8px 0 12px;letter-spacing:-.04em}.muted{color:var(--quiz-muted)}.quiz-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px}.quiz-card,.question-card,.result-card,.panel{background:var(--quiz-card);border:1px solid var(--quiz-border);border-radius:18px;padding:20px}.quiz-card h2,.question-card h2,.result-card h2{margin:8px 0 10px;font-size:19px}.quiz-meta{display:flex;flex-wrap:wrap;gap:8px;margin:12px 0;color:var(--quiz-muted);font-size:13px}.pill{display:inline-flex;padding:5px 9px;border-radius:999px;background:var(--quiz-soft);color:var(--quiz-accent);font-size:12px;font-weight:700}.notice,.error{padding:13px 15px;border-radius:12px;margin:0 0 18px}.notice{background:#e9f8ef;color:#166534}.error{background:#fff0f0;color:#9f1239}.question-card{margin:14px 0}.question-title{font-weight:700;font-size:18px;white-space:pre-wrap}.option{display:flex;gap:10px;align-items:flex-start;padding:12px;border:1px solid var(--quiz-border);border-radius:12px;margin-top:9px;cursor:pointer}.option input{margin-top:5px;accent-color:var(--quiz-accent)}.timer{font-variant-numeric:tabular-nums;font-weight:800;color:var(--quiz-accent);font-size:18px}.result-score{font-size:clamp(34px,6vw,54px);font-weight:850;letter-spacing:-.04em}.review-option{padding:10px 12px;border:1px solid var(--quiz-border);border-radius:10px;margin-top:8px}.review-option.correct{border-color:#22a06b;background:rgba(34,160,107,.08)}.review-option.chosen-wrong{border-color:#dc6262;background:rgba(220,98,98,.08)}.btn-row{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}.empty{padding:24px;border:1px dashed var(--quiz-border);border-radius:16px}.topline{font-size:12px;text-transform:uppercase;letter-spacing:.12em;color:var(--quiz-muted);font-weight:800}@media(max-width:640px){.quiz-wrap{padding:20px 14px 44px}.quiz-top{align-items:flex-start;flex-direction:column}.quiz-hero{padding:20px}}



 .review-details{margin:12px 0;padding:0;overflow:hidden}.review-details summary{display:flex;flex-direction:column;gap:7px;position:relative;cursor:pointer;padding:20px 160px 20px 20px;list-style:none}.review-details summary::-webkit-details-marker{display:none}.review-details summary:focus-visible{outline:3px solid var(--quiz-accent);outline-offset:-3px}.review-question{font-size:17px;font-weight:700;white-space:pre-wrap}.review-toggle{position:absolute;right:20px;top:50%;transform:translateY(-50%);border:1px solid var(--quiz-border);border-radius:10px;padding:7px 10px;color:var(--quiz-accent);font-size:13px;font-weight:700}.review-details[open] .review-toggle{font-size:0}.review-details[open] .review-toggle::after{content:"Hide answers";font-size:13px}.review-body{padding:0 20px 20px;border-top:1px solid var(--quiz-border)}@media(max-width:640px){.review-details summary{padding:18px 18px 58px}.review-toggle{top:auto;bottom:12px;left:18px;right:auto;transform:none}}


/* ULearn contextual AI answer review */
.ai-review-panel{margin-top:20px;padding:20px;border:1px solid #ddd9fa;background:linear-gradient(145deg,#faf9ff,#fff);border-radius:17px}
.ai-review-heading{display:flex;align-items:flex-start;gap:12px}.ai-review-heading strong{display:block;font-size:15px;color:#302b59}.ai-review-heading span:not(.ai-review-symbol){display:block;font-size:13px;color:#6f7190;margin-top:5px;line-height:1.6}
.ai-review-symbol{display:grid;place-items:center;flex-shrink:0;width:36px;height:36px;border-radius:11px;background:#eeeaff;color:#6552d5;font-size:21px}
.ai-review-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:17px}.ai-review-actions .quiz-btn{font-size:13px}.ai-youtube{color:#b52e3d!important;background:#fff!important}.ai-youtube:hover{border-color:#e5a3aa}
.ai-review-output{margin-top:17px;border-top:1px solid #e7e3f6;padding-top:16px;white-space:pre-wrap;overflow-wrap:anywhere;font-size:14px;line-height:1.8;color:#343650}.ai-review-output[hidden]{display:none}.ai-review-trigger:disabled{opacity:.65;cursor:wait}
</style>




<script src="<?= htmlspecialchars(url('assets/js/theme-global.js?v=20261107-2'), ENT_QUOTES, 'UTF-8') ?>"></script>
<link rel="stylesheet" href="<?= e(url('assets/css/ulearn-design-system.css?v=1')) ?>">
<script defer src="<?= e(url('assets/js/ulearn-motion.js?v=1')) ?>"></script>
<link rel="stylesheet" href="<?= e(url('assets/css/ulearn-student-workspaces.css?v=3')) ?>">
</head>



<body class="ul3-quizzes">



<main class="quiz-wrap">



<div class="quiz-top"><div><div class="topline">ULearn · Student workspace</div><strong>Quiz Arena</strong></div><div class="btn-row" style="margin-top:0"><a href="quiz-history.php">My Quiz History</a><?php if ($moduleFilter): ?><a href="module.php?module_id=<?= (int)$moduleFilterId ?>">Back to Module</a><?php endif; ?><a href="explore.php">Explore modules</a><a href="index.php">← Back to dashboard</a></div></div>



<?php if ($error !== ''): ?><div class="error"><?= e($error) ?></div><?php endif; ?>



<?php if ($notice !== ''): ?><div class="notice"><?= e($notice) ?></div><?php endif; ?>



<?php if ($attempt): ?>



<section class="quiz-hero"><div class="topline"><?= e((string)($attempt['module_code'] ?? 'PROGRAMME')) ?> · <?= e((string)($attempt['module_name'] ?? 'Degree programme quiz')) ?></div><h1><?= e((string)$attempt['title']) ?></h1><p class="muted"><?= e((string)($attempt['description'] ?? 'Answer each question, then submit to see your score and explanations.')) ?></p><div class="quiz-meta"><span class="pill"><?= count($questions) ?> questions</span><span class="pill"><?= (int)$attempt['duration_minutes'] ?> minutes</span><span>Time remaining: <span class="timer" id="quizTimer"><?= gmdate('i:s', $remainingSeconds) ?></span></span></div></section>



<form method="post" id="quizForm"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="submit"><input type="hidden" name="attempt_id" value="<?= (int)$attempt['id'] ?>">



<?php foreach ($questions as $i => $question): ?><section class="question-card"><div class="topline">Question <?= $i+1 ?> of <?= count($questions) ?></div><h2 class="question-title"><?= e((string)$question['question_text']) ?></h2><div class="quiz-meta"><?php if (!empty($question['topic'])): ?><span class="pill"><?= e((string)$question['topic']) ?></span><?php endif; ?><?php if (!empty($question['difficulty'])): ?><span><?= e((string)$question['difficulty']) ?></span><?php endif; ?></div><?php foreach ($question['options'] as $option): ?><label class="option"><input type="radio" name="answers[<?= (int)$question['id'] ?>]" value="<?= (int)$option['id'] ?>"><span><?= e((string)$option['option_text']) ?></span></label><?php endforeach; ?></section><?php endforeach; ?>



<div class="btn-row"><button type="submit" class="quiz-btn primary" onclick="return confirm('Submit this quiz now? You cannot change answers after submission.')">Submit quiz</button><a class="quiz-btn" href="index.php">Save nothing and leave</a></div></form>



<script>(function(){let remaining=<?= (int)$remainingSeconds ?>;const el=document.getElementById('quizTimer');const form=document.getElementById('quizForm');function tick(){if(remaining<=0){el.textContent='00:00';el.style.color='#dc2626';form.requestSubmit();return;}el.textContent=String(Math.floor(remaining/60)).padStart(2,'0')+':'+String(remaining%60).padStart(2,'0');remaining--;setTimeout(tick,1000);}tick();})();</script>



<?php elseif ($result): ?>



<section class="quiz-hero"><div class="topline">Quiz results · <?= e((string)($result['module_code'] ?? 'PROGRAMME')) ?></div><h1><?= e((string)$result['title']) ?></h1><div class="result-score"><?= e(rtrim(rtrim(number_format((float)$result['score'],2,'.',''),'0'),'.')) ?> <span class="muted" style="font-size:20px">/ <?= e(rtrim(rtrim(number_format((float)$result['max_score'],2,'.',''),'0'),'.')) ?></span></div><p class="muted"><?= $result['max_score'] > 0 ? (int)round(((float)$result['score']/(float)$result['max_score'])*100) : 0 ?>% · <?= $result['status'] === 'timed_out' ? 'Time expired' : 'Submitted' ?> · <?= !empty($result['submitted_at']) ? e(date('d M Y, H:i', strtotime((string)$result['submitted_at']))) : '' ?></p><div class="btn-row"><a class="quiz-btn primary" href="quiz-history.php">Back to Quiz History</a><a class="quiz-btn" href="quizzes.php">Quiz Arena</a></div></section>



<p style="margin:16px 0"><a class="quiz-btn primary" href="<?= e(url('dashboard/ai-study.php')) ?>">✦ Ask AI to explain a question</a></p>
<h2>Review your answers</h2><p class="muted">Select a question to show or hide its answers and explanations.</p>



<?php foreach ($resultQuestions as $i => $rq):
$needsHelp = (int)($rq['is_correct'] ?? 0) !== 1;
$topicSearch = trim((string)($rq['topic'] ?? ''));
if ($topicSearch === '' || strcasecmp($topicSearch, 'General') === 0) $topicSearch = mb_substr((string)$rq['question_text'], 0, 120);
$youtubeSearchUrl = 'https://www.youtube.com/results?search_query=' . rawurlencode($topicSearch . ' tutorial');
?><details class="result-card review-details"><summary><span class="topline">Question <?= $i+1 ?> · <?= e((string)($rq['topic'] ?: 'General')) ?></span><span class="review-question"><?= e((string)$rq['question_text']) ?></span><span class="review-toggle">Show answers</span></summary><div class="review-body"><?php foreach ($rq['options'] as $opt): $isCorrect=(int)$opt['is_correct']===1; $isChosen=(int)$opt['id']===(int)($rq['selected_option_id'] ?? 0); ?><div class="review-option <?= $isCorrect?'correct':($isChosen?'chosen-wrong':'') ?>"><strong><?= $isCorrect?'Correct answer':($isChosen?'Your answer':'Option') ?><?= $isCorrect||$isChosen?':':'' ?></strong> <?= e((string)$opt['option_text']) ?><?php if (!empty($opt['explanation'])): ?><div class="muted" style="margin-top:5px;font-size:14px"><?= e((string)$opt['explanation']) ?></div><?php endif; ?></div><?php endforeach; ?><?php if (!empty($rq['question_explanation'])): ?><p><strong>Explanation:</strong> <?= e((string)$rq['question_explanation']) ?></p><?php endif; ?>
<?php if ($needsHelp): ?>
<div class="ai-review-panel" data-ai-review>
  <div class="ai-review-heading"><span class="ai-review-symbol">✦</span><div><strong>Understand your mistake</strong><span>Get a clear explanation, then explore this topic on YouTube.</span></div></div>
  <div class="ai-review-actions">
    <button type="button" class="quiz-btn primary ai-review-trigger" data-attempt="<?= (int)$result['id'] ?>" data-question="<?= (int)$rq['id'] ?>">✦ Ask AI to explain</button>
    <a class="quiz-btn ai-youtube" href="<?= e($youtubeSearchUrl) ?>" target="_blank" rel="noopener noreferrer" aria-label="Search YouTube tutorials about <?= e($topicSearch) ?>">▶ YouTube tutorials ↗</a>
  </div>
  <div class="ai-review-output" role="status" aria-live="polite" hidden></div>
</div>
<?php endif; ?>
<p class="muted">Points earned: <?= e(rtrim(rtrim(number_format((float)$rq['points_awarded'],2,'.',''),'0'),'.')) ?></p></div></details><?php endforeach; ?>



<?php else: ?>



<section class="quiz-hero"><div class="topline">Practice · Learn · Improve</div><h1><?= $moduleFilter ? e((string)$moduleFilter['module_name']) . ' Quizzes' : 'Your Quiz Arena' ?></h1><p class="muted"><?= $moduleFilter ? 'Practice quizzes for this enrolled module.' : 'Take published quizzes from modules you have enrolled in.' ?> Answers and explanations appear after submission.</p></section>



<?php if ($moduleFilter): ?><p><a class="quiz-btn" href="quizzes.php">View all enrolled-module quizzes</a></p><?php endif; ?>
<?php if (!$availableQuizzes): ?><div class="empty"><h2><?= $moduleFilter ? 'No published quizzes for this module yet' : 'No published quizzes available yet' ?></h2><p class="muted"><?= $moduleFilter ? 'Published quizzes with questions will appear here when available.' : 'Enroll in a module through Explore &amp; Enroll. Published quizzes with questions will appear here when available.' ?></p><a class="quiz-btn primary" href="<?= $moduleFilter ? 'quizzes.php' : 'explore.php' ?>"><?= $moduleFilter ? 'View All My Quizzes' : 'Explore modules' ?></a></div>



<?php else: ?><div class="quiz-grid"><?php foreach ($availableQuizzes as $quiz): ?><article class="quiz-card"><div class="topline"><?= e((string)($quiz['module_code'] ?? 'PROGRAMME')) ?> · <?= e((string)($quiz['module_name'] ?? 'Degree programme quiz')) ?></div><h2><?= e((string)$quiz['title']) ?></h2><p class="muted"><?= e((string)($quiz['description'] ?: 'Practice questions for this module.')) ?></p><div class="quiz-meta"><span class="pill"><?= (int)$quiz['question_count'] ?> questions</span><span><?= (int)$quiz['duration_minutes'] ?> min</span><span><?= e(ucfirst((string)$quiz['quiz_type'])) ?></span></div><?php if (!empty($quiz['available_until'])): ?><p class="muted" style="font-size:13px">Closes <?= e(date('d M Y H:i', strtotime((string)$quiz['available_until']))) ?> · <strong class="quiz-countdown" data-until="<?= e(date('c',strtotime((string)$quiz['available_until']))) ?>">Calculating…</strong></p><?php endif; ?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="start"><input type="hidden" name="quiz_id" value="<?= (int)$quiz['id'] ?>"><button class="quiz-btn primary" type="submit">Start quiz →</button></form></article><?php endforeach; ?></div><?php endif; ?>







<?php endif; ?>



</main>



<script>
(function(){function update(){document.querySelectorAll('.quiz-countdown').forEach(function(el){let left=Math.max(0,Math.floor((new Date(el.dataset.until).getTime()-Date.now())/1000));if(!left){el.textContent='Expired';return;}const days=Math.floor(left/86400);left%=86400;const h=Math.floor(left/3600),m=Math.floor(left%3600/60),sec=left%60;el.textContent=days+'d '+String(h).padStart(2,'0')+'h '+String(m).padStart(2,'0')+'m '+String(sec).padStart(2,'0')+'s remaining';});}update();setInterval(update,1000);})();
</script>
<script>
(function(){
  const endpoint = <?= json_encode(url('dashboard/ai-explain.php')) ?>;
  const csrfToken = <?= json_encode(csrf()) ?>;
  document.querySelectorAll('.ai-review-trigger').forEach(function(button){
    button.addEventListener('click', async function(){
      const panel=button.closest('[data-ai-review]');
      const output=panel.querySelector('.ai-review-output');
      button.disabled=true;button.textContent='✦ Generating explanation…';
      output.hidden=false;
      
      // FIX 1: Changed textContent to innerHTML to render HTML tags properly
      output.innerHTML='<em>Your AI tutor is preparing a summary…</em>';
      
      try {
        const body=new URLSearchParams({csrf:csrfToken,attempt_id:button.dataset.attempt,question_id:button.dataset.question});
        const response=await fetch(endpoint,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded','Accept':'application/json'},body:body.toString()});
        const payload=await response.json();
        
        if(!response.ok || !payload.explanation) throw new Error(payload.error||'Unable to generate an explanation right now.');
        
        // FIX 2: Changed textContent to innerHTML to render the final response
        output.innerHTML=payload.explanation;
        
        button.textContent='↻ Regenerate explanation';
      }catch(error){
        
        // FIX 3: Changed textContent to innerHTML for error messages
        output.innerHTML=error.message||'AI service unavailable. Please try again later.';
        button.textContent='✦ Try again';
        
      }
      finally{button.disabled=false;}
    });
  });
})();
</script>
</body></html>
