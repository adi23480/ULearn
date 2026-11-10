<?php
declare(strict_types=1);

// Start buffering immediately so PHP errors don't corrupt the JSON
ob_start();
error_reporting(0);
ini_set('display_errors', '0');

// CORRECTED PATHS: Added /../ to step out of the dashboard folder
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../includes/ai-helper.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $prompt = $data['prompt'] ?? $data['message'] ?? $_POST['prompt'] ?? '';

    if (empty($prompt)) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Empty prompt received.']);
        exit;
    }

    // False parameter tells the helper to return raw Markdown for the Chatbot
    $response = ulearnGemini($prompt, false);
    
    ob_end_clean();
    
    echo json_encode([
        'success' => true, 
        'reply' => $response,
        'message' => $response
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    
} catch (Throwable $e) {
    ob_end_clean();
    echo json_encode([
        'success' => false, 
        'message' => 'Error: ' . $e->getMessage()
    ]);
}