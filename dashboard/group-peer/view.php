
<?php

require_once __DIR__ . '/common.php';

$user = qa_require_user();
$pdo = db();

$id = (int)($_GET['id'] ?? 0);

$st = $pdo->prepare(
    'SELECT q.*, u.full_name, u.role,
    (SELECT COALESCE(SUM(v.vote_value),0)
     FROM qa_votes v WHERE v.question_id=q.id) votes,
    (SELECT vote_value FROM qa_votes v
     WHERE v.question_id=q.id AND v.user_id=?) my_vote
     FROM qa_questions q
     JOIN users u ON u.id=q.user_id
     WHERE q.id=?'
);

$st->execute([(int)$user['id'], $id]);
$q = $st->fetch();

if (!$q) {
    http_response_code(404);
    exit('Question not found.');
}

$answers = $pdo->prepare(
    "SELECT a.*, u.full_name, u.role,
    lp.is_active lecturer_active
    FROM qa_answers a
    JOIN users u ON u.id=a.user_id
    LEFT JOIN lecturer_profiles lp
    ON lp.user_id=u.id AND lp.is_active=1
    WHERE a.question_id=?
    ORDER BY (a.id = ?) DESC, a.created_at ASC"
);

$answers->execute([
    $id,
    (int)($q['solved_answer_id'] ?? 0)
]);

$answers = $answers->fetchAll();

$qfiles = qa_attachments($pdo, 'question_id', $id);

qa_header('Question');
?>

<style>

/* ==================================
   COMMON BUTTON STYLE
================================== */

.btn-upvote,
.btn-downvote,
.btn-edit,
.btn-delete,
.btn-solved {
    display: inline-block !important;
    padding: 9px 16px !important;
    margin: 5px !important;
    border-radius: 8px !important;
    font-weight: 600 !important;
    cursor: pointer !important;
    text-decoration: none !important;
    transition: all .3s ease !important;
}


/* ==================================
   UPVOTE - BLUE
================================== */

.actions button.btn-upvote,
button.btn-upvote {
    background: #dbeafe !important;
    background-color: #dbeafe !important;
    color: #2563eb !important;
    border: 1px solid #2563eb !important;
}

.actions button.btn-upvote:hover,
button.btn-upvote:hover {
    background: #2563eb !important;
    background-color: #2563eb !important;
    border-color: #2563eb !important;
    color: #ffffff !important;
    transform: translateY(-2px);
}


/* ==================================
   DOWNVOTE - ORANGE
================================== */

.actions button.btn-downvote,
button.btn-downvote {
    background: #ffedd5 !important;
    background-color: #ffedd5 !important;
    color: #ea580c !important;
    border: 1px solid #ea580c !important;
}

.actions button.btn-downvote:hover,
button.btn-downvote:hover {
    background: #ea580c !important;
    background-color: #ea580c !important;
    border-color: #ea580c !important;
    color: #ffffff !important;
    transform: translateY(-2px);
}


/* ==================================
   EDIT - PURPLE
================================== */

.actions a.btn-edit,
a.btn-edit {
    background: #f0edff !important;
    background-color: #f0edff !important;
    color: #6856e8 !important;
    border: 1px solid #6856e8 !important;
}

.actions a.btn-edit:hover,
a.btn-edit:hover {
    background: #6856e8 !important;
    background-color: #6856e8 !important;
    border-color: #6856e8 !important;
    color: #ffffff !important;
    transform: translateY(-2px);
}


/* ==================================
   DELETE - RED
================================== */

.actions button.btn-delete,
button.btn-delete {
    background: #fee2e2 !important;
    background-color: #fee2e2 !important;
    color: #dc2626 !important;
    border: 1px solid #dc2626 !important;
}

.actions button.btn-delete:hover,
button.btn-delete:hover {
    background: #dc2626 !important;
    background-color: #dc2626 !important;
    border-color: #dc2626 !important;
    color: #ffffff !important;
    transform: translateY(-2px);
}


/* ==================================
   MARK AS SOLVED - GREEN
================================== */

.actions button.btn-solved,
button.btn-solved {
    background: #dcfce7 !important;
    background-color: #dcfce7 !important;
    color: #16a34a !important;
    border: 1px solid #16a34a !important;
}

.actions button.btn-solved:hover,
button.btn-solved:hover {
    background: #16a34a !important;
    background-color: #16a34a !important;
    border-color: #16a34a !important;
    color: #ffffff !important;
    transform: translateY(-2px);
}


/* ==================================
   ASK ANOTHER QUESTION
================================== */

.btn-ask-another {
    display: inline-block !important;
    background: #6856e8 !important;
    background-color: #6856e8 !important;
    color: #ffffff !important;
    padding: 11px 20px !important;
    border: 1px solid #6856e8 !important;
    border-radius: 8px !important;
    text-decoration: none !important;
    font-weight: 600 !important;
    margin-bottom: 18px !important;
    transition: .3s ease !important;
}

.btn-ask-another:hover {
    background: #5140c9 !important;
    background-color: #5140c9 !important;
    border-color: #5140c9 !important;
    color: #ffffff !important;
    transform: translateY(-2px);
}

</style>


<!-- ASK ANOTHER QUESTION BUTTON -->

<div style="display:flex; justify-content:flex-end;">

    <a
        href="<?= e(url('dashboard/group-peer/ask.php')) ?>"
        class="btn-ask-another"
    >
        + Ask Another Question
    </a>

</div>


<!-- QUESTION DETAILS -->

<article class="item">

    <div class="muted">
        Posted by <?= e($q['full_name']) ?>
        · <?= e($q['created_at']) ?>
    </div>

    <h2><?= e($q['title']) ?></h2>

    <p><?= nl2br(e($q['description'])) ?></p>

    <?php foreach ($qfiles as $f): ?>

        <img
            class="attachment"
            src="<?= e(qa_image_url($f['file_path'])) ?>"
            alt="Question attachment"
        >

    <?php endforeach; ?>


    <?php if ($q['code_snippet']): ?>

        <h4>Code snippet</h4>

        <pre id="question-code"><?= e($q['code_snippet']) ?></pre>

        <button
            type="button"
            onclick="copyCode('question-code')"
        >
            Copy code
        </button>

    <?php endif; ?>


    <p>

        <span class="tag">
            <?= (int)$q['votes'] ?> votes
        </span>

        <?php if ($q['is_solved']): ?>

            <span class="tag">Solved</span>

        <?php endif; ?>

    </p>


    <div class="actions">

        <!-- UPVOTE -->

        <form
            method="post"
            action="<?= e(url('dashboard/group-peer/vote.php')) ?>"
        >

            <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
            <input type="hidden" name="question_id" value="<?= $id ?>">
            <input type="hidden" name="vote" value="1">

            <button type="submit" class="btn-upvote">
                ▲ Upvote
            </button>

        </form>


        <!-- DOWNVOTE -->

        <form
            method="post"
            action="<?= e(url('dashboard/group-peer/vote.php')) ?>"
        >

            <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
            <input type="hidden" name="question_id" value="<?= $id ?>">
            <input type="hidden" name="vote" value="-1">

            <button type="submit" class="btn-downvote">
                ▼ Downvote
            </button>

        </form>


        <!-- EDIT AND DELETE QUESTION -->

        <?php if ((int)$q['user_id'] === (int)$user['id']): ?>

            <a
                class="btn-edit"
                href="<?= e(url('dashboard/group-peer/edit-question.php?id='.$id)) ?>"
            >
                ✎ Edit
            </a>


            <form
                method="post"
                action="<?= e(url('dashboard/group-peer/delete-question.php')) ?>"
                onsubmit="return confirm('Delete this question?')"
            >

                <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                <input type="hidden" name="id" value="<?= $id ?>">

                <button type="submit" class="btn-delete">
                    🗑 Delete
                </button>

            </form>

        <?php endif; ?>

    </div>

</article>


<!-- ANSWERS -->

<h3>Answers (<?= count($answers) ?>)</h3>


<?php foreach ($answers as $a): ?>

    <?php

    $afiles = qa_attachments(
        $pdo,
        'answer_id',
        (int)$a['id']
    );

    ?>


    <article class="item">

        <div class="muted">

            <?= e($a['full_name']) ?>
            · <?= e($a['created_at']) ?>


            <!-- VERIFIED LECTURER -->

            <?php if (
                $a['role'] === 'lecturer'
                && !empty($a['lecturer_active'])
            ): ?>

                <span class="tag">
                    ✓ Verified Lecturer
                </span>

            <?php endif; ?>


            <!-- ACCEPTED ANSWER -->

            <?php if (
                (int)$q['solved_answer_id'] === (int)$a['id']
            ): ?>

                <span class="tag">
                    Accepted answer
                </span>

            <?php endif; ?>

        </div>


        <p><?= nl2br(e($a['description'])) ?></p>


        <!-- ANSWER IMAGES -->

        <?php foreach ($afiles as $f): ?>

            <img
                class="attachment"
                src="<?= e(qa_image_url($f['file_path'])) ?>"
                alt="Answer attachment"
            >

        <?php endforeach; ?>


        <!-- ANSWER CODE -->

        <?php if ($a['code_snippet']): ?>

            <pre id="answer-code-<?= (int)$a['id'] ?>"><?= e($a['code_snippet']) ?></pre>

            <button
                type="button"
                onclick="copyCode('answer-code-<?= (int)$a['id'] ?>')"
            >
                Copy code
            </button>

        <?php endif; ?>


        <div class="actions">


            <!-- EDIT AND DELETE ANSWER -->

            <?php if ((int)$a['user_id'] === (int)$user['id']): ?>

                <a
                    class="btn-edit"
                    href="<?= e(url('dashboard/group-peer/edit-answer.php?id='.(int)$a['id'])) ?>"
                >
                    ✎ Edit
                </a>


                <form
                    method="post"
                    action="<?= e(url('dashboard/group-peer/delete-answer.php')) ?>"
                    onsubmit="return confirm('Delete this answer?')"
                >

                    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">

                    <button type="submit" class="btn-delete">
                        🗑 Delete
                    </button>

                </form>

            <?php endif; ?>


            <!-- MARK AS SOLVED -->

            <?php if (
                (int)$q['user_id'] === (int)$user['id']
                && !$q['is_solved']
            ): ?>

                <form
                    method="post"
                    action="<?= e(url('dashboard/group-peer/solve.php')) ?>"
                >

                    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                    <input type="hidden" name="question_id" value="<?= $id ?>">
                    <input type="hidden" name="answer_id" value="<?= (int)$a['id'] ?>">

                    <button type="submit" class="btn-solved">
                        ✓ Mark as Solved
                    </button>

                </form>

            <?php endif; ?>

        </div>

    </article>

<?php endforeach; ?>


<!-- YOUR ANSWER FORM -->

<section class="panel">

    <h3>Your answer</h3>

    <form
        method="post"
        action="<?= e(url('dashboard/group-peer/answer.php')) ?>"
        enctype="multipart/form-data"
    >

        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
        <input type="hidden" name="question_id" value="<?= $id ?>">

        <label>Answer</label>

        <textarea name="description" required></textarea>


        <label>Code snippet (optional)</label>

        <textarea name="code_snippet"></textarea>


        <label>Code language</label>

        <input name="code_language" maxlength="50">


        <label>Image (optional)</label>

        <input
            type="file"
            name="image"
            accept="image/jpeg,image/png,image/gif,image/webp"
        >


        <button type="submit">
            Post Answer
        </button>

    </form>

</section>


<?php qa_footer(); ?>
