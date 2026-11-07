

<?php

// Connect to the existing ULearn database
require_once __DIR__ . '/../config/bootstrap.php';


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

/** Award quiz XP once per attempt, even when results are reopened. */
function awardQuizXp(PDO $pdo, int $studentId, int $attemptId): void {
    $stmt = $pdo->prepare("SELECT id, score, max_score, status FROM quiz_attempts WHERE id=? AND user_id=? AND status IN ('submitted','timed_out') LIMIT 1");
    $stmt->execute([$attemptId, $studentId]);
    $attempt = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$attempt) return;
    $count = $pdo->prepare("SELECT COUNT(*) FROM quiz_attempt_answers WHERE attempt_id=? AND is_correct=1");
    $count->execute([$attemptId]);
    $correct = (int)$count->fetchColumn();
    // Each activity type + attempt ID is unique. Correct-answer points are multiplied by correct count.
    awardPoints($pdo, $studentId, 'quiz_completion', $attemptId, 'Completed quiz attempt #'.$attemptId);
    if ($correct > 0) {
        awardQuizCorrectXp($pdo, $studentId, $attemptId, $correct);
    }
    if ((float)$attempt['max_score'] > 0 && (float)$attempt['score'] >= (float)$attempt['max_score']) {
        awardPoints($pdo, $studentId, 'perfect_quiz_bonus', $attemptId, 'Perfect quiz attempt #'.$attemptId);
    }
}
function awardQuizCorrectXp(PDO $pdo, int $studentId, int $attemptId, int $correct): void {
    $stmt=$pdo->prepare("SELECT points FROM scoring_rules WHERE activity_type='quiz_correct_answer' AND is_active=1 LIMIT 1");
    $stmt->execute();
    $per=$stmt->fetchColumn();
    if ($per===false) return;
    $points=(int)$per*$correct;
    $pdo->beginTransaction();
    try {
        $insert=$pdo->prepare("INSERT IGNORE INTO score_transactions(student_id,activity_type,activity_id,points,description) VALUES (?,'quiz_correct_answer',?,?,?)");
        $insert->execute([$studentId,$attemptId,$points,$correct.' correct answers on quiz attempt #'.$attemptId]);
        if ($insert->rowCount()===1) {
            $upd=$pdo->prepare("INSERT INTO student_scores(student_id,total_score) VALUES (?,?) ON DUPLICATE KEY UPDATE total_score=total_score+VALUES(total_score)");
            $upd->execute([$studentId,$points]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
