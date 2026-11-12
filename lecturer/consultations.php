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

date_default_timezone_set('Asia/Colombo');

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
            $from = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($_POST['date_from'] ?? ''));
            $to = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($_POST['date_to'] ?? ''));
            $start = consultTime((string)($_POST['start_time'] ?? ''));
            $end = consultTime((string)($_POST['end_time'] ?? ''));
            if (!$end || !$start || $end <= $start) throw new RuntimeException('End time must be later than start time.');
            if (!$from || !$to || !$start || $from->format('Y-m-d') !== ($_POST['date_from'] ?? '') || $to->format('Y-m-d') !== ($_POST['date_to'] ?? '')) throw new RuntimeException('Enter valid availability dates and a start time.');
            if ($from < new DateTimeImmutable('today') || $to < $from || $to->diff($from)->days > 84) throw new RuntimeException('Choose future dates within a range of up to 12 weeks.');
            $rawDays = $_POST['weekdays'] ?? [];
            if (!is_array($rawDays)) throw new RuntimeException('Select at least one weekday.');
            $chosenDays = [];
            foreach ($rawDays as $day) {
                if (!is_scalar($day) || !preg_match('/^[1-7]$/', (string)$day)) throw new RuntimeException('Invalid weekday selection.');
                $chosenDays[(int)$day] = true;
            }
            if (!$chosenDays) throw new RuntimeException('Select at least one available weekday.');
            $dates = [];
            foreach (new DatePeriod($from, new DateInterval('P1D'), $to->modify('+1 day')) as $date) {
                if (isset($chosenDays[(int)$date->format('N')])) $dates[] = $date->format('Y-m-d');
            }
            if (!$dates) throw new RuntimeException('The selected weekdays do not occur in this date range.');
            // Preflight every date before writing: one conflicting date must not partially save the schedule.
            $overlap = $pdo->prepare("SELECT start_time, end_time FROM consultation_date_slots WHERE lecturer_id=? AND available_date=? AND is_active=1 AND start_time < ? AND COALESCE(end_time, ADDTIME(start_time, '01:00:00')) > ? AND start_time <> ? LIMIT 1");
            foreach ($dates as $day) {
                $overlap->execute([$lecturerId, $day, $end, $start, $start]);
                if ($conflict = $overlap->fetch(PDO::FETCH_ASSOC)) {
                    throw new RuntimeException('Cannot save: ' . $day . ' overlaps an existing window (' . substr((string)$conflict['start_time'],0,5) . '–' . substr((string)$conflict['end_time'],0,5) . '). Edit that date or choose another time.');
                }
            }
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('INSERT INTO consultation_date_slots (lecturer_id, available_date, start_time, end_time, is_active) VALUES (?,?,?,?,1) ON DUPLICATE KEY UPDATE end_time=VALUES(end_time), is_active=1');
            foreach ($dates as $day) $stmt->execute([$lecturerId, $day, $start, $end]);
            $pdo->commit();
            consultRedirect('Availability saved.');
        } elseif ($action === 'edit_availability') {
            $id = filter_var($_POST['availability_id'] ?? null, FILTER_VALIDATE_INT);
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($_POST['available_date'] ?? ''));
            $start = consultTime((string)($_POST['start_time'] ?? ''));
            $end = consultTime((string)($_POST['end_time'] ?? ''));
            if (!$end || !$start || $end <= $start) throw new RuntimeException('End time must be later than start time.');
            if (!$id || !$date || $date->format('Y-m-d') !== ($_POST['available_date'] ?? '') || $date < new DateTimeImmutable('today') || !$start) throw new RuntimeException('Choose a valid future date and start time.');
            $overlap = $pdo->prepare("SELECT COUNT(*) FROM consultation_date_slots WHERE lecturer_id=? AND available_date=? AND id<>? AND is_active=1 AND start_time < ? AND COALESCE(end_time, ADDTIME(start_time, '01:00:00')) > ?");
            $overlap->execute([$lecturerId, $date->format('Y-m-d'), $id, $end, $start]);
            if ((int)$overlap->fetchColumn()) throw new RuntimeException('This window overlaps another availability window.');
            $stmt = $pdo->prepare('UPDATE consultation_date_slots SET available_date=?, start_time=?, end_time=? WHERE id=? AND lecturer_id=? AND is_active=1');
            try { $stmt->execute([$date->format('Y-m-d'), $start, $end, $id, $lecturerId]); }
            catch (PDOException $ex) { if ($ex->getCode()==='23000') throw new RuntimeException('That availability slot already exists.'); throw $ex; }
            // An unchanged slot is a valid no-op, not an error.
            consultRedirect('Availability saved.');
        } elseif ($action === 'remove_availability') {
            $id = filter_var($_POST['availability_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id) throw new RuntimeException('Invalid availability slot.');
            $stmt = $pdo->prepare('UPDATE consultation_date_slots SET is_active=0 WHERE id=? AND lecturer_id=?');
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

                if (strtotime((string)$booking['starts_at']) > time()) throw new RuntimeException('You can mark a consultation completed once its scheduled start time has passed.');

                $stmt = $pdo->prepare("UPDATE consultation_bookings SET status='completed', lecturer_response=? WHERE id=? AND lecturer_id=? AND status='approved'");

                $stmt->execute([$reply ?: null, $id, $lecturerId]);

            } elseif ($decision === 'propose' && $status === 'pending') {

                $proposedStart = consultDateTime((string)($_POST['proposed_starts_at'] ?? ''));

                if (!$proposedStart || strtotime($proposedStart) <= time()) throw new RuntimeException('Choose a future proposed date and start time.');
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM consultation_bookings WHERE lecturer_id=? AND id<>? AND starts_at=? AND status IN ('pending','approved','reschedule_proposed')");
                $stmt->execute([$lecturerId, $id, $proposedStart]);
                if ((int)$stmt->fetchColumn()) throw new RuntimeException('That start time has already been requested.');
                $stmt = $pdo->prepare("UPDATE consultation_bookings SET status='reschedule_proposed', proposed_starts_at=?, proposed_ends_at=?, lecturer_response=? WHERE id=? AND lecturer_id=? AND status='pending'");

                $stmt->execute([$proposedStart, $proposedStart, $reply ?: null, $id, $lecturerId]);

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

    $stmt = $pdo->prepare('SELECT id, available_date, start_time, end_time FROM consultation_date_slots WHERE lecturer_id=? AND is_active=1 AND available_date>=CURDATE() ORDER BY available_date, start_time');

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

<style>:root{--bg:#f7f8fc;--card:#fff;--text:#1b1d2c;--muted:#777b90;--border:#e7e8ef;--accent:#6856e8;--soft:#f0edff} [data-theme="dark"]{--bg:#10121c;--card:#1b1e2b;--text:#f5f5fa;--muted:#a2a6b8;--border:#303344;--accent:#b1a4ff;--soft:#2c2745}*{box-sizing:border-box}body{margin:0;font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:var(--bg);color:var(--text)}.layout{display:grid;grid-template-columns:245px minmax(0,1fr);min-height:100vh}.sidebar{background:var(--card);border-right:1px solid var(--border);padding:30px 20px}.brand{font-size:27px;font-weight:800;letter-spacing:-1.5px;margin-bottom:50px}.brand span{color:var(--accent)}.nav-label,.eyebrow{color:var(--muted);font-size:11px;font-weight:750;letter-spacing:1.5px}.nav-label{margin-bottom:15px}.nav a{display:block;padding:13px 15px;margin-bottom:7px;border-radius:12px;text-decoration:none;color:var(--text);font-size:14px}.nav a:hover,.nav a.active{background:var(--soft);color:var(--accent)}main{padding:35px clamp(20px,5vw,75px);min-width:0}.topbar{display:flex;justify-content:space-between;align-items:center;gap:15px;margin-bottom:48px}.topbar-right{display:flex;align-items:center;gap:14px}.theme-button{width:42px;height:42px;border:1px solid var(--border);border-radius:12px;background:var(--card);color:var(--text);cursor:pointer;font-size:20px}.avatar{width:42px;height:42px;border-radius:50%;background:var(--soft);color:var(--accent);display:grid;place-items:center;font-weight:800}h1{font-size:clamp(32px,4vw,48px);letter-spacing:-1.6px;margin:12px 0}h2{font-size:22px;margin:0 0 20px}.subtitle,.muted{color:var(--muted);line-height:1.6}.section{margin-top:38px}.card{background:var(--card);border:1px solid var(--border);border-radius:19px;padding:24px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px}.field{display:flex;flex-direction:column;gap:7px}.field label{font-size:13px;font-weight:700}input,select,textarea{font:inherit;width:100%;padding:11px;border:1px solid var(--border);border-radius:10px;background:var(--card);color:var(--text)}textarea{min-height:72px;resize:vertical}.btn{font:inherit;font-size:13px;font-weight:700;border:1px solid var(--border);border-radius:11px;background:var(--card);color:var(--text);padding:10px 14px;cursor:pointer}.btn.primary{background:var(--accent);border-color:var(--accent);color:#fff}.btn:hover{opacity:.85}.flex{display:flex;gap:10px;flex-wrap:wrap;align-items:center}.slot,.booking{margin-top:12px}.slot{display:flex;justify-content:space-between;gap:12px;align-items:center}.pill{display:inline-block;background:var(--soft);color:var(--accent);border-radius:100px;padding:5px 10px;font-size:12px;font-weight:700}.meta{font-size:13px;color:var(--muted);line-height:1.7}.message{white-space:pre-wrap;overflow-wrap:anywhere}.notice{padding:14px;border-radius:12px;margin-bottom:16px;background:var(--soft);color:var(--text)}.error{background:#ffe6e9;color:#9b203c}.empty{color:var(--muted);padding:24px;border:1px dashed var(--border);border-radius:15px}.booking h3{margin:10px 0}.booking-actions{display:grid;gap:12px;margin-top:15px}.propose{border-top:1px solid var(--border);padding-top:15px;margin-top:15px}a{color:inherit}@media(max-width:850px){.layout{grid-template-columns:1fr}.sidebar{border-right:0;border-bottom:1px solid var(--border);padding:20px}.brand{margin-bottom:20px}main{padding:25px 20px}.topbar{margin-bottom:30px}}@media(max-width:550px){.slot{align-items:flex-start;flex-direction:column}}</style><link rel="stylesheet" href="<?= e(url('assets/css/consultations-premium.css')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/ulearn-design-system.css?v=1')) ?>">
<script defer src="<?= e(url('assets/js/ulearn-motion.js?v=1')) ?>"></script>
<script src="<?= htmlspecialchars(url('assets/js/theme-global.js?v=20261112-1'), ENT_QUOTES, 'UTF-8') ?>"></script>
</head><body><div class="layout"><aside class="sidebar"><div class="brand">u learn<span>.</span></div><div class="nav-label">LECTURER WORKSPACE</div><nav class="nav"><a href="<?= e(url('lecturer/index.php')) ?>">◫ Overview</a><a href="<?= e(url('lecturer/index.php')) ?>#modules">▤ My Modules</a><a href="<?= e(url('lecturer/materials.php')) ?>">▣ Study Materials</a><a href="<?= e(url('lecturer/quizzes.php')) ?>">☷ Quiz Management</a><a class="active" href="<?= e(url('lecturer/consultations.php')) ?>">◷ Consultations</a><a href="<?= e(url('dashboard/notifications.php')) ?>">♧ Notifications</a></nav></aside><main><header class="topbar"><div class="eyebrow">U LEARN / LECTURER PORTAL</div><div class="topbar-right"><button id="themeButton" class="theme-button" type="button" onclick="toggleTheme()" aria-label="Toggle appearance" title="Change theme">☾</button><div class="avatar"><?= e(strtoupper(substr((string)$user['full_name'],0,1))) ?></div></div></header><div class="consult-hero"><div class="eyebrow">CONSULTATION MANAGEMENT</div><h1>Consultations<span style="color:var(--accent)">.</span></h1><p class="subtitle">Publish appointment start times on specific dates, manage your availability, and respond to student requests.</p><div class="consult-stats"><div class="consult-stat"><strong><?= count($bookings) ?></strong><span>Total requests</span></div><div class="consult-stat"><strong><?= $pending ?></strong><span>Awaiting response</span></div><div class="consult-stat"><strong><?= count($availability) ?></strong><span>Availability slots</span></div></div></div>

<?php if($error!==''):?><div class="notice error" role="alert"><?= e($error) ?></div><?php endif;?><?php if($success!==''):?><div class="notice" role="status"><?= e($success) ?></div><?php endif;?>

<section class="section" id="availability"><div class="availability-heading"><div><div class="eyebrow">YOUR SCHEDULE</div><h2>Availability</h2><p class="muted">Set one or more consultation windows for each weekday. Breaks between windows stay unavailable.</p></div><button class="btn primary" type="button" id="openSchedule">+ Add availability</button></div>
<div class="card availability-editor" id="scheduleEditor" hidden>
<form method="post" class="weekday-form"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="add_availability">
<div class="editor-head"><strong>New availability window</strong><button class="btn" type="button" id="closeSchedule">Close</button></div>
<div class="field"><label>Repeat on</label><div class="weekday-picker" role="group" aria-label="Available weekdays"><?php foreach ($weekdays as $number => $name): ?><label class="weekday-option"><input type="checkbox" name="weekdays[]" value="<?= $number ?>" <?= $number <= 5 ? 'checked' : '' ?>><span><?= e(substr($name, 0, 3)) ?></span></label><?php endforeach; ?></div></div>
<div class="grid"><div class="field"><label>From date</label><input id="date_from" name="date_from" type="date" min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" required></div><div class="field"><label>Until date</label><input id="date_to" name="date_to" type="date" min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d',strtotime('+28 days')) ?>" required></div><div class="field"><label>Start time</label><input name="start_time" type="time" value="09:00" required></div><div class="field"><label>End time</label><input name="end_time" type="time" value="11:00" required></div></div>
<p class="muted" style="font-size:13px">Repeat selected weekdays for up to 12 weeks. Add another window to leave a gap for meetings or events.</p><button class="btn primary" type="submit">Publish availability</button></form></div>
<div class="card schedule-board"><div class="schedule-board-header"><strong>Upcoming windows</strong><span class="pill"><?= count($availability) ?> scheduled</span></div><div class="schedule-tabs" role="tablist" aria-label="Filter by weekday"><button class="schedule-tab active" data-day="all" type="button">All</button><?php foreach($weekdays as $number=>$name): ?><button class="schedule-tab" data-day="<?= $number ?>" type="button"><?= e(substr($name,0,3)) ?></button><?php endforeach; ?></div>
<?php if(!$availability): ?><div class="empty">No upcoming availability yet. Add your first time window.</div><?php else: ?>
<?php $previousDate=''; foreach($availability as $slot): $dateString=(string)$slot['available_date']; $weekday=(int)(new DateTimeImmutable($dateString))->format('N'); ?>
<div class="schedule-item" data-day="<?= $weekday ?>"><div class="schedule-date"><strong><?= e(date('D, M j',strtotime($dateString))) ?></strong><small><?= e($dateString) ?></small></div><form method="post" class="schedule-edit"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="edit_availability"><input type="hidden" name="availability_id" value="<?= (int)$slot['id'] ?>"><label class="sr-only">Date<input type="date" name="available_date" value="<?= e($dateString) ?>" min="<?= date('Y-m-d') ?>" required></label><label class="schedule-time">From <input type="time" name="start_time" value="<?= e(substr((string)$slot['start_time'],0,5)) ?>" required></label><span class="schedule-dash">–</span><label class="schedule-time">To <input type="time" name="end_time" value="<?= e(substr((string)$slot['end_time'],0,5)) ?>" required></label><button class="btn" type="submit">Update</button></form><form method="post" onsubmit="return confirm('Remove this availability window?')"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="remove_availability"><input type="hidden" name="availability_id" value="<?= (int)$slot['id'] ?>"><button class="btn remove-window" type="submit" aria-label="Remove window">×</button></form></div>
<?php endforeach; ?><?php endif; ?></div>
<style>.availability-heading,.schedule-board-header,.editor-head{display:flex;justify-content:space-between;align-items:center;gap:18px}.availability-heading{margin-bottom:20px}.availability-heading h2{margin:8px 0}.availability-heading p{margin:0}.availability-editor{margin-bottom:20px}.availability-editor[hidden]{display:none}.weekday-form{display:grid;gap:20px}.weekday-picker{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:9px}.weekday-option{position:relative;cursor:pointer}.weekday-option input{position:absolute;opacity:0;width:1px;height:1px}.weekday-option span{display:flex;align-items:center;justify-content:center;min-height:48px;border:1px solid var(--border);border-radius:12px;background:var(--card);font-size:13px;font-weight:750;color:var(--muted)}.weekday-option input:checked+span{border-color:var(--accent);background:var(--soft);color:var(--accent)}.weekday-option input:focus-visible+span{outline:2px solid var(--accent)}.schedule-board{padding:22px}.schedule-board-header{margin-bottom:18px}.schedule-tabs{display:flex;gap:6px;overflow-x:auto;padding-bottom:14px;border-bottom:1px solid var(--border)}.schedule-tab{border:0;background:transparent;color:var(--muted);padding:10px 13px;border-radius:10px;font-weight:700;cursor:pointer;white-space:nowrap}.schedule-tab.active{background:var(--soft);color:var(--accent)}.schedule-item{display:flex;align-items:center;gap:15px;padding:15px 0;border-bottom:1px solid var(--border)}.schedule-item:last-child{border-bottom:0}.schedule-date{width:135px;flex-shrink:0;display:grid;gap:4px;font-size:13px}.schedule-date small{color:var(--muted)}.schedule-edit{flex:1;display:flex;align-items:center;gap:10px;flex-wrap:wrap}.schedule-time{font-size:11px;color:var(--muted);display:flex;align-items:center;gap:7px}.schedule-time input{width:125px;padding:9px}.schedule-dash{color:var(--muted)}.remove-window{font-size:21px;color:#c24b5d}.sr-only{position:absolute;width:1px;height:1px;overflow:hidden;clip-path:inset(50%)}.schedule-item[hidden]{display:none}@media(max-width:780px){.schedule-item{align-items:flex-start;flex-wrap:wrap}.schedule-date{width:100%}.availability-heading{align-items:flex-start;flex-direction:column}.schedule-time input{width:110px}.weekday-picker{gap:4px}.weekday-option span{min-height:43px;font-size:11px}}</style>
<script>(function(){const editor=document.getElementById('scheduleEditor');document.getElementById('openSchedule').addEventListener('click',()=>{editor.hidden=false;editor.scrollIntoView({behavior:'smooth',block:'nearest'});});document.getElementById('closeSchedule').addEventListener('click',()=>editor.hidden=true);document.querySelectorAll('.schedule-tab').forEach(btn=>btn.addEventListener('click',()=>{document.querySelectorAll('.schedule-tab').forEach(b=>b.classList.toggle('active',b===btn));document.querySelectorAll('.schedule-item').forEach(row=>row.hidden=btn.dataset.day!=='all'&&row.dataset.day!==btn.dataset.day);}));})();</script>
</section>
<section class="section"><h2>Student requests <span class="pill"><?= $pending ?> pending</span></h2><?php if(!$bookings):?><div class="empty">No consultation requests yet. Students will be able to request sessions once the student booking page is installed.</div><?php else:?><?php foreach($bookings as $booking):?><article class="card booking"><a class="btn" style="float:right" href="<?= e(url('consultation-detail.php?id='.(int)$booking['id'])) ?>">Open chat →</a><div class="flex"><span class="pill"><?= e(str_replace('_',' ',ucfirst((string)$booking['status']))) ?></span><span class="meta">Request #<?= (int)$booking['id'] ?></span></div><h3><?= e((string)$booking['topic']) ?></h3><div class="meta"><strong><?= e((string)$booking['student_name']) ?></strong> · <?= e((string)$booking['module_code']) ?> — <?= e((string)$booking['module_name']) ?><br>Requested: <?= e((string)$booking['starts_at']) ?> · In person</div><?php if(!empty($booking['student_message'])):?><p class="message"><?= e((string)$booking['student_message']) ?></p><?php endif;?><?php if(!empty($booking['lecturer_response'])):?><p class="message"><strong>Your response:</strong> <?= e((string)$booking['lecturer_response']) ?></p><?php endif;?><?php if(!empty($booking['proposed_starts_at'])):?><p class="meta">Proposed: <?= e((string)$booking['proposed_starts_at']) ?></p><?php endif;?><?php if(in_array((string)$booking['status'],['pending','approved'],true)):?><form method="post" class="booking-actions"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="booking"><input type="hidden" name="booking_id" value="<?= (int)$booking['id'] ?>"><div class="field"><label for="reply<?= (int)$booking['id'] ?>">Message to student (optional)</label><textarea id="reply<?= (int)$booking['id'] ?>" name="lecturer_response" maxlength="5000"></textarea></div><div class="flex"><?php if($booking['status']==='pending'):?><button class="btn primary" type="submit" name="decision" value="approve">Approve</button><button class="btn" type="submit" name="decision" value="decline" onclick="return confirm('Decline this request?')">Decline</button><?php else:?><button class="btn primary" type="submit" name="decision" value="complete">Mark completed</button><?php endif;?></div><?php if($booking['status']==='pending'):?><div class="propose"><strong>Suggest another time</strong><div class="grid" style="margin:12px 0"><div class="field"><label for="ps<?= (int)$booking['id'] ?>">New date and start time</label><input id="ps<?= (int)$booking['id'] ?>" type="datetime-local" name="proposed_starts_at"></div></div><button class="btn" type="submit" name="decision" value="propose">Propose reschedule</button></div><?php endif;?></form><?php endif;?></article><?php endforeach;?><?php endif;?></section></main></div>
</body></html>
