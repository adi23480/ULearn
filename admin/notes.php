<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$user = require_login();
if (!in_array((string)($user['role'] ?? ''), ['admin', 'main_admin'], true)) {
    http_response_code(403);
    exit('Access denied. Admin permission required.');
}

$pdo = db();
$adminId = (int)$user['id'];

// Personal unread notifications for the signed-in administrator.
$notificationStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0"
);
$notificationStmt->execute([$adminId]);
$unreadNotifications = (int)$notificationStmt->fetchColumn();

$error = '';
$notice = '';

// Private file delivery for authenticated administrators only. Never expose filesystem paths.
if (isset($_GET['file'])) {
    $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$id) { http_response_code(400); exit('Invalid note.'); }
    $stmt = $pdo->prepare('SELECT original_filename, stored_filename, preview_filename, mime_type FROM student_notes WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $note = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$note) { http_response_code(404); exit('Note not found.'); }
    $storage = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'ulearn_private' . DIRECTORY_SEPARATOR . 'notes';
    $requestedPreview = (string)($_GET['file'] ?? '') === 'preview';
    $originalStored = (string)$note['stored_filename'];
    if ($originalStored === '' || basename($originalStored) !== $originalStored || str_contains($originalStored, "\0")) {
        http_response_code(404); exit('File unavailable.');
    }
    $originalPath = $storage . DIRECTORY_SEPARATOR . $originalStored;
    if (!is_file($originalPath) || !is_readable($originalPath)) { http_response_code(404); exit('File unavailable.'); }
    $original = basename(str_replace('\\', '/', (string)$note['original_filename']));
    $originalExtension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $storedExtension = strtolower(pathinfo($originalStored, PATHINFO_EXTENSION));
    $mimeTypes = [
        'pdf' => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];
    if (!isset($mimeTypes[$originalExtension]) || $storedExtension !== $originalExtension) {
        http_response_code(415); exit('Unsupported file type.');
    }
    $isPreview = $requestedPreview;
    $stored = $originalStored;
    if ($requestedPreview && $originalExtension !== 'pdf') {
        $preview = (string)($note['preview_filename'] ?? '');
        if ($preview !== '' && basename($preview) === $preview && strtolower(pathinfo($preview, PATHINFO_EXTENSION)) === 'pdf'
            && is_file($storage . DIRECTORY_SEPARATOR . $preview)) {
            $stored = $preview;
        } else {
            $sofficeCandidates = [
                'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
                'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe',
                '/usr/bin/libreoffice',
            ];
            $soffice = null;
            foreach ($sofficeCandidates as $candidate) {
                if (is_file($candidate)) { $soffice = $candidate; break; }
            }
            if ($soffice === null || !function_exists('exec')) {
                http_response_code(503);
                exit('Office preview requires LibreOffice on the server. Install LibreOffice, then refresh. You can still download the original for review.');
            }
            $previewDir = $storage . DIRECTORY_SEPARATOR . 'previews';
            if (!is_dir($previewDir) && !mkdir($previewDir, 0700, true) && !is_dir($previewDir)) {
                http_response_code(503); exit('Preview storage unavailable.');
            }
            $workDir = $previewDir . DIRECTORY_SEPARATOR . bin2hex(random_bytes(12));
            if (!mkdir($workDir, 0700)) { http_response_code(503); exit('Preview storage unavailable.'); }
            $input = $workDir . DIRECTORY_SEPARATOR . 'note.' . $originalExtension;
            $output = $workDir . DIRECTORY_SEPARATOR . 'note.pdf';
            $converted = false;
            try {
                if (copy($originalPath, $input)) {
                    $profileDir = $workDir . DIRECTORY_SEPARATOR . 'lo-profile';
                    mkdir($profileDir, 0700);
                    $profileUri = 'file:///' . str_replace('\\', '/', ltrim($profileDir, '/'));
                    if (DIRECTORY_SEPARATOR === '/') { $profileUri = 'file://' . $profileDir; }
                    $command = escapeshellarg($soffice) . ' ' . escapeshellarg('-env:UserInstallation=' . $profileUri)
                        . ' --headless --convert-to pdf --outdir ' . escapeshellarg($workDir)
                        . ' ' . escapeshellarg($input) . ' 2>&1';
                    $outputLines = []; $exitCode = 1;
                    exec($command, $outputLines, $exitCode);
                    if ($exitCode === 0 && is_file($output) && filesize($output) > 0) {
                        $previewName = 'note-' . (int)$id . '-' . bin2hex(random_bytes(12)) . '.pdf';
                        if (rename($output, $storage . DIRECTORY_SEPARATOR . $previewName)) {
                            $stmt = $pdo->prepare('UPDATE student_notes SET preview_filename = ? WHERE id = ?');
                            $stmt->execute([$previewName, $id]);
                            $stored = $previewName;
                            $converted = true;
                        }
                    }
                }
            } finally {
                foreach (glob($workDir . DIRECTORY_SEPARATOR . '*') ?: [] as $item) {
                    if (is_file($item)) { @unlink($item); }
                }
                @rmdir($workDir . DIRECTORY_SEPARATOR . 'lo-profile');
                @rmdir($workDir);
            }
            if (!$converted) { http_response_code(503); exit('Could not generate the document preview. Download the original to review it.'); }
        }
    }
    $path = $storage . DIRECTORY_SEPARATOR . $stored;
    if (!is_file($path) || !is_readable($path)) { http_response_code(404); exit('File unavailable.'); }
    $extension = strtolower(pathinfo($stored, PATHINFO_EXTENSION));
    $mime = $isPreview ? $mimeTypes['pdf'] : $mimeTypes[$originalExtension];
    $inline = $isPreview;
    $name = $isPreview ? 'ULearn-Note-Preview.pdf' : ($original !== '' ? $original : 'ULearn-Note.' . $extension);
    $ascii = preg_replace('/[^A-Za-z0-9._ -]/', '_', $name) ?: 'ULearn-Note.' . $extension;
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: ' . $mime);
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Content-Security-Policy: sandbox');
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $noteId = filter_var($_POST['note_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $action = (string)($_POST['action'] ?? '');
    $reason = trim((string)($_POST['reason'] ?? ''));
    $inspected = (string)($_POST['inspected'] ?? '') === '1';
    if (!$noteId || !in_array($action, ['approve', 'reject'], true)) {
        $error = 'Invalid review request.';
    } elseif (!$inspected) {
        $error = 'Please inspect the note and confirm that you reviewed it before deciding.';
    } elseif ($action === 'reject' && (mb_strlen($reason) < 3 || mb_strlen($reason) > 500)) {
        $error = 'Please provide a rejection reason between 3 and 500 characters.';
    } else {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT n.id, n.status, n.uploaded_by, u.role FROM student_notes n JOIN users u ON u.id = n.uploaded_by WHERE n.id = ? FOR UPDATE');
            $stmt->execute([$noteId]);
            $note = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$note) { throw new RuntimeException('Note not found.'); }
            if ($note['status'] !== 'pending') { throw new RuntimeException('This note has already been reviewed. Refresh the page.'); }
            if ($action === 'approve') {
                $stmt = $pdo->prepare("UPDATE student_notes SET status = 'approved', reviewed_by = ?, reviewed_at = NOW(), approved_at = NOW(), rejection_reason = NULL WHERE id = ? AND status = 'pending'");
                $stmt->execute([$adminId, $noteId]);
                if ((string)$note['role'] === 'student') {
                    $stmt = $pdo->prepare('INSERT IGNORE INTO student_note_points (note_id, user_id, points) VALUES (?, ?, 10)');
                    $stmt->execute([$noteId, (int)$note['uploaded_by']]);
                }
            } else {
                $stmt = $pdo->prepare("UPDATE student_notes SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), approved_at = NULL, rejection_reason = ? WHERE id = ? AND status = 'pending'");
                $stmt->execute([$adminId, $reason, $noteId]);
            }
            $pdo->commit();
            go('admin/notes.php?result=' . ($action === 'approve' ? 'approved' : 'rejected'));
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            if ($ex instanceof RuntimeException) { $error = $ex->getMessage(); }
            else { error_log('ULearn notes review error: ' . $ex->getMessage()); $error = 'Unable to save the review. Please try again.'; }
        }
    }
}

$result = (string)($_GET['result'] ?? '');
if ($result === 'approved') { $notice = 'Note approved and published. Student contribution points were awarded if eligible.'; }
if ($result === 'rejected') { $notice = 'Note rejected. The uploader can see your rejection reason.'; }
$filter = (string)($_GET['status'] ?? 'pending');
if (!in_array($filter, ['pending', 'approved', 'rejected', 'all'], true)) { $filter = 'pending'; }

try {
    $where = $filter === 'all' ? '' : 'WHERE n.status = ?';
    $sql = "SELECT n.id, n.module_id, n.title, n.description, n.original_filename, n.file_size, n.mime_type, n.preview_filename, n.status, n.rejection_reason, n.created_at, n.reviewed_at, u.full_name AS author_name, u.role AS author_role, m.module_code, m.module_name, reviewer.full_name AS reviewer_name FROM student_notes n JOIN users u ON u.id = n.uploaded_by JOIN academic_modules m ON m.id = n.module_id LEFT JOIN users reviewer ON reviewer.id = n.reviewed_by $where ORDER BY CASE WHEN n.status = 'pending' THEN 0 ELSE 1 END, n.created_at DESC, n.id DESC LIMIT 200";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($filter === 'all' ? [] : [$filter]);
    $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
    foreach ($pdo->query('SELECT status, COUNT(*) AS total FROM student_notes GROUP BY status') as $row) {
        $counts[(string)$row['status']] = (int)$row['total'];
    }
} catch (Throwable $ex) {
    error_log('ULearn notes admin list error: ' . $ex->getMessage());
    http_response_code(500); exit('Unable to load the notes queue.');
}

$fullName = (string)($user['full_name'] ?? 'Administrator');
$nameParts = preg_split('/\s+/', trim($fullName));
$firstName = $nameParts[0] ?: 'Administrator';
$initial = strtoupper(substr($firstName, 0, 1));
$roleLabel = $user['role'] === 'main_admin' ? 'Main Administrator' : 'Administrator';
$theme = (string)($user['theme'] ?? 'system');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Notes Approval — U Learn</title>
<script>
    try {
        const saved = localStorage.getItem('ulearn-theme') || <?= json_encode($theme, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const theme = saved === 'system'
            ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
            : saved;
        document.documentElement.dataset.theme = theme;
        localStorage.setItem('ulearn-theme', theme);
    } catch (error) {}
</script>
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
        --danger: #a9274c;
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
        --danger: #ff91ad;
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
        transition: background .2s, color .2s;
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
    .sidebar-bottom strong {
        display: block;
        overflow-wrap: anywhere;
    }
    .sidebar-bottom small {
        color: var(--muted);
        overflow-wrap: anywhere;
    }
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

    .stage9-management {
        margin: 4px 0 12px;
        padding: 10px 12px;
        border: 1px solid var(--border);
        border-radius: 14px;
        background: var(--surface-alt);
    }
    .stage9-management summary {
        cursor: pointer;
        font-weight: 750;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 1.2px;
        color: var(--muted);
    }
    .stage9-management a {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 12px;
        margin-top: 6px;
        border-radius: 10px;
        color: var(--text);
        font-size: 13px;
        font-weight: 600;
    }
    .stage9-management a:hover {
        background: var(--accent-light);
        color: var(--accent);
    }

    h1 {
        font-size: clamp(28px, 4vw, 44px);
        letter-spacing: -1.5px;
        margin: 6px 0 10px;
    }
    h1 span { color: var(--accent); }
    .lead { color: var(--muted); font-size: 14px; max-width: 680px; margin-bottom: 0; }

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
        padding: 10px 16px;
        border: 1px solid var(--border);
        border-radius: 12px;
        background: var(--surface);
        font-size: 13px;
        font-weight: 750;
        transition: border-color .18s ease, background .18s ease, color .18s ease;
    }
    .tab.active {
        background: var(--accent);
        color: #fff;
        border-color: var(--accent);
    }
    .tab:hover:not(.active) { border-color: var(--accent); color: var(--accent); }

    .note-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 20px;
        padding: 28px;
        margin-bottom: 18px;
        box-shadow: var(--shadow);
    }
    .note-top {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 15px;
        margin-bottom: 12px;
    }
    .note-card h2 { font-size: 20px; letter-spacing: -.5px; margin: 6px 0; }
    
    .pill {
        display: inline-block;
        border-radius: 100px;
        padding: 6px 12px;
        background: var(--soft);
        color: var(--green);
        font-size: 12px;
        font-weight: 750;
    }
    .pill.pending { background: var(--accent-light); color: var(--accent); }
    .pill.rejected { background: #ffedf0; color: #a32143; }

    .meta { font-size: 12px; color: var(--muted); overflow-wrap: anywhere; margin-top: 8px; }
    
    .button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 11px 18px;
        border: 1px solid var(--border);
        border-radius: 12px;
        background: var(--surface-alt);
        color: var(--text);
        font-size: 13px;
        font-weight: 700;
        cursor: pointer;
        transition: border-color .18s ease, background .18s ease, color .18s ease;
    }
    .button.primary {
        background: var(--accent);
        color: #fff;
        border-color: var(--accent);
        box-shadow: 0 8px 20px rgba(103,86,232,.2);
    }
    .button.primary:hover { filter: brightness(1.08); transform: translateY(-2px); }
    .button.danger { color: #fff; background: #c43b54; border-color: #c43b54; }
    .button.danger:hover { filter: brightness(1.08); transform: translateY(-2px); }
    .button:hover:not(.primary):not(.danger) { border-color: var(--accent); color: var(--accent); }

    .actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 18px; }
    
    .review {
        border-top: 1px solid var(--border);
        margin-top: 20px;
        padding-top: 20px;
        display: grid;
        gap: 16px;
    }
    .review form {
        display: flex;
        flex-wrap: wrap;
        gap: 14px;
        align-items: flex-end;
    }
    .review-confirm {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 13px;
        font-weight: 700;
        cursor: pointer;
    }
    .review label {
        display: block;
        font-size: 13px;
        font-weight: 700;
        flex: 1;
        min-width: 260px;
    }
    .review textarea {
        width: 100%;
        border: 1px solid var(--border);
        border-radius: 11px;
        padding: 12px 14px;
        background: var(--surface-alt);
        color: var(--text);
        font: inherit;
        resize: vertical;
        outline: none;
        margin-top: 6px;
    }
    .review textarea:focus { border-color: var(--accent); }

    .message {
        padding: 14px 18px;
        border-radius: 14px;
        margin: 22px 0;
        font-size: 14px;
    }
    .success { background: var(--soft); color: var(--green); }
    .error { background: #ffedf0; color: #a32143; }

    .empty {
        background: var(--surface);
        border: 1px dashed var(--border);
        border-radius: 20px;
        padding: 35px;
        text-align: center;
        color: var(--muted);
        font-size: 14px;
    }

    .preview {
        width: 100%;
        height: 540px;
        border: 1px solid var(--border);
        border-radius: 14px;
        margin-top: 16px;
        background: #fff;
    }
    .preview-panel { margin-top: 16px; }
    .preview-panel summary {
        cursor: pointer;
        font-size: 13px;
        font-weight: 750;
        color: var(--accent);
    }

    .footer {
        margin-top: 50px;
        padding-top: 20px;
        border-top: 1px solid var(--border);
        font-size: 12px;
        color: var(--muted);
    }

    @media (max-width: 900px) {
        .layout { grid-template-columns: 1fr; }
        .sidebar { border-right: 0; border-bottom: 1px solid var(--border); padding: 18px; gap: 18px; }
        .nav { display: flex; flex-wrap: wrap; }
        .sidebar-bottom { display: none; }
        .main { padding-top: 25px; }
    }
    @media (max-width: 600px) {
        .main { padding: 24px 18px 60px; }
        .note-card { padding: 20px; }
        .preview { height: 360px; }
    }
</style>

<script src="<?= htmlspecialchars(url('assets/js/theme-global.js?v=20261107-2'), ENT_QUOTES, 'UTF-8') ?>"></script>
<link rel="stylesheet" href="<?= e(url('assets/css/ulearn-design-system.css?v=1')) ?>">
<script defer src="<?= e(url('assets/js/ulearn-motion.js?v=1')) ?>"></script>
</head>

<body>

<div class="layout">

    <aside class="sidebar" id="adminSidebar">
        <a class="brand" href="<?= e(url('admin/index.php')) ?>">
            u learn<span>.</span>
        </a>

        <nav aria-label="Admin navigation">
            <p class="nav-label">System management</p>
            <details class="stage9-management">
                <summary>System Access</summary>
                <a href="<?= e(url('admin/users.php')) ?>">
                    <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    All Registered Users
                </a>
                <a href="<?= e(url('admin/change-requests.php')) ?>">
                    <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                    Academic Requests
                </a>
            </details>

            <p class="nav-label" style="margin-top: 18px;">Your workspace</p>

            <div class="nav ul-nav">
                <a href="<?= e(url('admin/index.php')) ?>">
                    <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                    <span>Overview</span>
                </a>
                <a href="<?= e(url('admin/lecturers.php')) ?>">
                    <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    <span>Lecturers</span>
                </a>
                <a href="<?= e(url('admin/academics.php')) ?>">
                    <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                    <span>Academics</span>
                </a>
                <a href="<?= e(url('admin/universities.php')) ?>">
                     <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="m2 9 10-5 10 5-10 5z"/><path d="M6 12v5c4 3 8 3 12 0v-5M22 9v7"/></svg>
                     <span>Universities</span>
                </a>
                <a href="<?= e(url('admin/quizzes.php')) ?>">
                    <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    <span>Quizzes</span>
                </a>
                <a href="<?= e(url('admin/assignments.php')) ?>">
                    <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-2 2"/><path d="M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l2-2"/></svg>
                    <span>Assignments</span>
                </a>
                <a href="<?= e(url('admin/announcements.php')) ?>">
                    <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                    <span>Announcements</span>
                </a>
                <a class="active" href="<?= e(url('admin/notes.php')) ?>">
                    <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    <span>Notes Approval<?= $counts['pending'] ? ' (' . $counts['pending'] . ')' : '' ?></span>
                </a>
                <a href="<?= e(url('admin/platform-settings.php')) ?>">
                    <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l-.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                    <span>Platform Settings</span>
                </a>
                <a href="<?= e(url('admin/profile.php')) ?>">
                    <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <span>My Profile &amp; Settings</span>
                </a>
                <a href="<?= e(url('admin/consultations.php')) ?>">
                    <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <span>Consultations</span>
                </a>
                <a href="<?= e(url('admin/kuppiya.php')) ?>">
                    <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3"/><path d="M3 20v-2a6 6 0 0 1 12 0v2"/><path d="M17 5a3 3 0 0 1 0 6"/><path d="M18 15a5 5 0 0 1 3 5"/></svg>
                    <span>Kuppiya Sessions</span>
                </a>
            </div>
        </nav>

        <div class="sidebar-bottom">
            <small>Signed in as</small>
            <strong><?= e($fullName) ?></strong>
            <small><?= e((string)$user['email']) ?></small>
        </div>
    </aside>

    <main class="main">

        <header class="topbar">
            <span class="eyebrow">
                U LEARN / ADMIN SPACE / NOTES APPROVAL
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

                <button
                    type="button"
                    class="theme-toggle"
                    id="themeToggle"
                    aria-label="Switch appearance"
                    title="Switch appearance"
                >◐</button>

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

        <section>
            <div class="eyebrow">ADMINISTRATION WORKSPACE</div>
            <h1>Notes approval<span>.</span></h1>
            <p class="lead">Open and inspect each document before approving or rejecting it. PDF previews open directly; DOCX and PPTX previews require LibreOffice installed on the server. Approving an eligible student note awards 10 points once.</p>
        </section>

        <?php if ($error !== ''): ?><div class="message error" role="alert"><?= e($error) ?></div><?php endif; ?>
        <?php if ($notice !== ''): ?><div class="message success" role="status"><?= e($notice) ?></div><?php endif; ?>

        <div class="tabs" aria-label="Filter notes">
            <?php foreach (['pending'=>'Pending','approved'=>'Approved','rejected'=>'Rejected','all'=>'All notes'] as $key => $label): ?>
                <a class="tab<?= $filter === $key ? ' active' : '' ?>" href="<?= e(url('admin/notes.php?status=' . $key)) ?>"><?= e($label) ?><?= $key === 'all' ? '' : ' (' . ($counts[$key] ?? 0) . ')' ?></a>
            <?php endforeach; ?>
        </div>

        <?php if (!$notes): ?>
            <div class="empty">
                <strong>No <?= e($filter === 'all' ? '' : $filter) ?> notes found.</strong>
                <p style="margin-top: 6px; color: var(--muted); font-size: 13px;">Student submissions will appear here when available.</p>
            </div>
        <?php else: foreach ($notes as $note): ?>
            <article class="note-card">
                <div class="note-top">
                    <div>
                        <div class="eyebrow"><?= e((string)$note['module_code']) ?> · <?= e((string)$note['module_name']) ?></div>
                        <h2><?= e((string)$note['title']) ?></h2>
                    </div>
                    <?php 
                        $statusClass = 'pending';
                        if ((string)$note['status'] === 'approved') $statusClass = '';
                        elseif ((string)$note['status'] === 'rejected') $statusClass = 'rejected';
                    ?>
                    <span class="pill <?= $statusClass ?>"><?= e(ucfirst((string)$note['status'])) ?></span>
                </div>

                <?php if ((string)$note['description'] !== ''): ?>
                    <p style="font-size: 14px; margin-bottom: 14px;"><?= nl2br(e((string)$note['description'])) ?></p>
                <?php endif; ?>

                <div class="meta">
                    Uploaded by <strong><?= e((string)$note['author_name']) ?></strong> (<?= e(ucwords(str_replace('_', ' ', (string)$note['author_role']))) ?>) · <?= e((string)$note['original_filename']) ?> · <?= number_format((int)$note['file_size'] / 1048576, 2) ?> MB · <?= e(date('M j, Y, g:i a', strtotime((string)$note['created_at']))) ?>
                </div>

                <div class="actions">
                    <a class="button" href="<?= e(url('admin/notes.php?id=' . (int)$note['id'] . '&file=download')) ?>">↓ Download original</a>
                </div>

                <?php 
                $ext = strtolower(pathinfo((string)$note['original_filename'], PATHINFO_EXTENSION)); 
                $canPreview = in_array($ext, ['pdf', 'docx', 'pptx'], true); 
                ?>
                <?php if ($canPreview): ?>
                    <details class="preview-panel">
                        <summary>View note before reviewing (PDF / DOCX / PPTX)</summary>
                        <iframe class="preview" title="Preview of <?= e((string)$note['title']) ?>" loading="lazy" src="<?= e(url('admin/notes.php?id=' . (int)$note['id'] . '&file=preview')) ?>"></iframe>
                    </details>
                <?php else: ?>
                    <p class="meta" style="margin-top: 12px;">Preview is unavailable for this file type.</p>
                <?php endif; ?>

                <?php if ((string)$note['status'] === 'pending'): ?>
                    <div class="review">
                        <form method="post" action="<?= e(url('admin/notes.php?status=pending')) ?>" onsubmit="return confirm('Have you reviewed the document? Approve and publish this note? This will award 10 points if uploaded by a student.');">
                            <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                            <input type="hidden" name="note_id" value="<?= (int)$note['id'] ?>">
                            <input type="hidden" name="action" value="approve">
                            <label class="review-confirm">
                                <input type="checkbox" name="inspected" value="1" required> I inspected this document
                            </label>
                            <button class="button primary" type="submit">✓ Approve &amp; publish (+10 points)</button>
                        </form>

                        <form method="post" action="<?= e(url('admin/notes.php?status=pending')) ?>">
                            <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                            <input type="hidden" name="note_id" value="<?= (int)$note['id'] ?>">
                            <input type="hidden" name="action" value="reject">
                            <label>Reason for rejection
                                <textarea name="reason" rows="2" minlength="3" maxlength="500" required placeholder="Explain what needs to be corrected..."></textarea>
                            </label>
                            <div style="width: 100%; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px; margin-top: 6px;">
                                <label class="review-confirm" style="flex: unset; min-width: unset;">
                                    <input type="checkbox" name="inspected" value="1" required> I inspected this document
                                </label>
                                <button class="button danger" type="submit" onclick="return confirm('Have you reviewed this document and confirmed the rejection reason?')">Reject note</button>
                            </div>
                        </form>
                    </div>
                <?php else: ?>
                    <p class="meta" style="margin-top: 18px;">
                        Reviewed<?= $note['reviewer_name'] ? ' by ' . e((string)$note['reviewer_name']) : '' ?><?= $note['reviewed_at'] ? ' on ' . e(date('M j, Y', strtotime((string)$note['reviewed_at']))) : '' ?><?php if ((string)$note['status'] === 'rejected' && $note['rejection_reason']): ?> · Reason: <?= e((string)$note['rejection_reason']) ?><?php endif; ?>
                    </p>
                <?php endif; ?>
            </article>
        <?php endforeach; endif; ?>

        <p style="font-size: 12px; color: var(--muted); margin-top: 24px;">Showing up to 200 notes. Only administrators can access this review page.</p>

        <footer class="footer">
            © <?= date('Y') ?> U Learn · Administration Workspace
        </footer>
    </main>
</div>

<script>
const themeButton = document.getElementById('themeToggle');
function updateThemeButton() {
    const theme = document.documentElement.dataset.theme;
    themeButton.textContent = theme === 'dark' ? '☀' : '☾';
}
themeButton.addEventListener('click', function () {
    const current = document.documentElement.dataset.theme;
    const next = current === 'dark' ? 'light' : 'dark';
    document.documentElement.dataset.theme = next;
    localStorage.setItem('ulearn-theme', next);
    updateThemeButton();
});
updateThemeButton();
</script>

</body>
</html>