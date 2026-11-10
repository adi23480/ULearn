<?php
// includes/ai-explain.php — explains a wrong quiz answer (uses the shared helper)
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/ai-helper.php';

header('Content-Type: application/json');

if (!me()) { http_response_code(401); echo json_encode(['error' => 'Please log in.']); exit; }

$data = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($data) || !isset($data['question'], $data['student_answer'], $data['correct_answer'])) {
    echo json_encode(['error' => 'Missing required fields.']);
    exit;
}

$prompt = "The student answered a quiz question incorrectly.\n"
    . "Question: " . mb_substr((string)$data['question'], 0, 1500) . "\n"
    . "Student's wrong answer: " . mb_substr((string)$data['student_answer'], 0, 500) . "\n"
    . "Correct answer: " . mb_substr((string)$data['correct_answer'], 0, 500) . "\n"
    . "Explain clearly and concisely why the student's answer is wrong and why the correct answer is right.";

session_write_close();
$r = uai_generate($prompt, ['system' => 'You are a helpful, encouraging tutor.', 'max_tokens' => 2048]);

if ($r['ok']) echo json_encode(['source' => $r['model'], 'explanation' => $r['text']]);
else echo json_encode(['error' => 'AI services are currently unavailable. Please try again later.', 'debug' => $r['debug']]);