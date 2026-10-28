<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
$user = require_login();
if ((string)$user['role'] !== 'student') { http_response_code(403); exit('Student access only.'); }
$pdo = db();
$userId = (int)$user['id'];
$error = '';
$success = '';
$days = [1=>'Monday',2=>'Tuesday',3=>'Wednesday',4=>'Thursday',5=>'Friday',6=>'Saturday',7=>'Sunday'];
function studentConsultDate(string $value): ?DateTimeImmutable {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value);
    return $d && $d->format('Y-m-d\TH:i') === $value ? $d : null;
}
/** Save a lecturer-facing event in the existing Stage 8 notification table. */
function studentConsultNotify(PDO $pdo, int $lecturerId, int $studentId, string $type, string $title, string $message): void {
    $stmt = $pdo->prepare('SELECT user_id FROM lecturer_profiles WHERE id = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$lecturerId]);
    $recipientId = (int)$stmt->fetchColumn();
    if ($recipientId <= 0) {
        throw new RuntimeException('The selected lecturer account is unavailable.');
    }
    $stmt = $pdo->prepare('INSERT INTO notifications (user_id, actor_user_id, type, title, message, target_url, is_read) VALUES (?, ?, ?, ?, ?, ?, 0)');
    $stmt->execute([$recipientId, $studentId, $type, $title, $message, 'lecturer/consultations.php']);
}
function studentConsultGo(string $message): never {
    go('dashboard/consultations.php?notice=' . rawurlencode($message));
}
if (isset($_GET['notice']) && is_string($_GET['notice']) && in_array($_GET['notice'], ['Request submitted.', 'Booking cancelled.', 'Reschedule accepted.', 'Reschedule declined.'], true)) {
    $success = $_GET['notice'];
}
try {
    // A lecturer is eligible only when assigned to a module this student enrolled in.
    $eligibleSql = "SELECT DISTINCT lp.id AS lecturer_id, u.full_name AS lecturer_name, lp.title, lp.designation, m.id AS module_id, m.module_code, m.module_name
        FROM student_module_enrollments sme
        INNER JOIN academic_modules m ON m.id=sme.module_id AND m.is_active=1
        INNER JOIN academic_curriculum c ON c.module_id=m.id AND c.is_active=1
        INNER JOIN lecturer_module_assignments lma ON lma.curriculum_id=c.id AND lma.is_active=1
        INNER JOIN lecturer_profiles lp ON lp.id=lma.lecturer_id AND lp.is_active=1
        INNER JOIN users u ON u.id=lp.user_id AND u.role='lecturer'
        WHERE sme.user_id=? ORDER BY m.module_code, u.full_name";
    $stmt = $pdo->prepare($eligibleSql);
    $stmt->execute([$userId]);
    $eligible = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $eligiblePairs = [];
    $lecturerIds = [];
    foreach ($eligible as $entry) {
        $eligiblePairs[(int)$entry['lecturer_id'].':'.(int)$entry['module_id']] = true;
        $lecturerIds[(int)$entry['lecturer_id']] = true;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        csrf_check();
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'request') {
            $lecturerId = filter_var($_POST['lecturer_id'] ?? null, FILTER_VALIDATE_INT);
            $moduleId = filter_var($_POST['module_id'] ?? null, FILTER_VALIDATE_INT);
            $start = studentConsultDate((string)($_POST['starts_at'] ?? ''));
            $end = studentConsultDate((string)($_POST['ends_at'] ?? ''));
            $topic = trim((string)($_POST['topic'] ?? ''));
            $message = trim((string)($_POST['student_message'] ?? ''));
            if (!$lecturerId || !$moduleId || !isset($eligiblePairs[$lecturerId.':'.$moduleId])) throw new RuntimeException('Select a lecturer teaching one of your enrolled modules.');
            if (!$start || !$end || $start <= new DateTimeImmutable('now') || $end <= $start) throw new RuntimeException('Choose a valid future consultation start and end time.');
            if ($end->getTimestamp() - $start->getTimestamp() > 7200) throw new RuntimeException('Consultations must be two hours or shorter.');
            if ($start->format('Y-m-d') !== $end->format('Y-m-d')) throw new RuntimeException('Consultations must begin and end on the same day.');
            if ($topic === '' || mb_strlen($topic) > 180 || mb_strlen($message) > 5000) throw new RuntimeException('Enter a topic (up to 180 characters) and a message under 5000 characters.');
            $weekday = (int)$start->format('N');
            $startTime = $start->format('H:i:s');
            $endTime = $end->format('H:i:s');
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM consultation_availability WHERE lecturer_id=? AND weekday=? AND is_active=1 AND start_time<=? AND end_time>=?');
            $stmt->execute([$lecturerId, $weekday, $startTime, $endTime]);
            if (!(int)$stmt->fetchColumn()) throw new RuntimeException('That time is outside the lecturer’s weekly availability.');
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM consultation_bookings WHERE lecturer_id=? AND status='approved' AND starts_at<? AND ends_at>?");
            $stmt->execute([$lecturerId, $end->format('Y-m-d H:i:s'), $start->format('Y-m-d H:i:s')]);
            if ((int)$stmt->fetchColumn() > 0) throw new RuntimeException('This time overlaps an already approved consultation.');
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("INSERT INTO consultation_bookings (student_id, lecturer_id, module_id, topic, student_message, starts_at, ends_at, status) VALUES (?,?,?,?,?,?,?,'pending')");
                $stmt->execute([$userId, $lecturerId, $moduleId, $topic, $message !== '' ? $message : null, $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')]);
                $bookingId = (int)$pdo->lastInsertId();
                studentConsultNotify($pdo, $lecturerId, $userId, 'consultation_requested', 'New consultation request',
                    (string)$user['full_name'] . ' requested a consultation about "' . $topic . '" on ' . $start->format('Y-m-d H:i') . ' (request #' . $bookingId . ').');
                $pdo->commit();
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $ex;
            }
            studentConsultGo('Request submitted.');
        } elseif (in_array($action, ['cancel','accept_reschedule','decline_reschedule'], true)) {
            $bookingId = filter_var($_POST['booking_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$bookingId) throw new RuntimeException('Invalid booking.');
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('SELECT * FROM consultation_bookings WHERE id=? AND student_id=? FOR UPDATE');
                $stmt->execute([$bookingId, $userId]);
                $booking = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$booking) throw new RuntimeException('Booking not found.');
                if ($action === 'cancel') {
                    if (!in_array($booking['status'], ['pending','approved','reschedule_proposed'], true) || strtotime((string)$booking['starts_at']) <= time()) throw new RuntimeException('This booking can no longer be cancelled.');
                    $stmt = $pdo->prepare("UPDATE consultation_bookings SET status='cancelled' WHERE id=? AND student_id=?");
                    $stmt->execute([$bookingId, $userId]);
                    $notice = 'Booking cancelled.';
                } elseif ($action === 'decline_reschedule') {
                    if ($booking['status'] !== 'reschedule_proposed') throw new RuntimeException('No active reschedule proposal.');
                    $stmt = $pdo->prepare("UPDATE consultation_bookings SET status='cancelled' WHERE id=? AND student_id=?");
                    $stmt->execute([$bookingId, $userId]);
                    $notice = 'Reschedule declined.';
                } else {
                    if ($booking['status'] !== 'reschedule_proposed' || !$booking['proposed_starts_at'] || !$booking['proposed_ends_at'] || strtotime((string)$booking['proposed_starts_at']) <= time()) throw new RuntimeException('This proposal is no longer available.');
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM consultation_bookings WHERE lecturer_id=? AND id<>? AND status='approved' AND starts_at<? AND ends_at>?");
                    $stmt->execute([(int)$booking['lecturer_id'], $bookingId, $booking['proposed_ends_at'], $booking['proposed_starts_at']]);
                    if ((int)$stmt->fetchColumn() > 0) throw new RuntimeException('The proposed time now overlaps another approved booking.');
                    $stmt = $pdo->prepare("UPDATE consultation_bookings SET starts_at=proposed_starts_at, ends_at=proposed_ends_at, proposed_starts_at=NULL, proposed_ends_at=NULL, status='approved' WHERE id=? AND student_id=? AND status='reschedule_proposed'");
                    $stmt->execute([$bookingId, $userId]);
                    $notice = 'Reschedule accepted.';
                }
                $event = match ($action) {
                    'cancel' => ['consultation_cancelled', 'Consultation cancelled', 'cancelled their consultation request'],
                    'accept_reschedule' => ['consultation_reschedule_accepted', 'Reschedule accepted', 'accepted your proposed consultation time'],
                    'decline_reschedule' => ['consultation_reschedule_declined', 'Reschedule declined', 'declined your proposed consultation time'],
                };
                studentConsultNotify($pdo, (int)$booking['lecturer_id'], $userId, $event[0], $event[1],
                    (string)$user['full_name'] . ' ' . $event[2] . ' (request #' . $bookingId . ').');
                $pdo->commit();
                studentConsultGo($notice);
            } catch (Throwable $ex) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $ex; }
        } else throw new RuntimeException('Unknown action.');
    }
    $slots = [];
    if ($lecturerIds) {
        $placeholders = implode(',', array_fill(0, count($lecturerIds), '?'));
        $stmt = $pdo->prepare("SELECT lecturer_id, weekday, start_time, end_time FROM consultation_availability WHERE is_active=1 AND lecturer_id IN ($placeholders) ORDER BY weekday, start_time");
        $stmt->execute(array_keys($lecturerIds));
        $slots = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    $stmt = $pdo->prepare('SELECT cb.*, u.full_name AS lecturer_name, lp.title AS lecturer_title, m.module_code, m.module_name FROM consultation_bookings cb JOIN lecturer_profiles lp ON lp.id=cb.lecturer_id JOIN users u ON u.id=lp.user_id JOIN academic_modules m ON m.id=cb.module_id WHERE cb.student_id=? ORDER BY cb.created_at DESC, cb.id DESC LIMIT 100');
    $stmt->execute([$userId]);
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $ex) {
    if ($ex instanceof RuntimeException) $error = $ex->getMessage();
    else { error_log('ULearn student consultation error: '.$ex->getMessage()); $error = 'Consultations could not be loaded. Confirm the Stage 6 database tables exist.'; }
}
$eligible = $eligible ?? []; $slots = $slots ?? []; $bookings = $bookings ?? [];
$initial = strtoupper(substr(trim((string)$user['full_name']),0,1));
?>
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Student Consultations — ULearn</title>
<script>(function(){try{let t=localStorage.getItem('ulearn-theme');if(t!=='light'&&t!=='dark')t=window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';document.documentElement.dataset.theme=t;}catch(e){}})();function toggleTheme(){let t=document.documentElement.dataset.theme==='dark'?'light':'dark';document.documentElement.dataset.theme=t;try{localStorage.setItem('ulearn-theme',t);}catch(e){}updateThemeButton();}function updateThemeButton(){let b=document.getElementById('themeButton');if(b){let dark=document.documentElement.dataset.theme==='dark';b.textContent=dark?'☀':'☾';b.setAttribute('aria-label',dark?'Switch to light mode':'Switch to dark mode');}}document.addEventListener('DOMContentLoaded',updateThemeButton);</script>
<style>:root{--bg:#f7f8fc;--surface:#fff;--text:#171927;--muted:#777b90;--border:#e7e8f0;--accent:#6756e8;--soft:#f0edff}:root[data-theme="dark"]{--bg:#0c0e16;--surface:#161924;--text:#f4f4fa;--muted:#a0a4b6;--border:#2b2e3c;--accent:#a99aff;--soft:#292440}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif;line-height:1.6}a{color:inherit;text-decoration:none}.layout{display:grid;grid-template-columns:250px minmax(0,1fr);min-height:100vh}.sidebar{background:var(--surface);border-right:1px solid var(--border);padding:32px 20px}.brand{font-size:26px;font-weight:800;letter-spacing:-1.5px;margin:0 0 45px 12px}.brand span{color:var(--accent)}.nav-label,.eyebrow{font-size:11px;font-weight:750;letter-spacing:1.7px;text-transform:uppercase;color:var(--muted)}.nav-label{margin:0 12px 14px}.nav a{display:block;padding:12px 14px;border-radius:12px;font-size:14px;font-weight:600;margin-bottom:6px}.nav a:hover,.nav a.active{background:var(--soft);color:var(--accent)}main{min-width:0;padding:34px clamp(20px,5vw,76px) 80px}.topbar{display:flex;align-items:center;justify-content:space-between;gap:20px;margin-bottom:50px}.topbar-right{display:flex;align-items:center;gap:16px}.theme-button{width:42px;height:42px;border:1px solid var(--border);border-radius:12px;background:var(--surface);color:var(--text);font-size:20px;cursor:pointer}.avatar{width:40px;height:40px;border-radius:50%;background:var(--soft);color:var(--accent);display:grid;place-items:center;font-weight:800}h1{font-size:clamp(34px,4vw,54px);letter-spacing:-2px;line-height:1.15;margin:12px 0}h2{font-size:22px;margin:0 0 18px}.lead,.muted{color:var(--muted)}.section{margin-top:42px}.card{background:var(--surface);border:1px solid var(--border);border-radius:19px;padding:24px}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:17px}.field{display:flex;flex-direction:column;gap:7px}.field label{font-size:13px;font-weight:700}input,select,textarea{width:100%;padding:12px;border:1px solid var(--border);border-radius:11px;background:var(--surface);color:var(--text);font:inherit}textarea{min-height:88px;resize:vertical}.btn{display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--border);border-radius:11px;padding:11px 15px;background:var(--surface);color:var(--text);font:inherit;font-size:13px;font-weight:700;cursor:pointer}.btn.primary{background:var(--accent);border-color:var(--accent);color:#fff}.btn:hover{opacity:.85}.flex{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.pill{display:inline-block;border-radius:100px;padding:6px 11px;background:var(--soft);color:var(--accent);font-size:12px;font-weight:750}.meta{color:var(--muted);font-size:13px}.booking{margin:12px 0}.booking h3{margin:12px 0 7px}.empty{border:1px dashed var(--border);border-radius:17px;padding:26px;color:var(--muted)}.notice{padding:14px 17px;border-radius:12px;background:var(--soft);margin:15px 0}.error{background:#ffedf0;color:#a32143}.slots{display:grid;gap:8px}.slot{padding:11px 14px;border:1px solid var(--border);border-radius:11px;font-size:13px}.message{white-space:pre-wrap;overflow-wrap:anywhere}.actions{margin-top:15px}.full{grid-column:1/-1}@media(max-width:850px){.layout{grid-template-columns:1fr}.sidebar{border-right:0;border-bottom:1px solid var(--border);padding:20px}.brand{margin-bottom:18px}main{padding:25px 20px}}@media(max-width:600px){.grid{grid-template-columns:1fr}.topbar{margin-bottom:30px}}</style></head><body><div class="layout"><aside class="sidebar"><a class="brand" href="<?= e(url('dashboard/index.php')) ?>">u learn<span>.</span></a><div class="nav-label">YOUR WORKSPACE</div><nav class="nav"><a href="<?= e(url('dashboard/index.php')) ?>">◫ Overview</a><a href="<?= e(url('dashboard/index.php')) ?>#modules">▤ My Modules</a><a href="<?= e(url('dashboard/explore.php')) ?>">⊕ Explore &amp; Enroll</a><a href="<?= e(url('dashboard/quizzes.php')) ?>">◇ Quiz Arena</a><a href="<?= e(url('dashboard/quiz-history.php')) ?>">◷ My Quiz History</a><a class="active" href="<?= e(url('dashboard/consultations.php')) ?>">◎ Consultations</a></nav></aside><main><header class="topbar"><div class="eyebrow">U LEARN / STUDENT SPACE</div><div class="topbar-right"><button id="themeButton" class="theme-button" type="button" onclick="toggleTheme()" title="Change theme" aria-label="Toggle appearance">☾</button><div class="avatar"><?= e($initial) ?></div></div></header><div class="eyebrow">CONNECT WITH YOUR LECTURERS</div><h1>Consultations<span style="color:var(--accent)">.</span></h1><p class="lead">Book time with lecturers for your enrolled modules and track your requests.</p>
<?php if($error!==''):?><div class="notice error" role="alert"><?= e($error) ?></div><?php endif;?><?php if($success!==''):?><div class="notice" role="status"><?= e($success) ?></div><?php endif;?>
<section class="section"><h2>Request a consultation</h2><?php if(!$eligible):?><div class="empty">No eligible lecturers found for your enrolled modules yet. Enroll in a module and ask an administrator to assign its lecturer. The module assignment improvements are planned for a later stage.</div><?php else:?><div class="card"><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="request"><div class="grid"><div class="field full"><label for="lecturer_module">Module and lecturer</label><select id="lecturer_module" required><option value="">Choose a module and lecturer</option><?php foreach($eligible as $entry):?><option value="<?= (int)$entry['lecturer_id'] ?>:<?= (int)$entry['module_id'] ?>"><?= e((string)$entry['module_code'].' — '.(string)$entry['module_name'].' · '.trim((string)$entry['title'].' '.(string)$entry['lecturer_name'])) ?></option><?php endforeach;?></select><input type="hidden" name="lecturer_id" id="lecturer_id"><input type="hidden" name="module_id" id="module_id"></div><div class="field"><label for="starts_at">Start date and time</label><input type="datetime-local" id="starts_at" name="starts_at" required></div><div class="field"><label for="ends_at">End date and time</label><input type="datetime-local" id="ends_at" name="ends_at" required></div><div class="field full"><label for="topic">What do you need help with?</label><input id="topic" name="topic" maxlength="180" placeholder="e.g. Database normalization" required></div><div class="field full"><label for="student_message">Additional details (optional)</label><textarea id="student_message" name="student_message" maxlength="5000" placeholder="Briefly explain what you'd like to discuss."></textarea></div></div><div class="section" style="margin-top:20px"><strong>Lecturer's weekly availability</strong><p class="meta">Choose a date and time entirely inside one of the lecturer's available slots. Times use your server's local timezone.</p><div class="slots" id="slotList"><div class="empty">Choose a lecturer above to view available hours.</div></div></div><p><button type="submit" class="btn primary">Send consultation request →</button></p></form></div><?php endif;?></section>
<section class="section"><h2>My consultation requests <span class="pill"><?= count($bookings) ?></span></h2><?php if(!$bookings):?><div class="empty">No requests yet. When you submit one, its status will appear here.</div><?php else:?><?php foreach($bookings as $booking):?><article class="card booking"><div class="flex"><span class="pill"><?= e(ucwords(str_replace('_',' ',(string)$booking['status']))) ?></span><span class="meta">Request #<?= (int)$booking['id'] ?></span></div><h3><?= e((string)$booking['topic']) ?></h3><div class="meta"><?= e(trim((string)$booking['lecturer_title'].' '.(string)$booking['lecturer_name'])) ?> · <?= e((string)$booking['module_code']) ?> — <?= e((string)$booking['module_name']) ?><div>Scheduled: <?= e((string)$booking['starts_at']) ?> to <?= e((string)$booking['ends_at']) ?></div></div><?php if(!empty($booking['student_message'])):?><p class="message"><?= e((string)$booking['student_message']) ?></p><?php endif;?><?php if(!empty($booking['lecturer_response'])):?><p class="message"><strong>Lecturer response:</strong> <?= e((string)$booking['lecturer_response']) ?></p><?php endif;?><?php if($booking['status']==='reschedule_proposed'):?><div class="notice"><strong>New time proposed:</strong> <?= e((string)$booking['proposed_starts_at']) ?> to <?= e((string)$booking['proposed_ends_at']) ?></div><div class="flex actions"><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="accept_reschedule"><input type="hidden" name="booking_id" value="<?= (int)$booking['id'] ?>"><button type="submit" class="btn primary">Accept new time</button></form><form method="post" onsubmit="return confirm('Decline the proposed time and close this request?')"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="decline_reschedule"><input type="hidden" name="booking_id" value="<?= (int)$booking['id'] ?>"><button type="submit" class="btn">Decline proposal</button></form></div><?php endif;?><?php if(in_array((string)$booking['status'],['pending','approved','reschedule_proposed'],true) && strtotime((string)$booking['starts_at'])>time()):?><form method="post" class="actions" onsubmit="return confirm('Cancel this consultation request?')"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="cancel"><input type="hidden" name="booking_id" value="<?= (int)$booking['id'] ?>"><button type="submit" class="btn">Cancel booking</button></form><?php endif;?></article><?php endforeach;?><?php endif;?></section></main></div>
<script>const consultSlots=<?= json_encode($slots, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;const dayNames=<?= json_encode($days) ?>;const select=document.getElementById('lecturer_module');if(select){select.addEventListener('change',()=>{const [lecturer,module]=select.value.split(':');document.getElementById('lecturer_id').value=lecturer||'';document.getElementById('module_id').value=module||'';const list=document.getElementById('slotList');list.replaceChildren();const rows=consultSlots.filter(s=>String(s.lecturer_id)===lecturer);if(!rows.length){const div=document.createElement('div');div.className='empty';div.textContent='This lecturer has not set any available hours yet.';list.append(div);return;}rows.forEach(s=>{const div=document.createElement('div');div.className='slot';div.textContent=(dayNames[s.weekday]||'Day')+' · '+String(s.start_time).slice(0,5)+' – '+String(s.end_time).slice(0,5);list.append(div);});});}</script></body></html>
