<?php
declare(strict_types=1);
/** uLearn Buddy: authenticated JSON endpoint; compatible with Sasindu's widget. */
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../includes/ai-helper.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function buddy_response(int $status, array $data): never { http_response_code($status); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') buddy_response(405, ['success'=>false,'message'=>'POST required.']);
$student = me();
if (!$student || ($student['role'] ?? '') !== 'student') buddy_response(401, ['success'=>false,'message'=>'Please sign in as a student.']);
$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input) || !hash_equals(csrf(), (string)($input['csrf'] ?? ''))) buddy_response(419, ['success'=>false,'message'=>'Session expired. Refresh this page.']);
$message = trim((string)($input['message'] ?? ''));
if ($message === '' || mb_strlen($message) > 2000) buddy_response(422, ['success'=>false,'message'=>'Enter a message under 2000 characters.']);
if (too_many_attempts('ulearn_buddy', 20, 300)) buddy_response(429, ['success'=>false,'message'=>'Too many requests. Try again in a few minutes.']);
record_attempt('ulearn_buddy');
$history = [];
foreach (array_slice((array)($input['history'] ?? []), -10) as $turn) {
    if (!is_array($turn)) continue;
    $role = ($turn['role'] ?? '') === 'user' ? 'Student' : 'Assistant';
    $text = trim(mb_substr(strip_tags((string)($turn['text'] ?? '')), 0, 1000));
    if ($text !== '') $history[] = $role . ': ' . $text;
}
$modules = [];
foreach (array_slice((array)($input['modules'] ?? []), 0, 12) as $module) {
    if (is_string($module)) $modules[] = mb_substr(strip_tags($module), 0, 80);
}
$name = explode(' ', trim((string)($student['full_name'] ?? 'Student')))[0];
$prompt = 'You are uLearn Buddy, a helpful, friendly university study assistant. Keep replies clear, supportive, and accurate. Do not invent university policies or claim to access private grades. You may make mistakes; encourage verification of important facts. Student first name: ' . $name . '. Enrolled modules (if provided): ' . implode(', ', $modules) . ".\n" . implode("\n", $history) . "\nStudent: " . $message . "\nAssistant:";
try {
    $reply = trim(ulearnGemini($prompt));
    buddy_response(200, ['success'=>true,'reply'=>$reply]);
} catch (Throwable $e) {
    error_log('uLearn Buddy error: ' . $e->getMessage());
    buddy_response(503, ['success'=>false,'message'=>'AI is temporarily unavailable. Check the Gemini configuration or try again later.']);
}
