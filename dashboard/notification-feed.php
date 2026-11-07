<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
$user = me();
if (!$user) { http_response_code(401); echo json_encode(['error'=>'Please sign in']); exit; }
try {
    $pdo = db();
    $uid = (int)$user['id'];
    $count = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $count->execute([$uid]);
    $q = $pdo->prepare('SELECT id, type, title, message, target_url, created_at FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY id DESC LIMIT 12');
    $q->execute([$uid]);
    $rows = $q->fetchAll();
    $items = [];
    foreach ($rows as $row) {
        $path = (string)($row['target_url'] ?? '');
        // Only allow internal destinations. No external redirects or script URLs.
        $safe = preg_match('~^(dashboard|lecturer|admin)/[a-zA-Z0-9_./?=&+\-]+$~D', $path)
            && !str_contains($path, '..') && !str_contains($path, '//');
        $items[] = [
            'id' => (int)$row['id'],
            'type' => (string)$row['type'],
            'title' => (string)$row['title'],
            'message' => (string)$row['message'],
            'url' => $safe ? url($path) : url('dashboard/notifications.php'),
            'created_at' => (string)$row['created_at']
        ];
    }
    $role = (string)$user['role'];
    $audience = in_array($role, ['student','lecturer'], true) ? $role : 'admin';
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Colombo'));
    $nowStr = $now->format('Y-m-d H:i:s');
    $a = $pdo->prepare("SELECT id, title, body FROM announcements WHERE is_published = 1 AND audience IN ('all', ?) AND (published_at IS NULL OR published_at <= ?) AND (expires_at IS NULL OR expires_at > ?) ORDER BY id DESC LIMIT 3");
    $a->execute([$audience, $nowStr, $nowStr]);
    echo json_encode(['unread'=>(int)$count->fetchColumn(), 'items'=>$items, 'announcements'=>$a->fetchAll()], JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $ex) {
    error_log('ULearn notification feed error: '.$ex->getMessage());
    http_response_code(503);
    echo json_encode(['error'=>'Notifications temporarily unavailable']);
}
