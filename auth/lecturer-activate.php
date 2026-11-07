<?php
declare(strict_types=1);
require_once __DIR__.'/../config/bootstrap.php';
if (me()) { go('auth/login.php'); }
$pdo=db();$error='';$email=strtolower(trim((string)($_POST['email']??$_GET['email']??'')));
if ($_SERVER['REQUEST_METHOD']==='POST') {
 csrf_check();
 $code=trim((string)($_POST['code']??''));$name=trim((string)($_POST['full_name']??''));
 $username=trim((string)($_POST['username']??''));$lecturerNumber=trim((string)($_POST['lecturer_number']??''));
 $password=(string)($_POST['password']??'');$confirm=(string)($_POST['confirm_password']??'');
 try {
  if (!filter_var($email,FILTER_VALIDATE_EMAIL) || !preg_match('/^[0-9]{6}$/',$code)) throw new RuntimeException('Enter your invitation email and six-digit code.');
  if (mb_strlen($name)<2 || mb_strlen($name)>120) throw new RuntimeException('Full name must contain 2–120 characters.');
  if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_.]{2,39}$/',$username)) throw new RuntimeException('Username must be 3–40 characters and use letters, numbers, _ or .');
  if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{2,59}$/',$lecturerNumber)) throw new RuntimeException('Lecturer ID must be 3–60 characters and use letters, numbers, _ or -.');
  if (strlen($password)<8 || !preg_match('/[A-Z]/',$password) || !preg_match('/[0-9]/',$password) || !preg_match('/[^a-zA-Z0-9]/',$password)) throw new RuntimeException('Password needs at least 8 characters, an uppercase letter, number and symbol.');
  if ($password!==$confirm) throw new RuntimeException('Passwords do not match.');
  $pdo->beginTransaction();
  $q=$pdo->prepare('SELECT * FROM lecturer_invitations WHERE email=? FOR UPDATE');$q->execute([$email]);$inv=$q->fetch();
  if (!$inv || $inv['used_at']!==null || strtotime((string)$inv['expires_at'])<time() || (int)$inv['attempts']>=5) throw new RuntimeException('Invitation expired or unavailable. Contact your administrator.');
  $q=$pdo->prepare('UPDATE lecturer_invitations SET attempts=attempts+1 WHERE id=?');$q->execute([$inv['id']]);
  if (!password_verify($code,(string)$inv['code_hash'])) { $pdo->commit();throw new RuntimeException('Incorrect activation code.'); }
  $q=$pdo->prepare('SELECT id FROM users WHERE email=? OR username=? LIMIT 1');$q->execute([$email,$username]);
  if ($q->fetch()) throw new RuntimeException('Email or username already in use.');
  $q=$pdo->prepare('SELECT id FROM lecturer_profiles WHERE lecturer_number=? LIMIT 1');$q->execute([$lecturerNumber]);
  if ($q->fetch()) throw new RuntimeException('Lecturer ID already in use.');
  $q=$pdo->prepare("INSERT INTO users(full_name,email,password_hash,role,email_verified_at,username) VALUES (?,?,?,'lecturer',NOW(),?)");
  $q->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT),$username]);$uid=(int)$pdo->lastInsertId();
  $q=$pdo->prepare('INSERT INTO lecturer_profiles(user_id,title,department,designation,is_active,lecturer_number) VALUES (?,?,?,?,1,?)');
  $q->execute([$uid,$inv['title'],$inv['department'],$inv['designation'],$lecturerNumber]);
  // Activate the initial programme scope only after successful account creation.
  if (!$inv['degree_id'] || !$inv['batch_id'] || !$inv['study_year'] || !$inv['semester']) throw new RuntimeException('Invitation missing its initial teaching assignment. Ask the admin to resend it.');
  $lecturerProfileId=(int)$pdo->lastInsertId();
  $q=$pdo->prepare('INSERT INTO lecturer_degree_assignments(lecturer_id,degree_id,batch_id,study_year,semester,is_active) VALUES (?,?,?,?,?,1) ON DUPLICATE KEY UPDATE is_active=1');
  $q->execute([$lecturerProfileId,$inv['degree_id'],$inv['batch_id'],$inv['study_year'],$inv['semester']]);
  $q=$pdo->prepare('UPDATE lecturer_invitations SET used_at=NOW() WHERE id=?');$q->execute([$inv['id']]);
  $pdo->commit();
  $q=$pdo->prepare('SELECT session_version FROM users WHERE id=?');$q->execute([$uid]);
  session_regenerate_id(true);$_SESSION['uid']=$uid;$_SESSION['session_version']=(int)$q->fetchColumn();
  go('lecturer/index.php');
 } catch(Throwable $ex) {
  if ($pdo->inTransaction())$pdo->rollBack();
  if ($ex instanceof RuntimeException)$error=$ex->getMessage();
  elseif ($ex instanceof PDOException && $ex->getCode()==='23000')$error='Email, username or Lecturer ID already in use.';
  else {error_log('Lecturer activation: '.$ex->getMessage());$error='Unable to activate account. Please try again.';}
 }
}
ob_start(); ?>
<h2>Activate lecturer account</h2><p class="sub">Enter the six-digit code emailed to you, then create your lecturer profile.</p>
<?php if ($error): ?><div class="message error" role="alert"><?= e($error) ?></div><?php endif; ?>
<form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
<?php foreach (['email'=>'Invited email','code'=>'Six-digit activation code','full_name'=>'Full name','username'=>'Username','lecturer_number'=>'Lecturer ID'] as $field=>$label): ?>
<div class="field"><label for="<?= e($field) ?>"><?= e($label) ?></label><input id="<?= e($field) ?>" name="<?= e($field) ?>" type="<?= $field==='email'?'email':'text' ?>" value="<?= $field==='email'?e($email):'' ?>" required autocomplete="off"></div>
<?php endforeach; ?>
<div class="field"><label for="password">Password</label><input id="password" type="password" name="password" required autocomplete="new-password"></div>
<div class="field"><label for="confirm_password">Confirm password</label><input id="confirm_password" type="password" name="confirm_password" required autocomplete="new-password"></div>
<button class="btn" type="submit">Activate &amp; open dashboard →</button></form>
<p class="switch">Already activated? <a href="<?= e(url('auth/login.php')) ?>">Log in</a></p>
<?php $content=ob_get_clean();$title='Lecturer activation';require __DIR__.'/../includes/auth-layout.php';
