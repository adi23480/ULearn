<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$admin = require_login();
if (!in_array((string)$admin['role'], ['admin', 'main_admin'], true)) {
    http_response_code(403);
    exit('Admin access only');
}

$pdo = db();
$error = '';
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $id = (int)($_POST['request_id'] ?? 0);
        $action = (string)($_POST['action'] ?? '');
        $note = trim((string)($_POST['note'] ?? ''));

        if (!in_array($action, ['approve', 'reject'], true) || mb_strlen($note) > 1000) {
            throw new RuntimeException('Invalid review.');
        }

        $pdo->beginTransaction();
        $s = $pdo->prepare("SELECT * FROM academic_change_requests WHERE id=? AND status='pending' FOR UPDATE");
        $s->execute([$id]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            throw new RuntimeException('This request is no longer pending.');
        }

        if ($action === 'approve') {
            $valid = $pdo->prepare('SELECT 1 FROM academic_degree_intakes WHERE degree_id=? AND batch_id=? AND is_active=1');
            $valid->execute([$r['requested_degree_id'], $r['requested_batch_id']]);
            if (!$valid->fetchColumn()) {
                throw new RuntimeException('Requested degree/intake is no longer available.');
            }
            $pdo->prepare('INSERT INTO student_academic_profiles(user_id,degree_id,batch_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE degree_id=VALUES(degree_id),batch_id=VALUES(batch_id)')->execute([$r['student_user_id'], $r['requested_degree_id'], $r['requested_batch_id']]);
            $pdo->prepare('INSERT INTO student_account_audit(student_user_id,admin_user_id,action) VALUES (?,?,?)')->execute([$r['student_user_id'], $admin['id'], 'correct_enrollment']);
        }

        $pdo->prepare('UPDATE academic_change_requests SET status=?,admin_user_id=?,admin_note=?,reviewed_at=NOW() WHERE id=?')->execute([$action === 'approve' ? 'approved' : 'rejected', $admin['id'], $note, $id]);
        $pdo->prepare('INSERT INTO notifications(user_id,actor_user_id,type,title,message,target_url,is_read) VALUES (?,?,?,?,?,?,0)')->execute([$r['student_user_id'], $admin['id'], 'system', 'Academic change request ' . ($action === 'approve' ? 'approved' : 'rejected'), $note !== '' ? $note : ($action === 'approve' ? 'Your programme/intake was updated.' : 'Your request was not approved.'), 'dashboard/profile.php']);
        $pdo->commit();
        $notice = 'Request ' . ($action === 'approve' ? 'approved' : 'rejected') . '.';
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $ex instanceof RuntimeException ? $ex->getMessage() : 'Unable to review request.';
        error_log($ex->getMessage());
    }
}

$status = (string)($_GET['status'] ?? 'pending');
if (!in_array($status, ['pending', 'approved', 'rejected', 'all'], true)) {
    $status = 'pending';
}

$sql = 'SELECT r.*,u.full_name,u.email,u.student_number,d.name degree_name,b.batch_code FROM academic_change_requests r JOIN users u ON u.id=r.student_user_id JOIN academic_degrees d ON d.id=r.requested_degree_id JOIN academic_batches b ON b.id=r.requested_batch_id' . ($status === 'all' ? '' : ' WHERE r.status=?') . ' ORDER BY r.id DESC LIMIT 150';
$stmt = $pdo->prepare($sql);
$stmt->execute($status === 'all' ? [] : [$status]);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Personal unread notifications for the signed-in administrator.
$notificationStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0"
);
$notificationStmt->execute([(int)$admin['id']]);
$unreadNotifications = (int)$notificationStmt->fetchColumn();

$fullName = (string)($admin['full_name'] ?? 'Administrator');
$nameParts = preg_split('/\s+/', trim($fullName));
$firstName = $nameParts[0] ?: 'Administrator';
$initial = strtoupper(substr($firstName, 0, 1));
$roleLabel = $admin['role'] === 'main_admin' ? 'Main Administrator' : 'Administrator';
$theme = (string)($admin['theme'] ?? 'system');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Academic Change Requests — U Learn</title>
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
        --danger: #c43b54;
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
        --danger: #ff8a9b;
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
    button, select, input, textarea { font: inherit; }
    button { cursor: pointer; }
    
    .layout {
        display: block;
        min-height: 100vh;
    }
    .main {
        max-width: 1100px;
        margin: 0 auto;
        padding: 34px clamp(20px, 5vw, 40px) 80px;
    }
    .topbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 20px;
        margin-bottom: 40px;
    }
    .topbar-right {
        display: flex;
        align-items: center;
        gap: 16px;
    }
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
    .theme-toggle {
        border: 1px solid var(--border);
        background: var(--surface);
        color: var(--text);
        border-radius: 12px;
        width: 42px;
        height: 42px;
        display: grid;
        place-items: center;
        font-size: 18px;
        box-shadow: var(--shadow);
        transition: transform .18s ease, background .18s ease, border-color .18s ease;
    }
    .theme-toggle:hover {
        background: var(--accent-light);
        border-color: var(--accent);
        transform: translateY(-2px);
    }
    .avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: var(--accent-light);
        color: var(--accent);
        display: grid;
        place-items: center;
        font-weight: 800;
        transition: transform .18s ease;
    }
    .avatar:hover { transform: translateY(-2px); }
    .logout {
        background: transparent;
        border: 0;
        color: var(--muted);
        font-size: 13px;
        font-weight: 650;
        cursor: pointer;
    }
    .logout:hover { color: var(--text); }
    h1, h2, h3, p { margin-top: 0; }
    
    .hero {
        border-radius: 24px;
        padding: 32px;
        background: linear-gradient(115deg, #063e39, #087d62 70%, #12677b);
        color: white;
        margin-bottom: 30px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 20px;
    }
    .hero h1 { font-size: clamp(28px, 4vw, 44px); margin: 6px 0; letter-spacing: -1.5px; }
    .hero p { opacity: .88; margin-bottom: 0; max-width: 600px; }
    .hero .pill {
        display: inline-flex;
        align-items: center;
        padding: 8px 16px;
        border-radius: 100px;
        background: rgba(255, 255, 255, 0.15);
        backdrop-filter: blur(8px);
        color: #fff;
        font-size: 12px;
        font-weight: 750;
        white-space: nowrap;
        border: 1px solid rgba(255, 255, 255, 0.2);
    }

    .tabs {
        display: flex;
        gap: 9px;
        flex-wrap: wrap;
        margin: 28px 0;
    }
    .tab {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 10px 15px;
        border: 1px solid var(--border);
        border-radius: 12px;
        background: var(--surface-alt);
        color: var(--muted);
        font-size: 13px;
        font-weight: 750;
        cursor: pointer;
        transition: transform .18s, border-color .18s;
    }
    .tab.active {
        background: linear-gradient(110deg, #5d4ae0, #8571fa);
        color: #fff;
        border-color: transparent;
        box-shadow: 0 9px 22px rgba(103,86,232,.2);
    }
    .tab:hover { transform: translateY(-1px); border-color: var(--accent); color: var(--text); }
    .tab.active:hover { color: #fff; }

    .card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 20px;
        padding: 28px;
        margin-bottom: 18px;
        box-shadow: var(--shadow);
    }
    .card h3 {
        font-size: 18px;
        letter-spacing: -.5px;
        margin-bottom: 8px;
    }
    label {
        display: block;
        font-size: 13px;
        font-weight: 750;
        margin: 16px 0 7px;
    }
    textarea {
        display: block;
        width: 100%;
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 12px 13px;
        background: var(--surface-alt);
        color: var(--text);
        outline: none;
        resize: vertical;
        transition: border-color .2s, box-shadow .2s;
    }
    textarea:focus {
        border-color: var(--accent);
        box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent) 18%, transparent);
    }
    .btn {
        border: 1px solid var(--border);
        background: var(--surface-alt);
        color: var(--text);
        border-radius: 12px;
        padding: 10px 15px;
        font-size: 13px;
        font-weight: 750;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        cursor: pointer;
        transition: transform .18s, border-color .18s;
    }
    .btn.primary {
        background: linear-gradient(110deg, #5d4ae0, #8571fa);
        border-color: transparent;
        color: #fff;
        box-shadow: 0 9px 22px rgba(103,86,232,.2);
    }
    .btn.reject {
        background: var(--surface-alt);
        color: var(--danger);
        border-color: var(--border);
    }
    .btn:hover { transform: translateY(-1px); border-color: var(--accent); }
    .actions { display: flex; gap: 10px; margin-top: 16px; flex-wrap: wrap; }
    
    .message {
        padding: 13px 17px;
        background: var(--accent-light);
        color: var(--accent);
        border-radius: 12px;
        margin-bottom: 20px;
        font-size: 13px;
        font-weight: 650;
    }
    .message.error { background: rgba(196,59,84,.1); color: var(--danger); }
    
    .meta { color: var(--muted); font-size: 12px; }
    
    .pill {
        display: inline-flex;
        align-items: center;
        padding: 6px 12px;
        border-radius: 999px;
        background: var(--accent-light);
        color: var(--accent);
        font-size: 11px;
        font-weight: 850;
    }
    
    .footer {
        margin-top: 50px;
        padding-top: 20px;
        border-top: 1px solid var(--border);
        font-size: 12px;
        color: var(--muted);
    }

    @media (max-width: 600px) {
        .main { padding: 24px 18px 60px; }
        .hero { flex-direction: column; align-items: flex-start; }
        .topbar { flex-wrap: wrap; }
    }
</style>

<link rel="stylesheet" href="<?= e(url('assets/css/ulearn-design-system.css?v=1')) ?>">
<script defer src="<?= e(url('assets/js/ulearn-motion.js?v=1')) ?>"></script>
<script src="<?= htmlspecialchars(url('assets/js/theme-global.js?v=20261112-1'), ENT_QUOTES, 'UTF-8') ?>"></script>
</head>

<body>

<div class="layout">
    <main class="main">
        <header class="topbar">
            <span class="eyebrow">
                U LEARN / ACADEMIC REQUESTS SPACE
            </span>

            <div class="topbar-right">
                <a class="notification-bell" href="<?= e(url('dashboard/notifications.php')) ?>"
                   aria-label="Notifications<?= $unreadNotifications ? ' (' . $unreadNotifications . ' unread)' : '' ?>"
                   title="Open notification center">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg>
                    <?php if ($unreadNotifications > 0): ?>
                        <span class="notification-count" aria-hidden="true"><?= $unreadNotifications > 99 ? '99+' : $unreadNotifications ?></span>
                    <?php endif; ?>
                </a>

                <?php include __DIR__ . '/../includes/theme-toggle-button.php'; ?>

                <a class="avatar" href="<?= e(url('admin/profile.php')) ?>" aria-label="Open My Profile and Settings" title="My Profile & Settings">
                    <?= e($initial) ?>
                </a>

                <form method="post" action="<?= e(url('auth/logout.php')) ?>" style="margin:0;">
                    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                    <button type="submit" class="logout">
                        Log out ↗
                    </button>
                </form>
            </div>
        </header>

        <header class="hero">
            <div>
                <div style="font-size:12px; font-weight:750; text-transform:uppercase; letter-spacing:1.5px; opacity:0.8;">Administration / People & Accounts</div>
                <h1>Academic change requests</h1>
                <p>Review identity corrections before modifying locked student records.</p>
            </div>
            <span class="pill">
                <?= e($roleLabel) ?>
            </span>
        </header>

        <?php if ($error !== ''): ?><div class="message error" role="alert"><?= e($error) ?></div><?php endif; ?>
        <?php if ($notice !== ''): ?><div class="message" role="status"><?= e($notice) ?></div><?php endif; ?>

        <div class="tabs" aria-label="Filter requests">
            <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All requests'] as $v => $label): ?>
                <a class="tab<?= $v === $status ? ' active' : '' ?>" href="?status=<?= e($v) ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </div>

        <?php foreach ($requests as $r): ?>
            <section class="card">
                <h3><?= e($r['full_name']) ?></h3>
                <p class="meta"><?= e($r['email']) ?> · ID <?= e($r['student_number'] ?: $r['student_user_id']) ?> · <?= e($r['created_at']) ?></p>
                <p style="margin: 12px 0;">Requested: <strong><?= e($r['degree_name']) ?> / <?= e($r['batch_code']) ?></strong></p>
                <p style="margin: 12px 0;">Reason: <?= e($r['reason']) ?></p>
                <p style="margin: 12px 0;">Status: <span class="pill"><?= e(ucfirst($r['status'])) ?></span></p>

                <?php if ($r['status'] === 'pending'): ?>
                    <form method="post">
                        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                        <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                        <label for="note-<?= (int)$r['id'] ?>">Admin note (optional)</label>
                        <textarea id="note-<?= (int)$r['id'] ?>" name="note" maxlength="1000" rows="2" placeholder="Provide context if needed..."></textarea>
                        <div class="actions">
                            <button class="btn primary" name="action" value="approve" onclick="return confirm('Approve and change this student academic profile?')">Approve & update</button>
                            <button class="btn reject" name="action" value="reject" onclick="return confirm('Reject this request?')">Reject request</button>
                        </div>
                    </form>
                <?php else: ?>
                    <p class="meta" style="margin-top: 12px;">Admin note: <?= e($r['admin_note'] ?: '—') ?></p>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>

        <?php if (!$requests): ?>
            <div class="card" style="text-align: center; color: var(--muted); font-size: 13px;">No requests in this category.</div>
        <?php endif; ?>

        <footer class="footer">
            © <?= date('Y') ?> U Learn · Administration Workspace
        </footer>
    </main>
</div>

</body>
</html>
