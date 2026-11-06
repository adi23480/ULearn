<?php
// scoring/fetch_leaderboard.php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../includes/database.php';

header('Content-Type: application/json');

$current_student_id = $_SESSION['user_id'] ?? 1; // Default to ID 1 for testing if session is not set

try {
    // 1. Top 10 Leaderboard (Using safe fallback for avatar)
    $top10_stmt = $pdo->prepare("
        SELECT 
            s.student_id,
            u.full_name,
            '../assets/images/default-avatar.png' AS avatar_url,
            s.total_score,
            s.updated_at
        FROM student_scores s
        JOIN users u ON s.student_id = u.id
        ORDER BY s.total_score DESC, s.updated_at ASC
        LIMIT 10
    ");
    $top10_stmt->execute();
    $top10 = $top10_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. User Data, Analytics Breakdown, Tracking History & Recommendations
    $user_data = null;
    $score_breakdown = [];
    $recent_history = [];
    $recommendations = [];

    if ($current_student_id > 0) {
        $user_stmt = $pdo->prepare("
            SELECT 
                s.student_id,
                u.full_name,
                s.total_score,
                (SELECT COUNT(*) + 1 FROM student_scores WHERE total_score > s.total_score) AS `rank`
            FROM student_scores s
            JOIN users u ON s.student_id = u.id
            WHERE s.student_id = :student_id
        ");
        $user_stmt->execute(['student_id' => $current_student_id]);
        $user_data = $user_stmt->fetch(PDO::FETCH_ASSOC);

        // Analytics: Score Breakdown by Activity Type
        $breakdown_stmt = $pdo->prepare("
            SELECT 
                activity_type, 
                SUM(points) as total_points, 
                COUNT(*) as action_count
            FROM score_transactions
            WHERE student_id = :student_id
            GROUP BY activity_type
        ");
        $breakdown_stmt->execute(['student_id' => $current_student_id]);
        $score_breakdown = $breakdown_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Tracking: Recent Activity Log
        $history_stmt = $pdo->prepare("
            SELECT 
                activity_type, 
                points, 
                description, 
                created_at
            FROM score_transactions
            WHERE student_id = :student_id
            ORDER BY created_at DESC
            LIMIT 5
        ");
        $history_stmt->execute(['student_id' => $current_student_id]);
        $recent_history = $history_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Smart Recommendations & Quiz Analysis Logic
        if (!empty($score_breakdown)) {
            usort($score_breakdown, function($a, $b) {
                return $a['total_points'] <=> $b['total_points'];
            });
            $lowest = $score_breakdown[0];
            $formattedName = ucwords(str_replace('_', ' ', $lowest['activity_type']));
            
            $recommendations[] = "Focus area: Your lowest scoring category is <strong>{$formattedName}</strong> (" . $lowest['total_points'] . " pts). Review corresponding course materials.";
            
            foreach ($score_breakdown as $item) {
                if (stripos($item['activity_type'], 'quiz') !== false && $item['total_points'] < 30) {
                    $recommendations[] = "Quiz Analysis: Consider retaking practice quizzes to improve accuracy and boost your overall rank.";
                }
            }
        } else {
            $recommendations[] = "Complete your first set of quizzes or assignments to unlock personalized performance analysis.";
        }
    }

    echo json_encode([
        'status' => 'success',
        'top10' => $top10,
        'current_user' => $user_data,
        'breakdown' => $score_breakdown,
        'history' => $recent_history,
        'recommendations' => $recommendations
    ]);
} catch (PDOException $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Failed to fetch data: ' . $e->getMessage()
    ]);
}