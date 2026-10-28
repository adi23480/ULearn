<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
$user = require_login();
if (!in_array((string)$user['role'], ['admin', 'main_admin'], true)) {
    http_response_code(403);
    exit('Admin access required.');
}
$pdo = db();
$adminId = (int)$user['id'];
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
        // Office documents are converted to private PDF previews on demand.
        // Requires LibreOffice installed on the XAMPP server; no public upload URLs.
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
            // Unique working directory avoids concurrent conversion collisions.
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
                    // The UNIQUE(note_id) key ensures a contribution can only earn points once.
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
$theme = (string)($user['theme'] ?? 'system');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Notes Approval — ULearn Admin</title>
<script>(function(){let t=<?= json_encode($theme, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;if(t==='system')t=matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';document.documentElement.dataset.theme=t;try{localStorage.setItem('ulearn-theme',t)}catch(e){}})();</script>
<style>
:root{color-scheme:light;--bg:#f7f8fc;--surface:#fff;--text:#171927;--muted:#777b90;--border:#e7e8f0;--accent:#6756e8;--soft:#f0edff;--danger:#a9274c}
:root[data-theme=dark]{color-scheme:dark;--bg:#0c0e16;--surface:#161924;--text:#f4f4fa;--muted:#a0a4b6;--border:#2b2e3c;--accent:#a99aff;--soft:#292440;--danger:#ff91ad}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif;line-height:1.55}a{color:inherit;text-decoration:none}.layout{display:grid;grid-template-columns:245px minmax(0,1fr);min-height:100vh}.sidebar{padding:30px 20px;background:var(--surface);border-right:1px solid var(--border)}.brand{font-size:27px;font-weight:850;letter-spacing:-1.5px;margin:0 12px 34px;display:block}.brand span{color:var(--accent)}.nav{display:grid;gap:6px}.nav a{display:block;padding:11px 14px;border-radius:11px;font-size:14px;font-weight:650}.nav a:hover{background:var(--soft)}.nav a.active{background:var(--soft);color:var(--accent)}.main{padding:35px clamp(18px,5vw,72px) 80px;min-width:0}.eyebrow{font-size:11px;font-weight:800;letter-spacing:1.6px;text-transform:uppercase;color:var(--muted)}h1{font-size:clamp(31px,4vw,48px);letter-spacing:-1.5px;margin:13px 0 6px}p{margin:0 0 15px}.muted{color:var(--muted)}.tabs{display:flex;gap:9px;flex-wrap:wrap;margin:28px 0}.tab,.button{display:inline-flex;align-items:center;justify-content:center;padding:10px 15px;border:1px solid var(--border);border-radius:11px;background:var(--surface);font:inherit;font-size:13px;font-weight:750;cursor:pointer}.tab.active,.button.primary{background:var(--accent);color:white;border-color:var(--accent)}.button.danger{color:var(--danger)}.button:hover,.tab:hover{border-color:var(--accent)}.note{background:var(--surface);border:1px solid var(--border);border-radius:18px;padding:24px;margin-bottom:15px}.note-top{display:flex;align-items:flex-start;justify-content:space-between;gap:15px}.note h2{font-size:20px;letter-spacing:-.5px;margin:8px 0}.pill{font-size:12px;font-weight:800;color:var(--accent);background:var(--soft);border-radius:99px;padding:6px 11px;white-space:nowrap}.meta{font-size:12px;color:var(--muted);overflow-wrap:anywhere}.actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:17px}.review{border-top:1px solid var(--border);margin-top:18px;padding-top:18px}.review form{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end}.review .review-confirm{display:flex;align-items:center;gap:7px;min-width:0;flex:0 0 auto}.review label{font-size:13px;font-weight:700;display:grid;gap:6px;flex:1;min-width:220px}.review textarea{font:inherit;width:100%;border:1px solid var(--border);border-radius:10px;padding:10px;color:var(--text);background:var(--bg);resize:vertical}.message{padding:13px 16px;border-radius:11px;background:var(--soft);color:var(--accent);margin:15px 0}.message.error{background:#ffedf0;color:#a32143}.empty{background:var(--surface);padding:30px;border:1px solid var(--border);border-radius:16px}.preview{width:100%;height:540px;border:1px solid var(--border);border-radius:12px;margin-top:15px;background:white}.preview-panel{margin-top:12px}.preview-panel summary{cursor:pointer;font-size:13px;font-weight:750;color:var(--accent)}@media(max-width:850px){.layout{grid-template-columns:1fr}.sidebar{border-right:0;border-bottom:1px solid var(--border);padding:18px}.brand{margin:0 0 12px}.nav{display:flex;flex-wrap:wrap}.main{padding:25px 17px 55px}}@media(max-width:600px){.preview{height:360px}.note{padding:18px}}
</style>
</head>
<body><div class="layout">
<aside class="sidebar"><a class="brand" href="<?= e(url('admin/index.php')) ?>">u learn<span>.</span></a><nav class="nav" aria-label="Admin navigation">
<a href="<?= e(url('admin/index.php')) ?>">Overview</a>
<a href="<?= e(url('admin/lecturers.php')) ?>">Lecturers</a>
<a href="<?= e(url('admin/academics.php')) ?>">Academics</a>
<a href="<?= e(url('admin/quizzes.php')) ?>">Quizzes</a>
<a href="<?= e(url('admin/assignments.php')) ?>">Assignments</a>
<a href="<?= e(url('admin/announcements.php')) ?>">Announcements</a>
<a class="active" href="<?= e(url('admin/notes.php')) ?>">Notes Approval<?= $counts['pending'] ? ' (' . $counts['pending'] . ')' : '' ?></a>
<a href="<?= e(url('admin/profile.php')) ?>">My Profile &amp; Settings</a>
</nav></aside>
<main class="main"><div class="eyebrow">ULearn / Administration</div><h1>Notes approval<span style="color:var(--accent)">.</span></h1>
<p class="muted">Open and inspect each document before approving or rejecting it. PDF previews open directly; DOCX and PPTX previews require LibreOffice installed on the server. Approving an eligible student note awards 10 points once.</p>
<?php if ($error !== ''): ?><div class="message error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if ($notice !== ''): ?><div class="message" role="status"><?= e($notice) ?></div><?php endif; ?>
<div class="tabs" aria-label="Filter notes">
<?php foreach (['pending'=>'Pending','approved'=>'Approved','rejected'=>'Rejected','all'=>'All notes'] as $key=>$label): ?>
<a class="tab<?= $filter === $key ? ' active' : '' ?>" href="<?= e(url('admin/notes.php?status=' . $key)) ?>"><?= e($label) ?><?= $key === 'all' ? '' : ' (' . ($counts[$key] ?? 0) . ')' ?></a>
<?php endforeach; ?></div>
<?php if (!$notes): ?><div class="empty"><strong>No <?= e($filter === 'all' ? '' : $filter) ?> notes found.</strong><p class="muted">Student submissions will appear here when available.</p></div><?php endif; ?>
<?php foreach ($notes as $note): ?>
<article class="note"><div class="note-top"><div><div class="eyebrow"><?= e((string)$note['module_code']) ?> · <?= e((string)$note['module_name']) ?></div><h2><?= e((string)$note['title']) ?></h2></div><span class="pill"><?= e(ucfirst((string)$note['status'])) ?></span></div>
<?php if ((string)$note['description'] !== ''): ?><p><?= nl2br(e((string)$note['description'])) ?></p><?php endif; ?>
<div class="meta">Uploaded by <strong><?= e((string)$note['author_name']) ?></strong> (<?= e(ucwords(str_replace('_',' ',(string)$note['author_role']))) ?>) · <?= e((string)$note['original_filename']) ?> · <?= number_format((int)$note['file_size']/1048576,2) ?> MB · <?= e(date('M j, Y, g:i a', strtotime((string)$note['created_at']))) ?></div>
<div class="actions"><a class="button" href="<?= e(url('admin/notes.php?id=' . (int)$note['id'] . '&file=download')) ?>">↓ Download original</a></div>
<?php $ext = strtolower(pathinfo((string)$note['original_filename'], PATHINFO_EXTENSION)); $canPreview = in_array($ext, ['pdf', 'docx', 'pptx'], true); ?>
<?php if ($canPreview): ?><details class="preview-panel"><summary>View note before reviewing (PDF / DOCX / PPTX)</summary><iframe class="preview" title="Preview of <?= e((string)$note['title']) ?>" loading="lazy" src="<?= e(url('admin/notes.php?id=' . (int)$note['id'] . '&file=preview')) ?>"></iframe></details>
<?php else: ?><p class="muted" style="font-size:12px;margin-top:12px">Preview is unavailable for this file type.</p><?php endif; ?>
<?php if ((string)$note['status'] === 'pending'): ?><div class="review">
<form method="post" action="<?= e(url('admin/notes.php?status=pending')) ?>" onsubmit="return confirm('Have you reviewed the document? Approve and publish this note? This will award 10 points if uploaded by a student.');">
<input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="note_id" value="<?= (int)$note['id'] ?>"><input type="hidden" name="action" value="approve"><label class="review-confirm"><input type="checkbox" name="inspected" value="1" required> I inspected this document</label><button class="button primary" type="submit">✓ Approve &amp; publish (+10 points)</button></form>
<form method="post" action="<?= e(url('admin/notes.php?status=pending')) ?>" style="margin-top:12px">
<input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="note_id" value="<?= (int)$note['id'] ?>"><input type="hidden" name="action" value="reject"><label>Reason for rejection<textarea name="reason" rows="2" minlength="3" maxlength="500" required placeholder="Explain what needs to be corrected..."></textarea></label><label class="review-confirm"><input type="checkbox" name="inspected" value="1" required> I inspected this document</label><button class="button danger" type="submit" onclick="return confirm('Have you reviewed this document and confirmed the rejection reason?')">Reject note</button></form>
</div><?php else: ?><p class="meta" style="margin-top:15px">Reviewed<?= $note['reviewer_name'] ? ' by ' . e((string)$note['reviewer_name']) : '' ?><?= $note['reviewed_at'] ? ' on ' . e(date('M j, Y', strtotime((string)$note['reviewed_at']))) : '' ?><?php if ((string)$note['status'] === 'rejected' && $note['rejection_reason']): ?> · Reason: <?= e((string)$note['rejection_reason']) ?><?php endif; ?></p><?php endif; ?>
</article>
<?php endforeach; ?>
<p class="muted" style="font-size:12px;margin-top:24px">Showing up to 200 notes. Only administrators can access this review page.</p>
</main></div></body></html>
