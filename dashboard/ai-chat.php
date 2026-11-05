<?php
declare(strict_types=1);

/**
 * uLearn Buddy — chat endpoint for the student dashboard bot.
 * POST JSON: { csrf, message, history: [{role:'user'|'bot', text}], modules: [string] }
 * Returns:   { success: true, reply } or { success: false, message }
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../includes/ai-helper.php'; // GEMINI_API_KEY, OPENROUTER_API_KEY

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function bot_out(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// Preferred models first; the rest are discovered from Google automatically.
const BOT_GEMINI_MODELS     = ['gemini-3.8-flash', 'gemini-3.5-flash-lite'];
const BOT_OPENROUTER_MODELS = ['meta-llama/llama-3.3-70b-instruct']; // paid fallback, only used if Gemini fails

function bot_curl(string $url, array $headers, ?array $payload, int $timeout = 40): array
{
    $host   = $_SERVER['SERVER_NAME'] ?? '';
    $verify = !in_array($host, ['localhost', '127.0.0.1', '::1'], true); // XAMPP often has no CA bundle
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        CURLOPT_SSL_VERIFYPEER => $verify,
        CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
    ];
    if ($payload !== null) {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload);
    }
    curl_setopt_array($ch, $opts);
    $body  = curl_exec($ch);
    $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => is_string($body) ? $body : '', 'error' => $error];
}

function bot_failure(array $r): string
{
    $d   = json_decode($r['body'], true);
    $msg = $d['error']['message'] ?? ($d['error'] ?? null);
    if (is_array($msg)) $msg = json_encode($msg);
    if (!$msg) $msg = $r['error'] !== '' ? $r['error'] : mb_substr($r['body'], 0, 200);
    return "HTTP {$r['code']}: " . trim((string)$msg);
}

/** Models this API key can call (cached 6 h; same cache file as the AI Quiz Builder). */
function bot_discover_models(): array
{
    $cache = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ulearn_gemini_models.json';
    if (is_file($cache) && filemtime($cache) > time() - 6 * 3600) {
        $c = json_decode((string)file_get_contents($cache), true);
        if (is_array($c) && $c) return $c;
    }
    $r = bot_curl('https://generativelanguage.googleapis.com/v1beta/models?pageSize=200', ['x-goog-api-key: ' . GEMINI_API_KEY], null, 20);
    if ($r['code'] !== 200) return [];

    $found = [];
    foreach ((json_decode($r['body'], true)['models'] ?? []) as $m) {
        $id = preg_replace('#^models/#', '', (string)($m['name'] ?? ''));
        if (!in_array('generateContent', (array)($m['supportedGenerationMethods'] ?? []), true)) continue;
        if (!str_contains($id, 'flash')) continue;
        if (preg_match('/image|tts|live|audio|embedding|robotics|computer|learnlm|gemma/i', $id)) continue;
        $found[] = $id;
    }
    usort($found, function ($a, $b) {
        $pa = preg_match('/preview|exp/i', $a) ? 1 : 0; $pb = preg_match('/preview|exp/i', $b) ? 1 : 0;
        if ($pa !== $pb) return $pa <=> $pb;
        $la = str_contains($a, 'lite') ? 1 : 0; $lb = str_contains($b, 'lite') ? 1 : 0;
        if ($la !== $lb) return $la <=> $lb;
        return strnatcmp($b, $a);
    });
    if ($found) @file_put_contents($cache, json_encode($found));
    return $found;
}

// ---------- auth, method, CSRF ----------
$user = me();
if (!$user) bot_out(['success' => false, 'message' => 'Please log in again.'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') bot_out(['success' => false, 'message' => 'Invalid request.'], 405);

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) bot_out(['success' => false, 'message' => 'Invalid request.'], 400);
if (!hash_equals(csrf(), (string)($in['csrf'] ?? ''))) bot_out(['success' => false, 'message' => 'Session expired. Refresh the page.'], 419);

$message = trim((string)($in['message'] ?? ''));
if ($message === '') bot_out(['success' => false, 'message' => 'Type a message first.'], 422);
$message = mb_substr($message, 0, 2000);

// Simple rate limit: 25 messages / 5 minutes per session.
if (too_many_attempts('student_bot', 25, 300)) bot_out(['success' => false, 'message' => 'Slow down a little 😊 — try again in a few minutes.'], 429);
record_attempt('student_bot');

// ---------- build conversation ----------
$turns = [];
foreach (array_slice((array)($in['history'] ?? []), -12) as $h) {
    if (!is_array($h)) continue;
    $role = ($h['role'] ?? '') === 'user' ? 'user' : 'model';
    $text = trim(mb_substr((string)($h['text'] ?? ''), 0, 2000));
    if ($text === '') continue;
    $turns[] = ['role' => $role, 'text' => $text];
}
$turns[] = ['role' => 'user', 'text' => $message];

// Gemini needs the first turn to be "user" and roles to alternate: merge repeats, drop a leading model turn.
$contents = [];
foreach ($turns as $t) {
    if (!$contents && $t['role'] === 'model') continue;
    $last = count($contents) - 1;
    if ($last >= 0 && $contents[$last]['role'] === $t['role']) {
        $contents[$last]['parts'][0]['text'] .= "\n" . $t['text'];
    } else {
        $contents[] = ['role' => $t['role'], 'parts' => [['text' => $t['text']]]];
    }
}

$parts = preg_split('/\s+/', trim((string)$user['full_name']));
$first = $parts[0] ?? 'there';
$modules = [];
foreach (array_slice((array)($in['modules'] ?? []), 0, 20) as $m) {
    $m = trim(mb_substr(strip_tags((string)$m), 0, 80));
    if ($m !== '') $modules[] = $m;
}

$system = "You are uLearn Buddy, a friendly, upbeat study assistant inside the uLearn university learning platform. "
    . "The student's first name is {$first}. "
    . "When the chat started you already greeted them: asked how their day is going and what they want to learn. "
    . ($modules ? "They are enrolled in: " . implode('; ', $modules) . ". " : "")
    . "Guidelines: keep replies short and conversational (about 150 words unless they ask for depth); explain step by step with simple examples; "
    . "if they share how their day is going, respond warmly in one or two sentences and steer toward what they'd like to learn; "
    . "end most replies with one short follow-up question or an offer to quiz them; "
    . "use simple markdown only (**bold**, bullet lists, `code`, fenced code blocks); "
    . "reply in the language the student writes in; "
    . "stay on studying, learning, careers and university life, and gently steer back otherwise; "
    . "never invent facts about their grades, deadlines or lecturers, and be honest when you are unsure.";

@set_time_limit(120);
session_write_close(); // don't block other tabs while waiting for the AI

// ---------- call the AI ----------
$reply = '';
$debug = [];

if (defined('GEMINI_API_KEY') && GEMINI_API_KEY !== '') {
    $models = array_slice(array_values(array_unique(array_merge(BOT_GEMINI_MODELS, bot_discover_models()))), 0, 5);
    foreach ($models as $model) {
        $r = bot_curl(
            'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent',
            ['Content-Type: application/json', 'x-goog-api-key: ' . GEMINI_API_KEY],
            [
                'systemInstruction' => ['parts' => [['text' => $system]]],
                'contents'          => $contents,
                'generationConfig'  => ['temperature' => 0.8, 'maxOutputTokens' => 2048],
            ]
        );
        if ($r['code'] === 200) {
            $d = json_decode($r['body'], true);
            $text = '';
            foreach (($d['candidates'][0]['content']['parts'] ?? []) as $p) $text .= (string)($p['text'] ?? '');
            if (trim($text) !== '') { $reply = trim($text); break; }
            $debug[] = "$model — empty reply (" . ($d['candidates'][0]['finishReason'] ?? 'unknown') . ')';
        } else {
            $debug[] = "$model — " . bot_failure($r);
        }
    }
}

if ($reply === '' && defined('OPENROUTER_API_KEY') && OPENROUTER_API_KEY !== '') {
    $msgs = [['role' => 'system', 'content' => $system]];
    foreach ($contents as $c) $msgs[] = ['role' => $c['role'] === 'model' ? 'assistant' : 'user', 'content' => $c['parts'][0]['text']];
    foreach (BOT_OPENROUTER_MODELS as $model) {
        $r = bot_curl(
            'https://openrouter.ai/api/v1/chat/completions',
            ['Content-Type: application/json', 'Authorization: Bearer ' . OPENROUTER_API_KEY, 'HTTP-Referer: http://localhost', 'X-Title: uLearn Buddy'],
            ['model' => $model, 'messages' => $msgs],
            60
        );
        if ($r['code'] === 200) {
            $d = json_decode($r['body'], true);
            $text = trim((string)($d['choices'][0]['message']['content'] ?? ''));
            if ($text !== '') { $reply = $text; break; }
        }
        $debug[] = "$model — " . bot_failure($r);
    }
}

if ($reply === '') {
    $out = ['success' => false, 'message' => "I couldn't reach my AI brain just now. Please try again in a moment."];
    if (defined('LOCAL_DEVELOPMENT') && LOCAL_DEVELOPMENT) $out['debug'] = implode("\n", $debug);
    bot_out($out, 502);
}

bot_out(['success' => true, 'reply' => $reply]);