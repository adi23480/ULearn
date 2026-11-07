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

<div class="qa-page-intro"><a class="qa-back" href="<?= e(url('dashboard/group-peer/index.php')) ?>">← All questions</a><span class="qa-eyebrow">COMMUNITY / NEW QUESTION</span><h1>What would you like to ask?</h1><p class="muted">Choose a question type. We'll keep the form focused on what you need.</p></div>
<?php if ($error): ?><div class="panel error" role="alert"><?= e($error) ?></div><?php endif; ?>
<form method="post" enctype="multipart/form-data" class="panel qa-ask-form" id="qaAskForm">
<input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
<div class="qa-step-label"><span class="qa-step-number">1</span><div><h2>Choose your question type</h2><p class="muted">This helps you write a clearer question.</p></div></div>
<fieldset class="qa-type-grid" aria-label="Question type">
<legend class="sr-only">Question type</legend>
<label class="qa-type-card"><input type="radio" name="question_type" value="academic" checked><span class="qa-type-icon">◈</span><strong>Academic question</strong><small>Understand a topic or concept</small></label>
<label class="qa-type-card"><input type="radio" name="question_type" value="code"><span class="qa-type-icon">&lt;/&gt;</span><strong>Programming help</strong><small>Code, errors and debugging</small></label>
<label class="qa-type-card"><input type="radio" name="question_type" value="exam"><span class="qa-type-icon">▤</span><strong>Exam preparation</strong><small>Revision, past papers and quizzes</small></label>
<label class="qa-type-card"><input type="radio" name="question_type" value="discussion"><span class="qa-type-icon">◎</span><strong>Study discussion</strong><small>Ideas, advice and group learning</small></label>
</fieldset>
<div class="qa-step-label qa-step-spaced"><span class="qa-step-number">2</span><div><h2>Tell the community</h2><p class="muted">A descriptive title and a little context help others answer.</p></div></div>
<label for="qaTitle">Question title <span class="qa-required">*</span></label>
<input id="qaTitle" name="title" maxlength="255" value="<?= e((string)($_POST['title'] ?? '')) ?>" placeholder="e.g. Can someone explain how binary search works?" required>
<label for="qaDescription">Your question <span class="qa-required">*</span></label>
<textarea id="qaDescription" name="description" rows="6" placeholder="What are you trying to understand? What have you tried so far?" required><?= e((string)($_POST['description'] ?? '')) ?></textarea>
<div class="qa-optional-section" id="qaCodeFields" hidden>
<div class="qa-section-head"><strong>Programming details</strong><span class="muted">Shown for programming questions</span></div>
<label for="qaCode">Code snippet <span class="muted">(optional)</span></label>
<textarea id="qaCode" name="code_snippet" rows="6" placeholder="Paste the relevant part of your code here..."><?= e((string)($_POST['code_snippet'] ?? '')) ?></textarea>
<label for="qaLanguage">Programming language <span class="muted">(optional)</span></label>
<select id="qaLanguage" name="code_language"><option value="">Select a language</option><?php foreach (['Python','JavaScript','PHP','Java','C','C++','C#','SQL','HTML/CSS','Other'] as $language): ?><option value="<?= e($language) ?>" <?= ($_POST['code_language'] ?? '') === $language ? 'selected' : '' ?>><?= e($language) ?></option><?php endforeach; ?></select>
</div>
<details class="qa-extra"><summary>Add an image <span class="muted">(optional)</span></summary><p class="muted">Upload a screenshot, diagram or other helpful image (JPG, PNG, GIF or WEBP, up to 5 MB).</p><input type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp"></details>
<div class="qa-submit-row"><span class="muted">Your question will be visible to the ULearn community.</span><button type="submit">Publish question <span aria-hidden="true">→</span></button></div>
</form>
<script>(function(){const radios=document.querySelectorAll('input[name="question_type"]');const code=document.getElementById('qaCodeFields');const title=document.getElementById('qaTitle');const desc=document.getElementById('qaDescription');const examples={academic:['e.g. How does binary search work?','Explain the concept you are struggling with and where you got stuck...'],code:['e.g. Why is my PHP query returning no results?','Describe what you expected, what happened, and any error message...'],exam:['e.g. How should I revise database normalization?','Mention the subject, exam topic and what you need help with...'],discussion:['e.g. What is the best way to organize a group study session?','Share your idea or question so others can contribute...']};function update(){const selected=document.querySelector('input[name="question_type"]:checked');const type=selected?selected.value:'academic';code.hidden=type!=='code';title.placeholder=examples[type][0];desc.placeholder=examples[type][1];}radios.forEach(r=>r.addEventListener('change',update));update();})();</script>
<?php qa_footer(); ?>
