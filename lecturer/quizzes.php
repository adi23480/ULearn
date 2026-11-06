<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
$user = require_login();

if ((string)$user['role'] !== 'lecturer') {
    http_response_code(403);
    exit('Access denied. Lecturer permission required.');
}

$pdo = db();
$profileStmt = $pdo->prepare('SELECT id FROM lecturer_profiles WHERE user_id = ? AND is_active = 1 LIMIT 1');
$profileStmt->execute([(int)$user['id']]);
$lecturerId = (int)$profileStmt->fetchColumn();
if ($lecturerId < 1) { http_response_code(403); exit('Your lecturer profile is not active.'); }

// An active curriculum assignment is required for every quiz action.
$assignedStmt = $pdo->prepare('SELECT DISTINCT m.id, m.module_code, m.module_name FROM lecturer_module_assignments a JOIN academic_curriculum c ON c.id = a.curriculum_id JOIN academic_modules m ON m.id = c.module_id JOIN academic_degrees d ON d.id = c.degree_id JOIN academic_batches b ON b.id = c.batch_id WHERE a.lecturer_id = ? AND a.is_active = 1 AND c.is_active = 1 AND m.is_active = 1 AND d.is_active = 1 AND b.is_active = 1 ORDER BY m.module_code');
$assignedStmt->execute([$lecturerId]);
$assignedModules = $assignedStmt->fetchAll(PDO::FETCH_ASSOC);
$allowedModuleIds = array_map('intval', array_column($assignedModules, 'id'));

function lecturerQuizOwned(PDO $pdo, int $quizId, int $userId, array $allowedModuleIds): array {
    $stmt = $pdo->prepare('SELECT id, module_id, is_published FROM quizzes WHERE id = ? AND created_by = ? LIMIT 1');
    $stmt->execute([$quizId, $userId]);
    $quiz = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$quiz || !in_array((int)$quiz['module_id'], $allowedModuleIds, true)) {
        throw new RuntimeException('Quiz not found or you are no longer assigned to its module.');
    }
    return $quiz;
}


/** Notify enrolled students when a quiz becomes published or its published details change.
 * Must be called inside the transaction that changes the quiz.
 */
function lecturerQuizNotifyStudents(PDO $pdo, int $moduleId, int $actorId, int $quizId, string $quizTitle, bool $updated = false): void {
    $type = $updated ? 'quiz_updated' : 'quiz_published';
    $title = $updated ? 'Quiz updated' : 'New quiz available';
    $message = ($updated ? 'A published quiz was updated: ' : 'A new quiz was published: ') . $quizTitle;
    $target = url('dashboard/quizzes.php');
    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, actor_user_id, type, title, message, target_url) SELECT DISTINCT sme.user_id, ?, ?, ?, ?, ? FROM student_module_enrollments sme INNER JOIN users u ON u.id=sme.user_id AND u.role='student' WHERE sme.module_id=?");
    $stmt->execute([$actorId, $type, $title, $message, $target, $moduleId]);
}

$error = '';
$notice = '';
$editingQuiz = null;
$editingQuestion = null;
$bulkText = '';
$bulkPreview = [];
$bulkErrors = [];
$selectedQuizId = filter_input(INPUT_GET, 'quiz', FILTER_VALIDATE_INT) ?: 0;
$editQuizId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT) ?: 0;

// Keep the selected quiz attached to POST previews/actions as well as GET requests.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quiz_id'])) {
    $postedQuizId = filter_var($_POST['quiz_id'], FILTER_VALIDATE_INT);
    if ($selectedQuizId < 1 && $postedQuizId && $postedQuizId > 0) {
        $selectedQuizId = (int)$postedQuizId;
    }
}
$editQuestionId = filter_input(INPUT_GET, 'edit_question', FILTER_VALIDATE_INT) ?: 0;
if ($selectedQuizId < 1 && $editQuizId > 0) { $selectedQuizId = $editQuizId; }

if (isset($_SESSION['quiz_notice'])) {
    $notice = (string) $_SESSION['quiz_notice'];
    unset($_SESSION['quiz_notice']);
}

function quizRedirect(string $query = ''): never
{
    go('lecturer/quizzes.php' . ($query !== '' ? '?' . $query : ''));
}


/** Parse a pasted plain-text multiple-choice quiz into structured question records. */
function parseBulkQuizText(string $raw): array
{
    $raw = str_replace(["\r\n", "\r"], "\n", trim($raw));
    if ($raw === '') return ['questions' => [], 'errors' => ['Paste your questions into the text area first.']];
    if (strlen($raw) > 2_000_000) return ['questions' => [], 'errors' => ['The pasted content is too large. Please import fewer questions at a time.']];

    // Question headers are required so question text and options can be separated reliably.
    $lines = explode("\n", $raw);
    $blocks = [];
    $current = [];
    foreach ($lines as $line) {
        if (preg_match('/^\s*(?:(?:Q(?:uestion)?\s*\d+)\s*[:.)-]|\d+\s*[.)])\s*(.*)$/i', $line, $m)) {
            if ($current) $blocks[] = $current;
            $current = [];
            if (trim($m[1]) !== '') $current[] = trim($m[1]);
        } else {
            if (trim($line) !== '' || $current) $current[] = rtrim($line);
        }
    }
    if ($current) $blocks[] = $current;
    if (!$blocks) return ['questions' => [], 'errors' => ['No question blocks found. Start each question with Q1:, Q2:, or 1. / 2.']];

    $questions = [];
    $errors = [];
    foreach ($blocks as $blockIndex => $blockLines) {
        $number = $blockIndex + 1;
        $item = [
            'question_text' => '', 'options' => ['A' => '', 'B' => '', 'C' => '', 'D' => ''],
            'correct' => '', 'topic' => 'General', 'difficulty' => '', 'explanation' => '',
            'option_explanations' => ['A' => '', 'B' => '', 'C' => '', 'D' => '']
        ];
        $active = 'question_text';
        $sawOption = false;
        foreach ($blockLines as $line) {
            $trim = trim($line);
            if ($trim === '') continue;
            if (preg_match('/^([A-D])\s*[).:\-]\s*(.*)$/i', $trim, $m)) {
                $letter = strtoupper($m[1]);
                $item['options'][$letter] = trim($m[2]);
                $active = 'option:' . $letter;
                $sawOption = true;
                continue;
            }
            if (preg_match('/^(?:correct(?:\s+answer)?|answer\s+key)\s*[:=\-]\s*([A-D])\b/i', $trim, $m) || preg_match('/^answer\s*[:=\-]\s*([A-D])\b/i', $trim, $m)) {
                $item['correct'] = strtoupper($m[1]); $active = 'correct'; continue;
            }
            if (preg_match('/^topic\s*[:=\-]\s*(.*)$/i', $trim, $m)) { $item['topic'] = trim($m[1]) ?: 'General'; $active = 'topic'; continue; }
            if (preg_match('/^difficulty\s*[:=\-]\s*(.*)$/i', $trim, $m)) { $item['difficulty'] = trim($m[1]); $active = 'difficulty'; continue; }
            if (preg_match('/^(?:explanation|reasoning|rationale)\s*[:=\-]\s*(.*)$/i', $trim, $m)) { $item['explanation'] = trim($m[1]); $active = 'explanation'; continue; }
            if (preg_match('/^(?:reason|why)\s*([A-D])\s*[:=\-]\s*(.*)$/i', $trim, $m) || preg_match('/^([A-D])\s+(?:reason|why)\s*[:=\-]\s*(.*)$/i', $trim, $m)) {
                $letter = strtoupper($m[1]); $item['option_explanations'][$letter] = trim($m[2]); $active = 'reason:' . $letter; continue;
            }
            // Continuation lines are appended to whichever field was most recently started.
            if (!$sawOption && $active === 'question_text') {
                $item['question_text'] .= ($item['question_text'] === '' ? '' : "\n") . $trim;
            } elseif (str_starts_with($active, 'option:')) {
                $letter = substr($active, 7); $item['options'][$letter] .= ($item['options'][$letter] === '' ? '' : "\n") . $trim;
            } elseif ($active === 'explanation') {
                $item['explanation'] .= ($item['explanation'] === '' ? '' : "\n") . $trim;
            } elseif (str_starts_with($active, 'reason:')) {
                $letter = substr($active, 7); $item['option_explanations'][$letter] .= ($item['option_explanations'][$letter] === '' ? '' : "\n") . $trim;
            } elseif ($active === 'topic') {
                $item['topic'] .= ' ' . $trim;
            } elseif ($active === 'difficulty') {
                $item['difficulty'] .= ' ' . $trim;
            } else {
                $errors[] = "Question {$number}: could not understand line: " . substr($trim, 0, 100);
            }
        }
        $item['question_text'] = trim($item['question_text']);
        $questionErrors = [];
        if ($item['question_text'] === '') $questionErrors[] = 'question text is missing';
        foreach (['A','B','C','D'] as $letter) if (trim($item['options'][$letter]) === '') $questionErrors[] = "option {$letter} is missing";
        if (!in_array($item['correct'], ['A','B','C','D'], true)) $questionErrors[] = 'correct answer must be A, B, C, or D';
        if (trim($item['topic']) === '') $item['topic'] = 'General';
        if ($questionErrors) $errors[] = 'Question ' . $number . ': ' . implode(', ', $questionErrors) . '.';
        $questions[] = $item;
    }
    return ['questions' => $questions, 'errors' => $errors];
}

/** Extract plain text from an uploaded .txt or .docx file. No AI API is required. */
function extractQuizUpload(array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return '';
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) throw new RuntimeException('The uploaded file could not be received. Try again.');
    if ((int)($file['size'] ?? 0) > 5 * 1024 * 1024) throw new RuntimeException('Please upload a file smaller than 5 MB.');
    $name = strtolower((string)($file['name'] ?? ''));
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) throw new RuntimeException('The uploaded file is invalid.');
    if (str_ends_with($name, '.txt')) {
        $text = file_get_contents($tmp);
        if ($text === false) throw new RuntimeException('Could not read the text file.');
        return mb_convert_encoding($text, 'UTF-8', 'UTF-8, Windows-1252, ISO-8859-1');
    }
    if (str_ends_with($name, '.docx')) {
        if (!class_exists(ZipArchive::class)) throw new RuntimeException('Word .docx import requires PHP ZipArchive to be enabled. Use copy-paste or a .txt file for now.');
        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) throw new RuntimeException('Could not open the Word document. Please save it as .docx and try again.');
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml === false) throw new RuntimeException('This Word document does not contain readable document text.');
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) throw new RuntimeException('Could not read the Word document text.');
        $out = [];
        foreach ($dom->getElementsByTagNameNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'p') as $paragraph) {
            $line = '';
            foreach ($paragraph->getElementsByTagNameNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 't') as $t) $line .= $t->textContent;
            if ($line !== '') $out[] = $line;
        }
        return implode("\n", $out);
    }
    throw new RuntimeException('Supported formats are .txt and .docx. You can also paste directly into the textbox.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    try {
        if (!in_array($action, ['create_quiz', 'update_quiz'], true)) {
            $guardQuizId = (int)($_POST['quiz_id'] ?? 0);
            lecturerQuizOwned($pdo, $guardQuizId, (int)$user['id'], $allowedModuleIds);
        }
        if ($action === 'create_quiz' || $action === 'update_quiz') {
            $title = trim((string)($_POST['title'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $moduleId = filter_var($_POST['module_id'] ?? null, FILTER_VALIDATE_INT);
            $newModuleCode = strtoupper(trim((string)($_POST['new_module_code'] ?? '')));
            $newModuleName = trim((string)($_POST['new_module_name'] ?? ''));
            $quizType = (string)($_POST['quiz_type'] ?? 'practice');
            $duration = filter_var($_POST['duration_minutes'] ?? null, FILTER_VALIDATE_INT);
            $points = filter_var($_POST['points_per_question'] ?? null, FILTER_VALIDATE_INT);
            $availabilityMode = (string)($_POST['availability_mode'] ?? 'unlimited');
            $rawAvailableFrom = trim((string)($_POST['available_from'] ?? ''));
            $rawAvailableUntil = trim((string)($_POST['available_until'] ?? ''));
            if (!in_array($availabilityMode, ['unlimited', 'scheduled', 'open_until'], true)) {
                throw new RuntimeException('Choose a valid quiz availability setting.');
            }
            // Unlimited is the default: dates are ignored and stored as NULL.
            if ($availabilityMode === 'unlimited') {
                $availableFrom = null;
                $availableUntil = null;
            } elseif ($availabilityMode === 'scheduled') {
                if ($rawAvailableFrom === '' || $rawAvailableUntil === '') {
                    throw new RuntimeException('Scheduled availability requires both an opening and closing date.');
                }
                $availableFrom = str_replace('T', ' ', $rawAvailableFrom) . (strlen($rawAvailableFrom) === 16 ? ':00' : '');
                $availableUntil = str_replace('T', ' ', $rawAvailableUntil) . (strlen($rawAvailableUntil) === 16 ? ':00' : '');
            } else { // open_until: available immediately until the optional closing date
                $availableFrom = null;
                $availableUntil = $rawAvailableUntil !== '' ? str_replace('T', ' ', $rawAvailableUntil) . (strlen($rawAvailableUntil) === 16 ? ':00' : '') : null;
            }
            if ($availableFrom && $availableUntil && strtotime($availableUntil) <= strtotime($availableFrom)) throw new RuntimeException('The closing date must be later than the opening date.');
            if ($availableUntil && strtotime($availableUntil) <= time()) throw new RuntimeException('The closing date must be in the future.');

            if ($title === '' || strlen($title) > 200) {
                throw new RuntimeException('Enter a quiz title of no more than 200 characters.');
            }
            if ((!$moduleId || $moduleId < 1) && ($newModuleCode === '' || $newModuleName === '')) {
                throw new RuntimeException('Choose one of your assigned modules.');
            }
            if ($newModuleCode !== '' && (strlen($newModuleCode) > 30 || !preg_match('/^[A-Z0-9._-]+$/', $newModuleCode))) {
                throw new RuntimeException('Module code must be 1–30 characters and use only letters, numbers, dots, underscores or hyphens.');
            }
            if ($newModuleName !== '' && strlen($newModuleName) > 200) {
                throw new RuntimeException('Module name must be no longer than 200 characters.');
            }
            if (!in_array($quizType, ['module', 'practice', 'timed'], true)) {
                throw new RuntimeException('Choose a valid quiz type.');
            }
            if (!$duration || $duration < 1 || $duration > 600) {
                throw new RuntimeException('Duration must be between 1 and 600 minutes.');
            }
            if (!$points || $points < 1 || $points > 100) {
                throw new RuntimeException('Points per question must be between 1 and 100.');
            }
            if (strlen($description) > 5000) {
                throw new RuntimeException('Description must be no longer than 5,000 characters.');
            }

            if (!$moduleId || !in_array((int)$moduleId, $allowedModuleIds, true)) {
                throw new RuntimeException('Choose a module currently assigned to you.');
            }
            if ($action === 'update_quiz') {
                $quizId = (int)($_POST['quiz_id'] ?? 0);
                lecturerQuizOwned($pdo, $quizId, (int)$user['id'], $allowedModuleIds);
            }

            if ($action === 'create_quiz') {
                $stmt = $pdo->prepare(
                    'INSERT INTO quizzes (module_id, created_by, title, description, quiz_type, duration_minutes, points_per_question, available_from, available_until, is_published) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0)'
                );
                $stmt->execute([$moduleId, (int)$user['id'], $title, $description, $quizType, $duration, $points, $availableFrom, $availableUntil]);
                $newId = (int)$pdo->lastInsertId();
                $_SESSION['quiz_notice'] = 'Quiz created as a draft. Add questions before publishing.';
                quizRedirect('quiz=' . $newId);
            }

            $quizId = filter_var($_POST['quiz_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$quizId || $quizId < 1) {
                throw new RuntimeException('Invalid quiz selected.');
            }
            $pdo->beginTransaction();
            $previousStmt = $pdo->prepare('SELECT module_id, title, description, quiz_type, duration_minutes, points_per_question, available_from, available_until, is_published FROM quizzes WHERE id=? AND created_by=? FOR UPDATE');
            $previousStmt->execute([$quizId, (int)$user['id']]);
            $previous = $previousStmt->fetch(PDO::FETCH_ASSOC);
            if (!$previous) throw new RuntimeException('Quiz not found.');
            $stmt = $pdo->prepare('UPDATE quizzes SET module_id = ?, title = ?, description = ?, quiz_type = ?, duration_minutes = ?, points_per_question = ?, available_from = ?, available_until = ? WHERE id = ? AND created_by = ?');
            $stmt->execute([$moduleId, $title, $description, $quizType, $duration, $points, $availableFrom, $availableUntil, $quizId, (int)$user['id']]);
            $changed = (int)$previous['module_id'] !== (int)$moduleId || (string)$previous['title'] !== $title
                || (string)($previous['description'] ?? '') !== $description || (string)$previous['quiz_type'] !== $quizType
                || (int)$previous['duration_minutes'] !== (int)$duration || (int)$previous['points_per_question'] !== (int)$points
                || ($previous['available_from'] ?? null) !== $availableFrom || ($previous['available_until'] ?? null) !== $availableUntil;
            if ((int)$previous['is_published'] === 1 && $changed) {
                lecturerQuizNotifyStudents($pdo, (int)$moduleId, (int)$user['id'], $quizId, $title, true);
            }
            $pdo->commit();
            $_SESSION['quiz_notice'] = 'Quiz details updated.';
            quizRedirect('quiz=' . $quizId);
        }

        if ($action === 'preview_bulk') {
            $quizId = filter_var($_POST['quiz_id'] ?? null, FILTER_VALIDATE_INT);
            $bulkText = trim((string)($_POST['bulk_text'] ?? ''));
            $uploadedText = extractQuizUpload($_FILES['source_file'] ?? ['error' => UPLOAD_ERR_NO_FILE]);
            if ($uploadedText !== '') $bulkText = $uploadedText;
            if (!$quizId || $quizId < 1) throw new RuntimeException('Select a valid quiz before importing questions.');
            // Confirm that this lecturer owns the selected quiz and is still assigned to its module.
            $quizRow = lecturerQuizOwned($pdo, (int)$quizId, (int)$user['id'], $allowedModuleIds);
            if ((int)$quizRow['is_published'] === 1) {
                throw new RuntimeException('Move the quiz back to draft before importing questions.');
            }
            $parsed = parseBulkQuizText($bulkText);
            $bulkPreview = $parsed['questions'];
            $bulkErrors = $parsed['errors'];
            $selectedQuizId = (int)$quizId;
        }

        if ($action === 'import_bulk') {

    $quizId = filter_var($_POST['quiz_id'] ?? null, FILTER_VALIDATE_INT);
    $bulkText = (string)($_POST['bulk_text'] ?? '');

    if (!$quizId || $quizId < 1) {
        throw new RuntimeException('Select a valid quiz.');
    }

    // Verify lecturer owns this quiz
    lecturerQuizOwned($pdo, $quizId, (int)$user['id'], $allowedModuleIds);

    $check = $pdo->prepare(
        'SELECT is_published FROM quizzes WHERE id = ?'
    );
    $check->execute([$quizId]);

    $quizState = $check->fetchColumn();

    if ($quizState === false) {
        throw new RuntimeException('Quiz not found.');
    }

    if ((int)$quizState === 1) {
        throw new RuntimeException('Unpublish the quiz before importing questions.');
    }

    $parsed = parseBulkQuizText($bulkText);

    if (!empty($parsed['errors'])) {
        throw new RuntimeException(
            'Import stopped: ' . implode(' ', array_slice($parsed['errors'], 0, 5))
        );
    }

    if (empty($parsed['questions'])) {
        throw new RuntimeException('No valid questions found.');
    }

    $pdo->beginTransaction();

    try {

        $questionStmt = $pdo->prepare(
            'INSERT INTO quiz_questions
            (quiz_id, question_text, explanation, topic, difficulty)
            VALUES (?, ?, ?, ?, ?)'
        );

        $optionStmt = $pdo->prepare(
            'INSERT INTO quiz_options
            (question_id, option_text, is_correct, explanation)
            VALUES (?, ?, ?, ?)'
        );

        foreach ($parsed['questions'] as $question) {

            $questionStmt->execute([
                $quizId,
                $question['question_text'],
                $question['explanation'],
                $question['topic'],
                $question['difficulty'] !== ''
                    ? $question['difficulty']
                    : null
            ]);

            $questionId = (int)$pdo->lastInsertId();

            foreach (['A', 'B', 'C', 'D'] as $letter) {

                $optionStmt->execute([
                    $questionId,
                    $question['options'][$letter],
                    $question['correct'] === $letter ? 1 : 0,
                    $question['option_explanations'][$letter]
                ]);
            }
        }

        $pdo->commit();

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }

    $_SESSION['quiz_notice'] =
        count($parsed['questions']) . ' questions imported successfully.';

    quizRedirect('quiz=' . $quizId);
}

        if ($action === 'add_question') {
            $quizId = filter_var($_POST['quiz_id'] ?? null, FILTER_VALIDATE_INT);
            $questionText = trim((string)($_POST['question_text'] ?? ''));
            $explanation = trim((string)($_POST['explanation'] ?? ''));
            $topic = trim((string)($_POST['topic'] ?? ''));
            $difficulty = trim((string)($_POST['difficulty'] ?? ''));
            $optionReasons = [trim((string)($_POST['reason_a'] ?? '')), trim((string)($_POST['reason_b'] ?? '')), trim((string)($_POST['reason_c'] ?? '')), trim((string)($_POST['reason_d'] ?? ''))];
            $options = [
                trim((string)($_POST['option_a'] ?? '')),
                trim((string)($_POST['option_b'] ?? '')),
                trim((string)($_POST['option_c'] ?? '')),
                trim((string)($_POST['option_d'] ?? '')),
            ];
            $correct = filter_var($_POST['correct_option'] ?? null, FILTER_VALIDATE_INT);

            if (!$quizId || $quizId < 1) throw new RuntimeException('Select a valid quiz.');
            if ($questionText === '' || strlen($questionText) > 10000) throw new RuntimeException('Enter a question (maximum 10,000 characters).');
            if ($topic === '' || strlen($topic) > 150) throw new RuntimeException('Enter a topic, such as Sorting or Searching.');
            if (strlen($explanation) > 10000) throw new RuntimeException('Explanation is too long.');
            foreach ($options as $option) {
                if ($option === '' || strlen($option) > 2000) throw new RuntimeException('Fill in all four options (maximum 2,000 characters each).');
            }
            if (!in_array($correct, [0, 1, 2, 3], true)) throw new RuntimeException('Choose the correct answer.');

            $quizCheck = $pdo->prepare('SELECT id, is_published FROM quizzes WHERE id = ?');
            $quizCheck->execute([$quizId]);
            $quizRow = $quizCheck->fetch();
            if (!$quizRow) throw new RuntimeException('Quiz not found.');
            if ((int)$quizRow['is_published'] === 1) throw new RuntimeException('Unpublish the quiz before changing its questions.');

            $pdo->beginTransaction();
            $stmt = $pdo->prepare('INSERT INTO quiz_questions (quiz_id, question_text, explanation, topic, difficulty) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$quizId, $questionText, $explanation, $topic, $difficulty !== '' ? $difficulty : null]);
            $questionId = (int)$pdo->lastInsertId();
            $optionStmt = $pdo->prepare('INSERT INTO quiz_options (question_id, option_text, is_correct, explanation) VALUES (?, ?, ?, ?)');
            foreach ($options as $index => $option) {
                $optionStmt->execute([$questionId, $option, $index === $correct ? 1 : 0, $optionReasons[$index]]);
            }
            $pdo->commit();
            $_SESSION['quiz_notice'] = 'Question and answer options added successfully.';
            quizRedirect('quiz=' . $quizId);
        }

        if ($action === 'update_question') {
            $quizId = filter_var($_POST['quiz_id'] ?? null, FILTER_VALIDATE_INT);
            $questionId = filter_var($_POST['question_id'] ?? null, FILTER_VALIDATE_INT);
            $questionText = trim((string)($_POST['question_text'] ?? ''));
            $topic = trim((string)($_POST['topic'] ?? 'General'));
            $difficulty = trim((string)($_POST['difficulty'] ?? ''));
            $explanation = trim((string)($_POST['explanation'] ?? ''));
            $optionTexts = [];
            $optionReasons = [];
            foreach (['A','B','C','D'] as $letter) {
                $optionTexts[$letter] = trim((string)($_POST['option_' . strtolower($letter)] ?? ''));
                $optionReasons[$letter] = trim((string)($_POST['reason_' . strtolower($letter)] ?? ''));
            }
            $correct = strtoupper(trim((string)($_POST['correct_option'] ?? '')));
            if (!$quizId || !$questionId) throw new RuntimeException('Invalid question selected.');
            if ($questionText === '' || $topic === '') throw new RuntimeException('Question text and topic are required.');
            foreach ($optionTexts as $value) if ($value === '') throw new RuntimeException('All four answer options are required.');
            if (!in_array($correct, ['A','B','C','D'], true)) throw new RuntimeException('Select the correct answer.');
            $qcheck = $pdo->prepare('SELECT q.id FROM quiz_questions q JOIN quizzes z ON z.id=q.quiz_id WHERE q.id=? AND q.quiz_id=? AND z.is_published=0');
            $qcheck->execute([$questionId, $quizId]);
            if (!$qcheck->fetchColumn()) throw new RuntimeException('Question not found, or unpublish the quiz before editing it.');
            $pdo->beginTransaction();
            $uq = $pdo->prepare('UPDATE quiz_questions SET question_text=?, topic=?, difficulty=?, explanation=? WHERE id=? AND quiz_id=?');
            $uq->execute([$questionText, $topic, $difficulty !== '' ? $difficulty : null, $explanation, $questionId, $quizId]);
            $existingOptions = $pdo->prepare('SELECT id FROM quiz_options WHERE question_id=? ORDER BY id ASC');
            $existingOptions->execute([$questionId]);
            $optionIds = $existingOptions->fetchAll(PDO::FETCH_COLUMN);
            $updateOption = $pdo->prepare('UPDATE quiz_options SET option_text=?, is_correct=?, explanation=? WHERE id=? AND question_id=?');
            $insertOption = $pdo->prepare('INSERT INTO quiz_options (question_id, option_text, is_correct, explanation) VALUES (?, ?, ?, ?)');
            foreach (['A','B','C','D'] as $idx => $letter) {
                if (isset($optionIds[$idx])) $updateOption->execute([$optionTexts[$letter], $correct === $letter ? 1 : 0, $optionReasons[$letter], (int)$optionIds[$idx], $questionId]);
                else $insertOption->execute([$questionId, $optionTexts[$letter], $correct === $letter ? 1 : 0, $optionReasons[$letter]]);
            }
            if (count($optionIds) > 4) {
                $extra = array_slice($optionIds, 4);
                $del = $pdo->prepare('DELETE FROM quiz_options WHERE id=? AND question_id=?');
                foreach ($extra as $oid) $del->execute([(int)$oid, $questionId]);
            }
            $pdo->commit();
            $_SESSION['quiz_notice'] = 'Question updated successfully.';
            quizRedirect('quiz=' . $quizId);
        }

        if ($action === 'toggle_publish') {
            $quizId = filter_var($_POST['quiz_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$quizId || $quizId < 1) throw new RuntimeException('Invalid quiz selected.');
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT module_id, title, is_published FROM quizzes WHERE id = ? AND created_by = ? FOR UPDATE');
            $stmt->execute([$quizId, (int)$user['id']]);
            $quizToPublish = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$quizToPublish) throw new RuntimeException('Quiz not found.');
            $currentlyPublished = (int)$quizToPublish['is_published'] === 1;
            if (!$currentlyPublished) {
                $countStmt = $pdo->prepare('SELECT COUNT(*) FROM quiz_questions WHERE quiz_id = ?');
                $countStmt->execute([$quizId]);
                if ((int)$countStmt->fetchColumn() < 1) throw new RuntimeException('Add at least one question before publishing this quiz.');
            }
            $stmt = $pdo->prepare('UPDATE quizzes SET is_published = ? WHERE id = ? AND created_by = ?');
            $stmt->execute([$currentlyPublished ? 0 : 1, $quizId, (int)$user['id']]);
            if (!$currentlyPublished) {
                lecturerQuizNotifyStudents($pdo, (int)$quizToPublish['module_id'], (int)$user['id'], $quizId, (string)$quizToPublish['title']);
            }
            $pdo->commit();
            $_SESSION['quiz_notice'] = $currentlyPublished ? 'Quiz moved back to draft.' : 'Quiz published successfully.';
            quizRedirect('quiz=' . $quizId);
        }

        if ($action === 'delete_question') {
            $questionId = filter_var($_POST['question_id'] ?? null, FILTER_VALIDATE_INT);
            $quizId = filter_var($_POST['quiz_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$questionId || !$quizId) throw new RuntimeException('Invalid question selected.');
            $publishedCheck = $pdo->prepare('SELECT is_published FROM quizzes WHERE id = ?');
            $publishedCheck->execute([$quizId]);
            if ((int)$publishedCheck->fetchColumn() === 1) throw new RuntimeException('Unpublish the quiz before removing questions.');
            $questionCheck = $pdo->prepare('SELECT id FROM quiz_questions WHERE id = ? AND quiz_id = ?');
            $questionCheck->execute([$questionId, $quizId]);
            if (!$questionCheck->fetchColumn()) throw new RuntimeException('Question does not belong to this quiz.');
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM quiz_options WHERE question_id = ?')->execute([$questionId]);
            $pdo->prepare('DELETE FROM quiz_questions WHERE id = ? AND quiz_id = ?')->execute([$questionId, $quizId]);
            $pdo->commit();
            $_SESSION['quiz_notice'] = 'Question deleted.';
            quizRedirect('quiz=' . $quizId);
        }

        if ($action === 'delete_quiz') {
            $quizId = filter_var($_POST['quiz_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$quizId || $quizId < 1) throw new RuntimeException('Invalid quiz selected.');
            // Preserve student attempt history; prevent deletion if any attempts exist.
            $attemptCheck = $pdo->prepare('SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id = ?');
            $attemptCheck->execute([$quizId]);
            if ((int)$attemptCheck->fetchColumn() > 0) throw new RuntimeException('This quiz has student attempts and cannot be deleted. Unpublish it instead.');
            $pdo->beginTransaction();
            $questionIdsStmt = $pdo->prepare('SELECT id FROM quiz_questions WHERE quiz_id = ?');
            $questionIdsStmt->execute([$quizId]);
            $questionIds = $questionIdsStmt->fetchAll(PDO::FETCH_COLUMN);
            $deleteOptions = $pdo->prepare('DELETE FROM quiz_options WHERE question_id = ?');
            foreach ($questionIds as $questionId) $deleteOptions->execute([(int)$questionId]);
            $pdo->prepare('DELETE FROM quiz_questions WHERE quiz_id = ?')->execute([$quizId]);
            $pdo->prepare('DELETE FROM quizzes WHERE id = ?')->execute([$quizId]);
            $pdo->commit();
            $_SESSION['quiz_notice'] = 'Quiz and its questions deleted.';
            quizRedirect();
        }

        // preview_bulk is a display action: keep the parsed preview on this page.
        // Every other unhandled action should still be rejected.
        if ($action !== 'preview_bulk') {
            throw new RuntimeException('Unknown action. Refresh the page and try again.');
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('ULearn quiz management error: ' . $exception->getMessage());
        $error = $exception instanceof RuntimeException
            ? $exception->getMessage()
            : 'Could not complete that action. Please check the database structure and try again.';
    }
}

try {
    $modules = $assignedModules;
    $quizStmt = $pdo->prepare(
        'SELECT q.id, q.module_id, q.title, q.description, q.quiz_type, q.duration_minutes, q.points_per_question, q.available_from, q.available_until, q.is_published, q.created_at, m.module_code, m.module_name, (SELECT COUNT(*) FROM quiz_questions qq WHERE qq.quiz_id = q.id) AS question_count FROM quizzes q LEFT JOIN academic_modules m ON m.id = q.module_id WHERE q.created_by = ? ORDER BY q.created_at DESC, q.id DESC'
    );
    $quizStmt->execute([(int)$user['id']]);
    $quizzes = array_values(array_filter($quizStmt->fetchAll(), static fn($q) => in_array((int)$q['module_id'], $allowedModuleIds, true)));

    if ($selectedQuizId > 0) {
        lecturerQuizOwned($pdo, $selectedQuizId, (int)$user['id'], $allowedModuleIds);
        $editStmt = $pdo->prepare('SELECT * FROM quizzes WHERE id = ?');
        $editStmt->execute([$selectedQuizId]);
        $editingQuiz = $editStmt->fetch() ?: null;
        if (!$editingQuiz) {
            $selectedQuizId = 0;
            $error = $error ?: 'That quiz could not be found.';
        }
    }

    $questions = [];
    if ($selectedQuizId > 0) {
        $questionStmt = $pdo->prepare('SELECT id, question_text, explanation, topic, difficulty, created_at FROM quiz_questions WHERE quiz_id = ? ORDER BY id ASC');
        $questionStmt->execute([$selectedQuizId]);
        $questions = $questionStmt->fetchAll();
        if ($questions) {
            $optionStmt = $pdo->prepare('SELECT id, question_id, option_text, is_correct, explanation FROM quiz_options WHERE question_id = ? ORDER BY id ASC');
            foreach ($questions as &$question) {
                $optionStmt->execute([(int)$question['id']]);
                $question['options'] = $optionStmt->fetchAll();
            }
            unset($question);
        }
        if ($editQuestionId > 0) {
            foreach ($questions as $qrow) {
                if ((int)$qrow['id'] === $editQuestionId) { $editingQuestion = $qrow; break; }
            }
        }
    }
} catch (Throwable $exception) {
    error_log('ULearn quiz page load error: ' . $exception->getMessage());
    $modules = $modules ?? [];
    $quizzes = $quizzes ?? [];
    $questions = $questions ?? [];
    $error = $error ?: ($exception instanceof RuntimeException ? $exception->getMessage() : 'Could not load quiz data. Check the database structure.');
}

$fullName = (string)($user['full_name'] ?? 'Administrator');
$theme = (string)($user['theme'] ?? 'system');
$publishedCount = count(array_filter($quizzes, static fn($q) => (int)$q['is_published'] === 1));
$totalQuestions = array_sum(array_map(static fn($q) => (int)$q['question_count'], $quizzes));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Lecturer Quiz Management — U Learn</title>
<script>
(function(){const p=<?= json_encode($theme, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;const s=localStorage.getItem('ulearn-theme');const t=(s==='light'||s==='dark')?s:((p==='light'||p==='dark')?p:(window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'));document.documentElement.dataset.theme=t;})();
function toggleTheme(){const t=document.documentElement.dataset.theme==='dark'?'light':'dark';document.documentElement.dataset.theme=t;localStorage.setItem('ulearn-theme',t);const b=document.getElementById('themeToggle');if(b)b.textContent=t==='dark'?'☀':'☾';}
document.addEventListener('DOMContentLoaded',()=>{const b=document.getElementById('themeToggle');if(b)b.textContent=document.documentElement.dataset.theme==='dark'?'☀':'☾';});
</script>
<style>
:root{color-scheme:light;--bg:#f7f8fc;--surface:#fff;--surface-alt:#f1f2f8;--text:#171927;--muted:#777b90;--border:#e7e8f0;--accent:#6756e8;--accent-light:#f0edff;--shadow:0 10px 40px rgba(30,30,60,.04);--green:#18794e;--green-bg:#e9f8f0;--red:#b4233c;--red-bg:#fff0f2}
:root[data-theme="dark"]{color-scheme:dark;--bg:#0c0e16;--surface:#161924;--surface-alt:#202431;--text:#f4f4fa;--muted:#a0a4b6;--border:#2b2e3c;--accent:#a99aff;--accent-light:#292440;--shadow:none;--green:#8ee0b8;--green-bg:#173127;--red:#ffb1bf;--red-bg:#351e28}
*{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif;line-height:1.6}a{color:inherit;text-decoration:none}button,input,select,textarea{font:inherit}button{cursor:pointer}.layout{display:grid;grid-template-columns:250px minmax(0,1fr);min-height:100vh}.sidebar{background:var(--surface);border-right:1px solid var(--border);padding:28px 15px;display:flex;flex-direction:column;gap:30px}.brand{font-size:27px;font-weight:850;letter-spacing:-1.7px;padding:0 14px}.brand span,.accent-dot{color:var(--accent)}.nav-label{padding:0 14px;color:var(--muted);font-size:10px;letter-spacing:1.7px;font-weight:850;text-transform:uppercase}.nav{display:grid;gap:7px}.nav a{display:flex;align-items:center;gap:12px;padding:12px 14px;border-radius:13px;color:var(--muted);font-size:13px;font-weight:700;transition:.2s}.nav a:hover,.nav a.active{background:var(--accent-light);color:var(--accent)}.nav .ico{width:19px;text-align:center;font-size:16px}.sidebar-foot{margin-top:auto;padding:16px 14px;border:1px solid var(--border);border-radius:15px;color:var(--muted);font-size:11px}.main{min-width:0;padding:28px clamp(18px,4vw,54px) 60px}.topbar{display:flex;justify-content:space-between;align-items:center;gap:15px;margin-bottom:52px}.crumb{font-size:11px;letter-spacing:1.6px;text-transform:uppercase;color:var(--muted);font-weight:850}.top-actions{display:flex;align-items:center;gap:12px}.role{font-size:10px;color:var(--muted);font-weight:850;letter-spacing:1.2px;text-transform:uppercase}.theme-btn{width:42px;height:42px;border:1px solid var(--border);border-radius:12px;background:var(--surface);color:var(--text);font-size:18px}.logout{font-size:12px;color:var(--muted);font-weight:750}.intro{margin-bottom:30px;animation:rise .4s ease both}.eyebrow{font-size:11px;letter-spacing:1.7px;text-transform:uppercase;color:var(--muted);font-weight:850}h1{font-size:clamp(38px,5vw,62px);line-height:1.08;letter-spacing:-.06em;margin:14px 0;font-weight:820}h1 span{color:var(--accent)}.subtitle{color:var(--muted);font-size:16px;max-width:700px;margin:0;line-height:1.8}.stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin:28px 0}.stat{background:var(--surface);border:1px solid var(--border);border-radius:19px;padding:20px 22px;box-shadow:var(--shadow)}.stat-label{color:var(--muted);font-size:12px;font-weight:700}.stat-number{font-size:30px;letter-spacing:-1px;font-weight:820;margin-top:8px}.stat-foot{color:var(--muted);font-size:11px;margin-top:3px}.columns{display:grid;grid-template-columns:minmax(300px,.9fr) minmax(0,1.35fr);gap:20px;align-items:start}.card{background:var(--surface);border:1px solid var(--border);border-radius:22px;padding:25px;box-shadow:var(--shadow);min-width:0;animation:rise .45s ease both}.card-head{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;margin-bottom:20px}.kicker{color:var(--accent);font-size:10px;letter-spacing:1.5px;font-weight:850;text-transform:uppercase}.card h2{font-size:20px;letter-spacing:-.6px;margin:4px 0 7px}.hint{font-size:12px;color:var(--muted);margin:0;line-height:1.7}.field{margin-top:16px}label{display:block;font-size:12px;font-weight:750;margin-bottom:7px}input,select,textarea{width:100%;background:var(--surface-alt);color:var(--text);border:1px solid var(--border);border-radius:12px;padding:12px 13px;outline:none;transition:border-color .2s,box-shadow .2s}input,select{min-height:46px}textarea{min-height:82px;resize:vertical}input:focus,select:focus,textarea:focus{border-color:var(--accent);box-shadow:0 0 0 3px color-mix(in srgb,var(--accent) 18%,transparent)}.two{display:grid;grid-template-columns:1fr 1fr;gap:12px}.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:11px 15px;border-radius:12px;border:1px solid var(--border);background:var(--surface-alt);color:var(--text);font-size:12px;font-weight:800;transition:transform .18s,border-color .18s}.btn:hover{transform:translateY(-1px);border-color:var(--accent)}.btn-primary{background:linear-gradient(110deg,#5d4ae0,#8571fa);border-color:transparent;color:#fff;box-shadow:0 9px 22px rgba(103,86,232,.2)}.btn-small{padding:8px 10px;font-size:11px}.btn-danger{color:var(--red)}.full{width:100%;margin-top:20px}.message{padding:13px 15px;border-radius:12px;margin-bottom:18px;font-size:13px}.success{background:var(--green-bg);color:var(--green)}.error{background:var(--red-bg);color:var(--red)}.badge{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;background:var(--accent-light);color:var(--accent);font-size:10px;font-weight:850;white-space:nowrap}.badge.published{background:var(--green-bg);color:var(--green)}.badge.draft{background:var(--surface-alt);color:var(--muted)}.quiz-list{display:grid;gap:12px}.quiz-item{padding:16px;border:1px solid var(--border);border-radius:16px;background:var(--surface);transition:border-color .2s,transform .2s}.quiz-item:hover{border-color:color-mix(in srgb,var(--accent) 55%,var(--border));transform:translateY(-1px)}.quiz-title{font-size:15px;font-weight:800;letter-spacing:-.3px}.quiz-meta{font-size:11px;color:var(--muted);margin-top:7px;display:flex;gap:8px;flex-wrap:wrap}.quiz-actions{display:flex;gap:7px;flex-wrap:wrap;margin-top:13px}.empty{border:1px dashed var(--border);border-radius:16px;padding:30px 18px;text-align:center;color:var(--muted);font-size:12px}.empty strong{display:block;color:var(--text);font-size:14px;margin-bottom:6px}.section-gap{margin-top:20px}.question-item{border:1px solid var(--border);border-radius:15px;padding:16px;margin-top:12px}.question-text{font-size:13px;font-weight:750;white-space:pre-wrap;overflow-wrap:anywhere}.options{display:grid;gap:7px;margin-top:12px}.option{font-size:11px;color:var(--muted);padding:8px 10px;border-radius:9px;background:var(--surface-alt);overflow-wrap:anywhere}.option.correct{color:var(--green);background:var(--green-bg)}.topline{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}.separator{height:1px;background:var(--border);margin:20px 0}.back-link{display:inline-flex;color:var(--accent);font-size:12px;font-weight:800;margin-bottom:14px}.fine{font-size:11px;color:var(--muted);margin-top:9px}@keyframes rise{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}@media(max-width:1100px){.layout{grid-template-columns:210px minmax(0,1fr)}.columns{grid-template-columns:1fr}.sidebar{padding-inline:10px}}@media(max-width:720px){.layout{grid-template-columns:1fr}.sidebar{padding:14px;gap:14px;border-right:0;border-bottom:1px solid var(--border)}.sidebar .nav{display:flex;overflow:auto}.sidebar .nav a{white-space:nowrap}.sidebar-foot,.nav-label{display:none}.main{padding:20px 15px 42px}.topbar{margin-bottom:34px}.stats{grid-template-columns:1fr 1fr}.stat:last-child{grid-column:1/-1}.card{padding:19px}.two{grid-template-columns:1fr}.top-actions{gap:8px}}@media(prefers-reduced-motion:reduce){*,*::before,*::after{animation-duration:.01ms!important;transition-duration:.01ms!important;scroll-behavior:auto!important}}
</style>
</head>
<body>
<div class="layout">
<aside class="sidebar">
    <a class="brand" href="<?= e(url('lecturer/index.php')) ?>">u learn<span>.</span></a>
    <div class="nav-label">Your workspace</div>
    <nav class="nav" aria-label="Lecturer navigation">
        <a href="<?= e(url('lecturer/index.php')) ?>"><span class="ico">▦</span> Overview</a>
        <a href="<?= e(url('lecturer/index.php#modules')) ?>"><span class="ico">▤</span> My modules</a>
        <a href="<?= e(url('lecturer/materials.php')) ?>"><span class="ico">♧</span> Study materials</a>
        <a class="active" href="<?= e(url('lecturer/quizzes.php')) ?>"><span class="ico">▣</span> Quiz management</a>
    </nav>
    <div class="sidebar-foot">ULearn lecturer workspace<br>Manage your assigned modules with care.</div>
</aside>
<main class="main">
<header class="topbar">
    <div class="crumb">U Learn / Lecturer space</div>
    <div class="top-actions"><span class="role"><?= e(str_replace('_', ' ', (string)$user['role'])) ?></span><button class="theme-btn" id="themeToggle" type="button" onclick="toggleTheme()" aria-label="Toggle theme">☾</button><a class="logout" href="<?= e(url('auth/logout.php')) ?>">Log out ↗</a></div>
</header>
<section class="intro">
    <div class="eyebrow">Assessment workspace</div>
    <h1>Quiz management<span>.</span></h1>
    <p class="subtitle">Create quizzes for your assigned modules, write multiple-choice questions, review answer keys, and publish only when your quiz is ready.</p>
</section>
<?php if ($notice !== ''): ?><div class="message success" role="status"><?= e($notice) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="message error" role="alert"><?= e($error) ?></div><?php endif; ?>
<div class="stats">
    <div class="stat"><div class="stat-label">Total quizzes</div><div class="stat-number"><?= count($quizzes) ?></div><div class="stat-foot">Drafts and published quizzes</div></div>
    <div class="stat"><div class="stat-label">Published</div><div class="stat-number"><?= $publishedCount ?></div><div class="stat-foot">Available for student delivery</div></div>
    <div class="stat"><div class="stat-label">Questions created</div><div class="stat-number"><?= $totalQuestions ?></div><div class="stat-foot">Across all quizzes</div></div>
</div>
<div class="columns">
<section class="card">
    <div class="card-head"><div><div class="kicker"><?= $editingQuiz ? 'Quiz settings' : 'Build an assessment' ?></div><h2><?= $editingQuiz ? 'Edit quiz details' : 'Create a quiz' ?></h2><p class="hint">Start with the module, format and timing. New quizzes remain drafts until published.</p></div></div>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
        <input type="hidden" name="action" value="<?= $editingQuiz ? 'update_quiz' : 'create_quiz' ?>">
        <?php if ($editingQuiz): ?><input type="hidden" name="quiz_id" value="<?= (int)$selectedQuizId ?>"><?php endif; ?>
        <div class="field"><label for="title">Quiz title</label><input id="title" name="title" maxlength="200" required placeholder="e.g. Sorting Algorithms — Practice 01" value="<?= e((string)($editingQuiz['title'] ?? '')) ?>"></div>
        <div class="field">
            <label for="module_id">Assigned module</label>
            <select id="module_id" name="module_id">
                <option value="">Choose an assigned module</option>
                <?php foreach ($modules as $module): ?>
                <option value="<?= (int)$module['id'] ?>" <?= (int)($editingQuiz['module_id'] ?? 0) === (int)$module['id'] ? 'selected' : '' ?>><?= e((string)$module['module_code']) ?> — <?= e((string)$module['module_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="fine">Only modules actively assigned to you are available.</p>
        </div>
        <div class="field"><label for="description">Description <span style="color:var(--muted);font-weight:500">(optional)</span></label><textarea id="description" name="description" maxlength="5000" placeholder="What should students practise or learn?"><?= e((string)($editingQuiz['description'] ?? '')) ?></textarea></div>
        <div class="two">
            <div class="field"><label for="quiz_type">Quiz category</label>
            
            <select id="quiz_type" name="quiz_type">

    <option value="module" <?= ($editingQuiz['quiz_type'] ?? '') === 'module' ? 'selected' : '' ?>>
        Topic practice
    </option>

    <option value="practice" <?= ($editingQuiz['quiz_type'] ?? 'practice') === 'practice' ? 'selected' : '' ?>>
        General practice
    </option>

    <option value="timed" <?= ($editingQuiz['quiz_type'] ?? '') === 'timed' ? 'selected' : '' ?>>
        Exam preparation
    </option>

</select>
        </div>
            <div class="field"><label for="duration_minutes">Duration (minutes)</label><input id="duration_minutes" name="duration_minutes" type="number" min="1" max="600" required value="<?= e((string)($editingQuiz['duration_minutes'] ?? 20)) ?>"></div>
        </div>
        <div class="field"><label for="points_per_question">Points per question</label><input id="points_per_question" name="points_per_question" type="number" min="1" max="100" required value="<?= e((string)($editingQuiz['points_per_question'] ?? 1)) ?>"></div>
        <?php
            $savedFrom = !empty($editingQuiz['available_from']) ? date('Y-m-d\TH:i', strtotime((string)$editingQuiz['available_from'])) : '';
            $savedUntil = !empty($editingQuiz['available_until']) ? date('Y-m-d\TH:i', strtotime((string)$editingQuiz['available_until'])) : '';
            $savedAvailabilityMode = ($savedFrom !== '' && $savedUntil !== '') ? 'scheduled' : (($savedFrom === '' && $savedUntil !== '') ? 'open_until' : 'unlimited');
        ?>
        <div class="field">
            <label for="availability_mode">Quiz availability <span style="color:var(--muted);font-weight:500">(optional)</span></label>
            <select id="availability_mode" name="availability_mode" onchange="updateAvailabilityFields()">
                <option value="unlimited" <?= $savedAvailabilityMode === 'unlimited' ? 'selected' : '' ?>>Unlimited — stays available until I close it</option>
                <option value="scheduled" <?= $savedAvailabilityMode === 'scheduled' ? 'selected' : '' ?>>Scheduled — choose opening and closing dates</option>
                <option value="open_until" <?= $savedAvailabilityMode === 'open_until' ? 'selected' : '' ?>>Open immediately — optional closing date</option>
            </select>
        </div>
        <div class="two" id="availability_dates" style="display:none">
            <div class="field" id="available_from_wrap"><label for="available_from">Opens</label><input id="available_from" name="available_from" type="datetime-local" value="<?= e($savedFrom) ?>"></div>
            <div class="field" id="available_until_wrap"><label for="available_until">Closes <span style="color:var(--muted);font-weight:500">(optional for open)</span></label><input id="available_until" name="available_until" type="datetime-local" value="<?= e($savedUntil) ?>"></div>
        </div>
        <p class="fine" id="availability_help">Unlimited availability is selected by default. Leave dates unset to keep the quiz available until you manually close or unpublish it.</p>
        <button class="btn btn-primary full" type="submit"><?= $editingQuiz ? 'Save quiz details' : 'Create quiz draft' ?> <span>→</span></button>
        <?php if ($editingQuiz): ?><a class="btn full" href="<?= e(url('lecturer/quizzes.php?quiz=' . (int)$editingQuiz['id'])) ?>">Cancel edit</a><?php endif; ?>
    </form>
</section>
<section class="card">
<?php if ($editingQuiz): ?>
    <a class="back-link" href="<?= e(url('lecturer/quizzes.php')) ?>">← All quizzes</a>
    <div class="card-head"><div><div class="kicker">Question builder</div><h2><?= e((string)$editingQuiz['title']) ?></h2><p class="hint">Add four-option multiple-choice questions. Select the correct answer and include an explanation to help students learn from mistakes.</p></div><span class="badge <?= (int)$editingQuiz['is_published'] === 1 ? 'published' : 'draft' ?>"><?= (int)$editingQuiz['is_published'] === 1 ? 'Published' : 'Draft' ?></span></div>
    <?php if ((int)$editingQuiz['is_published'] === 1): ?>
      <div class="message success">This quiz is currently published. To safely edit an individual question, first move this quiz back to draft. Students will no longer be able to start it while it is a draft; you can publish it again after reviewing your changes.</div>
      <form method="post" class="section-gap" onsubmit="return confirm('Unpublish this quiz so you can edit individual questions?')">
        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
        <input type="hidden" name="action" value="toggle_publish">
        <input type="hidden" name="quiz_id" value="<?= (int)$editingQuiz['id'] ?>">
        <button class="btn btn-primary" type="submit">Unpublish quiz to edit questions</button>
        <a class="btn" href="<?= e(url('lecturer/quizzes.php')) ?>">Back to quiz list</a>
      </form>
      <div class="separator"></div>
      <div class="kicker">Question review</div><h2>Questions (<?= count($questions) ?>)</h2>
      <p class="hint">Your questions are listed below for review. Unpublish the quiz to reveal the individual Edit question and Delete question controls.</p>
      <?php if (!$questions): ?><div class="empty"><strong>No questions added yet</strong></div><?php else: foreach ($questions as $index => $question): ?>
        <article class="question-item">
          <div class="topline"><div class="question-text"><?= ($index + 1) ?>. <?= e((string)$question['question_text']) ?></div><span class="badge"><?= e((string)$question['topic']) ?></span></div>
          <div class="options"><?php foreach (($question['options'] ?? []) as $option): ?><div class="option <?= (int)$option['is_correct'] === 1 ? 'correct' : '' ?>"><?= (int)$option['is_correct'] === 1 ? '✓ Correct · ' : '' ?><?= e((string)$option['option_text']) ?></div><?php endforeach; ?></div>
        </article>
      <?php endforeach; endif; ?>
    <?php else: ?>
    <div class="separator"></div>
    <div class="kicker">Faster workflow</div><h2>Bulk import questions</h2>
    <p class="hint">Paste all questions at once. ULearn separates the options, correct answer, explanation, topic and optional reason for each option. Students will not see answer keys during the quiz.</p>
    <form method="post" enctype="multipart/form-data" class="section-gap">
        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="preview_bulk"><input type="hidden" name="quiz_id" value="<?= (int)$editingQuiz['id'] ?>">
        <div class="field"><label for="source_file">Upload question file (.txt or .docx)</label><input id="source_file" name="source_file" type="file" accept=".txt,.docx,text/plain,application/vnd.openxmlformats-officedocument.wordprocessingml.document"><p class="fine">Maximum file size: 5 MB. If a file is selected, the file content is used instead of the textbox. For .docx uploads, enable PHP ZipArchive and DOM extensions. You can also paste the questions below.</p></div><div class="field"><label for="bulk_text">Paste question set</label><textarea id="bulk_text" name="bulk_text" style="min-height:280px" placeholder="Q1: What is the time complexity of binary search?&#10;A) O(n)&#10;B) O(log n)&#10;C) O(n²)&#10;D) O(1)&#10;Correct: B&#10;Topic: Searching&#10;Explanation: Binary search halves the search interval at each step.&#10;Reason A: A linear scan is O(n), not binary search.&#10;Reason B: Each step halves the interval, so this is correct.&#10;Reason C: This is not the complexity of binary search.&#10;Reason D: The search is not constant time in general."><?= e($bulkText) ?></textarea></div>
        <div class="message success" style="margin-top:14px"><strong>Lecturer upload guidelines</strong><ul style="margin:8px 0 0;padding-left:20px"><li>Upload only .txt or .docx files, up to 5 MB.</li><li>Use one question block at a time, starting with Q1:, Q2:, Q3:.</li><li>Every question must contain exactly four options: A), B), C), and D).</li><li>Add one correct answer line, for example: Correct: B.</li><li>Topic, Difficulty, Explanation, and Reason A–D are optional.</li><li>Keep each option and explanation on its own labelled line; leave a blank line between questions.</li><li>Click Preview and validate first. Check every question and correct answer before confirming import.</li></ul></div>
        <p class="fine"><strong>Required format:</strong> Q1: question text → A) option → B) option → C) option → D) option → Correct: B. Optional lines: Topic: Sorting, Difficulty: Easy, Explanation: ..., Reason A: ..., Reason B: ..., Reason C: ..., Reason D: ....</p>
        <button class="btn btn-primary full" type="submit">Preview and validate questions →</button>
    </form>
    <?php if ($bulkPreview): ?>
        <div class="separator"></div><div class="topline"><div><div class="kicker">Import preview</div><h2><?= count($bulkPreview) ?> question block(s) detected</h2></div><span class="badge <?= $bulkErrors ? 'draft' : 'published' ?>"><?= $bulkErrors ? count($bulkErrors) . ' issue(s)' : 'Ready to import' ?></span></div>
        <?php foreach ($bulkErrors as $bulkIssue): ?><div class="message error"><?= e($bulkIssue) ?></div><?php endforeach; ?>
        <?php foreach ($bulkPreview as $pi => $pq): ?><article class="question-item"><div class="question-text"><?= ($pi + 1) ?>. <?= e($pq['question_text']) ?></div><div class="options"><?php foreach (['A','B','C','D'] as $letter): ?><div class="option <?= $pq['correct'] === $letter ? 'correct' : '' ?>"><?= $letter ?>. <?= e($pq['options'][$letter]) ?><?= $pq['correct'] === $letter ? ' · Correct answer' : '' ?><?php if ($pq['option_explanations'][$letter] !== ''): ?><div class="fine"><?= e($pq['option_explanations'][$letter]) ?></div><?php endif; ?></div><?php endforeach; ?></div><p class="hint" style="margin-top:10px">Topic: <?= e($pq['topic']) ?><?= $pq['difficulty'] !== '' ? ' · Difficulty: ' . e($pq['difficulty']) : '' ?></p><?php if ($pq['explanation'] !== ''): ?><p class="hint"><strong>Explanation:</strong> <?= e($pq['explanation']) ?></p><?php endif; ?></article><?php endforeach; ?>
        <?php if (!$bulkErrors): ?><form method="post" class="section-gap" onsubmit="return confirm('Import all <?= count($bulkPreview) ?> questions into this draft?')"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="import_bulk"><input type="hidden" name="quiz_id" value="<?= (int)$selectedQuizId ?>"><textarea name="bulk_text" hidden><?= e($bulkText) ?></textarea><button class="btn btn-primary full" type="submit">Confirm &amp; import <?= count($bulkPreview) ?> questions</button></form><?php endif; ?>
    <?php endif; ?>
    <div class="separator"></div><div class="kicker">Or add one question manually</div>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="add_question"><input type="hidden" name="quiz_id" value="<?= (int)$editingQuiz['id'] ?>">
        <div class="field"><label for="question_text">Question</label><textarea id="question_text" name="question_text" required maxlength="10000" placeholder="Write a clear question..."></textarea></div>
        <div class="two"><div class="field"><label for="topic">Topic tag</label><input id="topic" name="topic" required maxlength="150" placeholder="e.g. Sorting, Searching"></div><div class="field"><label for="difficulty">Difficulty</label><select id="difficulty" name="difficulty"><option value="">Not set</option><option>Easy</option><option>Medium</option><option>Advanced</option></select></div></div>
        <div class="separator"></div>
        <div class="kicker">Answer options</div>
        <?php foreach (['a','b','c','d'] as $idx => $letter): ?><div class="field"><label for="option_<?= $letter ?>">Option <?= strtoupper($letter) ?> <?= $idx === 0 ? '' : '' ?></label><input id="option_<?= $letter ?>" name="option_<?= $letter ?>" maxlength="2000" required placeholder="Enter option <?= strtoupper($letter) ?>"></div><?php endforeach; ?>
        <div class="field"><label for="reason_a">Why option A is wrong/right (optional)</label><textarea id="reason_a" name="reason_a"></textarea></div><div class="field"><label for="reason_b">Why option B is wrong/right (optional)</label><textarea id="reason_b" name="reason_b"></textarea></div><div class="field"><label for="reason_c">Why option C is wrong/right (optional)</label><textarea id="reason_c" name="reason_c"></textarea></div><div class="field"><label for="reason_d">Why option D is wrong/right (optional)</label><textarea id="reason_d" name="reason_d"></textarea></div><div class="field"><label for="correct_option">Correct answer</label><select id="correct_option" name="correct_option" required><option value="">Choose the correct option</option><option value="0">Option A</option><option value="1">Option B</option><option value="2">Option C</option><option value="3">Option D</option></select></div>
        <div class="field"><label for="explanation">Answer explanation</label><textarea id="explanation" name="explanation" maxlength="10000" placeholder="Explain why the correct answer is right and clarify common mistakes."></textarea></div>
        <button class="btn btn-primary full" type="submit">Add question <span>→</span></button>
    </form>
    <?php endif; ?>
    <div class="separator"></div>
    <div class="topline"><div><div class="kicker">Question review</div><h2>Questions (<?= count($questions) ?>)</h2></div></div>
    <?php if (!$questions): ?><div class="empty"><strong>No questions added yet</strong>Add your first question above. At least one question is required before publishing.</div><?php else: foreach ($questions as $index => $question): ?>
    <article class="question-item">
        <div class="topline"><div class="question-text"><?= ($index + 1) ?>. <?= e((string)$question['question_text']) ?></div><span class="badge"><?= e((string)$question['topic']) ?></span></div>
        <?php if ($editQuestionId === (int)$question['id'] && (int)$editingQuiz['is_published'] !== 1): ?>
        <form method="post" class="section-gap">
          <input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="update_question"><input type="hidden" name="quiz_id" value="<?= (int)$editingQuiz['id'] ?>"><input type="hidden" name="question_id" value="<?= (int)$question['id'] ?>">
          <div class="field"><label>Question text</label><textarea name="question_text" required><?= e((string)$question['question_text']) ?></textarea></div>
          <div class="two"><div class="field"><label>Topic</label><input name="topic" required value="<?= e((string)$question['topic']) ?>"></div><div class="field"><label>Difficulty</label><select name="difficulty"><option value="">Not set</option><?php foreach (['Easy','Medium','Advanced'] as $level): ?><option value="<?= e($level) ?>" <?= (string)($question['difficulty'] ?? '') === $level ? 'selected' : '' ?>><?= e($level) ?></option><?php endforeach; ?></select></div></div>
          <?php foreach (['A','B','C','D'] as $letter): $op = $question['options'][ord($letter)-65] ?? []; ?>
          <div class="field"><label>Option <?= $letter ?></label><input name="option_<?= strtolower($letter) ?>" required value="<?= e((string)($op['option_text'] ?? '')) ?>"></div>
          <div class="field"><label>Reason for option <?= $letter ?> (optional)</label><textarea name="reason_<?= strtolower($letter) ?>"><?= e((string)($op['explanation'] ?? '')) ?></textarea></div>
          <?php endforeach; ?>
          <div class="field"><label>Correct answer</label><select name="correct_option" required><?php foreach (['A','B','C','D'] as $letter): $op = $question['options'][ord($letter)-65] ?? []; ?><option value="<?= $letter ?>" <?= !empty($op['is_correct']) ? 'selected' : '' ?>>Option <?= $letter ?></option><?php endforeach; ?></select></div>
          <div class="field"><label>Overall explanation</label><textarea name="explanation"><?= e((string)$question['explanation']) ?></textarea></div>
          <button class="btn btn-primary" type="submit">Save question changes</button> <a class="btn" href="<?= e(url('lecturer/quizzes.php?quiz=' . (int)$editingQuiz['id'])) ?>">Cancel</a>
        </form>
        <?php else: ?>
        <div class="options"><?php foreach (($question['options'] ?? []) as $option): ?><div class="option <?= (int)$option['is_correct'] === 1 ? 'correct' : '' ?>"><?= (int)$option['is_correct'] === 1 ? '✓ Correct · ' : '' ?><?= e((string)$option['option_text']) ?><?php if (!empty($option['explanation'])): ?><div class="fine">Reason: <?= e((string)$option['explanation']) ?></div><?php endif; ?></div><?php endforeach; ?></div>
        <?php if (trim((string)$question['explanation']) !== ''): ?><p class="hint" style="margin-top:12px"><strong>Explanation:</strong> <?= e((string)$question['explanation']) ?></p><?php endif; ?>
        <?php if ((int)$editingQuiz['is_published'] !== 1): ?><a class="btn btn-small" href="<?= e(url('lecturer/quizzes.php?quiz=' . (int)$editingQuiz['id'] . '&edit_question=' . (int)$question['id'])) ?>">Edit question</a> <form method="post" class="section-gap" onsubmit="return confirm('Delete this question and its options?')"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="delete_question"><input type="hidden" name="quiz_id" value="<?= (int)$editingQuiz['id'] ?>"><input type="hidden" name="question_id" value="<?= (int)$question['id'] ?>"><button class="btn btn-small btn-danger" type="submit">Delete question</button></form><?php endif; ?>
        <?php endif; ?>
    </article>
    <?php endforeach; endif; ?>
<?php else: ?>
    <div class="card-head"><div><div class="kicker">Assessment library</div><h2>Your quizzes</h2><p class="hint">Open a quiz to manage its questions, review answers, or change publication status.</p></div></div>
    <?php if (!$quizzes): ?><div class="empty"><strong>Your quiz library is ready</strong>No quizzes have been created yet. Use the form to create your first draft.</div><?php else: ?>
    <div class="quiz-list">
    <?php foreach ($quizzes as $quiz): ?><article class="quiz-item">
        <div class="topline"><div class="quiz-title"><?= e((string)$quiz['title']) ?></div><span class="badge <?= (int)$quiz['is_published'] === 1 ? 'published' : 'draft' ?>"><?= (int)$quiz['is_published'] === 1 ? 'Published' : 'Draft' ?></span></div>
        <div class="quiz-meta"><span><?= e((string)($quiz['module_code'] ?? 'No module')) ?></span><span>·</span><span><?= (int)$quiz['question_count'] ?> questions</span><span>·</span><span><?= (int)$quiz['duration_minutes'] ?> min</span><span>·</span><span><?= e(ucfirst((string)$quiz['quiz_type'])) ?></span><?php if (!empty($quiz['available_from'])): ?><span>· Opens <?= e(date('d M Y H:i', strtotime((string)$quiz['available_from']))) ?></span><?php endif; ?><?php if (!empty($quiz['available_until'])): ?><span>· Closes <?= e(date('d M Y H:i', strtotime((string)$quiz['available_until']))) ?></span><?php endif; ?></div>
        <?php if (trim((string)$quiz['description']) !== ''): ?><p class="hint" style="margin-top:8px"><?= e((string)$quiz['description']) ?></p><?php endif; ?>
        <div class="quiz-actions"><a class="btn btn-small" href="<?= e(url('lecturer/quizzes.php?quiz=' . (int)$quiz['id'])) ?>">Manage questions</a><a class="btn btn-small" href="<?= e(url('lecturer/quizzes.php?edit=' . (int)$quiz['id'])) ?>">Edit details</a>
        <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="toggle_publish"><input type="hidden" name="quiz_id" value="<?= (int)$quiz['id'] ?>"><button class="btn btn-small" type="submit"><?= (int)$quiz['is_published'] === 1 ? 'Unpublish' : 'Publish' ?></button></form>
        <form method="post" onsubmit="return confirm('Permanently delete this quiz and all its questions?')"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="delete_quiz"><input type="hidden" name="quiz_id" value="<?= (int)$quiz['id'] ?>"><button class="btn btn-small btn-danger" type="submit">Delete</button></form></div>
    </article><?php endforeach; ?>
    </div><?php endif; ?>
<?php endif; ?>
</section>
</div>
</main>
</div>
<script>
function updateAvailabilityFields() {
    const mode = document.getElementById('availability_mode');
    const dates = document.getElementById('availability_dates');
    const fromWrap = document.getElementById('available_from_wrap');
    const fromInput = document.getElementById('available_from');
    const untilInput = document.getElementById('available_until');
    const help = document.getElementById('availability_help');
    if (!mode || !dates || !fromWrap || !fromInput || !untilInput || !help) return;
    dates.style.display = mode.value === 'unlimited' ? 'none' : 'grid';
    fromWrap.style.display = mode.value === 'scheduled' ? '' : 'none';
    fromInput.required = mode.value === 'scheduled';
    untilInput.required = mode.value === 'scheduled';
    if (mode.value === 'unlimited') {
        help.textContent = 'Unlimited availability is selected. ULearn will ignore any dates and keep this quiz available until you manually close or unpublish it.';
    } else if (mode.value === 'scheduled') {
        help.textContent = 'Students can access this quiz only between the required opening and closing dates.';
    } else {
        help.textContent = 'The quiz is available immediately. Set a closing date if you want it to expire automatically, or leave it blank for no automatic expiry.';
    }
}
document.addEventListener('DOMContentLoaded', updateAvailabilityFields);

// A small confirmation guard for publishing and deleting destructive records.
document.querySelectorAll('form').forEach(function(form){
    const action = form.querySelector('input[name="action"]')?.value;
    if (action === 'toggle_publish') {
        form.addEventListener('submit', function(event){
            const button = form.querySelector('button[type="submit"]');
            if (button && button.textContent.trim() === 'Publish' && !confirm('Publish this quiz now? Students may be able to access it.')) event.preventDefault();
        });
    }
});
</script>
</body>
</html>