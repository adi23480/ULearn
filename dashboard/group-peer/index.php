<?php
declare(strict_types=1);
require_once __DIR__ . '/common.php';
$user=qa_require_user();$pdo=db();
$search=trim((string)($_GET['q']??''));$filter=(string)($_GET['status']??'all');$sort=(string)($_GET['sort']??'newest');
$where=[];$params=[];
if($search!==''){$where[]='(q.title LIKE ? OR q.description LIKE ? OR q.code_snippet LIKE ?)';$like='%'.$search.'%';array_push($params,$like,$like,$like);}
if($filter==='unanswered')$where[]='NOT EXISTS (SELECT 1 FROM qa_answers a WHERE a.question_id=q.id)';
if($filter==='solved')$where[]='q.is_solved=1';
$order=match($sort){'votes'=>'votes DESC, q.created_at DESC','oldest'=>'q.created_at ASC',default=>'q.created_at DESC'};
$sql='SELECT q.*,u.full_name,(SELECT COUNT(*) FROM qa_answers a WHERE a.question_id=q.id) answer_count,(SELECT COALESCE(SUM(v.vote_value),0) FROM qa_votes v WHERE v.question_id=q.id) votes,(SELECT vote_value FROM qa_votes v WHERE v.question_id=q.id AND v.user_id=?) my_vote FROM qa_questions q JOIN users u ON u.id=q.user_id'.($where?' WHERE '.implode(' AND ',$where):'')." ORDER BY {$order}";
$st=$pdo->prepare($sql);$st->execute(array_merge([(int)$user['id']],$params));$questions=$st->fetchAll();
qa_header('Questions');
?><div class="actions"><a class="btn" href="<?= e(url('dashboard/group-peer/ask.php')) ?>">Ask a Question</a></div>
<form class="panel" method="get"><label>Search</label><input name="q" value="<?= e($search) ?>" placeholder="Search questions..."><div class="actions"><select name="status"><option value="all" <?= $filter==='all'?'selected':'' ?>>All questions</option><option value="unanswered" <?= $filter==='unanswered'?'selected':'' ?>>Unanswered</option><option value="solved" <?= $filter==='solved'?'selected':'' ?>>Solved</option></select><select name="sort"><option value="newest" <?= $sort==='newest'?'selected':'' ?>>Newest</option><option value="oldest" <?= $sort==='oldest'?'selected':'' ?>>Oldest</option><option value="votes" <?= $sort==='votes'?'selected':'' ?>>Most votes</option></select><button>Apply</button></div></form>
<?php if(!$questions): ?><div class="panel">No questions found.</div><?php endif; foreach($questions as $q): ?><article class="item"><div class="muted"><?= e($q['full_name']) ?> · <?= e($q['created_at']) ?></div><h3><a href="<?= e(url('dashboard/group-peer/view.php?id='.(int)$q['id'])) ?>"><?= e($q['title']) ?></a></h3><p><?= e(mb_strimwidth((string)$q['description'],0,240,'…','UTF-8')) ?></p><div class="actions"><span class="tag"><?= (int)$q['votes'] ?> votes</span><span class="tag"><?= (int)$q['answer_count'] ?> answers</span><?= $q['is_solved']?'<span class="tag">Solved</span>':'' ?></div></article><?php endforeach; qa_footer();
