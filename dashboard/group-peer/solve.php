<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

$user = qa_require_user();

qa_post();

$qid = (int)($_POST['question_id'] ?? 0);
$aid = (int)($_POST['answer_id'] ?? 0);

if ($qid <= 0 || $aid <= 0) {
    http_response_code(400);
    exit('Invalid question or answer.');
}

$pdo = db();

// Check that the answer belongs to the selected question.
$st = $pdo->prepare(
    'SELECT q.user_id
     FROM qa_questions q
     INNER JOIN qa_answers a
        ON a.question_id = q.id
     WHERE q.id = ?
       AND a.id = ?
     LIMIT 1'
);

$st->execute([$qid, $aid]);

$row = $st->fetch();

if (!$row) {
    http_response_code(404);
    exit('Question or answer not found.');
}

// Check whether the current user owns the question.
$isQuestionOwner = (
    (int)$row['user_id'] === (int)$user['id']
);

// Check whether the current user is an active lecturer.
$isActiveLecturer = false;

if (($user['role'] ?? '') === 'lecturer') {

    $lecturerCheck = $pdo->prepare(
        'SELECT 1
         FROM lecturer_profiles
         WHERE user_id = ?
           AND is_active = 1
         LIMIT 1'
    );

    $lecturerCheck->execute([(int)$user['id']]);

    $isActiveLecturer = (bool)$lecturerCheck->fetchColumn();
}

// Only the question owner or an active lecturer can solve answers.
if (!$isQuestionOwner && !$isActiveLecturer) {
    http_response_code(403);
    exit('You are not allowed to mark this answer as solved.');
}

// Mark the selected answer as the accepted solution.
$update = $pdo->prepare(
    'UPDATE qa_questions
     SET is_solved = 1,
         solved_answer_id = ?,
         updated_at = CURRENT_TIMESTAMP
     WHERE id = ?'
);

$update->execute([$aid, $qid]);

qa_redirect('view.php?id=' . $qid);
