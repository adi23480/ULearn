<?php
declare(strict_types=1);

// Adjust the path to bootstrap.php if your folder structure requires it
require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/includes/ai-helper.php';

header('Content-Type: application/json');

// Get prompt from either raw JSON payload or standard POST
$data = json_decode(file_get_contents('php://input'), true);
$prompt = $data['prompt'] ?? $data['message'] ?? $_POST['prompt'] ?? '';

if (empty($prompt)) {
    echo json_encode(['success' => false, 'message' => 'Empty prompt received.']);
    exit;
}

try {
    // Calls the upgraded function in ai-helper.php
    $response = ulearnGemini($prompt);
    
    // Output the response
    echo json_encode([
        'success' => true, 
        'response' => $response,
        'message' => $response
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false, 
        'error' => $e->getMessage()
    ]);
}