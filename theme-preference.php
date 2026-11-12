<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';

$user = require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

try {
    $pdo = db();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        echo json_encode([
            'success' => true,
            'theme' => in_array(
                (string)($user['theme'] ?? ''),
                ['light', 'dark', 'system'],
                true
            ) ? $user['theme'] : 'system',
            'csrf' => csrf()
        ]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Allow: GET, POST');

        echo json_encode([
            'success' => false,
            'message' => 'Method not allowed.'
        ]);
        exit;
    }

    csrf_check();

    $theme = trim((string)($_POST['theme'] ?? ''));

    if (!in_array($theme, ['light', 'dark', 'system'], true)) {
        http_response_code(422);

        echo json_encode([
            'success' => false,
            'message' => 'Invalid theme preference.'
        ]);
        exit;
    }

    $stmt = $pdo->prepare(
        'UPDATE users SET theme = ? WHERE id = ?'
    );

    $stmt->execute([
        $theme,
        (int)$user['id']
    ]);

    echo json_encode([
        'success' => true,
        'theme' => $theme
    ]);
    exit;

} catch (Throwable $ex) {
    error_log('ULearn theme preference error: ' . $ex->getMessage());

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to save theme preference.'
    ]);
    exit;
}
