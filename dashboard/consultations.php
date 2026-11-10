<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
date_default_timezone_set('Asia/Colombo');
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
            $topic = trim((string)($_POST['topic'] ?? ''));
            $message = trim((string)($_POST['student_message'] ?? ''));
            if (!$lecturerId || !$moduleId || !isset($eligiblePairs[$lecturerId.':'.$moduleId])) throw new RuntimeException('Select a lecturer teaching one of your enrolled modules.');
            if (!$start || $start <= new DateTimeImmutable('now')) throw new RuntimeException('Choose a future consultation date and start time.');
            if ($topic === '' || mb_strlen($topic) > 180 || mb_strlen($message) > 5000) throw new RuntimeException('Enter a topic (up to 180 characters) and a message under 5000 characters.');
            $date = $start->format('Y-m-d');
            $startTime = $start->format('H:i:s');
            $pdo->beginTransaction();
            try {
                // Serialize bookings per lecturer to prevent simultaneous requests claiming the same time.
                $lock = $pdo->prepare('SELECT id FROM lecturer_profiles WHERE id=? AND is_active=1 FOR UPDATE');
                $lock->execute([$lecturerId]);
                if (!$lock->fetchColumn()) throw new RuntimeException('This lecturer is currently unavailable.');
                $stmt = $pdo->prepare('SELECT start_time, end_time FROM consultation_date_slots WHERE lecturer_id=? AND available_date=? AND start_time<=? AND end_time>? AND is_active=1');
                $stmt->execute([$lecturerId, $date, $startTime, $startTime]);
                $window = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$window) throw new RuntimeException('This time is no longer available. Refresh the page and choose another time.');
                // Picker offers 30-minute steps relative to the beginning of each window.
                if (((strtotime($startTime) - strtotime((string)$window['start_time'])) % 1800) !== 0) throw new RuntimeException('Please select one of the displayed consultation times.');
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM consultation_bookings WHERE lecturer_id=? AND starts_at=? AND status IN ('pending','approved','reschedule_proposed')");
                $stmt->execute([$lecturerId, $start->format('Y-m-d H:i:s')]);
                if ((int)$stmt->fetchColumn()) throw new RuntimeException('That time was already requested. Refresh the page to see the remaining times.');
                $stmt = $pdo->prepare("INSERT INTO consultation_bookings (student_id, lecturer_id, module_id, topic, student_message, starts_at, ends_at, status) VALUES (?,?,?,?,?,?,?,'pending')");
                $stmt->execute([$userId, $lecturerId, $moduleId, $topic, $message !== '' ? $message : null, $start->format('Y-m-d H:i:s'), $start->format('Y-m-d H:i:s')]);
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
                    if (!in_array($booking['status'], ['pending','approved','reschedule_proposed'], true) || strtotime((string)$booking['starts_at']) <= time() + 10 * 3600) throw new RuntimeException('This booking can no longer be cancelled.');
                    $stmt = $pdo->prepare("UPDATE consultation_bookings SET status='cancelled' WHERE id=? AND student_id=?");
                    $stmt->execute([$bookingId, $userId]);
                    $notice = 'Booking cancelled.';
                } elseif ($action === 'decline_reschedule') {
                    if ($booking['status'] !== 'reschedule_proposed') throw new RuntimeException('No active reschedule proposal.');
                    $stmt = $pdo->prepare("UPDATE consultation_bookings SET status='cancelled' WHERE id=? AND student_id=?");
                    $stmt->execute([$bookingId, $userId]);
                    $notice = 'Reschedule declined.';
                } else {
                    if ($booking['status'] !== 'reschedule_proposed' || !$booking['proposed_starts_at']  || strtotime((string)$booking['proposed_starts_at']) <= time()) throw new RuntimeException('This proposal is no longer available.');
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM consultation_bookings WHERE lecturer_id=? AND id<>? AND status IN ('pending','approved','reschedule_proposed') AND starts_at=?");
                    $stmt->execute([(int)$booking['lecturer_id'], $bookingId, $booking['proposed_starts_at']]);
                    if ((int)$stmt->fetchColumn() > 0) throw new RuntimeException('That proposed start time has already been booked.');
                    $stmt = $pdo->prepare("UPDATE consultation_bookings SET starts_at=proposed_starts_at, ends_at=proposed_starts_at, proposed_starts_at=NULL, proposed_ends_at=NULL, status='approved' WHERE id=? AND student_id=? AND status='reschedule_proposed'");
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
        $stmt = $pdo->prepare("SELECT lecturer_id, available_date, start_time, end_time FROM consultation_date_slots WHERE is_active=1 AND available_date>=CURDATE() AND lecturer_id IN ($placeholders) ORDER BY available_date, start_time");
        $stmt->execute(array_keys($lecturerIds));
        $slots = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    // Exclude already-requested start times from the student picker.
    $takenStarts = [];
    if ($lecturerIds) {
        $placeholders = implode(',', array_fill(0, count($lecturerIds), '?'));
        $stmt = $pdo->prepare("SELECT lecturer_id, starts_at FROM consultation_bookings WHERE lecturer_id IN ($placeholders) AND starts_at >= NOW() AND status IN ('pending','approved','reschedule_proposed')");
        $stmt->execute(array_keys($lecturerIds));
        $takenStarts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    $stmt = $pdo->prepare('SELECT cb.*, u.full_name AS lecturer_name, lp.title AS lecturer_title, m.module_code, m.module_name FROM consultation_bookings cb JOIN lecturer_profiles lp ON lp.id=cb.lecturer_id JOIN users u ON u.id=lp.user_id JOIN academic_modules m ON m.id=cb.module_id WHERE cb.student_id=? ORDER BY cb.created_at DESC, cb.id DESC LIMIT 100');
    $stmt->execute([$userId]);
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $ex) {
    if ($ex instanceof RuntimeException) $error = $ex->getMessage();
    else { error_log('ULearn student consultation error: '.$ex->getMessage()); $error = 'Consultations could not be loaded. Confirm the Stage 6 database tables exist.'; }
}
$eligible = $eligible ?? []; $slots = $slots ?? []; $bookings = $bookings ?? []; $takenStarts = $takenStarts ?? [];
$initial = strtoupper(substr(trim((string)$user['full_name']),0,1));

// Count unread notifications for badge consistency
$unreadNotificationsStmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
$unreadNotificationsStmt->execute([$userId]);
$unreadNotifications = (int)$unreadNotificationsStmt->fetchColumn();

// Fetch note contribution points for sidebar bottom display
$notePointsStmt = $pdo->prepare('SELECT COALESCE(SUM(points), 0) FROM student_note_points WHERE user_id = ?');
$notePointsStmt->execute([$userId]);
$notePoints = (int)$notePointsStmt->fetchColumn();
?>
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Student Consultations — ULearn</title>
<script>(function(){try{let t=localStorage.getItem('ulearn-theme');if(t!=='light'&&t!=='dark')t=window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';document.documentElement.dataset.theme=t;}catch(e){}})();</script>
<style>
    :root {
        color-scheme: light;
        --bg: #f7f8fc;
        --surface: #ffffff;
        --surface-alt: #f1f2f8;
        --text: #171927;
        --muted: #777b90;
        --border: #e7e8f0;
        --accent: #6756e8;
        --accent-light: #f0edff;
        --shadow: 0 10px 40px rgba(30,30,60,.04);
        --green: #087e60;
        --soft: #e7f7ef;
    }
    :root[data-theme="dark"] {
        color-scheme: dark;
        --bg: #0c0e16;
        --surface: #161924;
        --surface-alt: #202431;
        --text: #f4f4fa;
        --muted: #a0a4b6;
        --border: #2b2e3c;
        --accent: #a99aff;
        --accent-light: #292440;
        --shadow: none;
        --green: #5ce3b5;
        --soft: #153d34;
    }
    * { box-sizing: border-box; }
    html { scroll-behavior: smooth; }
    body {
        margin: 0;
        background: var(--bg);
        color: var(--text);
        font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif;
        line-height: 1.6;
    }
    a { color: inherit; text-decoration: none; }
    button, select { font: inherit; }
    button { cursor: pointer; }
    .layout {
        display: grid;
        grid-template-columns: 250px minmax(0, 1fr);
        min-height: 100vh;
    }
    .sidebar {
        background: var(--surface);
        border-right: 1px solid var(--border);
        padding: 32px 20px;
        display: flex;
        flex-direction: column;
        gap: 40px;
    }
    .brand {
        font-size: 26px;
        font-weight: 800;
        letter-spacing: -1.5px;
        padding-left: 12px;
    }
    .brand span { color: var(--accent); }
    .nav-label, .eyebrow {
        font-size: 11px;
        font-weight: 750;
        letter-spacing: 1.7px;
        text-transform: uppercase;
        color: var(--muted);
    }
    .nav-label { margin: 0 12px 14px; }
    .nav { display: grid; gap: 6px; }
    .nav a {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px 14px;
        border-radius: 12px;
        font-size: 14px;
        font-weight: 600;
    }
    .nav a:hover { background: var(--surface-alt); }
    .nav a.active {
        background: var(--accent-light);
        color: var(--accent);
    }
    .ul-nav-icon {
        width: 20px;
        height: 20px;
        flex-shrink: 0;
    }
    .sidebar-bottom {
        margin-top: auto;
        border: 1px solid var(--border);
        border-radius: 16px;
        padding: 16px;
        font-size: 13px;
    }
    .sidebar-bottom strong { display: block; overflow-wrap: anywhere; }
    .sidebar-bottom small { color: var(--muted); overflow-wrap: anywhere; }
    .main {
        min-width: 0;
        padding: 34px clamp(20px, 5vw, 76px) 80px;
    }
    .topbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 20px;
        margin-bottom: 40px;
    }
    .topbar-right { display: flex; align-items: center; gap: 16px; }
    .notification-bell {
        width: 42px; height: 42px; position: relative;
        display: inline-grid; place-items: center;
        background: var(--surface); color: var(--text);
        border: 1px solid var(--border); border-radius: 12px;
        box-shadow: var(--shadow);
        transition: transform .18s ease, background .18s ease, border-color .18s ease;
    }
    .notification-bell:hover { transform: translateY(-2px); background: var(--accent-light); border-color: var(--accent); }
    .notification-bell svg { width: 21px; height: 21px; stroke: currentColor; fill: none; stroke-width: 1.9; stroke-linecap: round; stroke-linejoin: round; }
    .notification-count {
        position: absolute; top: -8px; right: -9px;
        min-width: 20px; height: 20px; padding: 0 5px;
        display: inline-grid; place-items: center;
        border-radius: 999px; background: #e34878; color: #fff;
        border: 2px solid var(--bg); font-size: 10px; font-weight: 800;
        line-height: 1;
    }
    .avatar {
        width: 40px; height: 40px; border-radius: 50%;
        background: var(--accent-light); color: var(--accent);
        display: grid; place-items: center; font-weight: 800;
    }
    .logout {
        background: transparent; border: 0; color: var(--muted);
        font-size: 13px; font-weight: 650; cursor: pointer;
    }
    h1, h2, h3, p { margin-top: 0; }
    .hero {
        border-radius: 24px; padding: 32px;
        background: linear-gradient(115deg, #063e39, #087d62 70%, #12677b);
        color: white; margin-bottom: 20px;
    }
    .hero h1 { font-size: clamp(28px, 4vw, 44px); margin: 6px 0; }
    .hero p { opacity: .88; }
    .card { background: var(--surface); border: 1px solid var(--border); border-radius: 20px; padding: 28px; margin-bottom: 18px; box-shadow: var(--shadow); }
    .muted { color: var(--muted); }
    .lead { color: var(--muted); }
    .section { margin-top: 42px; }
    .grid { display: grid; grid-template-columns: repeat(2,minmax(0,1fr)); gap: 17px; }
    .field { display: flex; flex-direction: column; gap: 7px; }
    .field label { font-size: 13px; font-weight: 700; }
    input, select, textarea { width: 100%; padding: 12px; border: 1px solid var(--border); border-radius: 11px; background: var(--surface); color: var(--text); font: inherit; }
    textarea { min-height: 88px; resize: vertical; }
    .btn { display: inline-flex; align-items: center; justify-content: center; border: 1px solid var(--border); border-radius: 11px; padding: 11px 15px; background: var(--surface); color: var(--text); font: inherit; font-size: 13px; font-weight: 700; cursor: pointer; }
    .btn.primary { background: var(--accent); border-color: var(--accent); color: #fff; }
    .btn:hover { opacity: .85; }
    .flex { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .pill { display: inline-block; border-radius: 100px; padding: 6px 11px; background: var(--accent-light); color: var(--accent); font-size: 12px; font-weight: 750; }
    .meta { color: var(--muted); font-size: 13px; }
    .booking { margin: 12px 0; }
    .booking h3 { margin: 12px 0 7px; }
    .empty { border: 1px dashed var(--border); border-radius: 17px; padding: 26px; color: var(--muted); }
    .notice { padding: 14px 17px; border-radius: 12px; background: var(--accent-light); color: var(--accent); margin: 15px 0; }
    .error { background: #ffedf0; color: #a32143; border: 1px solid #e9a4a4; }
    .slots { display: grid; gap: 8px; }
    .slot { padding: 11px 14px; border: 1px solid var(--border); border-radius: 11px; font-size: 13px; }
    .message { white-space: pre-wrap; overflow-wrap: anywhere; }
    .actions { margin-top: 15px; }
    .full { grid-column: 1/-1; }
    @media (max-width: 900px) {
        .layout { grid-template-columns: 1fr; }
        .sidebar { border-right: 0; border-bottom: 1px solid var(--border); padding: 18px; gap: 18px; }
        .nav { display: flex; flex-wrap: wrap; }
        .sidebar-bottom { display: none; }
        .main { padding-top: 25px; }
        .topbar { margin-bottom: 35px; }
    }
    @media(max-width:600px){.grid{grid-template-columns:1fr}.topbar{margin-bottom:30px}}
</style>
<link rel="stylesheet" href="<?= e(url('assets/css/consultations-premium.css')) ?>">
<script src="<?= htmlspecialchars(url('assets/js/theme-global.js?v=20261107-2'), ENT_QUOTES, 'UTF-8') ?>"></script>
<link rel="stylesheet" href="<?= e(url('assets/css/ulearn-design-system.css?v=1')) ?>">
<script defer src="<?= e(url('assets/js/ulearn-motion.js?v=1')) ?>"></script>
<link rel="stylesheet" href="<?= e(url('assets/css/ulearn-student-shell.css?v=2')) ?>">
<script defer src="<?= e(url('assets/js/ulearn-student-shell.js?v=2')) ?>"></script>
</head><body><div class="layout">

    <aside class="sidebar" id="studentSidebar">
        <a class="brand" href="<?= e(url('dashboard/index.php')) ?>">
            u learn<span>.</span>
        </a>

        <nav aria-label="Student navigation">
            <p class="nav-label">Your workspace</p>

            <div class="nav ul-nav">
                <a href="<?= e(url('dashboard/index.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/></svg><span>Overview</span></a>
                <a href="<?= e(url('dashboard/index.php#modules')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 7v14M3 18V4a1 1 0 0 1 1-1h5a3 3 0 0 1 3 3 3 3 0 0 1 3-3h5a1 1 0 0 1 1 1v14h-6a3 3 0 0 0-3 3 3 3 0 0 0-3-3z"/></svg><span>My Modules</span></a>
                <a href="<?= e(url('dashboard/explore.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="m16 8-2 6-6 2 2-6z"/></svg><span>Explore & Enroll</span></a>
                <a href="<?= e(url('dashboard/notes-library.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="8" y="7" width="12" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h2"/></svg><span>Notes Library</span></a>
                <a href="<?= e(url('dashboard/notes.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V8m-4 4 4-4 4 4M4 17a5 5 0 0 1 1-9 7 7 0 0 1 13 0 5 5 0 0 1 2 9"/></svg><span>Upload Notes</span></a>
                <a href="<?= e(url('dashboard/quiz-history.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-7M3 3v6h6M12 7v5l3 2"/></svg><span>Quiz History</span></a>
                <a href="<?= e(url('dashboard/learning-insights.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="m3 17 6-6 4 4 8-8M15 7h6v6"/></svg><span>Learning Insights</span></a>
                <a href="<?= e(url('dashboard/analytics.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20V12h4v8M10 20V4h4v16M16 20v-9h4v9M2 20h20"/></svg><span>Performance Analytics</span></a>
                <a href="<?= e(url('dashboard/topic-analysis.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/></svg><span>Topic Weaknesses</span></a>
                <a href="<?= e(url('dashboard/ai-study.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="m12 3 1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8zM19 17l.7 1.3L21 19l-1.3.7L19 21l-.7-1.3L17 19l1.3-.7z"/></svg><span>AI Study Assistant</span></a>
                <a class="active" href="<?= e(url('dashboard/consultations.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M20 11V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v10l4-3h11a2 2 0 0 0 2-1zM9 16v3a2 2 0 0 0 2 2h8l3 2v-9"/></svg><span>Consultations</span></a>
                <a href="<?= e(url('dashboard/kuppiya.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3"/><path d="M3 20v-2a6 6 0 0 1 12 0v2M17 5a3 3 0 0 1 0 6M17 15a5 5 0 0 1 4 5"/></svg><span>Kuppiya Sessions</span></a>
                <a href="<?= e(url('dashboard/leaderboard.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M7 4h10v7a5 5 0 0 1-10 0zM7 6H4v3a4 4 0 0 0 4 4m9-7h3v3a4 4 0 0 1-4 4M12 16v4m-4 1h8"/></svg><span>Leaderboard</span></a>
                <a href="<?= e(url('dashboard/notifications.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg><span>Notifications</span></a>
                <a href="<?= e(url('dashboard/profile.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M4 17h16"/><circle cx="8" cy="7" r="2"/><circle cx="16" cy="17" r="2"/></svg><span>My Profile & Settings</span></a>
            </div>
        </nav>

        <div class="sidebar-bottom">
            <small>Signed in as</small>
            <strong><?= htmlspecialchars((string)$user['full_name'], ENT_QUOTES, 'UTF-8') ?></strong>
            <small><?= htmlspecialchars((string)($user['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></small>
            <small style="display:block;margin-top:12px"><?= $notePoints ?> contribution points</small>
        </div>
    </aside>

    <main>
        <header class="topbar">
            <button type="button" class="ul-menu-toggle" id="ulMenuToggle" aria-expanded="false" aria-controls="studentSidebar" aria-label="Toggle navigation"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button>

            <span class="eyebrow">
                U LEARN / STUDENT SPACE
            </span>

            <div class="topbar-right">
                <a class="notification-bell" href="<?= e(url('dashboard/notifications.php')) ?>"
                   aria-label="Notifications: <?= $unreadNotifications ?> unread"
                   title="<?= $unreadNotifications ?> unread notifications" style="width:42px;height:42px;position:relative;display:inline-grid;place-items:center;background:var(--surface);color:var(--text);border:1px solid var(--border);border-radius:12px;">
                    <svg viewBox="0 0 24 24" aria-hidden="true" style="width:21px;height:21px;stroke:currentColor;fill:none;stroke-width:1.9;stroke-linecap:round;stroke-linejoin:round;"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg>
                    <?php if ($unreadNotifications > 0): ?>
                        <span class="notification-count" aria-hidden="true" style="position:absolute;top:-8px;right:-9px;min-width:20px;height:20px;padding:0 5px;display:inline-grid;place-items:center;border-radius:999px;background:#e34878;color:#fff;border:2px solid var(--bg);font-size:10px;font-weight:800;line-height:1;"><?= $unreadNotifications > 99 ? '99+' : $unreadNotifications ?></span>
                    <?php endif; ?>
                </a>

                <button id="themeButton" class="theme-button" type="button" onclick="toggleTheme()" title="Change theme" aria-label="Toggle appearance">☾</button>
                <div class="avatar"><?= e($initial) ?></div>

                <form method="post" action="<?= e(url('auth/logout.php')) ?>" style="margin:0">
                    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                    <button type="submit" class="logout" style="background:transparent;border:0;color:var(--muted);font-size:13px;font-weight:650;cursor:pointer;">
                        Log out ↗
                    </button>
                </form>
            </div>
        </header>

        <div class="consult-hero">
            <div class="eyebrow">CONNECT WITH YOUR LECTURERS</div>
            <h1>Consultations<span style="color:var(--accent)">.</span></h1>
            <p class="lead">Book time with lecturers for your enrolled modules and track your requests.</p>
            <div class="consult-stats">
                <div class="consult-stat"><strong><?= count($bookings) ?></strong><span>Total requests</span></div>
                <div class="consult-stat"><strong><?= count(array_filter($bookings, static fn($b) => ($b['status'] ?? '') === 'approved')) ?></strong><span>Approved</span></div>
                <div class="consult-stat"><strong><?= count($eligible) ?></strong><span>Module–lecturer options</span></div>
            </div>
        </div>

        <?php if($error!==''):?><div class="notice error" role="alert"><?= e($error) ?></div><?php endif;?>
        <?php if($success!==''):?><div class="notice" role="status"><?= e($success) ?></div><?php endif;?>

        <section class="section">
            <h2>Request a consultation</h2>
            <?php if(!$eligible):?>
                <div class="empty">No eligible lecturers found for your enrolled modules yet. Enroll in a module and ask an administrator to assign its lecturer. The module assignment improvements are planned for a later stage.</div>
            <?php else:?>
                <div class="card">
                    <form method="post">
                        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                        <input type="hidden" name="action" value="request">
                        <div class="grid">
                            <div class="field full">
                                <label for="lecturer_module">Module and lecturer</label>
                                <select id="lecturer_module" required>
                                    <option value="">Choose a module and lecturer</option>
                                    <?php foreach($eligible as $entry):?>
                                        <option value="<?= (int)$entry['lecturer_id'] ?>:<?= (int)$entry['module_id'] ?>"><?= e((string)$entry['module_code'].' — '.(string)$entry['module_name'].' · '.trim((string)$entry['title'].' '.(string)$entry['lecturer_name'])) ?></option>
                                    <?php endforeach;?>
                                </select>
                                <input type="hidden" name="lecturer_id" id="lecturer_id">
                                <input type="hidden" name="module_id" id="module_id">
                            </div>
                            <div class="field full">
                                <label>Choose a consultation date &amp; time</label>
                                <input type="hidden" id="starts_at" name="starts_at" required>
                                <div class="appointment-picker">
                                    <div class="picker-heading"><span>Available dates</span><span class="picker-hint">Select a day to see times</span></div>
                                    <div id="dateList" class="date-list" role="group" aria-label="Available consultation dates"><div class="picker-empty">Choose a lecturer to view available dates.</div></div>
                                    <div class="picker-heading" style="margin-top:20px"><span>Available start times</span><span id="selectedDayLabel" class="picker-hint"></span></div>
                                    <div id="timeList" class="time-list" role="group" aria-label="Available consultation start times"><div class="picker-empty">Select a date first.</div></div>
                                    <div id="selectionSummary" class="selection-summary" aria-live="polite" hidden></div>
                                </div>
                                <p class="meta">In-person consultation · no fixed appointment duration</p>
                            </div>
                            <div class="field full">
                                <label for="topic">What do you need help with?</label>
                                <input id="topic" name="topic" maxlength="180" placeholder="e.g. Database normalization" required>
                            </div>
                            <div class="field full">
                                <label for="student_message">Additional details (optional)</label>
                                <textarea id="student_message" name="student_message" maxlength="5000" placeholder="Briefly explain what you'd like to discuss."></textarea>
                            </div>
                        </div>
                        <p><button type="submit" class="btn primary">Send consultation request →</button></p>
                    </form>
                </div>
            <?php endif;?>
        </section>

        <section class="section">
            <h2>My consultations <span class="pill"><?= count($bookings) ?></span></h2>
            <div class="consult-tabs">
                <button type="button" class="consult-tab active" data-filter="active">Active</button>
                <button type="button" class="consult-tab" data-filter="completed">Completed</button>
                <button type="button" class="consult-tab" data-filter="cancelled">Cancelled</button>
            </div>
            <?php if (!$bookings): ?>
                <div class="empty">No consultations yet.</div>
            <?php else: 
                foreach($bookings as $booking):
                    $group = in_array((string)$booking['status'], ['completed'], true) ? 'completed' : (in_array((string)$booking['status'], ['cancelled','declined'], true) ? 'cancelled' : 'active'); 
            ?>
                <a class="card consult-summary" data-group="<?= e($group) ?>" href="<?= e(url('consultation-detail.php?id='.(int)$booking['id'])) ?>">
                    <div class="flex"><span class="pill"><?= e(ucwords(str_replace('_',' ',(string)$booking['status']))) ?></span><span class="meta">#<?= (int)$booking['id'] ?></span></div>
                    <h3><?= e((string)$booking['topic']) ?></h3>
                    <p class="meta"><?= e(trim((string)$booking['lecturer_title'].' '.(string)$booking['lecturer_name'])) ?> · <?= e((string)$booking['module_code']) ?></p>
                    <div class="flex"><span class="meta">◷ <?= e(date('D, M j · g:i A',strtotime((string)$booking['starts_at']))) ?></span><strong class="consult-open">Open consultation →</strong></div>
                </a>
            <?php endforeach; endif; ?>
            <div id="consultEmpty" class="empty" hidden>No consultations in this category.</div>
        </section>

        <style>
            .consult-tabs { display: flex; gap: 8px; margin: 18px 0; flex-wrap: wrap; }
            .consult-tab { border: 1px solid var(--border); border-radius: 12px; background: var(--surface); color: var(--muted); font: inherit; font-size: 13px; font-weight: 750; padding: 10px 18px; cursor: pointer; }
            .consult-tab.active { background: var(--accent-light); color: var(--accent); border-color: transparent; }
            .consult-summary { display: block; margin: 12px 0; transition: transform .15s, border-color .15s; }
            .consult-summary:hover { transform: translateY(-2px); border-color: var(--accent); }
            .consult-summary h3 { margin: 14px 0 5px; }
            .consult-summary .flex:last-child { justify-content: space-between; }
            .consult-open { color: var(--accent); font-size: 13px; }
        </style>
        <script>
            (function(){
                const tabs = document.querySelectorAll('.consult-tab'), cards = document.querySelectorAll('.consult-summary'), empty = document.getElementById('consultEmpty');
                function show(group){
                    let n = 0;
                    cards.forEach(c => { c.hidden = c.dataset.group !== group; if(!c.hidden) n++; });
                    tabs.forEach(t => t.classList.toggle('active', t.dataset.filter === group));
                    empty.hidden = n !== 0 || cards.length === 0;
                }
                tabs.forEach(t => t.addEventListener('click', () => show(t.dataset.filter)));
                show('active');
            })();
        </script>
    </main>
</div>
<style>
.appointment-picker{border:1px solid var(--border);border-radius:16px;padding:20px;background:var(--surface)}
.picker-heading{display:flex;justify-content:space-between;align-items:center;gap:10px;font-weight:750;font-size:13px;margin-bottom:12px}.picker-hint{font-size:12px;color:var(--muted);font-weight:500}
.date-list{display:flex;gap:9px;overflow-x:auto;padding:2px 1px 9px;scrollbar-width:thin}.date-button{flex:0 0 79px;min-height:83px;border:1px solid var(--border);background:var(--surface);color:var(--text);border-radius:13px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:1px;cursor:pointer;font:inherit;transition:border-color .15s,background .15s}.date-button .weekday{font-size:11px;color:var(--muted)}.date-button .day-number{font-size:22px;font-weight:800;line-height:1.25}.date-button .month-name{font-size:11px;color:var(--muted)}.date-button:hover,.time-button:hover{border-color:var(--accent)}.date-button.is-selected,.time-button.is-selected{background:var(--accent-light);border-color:var(--accent);color:var(--accent)}.date-button.is-selected .weekday,.date-button.is-selected .month-name{color:var(--accent)}
.time-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(95px,1fr));gap:9px}.time-button{border:1px solid var(--border);border-radius:10px;padding:11px 8px;background:var(--surface);color:var(--text);font:inherit;font-size:13px;font-weight:650;cursor:pointer}.picker-empty{padding:18px;color:var(--muted);font-size:13px;background:var(--bg);border-radius:11px;width:100%}.selection-summary{margin-top:17px;padding:12px 14px;background:var(--accent-light);color:var(--accent);border-radius:11px;font-size:13px;font-weight:700}@media(max-width:600px){.appointment-picker{padding:14px}.date-button{flex-basis:72px;min-height:76px}.time-list{grid-template-columns:repeat(3,minmax(0,1fr))}}
</style>
<script>
const consultSlots=<?= json_encode($slots, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
const takenStarts=<?= json_encode($takenStarts, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
const lecturerSelect=document.getElementById('lecturer_module');
const dateList=document.getElementById('dateList');
const timeList=document.getElementById('timeList');
const startsInput=document.getElementById('starts_at');
const selectedDayLabel=document.getElementById('selectedDayLabel');
const selectionSummary=document.getElementById('selectionSummary');
const dateFormatter=new Intl.DateTimeFormat('en-LK',{weekday:'short',day:'numeric',month:'short',timeZone:'UTC'});
const fullDateFormatter=new Intl.DateTimeFormat('en-LK',{weekday:'long',day:'numeric',month:'long',year:'numeric',timeZone:'UTC'});
const timeFormatter=new Intl.DateTimeFormat('en-LK',{hour:'numeric',minute:'2-digit',hour12:true,timeZone:'UTC'});
const formatDate=d=>fullDateFormatter.format(new Date(d+'T12:00:00Z'));
const formatTime=t=>timeFormatter.format(new Date('2000-01-01T'+t+':00Z'));
const minutes=t=>Number(t.slice(0,2))*60+Number(t.slice(3,5));
const pad=n=>String(n).padStart(2,'0');
const showEmpty=(node,message)=>{node.replaceChildren();const el=document.createElement('div');el.className='picker-empty';el.textContent=message;node.append(el);};
let dateTimes=new Map();let selectedDate='';
function renderTimes(date){
 selectedDate=date;startsInput.value='';selectionSummary.hidden=true;
 selectedDayLabel.textContent=formatDate(date);
 document.querySelectorAll('.date-button').forEach(b=>{const active=b.dataset.date===date;b.classList.toggle('is-selected',active);b.setAttribute('aria-pressed',String(active));});
 timeList.replaceChildren();
 const times=[...(dateTimes.get(date)||[])].sort();
 if(!times.length){showEmpty(timeList,'No start times available for this date.');return;}
 times.forEach(t=>{const btn=document.createElement('button');btn.type='button';btn.className='time-button';btn.textContent=formatTime(t);btn.setAttribute('aria-pressed','false');btn.addEventListener('click',()=>{
   startsInput.value=date+'T'+t;
   timeList.querySelectorAll('.time-button').forEach(b=>{b.classList.remove('is-selected');b.setAttribute('aria-pressed','false');});
   btn.classList.add('is-selected');btn.setAttribute('aria-pressed','true');
   selectionSummary.hidden=false;selectionSummary.textContent='Selected: '+formatDate(date)+' at '+formatTime(t);
 });timeList.append(btn);});
}
function updatePicker(){
 const [lecturer,module]=(lecturerSelect.value||'').split(':');
 document.getElementById('lecturer_id').value=lecturer||'';
 document.getElementById('module_id').value=module||'';
 startsInput.value='';selectionSummary.hidden=true;selectedDayLabel.textContent='';selectedDate='';dateTimes=new Map();
 const rows=consultSlots.filter(s=>String(s.lecturer_id)===lecturer);
 const reserved=new Set(takenStarts.filter(s=>String(s.lecturer_id)===lecturer).map(s=>String(s.starts_at).slice(0,16).replace(' ','T')));
 for(const s of rows){
   const date=String(s.available_date).slice(0,10),begin=String(s.start_time).slice(0,5),end=String(s.end_time).slice(0,5);
   if(!dateTimes.has(date))dateTimes.set(date,new Set());
   for(let m=minutes(begin);m<minutes(end);m+=30){const t=pad(Math.floor(m/60))+':'+pad(m%60);if(!reserved.has(date+'T'+t))dateTimes.get(date).add(t);}
 }
 dateList.replaceChildren();
 const dates=[...dateTimes.keys()].filter(d=>dateTimes.get(d).size).sort();
 if(!dates.length){showEmpty(dateList,lecturer?'This lecturer has no published availability.':'Choose a lecturer to view available dates.');showEmpty(timeList,'Select a date first.');return;}
 dates.forEach(d=>{const dateObj=new Date(d+'T12:00:00Z'),parts=dateFormatter.formatToParts(dateObj),get=type=>parts.find(p=>p.type===type)?.value||'';
   const btn=document.createElement('button');btn.type='button';btn.className='date-button';btn.dataset.date=d;btn.setAttribute('aria-label',formatDate(d));btn.setAttribute('aria-pressed','false');
   for(const [cls,val] of [['weekday',get('weekday')],['day-number',get('day')],['month-name',get('month')]]){const span=document.createElement('span');span.className=cls;span.textContent=val;btn.append(span);}
   btn.addEventListener('click',()=>renderTimes(d));dateList.append(btn);
 });
 renderTimes(dates[0]);
}
if(lecturerSelect){lecturerSelect.addEventListener('change',updatePicker);updatePicker();const form=lecturerSelect.closest('form');form.addEventListener('submit',e=>{if(!startsInput.value){e.preventDefault();showEmpty(timeList,selectedDate?'Please select a start time before sending your request.':'Please choose an available date and time.');timeList.scrollIntoView({behavior:'smooth',block:'center'});}});}
</script><?php require_once __DIR__ . '/../includes/student-bot.php'; ?></body></html>