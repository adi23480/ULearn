
<?php

require_once __DIR__ . '/scoring_engine.php';

try {
    echo "<h2>ULearn Scoring System Test</h2>";

    // Check database connection
    echo "Database: " . htmlspecialchars(
        $pdo->query("SELECT DATABASE()")->fetchColumn()
    );

    echo "<hr>";

    // Check whether the three tables exist
    $tables = [
        'student_scores',
        'score_transactions',
        'scoring_rules'
    ];

    foreach ($tables as $table) {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);

        echo htmlspecialchars($table) . ": ";

        echo $stmt->fetchColumn()
            ? "Found"
            : "Not found";

        echo "<br>";
    }

    echo "<hr><h3>Scoring Rules</h3>";

    $stmt = $pdo->query("
        SELECT activity_type, points, is_active
        FROM scoring_rules
    ");

    while ($rule = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo htmlspecialchars($rule['activity_type'])
            . " - " . $rule['points'] . " points<br>";
    }

} catch (PDOException $e) {
    echo "Error: " . htmlspecialchars($e->getMessage());
}
