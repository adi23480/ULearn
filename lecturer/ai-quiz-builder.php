<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$user = require_login();

// Verify lecturer access (adjust role check if your system uses a different string for lecturers/admins)
if ((string)$user['role'] !== 'lecturer' && (string)$user['role'] !== 'admin') {
    http_response_code(403);
    exit('Lecturer access only.');
}

$pdo = db();
$userId = (int)$user['id'];

// Fetch available modules for the dropdown
$modulesStmt =$pdo->query("SELECT id, module_code, module_name FROM academic_modules WHERE is_active=1 ORDER BY module_code");
$modules =$modulesStmt->fetchAll();

// ==========================================
// AJAX HANDLER 1: GENERATE AI QUESTIONS
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) &&$_POST['action'] === 'generate_ai') {
    header('Content-Type: application/json');
    try {
        csrf_check();
        $sourceText = trim((string)($_POST['source_text'] ?? ''));
        $questionCount = (int)($_POST['question_count'] ?? 5);
        $difficulty = trim((string)($_POST['difficulty'] ?? 'Medium'));

        if (empty($sourceText)) {
            echo json_encode(['success' => false, 'message' => 'Source text is required.']);
            exit;
        }

        $geminiKey = 'AQ.Ab8RN6ICdE2ES1yrj214gOiqP9OAi40ofcjD3F8f3qdkEW_Yyw';$openRouterKey = 'sk-or-v1-288d49d7927c5c75000000000000000000000000000000000000000000000000000000000000000000ff8dcb28539ba2';

        $prompt = "You are an expert curriculum designer. Based ONLY on the following reference text, generate exactly {$questionCount} multiple-choice questions at a '{$difficulty}' difficulty level.\n\n" .
                  "RETURN ONLY A RAW JSON ARRAY. Do not use markdown formatting like ```json. \n" .
                  "Format strictly as:\n" .
                  "[\n" .
                  "  {\n" .
                  "    \"question_text\": \"The question here?\",\n" .
                  "    \"options\": [\"Option A\", \"Option B\", \"Option C\", \"Option D\"],\n" .
                  "    \"correct_index\": 0,\n" . // 0, 1, 2, or 3
                  "    \"explanation\": \"Why A is correct.\",\n" .
                  "    \"topic\": \"Short Concept Name\"\n" .
                  "  }\n" .
                  "]\n\n" .
                  "Reference Text:\n{$sourceText}";

        $aiText = '';
        
        // Attempt 1: Gemini 3.8 Flash
        $ch = curl_init("[https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent?key=](https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent?key=)" . $geminiKey);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['contents' => [['parts' => [['text' => $prompt]]]]]));
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            $aiText = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        }

        // Attempt 2: OpenRouter Fallback
        if (trim($aiText) === '') {
            $ch = curl_init("[https://openrouter.ai/api/v1/chat/completions](https://openrouter.ai/api/v1/chat/completions)");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 20);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $openRouterKey,
                'HTTP-Referer: http://localhost'
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                'model' => 'meta-llama/llama-3.1-8b-instruct:free',
                'messages' => [['role' => 'user', 'content' => $prompt]]
            ]));
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200 && $response) {
                $data = json_decode($response, true);
                $aiText = $data['choices'][0]['message']['content'] ?? '';
            }
        }

        if (trim($aiText) === '') {
            echo json_encode(['success' => false, 'message' => 'AI failed to generate questions. Check API keys.']);
            exit;
        }

        // Clean up markdown block formatting if AI included it
        $aiText = preg_replace('/^```json\s*/i', '', trim($aiText));$aiText = preg_replace('/```$/', '', $aiText);
        $aiText = trim($aiText);

        $parsedJSON = json_decode($aiText, true);
        
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($parsedJSON)) {
            echo json_encode(['success' => false, 'message' => 'AI returned invalid JSON format.', 'debug' => $aiText]);
            exit;
        }

        echo json_encode(['success' => true, 'questions' => $parsedJSON]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ==========================================
// AJAX HANDLER 2: SAVE FINAL QUIZ TO DB
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_quiz') {
    header('Content-Type: application/json');
    try {
        csrf_check();
        $quizData = json_decode($_POST['quiz_payload'], true);

        if (!$quizData || empty($quizData['questions'])) {
            throw new Exception('Invalid quiz payload.');
        }

        $pdo->beginTransaction();

        // 1. Insert into quizzes table
        $stmt = $pdo->prepare("INSERT INTO quizzes (module_id, title, description, quiz_type, duration_minutes, points_per_question, is_published, available_from, available_until) VALUES (?, ?, ?, 'standard', ?, ?, 1, NULL, NULL)");
        $stmt->execute([
            $quizData['module_id'],
            $quizData['title'],
            $quizData['description'],
            $quizData['duration'],
            $quizData['points']
        ]);
        
        $quizId = $pdo->lastInsertId();

        // 2. Loop and insert questions and options
        $qStmt = $pdo->prepare("INSERT INTO quiz_questions (quiz_id, question_text, topic, difficulty, explanation) VALUES (?, ?, ?, ?, ?)");
        $optStmt = $pdo->prepare("INSERT INTO quiz_options (question_id, option_text, is_correct) VALUES (?, ?, ?)");

        foreach ($quizData['questions'] as $q) {
            $qStmt->execute([
                $quizId,
                $q['question_text'],
                $q['topic'] ?? 'General',
                $q['difficulty'] ?? 'Medium',
                $q['explanation'] ?? ''
            ]);
            $questionId = $pdo->lastInsertId();

            foreach ($q['options'] as $idx => $optText) {
                $isCorrect = ($idx === (int)$q['correct_index']) ? 1 : 0;
                $optStmt->execute([$questionId, $optText, $isCorrect]);
            }
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'quiz_id' => $quizId]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>AI Quiz Builder — ULearn Lecturer</title>
<script>(function(){const saved=localStorage.getItem('ulearn-theme');const theme=saved==='light'||saved==='dark'?saved:(window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light');document.documentElement.dataset.theme=theme;})();</script>
<link rel="stylesheet" href="../assets/css/style.css">
<style>
:root{--quiz-bg:#f7f8fc;--quiz-card:#fff;--quiz-text:#171927;--quiz-muted:#777b90;--quiz-border:#e7e8f0;--quiz-accent:#6756e8;--quiz-soft:#f0edff}
:root[data-theme="dark"]{--quiz-bg:#0c0e16;--quiz-card:#161924;--quiz-text:#f4f4fa;--quiz-muted:#a0a4b6;--quiz-border:#2b2e3c;--quiz-accent:#a99aff;--quiz-soft:#292440}
body{background:var(--quiz-bg);color:var(--quiz-text)}.quiz-wrap{max-width:900px;margin:0 auto;padding:32px 22px 64px}
.panel{background:var(--quiz-card);border:1px solid var(--quiz-border);border-radius:18px;padding:24px;margin-bottom:20px;}
.form-group{margin-bottom:16px;}
.form-group label{display:block;margin-bottom:6px;font-weight:600;font-size:14px;}
.form-control{width:100%;padding:10px 14px;border:1px solid var(--quiz-border);border-radius:10px;background:var(--quiz-bg);color:var(--quiz-text);font-family:inherit;}
textarea.form-control{resize:vertical;min-height:120px;}
.quiz-btn{display:inline-flex;align-items:center;justify-content:center;text-decoration:none;border:1px solid var(--quiz-border);border-radius:12px;padding:12px 20px;color:var(--quiz-text);background:var(--quiz-card);font-weight:650;cursor:pointer;transition:all 0.2s;}
.quiz-btn.primary{background:var(--quiz-accent);color:#fff;border-color:transparent;}
.quiz-btn.primary:disabled{opacity:0.6;cursor:not-allowed;}
.draft-card{border:1px solid var(--quiz-accent);border-radius:12px;padding:16px;margin-bottom:16px;background:var(--quiz-soft);}
.draft-card input[type="text"] { width: 100%; padding: 8px; margin: 4px 0; border: 1px solid var(--quiz-border); border-radius: 6px; }
.draft-card .options-list { margin-top: 10px; padding-left: 20px; }
.draft-card .options-list li { margin-bottom: 6px; display: flex; align-items: center; gap: 10px; }
.draft-card .options-list input[type="radio"] { margin: 0; }
</style>
</head>
<body>

<main class="quiz-wrap">
    <h2>🤖 AI Quiz Builder</h2>
    <p class="muted">Upload your lecture notes, and let ULearn AI generate a fully formatted quiz instantly.</p>

    <!-- STEP 1: Metadata & Source Material -->
    <div class="panel" id="step1">
        <h3>1. Quiz Settings & Source Material</h3>
        
        <div class="form-group">
            <label>Module / Category</label>
            <select id="moduleId" class="form-control">
                <?php foreach($modules as $m): ?>
                    <option value="<?= $m['id'] ?>"><?= e($m['module_code']) ?> - <?= e($m['module_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label>Quiz Title</label>
            <input type="text" id="quizTitle" class="form-control" placeholder="e.g., Midterm Review: Data Structures" required>
        </div>

        <div style="display:flex; gap: 15px;">
            <div class="form-group" style="flex:1;">
                <label>Duration (Minutes)</label>
                <input type="number" id="quizDuration" class="form-control" value="15">
            </div>
            <div class="form-group" style="flex:1;">
                <label>Points per Question</label>
                <input type="number" id="quizPoints" class="form-control" value="2">
            </div>
            <div class="form-group" style="flex:1;">
                <label>Difficulty</label>
                <select id="quizDifficulty" class="form-control">
                    <option>Easy</option>
                    <option selected>Medium</option>
                    <option>Hard</option>
                </select>
            </div>
        </div>

        <div class="form-group">
            <label>Source Reference Material (Paste PDF text, notes, or transcript here)</label>
            <textarea id="sourceText" class="form-control" placeholder="Paste your lecture notes here. The AI will read this and generate questions based on the facts provided..."></textarea>
            <small class="muted" style="display:block; margin-top:6px;">* PDF File upload integration requires a PHP library. For now, simply copy and paste your document text here.</small>
        </div>

        <button class="quiz-btn primary" id="generateBtn" onclick="generateQuestions()">Generate Draft Questions ✨</button>
    </div>

    <!-- STEP 2: Review & Edit Generated Questions -->
    <div class="panel" id="step2" style="display:none;">
        <h3>2. Review & Edit Generated Questions</h3>
        <p class="muted">You can edit the text directly before publishing to your students.</p>
        
        <div id="questionsContainer"></div>

        <div style="margin-top: 20px; display:flex; gap:10px;">
            <button class="quiz-btn primary" id="publishBtn" onclick="publishQuiz()">Save & Publish Quiz 🚀</button>
            <button class="quiz-btn" onclick="location.reload()">Start Over</button>
        </div>
    </div>
</main>

<script>
let generatedQuestions = [];

async function generateQuestions() {
    const btn = document.getElementById('generateBtn');
    const sourceText = document.getElementById('sourceText').value.trim();
    const title = document.getElementById('quizTitle').value.trim();

    if (!title) return alert("Please enter a Quiz Title.");
    if (!sourceText) return alert("Please paste some source text for the AI to read.");

    btn.disabled = true;
    btn.innerText = "Generating questions... (this may take 10-15 seconds)";

    const formData = new FormData();
    formData.append('action', 'generate_ai');
    formData.append('source_text', sourceText);
    formData.append('difficulty', document.getElementById('quizDifficulty').value);
    formData.append('csrf', '<?= csrf() ?>');

    try {
        const res = await fetch('ai-quiz-builder.php', { method: 'POST', body: formData });
        const data = await res.json();

        if (data.success) {
            generatedQuestions = data.questions;
            renderQuestions();
            document.getElementById('step1').style.display = 'none';
            document.getElementById('step2').style.display = 'block';
        } else {
            alert("Error: " + data.message + (data.debug ? "\n\nDebug: " + data.debug : ""));
        }
    } catch (e) {
        alert("Network error. Could not reach AI backend.");
    } finally {
        btn.disabled = false;
        btn.innerText = "Generate Draft Questions ✨";
    }
}

function renderQuestions() {
    const container = document.getElementById('questionsContainer');
    container.innerHTML = '';

    generatedQuestions.forEach((q, qIndex) => {
        let optionsHtml = '';
        q.options.forEach((opt, optIndex) => {
            const isChecked = q.correct_index === optIndex ? 'checked' : '';
            optionsHtml += `
                <li>
                    <input type="radio" name="correct_${qIndex}" value="${optIndex}" ${isChecked} onchange="updateCorrect(${qIndex}, ${optIndex})">
                    <input type="text" value="${opt.replace(/"/g, '&quot;')}" oninput="updateOption(${qIndex}, ${optIndex}, this.value)">
                </li>
            `;
        });

        const html = `
            <div class="draft-card">
                <label style="font-weight:bold; font-size:13px; color:var(--quiz-accent)">Question ${qIndex + 1} (${q.topic})</label>
                <input type="text" value="${q.question_text.replace(/"/g, '&quot;')}" oninput="updateQuestion(${qIndex}, this.value)" style="font-weight:bold;">
                <ul class="options-list" style="list-style:none;">
                    ${optionsHtml}
                </ul>
                <label style="font-size:12px; margin-top:10px; display:block;">Explanation for Correct Answer:</label>
                <input type="text" value="${q.explanation.replace(/"/g, '&quot;')}" oninput="updateExplanation(${qIndex}, this.value)" style="font-size:13px; color:var(--quiz-muted);">
            </div>
        `;
        container.innerHTML += html;
    });
}

function updateQuestion(qIdx, val) { generatedQuestions[qIdx].question_text = val; }
function updateOption(qIdx, optIdx, val) { generatedQuestions[qIdx].options[optIdx] = val; }
function updateCorrect(qIdx, optIdx) { generatedQuestions[qIdx].correct_index = optIdx; }
function updateExplanation(qIdx, val) { generatedQuestions[qIdx].explanation = val; }

async function publishQuiz() {
    const btn = document.getElementById('publishBtn');
    btn.disabled = true;
    btn.innerText = "Saving to Database...";

    const payload = {
        module_id: document.getElementById('moduleId').value,
        title: document.getElementById('quizTitle').value.trim(),
        description: "Generated by ULearn AI Tutor",
        duration: document.getElementById('quizDuration').value,
        points: document.getElementById('quizPoints').value,
        questions: generatedQuestions
    };

    const formData = new FormData();
    formData.append('action', 'save_quiz');
    formData.append('quiz_payload', JSON.stringify(payload));
    formData.append('csrf', '<?= csrf() ?>');

    try {
        const res = await fetch('ai-quiz-builder.php', { method: 'POST', body: formData });
        const data = await res.json();

        if (data.success) {
            alert("Quiz successfully published to students!");
            window.location.href = 'quizzes.php'; // Redirect back to lecturer quiz dashboard
        } else {
            alert("Failed to save: " + data.message);
            btn.disabled = false;
            btn.innerText = "Save & Publish Quiz 🚀";
        }
    } catch (e) {
        alert("Network error.");
        btn.disabled = false;
        btn.innerText = "Save & Publish Quiz 🚀";
    }
}
</script>
</body>
</html>