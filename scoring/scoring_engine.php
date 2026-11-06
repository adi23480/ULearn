

<?php

// Connect to the existing ULearn database
require_once __DIR__ . '/../includes/database.php';


/**
 * Award points to a student for completing an activity.
 *
 * Returns true if points were awarded.
 * Returns false if the activity was already rewarded
 * or no active scoring rule exists.
 */

function awardPoints(
    PDO $pdo,
    int $studentId,
    string $activityType,
    int $activityId,
    ?string $description = null
): bool {

    try {

        // Start database transaction
        $pdo->beginTransaction();

        // STEP 1: Find the scoring rule
        $stmt = $pdo->prepare("
            SELECT points
            FROM scoring_rules
            WHERE activity_type = ?
            AND is_active = 1
            LIMIT 1
        ");

        $stmt->execute([$activityType]);

        $rule = $stmt->fetch(PDO::FETCH_ASSOC);

        // Stop if no scoring rule exists
        if (!$rule) {
            $pdo->rollBack();
            return false;
        }

        $points = (int) $rule['points'];

        // STEP 2: Insert the activity transaction
        // UNIQUE KEY prevents duplicate awards

        $stmt = $pdo->prepare("
            INSERT IGNORE INTO score_transactions
            (student_id, activity_type, activity_id, points, description)
            VALUES (?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $studentId,
            $activityType,
            $activityId,
            $points,
            $description
        ]);

        // STEP 3: Update total score only for a new transaction

        if ($stmt->rowCount() === 1) {

            $stmt = $pdo->prepare("
                INSERT INTO student_scores
                (student_id, total_score)
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE
                total_score = total_score + VALUES(total_score)
            ");

            $stmt->execute([$studentId, $points]);

            $pdo->commit();

            return true;
        }

        // Activity was already rewarded
        $pdo->commit();

        return false;

    } catch (PDOException $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log("Scoring Engine Error: " . $e->getMessage());

        throw $e;
    }
}
