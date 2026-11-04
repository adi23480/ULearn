<?php
session_start();
require_once 'includes/ai-helper.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Invalid request method.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$action =$input['action'] ?? '';
$query =$input['query'] ?? '';

if (empty($query)) {
    echo json_encode(['error' => 'Input data cannot be empty.']);
    exit;
}

try {
    if ($action === 'student_ask') {
        $response = callOpenRouterAPI($query);
        echo json_encode(['success' => true, 'data' => $response]);

    } elseif ($action === 'lecturer_generate_quiz') {
        // Enforce a strict JSON structure from Gemini so it's easy to render in the UI
        $prompt = "You are an expert educator. Based on the following reference material, generate exactly 3 multiple-choice questions. " .
                  "Return ONLY a valid JSON array of objects. Do not include markdown formatting like ```json. " .
                  "Each object must have these exact keys: 'question' (string), 'options' (array of 4 strings), and 'correct_answer' (string). " .
                  "Material: " . $query;
        
        $rawResponse = callGeminiAPI($prompt);
        
        // Clean up any stray markdown formatting Gemini might inject
        $cleanJson = str_replace(['```json', '```'], '', $rawResponse);
        
        echo json_encode(['success' => true, 'data' => json_decode(trim($cleanJson), true)]);
    } else {
        echo json_encode(['error' => 'Invalid action specified.']);
    }

} catch (Exception $e) {
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
?>