<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';
date_default_timezone_set('Asia/Colombo');
$user=require_login();
if (!in_array((string)$user['role'],['student','lecturer'],true)) {http_response_code(403);exit('Access denied');}
$pdo=db();$uid=(int)$user['id'];$isStudent=$user['role']==='student';
$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
if (!$id) {http_response_code(400);exit('Invalid consultation');}
function detailBooking(PDO $pdo,int $id,int $uid,bool $isStudent): ?array {
 $sql='SELECT cb.*, su.full_name AS student_name, lu.full_name AS lecturer_name, m.module_code, m.module_name FROM consultation_bookings cb JOIN users su ON su.id=cb.student_id JOIN lecturer_profiles lp ON lp.id=cb.lecturer_id JOIN users lu ON lu.id=lp.user_id JOIN academic_modules m ON m.id=cb.module_id WHERE cb.id=? AND '.($isStudent?'cb.student_id=?':'lp.user_id=?').' LIMIT 1';
 $q=$pdo->prepare($sql);$q->execute([$id,$uid]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
}
$booking=detailBooking($pdo,$id,$uid,$isStudent);
if (!$booking){http_response_code(404);exit('Consultation not found');}
$error='';$notice='';
try {
 if (($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
  csrf_check();$action=(string)($_POST['action']??'');
  if ($action==='send') {
   if (!in_array($booking['status'],['pending','approved','reschedule_proposed'],true))throw new RuntimeException('Messaging is closed for this consultation.');
   $message=trim((string)($_POST['message']??''));
   if ($message===''||mb_strlen($message)>3000)throw new RuntimeException('Enter a message up to 3000 characters.');
   $q=$pdo->prepare('INSERT INTO consultation_messages (booking_id,sender_user_id,message) VALUES (?,?,?)');$q->execute([$id,$uid,$message]);$notice='Message sent.';
  } elseif ($action==='cancel' && $isStudent) {
   $pdo->beginTransaction();
   $q=$pdo->prepare('SELECT starts_at,status FROM consultation_bookings WHERE id=? AND student_id=? FOR UPDATE');$q->execute([$id,$uid]);$current=$q->fetch(PDO::FETCH_ASSOC);
   if (!$current||!in_array($current['status'],['pending','approved','reschedule_proposed'],true)||strtotime((string)$current['starts_at'])<=time()+36000)throw new RuntimeException('Cancellation closes 10 hours before the consultation.');
   $q=$pdo->prepare("UPDATE consultation_bookings SET status='cancelled' WHERE id=? AND student_id=?");$q->execute([$id,$uid]);$pdo->commit();$notice='Consultation cancelled.';
  } else throw new RuntimeException('This action is not allowed.');
  // Post/Redirect/Get prevents browser refresh from resubmitting messages or cancellations.
  $_SESSION['consultation_flash'] = $notice;
  header('Location: '.url('consultation-detail.php').'?id='.$id, true, 303);
  exit;
 }
} catch(Throwable $ex){if($pdo->inTransaction())$pdo->rollBack();$error=$ex instanceof RuntimeException?$ex->getMessage():'Unable to complete your request. Please try again.';if(!($ex instanceof RuntimeException))error_log($ex->getMessage());}
if (isset($_SESSION['consultation_flash'])) {
 $notice=(string)$_SESSION['consultation_flash'];
 unset($_SESSION['consultation_flash']);
}
$q=$pdo->prepare('SELECT cm.sender_user_id,cm.message,cm.created_at,u.full_name FROM consultation_messages cm JOIN users u ON u.id=cm.sender_user_id WHERE cm.booking_id=? ORDER BY cm.id ASC LIMIT 500');$q->execute([$id]);$messages=$q->fetchAll(PDO::FETCH_ASSOC);
$active=in_array($booking['status'],['pending','approved','reschedule_proposed'],true);
$canCancel=$isStudent&&$active&&strtotime((string)$booking['starts_at'])>time()+36000;
$back=$isStudent?'dashboard/consultations.php':'lecturer/consultations.php';
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Consultation #<?= $id ?> · ULearn</title><script>(function(){try{let t=localStorage.getItem('ulearn-theme');if(t==='dark'||t==='light')document.documentElement.dataset.theme=t}catch(e){}})()</script>
<style>
:root{--bg:#f7f8fc;--card:#fff;--text:#1a1c2c;--muted:#737b93;--border:#e7e9f1;--accent:#7059e8;--soft:#f0edff;--surface:#f8f9fc;--danger:#c84660}
[data-theme=dark]{--bg:#0e1019;--card:#1b1e2a;--text:#f6f6fb;--muted:#a4a9bb;--border:#343748;--accent:#aa9aff;--soft:#2b2545;--surface:#121520;--danger:#ff7f96}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:14px/1.55 Inter,system-ui,Arial,sans-serif}a{color:inherit;text-decoration:none}button,textarea{font:inherit}.wrap{max-width:1050px;margin:0 auto;padding:24px 22px 70px}.top{display:flex;justify-content:space-between;align-items:center;padding:0 0 22px;border-bottom:1px solid var(--border)}.brand{font-size:23px;font-weight:850;letter-spacing:-1px}.brand span{color:var(--accent)}.back{color:var(--muted);font-weight:650;font-size:13px}.back:hover{color:var(--accent)}.eyebrow{color:var(--muted);font-size:11px;letter-spacing:.11em;font-weight:750;text-transform:uppercase}.hero{padding:29px 0 24px}.hero h1{font-size:clamp(25px,3.7vw,35px);line-height:1.18;letter-spacing:-.9px;margin:9px 0 13px;font-weight:800}.pill{display:inline-flex;background:var(--soft);color:var(--accent);border-radius:99px;padding:5px 12px;font-size:11px;font-weight:800}.layout{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(275px,1fr);gap:18px;align-items:start}.card{background:var(--card);border:1px solid var(--border);border-radius:18px;box-shadow:0 8px 35px rgba(25,23,66,.025)}.chat-card{overflow:hidden;min-height:520px;display:flex;flex-direction:column}.card-head{padding:20px 23px;border-bottom:1px solid var(--border)}h2{font-size:16px;letter-spacing:-.2px;margin:0;font-weight:800}.muted{color:var(--muted)}.small{font-size:12px}.chat{display:flex;flex-direction:column;gap:12px;min-height:280px;max-height:520px;overflow:auto;padding:23px;flex:1}.empty{text-align:center;margin:auto;color:var(--muted);font-size:13px}.bubble{max-width:83%;align-self:flex-start;background:var(--surface);border:1px solid var(--border);border-radius:15px 15px 15px 4px;padding:11px 14px;overflow-wrap:anywhere;white-space:pre-wrap}.bubble.mine{align-self:flex-end;background:var(--soft);border-color:transparent;border-radius:15px 15px 4px 15px}.bubble strong{font-size:11px;color:var(--muted)}.bubble small{display:block;color:var(--muted);font-size:10px;margin-top:5px}.composer{display:flex;gap:9px;padding:15px;border-top:1px solid var(--border)}textarea{flex:1;min-width:0;min-height:47px;max-height:125px;padding:12px 13px;border:1px solid var(--border);border-radius:12px;background:var(--surface);color:var(--text);resize:vertical;outline:none}textarea:focus{border-color:var(--accent)}.btn{border:1px solid var(--border);border-radius:11px;padding:10px 15px;background:var(--card);color:var(--text);font-weight:700;cursor:pointer}.btn:hover{filter:brightness(.97)}.primary{background:var(--accent);color:white;border-color:var(--accent)}.side{padding:23px}.side h2{margin-bottom:18px}.detail-row{padding:13px 0;border-bottom:1px solid var(--border)}.detail-row:first-of-type{padding-top:0}.detail-row:last-of-type{border-bottom:0}.detail-label{font-size:11px;color:var(--muted);font-weight:650;margin-bottom:5px}.detail-value{font-size:13px;font-weight:650;overflow-wrap:anywhere}.summary{font-size:13px;white-space:pre-wrap;overflow-wrap:anywhere;color:var(--text);line-height:1.6}.cancel{margin-top:16px;color:var(--danger);border-color:var(--border);background:transparent;font-size:12px}.notice{background:var(--soft);padding:12px 15px;border-radius:12px;margin:10px 0}.error{background:#ffe8eb;color:#9b2440}.closed{padding:15px 22px;border-top:1px solid var(--border)}@media(max-width:760px){.layout{grid-template-columns:1fr}.wrap{padding:18px 14px 50px}.hero{padding:22px 0}.chat-card{min-height:410px}.chat{min-height:240px}.side{padding:20px}.card-head{padding:17px 20px}.back{font-size:12px}}
</style><link rel="stylesheet" href="<?= e(url('assets/css/ulearn-design-system.css?v=1')) ?>">
<script defer src="<?= e(url('assets/js/ulearn-motion.js?v=1')) ?>"></script>
</head><body><div class="wrap"><div class="top"><?php require __DIR__ . '/includes/brand-logo.php'; ?><a href="<?= e(url($back)) ?>">← All consultations</a></div>
<div class="hero"><div class="eyebrow">Consultation #<?= $id ?></div><h1><?= e((string)$booking['topic']) ?></h1><span class="pill"><?= e(ucwords(str_replace('_',' ',(string)$booking['status']))) ?></span></div>
<?php if($error):?><div class="notice error"><?= e($error) ?></div><?php endif;?><?php if($notice):?><div class="notice"><?= e($notice) ?></div><?php endif;?>
<div class="layout">
<section class="card chat-card"><div class="card-head"><h2>Conversation</h2><div class="muted small">Private messages with <?= e($isStudent?(string)$booking['lecturer_name']:(string)$booking['student_name']) ?></div></div><div class="chat" id="chat"><?php if(!$messages):?><div class="empty">No messages yet.<div>Start a conversation about your consultation.</div></div><?php endif;?><?php foreach($messages as $m):?><div class="bubble <?= (int)$m['sender_user_id']===$uid?'mine':'' ?>"><strong><?= (int)$m['sender_user_id']===$uid?'You':e((string)$m['full_name']) ?></strong><div><?= e((string)$m['message']) ?></div><small><?= e((string)$m['created_at']) ?></small></div><?php endforeach;?></div>
<?php if($active):?><form method="post" class="composer" id="message-form"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="send"><textarea name="message" id="message-input" required maxlength="3000" placeholder="Write a message…" aria-label="Message"></textarea><button class="btn primary" id="send-button" type="submit">Send →</button></form><?php else:?><div class="closed muted small">This consultation is closed. Messages are read-only.</div><?php endif;?></section>
<aside class="card side"><h2>Appointment</h2><div class="detail-row"><div class="detail-label"><?= $isStudent?'Lecturer':'Student' ?></div><div class="detail-value"><?= e($isStudent?(string)$booking['lecturer_name']:(string)$booking['student_name']) ?></div></div><div class="detail-row"><div class="detail-label">Module</div><div class="detail-value"><?= e((string)$booking['module_code']) ?> · <?= e((string)$booking['module_name']) ?></div></div><div class="detail-row"><div class="detail-label">Date & time</div><div class="detail-value"><?= e(date('D, M j, Y',strtotime((string)$booking['starts_at']))) ?> · <?= e(date('g:i A',strtotime((string)$booking['starts_at']))) ?></div></div><div class="detail-row"><div class="detail-label">Location</div><div class="detail-value">In person</div></div><?php if($booking['student_message']):?><div class="detail-row"><div class="detail-label">Initial request</div><div class="summary"><?= e((string)$booking['student_message']) ?></div></div><?php endif;?><?php if($booking['lecturer_response']):?><div class="detail-row"><div class="detail-label">Lecturer response</div><div class="summary"><?= e((string)$booking['lecturer_response']) ?></div></div><?php endif;?>
<?php if($canCancel):?><form method="post" onsubmit="return confirm('Cancel this consultation?')"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="cancel"><button class="btn cancel" type="submit">Cancel consultation</button></form><?php elseif($isStudent&&$active):?><p class="muted small">Cancellation closes 10 hours before the scheduled start.</p><?php endif;?></aside></div></div><script>const c=document.getElementById('chat');if(c)c.scrollTop=c.scrollHeight;
const messageForm=document.getElementById('message-form');
const messageInput=document.getElementById('message-input');
const sendButton=document.getElementById('send-button');
if(messageForm&&messageInput){
 messageInput.addEventListener('keydown',function(event){
  if(event.key==='Enter'&&!event.shiftKey&&!event.isComposing){
   event.preventDefault();
   if(messageInput.value.trim()&&!messageForm.dataset.sending){messageForm.requestSubmit();}
  }
 });
 messageForm.addEventListener('submit',function(event){
  if(!messageInput.value.trim()||messageForm.dataset.sending){event.preventDefault();return;}
  messageForm.dataset.sending='1';
  if(sendButton){sendButton.disabled=true;sendButton.textContent='Sending…';}
 });
}
</script></body></html>
