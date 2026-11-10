<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
$user = require_login();

if (!in_array((string)$user['role'], ['admin', 'main_admin'], true)) {
    http_response_code(403);
    exit('Access denied. Admin permission required.');
}

$pdo = db();
$error = '';
$notice = '';
$editingQuiz = null;
$editingQuestion = null;
$bulkText = '';
$bulkPreview = [];
$bulkErrors = [];
$selectedQuizId = filter_input(INPUT_GET, 'quiz', FILTER_VALIDATE_INT) ?: 0;
$editQuizId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT) ?: 0;
$editQuestionId = filter_input(INPUT_GET, 'edit_question', FILTER_VALIDATE_INT) ?: 0;
if ($selectedQuizId < 1 && $editQuizId > 0) { $selectedQuizId = $editQuizId; }

if (isset($_SESSION['quiz_notice'])) {
    $notice = (string) $_SESSION['quiz_notice'];
    unset($_SESSION['quiz_notice']);
}

function quizRedirect(string $query = ''): never
{
    go('admin/quizzes.php' . ($query !== '' ? '?' . $query : ''));
}

/** Parse a pasted plain-text multiple-choice quiz into structured question records. */
function parseBulkQuizText(string $raw): array
{
    $raw = str_replace(["\r\n", "\r"], "\n", trim($raw));
    if ($raw === '') return ['questions' => [], 'errors' => ['Paste your questions into the text area first.']];
    if (strlen($raw) > 2_000_000) return ['questions' => [], 'errors' => ['The pasted content is too large. Please import fewer questions at a time.']];

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

/** Extract plain text from an uploaded .txt or .docx file. */
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
        if ($action === 'create_quiz' || $action === 'update_quiz') {
            $title = trim((string)($_POST['title'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $moduleId = filter_var($_POST['module_id'] ?? null, FILTER_VALIDATE_INT);
            $newModuleCode = strtoupper(trim((string)($_POST['new_module_code'] ?? '')));
            $newModuleName = trim((string)($_POST['new_module_name'] ?? ''));
            $quizType = (string)($_POST['quiz_type'] ?? 'topic');
            $duration = filter_var($_POST['duration_minutes'] ?? null, FILTER_VALIDATE_INT);
            $points = filter_var($_POST['points_per_question'] ?? null, FILTER_VALIDATE_INT);
            $availabilityMode = (string)($_POST['availability_mode'] ?? 'unlimited');
            $rawAvailableFrom = trim((string)($_POST['available_from'] ?? ''));
            $rawAvailableUntil = trim((string)($_POST['available_until'] ?? ''));
            if (!in_array($availabilityMode, ['unlimited', 'scheduled', 'open_until'], true)) {
                throw new RuntimeException('Choose a valid quiz availability setting.');
            }
            if ($availabilityMode === 'unlimited') {
                $availableFrom = null;
                $availableUntil = null;
            } elseif ($availabilityMode === 'scheduled') {
                if ($rawAvailableFrom === '' || $rawAvailableUntil === '') {
                    throw new RuntimeException('Scheduled availability requires both an opening and closing date.');
                }
                $availableFrom = str_replace('T', ' ', $rawAvailableFrom) . (strlen($rawAvailableFrom) === 16 ? ':00' : '');
                $availableUntil = str_replace('T', ' ', $rawAvailableUntil) . (strlen($rawAvailableUntil) === 16 ? ':00' : '');
            } else {
                $availableFrom = null;
                $availableUntil = $rawAvailableUntil !== '' ? str_replace('T', ' ', $rawAvailableUntil) . (strlen($rawAvailableUntil) === 16 ? ':00' : '') : null;
            }
            if ($availableFrom && $availableUntil && strtotime($availableUntil) <= strtotime($availableFrom)) throw new RuntimeException('The closing date must be later than the opening date.');
            if ($availableUntil && strtotime($availableUntil) <= time()) throw new RuntimeException('The closing date must be in the future.');

            if ($title === '' || strlen($title) > 200) {
                throw new RuntimeException('Enter a quiz title of no more than 200 characters.');
            }
            if ((!$moduleId || $moduleId < 1) && ($newModuleCode === '' || $newModuleName === '')) {
                throw new RuntimeException('Choose an existing module or enter both a new module code and module name.');
            }
            if ($newModuleCode !== '' && (strlen($newModuleCode) > 30 || !preg_match('/^[A-Z0-9._-]+$/', $newModuleCode))) {
                throw new RuntimeException('Module code must be 1–30 characters and use only letters, numbers, dots, underscores or hyphens.');
            }
            if ($newModuleName !== '' && strlen($newModuleName) > 200) {
                throw new RuntimeException('Module name must be no longer than 200 characters.');
            }
            if (!in_array($quizType, ['topic', 'general', 'exam'], true)) {
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

            if ($moduleId && $moduleId > 0) {
                $moduleCheck = $pdo->prepare('SELECT id FROM academic_modules WHERE id = ? AND is_active = 1');
                $moduleCheck->execute([$moduleId]);
                if (!$moduleCheck->fetchColumn()) {
                    throw new RuntimeException('The selected module is unavailable. Refresh and try again.');
                }
            } else {
                $existingModule = $pdo->prepare('SELECT id, module_name, is_active FROM academic_modules WHERE module_code = ? LIMIT 1');
                $existingModule->execute([$newModuleCode]);
                $existing = $existingModule->fetch(PDO::FETCH_ASSOC);
                if ($existing) {
                    if ((int)$existing['is_active'] !== 1) {
                        throw new RuntimeException('That module code already exists but is inactive. Reactivate it under Academics first.');
                    }
                    if (strcasecmp((string)$existing['module_name'], $newModuleName) !== 0) {
                        throw new RuntimeException('That module code already exists with a different name. Choose it from the existing-module list or use another code.');
                    }
                    $moduleId = (int)$existing['id'];
                } else {
                    $createModule = $pdo->prepare('INSERT INTO academic_modules (module_code, module_name, description, is_active) VALUES (?, ?, ?, 1)');
                    $createModule->execute([$newModuleCode, $newModuleName, 'Created from Quiz Management']);
                    $moduleId = (int)$pdo->lastInsertId();
                }
            }

            if ($action === 'create_quiz') {
                $stmt = $pdo->prepare(
                    'INSERT INTO quizzes (module_id, title, description, quiz_type, duration_minutes, points_per_question, available_from, available_until, is_published) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)'
                );
                $stmt->execute([$moduleId, $title, $description, $quizType, $duration, $points, $availableFrom, $availableUntil]);
                $newId = (int)$pdo->lastInsertId();
                $_SESSION['quiz_notice'] = 'Quiz created as a draft. Add questions before publishing.';
                quizRedirect('quiz=' . $newId);
            }

            $quizId = filter_var($_POST['quiz_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$quizId || $quizId < 1) {
                throw new RuntimeException('Invalid quiz selected.');
            }
            $stmt = $pdo->prepare('UPDATE quizzes SET module_id = ?, title = ?, description = ?, quiz_type = ?, duration_minutes = ?, points_per_question = ?, available_from = ?, available_until = ? WHERE id = ?');
            $stmt->execute([$moduleId, $title, $description, $quizType, $duration, $points, $availableFrom, $availableUntil, $quizId]);
            $_SESSION['quiz_notice'] = 'Quiz details updated.';
            quizRedirect('quiz=' . $quizId);
        }

        if ($action === 'preview_bulk') {
            $quizId = filter_var($_POST['quiz_id'] ?? null, FILTER_VALIDATE_INT);
            $bulkText = trim((string)($_POST['bulk_text'] ?? ''));
            $uploadedText = extractQuizUpload($_FILES['source_file'] ?? ['error' => UPLOAD_ERR_NO_FILE]);
            if ($uploadedText !== '') $bulkText = $uploadedText;
            if (!$quizId || $quizId < 1) throw new RuntimeException('Select a valid quiz before importing questions.');
            $check = $pdo->prepare('SELECT is_published FROM quizzes WHERE id = ?');
            $check->execute([$quizId]); $quizState = $check->fetchColumn();
            if ($quizState === false) throw new RuntimeException('Quiz not found.');
            if ((int)$quizState === 1) throw new RuntimeException('Move the quiz back to draft before importing questions.');
            $parsed = parseBulkQuizText($bulkText);
            $bulkPreview = $parsed['questions'];
            $bulkErrors = $parsed['errors'];
            $_SESSION['quiz_import_preview'] = [
                'quiz_id' => (int)$quizId,
                'questions' => $bulkPreview,
                'errors' => $bulkErrors,
            ];
            quizRedirect('quiz=' . (int)$quizId . '&import_preview=1');
            $selectedQuizId = (int)$quizId;
        }

        if ($action === 'import_bulk') {
            $quizId = filter_var($_POST['quiz_id'] ?? null, FILTER_VALIDATE_INT);
            $bulkText = (string)($_POST['bulk_text'] ?? '');
            if (!$quizId || $quizId < 1) throw new RuntimeException('Select a valid quiz before importing questions.');
            $check = $pdo->prepare('SELECT is_published FROM quizzes WHERE id = ?');
            $check->execute([$quizId]); $quizState = $check->fetchColumn();
            if ($quizState === false) throw new RuntimeException('Quiz not found.');
            if ((int)$quizState === 1) throw new RuntimeException('Move the quiz back to draft before importing questions.');
            $parsed = parseBulkQuizText($bulkText);
            if ($parsed['errors']) throw new RuntimeException('Import stopped: ' . implode(' ', array_slice($parsed['errors'], 0, 5)));
            if (!$parsed['questions']) throw new RuntimeException('No valid questions were found to import.');
            $optionExplanationColumn = $pdo->query("SHOW COLUMNS FROM quiz_options LIKE 'explanation'")->fetch();
            if (!$optionExplanationColumn) throw new RuntimeException('Run database/migrations/013_quiz_option_reasons.sql in phpMyAdmin before importing questions.');

            $pdo->beginTransaction();
            $questionStmt = $pdo->prepare('INSERT INTO quiz_questions (quiz_id, question_text, explanation, topic, difficulty) VALUES (?, ?, ?, ?, ?)');
            $optionStmt = $pdo->prepare('INSERT INTO quiz_options (question_id, option_text, is_correct, explanation) VALUES (?, ?, ?, ?)');
            foreach ($parsed['questions'] as $question) {
                $questionStmt->execute([$quizId, $question['question_text'], $question['explanation'], $question['topic'], $question['difficulty'] !== '' ? $question['difficulty'] : null]);
                $questionId = (int)$pdo->lastInsertId();
                foreach (['A','B','C','D'] as $letter) {
                    $optionStmt->execute([$questionId, $question['options'][$letter], $question['correct'] === $letter ? 1 : 0, $question['option_explanations'][$letter]]);
                }
            }
            $pdo->commit();
            $_SESSION['quiz_notice'] = count($parsed['questions']) . ' questions imported successfully. Review the quiz before publishing.';
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
            $stmt = $pdo->prepare('SELECT is_published FROM quizzes WHERE id = ?');
            $stmt->execute([$quizId]);
            $current = $stmt->fetchColumn();
            if ($current === false) throw new RuntimeException('Quiz not found.');

            if ((int)$current === 0) {
                $countStmt = $pdo->prepare('SELECT COUNT(*) FROM quiz_questions WHERE quiz_id = ?');
                $countStmt->execute([$quizId]);
                if ((int)$countStmt->fetchColumn() < 1) throw new RuntimeException('Add at least one question before publishing this quiz.');
            }
            $stmt = $pdo->prepare('UPDATE quizzes SET is_published = ? WHERE id = ?');
            $stmt->execute([(int)$current === 1 ? 0 : 1, $quizId]);
            $_SESSION['quiz_notice'] = (int)$current === 1 ? 'Quiz moved back to draft.' : 'Quiz published successfully.';
            quizRedirect('quiz=' . $quizId);
        }

        if ($action === 'delete_question') {
            $questionId = filter_var($_POST['question_id'] ?? null, FILTER_VALIDATE_INT);
            $quizId = filter_var($_POST['quiz_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$questionId || !$quizId) throw new RuntimeException('Invalid question selected.');
            $publishedCheck = $pdo->prepare('SELECT is_published FROM quizzes WHERE id = ?');
            $publishedCheck->execute([$quizId]);
            if ((int)$publishedCheck->fetchColumn() === 1) throw new RuntimeException('Unpublish the quiz before removing questions.');
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

        throw new RuntimeException('Unknown action. Refresh the page and try again.');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('ULearn quiz management error: ' . $exception->getMessage());
        $error = $exception instanceof RuntimeException
            ? $exception->getMessage()
            : 'Could not complete that action. Please check the database structure and try again.';
    }
}

try {
    $modules = $pdo->query('SELECT id, module_code, module_name FROM academic_modules WHERE is_active = 1 ORDER BY module_code')->fetchAll();
    $quizStmt = $pdo->query(
        'SELECT q.id, q.module_id, q.title, q.description, q.quiz_type, q.duration_minutes, q.points_per_question, q.available_from, q.available_until, q.is_published, q.created_at, m.module_code, m.module_name, (SELECT COUNT(*) FROM quiz_questions qq WHERE qq.quiz_id = q.id) AS question_count FROM quizzes q LEFT JOIN academic_modules m ON m.id = q.module_id ORDER BY q.created_at DESC, q.id DESC'
    );
    $quizzes = $quizStmt->fetchAll();

    if ($selectedQuizId > 0) {
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
    $error = $error ?: 'The quiz page could not load its data. Check that the database tables and columns match the existing ULearn schema.';
}

$isImportPreviewPage = isset($_GET['import_preview']) && (string)$_GET['import_preview'] === '1';
if ($isImportPreviewPage) {
    $savedPreview = $_SESSION['quiz_import_preview'] ?? null;
    if (is_array($savedPreview) && (int)($savedPreview['quiz_id'] ?? 0) === (int)($selectedQuizId ?? 0)) {
        $bulkPreview = $savedPreview['questions'] ?? [];
        $bulkErrors = $savedPreview['errors'] ?? [];
    }
    unset($_SESSION['quiz_import_preview']);
}

// Personal unread notifications for the signed-in administrator.
$notificationStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0"
);
$notificationStmt->execute([(int)$user['id']]);
$unreadNotifications = (int)$notificationStmt->fetchColumn();

$fullName = (string)($user['full_name'] ?? 'Administrator');
$nameParts = preg_split('/\s+/', trim($fullName));
$firstName = $nameParts[0] ?: 'Administrator';
$initial = strtoupper(substr($firstName, 0, 1));
$roleLabel = $user['role'] === 'main_admin' ? 'Main Administrator' : 'Administrator';
$theme = (string)($user['theme'] ?? 'system');

$publishedCount = count(array_filter($quizzes, static fn($q) => (int)$q['is_published'] === 1));
$totalQuestions = array_sum(array_map(static fn($q) => (int)$q['question_count'], $quizzes));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Quiz Management — U Learn</title>
<script>
    try {
        const saved = localStorage.getItem('ulearn-theme') || <?= json_encode($theme, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const theme = saved === 'system'
            ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
            : saved;
        document.documentElement.dataset.theme = theme;
        localStorage.setItem('ulearn-theme', theme);
    } catch (error) {}
</script>
<style>
    :root {
        color-scheme: light;
        --bg: #f7f8fc;
        --surface: #ffffff;
        --surface-alt: #f1f2f8;
        --text: #171927;
        --muted: #777b90;
        --border: #e7e8f0;
        --accent: #6756e8;
        --accent-light: #f0edff;
        --shadow: 0 10px 40px rgba(30,30,60,.04);
        --green: #087e60;
        --soft: #e7f7ef;
    }
    :root[data-theme="dark"] {
        color-scheme: dark;
        --bg: #0c0e16;
        --surface: #161924;
        --surface-alt: #202431;
        --text: #f4f4fa;
        --muted: #a0a4b6;
        --border: #2b2e3c;
        --accent: #a99aff;
        --accent-light: #292440;
        --shadow: none;
        --green: #5ce3b5;
        --soft: #153d34;
    }
    * { box-sizing: border-box; }
    html { scroll-behavior: smooth; }
    body {
        margin: 0;
        background: var(--bg);
        color: var(--text);
        font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif;
        line-height: 1.6;
    }
    a { color: inherit; text-decoration: none; }
    button, select, input, textarea { font: inherit; }
    button { cursor: pointer; }
    
    .layout {
        display: grid;
        grid-template-columns: 250px minmax(0, 1fr);
        min-height: 100vh;
    }
    .sidebar {
        background: var(--surface);
        border-right: 1px solid var(--border);
        padding: 32px 20px;
        display: flex;
        flex-direction: column;
        gap: 40px;
    }
    .brand {
        font-size: 26px;
        font-weight: 800;
        letter-spacing: -1.5px;
        padding-left: 12px;
    }
    .brand span { color: var(--accent); }
    .nav-label, .eyebrow {
        font-size: 11px;
        font-weight: 750;
        letter-spacing: 1.7px;
        text-transform: uppercase;
        color: var(--muted);
    }
    .nav-label { margin: 0 12px 14px; }
    .nav { display: grid; gap: 6px; }
    .nav a {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px 14px;
        border-radius: 12px;
        font-size: 14px;
        font-weight: 600;
        transition: background .2s, color .2s;
    }
    .nav a:hover { background: var(--surface-alt); }
    .nav a.active {
        background: var(--accent-light);
        color: var(--accent);
    }
    .ul-nav-icon {
        width: 20px;
        height: 20px;
        flex-shrink: 0;
    }
    .sidebar-bottom {
        margin-top: auto;
        border: 1px solid var(--border);
        border-radius: 16px;
        padding: 16px;
        font-size: 13px;
    }
    .sidebar-bottom strong {
        display: block;
        overflow-wrap: anywhere;
    }
    .sidebar-bottom small {
        color: var(--muted);
        overflow-wrap: anywhere;
    }
    .main {
        min-width: 0;
        padding: 34px clamp(20px, 5vw, 76px) 80px;
    }
    .topbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 20px;
        margin-bottom: 40px;
    }
    .topbar-right {
        display: flex;
        align-items: center;
        gap: 16px;
    }
    .notification-bell {
        width: 42px; height: 42px; position: relative;
        display: inline-grid; place-items: center;
        background: var(--surface); color: var(--text);
        border: 1px solid var(--border); border-radius: 12px;
        box-shadow: var(--shadow);
        transition: transform .18s ease, background .18s ease, border-color .18s ease;
    }
    .notification-bell:hover { transform: translateY(-2px); background: var(--accent-light); border-color: var(--accent); }
    .notification-bell svg { width: 21px; height: 21px; stroke: currentColor; fill: none; stroke-width: 1.9; stroke-linecap: round; stroke-linejoin: round; }
    .notification-count {
        position: absolute; top: -8px; right: -9px;
        min-width: 20px; height: 20px; padding: 0 5px;
        display: inline-grid; place-items: center;
        border-radius: 999px; background: #e34878; color: #fff;
        border: 2px solid var(--bg); font-size: 10px; font-weight: 800;
        line-height: 1;
    }
    .theme-toggle {
        border: 1px solid var(--border);
        background: var(--surface);
        color: var(--text);
        border-radius: 12px;
        width: 42px;
        height: 42px;
        display: grid;
        place-items: center;
        font-size: 18px;
        box-shadow: var(--shadow);
        transition: transform .18s ease, background .18s ease, border-color .18s ease;
    }
    .theme-toggle:hover {
        background: var(--accent-light);
        border-color: var(--accent);
        transform: translateY(-2px);
    }
    .avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: var(--accent-light);
        color: var(--accent);
        display: grid;
        place-items: center;
        font-weight: 800;
        transition: transform .18s ease;
    }
    .avatar:hover { transform: translateY(-2px); }
    .logout {
        background: transparent;
        border: 0;
        color: var(--muted);
        font-size: 13px;
        font-weight: 650;
        cursor: pointer;
    }
    .logout:hover { color: var(--text); }
    h1, h2, h3, p { margin-top: 0; }
    
    .hero {
        border-radius: 24px;
        padding: 32px;
        background: linear-gradient(115deg, #063e39, #087d62 70%, #12677b);
        color: white;
        margin-bottom: 30px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 20px;
    }
    .hero h1 { font-size: clamp(28px, 4vw, 44px); margin: 6px 0; letter-spacing: -1.5px; }
    .hero p { opacity: .88; margin-bottom: 0; max-width: 600px; }
    .hero .pill {
        display: inline-flex;
        align-items: center;
        padding: 8px 16px;
        border-radius: 100px;
        background: rgba(255, 255, 255, 0.15);
        backdrop-filter: blur(8px);
        color: #fff;
        font-size: 12px;
        font-weight: 750;
        white-space: nowrap;
        border: 1px solid rgba(255, 255, 255, 0.2);
    }
    
    .section-heading {
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        gap: 15px;
        margin: 40px 0 20px;
    }
    .section-heading h2 {
        margin: 0;
        font-size: 22px;
        letter-spacing: -.5px;
    }
    .section-heading small { color: var(--muted); font-weight: 600; }
    
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 16px;
    }
    .stat-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 20px;
        padding: 24px;
        min-height: 154px;
        box-shadow: var(--shadow);
        transition: transform .2s ease, border-color .2s ease;
    }
    .stat-card:hover {
        border-color: var(--accent);
        transform: translateY(-2px);
    }
    .stat-top {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
    }
    .stat-label {
        color: var(--muted);
        font-size: 13px;
        font-weight: 650;
    }
    .icon-box {
        width: 40px;
        height: 40px;
        border-radius: 12px;
        background: var(--accent-light);
        color: var(--accent);
        display: grid;
        place-items: center;
        flex-shrink: 0;
    }
    .icon-box svg { width: 20px; height: 20px; }
    .stat-number {
        font-size: 38px;
        font-weight: 850;
        letter-spacing: -1.5px;
        line-height: 1.2;
        margin-top: 14px;
    }
    .stat-description {
        color: var(--muted);
        font-size: 12px;
        margin-top: 4px;
    }
    
    .columns {
        display: grid;
        grid-template-columns: minmax(300px,.9fr) minmax(0,1.35fr);
        gap: 20px;
        align-items: start;
    }
    .card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 22px;
        padding: 28px;
        box-shadow: var(--shadow);
        min-width: 0;
    }
    .card-head {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 14px;
        margin-bottom: 20px;
    }
    .kicker {
        color: var(--accent);
        font-size: 10px;
        letter-spacing: 1.5px;
        font-weight: 850;
        text-transform: uppercase;
    }
    .card h2 {
        font-size: 20px;
        letter-spacing: -.6px;
        margin: 4px 0 7px;
    }
    .hint {
        font-size: 12px;
        color: var(--muted);
        margin: 0;
        line-height: 1.7;
    }
    .field { margin-top: 16px; }
    label {
        display: block;
        font-size: 12px;
        font-weight: 750;
        margin-bottom: 7px;
    }
    input, select, textarea {
        width: 100%;
        background: var(--surface-alt);
        color: var(--text);
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 12px 13px;
        outline: none;
        transition: border-color .2s, box-shadow .2s;
    }
    input, select { min-height: 46px; }
    textarea { min-height: 82px; resize: vertical; }
    input:focus, select:focus, textarea:focus {
        border-color: var(--accent);
        box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent) 18%, transparent);
    }
    .two { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        padding: 11px 15px;
        border-radius: 12px;
        border: 1px solid var(--border);
        background: var(--surface-alt);
        color: var(--text);
        font-size: 12px;
        font-weight: 800;
        transition: transform .18s, border-color .18s;
    }
    .btn:hover { transform: translateY(-1px); border-color: var(--accent); }
    .btn-primary {
        background: linear-gradient(110deg, #5d4ae0, #8571fa);
        border-color: transparent;
        color: #fff;
        box-shadow: 0 9px 22px rgba(103,86,232,.2);
    }
    .btn-small { padding: 8px 10px; font-size: 11px; }
    .btn-danger { color: #e34878; }
    .full { width: 100%; margin-top: 20px; }
    .message { padding: 13px 15px; border-radius: 12px; margin-bottom: 18px; font-size: 13px; }
    .success { background: #e8fbf2; color: #176b49; }
    .error { background: #fff0f2; color: #a52b47; }
    .badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 10px;
        border-radius: 999px;
        background: var(--accent-light);
        color: var(--accent);
        font-size: 10px;
        font-weight: 850;
        white-space: nowrap;
    }
    .badge.published { background: #e8fbf2; color: #176b49; }
    .badge.draft { background: var(--surface-alt); color: var(--muted); }
    .quiz-list { display: grid; gap: 12px; }
    .quiz-item {
        padding: 16px;
        border: 1px solid var(--border);
        border-radius: 16px;
        background: var(--surface);
        transition: border-color .2s, transform .2s;
    }
    .quiz-item:hover { border-color: color-mix(in srgb, var(--accent) 55%, var(--border)); transform: translateY(-1px); }
    .quiz-title { font-size: 15px; font-weight: 800; letter-spacing: -.3px; }
    .quiz-meta { font-size: 11px; color: var(--muted); margin-top: 7px; display: flex; gap: 8px; flex-wrap: wrap; }
    .quiz-actions { display: flex; gap: 7px; flex-wrap: wrap; margin-top: 13px; }
    .empty {
        border: 1px dashed var(--border);
        border-radius: 16px;
        padding: 30px 18px;
        text-align: center;
        color: var(--muted);
        font-size: 12px;
    }
    .empty strong { display: block; color: var(--text); font-size: 14px; margin-bottom: 6px; }
    .section-gap { margin-top: 20px; }
    .question-item { border: 1px solid var(--border); border-radius: 15px; padding: 16px; margin-top: 12px; }
    .question-text { font-size: 13px; font-weight: 750; white-space: pre-wrap; overflow-wrap: anywhere; }
    .options { display: grid; gap: 7px; margin-top: 12px; }
    .option { font-size: 11px; color: var(--muted); padding: 8px 10px; border-radius: 9px; background: var(--surface-alt); overflow-wrap: anywhere; }
    .option.correct { color: #176b49; background: #e8fbf2; }
    .topline { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; }
    .separator { height: 1px; background: var(--border); margin: 20px 0; }
    .back-link { display: inline-flex; color: var(--accent); font-size: 12px; font-weight: 800; margin-bottom: 14px; }
    .fine { font-size: 11px; color: var(--muted); margin-top: 9px; }

    .stage9-management {
        margin: 4px 0 12px;
        padding: 10px 12px;
        border: 1px solid var(--border);
        border-radius: 14px;
        background: var(--surface-alt);
    }
    .stage9-management summary {
        cursor: pointer;
        font-weight: 750;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 1.2px;
        color: var(--muted);
    }
    .stage9-management a {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 12px;
        margin-top: 6px;
        border-radius: 10px;
        color: var(--text);
        font-size: 13px;
        font-weight: 600;
    }
    .stage9-management a:hover {
        background: var(--accent-light);
        color: var(--accent);
    }

    .footer {
        margin-top: 50px;
        padding-top: 20px;
        border-top: 1px solid var(--border);
        font-size: 12px;
        color: var(--muted);
    }

    @media (max-width: 1000px) {
        .stats-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 900px) {
        .layout { grid-template-columns: 1fr; }
        .sidebar {
            border-right: 0;
            border-bottom: 1px solid var(--border);
            padding: 18px;
            gap: 18px;
        }
        .nav { display: flex; flex-wrap: wrap; }
        .sidebar-bottom { display: none; }
        .main { padding-top: 25px; }
        .topbar { margin-bottom: 35px; }
    }
    @media (max-width: 600px) {
        .main { padding: 24px 18px 60px; }
        .stats-grid, .columns, .two { grid-template-columns: 1fr; }
        .topbar { flex-wrap: wrap; }
    }
</style>

<link rel="stylesheet" href="<?= e(url('assets/css/ulearn-design-system.css?v=1')) ?>">
<script defer src="<?= e(url('assets/js/ulearn-motion.js?v=1')) ?>"></script>
</head>

<body>

<div class="layout">

    <aside class="sidebar" id="adminSidebar">
        <a class="brand" href="<?= e(url('admin/index.php')) ?>">
            u learn<span>.</span>
        </a>

        <nav aria-label="Admin navigation">
            <p class="nav-label">System management</p>
            <details open class="stage9-management">
                <summary>System Access</summary>
                <a href="<?= e(url('admin/users.php')) ?>">
                    <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    All Registered Users
                </a>
                <a href="<?= e(url('admin/change-requests.php')) ?>">
                    <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                    Academic Requests
                </a>
            </details>

            <p class="nav-label" style="margin-top: 18px;">Your workspace</p>

            <div class="nav ul-nav">
                <a href="<?= e(url('admin/index.php')) ?>">
                    <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                    <span>Overview</span>
                </a>
                <a href="<?= e(url('admin/lecturers.php')) ?>">
                    <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    <span>Lecturers</span>
                </a>
                <a href="<?= e(url('admin/academics.php')) ?>">
                    <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                    <span>Academics</span>
                </a>
                <a href="<?= e(url('admin/universities.php')) ?>">
                     <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="m2 9 10-5 10 5-10 5z"/><path d="M6 12v5c4 3 8 3 12 0v-5M22 9v7"/></svg>
                     <span>Universities</span>
                </a>
                <a class="active" href="<?= e(url('admin/quizzes.php')) ?>">
                    <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    <span>Quizzes</span>
                </a>
                <a href="<?= e(url('admin/assignments.php')) ?>">
                    <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-2 2"/><path d="M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l2-2"/></svg>
                    <span>Assignments</span>
                </a>
                <a href="<?= e(url('admin/announcements.php')) ?>">
                    <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                    <span>Announcements</span>
                </a>
                <a href="<?= e(url('admin/platform-settings.php')) ?>">
                    <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l-.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                    <span>Platform Settings</span>
                </a>
                <a href="<?= e(url('admin/profile.php')) ?>">
                    <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <span>My Profile &amp; Settings</span>
                </a>
                <a href="<?= e(url('admin/consultations.php')) ?>">
                    <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <span>Consultations</span>
                </a>
                <a href="<?= e(url('admin/kuppiya.php')) ?>">
                    <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3"/><path d="M3 20v-2a6 6 0 0 1 12 0v2"/><path d="M17 5a3 3 0 0 1 0 6"/><path d="M18 15a5 5 0 0 1 3 5"/></svg>
                    <span>Kuppiya Sessions</span>
                </a>
            </div>
        </nav>

        <div class="sidebar-bottom">
            <small>Signed in as</small>
            <strong><?= e($fullName) ?></strong>
            <small><?= e((string)$user['email']) ?></small>
        </div>
    </aside>

    <main class="main">
        <header class="topbar">
            <span class="eyebrow">
                U LEARN / QUIZ MANAGEMENT SPACE
            </span>

            <div class="topbar-right">
                <a class="notification-bell" href="<?= e(url('dashboard/notifications.php')) ?>"
                   aria-label="Notifications<?= $unreadNotifications ? ' (' . $unreadNotifications . ' unread)' : '' ?>"
                   title="Open notification center">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg>
                    <?php if ($unreadNotifications > 0): ?>
                        <span class="notification-count" aria-hidden="true"><?= $unreadNotifications > 99 ? '99+' : $unreadNotifications ?></span>
                    <?php endif; ?>
                </a>

                <button
                    type="button"
                    class="theme-toggle"
                    id="themeToggle"
                    aria-label="Switch appearance"
                    title="Switch appearance"
                >◐</button>

                <a class="avatar" href="<?= e(url('admin/profile.php')) ?>" aria-label="Open My Profile and Settings" title="My Profile & Settings">
                    <?= e($initial) ?>
                </a>

                <form method="post" action="<?= e(url('auth/logout.php')) ?>" style="margin:0;">
                    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                    <button type="submit" class="logout">
                        Log out ↗
                    </button>
                </form>
            </div>
        </header>

        <header class="hero">
            <div>
                <div style="font-size:12px; font-weight:750; text-transform:uppercase; letter-spacing:1.5px; opacity:0.8;">Administration / Assessments</div>
                <h1>Quiz management</h1>
                <p>Create module-based quizzes, write multiple-choice questions, review answer keys, and publish safely.</p>
            </div>
            <span class="pill">
                <?= e($roleLabel) ?>
            </span>
        </header>

        <?php if ($notice !== ''): ?><div class="message success" role="status"><?= e($notice) ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="message error" role="alert"><?= e($error) ?></div><?php endif; ?>

        <div class="stats-grid" style="margin-bottom: 30px;">
            <article class="stat-card">
                <div class="stat-top">
                    <span class="stat-label">Total quizzes</span>
                    <div class="icon-box">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    </div>
                </div>
                <div class="stat-number"><?= count($quizzes) ?></div>
                <div class="stat-description">Drafts and published quizzes</div>
            </article>
            <article class="stat-card">
                <div class="stat-top">
                    <span class="stat-label">Published</span>
                    <div class="icon-box">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    </div>
                </div>
                <div class="stat-number"><?= $publishedCount ?></div>
                <div class="stat-description">Available for student delivery</div>
            </article>
            <article class="stat-card">
                <div class="stat-top">
                    <span class="stat-label">Questions created</span>
                    <div class="icon-box">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    </div>
                </div>
                <div class="stat-number"><?= $totalQuestions ?></div>
                <div class="stat-description">Across all quizzes</div>
            </article>
        </div>

        <div class="columns">
            <section class="card">
                <div class="card-head">
                    <div>
                        <div class="kicker"><?= $editingQuiz ? 'Quiz settings' : 'Build an assessment' ?></div>
                        <h2><?= $editingQuiz ? 'Edit quiz details' : 'Create a quiz' ?></h2>
                        <p class="hint">Start with the module, format and timing. New quizzes remain drafts until published.</p>
                    </div>
                </div>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                    <input type="hidden" name="action" value="<?= $editingQuiz ? 'update_quiz' : 'create_quiz' ?>">
                    <?php if ($editingQuiz): ?><input type="hidden" name="quiz_id" value="<?= (int)$editingQuiz['id'] ?>"><?php endif; ?>
                    <div class="field"><label for="title">Quiz title</label><input id="title" name="title" maxlength="200" required placeholder="e.g. Sorting Algorithms — Practice 01" value="<?= e((string)($editingQuiz['title'] ?? '')) ?>"></div>
                    <div class="field">
                        <label for="module_id">Choose an existing module <span style="color:var(--muted);font-weight:500">(optional if adding one below)</span></label>
                        <select id="module_id" name="module_id">
                            <option value="">Choose existing module or create one below</option>
                            <?php foreach ($modules as $module): ?>
                            <option value="<?= (int)$module['id'] ?>" <?= (int)($editingQuiz['module_id'] ?? 0) === (int)$module['id'] ? 'selected' : '' ?>><?= e((string)$module['module_code']) ?> — <?= e((string)$module['module_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="fine">If your module is not listed, you can create it here and edit its details later under Academics.</p>
                    </div>
                    <div class="separator"></div>
                    <div class="kicker">Or create a module now</div>
                    <div class="two">
                        <div class="field"><label for="new_module_code">Module code</label><input id="new_module_code" name="new_module_code" maxlength="30" placeholder="e.g. IT2030" pattern="[A-Za-z0-9._-]+"></div>
                        <div class="field"><label for="new_module_name">Module name</label><input id="new_module_name" name="new_module_name" maxlength="200" placeholder="e.g. Algorithms"></div>
                    </div>
                    <p class="fine">Enter both fields only if creating a new module. The module will be added to Academics automatically.</p>
                    <div class="field"><label for="description">Description <span style="color:var(--muted);font-weight:500">(optional)</span></label><textarea id="description" name="description" maxlength="5000" placeholder="What should students practise or learn?"><?= e((string)($editingQuiz['description'] ?? '')) ?></textarea></div>
                    <div class="two">
                        <div class="field"><label for="quiz_type">Quiz category</label><select id="quiz_type" name="quiz_type"><option value="topic" <?= ($editingQuiz['quiz_type'] ?? 'topic') === 'topic' ? 'selected' : '' ?>>Topic practice</option><option value="general" <?= ($editingQuiz['quiz_type'] ?? '') === 'general' ? 'selected' : '' ?>>General practice</option><option value="exam" <?= ($editingQuiz['quiz_type'] ?? '') === 'exam' ? 'selected' : '' ?>>Exam preparation</option></select></div>
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
                    <p class="fine" id="availability_help">Unlimited availability is selected by default.</p>
                    <button class="btn btn-primary full" type="submit"><?= $editingQuiz ? 'Save quiz details' : 'Create quiz draft' ?> <span>→</span></button>
                    <?php if ($editingQuiz): ?><a class="btn full" href="<?= e(url('admin/quizzes.php?quiz=' . (int)$editingQuiz['id'])) ?>">Cancel edit</a><?php endif; ?>
                </form>
            </section>

            <section class="card">
            <?php if ($editingQuiz): ?>
                <a class="back-link" href="<?= e(url('admin/quizzes.php')) ?>">← All quizzes</a>
                <div class="card-head">
                    <div>
                        <div class="kicker">Question builder</div>
                        <h2><?= e((string)$editingQuiz['title']) ?></h2>
                        <p class="hint">Add four-option multiple-choice questions. Select the correct answer and include an explanation.</p>
                    </div>
                    <span class="badge <?= (int)$editingQuiz['is_published'] === 1 ? 'published' : 'draft' ?>"><?= (int)$editingQuiz['is_published'] === 1 ? 'Published' : 'Draft' ?></span>
                </div>
                <?php if ((int)$editingQuiz['is_published'] === 1): ?>
                  <div class="message success">This quiz is currently published. To safely edit an individual question, first move this quiz back to draft.</div>
                  <form method="post" class="section-gap" onsubmit="return confirm('Unpublish this quiz so you can edit individual questions?')">
                    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                    <input type="hidden" name="action" value="toggle_publish">
                    <input type="hidden" name="quiz_id" value="<?= (int)$editingQuiz['id'] ?>">
                    <button class="btn btn-primary" type="submit">Unpublish quiz to edit questions</button>
                    <a class="btn" href="<?= e(url('admin/quizzes.php')) ?>">Back to quiz list</a>
                  </form>
                  <div class="separator"></div>
                  <div class="kicker">Question review</div><h2>Questions (<?= count($questions) ?>)</h2>
                  <?php if (!$questions): ?><div class="empty"><strong>No questions added yet</strong></div><?php else: foreach ($questions as $index => $question): ?>
                    <article class="question-item">
                      <div class="topline"><div class="question-text"><?= ($index + 1) ?>. <?= e((string)$question['question_text']) ?></div><span class="badge"><?= e((string)$question['topic']) ?></span></div>
                      <div class="options"><?php foreach (($question['options'] ?? []) as $option): ?><div class="option <?= (int)$option['is_correct'] === 1 ? 'correct' : '' ?>"><?= (int)$option['is_correct'] === 1 ? '✓ Correct · ' : '' ?><?= e((string)$option['option_text']) ?></div><?php endforeach; ?></div>
                    </article>
                  <?php endforeach; endif; ?>
                <?php else: ?>
                <div class="separator"></div>
                <div class="kicker">Faster workflow</div><h2>Bulk import questions</h2>
                <p class="hint">Paste all questions at once or upload a Word/Text file.</p>
                <form method="post" enctype="multipart/form-data" class="section-gap" id="quiz-upload-form">
                    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="preview_bulk"><input type="hidden" name="quiz_id" value="<?= (int)$editingQuiz['id'] ?>">
                    <div class="field quiz-upload-field">
                      <label for="source_file">Upload your question document</label>
                      <div class="quiz-upload-zone" id="quiz-upload-zone">
                        <div class="quiz-upload-symbol" aria-hidden="true">&#8593;</div>
                        <strong>Drop a question file here</strong>
                        <span class="quiz-upload-description">Word DOCX or plain TXT</span>
                        <label for="source_file" class="quiz-file-picker">Choose a file</label>
                        <input id="source_file" name="source_file" type="file" accept=".txt,.docx" aria-describedby="quiz-upload-status">
                        <div id="quiz-upload-status" class="quiz-upload-status" role="status" aria-live="polite">No file selected</div>
                      </div>
                      <button class="btn btn-primary full" type="submit" id="quiz-upload-button" disabled>Upload file &amp; review questions &rarr;</button>
                    </div>
                    <div class="field"><label for="bulk_text">Paste question set</label><textarea id="bulk_text" name="bulk_text" style="min-height:180px" placeholder="Q1: What is binary search complexity?&#10;A) O(n)&#10;B) O(log n)&#10;C) O(n²)&#10;D) O(1)&#10;Correct: B&#10;Topic: Searching"><?= e($bulkText) ?></textarea></div>
                    <button class="btn full" type="submit" id="quiz-paste-preview-button">Preview pasted questions &rarr;</button>
                </form>
                <?php if ($bulkPreview): ?>
                <div id="import-preview-content">
                    <div class="separator"></div><div class="topline"><div><div class="kicker">Import preview</div><h2><?= count($bulkPreview) ?> question block(s) detected</h2></div><span class="badge <?= $bulkErrors ? 'draft' : 'published' ?>"><?= $bulkErrors ? count($bulkErrors) . ' issue(s)' : 'Ready to import' ?></span></div>
                    <?php foreach ($bulkErrors as $bulkIssue): ?><div class="message error"><?= e($bulkIssue) ?></div><?php endforeach; ?>
                    <form method="post" class="section-gap" id="bulk-import-editor">
                      <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                      <input type="hidden" name="action" value="import_bulk">
                      <input type="hidden" name="quiz_id" value="<?= (int)$editingQuiz['id'] ?>">
                      <textarea name="bulk_text" hidden id="edited-bulk-text"></textarea>
                      <div id="bulk-editable-list">
                      <?php foreach ($bulkPreview as $pi => $pq): ?>
                        <article class="question-item bulk-edit-question">
                          <div class="topline"><strong class="bulk-question-number">Question <?= $pi + 1 ?></strong><button type="button" class="btn btn-small btn-danger bulk-remove-question">Delete</button></div>
                          <div class="field"><label>Question text</label><textarea data-field="question" required maxlength="10000"><?= e($pq['question_text']) ?></textarea></div>
                          <div class="two"><div class="field"><label>Topic</label><input data-field="topic" required maxlength="150" value="<?= e($pq['topic']) ?>"></div><div class="field"><label>Difficulty</label><select data-field="difficulty"><option value="">Not set</option><?php foreach (['Easy','Medium','Advanced'] as $level): ?><option value="<?= e($level) ?>" <?= strcasecmp((string)$pq['difficulty'],$level)===0?'selected':'' ?>><?= e($level) ?></option><?php endforeach; ?></select></div></div>
                          <?php foreach (['A','B','C','D'] as $letter): ?>
                            <div class="field"><label>Option <?= $letter ?></label><textarea data-field="option-<?= $letter ?>" required><?= e($pq['options'][$letter]) ?></textarea></div>
                          <?php endforeach; ?>
                          <div class="field"><label>Correct answer</label><select data-field="correct" required><?php foreach (['A','B','C','D'] as $letter): ?><option value="<?= $letter ?>" <?= $pq['correct']===$letter?'selected':'' ?>><?= $letter ?></option><?php endforeach; ?></select></div>
                        </article>
                      <?php endforeach; ?>
                      </div>
                      <button class="btn btn-primary full" type="submit">Validate edits &amp; import questions</button>
                    </form>
                </div>
                <?php endif; ?>
                <div class="separator"></div><div class="kicker">Or add one question manually</div>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="add_question"><input type="hidden" name="quiz_id" value="<?= (int)$editingQuiz['id'] ?>">
                    <div class="field"><label for="question_text">Question</label><textarea id="question_text" name="question_text" required maxlength="10000" placeholder="Write a clear question..."></textarea></div>
                    <div class="two"><div class="field"><label for="topic">Topic tag</label><input id="topic" name="topic" required maxlength="150" placeholder="e.g. Sorting"></div><div class="field"><label for="difficulty">Difficulty</label><select id="difficulty" name="difficulty"><option value="">Not set</option><option>Easy</option><option>Medium</option><option>Advanced</option></select></div></div>
                    <div class="separator"></div>
                    <div class="kicker">Answer options</div>
                    <?php foreach (['a','b','c','d'] as $idx => $letter): ?><div class="field"><label for="option_<?= $letter ?>">Option <?= strtoupper($letter) ?></label><input id="option_<?= $letter ?>" name="option_<?= $letter ?>" maxlength="2000" required placeholder="Enter option <?= strtoupper($letter) ?>"></div><?php endforeach; ?>
                    <div class="field"><label for="correct_option">Correct answer</label><select id="correct_option" name="correct_option" required><option value="">Choose the correct option</option><option value="0">Option A</option><option value="1">Option B</option><option value="2">Option C</option><option value="3">Option D</option></select></div>
                    <div class="field"><label for="explanation">Answer explanation</label><textarea id="explanation" name="explanation" maxlength="10000" placeholder="Explain why the correct answer is right..."></textarea></div>
                    <button class="btn btn-primary full" type="submit">Add question <span>→</span></button>
                </form>
                <?php endif; ?>
                <div class="separator"></div>
                <div class="topline"><div><div class="kicker">Question review</div><h2>Questions (<?= count($questions) ?>)</h2></div></div>
                <?php if (!$questions): ?><div class="empty"><strong>No questions added yet</strong>Add your first question above.</div><?php else: foreach ($questions as $index => $question): ?>
                <article class="question-item">
                    <div class="topline"><div class="question-text"><?= ($index + 1) ?>. <?= e((string)$question['question_text']) ?></div><span class="badge"><?= e((string)$question['topic']) ?></span></div>
                    <div class="options"><?php foreach (($question['options'] ?? []) as $option): ?><div class="option <?= (int)$option['is_correct'] === 1 ? 'correct' : '' ?>"><?= (int)$option['is_correct'] === 1 ? '✓ Correct · ' : '' ?><?= e((string)$option['option_text']) ?></div><?php endforeach; ?></div>
                    <?php if ((int)$editingQuiz['is_published'] !== 1): ?><form method="post" class="section-gap" onsubmit="return confirm('Delete this question?')"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="delete_question"><input type="hidden" name="quiz_id" value="<?= (int)$editingQuiz['id'] ?>"><input type="hidden" name="question_id" value="<?= (int)$question['id'] ?>"><button class="btn btn-small btn-danger" type="submit">Delete question</button></form><?php endif; ?>
                </article>
                <?php endforeach; endif; ?>
            <?php else: ?>
                <div class="card-head">
                    <div>
                        <div class="kicker">Assessment library</div>
                        <h2>Your quizzes</h2>
                        <p class="hint">Open a quiz to manage its questions, review answers, or change publication status.</p>
                    </div>
                </div>
                <?php if (!$quizzes): ?><div class="empty"><strong>Your quiz library is ready</strong>No quizzes have been created yet. Use the form to create your first draft.</div><?php else: ?>
                <div class="quiz-list">
                <?php foreach ($quizzes as $quiz): ?><article class="quiz-item">
                    <div class="topline"><div class="quiz-title"><?= e((string)$quiz['title']) ?></div><span class="badge <?= (int)$quiz['is_published'] === 1 ? 'published' : 'draft' ?>"><?= (int)$quiz['is_published'] === 1 ? 'Published' : 'Draft' ?></span></div>
                    <div class="quiz-meta"><span><?= e((string)($quiz['module_code'] ?? 'No module')) ?></span><span>·</span><span><?= (int)$quiz['question_count'] ?> questions</span><span>·</span><span><?= (int)$quiz['duration_minutes'] ?> min</span><span>·</span><span><?= e(ucfirst((string)$quiz['quiz_type'])) ?></span></div>
                    <?php if (trim((string)$quiz['description']) !== ''): ?><p class="hint" style="margin-top:8px"><?= e((string)$quiz['description']) ?></p><?php endif; ?>
                    <div class="quiz-actions">
                        <a class="btn btn-small" href="<?= e(url('admin/quizzes.php?quiz=' . (int)$quiz['id'])) ?>">Manage questions</a>
                        <a class="btn btn-small" href="<?= e(url('admin/quizzes.php?edit=' . (int)$quiz['id'])) ?>">Edit details</a>
                        <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="toggle_publish"><input type="hidden" name="quiz_id" value="<?= (int)$quiz['id'] ?>"><button class="btn btn-small" type="submit"><?= (int)$quiz['is_published'] === 1 ? 'Unpublish' : 'Publish' ?></button></form>
                        <form method="post" onsubmit="return confirm('Permanently delete this quiz?')"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="delete_quiz"><input type="hidden" name="quiz_id" value="<?= (int)$quiz['id'] ?>"><button class="btn btn-small btn-danger" type="submit">Delete</button></form>
                    </div>
                </article><?php endforeach; ?>
                </div><?php endif; ?>
            <?php endif; ?>
            </section>
        </div>

        <footer class="footer">
            © <?= date('Y') ?> U Learn · Administration Workspace
        </footer>
    </main>
</div>

<script>
const themeButton = document.getElementById('themeToggle');
function updateThemeButton() {
    const theme = document.documentElement.dataset.theme;
    themeButton.textContent = theme === 'dark' ? '☀' : '☾';
}
themeButton.addEventListener('click', function () {
    const current = document.documentElement.dataset.theme;
    const next = current === 'dark' ? 'light' : 'dark';
    document.documentElement.dataset.theme = next;
    localStorage.setItem('ulearn-theme', next);
    updateThemeButton();
});
updateThemeButton();

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
        help.textContent = 'The quiz is available immediately. Set a closing date if you want it to expire automatically.';
    }
}
document.addEventListener('DOMContentLoaded', updateAvailabilityFields);
</script>

</body>
</html>