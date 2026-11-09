<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
date_default_timezone_set('Asia/Colombo');
$user = require_login();
if (($user['role'] ?? '') !== 'student') { http_response_code(403); exit('Student access only.'); }
$pdo = db();
$pdo->exec("SET time_zone = '+05:30'");
$uid = (int)$user['id'];
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
if (!$id) { http_response_code(400); exit('Invalid Kuppiya session.'); }
$load = $pdo->prepare('SELECT s.*,u.full_name AS host_name FROM kuppiya_sessions s JOIN users u ON u.id=s.host_user_id WHERE s.id=?');
$load->execute([$id]);
$session = $load->fetch(PDO::FETCH_ASSOC);
if (!$session) { http_response_code(404); exit('Kuppiya not found.'); }
$memberQuery = $pdo->prepare('SELECT 1 FROM kuppiya_members WHERE session_id=? AND user_id=?');
$memberQuery->execute([$id,$uid]);
if (!$memberQuery->fetchColumn()) { http_response_code(403); exit('Join this Kuppiya first to access its private room.'); }
$active = $session['status']==='scheduled' && strtotime($session['ends_at']) > time();
$error = '';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_check();
    $message = trim((string)($_POST['message'] ?? ''));
    if (!$active) { $error='This conversation is read-only.'; }
    elseif ($message==='' || mb_strlen($message)>2000) { $error='Message must be 1–2000 characters.'; }
    else {
        try {
            $pdo->beginTransaction();
            $lock=$pdo->prepare('SELECT status,ends_at,title FROM kuppiya_sessions WHERE id=? FOR UPDATE');
            $lock->execute([$id]);
            $fresh=$lock->fetch(PDO::FETCH_ASSOC);
            $memberQuery->execute([$id,$uid]);
            if (!$fresh || $fresh['status']!=='scheduled' || strtotime($fresh['ends_at'])<=time() || !$memberQuery->fetchColumn()) {
                throw new RuntimeException('This session is no longer available for messages.');
            }
            $insert=$pdo->prepare("INSERT INTO kuppiya_messages (session_id,sender_user_id,message_type,message_text) VALUES (?,?,'chat',?)");
            $insert->execute([$id,$uid,$message]);
            $recipients=$pdo->prepare('SELECT user_id FROM kuppiya_members WHERE session_id=? AND user_id<>?');
            $recipients->execute([$id,$uid]);
            $notify=$pdo->prepare('INSERT INTO notifications (user_id,actor_user_id,type,title,message,target_url) VALUES (?,?,?,?,?,?)');
            foreach ($recipients->fetchAll(PDO::FETCH_COLUMN) as $recipient) {
                $notify->execute([(int)$recipient,$uid,'kuppiya_message','New Kuppiya message',($user['full_name']??'A member').' sent a message in '.$fresh['title'].'.',url('dashboard/kuppiya-room.php?id='.$id)]);
            }
            $pdo->commit();
            header('Location: '.url('dashboard/kuppiya-room.php?id='.$id.'#messages'),true,303);
            exit;
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error=$ex instanceof RuntimeException ? $ex->getMessage() : 'Unable to send message. Please try again.';
        }
    }
}
$membersStmt=$pdo->prepare('SELECT u.id,u.full_name FROM kuppiya_members m JOIN users u ON u.id=m.user_id WHERE m.session_id=? ORDER BY m.joined_at');
$membersStmt->execute([$id]);
$members=$membersStmt->fetchAll(PDO::FETCH_ASSOC);
$messagesStmt=$pdo->prepare('SELECT m.sender_user_id,m.message_type,m.message_text,m.created_at,u.full_name FROM kuppiya_messages m LEFT JOIN users u ON u.id=m.sender_user_id WHERE m.session_id=? ORDER BY m.id DESC LIMIT 150');
$messagesStmt->execute([$id]);
$messages=array_reverse($messagesStmt->fetchAll(PDO::FETCH_ASSOC));
$fmt=static fn(string $date):string => date('D, j M Y · g:i A',strtotime($date));
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= e($session['title']) ?> · Kuppiya Room</title>
<script>try{document.documentElement.dataset.theme=localStorage.getItem('ulearn-theme')||'light'}catch(e){}</script>
<style>
:root {
    color-scheme: light;
    font-family: Inter, ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif;
    color: #20213b;
    background: #f7f7fc;
    --surface: #fff;
    --muted: #777990;
    --border: #e8e8f2;
    --accent: #7656df;
    --soft: #f2efff;
    --field: #f9f9fd;
}

* {
    box-sizing: border-box;
}

html[data-theme="dark"] {
    color-scheme: dark;
    color: #f0eefb;
    background: #10111c;
    --surface: #1b1c2c;
    --muted: #a5a5bc;
    --border: #33334a;
    --soft: #302746;
    --field: #242537;
}

body {
    margin: 0;
    background: transparent;
    color: inherit;
}
a{color:inherit;text-decoration:none}.wrap{max-width:1240px;margin:0 auto;padding:28px 24px}.top{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:24px}.top-actions {
    display: flex;
    align-items: center;
    gap: 10px;
}

.theme-toggle {
    width: 40px;
    height: 40px;
    border: 1px solid var(--border);
    border-radius: 12px;
    background: var(--surface);
    color: inherit;
    font-size: 20px;
    cursor: pointer;
    display: grid;
    place-items: center;
}

.theme-toggle:hover {
    border-color: var(--accent);
}

.theme-toggle:focus-visible {
    outline: 2px solid var(--accent);
    outline-offset: 3px;
}.back{font-size:14px;color:var(--muted);font-weight:600}.tag{background:var(--soft);color:var(--accent);padding:8px 12px;border-radius:999px;font-size:12px;font-weight:700}.title{margin:0;font-size:clamp(23px,3vw,31px);letter-spacing:-.9px}.subtitle{color:var(--muted);font-size:13px;margin:8px 0 0}.layout{display:grid;grid-template-columns:minmax(0,1fr) 305px;gap:18px;margin-top:24px}.panel{background:var(--surface);border:1px solid var(--border);border-radius:20px;overflow:hidden;box-shadow:0 12px 36px rgba(36,30,74,.035)}.chat-head{display:flex;justify-content:space-between;align-items:center;padding:20px 24px;border-bottom:1px solid var(--border)}.chat-head h2,.side h2{font-size:15px;margin:0;letter-spacing:-.2px}.small{font-size:12px;color:var(--muted)}.chat{height:min(60vh,610px);min-height:340px;overflow-y:auto;padding:24px;display:flex;flex-direction:column;gap:13px;scroll-behavior:smooth}.bubble{max-width:min(78%,560px);padding:12px 15px;border:1px solid var(--border);background:var(--field);border-radius:16px 16px 16px 5px;align-self:flex-start;overflow-wrap:anywhere}.bubble.mine{background:var(--soft);border-color:transparent;border-radius:16px 16px 5px 16px;align-self:flex-end}.bubble.system{background:transparent;border:0;align-self:center;text-align:center;color:var(--muted);font-size:12px}.author{font-size:11px;font-weight:750;color:var(--accent);margin-bottom:5px}.body{white-space:pre-wrap;font-size:14px;line-height:1.55}.when{text-align:right;color:var(--muted);font-size:10px;margin-top:6px}.compose{padding:15px 20px;border-top:1px solid var(--border);display:flex;gap:10px;align-items:flex-end}.compose textarea{width:100%;resize:none;max-height:130px;min-height:44px;padding:12px 14px;background:var(--field);color:inherit;border:1px solid var(--border);border-radius:12px;font:inherit;font-size:14px;outline:none}.compose textarea:focus{border-color:var(--accent)}.send{border:0;background:var(--accent);color:white;border-radius:12px;height:44px;padding:0 20px;font-weight:700;cursor:pointer}.send:disabled{opacity:.5;cursor:wait}.side{padding:23px}.section{margin-top:23px;padding-top:20px;border-top:1px solid var(--border)}.item{margin-top:16px}.item label{display:block;color:var(--muted);font-size:11px;margin-bottom:5px}.item strong{font-size:13px;line-height:1.5;font-weight:600;overflow-wrap:anywhere}.person{display:flex;align-items:center;gap:10px;margin-top:13px;font-size:13px}.avatar{width:31px;height:31px;display:grid;place-items:center;background:var(--soft);color:var(--accent);font-size:12px;font-weight:800;border-radius:10px}.alert{padding:12px 16px;border-radius:12px;background:#fff0f0;color:#a02735;margin-bottom:14px;font-size:13px}.empty{margin:auto;color:var(--muted);text-align:center;font-size:14px}.read-only{padding:15px 20px;color:var(--muted);font-size:13px;border-top:1px solid var(--border)}@media(max-width:800px){.wrap{padding:18px 14px}.layout{grid-template-columns:1fr}.chat{height:56vh}.side{padding:18px}.top{margin-bottom:18px}.chat-head{padding:17px}.chat{padding:15px}.compose{padding:12px}.bubble{max-width:90%}}
</style>

<link rel="stylesheet" href="<?= e(url('assets/css/ulearn-community-phase4.css?v=1')) ?>">
</head>
<body>

<main class="wrap">

<div class="top">

    <a class="back" href="<?= e(url('dashboard/kuppiya.php?view=my')) ?>">
        ← My Kuppiyas
    </a>

    <div class="top-actions">

        <button
            type="button"
            class="theme-toggle"
            id="themeButton"
            aria-label="Switch to dark mode"
            title="Switch to dark mode">
            ☾
        </button>

        <span class="tag">
            <?= e(ucfirst($session['status'])) ?>
        </span>

    </div>

</div>
<h1 class="title"><?= e($session['title']) ?></h1><p class="subtitle">Hosted by <?= e($session['host_name']) ?> · <?= count($members) ?> members · Private group</p>
<div class="layout"><section class="panel"><header class="chat-head"><div><h2>Group conversation</h2><span class="small">Only members can view these messages</span></div><span class="tag">Private</span></header><div class="chat" id="messages">
<?php if (!$messages): ?><p class="empty">No messages yet. Start the conversation.</p><?php endif; ?>
<?php foreach($messages as $m): $system=$m['message_type']==='system';$mine=!$system && (int)$m['sender_user_id']===$uid; ?><div class="bubble <?= $system?'system':($mine?'mine':'') ?>"><?php if(!$system&&!$mine): ?><div class="author"><?= e($m['full_name']?:'Former member') ?></div><?php endif; ?><div class="body"><?= e($m['message_text']) ?></div><div class="when"><?= e(date('g:i A',strtotime($m['created_at']))) ?></div></div><?php endforeach; ?>
</div><?php if($error): ?><p class="alert"><?= e($error) ?></p><?php endif; ?>
<?php if($active): ?><form method="post" class="compose" id="chatForm"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><textarea name="message" id="messageInput" maxlength="2000" rows="1" required placeholder="Write a message…" aria-label="Write a message"></textarea><button class="send" id="sendButton" type="submit">Send</button></form><?php else: ?><div class="read-only">This session has ended or been cancelled. Messages are read-only.</div><?php endif; ?></section>
<aside class="panel side"><h2>Session details</h2><div class="item"><label>Starts</label><strong><?= e($fmt($session['starts_at'])) ?></strong></div><div class="item"><label>Ends</label><strong><?= e($fmt($session['ends_at'])) ?></strong></div><div class="item"><label>Format</label><strong><?= $session['location_type']==='online'?'Online':'In person' ?></strong></div><div class="item"><label>Meeting details</label><strong><?= e($session['meeting_details']?:'Not provided') ?></strong></div><?php if($session['description']): ?><div class="item"><label>About this session</label><strong><?= e($session['description']) ?></strong></div><?php endif; ?><div class="section"><h2>Members <span class="small">(<?= count($members) ?>)</span></h2><?php foreach($members as $m): ?><div class="person"><span class="avatar"><?= e(mb_strtoupper(mb_substr($m['full_name'],0,1))) ?></span><span><?= e($m['full_name']) ?><?= (int)$m['id']===(int)$session['host_user_id']?' · Host':'' ?></span></div><?php endforeach; ?></div></aside></div></main>
<script>
(function () {

    const root = document.documentElement;
    const button = document.getElementById('themeButton');

    function applyTheme(theme) {

        const isDark = theme === 'dark';

        root.dataset.theme = isDark ? 'dark' : 'light';

        if (button) {

            button.textContent = isDark ? '\u2600' : '\u263E';

            button.title = isDark
                ? 'Switch to light mode'
                : 'Switch to dark mode';

            button.setAttribute('aria-label', button.title);
        }
    }

    // Get theme saved by kuppiya.php
    let savedTheme = 'light';

    try {
        savedTheme = localStorage.getItem('ulearn-theme') || 'light';
    } catch (error) {
        savedTheme = root.dataset.theme || 'light';
    }

    applyTheme(savedTheme);

    // Toggle theme
    if (button) {

        button.addEventListener('click', function () {

            const nextTheme =
                root.dataset.theme === 'dark' ? 'light' : 'dark';

            applyTheme(nextTheme);

            try {
                localStorage.setItem('ulearn-theme', nextTheme);
            } catch (error) {
                // Continue without local storage
            }

        });
    }

})();
</script>
<script>(function(){const chat=document.getElementById('messages');if(chat)chat.scrollTop=chat.scrollHeight;const form=document.getElementById('chatForm');if(!form)return;const input=document.getElementById('messageInput'),button=document.getElementById('sendButton');input.addEventListener('keydown',function(e){if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();if(input.value.trim()&&!button.disabled)form.requestSubmit();}});form.addEventListener('submit',function(e){if(!input.value.trim()){e.preventDefault();return;}button.disabled=true;button.textContent='Sending…';});})();</script><script src="<?= e(url('assets/js/notifications-live.js?v=20261107-profile-only-2')) ?>" data-base="<?= e(rtrim(url(), '/')) ?>" defer></script>
<?php require_once __DIR__ . '/../includes/student-bot.php'; ?></body></html><div class=