<?php
declare(strict_types=1);

function ulearnGeminiKey(): string {
    $file = __DIR__ . '/../config/local.php';
    if (is_file($file)) { 
        $cfg = require $file; 
        return trim((string)($cfg['gemini_api_key'] ?? '')); 
    }
    return '';
}

function ulearnOpenRouterKey(): string {
    $file = __DIR__ . '/../config/local.php';
    if (is_file($file)) { 
        $cfg = require $file; 
        return trim((string)($cfg['openrouter_api_key'] ?? '')); 
    }
    return '';
}

function parseAiMarkdownToHtml(string $text): string {
    $text = str_replace(['$', '\(', '\)', '\[', '\]', '\\$'], '', $text);
    $text = str_replace(['\div', '\times', '\cdot', '\pm', '\%'], ['÷', '×', '⋅', '±', '%'], $text);
    $text = preg_replace('/\\\\text\{([^}]*)\}/', '$1', $text); 
    
    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    
    $text = preg_replace('/###\s*(.*)/', '<h3 style="margin-top: 18px; margin-bottom: 8px; font-size: 16px;">$1</h3>', $text);
    $text = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $text);
    $text = preg_replace('/(?<!\*)\*(?!\*)(.*?)(?<!\*)\*(?!\*)/', '<em>$1</em>', $text);
    $text = preg_replace('/^\*\s+(.*)/m', '&bull; $1', $text);
    
    $text = preg_replace('/\n{3,}/', "\n\n", trim($text)); 
    $text = nl2br($text);
    $text = preg_replace('/<\/h3>\s*(?:<br\s*\/?>\s*)+/i', '</h3>', $text);
    $text = preg_replace('/(?:<br\s*\/?>\s*)+<h3/i', '<h3', $text);

    return $text;
}

// Added $parseHtml parameter to fix the Chatbot crash
function ulearnGemini(string $prompt, bool $parseHtml = true): string {
    $geminiKey = ulearnGeminiKey();
    $openRouterKey = ulearnOpenRouterKey();

    if ($geminiKey === '') {
        return "⚠️ **Configuration Error:** Valid API key missing in config/local.php";
    }

    $file = __DIR__ . '/../config/local.php';
    $cfg = is_file($file) ? require $file : [];
    $model = $cfg['gemini_model'] ?? 'gemini-3.5-flash-lite';
    
    $geminiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . $geminiKey;
    $openRouterUrl = 'https://openrouter.ai/api/v1/chat/completions';

    $debugLog = [];
    $finalOutput = '';

    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $geminiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4); 
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'x-goog-api-key: ' . $geminiKey
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => ['temperature' => 0.5]
        ]));
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            if (!empty($data['candidates'][0]['content']['parts'][0]['text'])) {
                $finalOutput = $data['candidates'][0]['content']['parts'][0]['text'];
            }
        } else {
            $debugLog[] = "Gemini Failed (HTTP $httpCode): " . ($response ?: $curlError);
        }
    }

    if (empty($finalOutput) && function_exists('curl_init') && $openRouterKey !== '') {
        $ch2 = curl_init();
        curl_setopt($ch2, CURLOPT_URL, $openRouterUrl);
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch2, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch2, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch2, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch2, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $openRouterKey,
            'HTTP-Referer: http://localhost'
        ]);
        curl_setopt($ch2, CURLOPT_POSTFIELDS, json_encode([
            'model' => 'meta-llama/llama-3.1-8b-instruct:free',
            'messages' => [['role' => 'user', 'content' => $prompt]]
        ]));
        
        $response2 = curl_exec($ch2);
        $httpCode2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        $curlError2 = curl_error($ch2);
        curl_close($ch2);

        if ($httpCode2 === 200 && $response2) {
            $data2 = json_decode($response2, true);
            if (!empty($data2['choices'][0]['message']['content'])) {
                $finalOutput = $data2['choices'][0]['message']['content'];
            }
        } else {
            $debugLog[] = "OpenRouter Failed (HTTP $httpCode2): " . ($response2 ?: $curlError2);
        }
    }

    if (!empty($finalOutput)) {
        // Fix for Chatbot HTTP 200 crash: return raw text if html parsing is disabled
        if (strpos($prompt, 'RAW JSON ARRAY') !== false || !$parseHtml) {
            return $finalOutput;
        }
        return parseAiMarkdownToHtml($finalOutput);
    }

    $debugString = implode(" | ", $debugLog);
    return "⚠️ <strong>AI Connection Error:</strong> We couldn't reach the AI servers right now.<br><br><em>(Debug: $debugString)</em>";
}