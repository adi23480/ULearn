<?php
declare(strict_types=1);
require_once __DIR__.'/../config/bootstrap.php';
$admin=require_login();
if(!in_array((string)$admin['role'],['admin','main_admin'],true)){http_response_code(403);exit('Admin access only');}
$pdo=db();$id=(int)($_GET['id']??$_POST['id']??0);if($id<1){http_response_code(400);exit('Invalid account');}
$notice='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 csrf_check();
 try{
  $action=(string)($_POST['action']??'');
  if($id===(int)$admin['id'])throw new RuntimeException('You cannot modify your own account here.');
  $pdo->beginTransaction();
  $s=$pdo->prepare('SELECT id,role,account_status FROM users WHERE id=? FOR UPDATE');$s->execute([$id]);$target=$s->fetch(PDO::FETCH_ASSOC);
  if(!$target)throw new RuntimeException('Account not found.');
  if(in_array($target['role'],['admin','main_admin'],true))throw new RuntimeException('Administrator accounts require separate privileged management.');
  if($action==='save'){
   $name=trim((string)($_POST['full_name']??''));
   if(mb_strlen($name)<2||mb_strlen($name)>120)throw new RuntimeException('Name must be 2–120 characters.');
   $pdo->prepare('UPDATE users SET full_name=? WHERE id=?')->execute([$name,$id]);
   $audit='update_account';$notice='Account updated.';
  }elseif(in_array($action,['suspend','activate'],true)){
   $new=$action==='suspend'?'suspended':'active';
   $pdo->prepare('UPDATE users SET account_status=?,session_version=session_version+1 WHERE id=?')->execute([$new,$id]);
   $audit=$action.'_account';$notice='Account '.($new==='active'?'reactivated':'suspended').'.';
  }else{throw new RuntimeException('Unknown action.');}
  if($target['role']==='student')$pdo->prepare('INSERT INTO student_account_audit(student_user_id,admin_user_id,action) VALUES (?,?,?)')->execute([$id,$admin['id'],$audit]);
  $pdo->commit();
 }catch(Throwable $ex){if($pdo->inTransaction())$pdo->rollBack();$error=$ex instanceof RuntimeException?$ex->getMessage():'Could not update account.';error_log($ex->getMessage());}
}
$s=$pdo->prepare('SELECT u.id,u.full_name,u.email,u.student_number,u.role,u.account_status,u.created_at,d.name degree_name,b.batch_code FROM users u LEFT JOIN student_academic_profiles sp ON sp.user_id=u.id LEFT JOIN academic_degrees d ON d.id=sp.degree_id LEFT JOIN academic_batches b ON b.id=sp.batch_id WHERE u.id=?');$s->execute([$id]);$account=$s->fetch(PDO::FETCH_ASSOC);if(!$account){http_response_code(404);exit('Account not found');}
$theme=(string)($admin['theme']??'system');
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Manage account | ULearn</title><script>const p=<?= json_encode($theme) ?>;const l=localStorage.getItem('ulearn-theme');document.documentElement.dataset.theme=l==='light'||l==='dark'?l:(p==='dark'||p==='light'?p:(matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'));</script><link rel="stylesheet" href="<?= e(url('assets/css/admin-stage9.css')) ?>">
<script src="<?= htmlspecialchars(url('assets/js/theme-global.js?v=20261107-2'), ENT_QUOTES, 'UTF-8') ?>"></script>
<link rel="stylesheet" href="<?= e(url('assets/css/admin-unified-v1.css?v=1')) ?>">
</head><body><div class="admin-layout"><aside class="admin-side"><?php require __DIR__ . '/../includes/brand-logo.php'; ?><div class="side-label">SYSTEM MANAGEMENT</div><a class="side-link active" href="<?= e(url('admin/users.php')) ?>">◉ All registered users</a><a class="side-link" href="<?= e(url('admin/change-requests.php')) ?>">◇ Academic requests</a><a class="side-link" href="<?= e(url('admin/index.php')) ?>">⌂ Admin overview</a></aside><main class="admin-main"><a class="back" href="<?= e(url('admin/users.php')) ?>">← Back to directory</a><div class="heading"><div><div class="eyebrow">SYSTEM MANAGEMENT / ACCOUNTS</div><h1>Manage account</h1><p>Review identity and account access without changing locked academic information.</p></div><span class="tag"><?= e($account['role']) ?></span></div><?php if($notice): ?><div class="alert success"><?= e($notice) ?></div><?php endif ?><?php if($error): ?><div class="alert error"><?= e($error) ?></div><?php endif ?><section class="panel"><h2>Account details</h2><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="save"><label>Full name<input name="full_name" required maxlength="120" value="<?= e($account['full_name']) ?>"></label><div class="facts"><div><small>Email</small><strong><?= e($account['email']) ?></strong></div><div><small>University ID</small><strong><?= e($account['student_number']?:'#'.$id) ?></strong></div><div><small>Degree</small><strong><?= e($account['degree_name']?:'—') ?></strong></div><div><small>Intake</small><strong><?= e($account['batch_code']?:'—') ?></strong></div><div><small>Status</small><strong><?= e($account['account_status']) ?></strong></div></div><?php if(!in_array($account['role'],['admin','main_admin'],true)&&$id!==(int)$admin['id']): ?><button class="primary">Save profile changes</button><?php endif ?></form></section><section class="panel"><h2>Account access</h2><p>Suspending an account blocks access and invalidates its existing sessions. We use reversible suspension instead of permanent deletion to protect academic history.</p><?php if(!in_array($account['role'],['admin','main_admin'],true)&&$id!==(int)$admin['id']): ?><form method="post" onsubmit="return confirm('Confirm this account status change?')"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="<?= $account['account_status']==='active'?'suspend':'activate' ?>"><button class="<?= $account['account_status']==='active'?'danger':'primary' ?>"><?= $account['account_status']==='active'?'Suspend account':'Reactivate account' ?></button></form><?php else: ?><p>Admin accounts are protected from changes on this page.</p><?php endif ?></section></main></div></body></html>