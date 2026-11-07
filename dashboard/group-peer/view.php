
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

qa_header('Discussion');
?>
<style>
.qa-thread{max-width:920px;margin:0 auto}.qa-thread *{box-sizing:border-box}.qa-thread .thread-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin:4px 0 22px}.qa-thread .thread-back{color:var(--muted);font-size:.88rem;text-decoration:none;font-weight:700}.qa-thread .thread-back:hover{color:var(--accent)}.qa-thread .thread-ask{background:var(--tag-bg);color:var(--accent);border:1px solid var(--border);padding:10px 15px;border-radius:11px;text-decoration:none;font-size:.85rem;font-weight:750}.qa-thread .thread-post,.qa-thread .thread-answer,.qa-thread .thread-composer{background:var(--panel);border:1px solid var(--border);border-radius:19px;padding:clamp(19px,3vw,30px);box-shadow:var(--shadow);margin-bottom:18px}.qa-thread .thread-post{border-top:3px solid var(--accent)}.qa-thread .thread-author{display:flex;align-items:center;gap:11px;margin-bottom:20px}.qa-thread .thread-avatar{height:38px;width:38px;flex:none;border-radius:12px;display:grid;place-items:center;background:var(--tag-bg);color:var(--accent);font-size:.84rem;font-weight:850}.qa-thread .thread-byline{min-width:0}.qa-thread .thread-name{font-size:.91rem;font-weight:800}.qa-thread .thread-date{font-size:.77rem;color:var(--muted)}.qa-thread .thread-post h1{font-size:clamp(1.4rem,3vw,2rem);letter-spacing:-.035em;line-height:1.22;margin:0 0 14px}.qa-thread .thread-body{white-space:pre-wrap;overflow-wrap:anywhere;line-height:1.75;margin:0 0 18px}.qa-thread .thread-image{max-width:100%;max-height:480px;object-fit:contain;border:1px solid var(--border);border-radius:13px;margin:10px 0;display:block}.qa-thread .thread-code{margin:16px 0}.qa-thread .thread-code pre{margin:0;border-radius:12px 12px 0 0}.qa-thread .thread-code button{border-radius:0 0 12px 12px;width:100%;background:var(--tag-bg);color:var(--accent);border:1px solid var(--border);font-size:.82rem;padding:8px}.qa-thread .thread-actions{display:flex;align-items:center;gap:9px;flex-wrap:wrap;margin-top:20px}.qa-thread .thread-actions form{margin:0}.qa-thread .thread-actions button,.qa-thread .thread-actions a{background:var(--tag-bg);color:var(--text);border:1px solid var(--border);padding:8px 12px;border-radius:999px;font-size:.82rem;font-weight:750;min-height:37px;display:inline-flex;align-items:center;gap:6px;text-decoration:none;box-shadow:none;transform:none}.qa-thread .thread-actions button:hover,.qa-thread .thread-actions a:hover{background:var(--border);box-shadow:none;transform:none}.qa-thread .thread-actions .vote-active{background:var(--tag-bg);border-color:var(--accent);color:var(--accent)}.qa-thread .thread-actions .thread-danger{color:#d85865}.qa-thread .thread-score{font-size:.83rem;font-weight:800;padding:0 3px}.qa-thread .thread-meta-pill{font-size:.78rem;color:var(--muted);display:inline-flex;align-items:center;gap:5px;padding:7px 10px}.qa-thread .thread-section-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin:28px 0 15px}.qa-thread .thread-section-head h2{font-size:1.12rem;margin:0;letter-spacing:-.02em}.qa-thread .thread-answer{position:relative;padding:22px 24px}.qa-thread .thread-answer.accepted{border-color:var(--accent)}.qa-thread .thread-label{margin-left:auto;background:var(--tag-bg);color:var(--accent);border-radius:99px;padding:5px 10px;font-size:.72rem;font-weight:800}.qa-thread .thread-empty{border:1px dashed var(--border);border-radius:16px;text-align:center;padding:32px 20px;color:var(--muted);margin-bottom:20px}.qa-thread .thread-composer{scroll-margin-top:20px}.qa-thread .thread-composer h2{font-size:1.12rem;margin:0 0 5px}.qa-thread .thread-hint{color:var(--muted);font-size:.85rem;margin:0 0 15px}.qa-thread .thread-composer textarea[name=description]{min-height:155px;resize:vertical;margin:0 0 10px}.qa-thread .thread-composer label{display:block;margin-bottom:7px}.qa-thread .thread-extra{border:1px solid var(--border);border-radius:13px;margin:10px 0 18px;padding:0 16px}.qa-thread .thread-extra summary{padding:14px 0;cursor:pointer;font-size:.88rem;font-weight:750;color:var(--accent)}.qa-thread .thread-extra[open] summary{margin-bottom:10px}.qa-thread .thread-extra textarea{min-height:110px}.qa-thread .thread-extra input[type=file]{font-size:.84rem}.qa-thread .thread-submit{display:flex;justify-content:flex-end;gap:10px;align-items:center}.qa-thread .thread-submit button{background:var(--button);color:var(--button-text);border-radius:11px}.qa-thread .thread-role{color:var(--accent);font-size:.76rem;font-weight:800}.qa-thread .thread-solved{display:inline-flex;color:var(--accent);font-size:.82rem;font-weight:800;background:var(--tag-bg);border-radius:99px;padding:6px 10px;margin-bottom:15px}.qa-thread .thread-actions .thread-solve-button{background:var(--tag-bg);color:var(--accent)}@media(max-width:600px){.qa-thread .thread-post,.qa-thread .thread-answer,.qa-thread .thread-composer{padding:18px}.qa-thread .thread-actions{gap:5px}.qa-thread .thread-actions button,.qa-thread .thread-actions a{padding:8px 10px}.qa-thread .thread-submit button{width:100%}}
</style>
<div class="qa-thread">
 <div class="thread-toolbar"><a class="thread-back" href="<?= e(url('dashboard/group-peer/index.php')) ?>">← All discussions</a><a class="thread-ask" href="<?= e(url('dashboard/group-peer/ask.php')) ?>">+ Start a discussion</a></div>
 <article class="thread-post">
  <div class="thread-author"><span class="thread-avatar" aria-hidden="true"><?= e(strtoupper(substr(trim((string)$q['full_name']),0,1))) ?></span><div class="thread-byline"><div class="thread-name"><?= e($q['full_name']) ?></div><div class="thread-date">Asked <?= e($q['created_at']) ?></div></div></div>
  <?php if ($q['is_solved']): ?><span class="thread-solved">✓ Solved discussion</span><?php endif; ?>
  <h1><?= e($q['title']) ?></h1><p class="thread-body"><?= e($q['description']) ?></p>
  <?php foreach ($qfiles as $f): ?><img class="thread-image" src="<?= e(qa_image_url($f['file_path'])) ?>" alt="Image attached to question" loading="lazy"><?php endforeach; ?>
  <?php if (!empty($q['code_snippet'])): ?><div class="thread-code"><pre id="question-code"><code><?= e($q['code_snippet']) ?></code></pre><button type="button" onclick="copyCode('question-code')">Copy code</button></div><?php endif; ?>
  <div class="thread-actions" aria-label="Question actions">
   <form method="post" action="<?= e(url('dashboard/group-peer/vote.php')) ?>"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="question_id" value="<?= $id ?>"><input type="hidden" name="vote" value="1"><button type="submit" class="<?= (int)($q['my_vote'] ?? 0) === 1 ? 'vote-active' : '' ?>" aria-label="Upvote question">↑ Upvote</button></form>
   <span class="thread-score" aria-label="Question score"><?= (int)$q['votes'] ?></span>
   <form method="post" action="<?= e(url('dashboard/group-peer/vote.php')) ?>"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="question_id" value="<?= $id ?>"><input type="hidden" name="vote" value="-1"><button type="submit" class="<?= (int)($q['my_vote'] ?? 0) === -1 ? 'vote-active' : '' ?>" aria-label="Downvote question">↓</button></form>
   <a href="#qa-answer-form">↳ Write an answer</a><span class="thread-meta-pill">◌ <?= count($answers) ?> answers</span>
   <?php if ((int)$q['user_id'] === (int)$user['id']): ?><a href="<?= e(url('dashboard/group-peer/edit-question.php?id='.$id)) ?>">Edit</a><form method="post" action="<?= e(url('dashboard/group-peer/delete-question.php')) ?>" onsubmit="return confirm('Delete this question?')"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="id" value="<?= $id ?>"><button class="thread-danger" type="submit">Delete</button></form><?php endif; ?>
  </div>
 </article>
 <div class="thread-section-head"><h2>Community answers <span class="muted">(<?= count($answers) ?>)</span></h2><a class="thread-back" href="#qa-answer-form">Add your answer ↓</a></div>
 <?php if (!$answers): ?><div class="thread-empty">No answers yet. Be the first to help this student.</div><?php endif; ?>
 <?php foreach ($answers as $a): $afiles=qa_attachments($pdo,'answer_id',(int)$a['id']); $accepted=(int)($q['solved_answer_id'] ?? 0)===(int)$a['id']; ?>
 <article class="thread-answer <?= $accepted ? 'accepted' : '' ?>" id="answer-<?= (int)$a['id'] ?>">
  <div class="thread-author"><span class="thread-avatar" aria-hidden="true"><?= e(strtoupper(substr(trim((string)$a['full_name']),0,1))) ?></span><div class="thread-byline"><div class="thread-name"><?= e($a['full_name']) ?> <?php if ($a['role']==='lecturer' && !empty($a['lecturer_active'])): ?><span class="thread-role">✓ Verified lecturer</span><?php endif; ?></div><div class="thread-date"><?= e($a['created_at']) ?></div></div><?php if ($accepted): ?><span class="thread-label">✓ Accepted answer</span><?php endif; ?></div>
  <p class="thread-body"><?= e($a['description']) ?></p>
  <?php foreach ($afiles as $f): ?><img class="thread-image" src="<?= e(qa_image_url($f['file_path'])) ?>" alt="Image attached to answer" loading="lazy"><?php endforeach; ?>
  <?php if (!empty($a['code_snippet'])): ?><div class="thread-code"><pre id="answer-code-<?= (int)$a['id'] ?>"><code><?= e($a['code_snippet']) ?></code></pre><button type="button" onclick="copyCode('answer-code-<?= (int)$a['id'] ?>')">Copy code</button></div><?php endif; ?>
  <div class="thread-actions">
   <?php if ((int)$a['user_id']===(int)$user['id']): ?><a href="<?= e(url('dashboard/group-peer/edit-answer.php?id='.(int)$a['id'])) ?>">Edit answer</a><form method="post" action="<?= e(url('dashboard/group-peer/delete-answer.php')) ?>" onsubmit="return confirm('Delete this answer?')"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="thread-danger" type="submit">Delete</button></form><?php endif; ?>
   <?php if ((int)$q['user_id']===(int)$user['id'] && !$q['is_solved']): ?><form method="post" action="<?= e(url('dashboard/group-peer/solve.php')) ?>"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="question_id" value="<?= $id ?>"><input type="hidden" name="answer_id" value="<?= (int)$a['id'] ?>"><button type="submit" class="thread-solve-button">✓ Accept as solution</button></form><?php endif; ?>
  </div>
 </article>
 <?php endforeach; ?>
 <section class="thread-composer" id="qa-answer-form"><h2>Join the discussion</h2><p class="thread-hint">Share an explanation, experience or solution that could help another student.</p>
  <form method="post" action="<?= e(url('dashboard/group-peer/answer.php')) ?>" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="question_id" value="<?= $id ?>">
   <label for="qa-answer-description">Your answer</label><textarea id="qa-answer-description" name="description" placeholder="Write a helpful answer…" required maxlength="20000"></textarea>
   <details class="thread-extra"><summary>＋ Add code or an image (optional)</summary><label for="qa-answer-code">Code snippet</label><textarea id="qa-answer-code" name="code_snippet" placeholder="Paste code here if relevant"></textarea><label for="qa-answer-language">Programming language</label><input id="qa-answer-language" name="code_language" maxlength="50" placeholder="e.g. PHP, JavaScript"><label for="qa-answer-image">Attach an image (up to 5 MB)</label><input id="qa-answer-image" type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp"></details>
   <div class="thread-submit"><button type="submit">Post answer ↗</button></div>
  </form>
 </section>
</div>
<?php qa_footer(); ?>
