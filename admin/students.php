<?php
declare(strict_types=1);
require_once __DIR__.'/../config/bootstrap.php';
$admin=require_login();
if(!in_array($admin['role'],['admin','main_admin'],true)){http_response_code(403);exit('Admin access only');}
$pdo=db();$error='';$notice='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 csrf_check();
 try{
  $action=(string)($_POST['action']??'');$student=(int)($_POST['student_user_id']??0);
  $q=$pdo->prepare("SELECT id FROM users WHERE id=? AND role='student'");$q->execute([$student]);
  if(!$q->fetchColumn())throw new RuntimeException('Student not found');
  if(in_array($action,['suspend','restore','revoke'],true)){
   $pdo->beginTransaction();
   if($action==='suspend')$sql="UPDATE users SET account_status='suspended',session_version=session_version+1 WHERE id=?";
   elseif($action==='restore')$sql="UPDATE users SET account_status='active',session_version=session_version+1 WHERE id=?";
   else $sql='UPDATE users SET session_version=session_version+1 WHERE id=?';
   $pdo->prepare($sql)->execute([$student]);
   $pdo->prepare('INSERT INTO student_account_audit(student_user_id,admin_user_id,action) VALUES (?,?,?)')->execute([$student,(int)$admin['id'],$action]);
   $pdo->commit();$notice='Account action completed.';
  }elseif($action==='correct'){
   $degree=(int)($_POST['degree_id']??0);$batch=(int)($_POST['batch_id']??0);
   $q=$pdo->prepare('SELECT 1 FROM academic_degree_intakes adi JOIN academic_degrees d ON d.id=adi.degree_id JOIN awarding_universities au ON au.id=d.awarding_university_id JOIN academic_batches b ON b.id=adi.batch_id WHERE adi.degree_id=? AND adi.batch_id=? AND adi.is_active=1 AND d.is_active=1 AND au.is_active=1 AND b.is_active=1');$q->execute([$degree,$batch]);
   if(!$q->fetchColumn())throw new RuntimeException('Degree/intake combination is not active');
   $pdo->beginTransaction();
   $pdo->prepare('INSERT INTO student_academic_profiles(user_id,degree_id,batch_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE degree_id=VALUES(degree_id),batch_id=VALUES(batch_id)')->execute([$student,$degree,$batch]);
   $pdo->prepare('INSERT INTO student_account_audit(student_user_id,admin_user_id,action) VALUES (?,?,?)')->execute([$student,(int)$admin['id'],'correct_enrollment']);
   $pdo->commit();$notice='Academic profile updated.';
  }
 }catch(Throwable $ex){if($pdo->inTransaction())$pdo->rollBack();$error=$ex instanceof RuntimeException?$ex->getMessage():'Update failed; check server logs.';if(!($ex instanceof RuntimeException))error_log($ex->getMessage());}
}
$students=$pdo->query("SELECT u.id,u.full_name,u.email,u.student_number,u.email_verified_at,u.account_status,u.created_at,d.name degree_name,au.name university_name,b.batch_code,p.degree_id,p.batch_id,ap.study_year,ap.semester FROM users u LEFT JOIN student_academic_profiles p ON p.user_id=u.id LEFT JOIN academic_degrees d ON d.id=p.degree_id LEFT JOIN awarding_universities au ON au.id=d.awarding_university_id LEFT JOIN academic_batches b ON b.id=p.batch_id LEFT JOIN academic_intake_progression ap ON ap.degree_id=p.degree_id AND ap.batch_id=p.batch_id WHERE u.role='student' ORDER BY u.id DESC")->fetchAll();
$degrees=$pdo->query('SELECT d.id,d.name,au.name university_name FROM academic_degrees d JOIN awarding_universities au ON au.id=d.awarding_university_id WHERE d.is_active=1 AND au.is_active=1 ORDER BY au.name,d.name')->fetchAll();
$links=$pdo->query('SELECT adi.degree_id,adi.batch_id,b.batch_code FROM academic_degree_intakes adi JOIN academic_batches b ON b.id=adi.batch_id WHERE adi.is_active=1 AND b.is_active=1')->fetchAll();
$selected=(int)($_GET['id']??0);
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Student management | ULearn</title><style>
:root{font-family:system-ui,Arial;background:#f5f7fb;color:#20283c}body{margin:0}.wrap{max-width:1180px;margin:auto;padding:30px 18px}a{color:#5d43d4}header{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}h1{font-size:30px}.panel{background:white;border:1px solid #e3e7ef;border-radius:18px;padding:22px;margin:18px 0;box-shadow:0 6px 30px #17294b09}.muted{color:#69748b}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px}.student{border-top:1px solid #e9ecf3;padding:14px 0;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}.student:first-child{border-top:0}button,.btn{background:#6048ce;color:white;border:0;padding:10px 14px;border-radius:9px;cursor:pointer;font:inherit}button.warn{background:#ab3148}button.soft{background:#e8e5fa;color:#42369d}input,select{padding:10px;border:1px solid #cbd2e1;border-radius:8px;font:inherit;max-width:100%}label{display:block;font-weight:650;margin-bottom:6px}.alert{padding:12px;border-radius:9px;background:#e4f5ed}.error{background:#ffe5e9}.badge{padding:4px 8px;background:#e5f5ed;border-radius:20px}.badge.bad{background:#ffe4e8}form.inline{display:inline-block;margin:3px}small{display:block;color:#69748b}summary{cursor:pointer;font-weight:650}</style>
<script src="<?= htmlspecialchars(url('assets/js/theme-global.js?v=20261107-2'), ENT_QUOTES, 'UTF-8') ?>"></script>
<link rel="stylesheet" href="<?= e(url('assets/css/admin-unified-v1.css?v=1')) ?>">
</head><body><div class="wrap"><header><div><a href="<?= e(url('admin/index.php')) ?>">← Admin overview</a><h1>Student Management</h1><p><a href="<?= e(url('admin/users.php')) ?>">All registered users</a> · <a href="<?= e(url('admin/change-requests.php')) ?>">Academic change requests</a></p><p class="muted">Student directory, academic profiles and security controls</p></div><a href="<?= e(url('admin/intake-progression.php')) ?>">Manage intake progression →</a></header>
<?php if($error):?><div class="alert error"><?= e($error) ?></div><?php endif;?><?php if($notice):?><div class="alert"><?= e($notice) ?></div><?php endif;?>
<div class="panel"><div class="grid"><div><strong><?= count($students) ?></strong><small>Total students</small></div><div><strong><?= count(array_filter($students,fn($s)=>$s['account_status']==='active')) ?></strong><small>Active accounts</small></div><div><strong><?= count(array_filter($students,fn($s)=>$s['account_status']==='suspended')) ?></strong><small>Suspended</small></div></div></div>
<div class="panel"><label for="search">Search students</label><input id="search" type="search" placeholder="Name, email, ID, university, degree or intake" style="width:100%;box-sizing:border-box"><div id="records">
<?php foreach($students as $s):?><div class="student" data-search="<?= e(mb_strtolower(implode(' ',array_map('strval',[$s['full_name'],$s['email'],$s['student_number'],$s['university_name'],$s['degree_name'],$s['batch_code']])))) ?>"><div><strong><?= e($s['full_name']) ?></strong><small><?= e($s['email']) ?> · Student ID <?= e((string)($s['student_number']??'Not set')) ?></small><small><?= e(($s['university_name']??'No university').' · '.($s['degree_name']??'No degree').' · Intake '.($s['batch_code']??'—')) ?> · Year <?= (int)($s['study_year']??1) ?> / Semester <?= (int)($s['semester']??1) ?></small><small><?= $s['email_verified_at']?'Email verified':'Email not verified' ?> · <?= e($s['account_status']) ?></small></div><a href="?id=<?= (int)$s['id'] ?>">Manage profile</a></div><?php endforeach;?></div></div>
<?php foreach($students as $s):if((int)$s['id']!==$selected)continue;?><section class="panel"><h2><?= e($s['full_name']) ?> — Account controls</h2><p><?= e($s['email']) ?> · Student ID <?= e((string)($s['student_number']??'Not set')) ?></p><p><strong>Status:</strong> <?= e($s['account_status']) ?> · <strong>Email:</strong> <?= $s['email_verified_at']?'Verified':'Unverified' ?></p>
<?php foreach(['suspend'=>'Suspend account','restore'=>'Restore account','revoke'=>'Revoke all sessions'] as $action=>$label):?><form method="post" class="inline" onsubmit="return confirm('<?= e($label) ?>?')"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="student_user_id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="action" value="<?= e($action) ?>"><button class="<?= $action==='suspend'?'warn':'soft' ?>"><?= e($label) ?></button></form><?php endforeach; ?>
<h3>Correct academic profile</h3><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="student_user_id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="action" value="correct"><div class="grid"><div><label>Degree</label><select name="degree_id" id="degree" required><option value="">Choose</option><?php foreach($degrees as $d):?><option value="<?= (int)$d['id'] ?>" <?= (int)$d['id']===(int)$s['degree_id']?'selected':'' ?>><?= e($d['university_name'].' — '.$d['name']) ?></option><?php endforeach;?></select></div><div><label>Intake</label><select name="batch_id" id="batch" required></select></div></div><p><button>Save academic correction</button></p></form></section>
<script>const links=<?= json_encode($links,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;const deg=document.getElementById('degree'),batch=document.getElementById('batch');function sync(){const prev=batch.value;batch.innerHTML='<option value="">Choose intake</option>';links.filter(x=>String(x.degree_id)===deg.value).forEach(x=>batch.add(new Option(x.batch_code,x.batch_id)));batch.value=prev||<?= json_encode((string)$s['batch_id']) ?>;}deg.addEventListener('change',()=>{batch.value='';sync()});sync();</script>
<?php endforeach;?><script>document.getElementById('search').addEventListener('input',e=>{const q=e.target.value.toLowerCase();document.querySelectorAll('.student').forEach(s=>s.hidden=!s.dataset.search.includes(q))});</script></div></body></html>
