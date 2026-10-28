<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$user = require_login();

if ((string)$user['role'] !== 'lecturer') { http_response_code(403); exit('Lecturer access only.'); }

$pdo = db();

$stmt = $pdo->prepare('SELECT id, is_active FROM lecturer_profiles WHERE user_id = ? LIMIT 1');

$stmt->execute([(int)$user['id']]);

$profile = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$profile || (int)$profile['is_active'] !== 1) { http_response_code(403); exit('Active lecturer profile required.'); }

$lecturerId = (int)$profile['id'];

// Notification recipient is users.id, not lecturer_profiles.id.
$actorUserId = (int)$user['id'];

$error = ''; $success = '';

$weekdays = [1=>'Monday',2=>'Tuesday',3=>'Wednesday',4=>'Thursday',5=>'Friday',6=>'Saturday',7=>'Sunday'];

function consultTime(string $value): ?string {

    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value)) return null;

    return $value . ':00';

}

function consultDateTime(string $value): ?string {

    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value);

    return $date && $date->format('Y-m-d\TH:i') === $value ? $date->format('Y-m-d H:i:s') : null;

}

function consultRedirect(string $message): never { go('lecturer/consultations.php?notice=' . rawurlencode($message)); }

if (isset($_GET['notice']) && is_string($_GET['notice'])) {

    $known = ['Availability saved.', 'Availability removed.', 'Booking updated.'];

    if (in_array($_GET['notice'], $known, true)) $success = $_GET['notice'];

}

try {

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {

        csrf_check();

        $action = (string)($_POST['action'] ?? '');

        if ($action === 'add_availability') {

            $weekday = filter_var($_POST['weekday'] ?? null, FILTER_VALIDATE_INT);

            $start = consultTime((string)($_POST['start_time'] ?? ''));

            $end = consultTime((string)($_POST['end_time'] ?? ''));

            if (!$weekday || !isset($weekdays[$weekday]) || !$start || !$end || $start >= $end) throw new RuntimeException('Choose a valid day and time range.');

            $stmt = $pdo->prepare('SELECT COUNT(*) FROM consultation_availability WHERE lecturer_id=? AND weekday=? AND is_active=1 AND start_time < ? AND end_time > ?');

            $stmt->execute([$lecturerId, $weekday, $end, $start]);

            if ((int)$stmt->fetchColumn() > 0) throw new RuntimeException('This availability overlaps an existing active slot.');

            $stmt = $pdo->prepare('INSERT INTO consultation_availability (lecturer_id, weekday, start_time, end_time, is_active) VALUES (?,?,?,?,1)');

            $stmt->execute([$lecturerId, $weekday, $start, $end]);

            consultRedirect('Availability saved.');

        } elseif ($action === 'remove_availability') {

            $id = filter_var($_POST['availability_id'] ?? null, FILTER_VALIDATE_INT);

            if (!$id) throw new RuntimeException('Invalid availability slot.');

            $stmt = $pdo->prepare('UPDATE consultation_availability SET is_active=0 WHERE id=? AND lecturer_id=?');

            $stmt->execute([$id, $lecturerId]);

            consultRedirect('Availability removed.');

        } elseif ($action === 'booking') {

            $id = filter_var($_POST['booking_id'] ?? null, FILTER_VALIDATE_INT);

            $decision = (string)($_POST['decision'] ?? '');

            if (!$id || !in_array($decision, ['approve','decline','propose','complete'], true)) throw new RuntimeException('Invalid booking action.');

            $stmt = $pdo->prepare('SELECT id, student_id, topic, status, starts_at, ends_at FROM consultation_bookings WHERE id=? AND lecturer_id=? LIMIT 1 FOR UPDATE');

            $pdo->beginTransaction();

            $stmt->execute([$id, $lecturerId]);

            $booking = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$booking) throw new RuntimeException('Booking not found.');

            $status = (string)$booking['status'];

            $reply = trim((string)($_POST['lecturer_response'] ?? ''));

            if (mb_strlen($reply) > 5000) throw new RuntimeException('Your response is too long.');

            if ($decision === 'approve' && $status === 'pending') {

                $stmt = $pdo->prepare("UPDATE consultation_bookings SET status='approved', lecturer_response=? WHERE id=? AND lecturer_id=? AND status='pending'");

                $stmt->execute([$reply ?: null, $id, $lecturerId]);

            } elseif ($decision === 'decline' && $status === 'pending') {

                $stmt = $pdo->prepare("UPDATE consultation_bookings SET status='declined', lecturer_response=? WHERE id=? AND lecturer_id=? AND status='pending'");

                $stmt->execute([$reply ?: null, $id, $lecturerId]);

            } elseif ($decision === 'complete' && $status === 'approved') {

                if (strtotime((string)$booking['ends_at']) > time()) throw new RuntimeException('You can mark a consultation completed after its scheduled end time.');

                $stmt = $pdo->prepare("UPDATE consultation_bookings SET status='completed', lecturer_response=? WHERE id=? AND lecturer_id=? AND status='approved'");

                $stmt->execute([$reply ?: null, $id, $lecturerId]);

            } elseif ($decision === 'propose' && $status === 'pending') {

                $proposedStart = consultDateTime((string)($_POST['proposed_starts_at'] ?? ''));

                $proposedEnd = consultDateTime((string)($_POST['proposed_ends_at'] ?? ''));

                if (!$proposedStart || !$proposedEnd || $proposedStart >= $proposedEnd || strtotime($proposedStart) <= time()) throw new RuntimeException('Enter a valid future rescheduling interval.');

                $stmt = $pdo->prepare("UPDATE consultation_bookings SET status='reschedule_proposed', proposed_starts_at=?, proposed_ends_at=?, lecturer_response=? WHERE id=? AND lecturer_id=? AND status='pending'");

                $stmt->execute([$proposedStart, $proposedEnd, $reply ?: null, $id, $lecturerId]);

            } else throw new RuntimeException('This booking can no longer be changed using that action.');

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('This booking was already changed. Refresh and try again.');
            }

            $notificationDetails = match ($decision) {
                'approve' => ['consultation_approved', 'Consultation approved', 'Your consultation request has been approved.'],
                'decline' => ['consultation_declined', 'Consultation declined', 'Your consultation request has been declined.'],
                'propose' => ['consultation_reschedule', 'New consultation time proposed', 'Your lecturer has proposed another time for your consultation.'],
                'complete' => ['consultation_completed', 'Consultation completed', 'Your lecturer has marked your consultation as completed.'],
            };
            $notificationMessage = $notificationDetails[2] . ' Topic: ' . (string)$booking['topic'];
            $notificationStmt = $pdo->prepare('INSERT INTO notifications (user_id, actor_user_id, type, title, message, target_url) VALUES (?, ?, ?, ?, ?, ?)');
            $notificationStmt->execute([
                (int)$booking['student_id'],
                $actorUserId,
                $notificationDetails[0],
                $notificationDetails[1],
                $notificationMessage,
                url('dashboard/consultations.php'),
            ]);
            $pdo->commit();
            consultRedirect('Booking updated.');

        } else throw new RuntimeException('Unknown action.');

    }

    $stmt = $pdo->prepare('SELECT id, weekday, start_time, end_time FROM consultation_availability WHERE lecturer_id=? AND is_active=1 ORDER BY weekday, start_time');

    $stmt->execute([$lecturerId]);

    $availability = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare('SELECT cb.id, cb.topic, cb.student_message, cb.lecturer_response, cb.starts_at, cb.ends_at, cb.status, cb.proposed_starts_at, cb.proposed_ends_at, cb.created_at, u.full_name AS student_name, m.module_code, m.module_name FROM consultation_bookings cb INNER JOIN users u ON u.id=cb.student_id INNER JOIN academic_modules m ON m.id=cb.module_id WHERE cb.lecturer_id=? ORDER BY CASE WHEN cb.status = \'pending\' THEN 0 ELSE 1 END, cb.starts_at DESC, cb.id DESC LIMIT 150');

    $stmt->execute([$lecturerId]);

    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $ex) {

    if ($pdo->inTransaction()) $pdo->rollBack();

    if ($ex instanceof RuntimeException) $error = $ex->getMessage();

    else { error_log('ULearn consultation lecturer error: '.$ex->getMessage()); $error = 'Consultations could not be loaded. Check the Stage 6 database tables.'; }

}

$availability = $availability ?? []; $bookings = $bookings ?? [];

$pending = count(array_filter($bookings, static fn(array $b): bool => $b['status'] === 'pending'));

?>

<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Consultations | ULearn</title>

<script>(function(){try{let t=localStorage.getItem('ulearn-theme');if(t!=='light'&&t!=='dark')t=window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';document.documentElement.dataset.theme=t;}catch(e){}})();function toggleTheme(){let t=document.documentElement.dataset.theme==='dark'?'light':'dark';document.documentElement.dataset.theme=t;try{localStorage.setItem('ulearn-theme',t);}catch(e){}updateThemeButton();}function updateThemeButton(){let b=document.getElementById('themeButton');if(b){let dark=document.documentElement.dataset.theme==='dark';b.textContent=dark?'☀':'☾';b.setAttribute('aria-label',dark?'Switch to light mode':'Switch to dark mode');}}document.addEventListener('DOMContentLoaded',updateThemeButton);</script>

<style>:root{--bg:#f7f8fc;--card:#fff;--text:#1b1d2c;--muted:#777b90;--border:#e7e8ef;--accent:#6856e8;--soft:#f0edff} [data-theme="dark"]{--bg:#10121c;--card:#1b1e2b;--text:#f5f5fa;--muted:#a2a6b8;--border:#303344;--accent:#b1a4ff;--soft:#2c2745}*{box-sizing:border-box}body{margin:0;font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:var(--bg);color:var(--text)}.layout{display:grid;grid-template-columns:245px minmax(0,1fr);min-height:100vh}.sidebar{background:var(--card);border-right:1px solid var(--border);padding:30px 20px}.brand{font-size:27px;font-weight:800;letter-spacing:-1.5px;margin-bottom:50px}.brand span{color:var(--accent)}.nav-label,.eyebrow{color:var(--muted);font-size:11px;font-weight:750;letter-spacing:1.5px}.nav-label{margin-bottom:15px}.nav a{display:block;padding:13px 15px;margin-bottom:7px;border-radius:12px;text-decoration:none;color:var(--text);font-size:14px}.nav a:hover,.nav a.active{background:var(--soft);color:var(--accent)}main{padding:35px clamp(20px,5vw,75px);min-width:0}.topbar{display:flex;justify-content:space-between;align-items:center;gap:15px;margin-bottom:48px}.topbar-right{display:flex;align-items:center;gap:14px}.theme-button{width:42px;height:42px;border:1px solid var(--border);border-radius:12px;background:var(--card);color:var(--text);cursor:pointer;font-size:20px}.avatar{width:42px;height:42px;border-radius:50%;background:var(--soft);color:var(--accent);display:grid;place-items:center;font-weight:800}h1{font-size:clamp(32px,4vw,48px);letter-spacing:-1.6px;margin:12px 0}h2{font-size:22px;margin:0 0 20px}.subtitle,.muted{color:var(--muted);line-height:1.6}.section{margin-top:38px}.card{background:var(--card);border:1px solid var(--border);border-radius:19px;padding:24px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px}.field{display:flex;flex-direction:column;gap:7px}.field label{font-size:13px;font-weight:700}input,select,textarea{font:inherit;width:100%;padding:11px;border:1px solid var(--border);border-radius:10px;background:var(--card);color:var(--text)}textarea{min-height:72px;resize:vertical}.btn{font:inherit;font-size:13px;font-weight:700;border:1px solid var(--border);border-radius:11px;background:var(--card);color:var(--text);padding:10px 14px;cursor:pointer}.btn.primary{background:var(--accent);border-color:var(--accent);color:#fff}.btn:hover{opacity:.85}.flex{display:flex;gap:10px;flex-wrap:wrap;align-items:center}.slot,.booking{margin-top:12px}.slot{display:flex;justify-content:space-between;gap:12px;align-items:center}.pill{display:inline-block;background:var(--soft);color:var(--accent);border-radius:100px;padding:5px 10px;font-size:12px;font-weight:700}.meta{font-size:13px;color:var(--muted);line-height:1.7}.message{white-space:pre-wrap;overflow-wrap:anywhere}.notice{padding:14px;border-radius:12px;margin-bottom:16px;background:var(--soft);color:var(--text)}.error{background:#ffe6e9;color:#9b203c}.empty{color:var(--muted);padding:24px;border:1px dashed var(--border);border-radius:15px}.booking h3{margin:10px 0}.booking-actions{display:grid;gap:12px;margin-top:15px}.propose{border-top:1px solid var(--border);padding-top:15px;margin-top:15px}a{color:inherit}@media(max-width:850px){.layout{grid-template-columns:1fr}.sidebar{border-right:0;border-bottom:1px solid var(--border);padding:20px}.brand{margin-bottom:20px}main{padding:25px 20px}.topbar{margin-bottom:30px}}@media(max-width:550px){.slot{align-items:flex-start;flex-direction:column}}</style></head><body><div class="layout"><aside class="sidebar"><div class="brand">u learn<span>.</span></div><div class="nav-label">LECTURER WORKSPACE</div><nav class="nav"><a href="<?= e(url('lecturer/index.php')) ?>">◫ Overview</a><a href="<?= e(url('lecturer/index.php')) ?>#modules">▤ My Modules</a><a href="<?= e(url('lecturer/materials.php')) ?>">▣ Study Materials</a><a href="<?= e(url('lecturer/quizzes.php')) ?>">☷ Quiz Management</a><a class="active" href="<?= e(url('lecturer/consultations.php')) ?>">◷ Consultations</a><a href="<?= e(url('dashboard/notifications.php')) ?>">♧ Notifications</a></nav></aside><main><header class="topbar"><div class="eyebrow">U LEARN / LECTURER PORTAL</div><div class="topbar-right"><button id="themeButton" class="theme-button" type="button" onclick="toggleTheme()" aria-label="Toggle appearance" title="Change theme">☾</button><div class="avatar"><?= e(strtoupper(substr((string)$user['full_name'],0,1))) ?></div></div></header><div class="eyebrow">CONSULTATION MANAGEMENT</div><h1>Consultations<span style="color:var(--accent)">.</span></h1><p class="subtitle">Set your weekly consultation hours and respond to student requests.</p>

<?php if($error!==''):?><div class="notice error" role="alert"><?= e($error) ?></div><?php endif;?><?php if($success!==''):?><div class="notice" role="status"><?= e($success) ?></div><?php endif;?>

<section class="section"><h2>Weekly availability</h2><div class="card"><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="add_availability"><div class="grid"><div class="field"><label for="weekday">Day</label><select id="weekday" name="weekday" required><?php foreach($weekdays as $n=>$day):?><option value="<?= $n ?>"><?= e($day) ?></option><?php endforeach;?></select></div><div class="field"><label for="start_time">From</label><input id="start_time" name="start_time" type="time" required></div><div class="field"><label for="end_time">Until</label><input id="end_time" name="end_time" type="time" required></div></div><p><button class="btn primary" type="submit">Add available hours</button></p></form></div><?php if(!$availability):?><p class="empty">No weekly availability configured yet.</p><?php else:?><?php foreach($availability as $slot):?><div class="card slot"><div><strong><?= e($weekdays[(int)$slot['weekday']] ?? 'Unknown') ?></strong><div class="meta"><?= e(substr((string)$slot['start_time'],0,5)) ?> – <?= e(substr((string)$slot['end_time'],0,5)) ?></div></div><form method="post" onsubmit="return confirm('Remove this availability slot?')"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="remove_availability"><input type="hidden" name="availability_id" value="<?= (int)$slot['id'] ?>"><button class="btn" type="submit">Remove</button></form></div><?php endforeach;?><?php endif;?></section>

<section class="section"><h2>Student requests <span class="pill"><?= $pending ?> pending</span></h2><?php if(!$bookings):?><div class="empty">No consultation requests yet. Students will be able to request sessions once the student booking page is installed.</div><?php else:?><?php foreach($bookings as $booking):?><article class="card booking"><div class="flex"><span class="pill"><?= e(str_replace('_',' ',ucfirst((string)$booking['status']))) ?></span><span class="meta">Request #<?= (int)$booking['id'] ?></span></div><h3><?= e((string)$booking['topic']) ?></h3><div class="meta"><strong><?= e((string)$booking['student_name']) ?></strong> · <?= e((string)$booking['module_code']) ?> — <?= e((string)$booking['module_name']) ?><br>Requested: <?= e((string)$booking['starts_at']) ?> to <?= e((string)$booking['ends_at']) ?></div><?php if(!empty($booking['student_message'])):?><p class="message"><?= e((string)$booking['student_message']) ?></p><?php endif;?><?php if(!empty($booking['lecturer_response'])):?><p class="message"><strong>Your response:</strong> <?= e((string)$booking['lecturer_response']) ?></p><?php endif;?><?php if(!empty($booking['proposed_starts_at'])):?><p class="meta">Proposed: <?= e((string)$booking['proposed_starts_at']) ?> to <?= e((string)$booking['proposed_ends_at']) ?></p><?php endif;?><?php if(in_array((string)$booking['status'],['pending','approved'],true)):?><form method="post" class="booking-actions"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="booking"><input type="hidden" name="booking_id" value="<?= (int)$booking['id'] ?>"><div class="field"><label for="reply<?= (int)$booking['id'] ?>">Message to student (optional)</label><textarea id="reply<?= (int)$booking['id'] ?>" name="lecturer_response" maxlength="5000"></textarea></div><div class="flex"><?php if($booking['status']==='pending'):?><button class="btn primary" type="submit" name="decision" value="approve">Approve</button><button class="btn" type="submit" name="decision" value="decline" onclick="return confirm('Decline this request?')">Decline</button><?php else:?><button class="btn primary" type="submit" name="decision" value="complete">Mark completed</button><?php endif;?></div><?php if($booking['status']==='pending'):?><div class="propose"><strong>Suggest another time</strong><div class="grid" style="margin:12px 0"><div class="field"><label for="ps<?= (int)$booking['id'] ?>">New start</label><input id="ps<?= (int)$booking['id'] ?>" type="datetime-local" name="proposed_starts_at"></div><div class="field"><label for="pe<?= (int)$booking['id'] ?>">New end</label><input id="pe<?= (int)$booking['id'] ?>" type="datetime-local" name="proposed_ends_at"></div></div><button class="btn" type="submit" name="decision" value="propose">Propose reschedule</button></div><?php endif;?></form><?php endif;?></article><?php endforeach;?><?php endif;?></section></main></div></body></html>
