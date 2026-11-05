<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../includes/ai-helper.php'; // defines GEMINI_API_KEY and OPENROUTER_API_KEY

$user = require_login();

// Verify lecturer access
if ((string)$user['role'] !== 'lecturer' && (string)$user['role'] !== 'admin') {
    http_response_code(403);
    exit('Lecturer access only.');
}

$pdo = db();
$userId = (int)$user['id'];
$fullName = (string)$user['full_name'];
$theme = (string)($user['theme'] ?? 'light');

if (!in_array($theme, ['light', 'dark', 'system'], true)) {$theme = 'light';
}

// Fetch available modules for the dropdown
$modulesStmt =$pdo->prepare("
    SELECT m.id, m.module_code, m.module_name 
    FROM lecturer_module_assignments lma
    INNER JOIN academic_curriculum c ON c.id = lma.curriculum_id
    INNER JOIN academic_modules m ON m.id = c.module_id
    WHERE lma.lecturer_id = (SELECT id FROM lecturer_profiles WHERE user_id = ?)
      AND lma.is_active = 1 AND c.is_active = 1 AND m.is_active = 1
    GROUP BY m.id
    ORDER BY m.module_code
");
$modulesStmt->execute([$userId]);
$modules =$modulesStmt->fetchAll();

// ==========================================
// AI HELPERS
// ==========================================

// Models are tried in order. Edit these lists if Google / OpenRouter retire a model.
// Preferred Gemini models go first; the script then asks Google which other flash models your key can use.
const AI_GEMINI_MODELS     = ['gemini-3.8-flash', 'gemini-3.5-flash-lite'];
// OpenRouter is only a last resort (this model is paid; the :free version was removed).
const AI_OPENROUTER_MODELS = ['meta-llama/llama-3.3-70b-instruct'];

/** Ask Google which models this API key can call (cached for 6 hours). Returns model ids like 'gemini-3.8-flash'. */
function ai_discover_gemini_models(): array
{
    if (!defined('GEMINI_API_KEY') || GEMINI_API_KEY === '') return [];

    $cache = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ulearn_gemini_models.json';
    if (is_file($cache) && filemtime($cache) > time() - 6 * 3600) {
        $cached = json_decode((string)file_get_contents($cache), true);
        if (is_array($cached) && $cached) return $cached;
    }

    $host   = $_SERVER['SERVER_NAME'] ?? '';
    $verify = !in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models?pageSize=200');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['x-goog-api-key: ' . GEMINI_API_KEY],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        CURLOPT_SSL_VERIFYPEER => $verify,
        CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !is_string($body)) return [];

    $found = [];
    foreach ((json_decode($body, true)['models'] ?? []) as $m) {
        $id = preg_replace('#^models/#', '', (string)($m['name'] ?? ''));
        if (!in_array('generateContent', (array)($m['supportedGenerationMethods'] ?? []), true)) continue;
        if (!str_contains($id, 'flash')) continue;
        if (preg_match('/image|tts|live|audio|embedding|robotics|computer|learnlm|gemma/i', $id)) continue;
        $found[] = $id;
    }
    // Stable models before preview/experimental, full flash before lite, newest version first.
    usort($found, function ($a, $b) {
        $pa = preg_match('/preview|exp/i', $a) ? 1 : 0;  $pb = preg_match('/preview|exp/i', $b) ? 1 : 0;
        if ($pa !== $pb) return $pa <=> $pb;
        $la = str_contains($a, 'lite') ? 1 : 0;  $lb = str_contains($b, 'lite') ? 1 : 0;
        if ($la !== $lb) return $la <=> $lb;
        return strnatcmp($b, $a);
    });
    if ($found) @file_put_contents($cache, json_encode($found));
    return $found;
}

/** POST JSON and return ['code' => int, 'body' => string, 'error' => string]. */
function ai_http_post(string $url, array $headers, array $payload, int $timeout = 45): array
{
    // On localhost (XAMPP/WAMP) PHP often has no CA bundle, so skip SSL verification there only.
    $host = $_SERVER['SERVER_NAME'] ?? '';
    $verify = !in_array($host, ['localhost', '127.0.0.1', '::1'], true);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        CURLOPT_SSL_VERIFYPEER => $verify,
        CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
    ]);
    $body  = curl_exec($ch);
    $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return ['code' => $code, 'body' => is_string($body) ? $body : '', 'error' => $error];
}

/** Short, readable description of a failed API call for the debug box. */
function ai_describe_failure(array $r): string
{
    $data = json_decode($r['body'], true);
    $msg  = $data['error']['message'] ?? ($data['error'] ?? null);
    if (is_array($msg)) $msg = json_encode($msg);
    if (!$msg) $msg = $r['error'] !== '' ? $r['error'] : mb_substr($r['body'], 0, 300);
    return "HTTP {$r['code']}: " . trim((string)$msg);
}

function ai_call_gemini(string $model, string $prompt, ?string &$err): string
{
    if (!defined('GEMINI_API_KEY') || GEMINI_API_KEY === '') { $err = 'GEMINI_API_KEY is not set'; return ''; }
    $r = ai_http_post(
        'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent',
        ['Content-Type: application/json', 'x-goog-api-key: ' . GEMINI_API_KEY],
        [
            'contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => ['temperature' => 0.7, 'responseMimeType' => 'application/json'],
        ]
    );
    if ($r['code'] !== 200) { $err = ai_describe_failure($r); return ''; }

    $data  = json_decode($r['body'], true);
    $parts = $data['candidates'][0]['content']['parts'] ?? [];
    $text  = '';
    foreach ($parts as $p) { $text .= (string)($p['text'] ?? ''); }
    if (trim($text) === '') { $err = 'Empty response (finishReason: ' . ($data['candidates'][0]['finishReason'] ?? 'unknown') . ')'; }
    return $text;
}

function ai_call_openrouter(string $model, string $prompt, ?string &$err): string
{
    if (!defined('OPENROUTER_API_KEY') || OPENROUTER_API_KEY === '') { $err = 'OPENROUTER_API_KEY is not set'; return ''; }
    $r = ai_http_post(
        'https://openrouter.ai/api/v1/chat/completions',
        [
            'Content-Type: application/json',
            'Authorization: Bearer ' . OPENROUTER_API_KEY,
            'HTTP-Referer: http://localhost',
            'X-Title: ULearn AI Builder',
        ],
        ['model' => $model, 'messages' => [['role' => 'user', 'content' => $prompt]]],
        60
    );
    if ($r['code'] !== 200) { $err = ai_describe_failure($r); return ''; }

    $data = json_decode($r['body'], true);
    $text = (string)($data['choices'][0]['message']['content'] ?? '');
    if (trim($text) === '') { $err = 'Empty response'; }
    return $text;
}

/** Pull a JSON array of questions out of whatever text the AI returned, and validate it. */
function ai_parse_questions(string $text): array
{
    $text = trim(preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($text)));

    $decoded = json_decode($text, true);
    if (!is_array($decoded)) {
        $s = strpos($text, '['); $e = strrpos($text, ']');
        if ($s !== false && $e !== false && $e > $s) $decoded = json_decode(substr($text, $s, $e - $s + 1), true);
    }
    if (is_array($decoded) && isset($decoded['questions']) && is_array($decoded['questions'])) $decoded = $decoded['questions'];
    if (!is_array($decoded)) return [];

    $clean = [];
    foreach ($decoded as $q) {
        if (!is_array($q)) continue;
        $questionText = trim((string)($q['question_text'] ?? $q['question'] ?? ''));
        $options = array_values(array_filter(array_map(fn($o) => trim((string)(is_array($o) ? ($o['text'] ?? '') : $o)), (array)($q['options'] ?? [])), fn($o) => $o !== ''));
        if ($questionText === '' || count($options) < 2) continue;
        $options = array_slice($options, 0, 4);

        $correct = 0;
        if (isset($q['correct_index']) && is_numeric($q['correct_index'])) {
            $correct = (int)$q['correct_index'];
        } elseif (isset($q['correct_answer'])) {
            $found = array_search(trim((string)$q['correct_answer']), $options, true);
            if ($found !== false) $correct = (int)$found;
        }
        if ($correct < 0 || $correct >= count($options)) $correct = 0;

        $clean[] = [
            'question_text' => $questionText,
            'options'       => $options,
            'correct_index' => $correct,
            'explanation'   => trim((string)($q['explanation'] ?? '')),
            'topic'         => trim((string)($q['topic'] ?? '')) ?: 'General',
        ];
    }
    return $clean;
}

// ==========================================
// AJAX HANDLER 1: GENERATE AI QUESTIONS
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate_ai') {
    header('Content-Type: application/json');
    @set_time_limit(180);
    try {
        csrf_check();
        $sourceText    = trim((string)($_POST['source_text'] ?? ''));
        $questionCount = max(1, min(20, (int)($_POST['question_count'] ?? 5)));
        $difficulty    = trim((string)($_POST['difficulty'] ?? 'Intermediate (Medium)'));

        if ($sourceText === '') {
            echo json_encode(['success' => false, 'message' => 'Source material is required.']);
            exit;
        }
        $sourceText = mb_substr($sourceText, 0, 30000);

        $prompt = "You are an expert curriculum designer. Based ONLY on the following topic or reference text, generate exactly {$questionCount} multiple-choice questions at a '{$difficulty}' difficulty level.\n\n" .
                  "Each question must have exactly 4 options and exactly one correct option.\n" .
                  "RETURN ONLY A RAW JSON ARRAY. Do not use markdown formatting like ```json.\n" .
                  "Format strictly as:\n" .
                  "[\n  {\n    \"question_text\": \"The question here?\",\n    \"options\": [\"Option A\", \"Option B\", \"Option C\", \"Option D\"],\n    \"correct_index\": 0,\n    \"explanation\": \"Why this is correct.\",\n    \"topic\": \"Short Concept Name\"\n  }\n]\n\n" .
                  "Topic/Reference Material:\n{$sourceText}";

        $questions = [];
        $debugLog  = [];

        // Try every Gemini model first, then every OpenRouter model, until one gives usable questions.
        $attempts = [];
        $geminiModels = array_slice(array_values(array_unique(array_merge(AI_GEMINI_MODELS, ai_discover_gemini_models()))), 0, 6);
        foreach ($geminiModels as $m)        $attempts[] = ['gemini', $m];
        foreach (AI_OPENROUTER_MODELS as $m) $attempts[] = ['openrouter', $m];

        foreach ($attempts as [$provider, $model]) {
            $err  = null;
            $text = $provider === 'gemini' ? ai_call_gemini($model, $prompt, $err) : ai_call_openrouter($model, $prompt, $err);

            if (trim($text) === '') {
                $debugLog[] = "$model — " . ($err ?: 'no response');
                continue;
            }
            $questions = ai_parse_questions($text);
            if ($questions) break;
            $debugLog[] = "$model — returned text that was not a valid question list";
        }

        if (!$questions) {
            echo json_encode([
                'success' => false,
                'message' => 'All AI APIs failed.',
                'debug'   => implode("\n\n", $debugLog),
            ]);
            exit;
        }

        echo json_encode(['success' => true, 'questions' => $questions]);
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
        $quizData = json_decode((string)($_POST['quiz_payload'] ?? ''), true);

        if (!is_array($quizData) || empty($quizData['questions']) || !is_array($quizData['questions'])) {
            throw new Exception('Invalid quiz payload.');
        }

        $moduleId = (int)($quizData['module_id'] ?? 0);
        $title    = trim((string)($quizData['title'] ?? ''));
        if ($title === '') throw new Exception('Quiz title is required.');

        // Lecturers may only save into modules they are assigned to.
        if ((string)$user['role'] === 'lecturer') {
            $allowed = array_map('intval', array_column($modules, 'id'));
            if (!in_array($moduleId, $allowed, true)) throw new Exception('You are not assigned to that module.');
        }

        $duration = max(1, (int)($quizData['duration'] ?? 15));
        $points   = max(1, (int)($quizData['points'] ?? 1));

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("INSERT INTO quizzes (module_id, created_by, title, description, quiz_type, duration_minutes, points_per_question, is_published) VALUES (?, ?, ?, ?, 'standard', ?, ?, 0)");
        $stmt->execute([
            $moduleId,
            $userId,
            $title,
            (string)($quizData['description'] ?? ''),
            $duration,
            $points
        ]);
        
        $quizId = (int)$pdo->lastInsertId();

        $qStmt = $pdo->prepare("INSERT INTO quiz_questions (quiz_id, question_text, topic, difficulty, explanation) VALUES (?, ?, ?, ?, ?)");
        $optStmt = $pdo->prepare("INSERT INTO quiz_options (question_id, option_text, is_correct) VALUES (?, ?, ?)");

        foreach ($quizData['questions'] as $q) {
            if (trim((string)($q['question_text'] ?? '')) === '' || empty($q['options']) || !is_array($q['options'])) {
                throw new Exception('A question is missing its text or options.');
            }
            $qStmt->execute([
                $quizId,
                trim((string)$q['question_text']),
                (string)($q['topic'] ?? 'General'),
                (string)($quizData['difficulty'] ?? 'Medium'),
                (string)($q['explanation'] ?? '')
            ]);
            $questionId = $pdo->lastInsertId();

            foreach (array_values($q['options']) as $idx => $optText) {
                $isCorrect = ($idx === (int)($q['correct_index'] ?? 0)) ? 1 : 0;
                $optStmt->execute([$questionId, trim((string)$optText), $isCorrect]);
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
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>AI Quiz Builder — U Learn</title>
<script>
(function(){const p=<?= json_encode($theme, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;const s=localStorage.getItem('ulearn-theme');const t=(s==='light'||s==='dark')?s:((p==='light'||p==='dark')?p:(window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'));document.documentElement.dataset.theme=t;})();
function toggleTheme(){const t=document.documentElement.dataset.theme==='dark'?'light':'dark';document.documentElement.dataset.theme=t;localStorage.setItem('ulearn-theme',t);const b=document.getElementById('themeToggle');if(b)b.textContent=t==='dark'?'☀':'☾';}
document.addEventListener('DOMContentLoaded',()=>{const b=document.getElementById('themeToggle');if(b)b.textContent=document.documentElement.dataset.theme==='dark'?'☀':'☾';});
</script>
<!-- PDF.js for client-side PDF text extraction -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.min.js"></script>

<style>
:root{color-scheme:light;--bg:#f7f8fc;--surface:#fff;--surface-alt:#f1f2f8;--text:#171927;--muted:#777b90;--border:#e7e8f0;--accent:#6756e8;--accent-light:#f0edff;--shadow:0 10px 40px rgba(30,30,60,.04);--green:#18794e;--green-bg:#e9f8f0;--red:#b4233c;--red-bg:#fff0f2}
:root[data-theme="dark"]{color-scheme:dark;--bg:#0c0e16;--surface:#161924;--surface-alt:#202431;--text:#f4f4fa;--muted:#a0a4b6;--border:#2b2e3c;--accent:#a99aff;--accent-light:#292440;--shadow:none;--green:#8ee0b8;--green-bg:#173127;--red:#ffb1bf;--red-bg:#351e28}
*{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif;line-height:1.6}a{color:inherit;text-decoration:none}button,input,select,textarea{font:inherit}button{cursor:pointer}
.layout{display:grid;grid-template-columns:250px minmax(0,1fr);min-height:100vh}
.sidebar{background:var(--surface);border-right:1px solid var(--border);padding:28px 15px;display:flex;flex-direction:column;gap:30px}
.brand{font-size:27px;font-weight:850;letter-spacing:-1.7px;padding:0 14px}.brand span{color:var(--accent)}
.nav-label{padding:0 14px;color:var(--muted);font-size:10px;letter-spacing:1.7px;font-weight:850;text-transform:uppercase}
.nav{display:grid;gap:7px}
.nav a{display:flex;align-items:center;gap:12px;padding:12px 14px;border-radius:13px;color:var(--muted);font-size:13px;font-weight:700;transition:.2s}
.nav a:hover,.nav a.active{background:var(--accent-light);color:var(--accent)}
.nav .ico{width:19px;text-align:center;font-size:16px}
.sidebar-foot{margin-top:auto;padding:16px 14px;border:1px solid var(--border);border-radius:15px;color:var(--muted);font-size:11px}
.main{min-width:0;padding:28px clamp(18px,4vw,54px) 60px}
.topbar{display:flex;justify-content:space-between;align-items:center;gap:15px;margin-bottom:52px}
.crumb{font-size:11px;letter-spacing:1.6px;text-transform:uppercase;color:var(--muted);font-weight:850}
.top-actions{display:flex;align-items:center;gap:12px}.role{font-size:10px;color:var(--muted);font-weight:850;letter-spacing:1.2px;text-transform:uppercase}
.theme-btn{width:42px;height:42px;border:1px solid var(--border);border-radius:12px;background:var(--surface);color:var(--text);font-size:18px}
.logout{font-size:12px;color:var(--muted);font-weight:750}
.intro{margin-bottom:30px;animation:rise .4s ease both}
h1{font-size:clamp(38px,5vw,62px);line-height:1.08;letter-spacing:-.06em;margin:14px 0;font-weight:820}
.subtitle{color:var(--muted);font-size:16px;max-width:700px;margin:0;line-height:1.8}

/* AI Specific Styles */
.ai-nav-btn { background: var(--accent) !important; color: #fff !important; }
.ai-nav-btn:hover { opacity: 0.9; }

.panel { background: var(--surface); border: 1px solid var(--border); border-radius: 18px; padding: 24px; margin-bottom: 20px; box-shadow: var(--shadow); }
.panel h3 { margin-top: 0; color: var(--text); font-size: 20px; }
.form-group { margin-bottom: 18px; }
.form-group label { display: block; margin-bottom: 8px; font-weight: 750; font-size: 13px; color: var(--text); }
.form-control { width: 100%; padding: 12px 14px; border: 1px solid var(--border); border-radius: 10px; background: var(--surface-alt); color: var(--text); font-family: inherit; box-sizing: border-box; outline:none; transition: border-color 0.2s; }
.form-control:focus { border-color: var(--accent); }
textarea.form-control { resize: vertical; min-height: 120px; }

.input-tabs { display: flex; gap: 10px; margin-bottom: 15px; flex-wrap: wrap; }
.input-tab { padding: 8px 16px; border-radius: 8px; border: 1px solid var(--border); background: var(--surface); color: var(--text); cursor: pointer; font-weight: 700; font-size: 13px; transition: all 0.2s; }
.input-tab.active { background: var(--accent-light); border-color: var(--accent); color: var(--accent); }

.source-container { display: none; }
.source-container.active { display: block; }
.file-drop-area { border: 2px dashed var(--border); border-radius: 12px; padding: 40px; text-align: center; cursor: pointer; background: var(--surface-alt); transition: all 0.2s; }
.file-drop-area:hover { border-color: var(--accent); background: var(--accent-light); }

.quiz-btn { display: inline-flex; align-items: center; justify-content: center; border: 1px solid var(--border); border-radius: 12px; padding: 12px 20px; font-weight: 800; cursor: pointer; font-size: 13px; transition: all 0.2s; background: var(--surface-alt); color: var(--text); }
.quiz-btn:hover { border-color: var(--accent); }
.quiz-btn.primary { background: linear-gradient(110deg,#5d4ae0,#8571fa); color: #fff; border: none; box-shadow: 0 9px 22px rgba(103,86,232,.2); }
.quiz-btn.primary:disabled { opacity: 0.7; cursor: wait; }

.draft-card { border: 1px solid var(--border); border-radius: 16px; padding: 20px; margin-bottom: 16px; background: var(--surface); }
.draft-card input[type="text"] { width: 100%; padding: 10px 14px; margin: 6px 0; border: 1px solid var(--border); border-radius: 10px; background: var(--surface-alt); color: var(--text); font-family: inherit; font-size: 13px; outline:none; transition:border-color 0.2s; }
.draft-card input[type="text"]:focus { border-color: var(--accent); }
.draft-card .options-list { margin-top: 12px; padding-left: 0; list-style: none; }
.draft-card .options-list li { margin-bottom: 8px; display: flex; align-items: center; gap: 12px; }
.draft-card .options-list input[type="radio"] { width: 18px; height: 18px; accent-color: var(--accent); cursor: pointer; flex-shrink: 0; }

@keyframes rise{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
@media(max-width:1100px){.layout{grid-template-columns:210px minmax(0,1fr)}.sidebar{padding-inline:10px}}
@media(max-width:720px){
    .layout{grid-template-columns:1fr}
    .sidebar{padding:14px;gap:14px;border-right:0;border-bottom:1px solid var(--border)}
    .sidebar .nav{display:flex;overflow:auto}
    .sidebar .nav a{white-space:nowrap}
    .sidebar-foot,.nav-label{display:none}
    .main{padding:20px 15px 42px}
    .topbar{margin-bottom:34px}
}
@media(prefers-reduced-motion:reduce){*,*::before,*::after{animation-duration:.01ms!important;transition-duration:.01ms!important;scroll-behavior:auto!important}}
</style>
</head>
<body>
<div class="layout">
<aside class="sidebar">
    <a class="brand" href="<?= e(url('lecturer/index.php')) ?>">u learn<span>.</span></a>
    <div class="nav-label">Your workspace</div>
    <nav class="nav" aria-label="Lecturer navigation">
        <a href="index.php"><span class="ico">▦</span> Overview</a>
        <a href="index.php#modules"><span class="ico">▤</span> My modules</a>
        <a href="materials.php"><span class="ico">♧</span> Study materials</a>
        <a href="quizzes.php"><span class="ico">▣</span> Quiz management</a>
        <a class="active ai-nav-btn" href="ai-quiz-builder.php"><span class="ico">🤖</span> AI Quiz Builder</a>
    </nav>
    <div class="sidebar-foot">ULearn lecturer workspace<br>Manage your assigned modules with care.</div>
</aside>
<main class="main">
    <header class="topbar">
        <div class="crumb">U Learn / Quiz Generation</div>
        <div class="top-actions">
            <span class="role"><?= e(str_replace('_', ' ', (string)$user['role'])) ?></span>
            <button class="theme-btn" id="themeToggle" type="button" onclick="toggleTheme()" aria-label="Toggle theme">☾</button>
            <a class="logout" href="<?= e(url('auth/logout.php')) ?>">Log out ↗</a>
        </div>
    </header>

    <section class="intro">
        <h1>🤖 AI Quiz Generator</h1>
        <p class="subtitle">
            Provide a topic, paste text, or upload a reference file (PDF/TXT). ULearn AI will analyze the material and automatically build a fully functional multiple-choice quiz.
        </p>
    </section>

    <!-- STEP 1: Configuration -->
    <div class="panel" id="step1">
        <h3>1. Quiz Configuration</h3>
        
        <div class="form-group">
            <label>Select Target Module</label>
            <select id="moduleId" class="form-control">
                <?php foreach($modules as $m): ?>
                    <option value="<?= $m['id'] ?>"><?= e($m['module_code']) ?> - <?= e($m['module_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label>Quiz Title</label>
            <input type="text" id="quizTitle" class="form-control" placeholder="e.g., Week 4 Review: Advanced Sorting Algorithms" required>
        </div>

        <div style="display:flex; gap: 15px; flex-wrap:wrap;">
            <div class="form-group" style="flex:1; min-width:150px;">
                <label>Number of Questions</label>
                <input type="number" id="quizCount" class="form-control" value="5" min="1" max="20">
            </div>
            <div class="form-group" style="flex:1; min-width:150px;">
                <label>Difficulty</label>
                <select id="quizDifficulty" class="form-control">
                    <option>Beginner (Easy)</option>
                    <option selected>Intermediate (Medium)</option>
                    <option>Advanced (Hard)</option>
                </select>
            </div>
            <div class="form-group" style="flex:1; min-width:150px;">
                <label>Points Per Question</label>
                <input type="number" id="quizPoints" class="form-control" value="2" min="1">
            </div>
            <div class="form-group" style="flex:1; min-width:150px;">
                <label>Total Duration (Mins)</label>
                <input type="number" id="quizDuration" class="form-control" value="15" min="1">
            </div>
        </div>

        <hr style="border:0; border-top:1px solid var(--border); margin:25px 0;">

        <h3>2. Source Material</h3>
        <div class="input-tabs">
            <button class="input-tab active" onclick="switchTab('topic', this)">Type a Topic</button>
            <button class="input-tab" onclick="switchTab('paste', this)">Paste Text</button>
            <button class="input-tab" onclick="switchTab('upload', this)">Upload File (PDF/TXT)</button>
        </div>

        <div id="container-topic" class="source-container active form-group">
            <label>What specific topic should the quiz cover?</label>
            <input type="text" id="inputTopic" class="form-control" placeholder="e.g., Sorting algorithms, specifically Merge Sort and Quick Sort">
        </div>

        <div id="container-paste" class="source-container form-group">
            <label>Paste Reference Text / Transcript</label>
            <textarea id="inputPaste" class="form-control" placeholder="Paste your lecture notes or syllabus content here..."></textarea>
        </div>

        <div id="container-upload" class="source-container form-group">
            <label>Upload Reference Document</label>
            <div class="file-drop-area" onclick="document.getElementById('inputFile').click()">
                <span id="fileLabel" style="color:var(--accent); font-weight:800; font-size:16px;">Click to upload PDF or TXT file</span>
                <input type="file" id="inputFile" accept=".pdf,.txt" style="display:none;" onchange="handleFileUpload(event)">
            </div>
            <input type="hidden" id="extractedFileText">
            <div id="extractingStatus" style="color:var(--green); margin-top:10px; font-weight:700; display:none;">Extracting text from document...</div>
        </div>

        <button class="quiz-btn primary" id="generateBtn" onclick="generateQuestions()" style="margin-top:20px; font-size:15px; padding: 14px 24px;">
            Generate Quiz ✨
        </button>
    </div>

    <!-- STEP 2: Review & Edit Generated Questions -->
    <div class="panel" id="step2" style="display:none;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
            <h3>3. Review & Edit Questions</h3>
            <button class="quiz-btn" onclick="location.reload()">Start Over</button>
        </div>
        
        <p class="subtitle" style="margin-bottom: 20px;">Make any necessary adjustments before saving the quiz as a draft.</p>
        
        <div id="questionsContainer"></div>

        <div style="margin-top: 25px;">
            <button class="quiz-btn primary" id="publishBtn" onclick="publishQuiz()" style="width:100%; font-size: 15px; padding: 14px 24px;">
                Save Draft to Module 🚀
            </button>
        </div>
    </div>
</main>
</div>

<script>
// Tab Switching Logic
let currentSourceType = 'topic';
function switchTab(type, btn) {
    currentSourceType = type;
    document.querySelectorAll('.input-tab').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.source-container').forEach(el => el.classList.remove('active'));
    
    if (btn) btn.classList.add('active');
    document.getElementById('container-' + type).classList.add('active');
}

// Client-Side File Extraction using PDF.js
if (window.pdfjsLib) pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.worker.min.js';

async function handleFileUpload(e) {
    const file = e.target.files[0];
    if (!file) return;

    document.getElementById('fileLabel').textContent = file.name;
    const statusDiv = document.getElementById('extractingStatus');
    const hiddenInput = document.getElementById('extractedFileText');
    
    statusDiv.style.display = 'block';
    statusDiv.style.color = 'var(--text)';
    statusDiv.textContent = 'Extracting text...';
    hiddenInput.value = '';

    try {
        const isPdf = file.type === 'application/pdf' || /\.pdf$/i.test(file.name);
        const isTxt = file.type === 'text/plain' || /\.txt$/i.test(file.name);
        if (isPdf) {
            if (!window.pdfjsLib) throw new Error('PDF library failed to load (check your internet connection).');
            const fileReader = new FileReader();
            fileReader.onload = async function() {
                try {
                    const typedarray = new Uint8Array(this.result);
                    const pdf = await pdfjsLib.getDocument({ data: typedarray }).promise;
                    let fullText = '';
                    for (let i = 1; i <= pdf.numPages && fullText.length < 30000; i++) {
                        const page = await pdf.getPage(i);
                        const textContent = await page.getTextContent();
                        fullText += textContent.items.map(s => s.str).join(' ') + ' ';
                    }
                    hiddenInput.value = fullText.substring(0, 30000);
                    if (!hiddenInput.value.trim()) throw new Error('No readable text found (scanned PDF?).');
                    statusDiv.textContent = 'Extraction complete! Ready to generate.';
                    statusDiv.style.color = 'var(--green)';
                } catch (err) {
                    statusDiv.textContent = 'Failed to extract text: ' + err.message;
                    statusDiv.style.color = 'var(--red)';
                }
            };
            fileReader.readAsArrayBuffer(file);
        } else if (isTxt) {
            const fileReader = new FileReader();
            fileReader.onload = function() {
                hiddenInput.value = this.result.substring(0, 30000);
                statusDiv.textContent = 'Extraction complete! Ready to generate.';
                statusDiv.style.color = 'var(--green)';
            };
            fileReader.readAsText(file);
        } else {
            statusDiv.textContent = 'Unsupported file format. Please upload PDF or TXT.';
            statusDiv.style.color = 'var(--red)';
        }
    } catch (err) {
        statusDiv.textContent = 'Failed to extract text: ' + err.message;
        statusDiv.style.color = 'var(--red)';
    }
}

// Escape text before putting it into HTML attributes / markup
function esc(s) {
    return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

// Generate Quiz Logic
let generatedQuestions = [];
async function generateQuestions() {
    const btn = document.getElementById('generateBtn');
    const title = document.getElementById('quizTitle').value.trim();
    const count = document.getElementById('quizCount').value;
    const difficulty = document.getElementById('quizDifficulty').value;
    
    let sourceText = '';
    if (currentSourceType === 'topic') sourceText = document.getElementById('inputTopic').value.trim();
    if (currentSourceType === 'paste') sourceText = document.getElementById('inputPaste').value.trim();
    if (currentSourceType === 'upload') sourceText = document.getElementById('extractedFileText').value.trim();

    if (!title) return alert("Please enter a Quiz Title.");
    if (!sourceText) return alert("Please provide a topic, paste text, or upload a completely extracted file.");

    btn.disabled = true;
    btn.innerText = "Analyzing material & Generating... (approx. 10-20s)";

    const formData = new FormData();
    formData.append('action', 'generate_ai');
    formData.append('source_text', sourceText);
    formData.append('question_count', count);
    formData.append('difficulty', difficulty);
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
            alert("Error: " + (data.message || 'Unknown error') + (data.debug ? "\n\nDebug Info:\n" + data.debug : ""));
        }
    } catch (e) {
        alert("Network/server error: " + e.message + "\n\nThe server may have returned an error page instead of JSON. Check the PHP error log.");
    } finally {
        btn.disabled = false;
        btn.innerText = "Generate Quiz ✨";
    }
}

function renderQuestions() {
    const container = document.getElementById('questionsContainer');
    container.innerHTML = '';

    generatedQuestions.forEach((q, qIndex) => {
        let optionsHtml = '';
        q.options.forEach((opt, optIndex) => {
            const isChecked = Number(q.correct_index) === optIndex ? 'checked' : '';
            optionsHtml += `
                <li>
                    <input type="radio" name="correct_${qIndex}" value="${optIndex}" ${isChecked} onchange="updateCorrect(${qIndex}, ${optIndex})">
                    <input type="text" value="${esc(opt)}" oninput="updateOption(${qIndex}, ${optIndex}, this.value)">
                </li>
            `;
        });

        const html = `
            <div class="draft-card">
                <label style="font-weight:850; font-size:13px; color:var(--accent); text-transform:uppercase; letter-spacing:1px;">Question ${qIndex + 1} &mdash; ${esc(q.topic)}</label>
                <input type="text" value="${esc(q.question_text)}" oninput="updateQuestion(${qIndex}, this.value)" style="font-weight:700; font-size:15px;">
                <ul class="options-list">
                    ${optionsHtml}
                </ul>
                <label style="font-size:12px; margin-top:12px; display:block; font-weight:750;">Explanation for Correct Answer:</label>
                <input type="text" value="${esc(q.explanation)}" oninput="updateExplanation(${qIndex}, this.value)" style="font-size:13px; color:var(--muted);">
            </div>
        `;
        container.insertAdjacentHTML('beforeend', html);
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
        description: "Generated by ULearn AI Assistant based on uploaded materials.",
        duration: document.getElementById('quizDuration').value,
        points: document.getElementById('quizPoints').value,
        difficulty: document.getElementById('quizDifficulty').value,
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
            window.location.href = 'quizzes.php?quiz=' + data.quiz_id; 
        } else {
            alert("Failed to save: " + data.message);
            btn.disabled = false;
            btn.innerText = "Save Draft to Module 🚀";
        }
    } catch (e) {
        alert("Network error.");
        btn.disabled = false;
        btn.innerText = "Save Draft to Module 🚀";
    }
}
</script>
</body>
</html>