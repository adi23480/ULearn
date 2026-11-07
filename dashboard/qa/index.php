<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';

$u = require_login();
$pdo = db();

$userId = (int)$u['id'];
$theme = (string)($u['theme'] ?? 'system');
$role = (string)$u['role'];

$error = '';
$success = '';

/* --------------------------------
   GET ACTIVE MODULES
-------------------------------- */

$modules = $pdo->query("
    SELECT id, module_code, module_name
    FROM academic_modules
    WHERE is_active = 1
    ORDER BY module_name
")->fetchAll();

/* --------------------------------
   HANDLE FORM ACTIONS
-------------------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_check();

    $action = (string)($_POST['action'] ?? '');

    try {
        /* CHANGE THEME */

if ($action === 'theme') {

    $newTheme = (string)($_POST['theme'] ?? '');

    if (!in_array($newTheme, ['light', 'dark'], true)) {
        throw new RuntimeException('Invalid appearance setting.');
    }

    $stmt = $pdo->prepare(
        "UPDATE users SET theme = ? WHERE id = ?"
    );

    $stmt->execute([$newTheme, $userId]);

    $_SESSION['qa_success'] = 'Appearance preference saved successfully.';
}

/* POST QUESTION */

        /* POST QUESTION */

        if ($action === 'ask') {

            $title = trim((string)($_POST['title'] ?? ''));
            $question = trim((string)($_POST['question'] ?? ''));
            $moduleId = filter_var(
                $_POST['module_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            if ($title === '' || $question === '' || !$moduleId) {
                throw new RuntimeException('Please complete all question fields.');
            }

            if (mb_strlen($title) > 200) {
                throw new RuntimeException('Question title is too long.');
            }

            $stmt = $pdo->prepare("
                SELECT id FROM academic_modules
                WHERE id = ? AND is_active = 1
            ");
            $stmt->execute([$moduleId]);

            if (!$stmt->fetch()) {
                throw new RuntimeException('Invalid module selected.');
            }

            $stmt = $pdo->prepare("
                INSERT INTO qa_questions
                    (user_id, module_id, title, question)
                VALUES (?, ?, ?, ?)
            ");

            $stmt->execute([
                $userId,
                $moduleId,
                $title,
                $question
            ]);

            $_SESSION['qa_success'] = 'Question posted successfully!';
        }

        /* POST ANSWER */

        elseif ($action === 'answer') {

            $questionId = (int)($_POST['question_id'] ?? 0);
            $answer = trim((string)($_POST['answer'] ?? ''));

            if ($questionId <= 0 || $answer === '') {
                throw new RuntimeException('Please enter your answer.');
            }

            $stmt = $pdo->prepare("
                SELECT id FROM qa_questions WHERE id = ?
            ");
            $stmt->execute([$questionId]);

            if (!$stmt->fetch()) {
                throw new RuntimeException('Question not found.');
            }

            $stmt = $pdo->prepare("
                INSERT INTO qa_answers
                    (question_id, user_id, answer)
                VALUES (?, ?, ?)
            ");

            $stmt->execute([
                $questionId,
                $userId,
                $answer
            ]);

            $_SESSION['qa_success'] = 'Your answer was posted!';
        }

        /* LIKE / UNLIKE ANSWER */

        elseif ($action === 'like') {

            $answerId = (int)($_POST['answer_id'] ?? 0);

            $stmt = $pdo->prepare("
                SELECT id FROM qa_answers WHERE id = ?
            ");
            $stmt->execute([$answerId]);

            if (!$stmt->fetch()) {
                throw new RuntimeException('Answer not found.');
            }

            $stmt = $pdo->prepare("
                SELECT id FROM qa_answer_likes
                WHERE answer_id = ? AND user_id = ?
            ");
            $stmt->execute([$answerId, $userId]);

            if ($stmt->fetch()) {

                $stmt = $pdo->prepare("
                    DELETE FROM qa_answer_likes
                    WHERE answer_id = ? AND user_id = ?
                ");

                $stmt->execute([$answerId, $userId]);

            } else {

                $stmt = $pdo->prepare("
                    INSERT INTO qa_answer_likes
                        (answer_id, user_id)
                    VALUES (?, ?)
                ");

                $stmt->execute([$answerId, $userId]);
            }

            $_SESSION['qa_success'] = 'Like updated.';
        }

        /* SELECT BEST ANSWER */

        elseif ($action === 'best') {

            $questionId = (int)($_POST['question_id'] ?? 0);
            $answerId = (int)($_POST['answer_id'] ?? 0);

            $stmt = $pdo->prepare("
                SELECT user_id FROM qa_questions WHERE id = ?
            ");
            $stmt->execute([$questionId]);

            $questionOwner = $stmt->fetch();

            if (!$questionOwner ||
                (int)$questionOwner['user_id'] !== $userId) {
                throw new RuntimeException('Only the question owner can select the best answer.');
            }

            $stmt = $pdo->prepare("
                SELECT id FROM qa_answers
                WHERE id = ? AND question_id = ?
            ");
            $stmt->execute([$answerId, $questionId]);

            if (!$stmt->fetch()) {
                throw new RuntimeException('Invalid answer selected.');
            }

            $stmt = $pdo->prepare("
                UPDATE qa_questions
                SET best_answer_id = ?
                WHERE id = ?
            ");

            $stmt->execute([$answerId, $questionId]);

            $_SESSION['qa_success'] = 'Best answer selected successfully!';
        }

        /* REMOVE BEST ANSWER */

        elseif ($action === 'remove_best') {

            $questionId = (int)($_POST['question_id'] ?? 0);

            $stmt = $pdo->prepare("
                UPDATE qa_questions
                SET best_answer_id = NULL
                WHERE id = ? AND user_id = ?
            ");

            $stmt->execute([$questionId, $userId]);

            if ($stmt->rowCount() === 0) {
                $check = $pdo->prepare("
                    SELECT id FROM qa_questions
                    WHERE id = ? AND user_id = ?
                ");
                $check->execute([$questionId, $userId]);

                if (!$check->fetch()) {
                    throw new RuntimeException('You cannot change this question.');
                }
            }

            $_SESSION['qa_success'] = 'Best answer removed.';
        }

        /* DELETE QUESTION */

        elseif ($action === 'delete_question') {

            $questionId = (int)($_POST['question_id'] ?? 0);

            $stmt = $pdo->prepare("
                DELETE FROM qa_questions
                WHERE id = ?
                AND (user_id = ? OR ? IN ('admin', 'main_admin'))
            ");

            $stmt->execute([$questionId, $userId, $role]);

            if ($stmt->rowCount() === 0) {
                throw new RuntimeException('You cannot delete this question.');
            }

            $_SESSION['qa_success'] = 'Question deleted successfully.';
        }

        /* DELETE ANSWER */

        elseif ($action === 'delete_answer') {

    $answerId = (int)($_POST['answer_id'] ?? 0);

    // Check permission before deleting
    $stmt = $pdo->prepare("
        SELECT id
        FROM qa_answers
        WHERE id = ?
        AND (
            user_id = ?
            OR ? IN ('admin', 'main_admin')
        )
    ");

    $stmt->execute([$answerId, $userId, $role]);

    if (!$stmt->fetch()) {
        throw new RuntimeException('You cannot delete this answer.');
    }

    // Clear best answer reference
    $stmt = $pdo->prepare("
        UPDATE qa_questions
        SET best_answer_id = NULL
        WHERE best_answer_id = ?
    ");

    $stmt->execute([$answerId]);

    // Delete answer
    $stmt = $pdo->prepare("
        DELETE FROM qa_answers
        WHERE id = ?
    ");

    $stmt->execute([$answerId]);

    $_SESSION['qa_success'] = 'Answer deleted successfully.';
}

else {
    throw new RuntimeException('Invalid action.');
}

       } catch (Throwable $e) {

        if ($e instanceof RuntimeException) {
            $_SESSION['qa_error'] = $e->getMessage();
        } else {
            error_log('Q&A error: ' . $e->getMessage());
            $_SESSION['qa_error'] = 'Something went wrong. Please try again.';
        }
    }

    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;

}
/* --------------------------------
   SUCCESS MESSAGE
-------------------------------- */

if (isset($_SESSION['qa_success'])) {
    $success = (string)$_SESSION['qa_success'];
    unset($_SESSION['qa_success']);
}
if (isset($_SESSION['qa_error'])) {
    $error = (string)$_SESSION['qa_error'];
    unset($_SESSION['qa_error']);
}

/* --------------------------------
   MODULE FILTER
-------------------------------- */

$selectedModule = filter_var(
    $_GET['module_id'] ?? '',
    FILTER_VALIDATE_INT
);

if ($selectedModule !== false && $selectedModule !== null && $selectedModule > 0) {

    $checkModule = $pdo->prepare("
        SELECT id FROM academic_modules
        WHERE id = ? AND is_active = 1
    ");
    $checkModule->execute([$selectedModule]);

    if (!$checkModule->fetch()) {
        $selectedModule = null;
    }

} else {
    $selectedModule = null;
}

/* --------------------------------
   GET QUESTIONS
-------------------------------- */

$sql = "
    SELECT
        q.id,
        q.user_id,
        q.module_id,
        q.title,
        q.question,
        q.best_answer_id,
        q.created_at,
        u.full_name,
        m.module_code,
        m.module_name,

        (SELECT COUNT(*)
         FROM qa_answers a
         WHERE a.question_id = q.id) AS answer_count

    FROM qa_questions q

    JOIN users u ON u.id = q.user_id
    JOIN academic_modules m ON m.id = q.module_id
";

if ($selectedModule !== null) {
    $sql .= " WHERE q.module_id = :module_id";
}

$sql .= " ORDER BY q.created_at DESC";

$stmt = $pdo->prepare($sql);

if ($selectedModule !== null) {
    $stmt->bindValue(':module_id', $selectedModule, PDO::PARAM_INT);
}

$stmt->execute();
$questions = $stmt->fetchAll();

/* --------------------------------
   GET ANSWERS FOR EACH QUESTION
-------------------------------- */

$answersByQuestion = [];

$answerStmt = $pdo->prepare("
    SELECT
        a.id,
        a.question_id,
        a.user_id,
        a.answer,
        a.created_at,
        u.full_name,

        (SELECT COUNT(*)
         FROM qa_answer_likes l
         WHERE l.answer_id = a.id) AS like_count,

        (SELECT COUNT(*)
         FROM qa_answer_likes l
         WHERE l.answer_id = a.id AND l.user_id = ?) AS liked

    FROM qa_answers a
    JOIN users u ON u.id = a.user_id
    WHERE a.question_id = ?
    ORDER BY a.created_at ASC
");

foreach ($questions as $question) {

    $answerStmt->execute([$userId, $question['id']]);

    $answersByQuestion[(int)$question['id']] = $answerStmt->fetchAll();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Group Peer & Q&A — ULearn</title>
<script>
try {
    const saved = <?= json_encode($theme) ?>;

    const selected = saved === 'system' ? null : saved;

    const theme = selected === 'light' || selected === 'dark'
        ? selected
        : (
            window.matchMedia('(prefers-color-scheme: dark)').matches
                ? 'dark'
                : 'light'
        );

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
    --danger: #dc4161;
    --green: #168454;
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
    --danger: #ff718b;
    --green: #55d69a;
}

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    background: var(--bg);
    color: var(--text);
    font-family: Arial, sans-serif;
    line-height: 1.6;
}

.container {
    max-width: 1050px;
    margin: auto;
    padding: 35px 20px 70px;
}

.top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    margin-bottom: 30px;
    flex-wrap: wrap;
}

.top h1 {
    margin: 0;
    font-size: 30px;
}

.top p {
    color: var(--muted);
    margin: 5px 0;
}

.back {
    color: var(--accent);
    font-weight: bold;
    text-decoration: none;
}

.card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 17px;
    padding: 23px;
    margin-bottom: 20px;
}

h2 {
    font-size: 21px;
    margin-top: 0;
}

label {
    display: block;
    font-weight: bold;
    font-size: 14px;
    margin: 15px 0 7px;
}

input, textarea, select {
    width: 100%;
    padding: 13px;
    border: 1px solid var(--border);
    border-radius: 10px;
    font: inherit;
    background: var(--surface-alt);
    color: var(--text);
}

textarea {
    min-height: 110px;
    resize: vertical;
}

.btn {
    display: inline-block;
    border: 0;
    border-radius: 9px;
    padding: 11px 17px;
    background: var(--accent);
    color: white;
    font-weight: bold;
    cursor: pointer;
    text-decoration: none;
    font-size: 13px;
}

.btn:hover {
    opacity: .88;
}

.btn-light {
    background: var(--accent-light);
    color: var(--accent);
}

.btn-danger {
    background: #ffedf0;
    color: var(--danger);
}

.btn-green {
    background: #e8f8ef;
    color: var(--green);
}

.message {
    padding: 13px 16px;
    border-radius: 10px;
    margin-bottom: 18px;
}

.success {
    background: #e9f8ef;
    color: #176b43;
}

.error {
    background: #ffedf0;
    color: #a32143;
}

.question-title {
    font-size: 20px;
    margin: 12px 0;
}

.meta {
    color: var(--muted);
    font-size: 13px;
}

.module {
    display: inline-block;
    background: var(--light);
    color: var(--accent);
    padding: 5px 11px;
    border-radius: 30px;
    font-size: 12px;
    font-weight: bold;
}

.question-text {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    margin: 15px 0;
}

.answer-box {
    border-top: 1px solid var(--border);
    padding: 17px 0;
}

.answer-text {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}

.answer-box.best {
    background: var(--surface-alt);
    border: 1px solid var(--green);
    border-radius: 12px;
    padding: 15px;
    margin: 12px 0;
}

.best-label {
    color: var(--green);
    font-size: 12px;
    font-weight: bold;
}

.inline-form {
    display: inline-block;
    margin: 5px 5px 5px 0;
}

.actions {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
    margin-top: 12px;
}

.empty {
    text-align: center;
    color: var(--muted);
    padding: 35px 15px;
}

.filter {
    display: flex;
    gap: 12px;
    align-items: end;
    flex-wrap: wrap;
}

.filter div {
    flex: 1;
    min-width: 200px;
}

@media(max-width:600px) {
    .container {
        padding: 20px 13px;
    }

    .card {
        padding: 17px;
    }

    .top h1 {
        font-size: 25px;
    }
    .theme-toggle-form {
    margin: 0;
    display: flex;
}

.theme-toggle {
    width: 42px;
    height: 42px;
    display: inline-grid;
    place-items: center;
    border: 1px solid var(--border);
    border-radius: 12px;
    background: var(--surface);
    color: var(--accent);
    cursor: pointer;
    font-size: 23px;
}

.theme-toggle:hover {
    background: var(--accent-light);
}

.theme-icon {
    font-size: 23px;
    line-height: 1;
}
.answer-box.best {
    background: var(--surface-alt);
    border: 1px solid var(--green);
    border-radius: 12px;
    padding: 15px;
    margin: 12px 0;
}

/* Dark Mode Colors */
:root[data-theme="dark"] .success {
    background: #193629;
    color: #8de0b2;
}

:root[data-theme="dark"] .error {
    background: #3a202b;
    color: #ff9aaf;
}

:root[data-theme="dark"] .btn-green {
    background: #193629;
    color: #8de0b2;
}

:root[data-theme="dark"] .btn-danger {
    background: #3a202b;
    color: #ff9aaf;
}
</style>
}
</style>
</head>

<body>

<div class="container">

    <header class="top">
    <div>
        <h1>Group Peer & Q&A</h1>
        <p>Ask questions, share knowledge and learn together.</p>
    </div>

    <div style="display:flex; align-items:center; gap:15px;">

        <form method="post" action="" class="theme-toggle-form">

            <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">

            <input type="hidden" name="action" value="theme">

            <input type="hidden"
                   name="theme"
                   value="<?= $theme === 'dark' ? 'light' : 'dark' ?>">

            <button type="submit"
                    class="theme-toggle"
                    title="<?= $theme === 'dark' ? 'Switch to Light Mode' : 'Switch to Dark Mode' ?>"
                    aria-label="Toggle Dark Mode">

                <span class="theme-icon">
                    <?= $theme === 'dark' ? '☀' : '☾' ?>
                </span>

            </button>

        </form>

        <a class="back" href="<?= e(url('dashboard/index.php')) ?>">
            ← Back to Dashboard
        </a>

    </div>
</header>

        <a class="back" href="<?= e(url('dashboard/index.php')) ?>">
            ← Back to Dashboard
        </a>
    </header>

    <?php if ($success): ?>
        <div class="message success"><?= e($success) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="message error"><?= e($error) ?></div>
    <?php endif; ?>

    <!-- ASK QUESTION -->

    <section class="card">

        <h2>Ask a Question</h2>

        <form method="post">

            <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
            <input type="hidden" name="action" value="ask">

            <label for="module_id">Select Module</label>

            <select name="module_id" id="module_id" required>
                <option value="">Choose a module</option>

                <?php foreach ($modules as $module): ?>
                    <option value="<?= (int)$module['id'] ?>">
                        <?= e($module['module_code']) ?> -
                        <?= e($module['module_name']) ?>
                    </option>
                <?php endforeach; ?>

            </select>

            <label for="title">Question Title</label>

            <input
                type="text"
                name="title"
                id="title"
                maxlength="200"
                placeholder="Enter your question title"
                required
            >

            <label for="question">Question Details</label>

            <textarea
                name="question"
                id="question"
                placeholder="Explain your question here..."
                required
            ></textarea>

            <button type="submit" class="btn" style="margin-top:15px">
                Post Question +
            </button>

        </form>

    </section>

    <!-- MODULE FILTER -->

    <section class="card">

        <h2>Explore Questions</h2>

        <form method="get" class="filter">

            <div>
                <label for="filter_module">Filter by Module</label>

                <select name="module_id" id="filter_module">

                    <option value="">All Modules</option>

                    <?php foreach ($modules as $module): ?>

                        <option
                            value="<?= (int)$module['id'] ?>"
                            <?= $selectedModule === (int)$module['id'] ? 'selected' : '' ?>
                        >
                            <?= e($module['module_code']) ?>
                        </option>

                    <?php endforeach; ?>

                </select>
            </div>

            <button class="btn" type="submit">Filter</button>

            <a href="<?= e(url('dashboard/qa/index.php')) ?>" class="btn btn-light">
                Clear
            </a>

        </form>

    </section>

    <!-- QUESTIONS LIST -->

    <?php if (!$questions): ?>

        <section class="card empty">
            <h2>No Questions Yet</h2>
            <p>Be the first student to ask a question!</p>
        </section>

    <?php else: ?>

        <?php foreach ($questions as $q): ?>

            <?php
                $questionId = (int)$q['id'];
                $isOwner = (int)$q['user_id'] === $userId;
                $isAdmin = in_array($role, ['admin', 'main_admin'], true);
                $answers = $answersByQuestion[$questionId] ?? [];
            ?>

            <article class="card">

                <span class="module">
                    <?= e($q['module_code']) ?>
                </span>

                <h2 class="question-title">
                    <?= e($q['title']) ?>
                </h2>

                <div class="meta">
                    Asked by <?= e($q['full_name']) ?>
                    · <?= e($q['created_at']) ?>
                    · <?= (int)$q['answer_count'] ?> Answers
                </div>

                <div class="question-text">
                    <?= e($q['question']) ?>
                </div>

                <?php if ($isOwner || $isAdmin): ?>

                    <form method="post" class="inline-form"
                          onsubmit="return confirm('Delete this question and all its answers?');">

                        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                        <input type="hidden" name="action" value="delete_question">
                        <input type="hidden" name="question_id" value="<?= $questionId ?>">

                        <button class="btn btn-danger" type="submit">
                            Delete Question
                        </button>

                    </form>

                <?php endif; ?>

                <hr style="border:0;border-top:1px solid #e7e8f0;margin:20px 0">

                <h3>Answers (<?= count($answers) ?>)</h3>

                <?php foreach ($answers as $a): ?>

                    <?php
                        $answerId = (int)$a['id'];
                        $isAnswerOwner = (int)$a['user_id'] === $userId;
                        $isBest = (int)($q['best_answer_id'] ?? 0) === $answerId;
                    ?>

                    <div class="answer-box <?= $isBest ? 'best' : '' ?>">

                        <?php if ($isBest): ?>
                            <div class="best-label">
                                ✓ BEST ANSWER
                            </div>
                        <?php endif; ?>

                        <strong><?= e($a['full_name']) ?></strong>

                        <div class="meta">
                            <?= e($a['created_at']) ?>
                        </div>

                        <div class="answer-text" style="margin:12px 0">
                            <?= e($a['answer']) ?>
                        </div>

                        <div class="actions">

                            <!-- LIKE -->

                            <form method="post" class="inline-form">

                                <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                                <input type="hidden" name="action" value="like">
                                <input type="hidden" name="answer_id" value="<?= $answerId ?>">

                                <button type="submit" class="btn btn-light">
                                    <?= (int)$a['liked'] ? '♥ Liked' : '♡ Like' ?>
                                    (<?= (int)$a['like_count'] ?>)
                                </button>

                            </form>

                            <!-- BEST ANSWER -->

                            <?php if ($isOwner && !$isBest): ?>

                                <form method="post" class="inline-form">

                                    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                                    <input type="hidden" name="action" value="best">
                                    <input type="hidden" name="question_id" value="<?= $questionId ?>">
                                    <input type="hidden" name="answer_id" value="<?= $answerId ?>">

                                    <button type="submit" class="btn btn-green">
                                        Select Best Answer
                                    </button>

                                </form>

                            <?php endif; ?>

                            <?php if ($isOwner && $isBest): ?>

                                <form method="post" class="inline-form">

                                    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                                    <input type="hidden" name="action" value="remove_best">
                                    <input type="hidden" name="question_id" value="<?= $questionId ?>">

                                    <button type="submit" class="btn btn-light">
                                        Remove Best Answer
                                    </button>

                                </form>

                            <?php endif; ?>

                            <!-- DELETE ANSWER -->

                            <?php if ($isAnswerOwner || $isAdmin): ?>

                                <form method="post" class="inline-form"
                                      onsubmit="return confirm('Delete this answer?');">

                                    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                                    <input type="hidden" name="action" value="delete_answer">
                                    <input type="hidden" name="answer_id" value="<?= $answerId ?>">

                                    <button type="submit" class="btn btn-danger">
                                        Delete Answer
                                    </button>

                                </form>

                            <?php endif; ?>

                        </div>

                    </div>

                <?php endforeach; ?>

                <!-- ADD ANSWER -->

                <form method="post" style="margin-top:18px">

                    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                    <input type="hidden" name="action" value="answer">
                    <input type="hidden" name="question_id" value="<?= $questionId ?>">

                    <label>Write Your Answer</label>

                    <textarea
                        name="answer"
                        placeholder="Share your knowledge..."
                        required
                    ></textarea>

                    <button type="submit" class="btn" style="margin-top:12px">
                        Submit Answer
                    </button>

                </form>

            </article>

        <?php endforeach; ?>

    <?php endif; ?>

</div>

</body>
</html>
