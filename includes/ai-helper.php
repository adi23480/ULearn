<?php
declare(strict_types=1);
/** Server-only Gemini client. Set GEMINI_API_KEY in your environment or config/local.php. */
function ulearnGeminiKey(): string {
    $key = trim((string)(getenv('GEMINI_API_KEY') ?: ''));
    if ($key !== '') return $key;
    $file = __DIR__ . '/../config/local.php';
    if (is_file($file)) { $cfg = require $file; return trim((string)($cfg['gemini_api_key'] ?? '')); }
    return '';
}
function ulearnGemini(string $prompt): string {
    $key = ulearnGeminiKey();
    if ($key === '') throw new RuntimeException('Gemini API key is missing. Add gemini_api_key to config/local.php.');
    if (!function_exists('curl_init')) throw new RuntimeException('Enable the PHP cURL extension in XAMPP.');
    $configFile = __DIR__ . '/../config/local.php';
    $localConfig = is_file($configFile) ? require $configFile : [];
    $model = trim((string)(getenv('GEMINI_MODEL') ?: ($localConfig['gemini_model'] ?? 'gemini-3.8-flash')));
    if (!preg_match('/^[a-zA-Z0-9._-]+$/', $model)) throw new RuntimeException('Invalid Gemini model name.');
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $key],
        CURLOPT_POSTFIELDS => json_encode(['contents' => [['parts' => [['text' => $prompt]]]], 'generationConfig' => ['temperature' => 0.35]], JSON_THROW_ON_ERROR)]);
    $body = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch); curl_close($ch);
    if ($body === false) throw new RuntimeException('Gemini connection failed: ' . $curlError);
    $json = json_decode($body, true);
    if ($status !== 200) {
        $message = (string)($json['error']['message'] ?? 'Unknown API error');
        throw new RuntimeException('Gemini HTTP ' . $status . ': ' . mb_substr($message, 0, 250));
    }
    $text = (string)($json['candidates'][0]['content']['parts'][0]['text'] ?? '');
    if (trim($text) === '') throw new RuntimeException('Gemini returned no text.');
    return $text;
}
