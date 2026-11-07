<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
$user = require_login();
if ((string)$user['role'] !== 'student') { http_response_code(403); exit('Student access only.'); }
$pdo = db();
$me = (int)$user['id'];
$period = (($_GET['period'] ?? '') === 'week') ? 'week' : 'all';
$when = $period === 'week' ? 'AND t.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)' : '';
$rows=[]; $podium=[]; $myRank=null; $myScore=0; $myQuiz=0; $myNotes=0; $history=[]; $error='';
$search=trim((string)($_GET['q'] ?? '')); $search=mb_substr($search,0,80);
$page=max(1,min(2000,(int)($_GET['page'] ?? 1))); $perPage=25; $total=0; $pages=1;
try {
  // Aggregate once per request. Rank is assigned BEFORE search and pagination.
  $quizWhere=$period==='week' ? 'WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)' : '';
  $notesJoin=$period==='week' ? '' : 'LEFT JOIN (SELECT user_id,SUM(points) note_xp FROM student_note_points GROUP BY user_id) n ON n.user_id=u.id';
  $notesValue=$period==='week' ? '0' : 'COALESCE(n.note_xp,0)';
  $base="SELECT u.id,u.full_name,COALESCE(s.quiz_xp,0) quiz_xp,$notesValue note_xp,
     COALESCE(s.quiz_xp,0)+$notesValue total_xp FROM users u
     LEFT JOIN (SELECT student_id,SUM(points) quiz_xp FROM score_transactions $quizWhere GROUP BY student_id) s ON s.student_id=u.id
     $notesJoin WHERE u.role='student'";
  $ranked="SELECT ROW_NUMBER() OVER (ORDER BY total_xp DESC,full_name ASC,id ASC) rank_position, ranked_base.* FROM ($base) ranked_base";
  $countStmt=$pdo->prepare("SELECT COUNT(*) FROM ($ranked) r WHERE r.full_name LIKE ?");
  $countStmt->execute(['%'.$search.'%']); $total=(int)$countStmt->fetchColumn();
  $pages=max(1,(int)ceil($total/$perPage)); $page=min($page,$pages);
  $listStmt=$pdo->prepare("SELECT * FROM ($ranked) r WHERE r.full_name LIKE ? ORDER BY rank_position LIMIT $perPage OFFSET ".(($page-1)*$perPage));
  $listStmt->execute(['%'.$search.'%']); $rows=$listStmt->fetchAll(PDO::FETCH_ASSOC);
  $podium=$pdo->query("SELECT * FROM ($ranked) r WHERE rank_position<=3 ORDER BY rank_position")->fetchAll(PDO::FETCH_ASSOC);
  $meStmt=$pdo->prepare("SELECT * FROM ($ranked) r WHERE r.id=? LIMIT 1");
  $meStmt->execute([$me]); $self=$meStmt->fetch(PDO::FETCH_ASSOC);
  if($self){$myRank=(int)$self['rank_position'];$myScore=(int)$self['total_xp'];$myQuiz=(int)$self['quiz_xp'];$myNotes=(int)$self['note_xp'];}
  $allStudents=(int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='student'")->fetchColumn();
  $stmt=$pdo->prepare("SELECT activity_type,points,description,created_at FROM score_transactions WHERE student_id=? ORDER BY created_at DESC,id DESC LIMIT 8");
  $stmt->execute([$me]); $history=$stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(Throwable $e){error_log('ULearn RankUp: '.$e->getMessage());$error='Leaderboard could not load. Check the scoring migration and database tables.';}
function esc($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function rankUrl(string $period,string $search,int $page):string{return '?'.http_build_query(['period'=>$period,'q'=>$search,'page'=>$page]);}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>ULearn · Leaderboard</title>
<style>
:root{color-scheme:light dark;--bg:#f4f7f9;--card:#fff;--text:#102139;--muted:#61758c;--line:#dce5e9;--green:#087e60;--soft:#e7f7ef}
@media(prefers-color-scheme:dark){:root{--bg:#0c1220;--card:#161f30;--text:#eff6ff;--muted:#a5b5c9;--line:#2d3b50;--soft:#153d34}}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:15px/1.55 system-ui,-apple-system,Segoe UI,sans-serif}.wrap{max-width:1100px;margin:auto;padding:30px 20px 80px}
nav{display:flex;justify-content:space-between;align-items:center;gap:15px;margin-bottom:25px}a{color:inherit}nav a,.tabs a{padding:10px 15px;border:1px solid var(--line);border-radius:12px;text-decoration:none;font-weight:700}
.hero{border-radius:24px;padding:32px;background:linear-gradient(115deg,#063e39,#087d62 70%,#12677b);color:white;margin-bottom:20px}.hero h1{font-size:clamp(28px,4vw,44px);margin:6px 0}.hero p{opacity:.88}
.grid{display:grid;grid-template-columns:1.1fr 1.9fr;gap:18px}.card{background:var(--card);border:1px solid var(--line);border-radius:20px;padding:22px;margin-bottom:18px;box-shadow:0 8px 25px #00000008}
.muted{color:var(--muted)}.big{font-size:48px;font-weight:850;letter-spacing:-2px}.stat{display:flex;justify-content:space-between;border-top:1px solid var(--line);padding:12px 0}
.tabs{display:flex;gap:9px;margin-bottom:18px;flex-wrap:wrap}.tabs a.active{background:var(--green);color:white;border-color:var(--green)}
.person{display:grid;grid-template-columns:42px minmax(0,1fr) auto;align-items:center;gap:12px;border-top:1px solid var(--line);padding:13px 0}.person:first-of-type{border-top:0}
.rank{border-radius:12px;background:var(--soft);color:var(--green);text-align:center;padding:9px;font-weight:850}.name{font-weight:700;overflow-wrap:anywhere}.you{background:var(--soft);margin:0 -10px;padding:13px 10px;border-radius:12px}.xp{font-weight:850;font-variant-numeric:tabular-nums}.badge{font-size:12px;background:var(--soft);color:var(--green);border-radius:99px;padding:4px 9px}
.timeline{border-top:1px solid var(--line);padding:10px 0}.error{border:1px solid #e9a4a4;padding:15px;border-radius:14px}
@media(max-width:720px){.grid{grid-template-columns:1fr}.hero{padding:24px}.wrap{padding:18px 12px}nav{flex-wrap:wrap}}

body{display:flex;min-height:100vh}.ulearn-sidebar{flex:0 0 250px;width:250px;background:var(--card);border-right:1px solid var(--line);padding:28px 18px;display:flex;flex-direction:column;gap:20px;position:sticky;top:0;height:100vh;overflow-y:auto}
.ulearn-brand{font-size:26px;font-weight:850;letter-spacing:-1.5px;text-decoration:none;padding:0 12px}.ulearn-brand span{color:var(--green)}
.ulearn-navlabel{font-size:11px;font-weight:750;letter-spacing:1.5px;text-transform:uppercase;color:var(--muted);padding:0 12px}.ulearn-links{display:grid;gap:5px}.ulearn-links a{padding:11px 13px;border:0;border-radius:11px;font-size:14px;text-decoration:none;font-weight:600}.ulearn-links a:hover{background:var(--soft)}.ulearn-links a.current{background:var(--soft);color:var(--green)}
.ulearn-user{margin-top:auto;border:1px solid var(--line);padding:15px;border-radius:14px;display:grid;gap:5px;overflow-wrap:anywhere}.ulearn-user small{color:var(--muted)}
.wrap{width:100%;min-width:0}nav.ulearn-links{display:grid;justify-content:initial;align-items:initial;margin:0}
@media(max-width:900px){body{display:block}.ulearn-sidebar{width:auto;height:auto;position:static;padding:14px;gap:10px;border-right:0;border-bottom:1px solid var(--line)}.ulearn-links{display:flex!important;flex-wrap:wrap;gap:4px}.ulearn-links a{padding:8px 10px;font-size:12px}.ulearn-user{display:none}}

/* ULearn RankUp — premium theme-aware navigation icon */
.ul-trophy{display:inline-block;vertical-align:-4px;flex-shrink:0;color:#07845f;transition:transform .35s cubic-bezier(.2,.8,.2,1),filter .35s ease;transform-origin:50% 55%}
a:hover>.ul-trophy,a:focus-visible>.ul-trophy{transform:rotate(-10deg) scale(1.12);filter:drop-shadow(0 2px 5px rgba(0,143,103,.25))}
@media(prefers-color-scheme:dark){.ul-trophy{color:#5ce3b5}}
@media(prefers-reduced-motion:reduce){.ul-trophy{transition:none}a:hover>.ul-trophy,a:focus-visible>.ul-trophy{transform:none}}

.ulearn-links a.current{background:var(--soft);color:var(--green);font-weight:750;box-shadow:inset 3px 0 0 var(--green)}

.podium{margin-bottom:20px}.podium h2{font-size:20px;margin:0 0 12px}.podium-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.podium-card{background:var(--card);border:1px solid var(--line);border-radius:18px;padding:20px 14px;display:flex;flex-direction:column;gap:8px;align-items:center;text-align:center;min-width:0;overflow-wrap:anywhere}.podium-card strong{font-size:16px}.podium-card span{color:var(--muted);font-weight:700}.podium-medal{font-weight:900;font-size:26px;color:var(--green)}.podium-1{border-color:#e6b84b;box-shadow:0 8px 28px #e6b84b22}.podium-1 .podium-medal{color:#b98a21}.podium-2 .podium-medal{color:#8394a8}.podium-3 .podium-medal{color:#b77c57}
.rank-search{display:flex;gap:8px;margin:15px 0 8px}.rank-search input{flex:1;min-width:0;padding:12px 14px;background:var(--bg);color:var(--text);border:1px solid var(--line);border-radius:12px;font:inherit}.rank-search button{background:var(--green);color:#fff;border:0;border-radius:12px;padding:10px 18px;font-weight:750;cursor:pointer}.pager{display:flex;align-items:center;justify-content:space-between;gap:8px;padding-top:20px;flex-wrap:wrap}.pager a{border:1px solid var(--line);border-radius:10px;padding:9px 12px;text-decoration:none;font-weight:700}.pager a.disabled{opacity:.35;pointer-events:none}.sr-only{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)}
@media(max-width:550px){.podium-grid{gap:6px}.podium-card{padding:12px 6px}.podium-card strong{font-size:12px}.podium-card span{font-size:11px}.rank-search{flex-wrap:wrap}.rank-search button{width:100%}.person{grid-template-columns:40px minmax(0,1fr) auto}.xp{font-size:12px}}
</style>
<script src="<?= htmlspecialchars(url('assets/js/theme-global.js?v=20261107-2'), ENT_QUOTES, 'UTF-8') ?>"></script>
<link rel="stylesheet" href="<?= e(url('assets/css/ulearn-community-phase4.css?v=1')) ?>">
</head><body><aside class="ulearn-sidebar" aria-label="Student sidebar">
<a class="ulearn-brand" href="<?=esc(url('dashboard/index.php'))?>">u learn<span>.</span></a>
<div class="ulearn-navlabel">Your workspace</div>
<nav class="ulearn-links" aria-label="Student navigation">
<a href="<?=esc(url('dashboard/index.php'))?>">◫ &nbsp; Dashboard</a>
<a href="<?=esc(url('dashboard/explore.php'))?>">⊕ &nbsp; Explore &amp; Enroll</a>
<a href="<?=esc(url('dashboard/notes-library.php'))?>">▤ &nbsp; Notes Library</a>
<a href="<?=esc(url('dashboard/notes.php'))?>">↥ &nbsp; Upload My Notes</a>
<a href="<?=esc(url('dashboard/kuppiya.php'))?>">♧ &nbsp; Kuppiya Sessions</a>
<a href="<?=esc(url('dashboard/consultations.php'))?>">◎ &nbsp; Consultations</a>
<a href="<?=esc(url('dashboard/quiz-history.php'))?>">◷ &nbsp; My Quiz History</a>
<a class="current" aria-current="page" href="<?=esc(url('dashboard/leaderboard.php'))?>"><svg class="ul-trophy" xmlns="http://www.w3.org/2000/svg" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 21h8m-4-4v4M7 4h10v7a5 5 0 0 1-10 0V4Z"/><path d="M7 6H4v3a4 4 0 0 0 4 4M17 6h3v3a4 4 0 0 1-4 4"/></svg> &nbsp; Leaderboard</a>
<a href="<?=esc(url('dashboard/analytics.php'))?>">◉ &nbsp; Performance Analytics</a>
<a href="<?=esc(url('dashboard/ai-study.php'))?>">✦ &nbsp; AI Study Assistant</a>
<a href="<?=esc(url('dashboard/topic-analysis.php'))?>">◈ &nbsp; Topic Weaknesses</a>
<a href="<?=esc(url('dashboard/notifications.php'))?>">♧ &nbsp; Notifications</a>
<a href="<?=esc(url('dashboard/profile.php'))?>">◉ &nbsp; My Profile &amp; Settings</a>
</nav>
<div class="ulearn-user"><small>Signed in as</small><strong><?=esc($user['full_name'])?></strong></div>
</aside><main class="wrap">
<nav><strong>ULearn RankUp</strong><a href="<?=esc(url('dashboard/index.php'))?>">← Back to Dashboard</a></nav>
<header class="hero"><div>ULearn · Learning rewards</div><h1>Student Leaderboard</h1><p>Earn XP by completing quizzes and sharing approved notes. Your effort counts.</p></header>
<?php if($error): ?><p class="error"><?=esc($error)?></p><?php else: ?>
<div class="tabs"><a class="<?=$period==='all'?'active':''?>" href="<?=esc(rankUrl('all',$search,1))?>">All-time</a><a class="<?=$period==='week'?'active':''?>" href="<?=esc(rankUrl('week',$search,1))?>">Last 7 days · Quiz XP</a><span class="muted" style="margin:auto 0 auto auto">Updates every 30 seconds</span></div>
<section class="podium" aria-label="Top three learners"><h2>Top Learners</h2><div class="podium-grid">
<?php foreach($podium as $p): ?><div class="podium-card podium-<?=esc($p['rank_position'])?>"><div class="podium-medal"><?=esc('#'.$p['rank_position'])?></div><strong><?=esc($p['full_name'])?></strong><span><?=number_format((int)$p['total_xp'])?> XP</span></div><?php endforeach; ?></div></section>
<div class="grid"><section><div class="card"><span class="badge">Your progress</span><h2>Your Rank</h2><div class="big"><?=$myRank===null?'—':'#'.number_format($myRank)?></div><p class="muted">Among <?=number_format($allStudents)?> registered students</p><div class="stat"><span>Total XP</span><strong><?=number_format($myScore)?></strong></div><div class="stat"><span>Quiz XP</span><strong><?=number_format($myQuiz)?></strong></div><?php if($period==='all'):?><div class="stat"><span>Notes contribution</span><strong><?=number_format($myNotes)?></strong></div><?php endif;?></div>
<div class="card"><h3>Recent XP</h3><?php if(!$history):?><p class="muted">Complete a quiz to start earning XP.</p><?php endif;?><?php foreach($history as $h):?><div class="timeline"><strong>+<?=esc($h['points'])?> XP</strong><div><?=esc($h['description'] ?: str_replace('_',' ',(string)$h['activity_type']))?></div><small class="muted"><?=esc($h['created_at'])?></small></div><?php endforeach;?></div></section>
<section><div class="card"><div class="list-head"><div><h2>All Student Rankings</h2><p class="muted"><?=number_format($total)?> matching students · 25 per page</p></div></div>
<form method="get" class="rank-search"><input type="hidden" name="period" value="<?=esc($period)?>"><label class="sr-only" for="rank-search">Search students by name</label><input id="rank-search" type="search" name="q" value="<?=esc($search)?>" placeholder="Search students by name…" maxlength="80"><button type="submit">Search</button></form>
<?php if(!$rows):?><p class="muted">No students match your search.</p><?php endif;?>
<?php foreach($rows as $r):?><div class="person <?=((int)$r['id']===$me)?'you':''?>"><div class="rank">#<?=number_format((int)$r['rank_position'])?></div><div><div class="name"><?=esc($r['full_name'])?> <?=((int)$r['id']===$me)?'<span class="badge">You</span>':''?></div><small class="muted">Quiz <?=number_format((int)$r['quiz_xp'])?><?php if($period==='all'):?> · Notes <?=number_format((int)$r['note_xp'])?><?php endif;?></small></div><div class="xp"><?=number_format((int)$r['total_xp'])?> XP</div></div><?php endforeach;?>
<div class="pager"><a class="<?=$page<=1?'disabled':''?>" href="<?=esc(rankUrl($period,$search,max(1,$page-1)))?>" aria-label="Previous ranking page">← Previous</a><span>Page <?=number_format($page)?> of <?=number_format($pages)?></span><a class="<?=$page>=$pages?'disabled':''?>" href="<?=esc(rankUrl($period,$search,min($pages,$page+1)))?>" aria-label="Next ranking page">Next →</a></div></div></section></div>
<script>document.addEventListener('visibilitychange',()=>{if(!document.hidden)window.location.reload()});setInterval(()=>{if(!document.hidden)window.location.reload()},30000);</script>
<?php endif;?></main></body></html>
