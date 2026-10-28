<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$user = require_login();

if (($user['role'] ?? '') !== 'student') { http_response_code(403); exit('Student access only.'); }

$pdo = db();

$uid = (int)$user['id'];

$error = '';

$notice = (string)($_GET['notice'] ?? '');

$token = (string)($_GET['invite'] ?? '');

$sessionId = filter_var($_GET['room'] ?? $_GET['session'] ?? null, FILTER_VALIDATE_INT) ?: 0;

$view = (($_GET['view'] ?? '') === 'my') ? 'my' : 'discover';

$invitationMode = $token !== '';



function kpDate(string $raw): ?DateTimeImmutable {

    $d = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $raw);

    return $d && $d->format('Y-m-d\TH:i') === $raw ? $d : null;

}

function kpRedirect(string $notice, int $id = 0): never {

    go('dashboard/kuppiya.php?notice='.rawurlencode($notice).($id ? '&room='.$id : '&view=my'));

}

function kpLock(PDO $pdo, int $id): array {

    $q=$pdo->prepare('SELECT * FROM kuppiya_sessions WHERE id=? FOR UPDATE'); $q->execute([$id]);

    $s=$q->fetch(PDO::FETCH_ASSOC); if (!$s) throw new RuntimeException('Session not found.'); return $s;

}

function kpMember(PDO $pdo, int $id, int $uid): bool {

    $q=$pdo->prepare('SELECT 1 FROM kuppiya_members WHERE session_id=? AND user_id=?'); $q->execute([$id,$uid]); return (bool)$q->fetchColumn();

}

function kpConflict(PDO $pdo, int $uid, string $start, string $end): bool {

    $q=$pdo->prepare("SELECT 1 FROM kuppiya_members km JOIN kuppiya_sessions ks ON ks.id=km.session_id WHERE km.user_id=? AND ks.status='scheduled' AND ks.ends_at>NOW() AND ks.starts_at<? AND ks.ends_at>? LIMIT 1");

    $q->execute([$uid,$end,$start]); return (bool)$q->fetchColumn();

}

function kpSystem(PDO $pdo,int $id,string $text): void {

    $q=$pdo->prepare("INSERT INTO kuppiya_messages(session_id,sender_user_id,message_type,message_text) VALUES (?,NULL,'system',?)"); $q->execute([$id,$text]);

}

function kpNotify(PDO $pdo, int $recipient, int $actor, string $type, string $title, string $message, int $sessionId): void {
    $q = $pdo->prepare('INSERT INTO notifications (user_id, actor_user_id, type, title, message, target_url) VALUES (?,?,?,?,?,?)');
    $q->execute([$recipient, $actor, $type, $title, $message, url('dashboard/kuppiya.php?room='.$sessionId)]);
}

function kpNotifyMembers(PDO $pdo, int $sessionId, int $actor, string $type, string $title, string $message): void {
    $q = $pdo->prepare('SELECT user_id FROM kuppiya_members WHERE session_id=? AND user_id<>?');
    $q->execute([$sessionId, $actor]);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $recipient) {
        kpNotify($pdo, (int)$recipient, $actor, $type, $title, $message, $sessionId);
    }
}

try {

    // Expire undersubscribed sessions at their start time. Runs on page requests,

    // so no cron job is required; the first visit after start applies the change.

    // Expiration is transactional: notify remaining members once per cancelled session.
    $pdo->beginTransaction();
    $q = $pdo->query("SELECT ks.id, ks.title FROM kuppiya_sessions ks WHERE ks.status='scheduled' AND ks.starts_at<=NOW() AND (SELECT COUNT(*) FROM kuppiya_members km WHERE km.session_id=ks.id)<2 FOR UPDATE");
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $expired) {
        $cancel = $pdo->prepare("UPDATE kuppiya_sessions SET status='cancelled' WHERE id=? AND status='scheduled'");
        $cancel->execute([(int)$expired['id']]);
        if ($cancel->rowCount() > 0) {
            $members = $pdo->prepare('SELECT user_id FROM kuppiya_members WHERE session_id=?');
            $members->execute([(int)$expired['id']]);
            foreach ($members->fetchAll(PDO::FETCH_COLUMN) as $memberId) {
                kpNotify($pdo, (int)$memberId, (int)$memberId, 'kuppiya_cancelled', 'Kuppiya cancelled', 'Your Kuppiya "'.$expired['title'].'" was cancelled because fewer than two students joined.', (int)$expired['id']);
            }
        }
    }
    $pdo->commit();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {

        csrf_check();

        $action=(string)($_POST['action'] ?? '');

        if ($action==='create') {

            $moduleId=null; // Kuppiyas are independent of academic modules.

            $title=trim((string)($_POST['title'] ?? ''));

            $description=trim((string)($_POST['description'] ?? ''));

            $location=(string)($_POST['location_type'] ?? 'online');

            $details=trim((string)($_POST['meeting_details'] ?? ''));

            $date=trim((string)($_POST['session_date'] ?? ''));

            $clock=trim((string)($_POST['start_time'] ?? ''));

            $duration=filter_var($_POST['duration_minutes'] ?? null,FILTER_VALIDATE_INT);

            $validDurations=[30,60,90,120,180];

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$clock) || !in_array($duration,$validDurations,true)) {

                throw new RuntimeException('Choose a valid calendar date, start time and duration.');

            }

            $start=kpDate($date.'T'.$clock);

            $end=$start ? $start->modify('+'.$duration.' minutes') : null;

            if ($title==='' || mb_strlen($title)>180 || mb_strlen($description)>3000 || mb_strlen($details)>500) throw new RuntimeException('Check title, description and meeting details lengths.');

            if (!in_array($location,['online','in_person'],true)) throw new RuntimeException('Choose a meeting type.');

            if (!$start || !$end || $start <= new DateTimeImmutable('now') || $end <= $start || $end->getTimestamp()-$start->getTimestamp()>21600) throw new RuntimeException('Choose a future session lasting up to 6 hours.');

            $st=$start->format('Y-m-d H:i:s'); $en=$end->format('Y-m-d H:i:s');

            $pdo->beginTransaction();

            // Serialize all schedule changes involving this user, even across sessions.

            $q=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE'); $q->execute([$uid]);

            if (kpConflict($pdo,$uid,$st,$en)) throw new RuntimeException('You already have a Kuppiya during this time.');

            $invite=bin2hex(random_bytes(24));

            $q=$pdo->prepare("INSERT INTO kuppiya_sessions(module_id,host_user_id,invite_token,title,description,location_type,meeting_details,starts_at,ends_at,max_members,status) VALUES (?,?,?,?,?,?,?,?,?,12,'scheduled')");

            $q->execute([$moduleId,$uid,$invite,$title,$description ?: null,$location,$details ?: null,$st,$en]);

            $id=(int)$pdo->lastInsertId();

            $q=$pdo->prepare('INSERT INTO kuppiya_members(session_id,user_id) VALUES (?,?)'); $q->execute([$id,$uid]);

            kpSystem($pdo,$id,'Kuppiya created. Waiting for at least one more member.');

            $pdo->commit(); kpRedirect('Session created.', $id);

        }

        $id=filter_var($_POST['session_id'] ?? null,FILTER_VALIDATE_INT);

        if (!$id) throw new RuntimeException('Invalid session.');

        if ($action==='chat') {

            $message=trim((string)($_POST['message'] ?? ''));

            if ($message==='' || mb_strlen($message)>2000) throw new RuntimeException('Message must be 1–2000 characters.');

            $pdo->beginTransaction(); $s=kpLock($pdo,$id);

            if ($s['status']!=='scheduled' || strtotime($s['ends_at'])<=time() || !kpMember($pdo,$id,$uid)) throw new RuntimeException('This chat is only available to current members of an active session.');

            $q=$pdo->prepare("INSERT INTO kuppiya_messages(session_id,sender_user_id,message_type,message_text) VALUES (?,?,'chat',?)"); $q->execute([$id,$uid,$message]);

            $pdo->commit(); kpRedirect('Message sent.',$id);

        }

        if ($action==='join') {

            // Lock user first, then session, to serialize overlapping join/create attempts.

            $pdo->beginTransaction();

            $q=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE'); $q->execute([$uid]);

            $s=kpLock($pdo,$id);

            if ($s['status']!=='scheduled' || strtotime($s['starts_at'])<=time()) throw new RuntimeException('This session is no longer open for joining.');

            if (kpMember($pdo,$id,$uid)) throw new RuntimeException('You are already a member.');

            $q=$pdo->prepare('SELECT COUNT(*) FROM kuppiya_members WHERE session_id=?'); $q->execute([$id]);

            if ((int)$q->fetchColumn()>=min(12,(int)$s['max_members'])) throw new RuntimeException('This group is full.');

            if (kpConflict($pdo,$uid,$s['starts_at'],$s['ends_at'])) throw new RuntimeException('You have another Kuppiya at an overlapping time.');

            $q=$pdo->prepare('INSERT INTO kuppiya_members(session_id,user_id) VALUES (?,?)'); $q->execute([$id,$uid]);

            kpSystem($pdo,$id,'A new student joined the group.');
            if ((int)$s['host_user_id'] !== $uid) {
                kpNotify($pdo, (int)$s['host_user_id'], $uid, 'kuppiya_member_joined', 'New Kuppiya member', 'A student joined your Kuppiya "'.$s['title'].'".', $id);
            }

            $pdo->commit(); kpRedirect('Joined successfully.',$id);

        }

        if ($action==='leave') {

            $pdo->beginTransaction(); $s=kpLock($pdo,$id);

            if ($s['status']!=='scheduled' || strtotime($s['starts_at'])<=time()) throw new RuntimeException('You can only leave before the session starts.');

            if (!kpMember($pdo,$id,$uid)) throw new RuntimeException('You are not a member.');

            $q=$pdo->prepare('DELETE FROM kuppiya_members WHERE session_id=? AND user_id=?'); $q->execute([$id,$uid]);

            if ((int)$s['host_user_id']===$uid) {

                $q=$pdo->prepare('SELECT user_id FROM kuppiya_members WHERE session_id=? ORDER BY user_id'); $q->execute([$id]);

                $remaining=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));

                if (!$remaining) {

                    $q=$pdo->prepare("UPDATE kuppiya_sessions SET status='cancelled' WHERE id=?"); $q->execute([$id]);

                } else {

                    $newHost=$remaining[random_int(0,count($remaining)-1)];

                    $q=$pdo->prepare('UPDATE kuppiya_sessions SET host_user_id=? WHERE id=?'); $q->execute([$newHost,$id]);

                    kpSystem($pdo,$id,'The previous host left. A new host was selected automatically.');
                    kpNotify($pdo, $newHost, $uid, 'kuppiya_host_transfer', 'You are now the Kuppiya host', 'You were automatically selected to host "'.$s['title'].'".', $id);
                    kpNotifyMembers($pdo, $id, $newHost, 'kuppiya_host_changed', 'Kuppiya host changed', 'The host of "'.$s['title'].'" has changed.', $id);

                }

            } else {
                kpSystem($pdo,$id,'A student left the group.');
                kpNotify($pdo, (int)$s['host_user_id'], $uid, 'kuppiya_member_left', 'Kuppiya member left', 'A student left your Kuppiya "'.$s['title'].'".', $id);
            }

            $pdo->commit(); kpRedirect('You left the session.');

        }

        throw new RuntimeException('Unknown action.');

    }

    if ($token!=='' && preg_match('/^[a-f0-9]{48}$/D',$token)) {

        $q=$pdo->prepare('SELECT id FROM kuppiya_sessions WHERE invite_token=?'); $q->execute([$token]);

        $sessionId=(int)($q->fetchColumn() ?: 0);

        if (!$sessionId) $error='This invitation link is invalid.';

    }

    $q=$pdo->prepare("SELECT ks.*,u.full_name AS host_name,(SELECT COUNT(*) FROM kuppiya_members km WHERE km.session_id=ks.id) AS member_count, EXISTS(SELECT 1 FROM kuppiya_members km2 WHERE km2.session_id=ks.id AND km2.user_id=?) AS is_member FROM kuppiya_sessions ks JOIN users u ON u.id=ks.host_user_id WHERE ks.status='scheduled' AND ks.ends_at>NOW() ORDER BY ks.starts_at ASC LIMIT 100");

    $q->execute([$uid]); $upcoming=$q->fetchAll(PDO::FETCH_ASSOC);

    $q=$pdo->prepare("SELECT ks.id,ks.title,ks.starts_at,ks.ends_at,ks.status FROM kuppiya_members km JOIN kuppiya_sessions ks ON ks.id=km.session_id WHERE km.user_id=? ORDER BY ks.starts_at DESC LIMIT 50");

    $q->execute([$uid]); $mine=$q->fetchAll(PDO::FETCH_ASSOC);

    $selected=null; $members=[]; $messages=[];

    if ($sessionId) {

        $q=$pdo->prepare("SELECT ks.*,u.full_name AS host_name,(SELECT COUNT(*) FROM kuppiya_members WHERE session_id=ks.id) AS member_count FROM kuppiya_sessions ks JOIN users u ON u.id=ks.host_user_id WHERE ks.id=?");

        $q->execute([$sessionId]); $selected=$q->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$selected) $error='Kuppiya not found.';

        elseif (kpMember($pdo,$sessionId,$uid)) {

            $q=$pdo->prepare('SELECT u.full_name,km.user_id FROM kuppiya_members km JOIN users u ON u.id=km.user_id WHERE km.session_id=? ORDER BY km.joined_at'); $q->execute([$sessionId]); $members=$q->fetchAll(PDO::FETCH_ASSOC);

            $q=$pdo->prepare('SELECT msg.sender_user_id,msg.message_type,msg.message_text,msg.created_at,u.full_name FROM kuppiya_messages msg LEFT JOIN users u ON u.id=msg.sender_user_id WHERE msg.session_id=? ORDER BY msg.id DESC LIMIT 100'); $q->execute([$sessionId]); $messages=array_reverse($q->fetchAll(PDO::FETCH_ASSOC));

        }

    }

} catch (Throwable $ex) {

    if ($pdo->inTransaction()) $pdo->rollBack();

    $error=$ex instanceof RuntimeException ? $ex->getMessage() : 'A database error occurred. Please verify the Stage 7 tables.';

}

$initial=strtoupper(substr(trim((string)($user['full_name'] ?? 'S')),0,1));

$base=url('dashboard/kuppiya.php');

?>

<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Kuppiya Sessions — ULearn</title>

<script>(function(){try{let t=localStorage.getItem('ulearn-theme');if(t!=='light'&&t!=='dark')t=window\.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';document.documentElement.dataset.theme=t;}catch(e){}})();function toggleTheme(){let t=document.documentElement.dataset.theme==='dark'?'light':'dark';document.documentElement.dataset.theme=t;try{localStorage.setItem('ulearn-theme',t);}catch(e){}updateThemeButton();}function updateThemeButton(){let b=document.getElementById('themeButton');if(b){let dark=document.documentElement.dataset.theme==='dark';b.textContent=dark?'☀':'☾';b.setAttribute('aria-label',dark?'Switch to light mode':'Switch to dark mode');}}document.addEventListener('DOMContentLoaded',updateThemeButton);function copyInvite(id){let el=document.getElementById(id);if(!el)return;let v=el.value;if(navigator.clipboard&&window\.isSecureContext){navigator.clipboard.writeText(v).then(()=>alert('Invitation copied!')).catch(()=>window\.prompt('Copy this link:',v));}else{el.select();document.execCommand('copy');alert('Invitation copied!');}}</script>

<style>:root{--bg:#f7f8fc;--surface:#fff;--text:#171927;--muted:#777b90;--border:#e7e8f0;--accent:#6756e8;--soft:#f0edff}:root[data-theme=dark]{--bg:#0c0e16;--surface:#161924;--text:#f4f4fa;--muted:#a0a4b6;--border:#2b2e3c;--accent:#a99aff;--soft:#292440}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:15px/1.6 Inter,system-ui,Arial,sans-serif}a{color:inherit;text-decoration:none}.layout{display:grid;grid-template-columns:250px minmax(0,1fr);min-height:100vh}.sidebar{background:var(--surface);border-right:1px solid var(--border);padding:32px 20px}.brand{font-size:26px;font-weight:800;letter-spacing:-1.5px;display:block;margin:0 0 45px 12px}.brand span,.accent{color:var(--accent)}.nav-label,.eyebrow{font-size:11px;font-weight:750;letter-spacing:1.7px;text-transform:uppercase;color:var(--muted)}.nav-label{margin:0 12px 14px}.nav a{display:block;padding:12px 14px;border-radius:12px;font-size:14px;font-weight:600;margin-bottom:6px}.nav a:hover,.nav a.active{background:var(--soft);color:var(--accent)}main{min-width:0;padding:34px clamp(20px,5vw,76px) 80px}.topbar{display:flex;align-items:center;justify-content:space-between;gap:20px;margin-bottom:50px}.right{display:flex;align-items:center;gap:16px}.theme-button{width:42px;height:42px;border:1px solid var(--border);border-radius:12px;background:var(--surface);color:var(--text);font-size:20px;cursor:pointer}.avatar{width:40px;height:40px;border-radius:50%;background:var(--soft);color:var(--accent);display:grid;place-items:center;font-weight:800}h1{font-size:clamp(34px,4vw,54px);letter-spacing:-2px;line-height:1.15;margin:12px 0}h2{font-size:23px;letter-spacing:-.5px;margin:0 0 18px}h3{margin:0 0 8px}.lead,.muted{color:var(--muted)}.section{margin-top:42px}.card{background:var(--surface);border:1px solid var(--border);border-radius:19px;padding:24px}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:17px}.field{display:flex;flex-direction:column;gap:7px}.field label{font-size:13px;font-weight:700}input,select,textarea{width:100%;padding:12px;border:1px solid var(--border);border-radius:11px;background:var(--surface);color:var(--text);font:inherit}textarea{min-height:88px;resize:vertical}.btn{display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--border);border-radius:11px;padding:11px 15px;background:var(--surface);color:var(--text);font:inherit;font-size:13px;font-weight:700;cursor:pointer}.btn.primary{background:var(--accent);border-color:var(--accent);color:#fff}.btn:hover{opacity:.85}.flex{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.pill{display:inline-block;border-radius:100px;padding:6px 11px;background:var(--soft);color:var(--accent);font-size:12px;font-weight:750}.meta{color:var(--muted);font-size:13px}.empty{border:1px dashed var(--border);border-radius:17px;padding:26px;color:var(--muted)}.notice{padding:14px 17px;border-radius:12px;background:var(--soft);margin:15px 0}.error{background:#ffedf0;color:#a32143}.full{grid-column:1/-1}.session{margin:12px 0}.session h3 a{color:var(--accent)}.chat{max-height:420px;overflow-y:auto;display:grid;gap:10px;padding:10px 0}.bubble{border:1px solid var(--border);border-radius:12px;padding:12px;overflow-wrap:anywhere}.bubble.system{background:var(--soft)}.message{white-space:pre-wrap;overflow-wrap:anywhere}.stack{display:grid;gap:12px}@media(max-width:850px){.layout{grid-template-columns:1fr}.sidebar{border-right:0;border-bottom:1px solid var(--border);padding:20px}.brand{margin-bottom:18px}main{padding:25px 20px}}@media(max-width:600px){.grid{grid-template-columns:1fr}.topbar{margin-bottom:30px}}.tabs{display:flex;gap:10px;flex-wrap:wrap;margin:26px 0 8px}.tab{padding:12px 17px;border:1px solid var(--border);border-radius:12px;font-weight:700;font-size:14px}.tab.active{background:var(--soft);color:var(--accent);border-color:var(--accent)}.room-grid{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:18px}.room-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:25px 0}.room-info{align-self:start}.member-list{display:grid;gap:12px;margin:14px 0}.member{display:flex;align-items:center;gap:9px;font-size:13px}.mini-avatar{width:30px;height:30px;display:grid;place-items:center;background:var(--soft);color:var(--accent);border-radius:50%;font-weight:800}.back{color:var(--accent);font-weight:700}.chat-compose{display:grid;gap:10px;margin-top:20px}.chat-compose .btn{justify-self:start}.chat{min-height:160px;max-height:460px}@media(max-width:900px){.room-grid{grid-template-columns:1fr}}



/* Messaging-style private room: incoming left, outgoing right, system centered. */

.chat-header{display:flex;align-items:center;gap:12px;padding:4px 0 16px;border-bottom:1px solid var(--border);margin-bottom:12px}

.chat-header-icon{width:40px;height:40px;border-radius:50%;display:grid;place-items:center;background:var(--soft);color:var(--accent);font-size:23px}

.chat{display:flex;flex-direction:column;gap:9px;min-height:300px;max-height:520px;overflow-y:auto;padding:18px 14px;background:var(--bg);border-radius:15px;scroll-behavior:smooth}

.chat-line{display:flex;width:100%}.chat-line-other{justify-content:flex-start}.chat-line-mine{justify-content:flex-end}.chat-line-system{justify-content:center}

.chat-bubble{max-width:min(78%,470px);padding:9px 12px 7px;border-radius:14px;overflow-wrap:anywhere;box-shadow:0 1px 2px rgba(0,0,0,.04)}

.chat-bubble-other{background:var(--surface);border:1px solid var(--border);border-bottom-left-radius:4px}

.chat-bubble-mine{background:#d9fdd3;color:#16251b;border-bottom-right-radius:4px}

:root[data-theme="dark"] .chat-bubble-mine{background:#245c4b;color:#f1fff5}

.chat-bubble-system{background:var(--soft);color:var(--muted);text-align:center;max-width:90%;font-size:12px;border-radius:12px}

.chat-author{font-size:12px;font-weight:800;color:var(--accent);margin-bottom:3px}

.chat-text{white-space:pre-wrap;font-size:14px;line-height:1.5}

.chat-time{text-align:right;font-size:10px;opacity:.65;margin-top:3px}

.chat-compose{display:flex;align-items:end;gap:10px;margin-top:12px}

.chat-compose textarea{flex:1;min-width:0;min-height:45px;max-height:130px;height:46px;border-radius:22px;padding:12px 16px;resize:vertical;background:var(--bg)}

.chat-compose .chat-send{flex:none;align-self:end;width:46px;height:46px;border-radius:50%;padding:0;font-size:21px}

.visually-hidden{position:absolute!important;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}

@media(max-width:600px){.chat{padding:12px 8px}.chat-bubble{max-width:88%}}



/* Modern Kuppiya room - presentation only, all server-side permissions preserved. */

.room-grid>.card:first-child{padding:0;overflow:hidden;border-radius:24px;background:var(--surface);box-shadow:0 18px 60px rgba(20,18,48,.08)}

.chat-header{margin:0;padding:20px 23px;background:linear-gradient(120deg,var(--surface),var(--soft));border-bottom:1px solid var(--border)}

.chat-header-icon{width:48px;height:48px;font-size:22px;background:var(--accent);color:white;box-shadow:0 6px 18px rgba(103,86,232,.25)}

.chat-header strong{font-size:17px;letter-spacing:-.3px}

.chat-header .meta{font-size:12px}

.chat{height:460px;min-height:320px;max-height:60vh;padding:24px 22px;gap:13px;background-color:var(--bg);background-image:radial-gradient(circle at 15% 20%,rgba(132,112,255,.055) 0 2px,transparent 3px),radial-gradient(circle at 80% 65%,rgba(132,112,255,.045) 0 2px,transparent 3px);background-size:95px 95px,130px 130px;border-radius:0;scrollbar-width:thin;scrollbar-color:var(--border) transparent}

.chat-line{animation:kuppiyaBubbleIn .32s cubic-bezier(.2,.8,.2,1) both;flex-shrink:0}

.chat-line-mine{transform-origin:bottom right}.chat-line-other{transform-origin:bottom left}

.chat-bubble{max-width:min(76%,490px);padding:11px 15px 9px;border-radius:19px;box-shadow:0 3px 12px rgba(0,0,0,.045);transition:transform .18s ease,box-shadow .18s ease}

.chat-bubble:hover{transform:translateY(-1px);box-shadow:0 6px 18px rgba(0,0,0,.075)}

.chat-bubble-other{background:var(--surface);border:1px solid var(--border);border-bottom-left-radius:5px}

.chat-bubble-mine{background:linear-gradient(135deg,#755df0,#6147d8);color:#fff;border-bottom-right-radius:5px}

:root[data-theme=dark] .chat-bubble-mine{background:linear-gradient(135deg,#6b55d7,#5140ad);color:#fff}

.chat-bubble-system{background:var(--soft);border:1px solid var(--border);color:var(--muted);border-radius:999px;max-width:90%;padding:8px 17px;box-shadow:none}

.chat-text{font-size:14px;line-height:1.55}

.chat-time{font-size:10px;opacity:.7;margin-top:5px}

.chat-compose{display:flex;align-items:center;gap:12px;margin:0;padding:16px 20px;background:var(--surface);border-top:1px solid var(--border)}

.chat-compose textarea{display:block;flex:1;min-width:0;height:46px;min-height:46px;max-height:130px;padding:12px 17px;border:1px solid var(--border);border-radius:24px;background:var(--bg);resize:none;overflow-y:hidden;line-height:22px;outline:none;transition:border-color .2s ease,box-shadow .2s ease}

.chat-compose textarea:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--soft)}

.chat-compose .chat-send{display:grid;place-items:center;flex:none;width:46px;height:46px;padding:0;border-radius:50%;font-size:20px;box-shadow:0 6px 18px rgba(103,86,232,.25);transition:transform .2s ease,box-shadow .2s ease}

.chat-compose .chat-send:hover{transform:translateY(-2px) scale(1.06);box-shadow:0 10px 22px rgba(103,86,232,.35);opacity:1}

.chat-compose .chat-send:active{transform:scale(.93)}

.chat-compose .chat-send svg{width:20px;height:20px;fill:currentColor}

@keyframes kuppiyaBubbleIn{from{opacity:0;transform:translateY(9px) scale(.97)}to{opacity:1;transform:translateY(0) scale(1)}}

@media(max-width:600px){.chat{height:410px;max-height:55vh;padding:18px 12px}.chat-bubble{max-width:88%}.chat-compose{padding:12px;gap:8px}.chat-header{padding:15px}.room-grid>.card:first-child{border-radius:18px}}

@media(prefers-reduced-motion:reduce){.chat-line{animation:none}.chat-bubble,.chat-compose textarea,.chat-compose .chat-send{transition:none}.chat{scroll-behavior:auto}}

</style></head>

<body><div class="layout"><aside class="sidebar"><a class="brand" href="<?= e(url('dashboard/index.php')) ?>">u learn<span>.</span></a><div class="nav-label">YOUR WORKSPACE</div><nav class="nav"><a href="<?= e(url('dashboard/index.php')) ?>">◫ Overview</a><a href="<?= e(url('dashboard/index.php')) ?>#modules">▤ My Modules</a><a href="<?= e(url('dashboard/explore.php')) ?>">⊕ Explore &amp; Enroll</a><a href="<?= e(url('dashboard/quizzes.php')) ?>">◇ Quiz Arena</a><a href="<?= e(url('dashboard/consultations.php')) ?>">◎ Consultations</a><a class="active" href="<?= e($base) ?>">♧ Kuppiya Sessions</a></nav></aside>

<main><header class="topbar"><div class="eyebrow">U LEARN / STUDENT SPACE</div><div class="right"><button id="themeButton" class="theme-button" type="button" onclick="toggleTheme()" aria-label="Toggle theme">☾</button><div class="avatar"><?= e($initial) ?></div></div></header><div class="eyebrow">STUDY TOGETHER</div><h1>Kuppiya Sessions<span class="accent">.</span></h1><p class="lead">Create private peer study groups, invite friends and chat with your members. Maximum 12 students per group.</p>

<?php if($notice):?><div class="notice" role="status"><?= e($notice) ?></div><?php endif;?><?php if($error):?><div class="notice error" role="alert"><?= e($error) ?></div><?php endif;?>

<?php if(!$sessionId && $view==='discover'): ?><section class="section"><h2>Create a Kuppiya</h2><div class="card"><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="create"><div class="grid">

<div class="field full"><label for="title">What are we studying?</label><input id="title" name="title" maxlength="180" required placeholder="e.g. Algorithm Sorting Revision"></div>

<div class="field"><label for="session_date">Session date</label><input id="session_date" name="session_date" type="date" min="<?= e(date('Y-m-d')) ?>" required><span class="meta">Click the calendar icon to choose a date.</span></div>

<div class="field"><label for="start_time">Start time</label><select id="start_time" name="start_time" required><option value="">Choose a start time</option><?php for($hour=7;$hour<=22;$hour++): for($minute=0;$minute<60;$minute+=30): $value=sprintf('%02d:%02d',$hour,$minute); ?><option value="<?= e($value) ?>"><?= e(date('g:i A',strtotime($value))) ?></option><?php endfor; endfor; ?></select></div>

<div class="field"><label for="duration_minutes">How long?</label><select id="duration_minutes" name="duration_minutes" required><option value="30">30 minutes</option><option value="60" selected>1 hour</option><option value="90">1 hour 30 minutes</option><option value="120">2 hours</option><option value="180">3 hours</option></select><span class="meta">End time is calculated automatically.</span></div>

<div class="field"><label for="location_type">Meeting type</label><select id="location_type" name="location_type"><option value="online">Online</option><option value="in_person">In person</option></select></div>

<div class="field full"><label for="meeting_details">Meeting link or location (members only)</label><input id="meeting_details" name="meeting_details" maxlength="500" placeholder="Zoom link or study room"></div>

<div class="field full"><label for="description">Description (optional)</label><textarea id="description" name="description" maxlength="3000" placeholder="Topics we'll cover"></textarea></div></div><p><button class="btn primary" type="submit">Create Kuppiya →</button></p><div class="meta">The host counts as member 1. At least 2 members are needed when the session begins. No module selection needed.</div></form></div></section><?php endif; ?>

<div class="tabs" role="navigation" aria-label="Kuppiya pages">

<a class="tab <?= !$sessionId && $view==='discover' ? 'active' : '' ?>" href="<?= e($base.'?view=discover') ?>">Discover Kuppiyas</a>

<a class="tab <?= !$sessionId && $view==='my' ? 'active' : '' ?>" href="<?= e($base.'?view=my') ?>">My Kuppiyas <span class="pill"><?= count($mine) ?></span></a>

<?php if($selected && kpMember($pdo,(int)$selected['id'],$uid)): ?><span class="tab active">Private Room</span><?php endif; ?>

</div>

<?php if($selected):

  $isMember=kpMember($pdo,(int)$selected['id'],$uid);

  $canJoin=$selected['status']==='scheduled'&&strtotime($selected['starts_at'])>time();

?>

<section class="section">

<?php if($isMember): ?>

<a class="back" href="<?= e($base.'?view=my') ?>">← Back to My Kuppiyas</a>

<div class="room-heading"><div><span class="eyebrow">MEMBERS-ONLY ROOM</span><h2 style="margin:8px 0"><?= e($selected['title']) ?></h2><p class="meta">Hosted by <?= e($selected['host_name']) ?> · <?= (int)$selected['member_count'] ?> / 12 members</p></div><span class="pill"><?= e(strtotime($selected['ends_at'])<=time()?'Finished':ucfirst($selected['status'])) ?></span></div>

<div class="room-grid"><div class="card">

<div class="chat-header"><div class="chat-header-icon">✦</div><div><strong>Group chat</strong><div class="meta">🔒 Only group members</div></div></div>



<div class="chat" id="roomChat" aria-label="Group messages">

<?php if(!$messages):?><div class="empty">No messages yet. Say hello to your group!</div>

<?php else: foreach($messages as $msg): ?>

<?php $isSystem=$msg['message_type']==='system'; $isMine=!$isSystem && (int)$msg['sender_user_id']===$uid; ?>

<div class="chat-line <?= $isSystem?'chat-line-system':($isMine?'chat-line-mine':'chat-line-other') ?>">

  <div class="chat-bubble <?= $isSystem?'chat-bubble-system':($isMine?'chat-bubble-mine':'chat-bubble-other') ?>">

    <?php if(!$isSystem && !$isMine): ?><div class="chat-author"><?= e($msg['full_name'] ?: 'Former member') ?></div><?php endif; ?>

    <div class="chat-text"><?= e($msg['message_text']) ?></div>

    <div class="chat-time"><?= e(date('g:i A',strtotime($msg['created_at']))) ?></div>

  </div>

</div>

<?php endforeach; endif; ?>

</div>

<?php if($selected['status']==='scheduled'&&strtotime($selected['ends_at'])>time()): ?>

<form method="post" class="chat-compose"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="chat"><input type="hidden" name="session_id" value="<?= (int)$selected['id'] ?>"><label for="message" class="visually-hidden">Your message</label><textarea id="message" name="message" maxlength="2000" rows="1" required placeholder="Type a message..."></textarea><button class="btn primary chat-send" type="submit" aria-label="Send message" title="Send message"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3.4 20.2 21 12 3.4 3.8l.1 6.6L15 12 3.5 13.6z"/></svg></button></form>

<?php else: ?><p class="notice">This session has ended. The conversation is read-only.</p><?php endif; ?>

</div><aside class="card room-info">

<h3>Session details</h3><p class="meta"><strong>Starts</strong><br><?= e($selected['starts_at']) ?></p><p class="meta"><strong>Ends</strong><br><?= e($selected['ends_at']) ?></p><p class="meta"><strong>Meeting type</strong><br><?= e($selected['location_type']==='online'?'Online':'In person') ?></p><p class="meta"><strong>Meeting link / place</strong><br><?= e($selected['meeting_details'] ?: 'Not provided') ?></p>

<?php if($selected['description']):?><p class="message"><?= e($selected['description']) ?></p><?php endif; ?>

<h3 style="margin-top:22px">Members</h3><div class="member-list"><?php foreach($members as $member): ?><div class="member"><span class="mini-avatar"><?= e(strtoupper(substr((string)$member['full_name'],0,1))) ?></span><span><?= e($member['full_name']) ?><?= (int)$member['user_id']===(int)$selected['host_user_id']?' · Host':'' ?></span></div><?php endforeach; ?></div>

<h3 style="margin-top:22px">Invite your peers</h3><label class="meta" for="inviteLink">Share this room's invitation</label><input id="inviteLink" readonly value="<?= e((isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'?'https':'http').'://'.($_SERVER['HTTP_HOST'] ?? 'localhost').url('dashboard/kuppiya.php?invite='.$selected['invite_token'])) ?>"><p><button class="btn" type="button" onclick="copyInvite('inviteLink')">Copy invitation link</button></p><p class="meta">Invitees must log in and join before they can access chat.</p>

<?php if($canJoin):?><form method="post" onsubmit="return confirm('Leave this Kuppiya?')"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="leave"><input type="hidden" name="session_id" value="<?= (int)$selected['id'] ?>"><button class="btn" type="submit">Leave Kuppiya</button></form><?php endif; ?>

</aside></div>

<?php else: ?>

<a class="back" href="<?= e($base.'?view=discover') ?>">← Back to Discover</a>

<div class="card" style="margin-top:15px"><span class="pill"><?= e(ucfirst($selected['status'])) ?></span><h2 style="margin-top:15px"><?= e($selected['title']) ?></h2><p class="meta">Hosted by <?= e($selected['host_name']) ?> · <?= (int)$selected['member_count'] ?> / 12 members</p><p><strong>Schedule:</strong> <?= e($selected['starts_at']) ?> – <?= e($selected['ends_at']) ?></p><?php if($selected['description']):?><p class="message"><?= e($selected['description']) ?></p><?php endif; ?><p class="notice">Join this group to access its private room, chat, members, and meeting details.</p>

<?php if($canJoin && (int)$selected['member_count']<12):?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="join"><input type="hidden" name="session_id" value="<?= (int)$selected['id'] ?>"><button class="btn primary" type="submit">Join Kuppiya →</button></form><?php else:?><p class="meta">This session is not open for new members.</p><?php endif; ?></div>

<?php endif; ?>

</section>

<?php elseif($view==='my'): ?>

<section class="section"><h2>My Kuppiyas</h2><p class="lead">Open a group you've joined to enter its private chat room.</p>

<?php if(empty($mine)):?><div class="empty">You haven't joined a Kuppiya yet. <a href="<?= e($base.'?view=discover') ?>">Discover a session →</a></div>

<?php else:?><div class="stack"><?php foreach($mine as $s):?><div class="card flex" style="justify-content:space-between"><div><strong><?= e($s['title']) ?></strong><div class="meta"><?= e($s['starts_at']) ?> · <?= e($s['status']==='cancelled'?'Cancelled':(strtotime($s['ends_at'])<=time()?'Finished':'Upcoming')) ?></div></div><a class="btn primary" href="<?= e($base.'?room='.(int)$s['id']) ?>">Open Group Chat →</a></div><?php endforeach;?></div><?php endif;?></section>

<?php else: ?>

<section class="section"><h2>Upcoming Kuppiyas</h2><p class="lead">Find a group, join it, then open its chat from My Kuppiyas.</p><?php if(empty($upcoming)):?><div class="empty">No upcoming sessions yet. Create the first one!</div><?php else:?><div class="grid"><?php foreach($upcoming as $s):?><article class="card session"><span class="pill"><?= (int)$s['member_count'] ?> / 12 members</span><h3 style="margin-top:12px"><?= e($s['title']) ?></h3><p class="meta"><?= e($s['starts_at']) ?> to <?= e($s['ends_at']) ?></p><p class="meta">Host: <?= e($s['host_name']) ?></p><a class="btn" href="<?= e($base.((int)$s['is_member']===1?'?room=':'?session=').(int)$s['id']) ?>"><?= (int)$s['is_member']===1?'Open my room':'View & join' ?></a></article><?php endforeach;?></div><?php endif;?></section>

<?php endif; ?>

<p class="meta" style="margin-top:35px">Sessions are archived after their scheduled end. Only members can access group chat and meeting details.</p></main></div><script>document.addEventListener('DOMContentLoaded',function(){

var c=document.getElementById('roomChat');if(c)c.scrollTop=c.scrollHeight;

var field=document.getElementById('message');if(!field)return;

function resize(){field.style.height='46px';field.style.height=Math.min(field.scrollHeight,130)+'px';field.style.overflowY=field.scrollHeight>130?'auto':'hidden';}

field.addEventListener('input',resize);resize();

field.addEventListener('keydown',function(e){if(e.key==='Enter'&&!e.shiftKey&&!e.isComposing){e.preventDefault();if(field.value.trim())field.form.requestSubmit();}});

});</script></body></html>
