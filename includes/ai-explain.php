<?php
// ai_explain.php
header('Content-Type: application/json');

// 1. Receive JSON payload from the frontend
$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['question']) || !isset($data['student_answer']) || !isset($data['correct_answer'])) {
    echo json_encode(['error' => 'Missing required fields.']);
    exit;
}

$question = $data['question'];
$studentAnswer = $data['student_answer'];
$correctAnswer = $data['correct_answer'];

// 2. Construct the Prompt
$prompt = "You are a helpful, encouraging tutor. The student answered a quiz question incorrectly. \n" .
          "Question: $question \n" .
          "Student's Wrong Answer: $studentAnswer \n" .
          "Correct Answer: $correctAnswer \n" .
          "Explain clearly and concisely why the student's answer is wrong and why the correct answer is right.";

// 3. Setup API Keys (Replace with your actual keys)
$geminiApiKey = 'AQ.Ab8RN6JLt3sJ386IMeyOw5CvrSucyvGpJOERkojW3Fqko9kW-A';
$openRouterApiKey = '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';

// 4. Try Primary API: Gemini
$geminiResponse = callGemini($prompt, $geminiApiKey);
if ($geminiResponse['success']) {
    echo json_encode(['source' => 'Gemini', 'explanation' => $geminiResponse['text']]);
    exit;
}

// 5. Try Fallback API: OpenRouter
$openRouterResponse = callOpenRouter($prompt, $openRouterApiKey);
if ($openRouterResponse['success']) {
    echo json_encode(['source' => 'OpenRouter', 'explanation' => $openRouterResponse['text']]);
    exit;
}

// 6. Both Failed
echo json_encode(['error' => 'AI services are currently unavailable. Please try again later.']);
exit;

/**
 * Call Google Gemini API
 */
function callGemini($prompt, $apiKey) {
    // Using the Gemini 1.5 Flash endpoint
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . $apiKey;
    
    $payload = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt]
                ]
            ]
        ]
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 10); // 10 second timeout
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode == 200 && $response) {
        $result = json_decode($response, true);
        if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
            return ['success' => true, 'text' => $result['candidates'][0]['content']['parts'][0]['text']];
        }
    }
    return ['success' => false, 'debug' => $response];
}

/**
 * Call OpenRouter API (Fallback)
 */
function callOpenRouter($prompt, $apiKey) {
    // OpenRouter chat completions endpoint
    $url = 'https://openrouter.ai/api/v1/chat/completions';
    
    $payload = [
        'model' => 'meta-llama/llama-3.1-8b-instruct', // Cheap, fast fallback model
        'messages' => [
            ['role' => 'user', 'content' => $prompt]
        ]
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
        'HTTP-Referer: https://your-domain.com', // REQUIRED by OpenRouter
        'X-Title: ULearn' // Optional, for your dashboard
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode == 200 && $response) {
        $result = json_decode($response, true);
        if (isset($result['choices'][0]['message']['content'])) {
            return ['success' => true, 'text' => $result['choices'][0]['message']['content']];
        }
    }
    return ['success' => false, 'debug' => $response];
}
?>