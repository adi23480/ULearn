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
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Consultations · ULearn</title>
<script>(function(){try{var t=localStorage.getItem('ulearn-theme');if(t!=='dark'&&t!=='light')t=matchMedia('(prefers-color-scheme:dark)').matches?'dark':'light';document.documentElement.dataset.theme=t}catch(e){document.documentElement.dataset.theme='light'}})();</script>
<link rel="stylesheet" href="<?= e(url('assets/css/ulearn-consultations-premium.css?v=1')) ?>">
<script defer src="<?= e(url('assets/js/ulearn-consultations-premium.js?v=1')) ?>"></script>
</head><body><div class="uc-progress" id="ucProgress"></div><div class="uc-app">
<aside class="uc-sidebar" id="ucSidebar"><?php require __DIR__ . '/../includes/brand-logo.php'; ?><div class="uc-nav-caption">YOUR WORKSPACE</div><nav class="uc-nav" aria-label="Student navigation">
<a href="<?= e(url('dashboard/index.php')) ?>"><span class="uc-nav-icon">⌂</span> Overview</a><a href="<?= e(url('dashboard/index.php')) ?>#modules"><span class="uc-nav-icon">▦</span> My Modules</a><a href="<?= e(url('dashboard/explore.php')) ?>"><span class="uc-nav-icon">⊕</span> Explore &amp; Enroll</a><a href="<?= e(url('dashboard/quizzes.php')) ?>"><span class="uc-nav-icon">◇</span> Quiz Arena</a><a href="<?= e(url('dashboard/quiz-history.php')) ?>"><span class="uc-nav-icon">◷</span> Quiz History</a><a class="active" aria-current="page" href="<?= e(url('dashboard/consultations.php')) ?>"><span class="uc-nav-icon">▤</span> Consultations <span class="uc-active-dot"></span></a></nav><div class="uc-sidebar-bottom"><div class="uc-sidebar-glow"></div><div class="uc-mini-title">LEARNING TOGETHER</div><p>Great questions lead to great conversations.</p><a href="<?= e(url('dashboard/ai-study.php')) ?>">Explore AI Study <span>↗</span></a></div></aside>
<div class="uc-main-wrap"><header class="uc-topbar"><div class="uc-top-left"><button class="uc-mobile-menu" type="button" id="ucMenu" aria-label="Toggle navigation" aria-expanded="false">☰</button><span class="uc-breadcrumb">STUDENT SPACE <span>/</span> CONSULTATIONS</span></div><div class="uc-top-actions"><span class="uc-date" id="ucToday"></span><button class="uc-theme" type="button" id="ucTheme" aria-label="Switch theme" title="Switch appearance"><span class="uc-theme-icon">☾</span></button><div class="uc-avatar" title="<?= e((string)$user['full_name']) ?>"><?= e($initial) ?></div></div></header>
<main class="uc-content"><section class="uc-hero uc-reveal"><div class="uc-hero-grid"></div><div class="uc-orb uc-orb-one"></div><div class="uc-orb uc-orb-two"></div><div class="uc-hero-inner"><span class="uc-kicker"><span class="uc-pulse"></span> CONNECT · LEARN · GROW</span><h1>Make time for <em>better learning.</em></h1><p>Connect with your lecturers, book a consultation that works for you, and keep every conversation on track.</p><div class="uc-hero-actions"><a class="uc-button uc-primary" href="#request">Book a consultation <span>↗</span></a><a class="uc-button uc-ghost" href="#bookings">View my bookings <span>↓</span></a></div></div><div class="uc-hero-art" aria-hidden="true"><div class="uc-orbit uc-orbit-a"></div><div class="uc-orbit uc-orbit-b"></div><div class="uc-art-core">✦</div><div class="uc-float uc-float-a">1:1 support</div><div class="uc-float uc-float-b">Your progress ↗</div></div></section>
<section class="uc-stats uc-reveal" aria-label="Consultation statistics"><article class="uc-stat"><div class="uc-stat-top"><span>Total requests</span><span class="uc-stat-icon">▤</span></div><strong data-count="<?= count($bookings) ?>">0</strong><small>All consultation requests</small></article><article class="uc-stat"><div class="uc-stat-top"><span>Approved</span><span class="uc-stat-icon">✓</span></div><strong data-count="<?= count(array_filter($bookings, static fn($b) => ($b['status'] ?? '') === 'approved')) ?>">0</strong><small>Confirmed by lecturers</small></article><article class="uc-stat"><div class="uc-stat-top"><span>Available connections</span><span class="uc-stat-icon">⌘</span></div><strong data-count="<?= count($eligible) ?>">0</strong><small>Module–lecturer options</small></article><article class="uc-stat"><div class="uc-stat-top"><span>Awaiting response</span><span class="uc-stat-icon">◷</span></div><strong data-count="<?= count(array_filter($bookings, static fn($b) => in_array(($b['status'] ?? ''), ['pending','reschedule_proposed'], true))) ?>">0</strong><small>Pending or reschedule proposed</small></article></section>
<?php if ($error !== ''): ?><div class="uc-alert uc-alert-error" role="alert"><?= e($error) ?></div><?php endif; ?><?php if ($success !== ''): ?><div class="uc-alert uc-alert-success" role="status"><?= e($success) ?></div><?php endif; ?>
<section class="uc-section uc-reveal" id="request"><div class="uc-section-heading"><div><div class="uc-eyebrow">01 / PLAN YOUR SESSION</div><h2>Request a consultation<span>.</span></h2><p>Choose a lecturer and an available time, then tell them what you'd like to discuss.</p></div><span class="uc-section-mark">BOOK A SESSION</span></div>
<?php if (!$eligible): ?><div class="uc-empty"><span class="uc-empty-icon">⌘</span><h3>No lecturers available yet</h3><p>Enroll in a module and ask an administrator to assign its lecturer. Your available lecturers will appear here.</p><a class="uc-button uc-primary" href="<?= e(url('dashboard/explore.php')) ?>">Explore modules ↗</a></div><?php else: ?>
<div class="uc-booking-layout"><div class="uc-panel uc-booking-panel"><div class="uc-panel-header"><span class="uc-panel-icon">✦</span><div><h3>Session details</h3><p>Fill in the details to request your appointment.</p></div></div><form method="post" id="ucRequestForm"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="request"><div class="uc-field"><label for="lecturer_module">Module &amp; lecturer <span>*</span></label><select id="lecturer_module" required><option value="">Select a module and lecturer</option><?php foreach($eligible as $entry): ?><option value="<?= (int)$entry['lecturer_id'] ?>:<?= (int)$entry['module_id'] ?>"><?= e((string)$entry['module_code'].' — '.(string)$entry['module_name'].' · '.trim((string)$entry['title'].' '.(string)$entry['lecturer_name'])) ?></option><?php endforeach; ?></select><input type="hidden" name="lecturer_id" id="lecturer_id"><input type="hidden" name="module_id" id="module_id"></div>
<div class="uc-field"><label>Available dates &amp; times <span>*</span></label><input type="hidden" id="starts_at" name="starts_at" required><div class="uc-picker"><div class="uc-picker-head"><strong>Choose a date</strong><small>Published lecturer availability</small></div><div id="dateList" class="uc-date-list" role="group" aria-label="Available dates"><div class="uc-picker-empty">Choose a lecturer first.</div></div><div class="uc-picker-head uc-picker-time-head"><strong>Available start times</strong><small id="selectedDayLabel"></small></div><div id="timeList" class="uc-time-list" role="group" aria-label="Available start times"><div class="uc-picker-empty">Select a date first.</div></div><div id="selectionSummary" class="uc-selection-summary" role="status" hidden></div></div><small class="uc-field-hint">In-person consultation · no fixed appointment duration</small></div>
<div class="uc-field"><label for="topic">Discussion topic <span>*</span></label><input id="topic" name="topic" maxlength="180" placeholder="e.g. Understanding database normalization" required><div class="uc-field-counter"><span>What would you like help with?</span><span id="ucTopicCount">0 / 180</span></div></div><div class="uc-field"><label for="student_message">Additional details <span class="uc-optional">optional</span></label><textarea id="student_message" name="student_message" maxlength="5000" rows="4" placeholder="Give your lecturer a little more context..."></textarea><div class="uc-field-counter"><span>Keep it clear and specific</span><span id="ucMessageCount">0 / 5000</span></div></div><button type="submit" class="uc-button uc-primary uc-submit" id="ucSubmit">Send consultation request <span>↗</span></button></form></div>
<aside class="uc-panel uc-help-panel"><div class="uc-help-icon">✧</div><div class="uc-eyebrow">MAKE THE MOST OF IT</div><h3>Come prepared. Leave confident.</h3><p>A little preparation can make your consultation much more useful.</p><div class="uc-help-step"><b>01</b><span>Choose the module you're currently studying.</span></div><div class="uc-help-step"><b>02</b><span>Pick a published date and available time.</span></div><div class="uc-help-step"><b>03</b><span>Describe the concept or question you want to explore.</span></div><div class="uc-help-divider"></div><span class="uc-help-note">Your request is sent to your lecturer for review. A submitted request is not yet approved.</span></aside></div><?php endif; ?></section>
<section class="uc-section uc-reveal" id="bookings"><div class="uc-section-heading"><div><div class="uc-eyebrow">02 / YOUR ACTIVITY</div><h2>My consultations<span>.</span></h2><p>Stay on top of your upcoming, completed and cancelled requests.</p></div><span class="uc-total-pill"><?= count($bookings) ?> requests</span></div>
<div class="uc-toolbar"><div class="uc-tabs" role="group" aria-label="Filter consultations"><button type="button" class="uc-tab active" data-filter="active" aria-pressed="true">Active</button><button type="button" class="uc-tab" data-filter="completed" aria-pressed="false">Completed</button><button type="button" class="uc-tab" data-filter="cancelled" aria-pressed="false">Cancelled</button><button type="button" class="uc-tab" data-filter="all" aria-pressed="false">All</button></div><label class="uc-search"><span>⌕</span><input id="ucSearch" type="search" placeholder="Search consultations..." aria-label="Search consultations"></label></div>
<?php if (!$bookings): ?><div class="uc-empty"><span class="uc-empty-icon">◷</span><h3>Your consultation journey starts here</h3><p>Once you request a consultation, you'll be able to follow its status here.</p><a href="#request" class="uc-button uc-primary">Book your first session ↗</a></div><?php else: ?><div class="uc-bookings" id="ucBookings"><?php foreach ($bookings as $booking): $group = in_array((string)$booking['status'], ['completed'], true) ? 'completed' : (in_array((string)$booking['status'], ['cancelled','declined'], true) ? 'cancelled' : 'active'); $status=(string)$booking['status']; ?><a class="uc-booking uc-reveal" data-group="<?= e($group) ?>" data-search="<?= e(mb_strtolower((string)$booking['topic'].' '.(string)$booking['lecturer_name'].' '.(string)$booking['module_code'].' '.(string)$booking['module_name'].' '.$status)) ?>" href="<?= e(url('consultation-detail.php?id='.(int)$booking['id'])) ?>"><div class="uc-booking-left"><div class="uc-booking-meta"><span class="uc-status uc-status-<?= e(preg_replace('/[^a-z_]/','',$status)) ?>"><?= e(ucwords(str_replace('_',' ',$status))) ?></span><span class="uc-request-id">REQUEST #<?= (int)$booking['id'] ?></span></div><h3><?= e((string)$booking['topic']) ?></h3><p><?= e(trim((string)$booking['lecturer_title'].' '.(string)$booking['lecturer_name'])) ?> <span>·</span> <?= e((string)$booking['module_code']) ?></p><div class="uc-booking-date">◷ <?= e(date('D, M j Y · g:i A',strtotime((string)$booking['starts_at']))) ?></div></div><span class="uc-booking-arrow">↗</span></a><?php endforeach; ?></div><div class="uc-empty" id="ucConsultEmpty" hidden><span class="uc-empty-icon">⌕</span><h3>No matching consultations</h3><p>Try a different search or choose another status.</p></div><?php endif; ?></section><footer class="uc-footer"><span>© <?= date('Y') ?> ULearn · Learn together, grow further.</span><a href="<?= e(url('dashboard/index.php')) ?>">Back to dashboard ↗</a></footer></main></div></div>
<script type="application/json" id="ucSlotData"><?= json_encode($slots, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE) ?></script><script type="application/json" id="ucTakenData"><?= json_encode($takenStarts, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
<?php require_once __DIR__ . '/../includes/student-bot.php'; ?></body></html>