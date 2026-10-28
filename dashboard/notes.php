<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
$user = require_login();
if ((string)$user['role'] !== 'student') { http_response_code(403); exit('Student access only.'); }
$pdo = db();
$userId = (int)$user['id'];
$error = '';
$success = '';
$selectedModule = filter_input(INPUT_GET, 'module_id', FILTER_VALIDATE_INT) ?: 0;
$maxBytes = 20 * 1024 * 1024;
$allowed = [
    'pdf' => ['application/pdf'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
    'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
];

try {
    $modulesStmt = $pdo->prepare('SELECT m.id, m.module_code, m.module_name FROM student_module_enrollments e JOIN academic_modules m ON m.id = e.module_id WHERE e.user_id = ? ORDER BY m.module_name');
    $modulesStmt->execute([$userId]);
    $modules = $modulesStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $ex) {
    error_log('ULearn notes modules error: ' . $ex->getMessage());
    http_response_code(500);
    exit('Unable to load your enrolled modules.');
}
$moduleIds = array_map(static fn(array $m): int => (int)$m['id'], $modules);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $title = trim((string)($_POST['title'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $moduleId = filter_var($_POST['module_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
    $selectedModule = $moduleId;
    $upload = $_FILES['note_file'] ?? null;
    if (!in_array($moduleId, $moduleIds, true)) {
        $error = 'Select a module you are enrolled in.';
    } elseif (mb_strlen($title) < 3 || mb_strlen($title) > 180) {
        $error = 'The title must contain between 3 and 180 characters.';
    } elseif (mb_strlen($description) > 3000) {
        $error = 'The description must be 3,000 characters or fewer.';
    } elseif (!is_array($upload) || is_array($upload['error'] ?? null) || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $error = 'Choose a valid PDF, DOCX or PPTX file (up to 20 MB).';
    } else {
        $original = (string)($upload['name'] ?? '');
        $original = basename(str_replace('\\', '/', $original));
        $original = trim((string)preg_replace('/[\x00-\x1F\x7F]/', '', $original));
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $tmp = (string)($upload['tmp_name'] ?? '');
        $size = (int)($upload['size'] ?? 0);
        if ($original === '' || strlen($original) > 255 || !isset($allowed[$extension])) {
            $error = 'Only PDF, DOCX and PPTX files are supported.';
        } elseif ($size < 1 || $size > $maxBytes || !is_uploaded_file($tmp)) {
            $error = 'The file must be between 1 byte and 20 MB.';
        } else {
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
            if (!in_array($mime, $allowed[$extension], true)) {
                $error = 'The selected file does not match its file type.';
            } elseif ($extension === 'pdf' && file_get_contents($tmp, false, null, 0, 5) !== '%PDF-') {
                $error = 'The PDF file is invalid.';
            } elseif ($extension !== 'pdf') {
                if (!class_exists('ZipArchive')) {
                    $error = 'ZIP support is required to validate Office files. Please enable the PHP zip extension.';
                } else {
                    $zip = new ZipArchive();
                    if ($zip->open($tmp) !== true) {
                        $error = 'The Office document is invalid.';
                    } else {
                        $required = $extension === 'docx' ? 'word/document.xml' : 'ppt/presentation.xml';
                        if ($zip->locateName($required) === false || $zip->locateName('[Content_Types].xml') === false) {
                            $error = 'This file is not a valid ' . strtoupper($extension) . ' document.';
                        }
                        $zip->close();
                    }
                }
            }
            if ($error === '') {
                // Serialize uploads for this student/module so rapid repeat submissions cannot race.
                $lockDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ulearn_note_upload_locks';
                if (!is_dir($lockDir) && !@mkdir($lockDir, 0700, true) && !is_dir($lockDir)) {
                    $error = 'Upload protection is temporarily unavailable. Please try again.';
                } else {
                    $lockPath = $lockDir . DIRECTORY_SEPARATOR . hash('sha256', $userId . ':' . $moduleId) . '.lock';
                    $lockHandle = @fopen($lockPath, 'c');
                    if (!$lockHandle || !flock($lockHandle, LOCK_EX)) {
                        if ($lockHandle) fclose($lockHandle);
                        $error = 'Unable to process the upload right now. Please try again.';
                    } else {
                        try {
                            $incomingHash = hash_file('sha256', $tmp);
                            if ($incomingHash === false) throw new RuntimeException('Cannot hash uploaded document.');
                            $storage = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'ulearn_private' . DIRECTORY_SEPARATOR . 'notes';
                            // Only active submissions block duplicates; rejected work may be revised and resubmitted.
                            $existingStmt = $pdo->prepare("SELECT id, stored_filename, status FROM student_notes WHERE uploaded_by = ? AND module_id = ? AND status IN ('pending', 'approved') ORDER BY id DESC");
                            $existingStmt->execute([$userId, $moduleId]);
                            foreach ($existingStmt->fetchAll(PDO::FETCH_ASSOC) as $existing) {
                                $existingName = (string)$existing['stored_filename'];
                                if ($existingName === '' || basename($existingName) !== $existingName) continue;
                                $existingPath = $storage . DIRECTORY_SEPARATOR . $existingName;
                                if (is_file($existingPath) && is_readable($existingPath) && hash_file('sha256', $existingPath) === $incomingHash) {
                                    $error = 'You already submitted this exact document for this module (status: ' . (string)$existing['status'] . '). Please check Your uploaded notes below.';
                                    break;
                                }
                            }
                            if ($error === '') {
                                if (!is_dir($storage) && !mkdir($storage, 0700, true) && !is_dir($storage)) {
                                    $error = 'Unable to prepare private note storage.';
                                } else {
                                    $storedName = bin2hex(random_bytes(20)) . '.' . $extension;
                                    $target = $storage . DIRECTORY_SEPARATOR . $storedName;
                                    if (!move_uploaded_file($tmp, $target)) {
                                        $error = 'Unable to save your file. Please try again.';
                                    } else {
                                        @chmod($target, 0600);
                                        try {
                                            $stmt = $pdo->prepare("INSERT INTO student_notes (module_id, uploaded_by, title, description, original_filename, stored_filename, mime_type, file_size, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
                                            $stmt->execute([$moduleId, $userId, $title, $description !== '' ? $description : null, $original, $storedName, $mime, $size]);
                                            $_SESSION['ulearn_notes_upload_success'] = 'Your note was submitted successfully. An administrator must approve it before publication. Points are awarded only after approval.';
                                            go('dashboard/notes.php?module_id=' . $moduleId);
                                        } catch (Throwable $ex) {
                                            @unlink($target);
                                            error_log('ULearn notes insert error: ' . $ex->getMessage());
                                            $error = 'Could not save your note details. Please try again.';
                                        }
                                    }
                                }
                            }
                        } catch (Throwable $ex) {
                            error_log('ULearn notes duplicate protection error: ' . $ex->getMessage());
                            $error = 'Could not check this upload safely. Please try again.';
                        } finally {
                            flock($lockHandle, LOCK_UN);
                            fclose($lockHandle);
                        }
                    }
                }
            }
        }
    }
}
if (isset($_SESSION['ulearn_notes_upload_success'])) {
    $success = (string)$_SESSION['ulearn_notes_upload_success'];
    unset($_SESSION['ulearn_notes_upload_success']);
}
try {
    $stmt = $pdo->prepare('SELECT n.id, n.module_id, n.title, n.description, n.original_filename, n.file_size, n.status, n.rejection_reason, n.created_at, m.module_code, m.module_name FROM student_notes n JOIN academic_modules m ON m.id = n.module_id WHERE n.uploaded_by = ? ORDER BY n.created_at DESC, n.id DESC LIMIT 100');
    $stmt->execute([$userId]);
    $myNotes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $pointsStmt = $pdo->prepare('SELECT COALESCE(SUM(points),0) FROM student_note_points WHERE user_id = ?');
    $pointsStmt->execute([$userId]);
    $points = (int)$pointsStmt->fetchColumn();
} catch (Throwable $ex) {
    error_log('ULearn notes list error: ' . $ex->getMessage());
    $myNotes = [];
    $points = 0;
    $error = $error ?: 'Could not load your uploaded notes.';
}
$theme = (string)($user['theme'] ?? 'system');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>My Notes — ULearn</title>
<script>(function(){const pref=<?= json_encode($theme, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;const t=pref==='dark'||pref==='light'?pref:(matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light');document.documentElement.dataset.theme=t;try{localStorage.setItem('ulearn-theme',t)}catch(e){}})();</script>
<style>
:root{color-scheme:light;--bg:#f7f8fc;--surface:#fff;--text:#171927;--muted:#777b90;--border:#e7e8f0;--accent:#6756e8;--soft:#f0edff;--input:#fff}
:root[data-theme="dark"]{color-scheme:dark;--bg:#0c0e16;--surface:#161924;--text:#f4f4fa;--muted:#a0a4b6;--border:#2b2e3c;--accent:#a99aff;--soft:#292440;--input:#202431}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;line-height:1.6}a{color:inherit;text-decoration:none}.wrap{max-width:1050px;margin:auto;padding:32px 22px 80px}.top{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:32px}.eyebrow{font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);font-weight:800}.button,button{display:inline-flex;justify-content:center;align-items:center;border:1px solid var(--border);border-radius:11px;background:var(--surface);color:var(--text);padding:11px 16px;font:700 13px inherit;cursor:pointer}.button.primary,button.primary{background:var(--accent);color:#fff;border-color:var(--accent)}.button:hover,button:hover{border-color:var(--accent)}h1{font-size:clamp(30px,4vw,46px);letter-spacing:-.04em;margin:10px 0}h2{font-size:22px;letter-spacing:-.025em}.muted{color:var(--muted)}.hero,.panel,.note{background:var(--surface);border:1px solid var(--border);border-radius:19px;padding:25px}.hero{margin-bottom:20px}.grid{display:grid;grid-template-columns:1.15fr .85fr;gap:18px}.field{margin-bottom:18px}label{display:block;font-weight:700;font-size:13px;margin-bottom:7px}input,select,textarea{width:100%;padding:12px 13px;border:1px solid var(--border);border-radius:11px;background:var(--input);color:var(--text);font:inherit}textarea{min-height:105px;resize:vertical}.help{font-size:12px;color:var(--muted);margin-top:6px}.notice{padding:13px 16px;border-radius:12px;margin-bottom:18px}.notice.error{background:#ffedf0;color:#9d2440}.notice.success{background:#e9f9ef;color:#166b3b}.stat{font-size:38px;font-weight:800;color:var(--accent);line-height:1.1}.notes{display:grid;gap:12px}.note{display:flex;align-items:flex-start;justify-content:space-between;gap:18px}.note h3{font-size:17px;margin:5px 0}.note p{margin:0 0 8px}.badge{display:inline-block;padding:6px 10px;border-radius:99px;background:var(--soft);color:var(--accent);font-size:12px;font-weight:800;text-transform:capitalize}.status-rejected{color:#a32143;background:#ffedf0}.status-approved{color:#166b3b;background:#e9f9ef}.section{margin-top:35px}.small{font-size:12px}.file-meta{overflow-wrap:anywhere}@media(max-width:760px){.grid{grid-template-columns:1fr}.wrap{padding:20px 15px 55px}.note{flex-direction:column}.hero,.panel,.note{padding:20px}}
</style>
</head>
<body><main class="wrap">
<div class="top"><div><div class="eyebrow">ULearn / Student workspace</div><strong>Notes Hub</strong></div><div><a class="button" href="<?= e(url('dashboard/index.php')) ?>">← Dashboard</a> <a class="button" href="<?= e(url('dashboard/profile.php')) ?>">My Profile</a></div></div>
<section class="hero"><div class="eyebrow">Contribute & learn together</div><h1>My Notes<span style="color:var(--accent)">.</span></h1><p class="muted">Share useful study resources with your university community. Every student upload is reviewed by an administrator before it becomes public.</p><span class="badge">+10 points for each approved note</span></section>
<?php if ($error !== ''): ?><div class="notice error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if ($success !== ''): ?><div class="notice success" role="status"><?= e($success) ?></div><?php endif; ?>
<div class="grid"><section class="panel"><h2>Upload a note</h2><p class="muted small">PDF, Word DOCX or PowerPoint PPTX · Maximum 20 MB</p>
<?php if (!$modules): ?><p class="muted">You must enroll in an academic module before uploading notes. <a href="<?= e(url('dashboard/explore.php')) ?>" style="color:var(--accent)">Explore modules →</a></p><?php else: ?>
<form method="post" enctype="multipart/form-data" action="<?= e(url('dashboard/notes.php')) ?>">
<input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
<div class="field"><label for="module_id">Academic module</label><select id="module_id" name="module_id" required><option value="">Select your enrolled module</option><?php foreach ($modules as $m): ?><option value="<?= (int)$m['id'] ?>" <?= $selectedModule === (int)$m['id'] ? 'selected' : '' ?>><?= e((string)$m['module_code'] . ' — ' . (string)$m['module_name']) ?></option><?php endforeach; ?></select></div>
<div class="field"><label for="title">Note title</label><input id="title" name="title" maxlength="180" minlength="3" required placeholder="e.g. Python Functions — Revision Notes" value="<?= e((string)($_POST['title'] ?? '')) ?>"></div>
<div class="field"><label for="description">Description (optional)</label><textarea id="description" name="description" maxlength="3000" placeholder="What topics does this note cover?"><?= e((string)($_POST['description'] ?? '')) ?></textarea></div>
<div class="field"><label for="note_file">Choose document</label><input id="note_file" type="file" name="note_file" accept=".pdf,.docx,.pptx" required><div class="help">Only upload content you have permission to share. Your name will appear when the note is published.</div></div>
<button class="primary" type="submit" id="submitNote">Submit for admin approval →</button></form><?php endif; ?></section>
<aside class="panel"><div class="eyebrow">Contribution rewards</div><h2>Your points</h2><div class="stat"><?= number_format($points) ?></div><p class="muted small">Points are earned after an administrator approves a student-uploaded note, not when you submit it.</p><hr style="border:0;border-top:1px solid var(--border);margin:25px 0"><h2>What happens next?</h2><p class="muted small">1. Submit your note.<br>2. An administrator reviews it.<br>3. Approved notes become visible in the public Notes Hub.<br>4. Other students can preview, download and rate them.</p><p class="muted small">Public browsing, document previews, ratings and the admin review queue will be added in the next steps.</p></aside></div>
<section class="section"><h2>Your uploaded notes</h2><?php if (!$myNotes): ?><div class="panel"><strong>No uploads yet</strong><p class="muted">Your submitted notes and their approval status will appear here.</p></div><?php else: ?><div class="notes"><?php foreach ($myNotes as $n): ?><article class="note"><div><div class="eyebrow"><?= e((string)$n['module_code']) ?> · <?= e((string)$n['module_name']) ?></div><h3><?= e((string)$n['title']) ?></h3><?php if (!empty($n['description'])): ?><p class="muted small"><?= nl2br(e((string)$n['description'])) ?></p><?php endif; ?><p class="muted small file-meta"><?= e((string)$n['original_filename']) ?> · <?= number_format((int)$n['file_size']/1048576,2) ?> MB · <?= e(date('M j, Y',strtotime((string)$n['created_at']))) ?></p><?php if ((string)$n['status']==='rejected' && !empty($n['rejection_reason'])): ?><p class="small" style="color:#d34c68">Reason: <?= e((string)$n['rejection_reason']) ?></p><?php endif; ?></div><span class="badge status-<?= e((string)$n['status']) ?>"><?= e((string)$n['status']) ?></span></article><?php endforeach; ?></div><?php endif; ?></section>
</main><script>
// Prevent accidental double clicks while the browser is sending the upload.
const uploadForm = document.querySelector('form[enctype="multipart/form-data"]');
if (uploadForm) uploadForm.addEventListener('submit', function () {
    if (!uploadForm.checkValidity()) return;
    const button = document.getElementById('submitNote');
    if (button) { button.disabled = true; button.textContent = 'Submitting…'; }
});
</script></body></html>
