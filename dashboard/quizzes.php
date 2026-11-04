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

// Handle AJAX AI Explanation & YouTube Recommendation Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ask_ai') {
    header('Content-Type: application/json');
    try {
        csrf_check();
        $questionText = trim((string)($_POST['question_text'] ?? ''));
        $selectedText = trim((string)($_POST['selected_text'] ?? 'None / Timeout'));
        $correctText = trim((string)($_POST['correct_text'] ?? ''));
        $topic = trim((string)($_POST['topic'] ?? 'General Concept'));

        $geminiKey = 'AQ.Ab8RN6ICdE2ES1yrj214gOiqP9OAi40ofcjD3F8f3qdkEW_Yyw';
        $openRouterKey = 'sk-or-v1-288d49d7927c5c75000000000000000000000000000000000000000000000000000000000000000000ff8dcb28539ba2';

        $prompt = "You are an expert, encouraging personal tutor explaining concepts to a beginner student.\nTopic: {$topic}\nQuestion: {$questionText}\nStudent's wrong answer: {$selectedText}\nCorrect answer: {$correctText}\n\nProvide:\n1. A crystal-clear, step-by-step explanation starting from scratch using simple analogies so anyone can understand why the student's answer was incorrect and why the correct answer is right.\n2. A precise YouTube search query string (enclosed in [YOUTUBE_QUERY: ... ]) for a tutorial video explaining this topic.";

        $aiText = '';
        $ytQuery = $topic;
        $sourceModel = '';
        $debugLog = [];

        // --- ATTEMPT 1: Gemini 3.8 Flash ---
        $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent?key=" . $geminiKey);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'contents' => [['parts' => [['text' => $prompt]]]]
        ]));
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            $aiText = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
            if (trim($aiText) !== '') $sourceModel = 'Gemini 3.8 Flash';
        } else {
            $debugLog[] = "Gemini Flash (Code $httpCode): " . ($response ? $response : $curlError);
        }

        // --- ATTEMPT 2: Gemini 3.5 Flash Lite ---
        if (trim($aiText) === '') {
            $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:generateContent?key=" . $geminiKey);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                'contents' => [['parts' => [['text' => $prompt]]]]
            ]));
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($httpCode === 200 && $response) {
                $data = json_decode($response, true);
                $aiText = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
                if (trim($aiText) !== '') $sourceModel = 'Gemini 3.5 Flash Lite';
            } else {
                $debugLog[] = "Gemini Lite (Code $httpCode): " . ($response ? $response : $curlError);
            }
        }

        // --- ATTEMPT 3: OpenRouter ---
        if (trim($aiText) === '') {
            $ch = curl_init("https://openrouter.ai/api/v1/chat/completions");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $openRouterKey,
                'HTTP-Referer: http://localhost', // Simplified referer for OR rules
                'X-Title: ULearn Study Assistant'
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                'model' => 'meta-llama/llama-3.1-8b-instruct:free', // Using a reliable free model as ultimate fallback
                'messages' => [['role' => 'user', 'content' => $prompt]]
            ]));
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($httpCode === 200 && $response) {
                $data = json_decode($response, true);
                $aiText = $data['choices'][0]['message']['content'] ?? '';
                if (trim($aiText) !== '') $sourceModel = 'OpenRouter (Llama)';
            } else {
                $debugLog[] = "OpenRouter (Code $httpCode): " . ($response ? $response : $curlError);
            }
        }

        // Parse YouTube query tag if generated
        if (preg_match('/\[YOUTUBE_QUERY:\s*(.*?)\]/', $aiText, $matches)) {
            $ytQuery = trim($matches[1]);
            $aiText = preg_replace('/\[YOUTUBE_QUERY:\s*.*?\]/', '', $aiText);
        }

        // Final Local Fallback if all external services fail (WITH DEBUG LOG)
        if (trim($aiText) === '') {
            $debugString = htmlspecialchars(implode(" | ", $debugLog));
            $aiText = "**All API connections failed. Here is the exact error from the servers:**\n\n`{$debugString}`\n\n---\n\nLet's break this down from scratch! You chose \"{$selectedText}\", but the correct answer is \"{$correctText}\". Under the topic of **{$topic}**, evaluate the core principles step-by-step rather than guessing.";
            $ytQuery = $topic . " tutorial explanation";
            $sourceModel = 'Local Smart Fallback (DEBUG MODE)';
        }

        echo json_encode([
            'success' => true, 
            'explanation' => trim($aiText),
            'source_model' => $sourceModel,
            'youtube_query' => $ytQuery,
            'youtube_url' => 'https://www.youtube.com/results?search_query=' . urlencode($ytQuery)
        ]);
    } catch (Throwable $e) {
        echo json_encode([
            'success' => true,
            'explanation' => "Exception caught: " . $e->getMessage(),
            'source_model' => 'Local Exception Fallback',
            'youtube_query' => $topic . ' tutorial',
            'youtube_url' => 'https://www.youtube.com/results?search_query=' . urlencode($topic . ' tutorial')
        ]);
    }
    exit;
}

// Optional module-specific Quiz Arena view.
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
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['action']) || (isset($_POST['action']) && $_POST['action'] !== 'ask_ai'))) {
        csrf_check();
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'start') {
            $quizId = filter_var($_POST['quiz_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$quizId) throw new RuntimeException('Invalid quiz selected.');
            $stmt = $pdo->prepare("SELECT q.*, m.module_code, m.module_name,
                    (SELECT COUNT(*) FROM quiz_questions qq WHERE qq.quiz_id=q.id) AS question_count
                FROM quizzes q
                JOIN academic_modules m ON m.id=q.module_id
                JOIN student_module_enrollments sme ON sme.module_id=m.id AND sme.user_id=?
                 WHERE q.id=? AND q.is_published=1 AND m.is_active=1 LIMIT 1");
            $stmt->execute([$userId, $quizId]);
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
            FROM quiz_attempts a JOIN quizzes q ON q.id=a.quiz_id JOIN academic_modules m ON m.id=q.module_id
            WHERE a.id=? AND a.user_id=? LIMIT 1");
        $stmt->execute([(int)$attemptId, $userId]);
        $attempt = $stmt->fetch() ?: null;
        if (!$attempt) throw new RuntimeException('Quiz attempt not found.');
        if ($attempt['status'] !== 'in_progress') studentQuizRedirect('result=' . (int)$attempt['id']);
        $elapsed = max(0, time() - strtotime((string)$attempt['started_at']));
        if ($elapsed > (int)$attempt['duration_minutes'] * 60 + 15) {
            $notice = 'Time is up. Submit now to record your attempt.';
        }
        $stmt = $pdo->prepare('SELECT id, question_text, topic, difficulty, explanation FROM quiz_questions WHERE quiz_id=? ORDER BY id ASC');
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
            FROM quiz_attempts a JOIN quizzes q ON q.id=a.quiz_id JOIN academic_modules m ON m.id=q.module_id
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
            FROM quizzes q JOIN academic_modules m ON m.id=q.module_id
            JOIN student_module_enrollments sme ON sme.module_id=m.id AND sme.user_id=?
             WHERE q.is_published=1 AND m.is_active=1" . ($moduleFilterId !== null ? " AND m.id=?" : "") . "
            ORDER BY m.module_code, q.title");
        $stmt->execute($moduleFilterId !== null ? [$userId, $moduleFilterId] : [$userId]);
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

<!-- Marked.js for parsing AI Markdown -->
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>

<!-- KaTeX for rendering LaTeX Math Formulas -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.css">
<script src="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/contrib/auto-render.min.js"></script>

<style>
:root{--quiz-bg:#f7f8fc;--quiz-card:#fff;--quiz-text:#171927;--quiz-muted:#777b90;--quiz-border:#e7e8f0;--quiz-accent:#6756e8;--quiz-soft:#f0edff}
:root[data-theme="dark"]{--quiz-bg:#0c0e16;--quiz-card:#161924;--quiz-text:#f4f4fa;--quiz-muted:#a0a4b6;--quiz-border:#2b2e3c;--quiz-accent:#a99aff;--quiz-soft:#292440}
body{background:var(--quiz-bg);color:var(--quiz-text)}.quiz-wrap{max-width:1080px;margin:0 auto;padding:32px 22px 64px}.quiz-top{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:24px}.quiz-top a,.quiz-btn{display:inline-flex;align-items:center;justify-content:center;text-decoration:none;border:1px solid var(--quiz-border);border-radius:12px;padding:10px 15px;color:var(--quiz-text);background:var(--quiz-card);font-weight:650;cursor:pointer}.quiz-btn.primary{background:var(--quiz-accent);color:#fff;border-color:transparent}.quiz-hero{padding:26px;border:1px solid var(--quiz-border);border-radius:22px;background:var(--quiz-card);margin-bottom:22px}.quiz-hero h1{font-size:clamp(28px,4vw,40px);line-height:1.1;margin:8px 0 12px;letter-spacing:-.04em}.muted{color:var(--quiz-muted)}.quiz-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px}.quiz-card,.question-card,.result-card,.panel{background:var(--quiz-card);border:1px solid var(--quiz-border);border-radius:18px;padding:20px}.quiz-card h2,.question-card h2,.result-card h2{margin:8px 0 10px;font-size:19px}.quiz-meta{display:flex;flex-wrap:wrap;gap:8px;margin:12px 0;color:var(--quiz-muted);font-size:13px}.pill{display:inline-flex;padding:5px 9px;border-radius:999px;background:var(--quiz-soft);color:var(--quiz-accent);font-size:12px;font-weight:700}.notice,.error{padding:13px 15px;border-radius:12px;margin:0 0 18px}.notice{background:#e9f8ef;color:#166534}.error{background:#fff0f0;color:#9f1239}.question-card{margin:14px 0}.question-title{font-weight:700;font-size:18px;white-space:pre-wrap}.option{display:flex;gap:10px;align-items:flex-start;padding:12px;border:1px solid var(--quiz-border);border-radius:12px;margin-top:9px;cursor:pointer}.option input{margin-top:5px;accent-color:var(--quiz-accent)}.timer{font-variant-numeric:tabular-nums;font-weight:800;color:var(--quiz-accent);font-size:18px}.result-score{font-size:clamp(34px,6vw,54px);font-weight:850;letter-spacing:-.04em}.review-option{padding:10px 12px;border:1px solid var(--quiz-border);border-radius:10px;margin-top:8px}.review-option.correct{border-color:#22a06b;background:rgba(34,160,107,.08)}.review-option.chosen-wrong{border-color:#dc6262;background:rgba(220,98,98,.08)}.btn-row{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}.empty{padding:24px;border:1px dashed var(--quiz-border);border-radius:16px}.topline{font-size:12px;text-transform:uppercase;letter-spacing:.12em;color:var(--quiz-muted);font-weight:800}@media(max-width:640px){.quiz-wrap{padding:20px 14px 44px}.quiz-top{align-items:flex-start;flex-direction:column}.quiz-hero{padding:20px}}
.review-details{margin:12px 0;padding:0;overflow:hidden}.review-details summary{display:flex;flex-direction:column;gap:7px;position:relative;cursor:pointer;padding:20px 160px 20px 20px;list-style:none}.review-details summary::-webkit-details-marker{display:none}.review-details summary:focus-visible{outline:3px solid var(--quiz-accent);outline-offset:-3px}.review-question{font-size:17px;font-weight:700;white-space:pre-wrap}.review-toggle{position:absolute;right:20px;top:50%;transform:translateY(-50%);border:1px solid var(--quiz-border);border-radius:10px;padding:7px 10px;color:var(--quiz-accent);font-size:13px;font-weight:700}.review-details[open] .review-toggle{font-size:0}.review-details[open] .review-toggle::after{content:"Hide answers";font-size:13px}.review-body{padding:0 20px 20px;border-top:1px solid var(--quiz-border)}@media(max-width:640px){.review-details summary{padding:18px 18px 58px}.review-toggle{top:auto;bottom:12px;left:18px;right:auto;transform:none}}

/* AI Assistant Drawer & YouTube Recommendation Styling */
.ai-drawer {
    position: fixed;
    right: -420px;
    top: 0;
    width: 400px;
    height: 100%;
    background: var(--quiz-card);
    color: var(--quiz-text);
    border-left: 1px solid var(--quiz-border);
    box-shadow: -5px 0 25px rgba(0,0,0,0.15);
    padding: 24px;
    z-index: 9999;
    transition: right 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    box-sizing: border-box;
    display: flex;
    flex-direction: column;
}
.ai-ask-btn {
    background: var(--quiz-soft);
    color: var(--quiz-accent);
    border: 1px solid var(--quiz-border);
    padding: 8px 14px;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-top: 12px;
    transition: all 0.2s ease;
}
.ai-ask-btn:hover {
    background: var(--quiz-accent);
    color: #fff;
}
.youtube-chip {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: var(--surface);
    color: var(--text);
    border: 1px solid var(--line);
    padding: 8px 12px;
    border-radius: 10px;
    text-decoration: none;
    font-size: 12px;
    font-weight: 750;
    margin-top: 14px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    transition: all 0.25s ease;
}
.youtube-chip svg {
    width: 14px;
    height: 14px;
    fill: #ff3333;
}
.youtube-chip:hover {
    background: var(--soft);
    border-color: var(--accent);
    transform: translateY(-2px);
}
.model-badge {
    display: inline-block;
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    background: var(--quiz-soft);
    color: var(--quiz-accent);
    padding: 3px 8px;
    border-radius: 6px;
    margin-bottom: 6px;
}

/* AI Markdown Spacing Styles */
.ai-markdown-content {
    word-break: break-word;
    font-size: 14px;
}
.ai-markdown-content p {
    margin-bottom: 12px;
    line-height: 1.6;
}
.ai-markdown-content h3, .ai-markdown-content h4 {
    margin: 18px 0 8px;
    color: var(--quiz-accent);
    font-size: 16px;
}
.ai-markdown-content ul, .ai-markdown-content ol {
    padding-left: 20px;
    margin-bottom: 12px;
}
.ai-markdown-content li {
    margin-bottom: 6px;
}
.ai-markdown-content hr {
    border: 0;
    height: 1px;
    background: var(--quiz-border);
    margin: 18px 0;
}
.ai-markdown-content strong {
    color: var(--quiz-text);
}
.ai-markdown-content code {
    background: var(--quiz-card);
    padding: 2px 6px;
    border-radius: 4px;
    border: 1px solid var(--quiz-border);
}
</style>
</head>
<body>
<main class="quiz-wrap">
<div class="quiz-top"><div><div class="topline">ULearn · Student workspace</div><strong>Quiz Arena</strong></div><div class="btn-row" style="margin-top:0"><a href="quiz-history.php">My Quiz History</a><a href="topic-analysis.php" style="background:var(--quiz-soft); color:var(--quiz-accent);">Topic Weaknesses</a><?php if ($moduleFilter): ?><a href="module.php?module_id=<?= (int)$moduleFilterId ?>">Back to Module</a><?php endif; ?><a href="explore.php">Explore modules</a><a href="index.php">← Back to dashboard</a></div></div>

<?php if ($error !== ''): ?><div class="error"><?= e($error) ?></div><?php endif; ?>
<?php if ($notice !== ''): ?><div class="notice"><?= e($notice) ?></div><?php endif; ?>

<?php if ($attempt): ?>
<section class="quiz-hero"><div class="topline"><?= e((string)$attempt['module_code']) ?> · <?= e((string)$attempt['module_name']) ?></div><h1><?= e((string)$attempt['title']) ?></h1><p class="muted"><?= e((string)($attempt['description'] ?? 'Answer each question, then submit to see your score and explanations.')) ?></p><div class="quiz-meta"><span class="pill"><?= count($questions) ?> questions</span><span class="pill"><?= (int)$attempt['duration_minutes'] ?> minutes</span><span>Time remaining: <span class="timer" id="quizTimer"><?= gmdate('i:s', $remainingSeconds) ?></span></span></div></section>
<form method="post" id="quizForm"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="submit"><input type="hidden" name="attempt_id" value="<?= (int)$attempt['id'] ?>">

<?php foreach ($questions as $i => $question): ?>
<section class="question-card">
    <div class="topline">Question <?= $i+1 ?> of <?= count($questions) ?></div>
    <h2 class="question-title"><?= e((string)$question['question_text']) ?></h2>
    <div class="quiz-meta"><?php if (!empty($question['topic'])): ?><span class="pill"><?= e((string)$question['topic']) ?></span><?php endif; ?><?php if (!empty($question['difficulty'])): ?><span><?= e((string)$question['difficulty']) ?></span><?php endif; ?></div>
    
    <?php foreach ($question['options'] as $option): ?>
        <label class="option"><input type="radio" name="answers[<?= (int)$question['id'] ?>]" value="<?= (int)$option['id'] ?>"><span><?= e((string)$option['option_text']) ?></span></label>
    <?php endforeach; ?>
</section>
<?php endforeach; ?>

<div class="btn-row"><button type="submit" class="quiz-btn primary" onclick="return confirm('Submit this quiz now? You cannot change answers after submission.')">Submit quiz</button><a class="quiz-btn" href="index.php">Save nothing and leave</a></div></form>

<script>(function(){let remaining=<?= (int)$remainingSeconds ?>;const el=document.getElementById('quizTimer');const form=document.getElementById('quizForm');function tick(){if(remaining<=0){el.textContent='00:00';el.style.color='#dc2626';form.requestSubmit();return;}el.textContent=String(Math.floor(remaining/60)).padStart(2,'0')+':'+String(remaining%60).padStart(2,'0');remaining--;setTimeout(tick,1000);};tick();})();</script>

<?php elseif ($result): ?>
<section class="quiz-hero"><div class="topline">Quiz results · <?= e((string)$result['module_code']) ?></div><h1><?= e((string)$result['title']) ?></h1><div class="result-score"><?= e(rtrim(rtrim(number_format((float)$result['score'],2,'.',''),'0'),'.')) ?> <span class="muted" style="font-size:20px">/ <?= e(rtrim(rtrim(number_format((float)$result['max_score'],2,'.',''),'0'),'.')) ?></span></div><p class="muted"><?= $result['max_score'] > 0 ? (int)round(((float)$result['score']/(float)$result['max_score'])*100) : 0 ?>% · <?= $result['status'] === 'timed_out' ? 'Time expired' : 'Submitted' ?> · <?= !empty($result['submitted_at']) ? e(date('d M Y, H:i', strtotime((string)$result['submitted_at']))) : '' ?></p><div class="btn-row"><a class="quiz-btn primary" href="quiz-history.php">Back to Quiz History</a><a class="quiz-btn" href="topic-analysis.php" style="background:var(--quiz-soft); color:var(--quiz-accent);">View Topic Weaknesses</a><a class="quiz-btn" href="quizzes.php">Quiz Arena</a></div></section>
<h2>Review your answers</h2><p class="muted">Select a question to show or hide its answers and explanations. For incorrect answers, ask ULearn AI for a detailed beginner-friendly breakdown and recommended YouTube videos.</p>

<?php foreach ($resultQuestions as $i => $rq): 
    $isWrong = ((int)$rq['is_correct'] === 0);
    $topicName = $rq['topic'] ?: 'General Concept';
?>
<details class="result-card review-details">
    <summary><span class="topline">Question <?= $i+1 ?> · <?= e((string)$topicName) ?></span><span class="review-question"><?= e((string)$rq['question_text']) ?></span><span class="review-toggle">Show answers</span></summary>
    <div class="review-body">
        <?php foreach ($rq['options'] as $opt): $isCorrect=(int)$opt['is_correct']===1; $isChosen=(int)$opt['id']===(int)($rq['selected_option_id'] ?? 0); ?>
            <div class="review-option <?= $isCorrect?'correct':($isChosen?'chosen-wrong':'') ?>"><strong><?= $isCorrect?'Correct answer':($isChosen?'Your answer':'Option') ?><?= $isCorrect||$isChosen?':':'' ?></strong> <?= e((string)$opt['option_text']) ?><?php if (!empty($opt['explanation'])): ?><div class="muted" style="margin-top:5px;font-size:14px"><?= e((string)$opt['explanation']) ?></div><?php endif; ?></div>
        <?php endforeach; ?>
        <?php if (!empty($rq['question_explanation'])): ?><p style="margin-top:12px;"><strong>Explanation:</strong> <?= e((string)$rq['question_explanation']) ?></p><?php endif; ?>
        
        <!-- Ask AI Button (Appears ONLY on Wrong Answers during review) -->
        <?php if ($isWrong): ?>
            <button type="button" class="ai-ask-btn" onclick="askAiWhyWrong(this, '<?= htmlspecialchars(addslashes($rq['question_text']), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($rq['selected_option_text'] ?? 'No answer selected'), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($rq['correct_option_text'] ?? ''), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($topicName), ENT_QUOTES) ?>')">
                🤖 Ask ULearn AI: Explain like a beginner
            </button>
        <?php endif; ?>

        <p class="muted" style="margin-top:10px;">Points earned: <?= e(rtrim(rtrim(number_format((float)$rq['points_awarded'],2,'.',''),'0'),'.')) ?></p>
    </div>
</details>
<?php endforeach; ?>

<?php else: ?>
<section class="quiz-hero"><div class="topline">Practice · Learn · Improve</div><h1><?= $moduleFilter ? e((string)$moduleFilter['module_name']) . ' Quizzes' : 'Your Quiz Arena' ?></h1><p class="muted"><?= $moduleFilter ? 'Practice quizzes for this enrolled module.' : 'Take published quizzes from modules you have enrolled in.' ?> Answers and explanations appear after submission.</p></section>

<?php if ($moduleFilter): ?><p><a class="quiz-btn" href="quizzes.php">View all enrolled-module quizzes</a></p><?php endif; ?>
<?php if (!$availableQuizzes): ?><div class="empty"><h2><?= $moduleFilter ? 'No published quizzes for this module yet' : 'No published quizzes available yet' ?></h2><p class="muted"><?= $moduleFilter ? 'Published quizzes with questions will appear here when available.' : 'Enroll in a module through Explore &amp; Enroll. Published quizzes with questions will appear here when available.' ?></p><a class="quiz-btn primary" href="<?= $moduleFilter ? 'quizzes.php' : 'explore.php' ?>"><?= $moduleFilter ? 'View All My Quizzes' : 'Explore modules' ?></a></div>
<?php else: ?><div class="quiz-grid"><?php foreach ($availableQuizzes as $quiz): ?><article class="quiz-card"><div class="topline"><?= e((string)$quiz['module_code']) ?> · <?= e((string)$quiz['module_name']) ?></div><h2><?= e((string)$quiz['title']) ?></h2><p class="muted"><?= e((string)($quiz['description'] ?: 'Practice questions for this module.')) ?></p><div class="quiz-meta"><span class="pill"><?= (int)$quiz['question_count'] ?> questions</span><span><?= (int)$quiz['duration_minutes'] ?> min</span><span><?= e(ucfirst((string)$quiz['quiz_type'])) ?></span></div><?php if (!empty($quiz['available_until'])): ?><p class="muted" style="font-size:13px">Available until <?= e(date('d M Y H:i', strtotime((string)$quiz['available_until']))) ?></p><?php endif; ?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="start"><input type="hidden" name="quiz_id" value="<?= (int)$quiz['id'] ?>"><button class="quiz-btn primary" type="submit">Start quiz →</button></form></article><?php endforeach; ?></div><?php endif; ?>
<?php endif; ?>
</main>

<!-- AI Study Assistant Sliding Drawer -->
<div id="ai-study-drawer" class="ai-drawer">
    <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid var(--quiz-border); padding-bottom:12px;">
        <h4 style="margin:0; font-size:16px; color:var(--quiz-accent); font-weight:600;">ULearn AI Support Assistant</h4>
        <button type="button" onclick="closeAiDrawer()" style="background:none; border:none; font-size:18px; cursor:pointer; color:var(--quiz-muted); font-weight:bold;">✕</button>
    </div>
    <div id="ai-chat-history" style="flex:1; overflow-y:auto; margin-top:15px; font-size:14px; color:var(--quiz-text); line-height: 1.6;">
        <p style="color: var(--quiz-muted);">Review your quiz results and click "Ask ULearn AI" on any incorrect question to get a beginner-friendly explanation and recommended YouTube videos!</p>
    </div>
</div>

<script>
function openAiDrawer() {
    const drawer = document.getElementById('ai-study-drawer');
    if (drawer) drawer.style.right = '0px';
}

function closeAiDrawer() {
    const drawer = document.getElementById('ai-study-drawer');
    if (drawer) drawer.style.right = '-420px';
}

async function askAiWhyWrong(btn, questionText, selectedText, correctText, topic) {
    openAiDrawer();
    const chatHistory = document.getElementById('ai-chat-history');
    chatHistory.innerHTML = `
        <div style="margin-bottom:15px; padding-bottom:12px; border-bottom:1px solid var(--quiz-border);">
            <strong style="color:var(--quiz-accent); display:block; margin-bottom:4px;">Topic: ${topic}</strong>
            <span style="color:var(--quiz-text); font-size:13px;">${questionText}</span>
        </div>
        <div style="background:var(--quiz-soft); padding:14px; border-radius:10px; border-left:4px solid var(--quiz-accent);">
            <div id="ai-model-badge" class="model-badge">Connecting to AI Server...</div>
            <div id="ai-loading-text" class="ai-markdown-content" style="color:var(--quiz-muted);">Simplifying this concept from scratch for you...</div>
        </div>
        <div id="ai-youtube-container"></div>
    `;

    try {
        const formData = new FormData();
        formData.append('action', 'ask_ai');
        formData.append('question_text', questionText);
        formData.append('selected_text', selectedText);
        formData.append('correct_text', correctText);
        formData.append('topic', topic);
        formData.append('csrf', '<?= csrf() ?>');

        const response = await fetch('quizzes.php', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();

        if (data.success) {
            document.getElementById('ai-model-badge').textContent = '🤖 Powered by: ' + data.source_model;
            
            // Parse Markdown into HTML using Marked.js
            const markdownHtml = marked.parse(data.explanation);
            const loadingTextDiv = document.getElementById('ai-loading-text');
            
            loadingTextDiv.innerHTML = markdownHtml;
            loadingTextDiv.style.color = 'var(--quiz-text)';
            
            // Render Math Equations using KaTeX
            if (window.renderMathInElement) {
                renderMathInElement(loadingTextDiv, {
                    delimiters: [
                        {left: '$$', right: '$$', display: true},
                        {left: '$', right: '$', display: false},
                        {left: '\\(', right: '\\)', display: false},
                        {left: '\\[', right: '\\]', display: true}
                    ],
                    throwOnError: false
                });
            }
            
            if (data.youtube_url) {
                document.getElementById('ai-youtube-container').innerHTML = `
                    <a href="${data.youtube_url}" target="_blank" class="youtube-chip">
                        <svg viewBox="0 0 24 24"><path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>
                        Watch beginner videos on "${data.youtube_query}"
                    </a>
                `;
            }
        } else {
            document.getElementById('ai-model-badge').textContent = '⚠️ System Error';
            document.getElementById('ai-loading-text').innerHTML = '<p>Error: ' + (data.message || 'Could not reach AI backend.') + '</p>';
        }
    } catch (err) {
        document.getElementById('ai-model-badge').textContent = '⚠️ Network Error';
        document.getElementById('ai-loading-text').innerHTML = '<p>Network error while connecting to AI backend.</p>';
    }
}
</script>

</body>
</html>