<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';

$user = require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode([
        'theme' => $user['theme'] ?? 'system',
        'csrf' => csrf()
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $theme = (string)($_POST['theme'] ?? '');

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
}

http_response_code(405);

echo json_encode([
    'success' => false,
    'message' => 'Method not allowed.'
]);
