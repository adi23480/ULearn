<?php
// uLearn AI Helper Config
define('GEMINI_API_KEY', 'AQ.Ab8RN6ICdE2ES1yrj214gOiqP9OAi40ofcjD3F8f3qdkEW_Yyw');
define('OPENROUTER_API_KEY', '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');

// Gemini API Function (Best for Lecturer Quiz Generation & Content Summaries)
function callGeminiAPI($prompt) {$url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . GEMINI_API_KEY;
    
    $data = [
        "contents" => [
            ["parts" => [["text" => $prompt]]]
        ]
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $result = json_decode($response, true);
    
    if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
        return $result['candidates'][0]['content']['parts'][0]['text'];
    }
    return json_encode(["error" => "Failed to generate response from Gemini."]);
}

// OpenRouter API Function (Best for Student Chat interactions)
function callOpenRouterAPI($userMessage) {
    $url = 'https://openrouter.ai/api/v1/chat/completions';$data = [
        "model" => "anthropic/claude-3-haiku", 
        "messages" => [
            ["role" => "system", "content" => "You are a helpful educational tutor for the uLearn platform."],
            ["role" => "user", "content" => $userMessage]
        ]
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . OPENROUTER_API_KEY
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $result = json_decode($response, true);
    
    if (isset($result['choices'][0]['message']['content'])) {
        return $result['choices'][0]['message']['content'];
    }
    return "Error generating response from OpenRouter.";
}
?>