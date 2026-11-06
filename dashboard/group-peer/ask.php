<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

$user = qa_require_user();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        qa_post();

        $title = trim((string)($_POST['title'] ?? ''));
        $desc = trim((string)($_POST['description'] ?? ''));
        $code = trim((string)($_POST['code_snippet'] ?? ''));
        $lang = trim((string)($_POST['code_language'] ?? ''));

        if ($title === '' || $desc === '') {
            throw new RuntimeException('Title and description are required.');
        }

        $pdo = db();
        $pdo->beginTransaction();

        $pdo->prepare(
            'INSERT INTO qa_questions
            (user_id, title, description, code_snippet, code_language)
            VALUES (?, ?, ?, ?, ?)'
        )->execute([
            (int)$user['id'],
            $title,
            $desc,
            $code ?: null,
            $lang ?: null
        ]);

        $id = (int)$pdo->lastInsertId();

        $file = qa_image_upload();

        if ($file) {
            qa_save_attachment(
                $pdo,
                $id,
                null,
                (int)$user['id'],
                $file
            );
        }

        $pdo->commit();

        qa_redirect('view.php?id=' . $id);

    } catch (Throwable $e) {

        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $error = $e->getMessage();
    }
}

qa_header('Ask a Question');
?>

<!-- Navigation button -->
<div class="actions" style="margin-bottom:15px;">

    <a class="btn"
       href="<?= e(url('dashboard/group-peer/index.php')) ?>">
        ← View All Questions
    </a>

</div>

<section class="panel">

    <h3>Ask a Question</h3>

    <p class="muted">
        Share your questions with other students and lecturers.
    </p>

    <?php if ($error): ?>

        <p class="error">
            <?= e($error) ?>
        </p>

    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">

        <input
            type="hidden"
            name="csrf"
            value="<?= e(csrf()) ?>"
        >

        <label>Title</label>

        <input
            name="title"
            maxlength="255"
            placeholder="Enter your question title"
            required
        >

        <label>Description</label>

        <textarea
            name="description"
            placeholder="Explain your question..."
            required
        ></textarea>

        <label>Code snippet (optional)</label>

        <textarea
            name="code_snippet"
            placeholder="Write your code here..."
        ></textarea>

        <label>Code language (optional)</label>

        <input
            name="code_language"
            maxlength="50"
            placeholder="PHP, JavaScript..."
        >

        <label>Image (optional, max 5 MB)</label>

        <input
            type="file"
            name="image"
            accept="image/jpeg,image/png,image/gif,image/webp"
        >

        <button type="submit">
            Post Question
        </button>

    </form>

</section>

<?php qa_footer(); ?>
