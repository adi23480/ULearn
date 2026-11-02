<?php
// ulearn/admin/generate_quiz.php
header('Content-Type: application/json');
require_once '../config/bootstrap.php';
require_once '../vendor/autoload.php'; // Requires Composer autoload for PDFParser & Guzzle

use Smalot\PdfParser\Parser;
use GuzzleHttp\Client;

$apiKey = 'YOUR_GEMINI_API_KEY'; // Replace with your valid Gemini API key

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['reference_paper'])) {
    $filePath = $_FILES['reference_paper']['tmp_name'];
    
    // 1. Extract raw text from the uploaded PDF paper
    $parser = new Parser();
    $pdf = $parser->parseFile($filePath);
    $extractedText = substr($pdf->getText(), 0, 15000); // Truncate to fit model context safely

    // 2. Define strict JSON schema so Gemini always responds with clean parseable data
    $jsonSchema = [
        "type" => "ARRAY",
        "items" => [
            "type" => "OBJECT",
            "properties" => [
                "question" => ["type" => "STRING"],
                "options" => [
                    "type" => "ARRAY",
                    "items" => ["type" => "STRING"]
                ],
                "correct_option_index" => ["type" => "INTEGER"],
                "explanation" => ["type" => "STRING"]
            ],
            "required" => ["question", "options", "correct_option_index", "explanation"]
        ]
    ];

    // 3. Call Gemini API endpoint with structured output configuration
    $client = new Client();
    try {
        $response = $client->post("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={$apiKey}", [
            'json' => [
                'contents' => [[
                    'parts' => [[
                        'text' => "Create a 5-question multiple-choice quiz based strictly on the following study reference material. Provide clear contextual explanations for the correct answers:\n\n" . $extractedText
                    ]]
                ]],
                'generationConfig' => [
                    'responseMimeType' => 'application/json',
                    'responseSchema' => $jsonSchema
                ]
            ]
        ]);

        $resultBody = json_decode($response->getBody()->getContents(), true);
        $rawJsonOutput = $resultBody['candidates'][0]['content']['parts'][0]['text'];
        $questions = json_decode($rawJsonOutput, true);

        // 4. Return success response back to the admin frontend UI
        echo json_encode(['status' => 'success', 'questions' => $questions]);
    } catch (\Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}