<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$user = require_login();

if ((string)$user['role'] !== 'student') {
    http_response_code(403);
    exit('Student access only.');
}

$pdo = db();
$userId = (int)$user['id'];

$error = '';
$success = '';
$selectedModule = filter_input(INPUT_GET, 'module_id', FILTER_VALIDATE_INT) ?: 0;

$maxBytes = 20 * 1024 * 1024;

$storage = dirname(__DIR__, 3)
    . DIRECTORY_SEPARATOR . 'ulearn_private'
    . DIRECTORY_SEPARATOR . 'notes';

$allowed = [
    'pdf' => ['application/pdf'],
    'docx' => [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/zip',
        'application/octet-stream',
        'binary/octet-stream'
    ],
    'pptx' => [
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/zip',
        'application/octet-stream',
        'binary/octet-stream'
    ]
];

/* Load enrolled modules */
try {
    $stmt = $pdo->prepare(
        'SELECT m.id, m.module_code, m.module_name
         FROM student_module_enrollments e
         JOIN academic_modules m ON m.id = e.module_id
         WHERE e.user_id = ?
         ORDER BY m.module_name'
    );

    $stmt->execute([$userId]);
    $modules = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $ex) {
    error_log('Notes modules error: ' . $ex->getMessage());
    http_response_code(500);
    exit('Unable to load your enrolled modules.');
}

$moduleIds = array_map(
    static fn(array $m): int => (int)$m['id'],
    $modules
);

/* POST requests */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_check();

    $action = (string)($_POST['action'] ?? 'upload');

    /* Delete pending note */
    if ($action === 'delete') {

        $noteId = filter_var(
            $_POST['note_id'] ?? null,
            FILTER_VALIDATE_INT
        ) ?: 0;

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                "SELECT stored_filename
                 FROM student_notes
                 WHERE id = ?
                 AND uploaded_by = ?
                 AND status = 'pending'
                 FOR UPDATE"
            );

            $stmt->execute([$noteId, $userId]);
            $note = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$note) {
                $pdo->rollBack();
                $error = 'Pending note not found or already reviewed.';
            } else {

                $delete = $pdo->prepare(
                    "DELETE FROM student_notes
                     WHERE id = ?
                     AND uploaded_by = ?
                     AND status = 'pending'"
                );

                $delete->execute([$noteId, $userId]);

                if ($delete->rowCount() === 1) {

                    $pdo->commit();

                    $storedName = (string)$note['stored_filename'];

                    if (
                        $storedName !== ''
                        && basename($storedName) === $storedName
                    ) {
                        $path = $storage . DIRECTORY_SEPARATOR . $storedName;

                        if (is_file($path)) {
                            @unlink($path);
                        }
                    }

                    $_SESSION['ulearn_notes_upload_success'] =
                        'Pending note successfully deleted.';

                    go('dashboard/notes.php' . (
                        $selectedModule
                            ? '?module_id=' . $selectedModule
                            : ''
                    ));

                } else {
                    $pdo->rollBack();
                    $error = 'Unable to delete this note.';
                }
            }

        } catch (Throwable $ex) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Notes delete error: ' . $ex->getMessage());
            $error = 'Could not delete the note. Please try again.';
        }

    } elseif ($action === 'upload') {

        $title = trim((string)($_POST['title'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));

        $moduleId = filter_var(
            $_POST['module_id'] ?? null,
            FILTER_VALIDATE_INT
        ) ?: 0;

        $selectedModule = $moduleId;
        $upload = $_FILES['note_file'] ?? null;

        if (!in_array($moduleId, $moduleIds, true)) {

            $error = 'Select a module you are enrolled in.';

        } elseif (
            mb_strlen($title) < 3 ||
            mb_strlen($title) > 180
        ) {

            $error = 'Title must contain 3 to 180 characters.';

        } elseif (mb_strlen($description) > 3000) {

            $error = 'Description must be 3000 characters or fewer.';

        } elseif (
            !is_array($upload) ||
            is_array($upload['error'] ?? null) ||
            (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        ) {

            $uploadError = is_array($upload)
                ? (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE)
                : UPLOAD_ERR_NO_FILE;

            $error = in_array(
                $uploadError,
                [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE],
                true
            )
                ? 'File exceeds the server upload limit.'
                : 'Choose a valid PDF, DOCX or PPTX file.';

        } else {

            $original = basename(
                str_replace('\\', '/', (string)$upload['name'])
            );

            $original = trim(
                (string)preg_replace(
                    '/[\x00-\x1F\x7F]/',
                    '',
                    $original
                )
            );

            $extension = strtolower(
                pathinfo($original, PATHINFO_EXTENSION)
            );

            $tmp = (string)$upload['tmp_name'];
            $size = (int)$upload['size'];

            if (
                $original === '' ||
                strlen($original) > 255 ||
                !isset($allowed[$extension])
            ) {

                $error = 'Only PDF, DOCX and PPTX files are supported.';

            } elseif (
                $size < 1 ||
                $size > $maxBytes ||
                !is_uploaded_file($tmp)
            ) {

                $error = 'File must be between 1 byte and 20 MB.';

            } else {

                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';

                /* Validate PDF */
                if ($extension === 'pdf') {

                    if (
                        $mime !== 'application/pdf' ||
                        file_get_contents($tmp, false, null, 0, 5) !== '%PDF-'
                    ) {
                        $error = 'Invalid PDF document.';
                    }

                } else {

                    /* Validate DOCX / PPTX */
                    if (!class_exists('ZipArchive')) {

                        $error = 'Enable PHP ZipArchive extension in XAMPP.';

                    } else {

                        $archive = new ZipArchive();

                        if (
                            $archive->open(
                                $tmp,
                                ZipArchive::CHECKCONS
                            ) !== true
                        ) {

                            $error = 'Invalid or damaged Office document.';

                        } else {

                            $requiredPart = $extension === 'docx'
                                ? 'word/document.xml'
                                : 'ppt/presentation.xml';

                            if (
                                $archive->locateName('[Content_Types].xml') === false ||
                                $archive->locateName($requiredPart) === false
                            ) {
                                $error = 'Invalid ' . strtoupper($extension) . ' document.';
                            }

                            $archive->close();
                        }
                    }

                    if ($error === '') {
                        $mime = $extension === 'docx'
                            ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                            : 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
                    }
                }

                /* Save upload */
                if ($error === '') {

                    $lockDir = sys_get_temp_dir()
                        . DIRECTORY_SEPARATOR . 'ulearn_note_upload_locks';

                    if (
                        !is_dir($lockDir) &&
                        !@mkdir($lockDir, 0700, true) &&
                        !is_dir($lockDir)
                    ) {

                        $error = 'Upload protection unavailable.';

                    } else {

                        $lockPath = $lockDir . DIRECTORY_SEPARATOR
                            . hash('sha256', $userId . ':' . $moduleId)
                            . '.lock';

                        $lockHandle = @fopen($lockPath, 'c');

                        if (!$lockHandle || !flock($lockHandle, LOCK_EX)) {

                            if ($lockHandle) {
                                fclose($lockHandle);
                            }

                            $error = 'Unable to process upload.';

                        } else {

                            try {

                                $incomingHash = hash_file('sha256', $tmp);

                                if ($incomingHash === false) {
                                    throw new RuntimeException('Hash failed.');
                                }

                                $existingStmt = $pdo->prepare(
                                    "SELECT id, stored_filename, status
                                     FROM student_notes
                                     WHERE uploaded_by = ?
                                     AND module_id = ?
                                     AND status IN ('pending', 'approved')
                                     ORDER BY id DESC"
                                );

                                $existingStmt->execute([
                                    $userId,
                                    $moduleId
                                ]);

                                foreach (
                                    $existingStmt->fetchAll(PDO::FETCH_ASSOC)
                                    as $existing
                                ) {

                                    $name = (string)$existing['stored_filename'];

                                    if (
                                        $name === '' ||
                                        basename($name) !== $name
                                    ) {
                                        continue;
                                    }

                                    $existingPath = $storage
                                        . DIRECTORY_SEPARATOR . $name;

                                    if (
                                        is_file($existingPath) &&
                                        is_readable($existingPath) &&
                                        hash_file('sha256', $existingPath) === $incomingHash
                                    ) {

                                        $error =
                                            'You already submitted this document. Status: '
                                            . $existing['status'];

                                        break;
                                    }
                                }

                                if ($error === '') {

                                    if (
                                        !is_dir($storage) &&
                                        !mkdir($storage, 0700, true) &&
                                        !is_dir($storage)
                                    ) {

                                        $error = 'Unable to prepare private storage.';

                                    } else {

                                        $storedName = bin2hex(random_bytes(20))
                                            . '.' . $extension;

                                        $target = $storage
                                            . DIRECTORY_SEPARATOR . $storedName;

                                        if (!move_uploaded_file($tmp, $target)) {

                                            $error = 'Unable to save uploaded file.';

                                        } else {

                                            @chmod($target, 0600);

                                            try {

                                                $stmt = $pdo->prepare(
                                                    "INSERT INTO student_notes
                                                    (
                                                        module_id,
                                                        uploaded_by,
                                                        title,
                                                        description,
                                                        original_filename,
                                                        stored_filename,
                                                        mime_type,
                                                        file_size,
                                                        status
                                                    )
                                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
                                                );

                                                $stmt->execute([
                                                    $moduleId,
                                                    $userId,
                                                    $title,
                                                    $description !== '' ? $description : null,
                                                    $original,
                                                    $storedName,
                                                    $mime,
                                                    $size
                                                ]);

                                                $_SESSION['ulearn_notes_upload_success'] =
                                                    'Your note was submitted successfully. Admin approval is required before publication.';

                                                go(
                                                    'dashboard/notes.php?module_id='
                                                    . $moduleId
                                                );

                                            } catch (Throwable $ex) {

                                                @unlink($target);

                                                error_log(
                                                    'Notes insert error: '
                                                    . $ex->getMessage()
                                                );

                                                $error = 'Could not save note details.';
                                            }
                                        }
                                    }
                                }

                            } catch (Throwable $ex) {

                                error_log(
                                    'Notes duplicate check error: '
                                    . $ex->getMessage()
                                );

                                $error = 'Could not verify this upload.';

                            } finally {

                                flock($lockHandle, LOCK_UN);
                                fclose($lockHandle);
                            }
                        }
                    }
                }
            }
        }

    } else {

        $error = 'Invalid request. Please refresh the page.';
    }
}

/* Flash message */
if (isset($_SESSION['ulearn_notes_upload_success'])) {

    $success = (string)$_SESSION['ulearn_notes_upload_success'];

    unset($_SESSION['ulearn_notes_upload_success']);
}

/* Load uploaded notes */
try {

    $stmt = $pdo->prepare(
        'SELECT
            n.id,
            n.module_id,
            n.title,
            n.description,
            n.original_filename,
            n.file_size,
            n.status,
            n.rejection_reason,
            n.created_at,
            m.module_code,
            m.module_name
         FROM student_notes n
         JOIN academic_modules m ON m.id = n.module_id
         WHERE n.uploaded_by = ?
         ORDER BY n.created_at DESC, n.id DESC
         LIMIT 100'
    );

    $stmt->execute([$userId]);

    $myNotes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $pointsStmt = $pdo->prepare(
        'SELECT COALESCE(SUM(points), 0)
         FROM student_note_points
         WHERE user_id = ?'
    );

    $pointsStmt->execute([$userId]);

    $points = (int)$pointsStmt->fetchColumn();

} catch (Throwable $ex) {

    error_log('Notes list error: ' . $ex->getMessage());

    $myNotes = [];
    $points = 0;

    $error = $error ?: 'Could not load uploaded notes.';
}

$approvedCount = count(array_filter(
    $myNotes,
    static fn($n) => $n['status'] === 'approved'
));

$pendingCount = count(array_filter(
    $myNotes,
    static fn($n) => $n['status'] === 'pending'
));

?>
<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>My Notes — ULearn</title>

<style>
:root {
    --bg:#f5f8f7;
    --surface:#fff;
    --text:#10233a;
    --muted:#627386;
    --border:#dce7e5;
    --accent:#087b57;
    --soft:#eaf6f1;
    --input:#fff;
}

:root[data-theme="light"] {
    color-scheme: light;
}

:root[data-theme="dark"] {
    color-scheme: dark;
    --bg:#0b1522;
    --surface:#142235;
    --text:#f1f7fa;
    --muted:#9eafbf;
    --border:#293d50;
    --accent:#43d5a1;
    --soft:#173b37;
    --input:#1b2b40;
}

* { box-sizing:border-box; }

body {
    margin:0;
    background:var(--bg);
    color:var(--text);
    font-family:Inter,system-ui,-apple-system,'Segoe UI',sans-serif;
    line-height:1.6;
}

a { color:inherit; text-decoration:none; }

.wrap {
    max-width:1200px;
    margin:auto;
    padding:30px 22px 80px;
}

.top {
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:14px;
    flex-wrap:wrap;
    padding-bottom:22px;
    margin-bottom:30px;
    border-bottom:1px solid var(--border);
}

.ul-brand {
    display:flex;
    align-items:center;
    gap:10px;
    font-size:25px;
}

.ul-brand img {
    object-fit:contain;
}

.ul-brand i {
    color:var(--accent);
}

.ul-actions {
    display:flex;
    gap:9px;
    flex-wrap:wrap;
}

.button,button {
    display:inline-flex;
    justify-content:center;
    align-items:center;
    min-height:42px;
    padding:10px 15px;
    border:1px solid var(--border);
    border-radius:11px;
    background:var(--surface);
    color:var(--text);
    font:inherit;
    font-size:13px;
    font-weight:700;
    cursor:pointer;
}

.button:hover,button:hover {
    border-color:var(--accent);
}

.primary {
    background:var(--accent);
    color:#fff;
    border-color:var(--accent);
}

h1 {
    font-size:clamp(30px,4vw,46px);
    letter-spacing:-.04em;
    margin:10px 0;
}

h2 {
    font-size:22px;
    letter-spacing:-.035em;
}

.muted { color:var(--muted); }

.eyebrow,.ul-section-kicker {
    font-size:12px;
    letter-spacing:.12em;
    font-weight:800;
    color:var(--muted);
}

.hero,.panel,.note {
    background:var(--surface);
    border:1px solid var(--border);
    border-radius:20px;
    padding:24px;
    box-shadow:0 10px 30px rgba(14,40,57,.045);
}

.hero {
    margin-bottom:20px;
    border-left:4px solid var(--accent);
    position:relative;
}

.badge {
    display:inline-block;
    padding:6px 11px;
    border-radius:99px;
    background:var(--soft);
    color:var(--accent);
    font-size:12px;
    font-weight:800;
    text-transform:capitalize;
}

.grid {
    display:grid;
    grid-template-columns:1.15fr .85fr;
    gap:18px;
}

.field { margin-bottom:18px; }

label {
    display:block;
    font-weight:700;
    font-size:13px;
    margin-bottom:7px;
}

input,select,textarea {
    width:100%;
    padding:12px 13px;
    border:1px solid var(--border);
    border-radius:11px;
    background:var(--input);
    color:var(--text);
    font:inherit;
}

input:focus-visible,select:focus-visible,textarea:focus-visible,
button:focus-visible,.button:focus-visible {
    outline:3px solid var(--accent);
    outline-offset:2px;
}

textarea {
    min-height:105px;
    resize:vertical;
}

input[type=file] {
    padding:16px;
    border:2px dashed var(--border);
    background:var(--soft);
    cursor:pointer;
}

.help,.small {
    font-size:12px;
    color:var(--muted);
}

.notice {
    padding:13px 16px;
    border-radius:12px;
    margin-bottom:18px;
}

.notice.error {
    background:#ffedf0;
    color:#9d2440;
}

.notice.success {
    background:#e9f9ef;
    color:#166b3b;
}

.ul-stats {
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:12px;
    margin-bottom:20px;
}

.ul-stat {
    padding:20px;
    background:var(--surface);
    border:1px solid var(--border);
    border-radius:16px;
}

.ul-stat span {
    display:block;
    color:var(--muted);
    font-size:12px;
}

.ul-stat strong {
    display:block;
    color:var(--accent);
    font-size:29px;
    margin-top:6px;
}

.stat {
    color:var(--accent);
    font-size:38px;
    font-weight:800;
}

.notes {
    display:grid;
    gap:12px;
}

.note {
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:18px;
}

.note h3 {
    margin:5px 0;
    font-size:17px;
}

.note p { margin:0 0 8px; }

.status-pending {
    background:#fff5dd;
    color:#9a6700;
}

.status-approved {
    background:#e9f9ef;
    color:#166b3b;
}

.status-rejected {
    background:#ffedf0;
    color:#a32143;
}

.section { margin-top:35px; }

.ul-notes-heading {
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:15px;
    flex-wrap:wrap;
}

.ul-note-tools {
    display:flex;
    gap:9px;
}

.ul-note-tools input { max-width:230px; }

.ul-no-match {
    padding:20px;
    text-align:center;
    color:var(--muted);
}

@media(max-width:760px) {
    .grid { grid-template-columns:1fr; }
    .ul-stats { grid-template-columns:repeat(2,1fr); }
    .wrap { padding:18px 15px 55px; }
}

@media(max-width:550px) {
    .note { flex-direction:column; }
    .ul-note-tools { width:100%; flex-direction:column; }
    .ul-note-tools input { max-width:none; }
    .ul-actions { width:100%; }
}

.theme-button {
    width: 42px;
    height: 42px;
    padding: 0;
    font-size: 22px;
    border-radius: 12px;
    cursor: pointer;
    background: var(--surface);
    color: var(--text);
    border: 1px solid var(--border);
}

.theme-button:hover {
    border-color: var(--accent);
}
</style>
<link rel="stylesheet" href="<?= e(url('assets/css/ulearn-design-system.css?v=1')) ?>">

<link rel="stylesheet" href="<?= e(url('assets/css/ulearn-my-notes-advanced.css?v=20261110-1')) ?>">

<script defer src="<?= e(url('assets/js/ulearn-motion.js?v=1')) ?>"></script>

<script defer src="<?= e(url('assets/js/ulearn-my-notes-advanced.js?v=20261110-1')) ?>"></script>

<script defer src="<?= e(url('assets/js/theme-global.js?v=20261112-2')) ?>"></script>
</head>

<body class="ul-my-notes">

<div class="ul-progress" aria-hidden="true"></div>

<main class="wrap">

<header class="top">

<a class="ul-brand" href="<?= e(url('dashboard/index.php')) ?>">
    <img src="<?= e(url('assets/images/nsbm-shield-only.png')) ?>"
         alt="" width="40" height="44">
    <span><b>u</b>Learn<i>.</i></span>
</a>

<nav class="ul-actions" aria-label="Page navigation">

<a class="button" href="<?= e(url('dashboard/notes-library.php')) ?>">
    Explore notes
</a>

<a class="button" href="<?= e(url('dashboard/index.php')) ?>">
    ← Dashboard
</a>

<a class="button" href="<?= e(url('dashboard/profile.php')) ?>">
    My Profile
</a>
<button
    type="button"
    id="themeToggle"
    class="theme-button"
    aria-label="Switch theme"
    title="Switch theme">
    <span class="theme-icon" aria-hidden="true">☾</span>
</button>

</nav>
</header>

<section class="hero">

<div class="eyebrow">✦ CONTRIBUTE & LEARN TOGETHER</div>

<h1>My Notes<span style="color:var(--accent)">.</span></h1>

<p class="muted">
Share useful study resources with your university community.
Every student upload is reviewed by an administrator before publication.
</p>

<span class="badge">+10 points for each approved note</span>

</section>

<?php if ($error !== ''): ?>
<div class="notice error" role="alert"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($success !== ''): ?>
<div class="notice success" role="status"><?= e($success) ?></div>
<?php endif; ?>

<div class="ul-stats">

<div class="ul-stat">
<span>Total uploads</span>
<strong><?= count($myNotes) ?></strong>
</div>

<div class="ul-stat">
<span>Approved</span>
<strong><?= $approvedCount ?></strong>
</div>

<div class="ul-stat">
<span>Pending review</span>
<strong><?= $pendingCount ?></strong>
</div>

<div class="ul-stat">
<span>Contribution points</span>
<strong><?= number_format($points) ?></strong>
</div>

</div>

<div class="grid">

<section class="panel">

<div class="ul-section-kicker">SHARE KNOWLEDGE</div>

<h2>Upload a note</h2>

<p class="muted small">
PDF, Word DOCX or PowerPoint PPTX · Maximum 20 MB
</p>

<?php if (!$modules): ?>

<p class="muted">
You must enroll in an academic module before uploading notes.
<a href="<?= e(url('dashboard/explore.php')) ?>"
   style="color:var(--accent)">Explore modules →</a>
</p>

<?php else: ?>

<form method="post"
      enctype="multipart/form-data"
      action="<?= e(url('dashboard/notes.php')) ?>">

<input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
<input type="hidden" name="action" value="upload">

<div class="field">

<label for="module_id">Academic module</label>

<select id="module_id" name="module_id" required>

<option value="">Select your enrolled module</option>

<?php foreach ($modules as $m): ?>

<option value="<?= (int)$m['id'] ?>"
    <?= $selectedModule === (int)$m['id'] ? 'selected' : '' ?>>

<?= e((string)$m['module_code'] . ' — ' . (string)$m['module_name']) ?>

</option>

<?php endforeach; ?>

</select>
</div>

<div class="field">

<label for="title">
Note title
<span id="ul-title-count">0 / 180</span>
</label>

<input
    id="title"
    name="title"
    maxlength="180"
    minlength="3"
    required
    placeholder="e.g. Python Functions — Revision Notes"
    value="<?= e((string)($_POST['title'] ?? '')) ?>">

</div>

<div class="field">

<label for="description">
Description
<span id="ul-desc-count">0 / 3000</span>
</label>

<textarea
    id="description"
    name="description"
    maxlength="3000"
    placeholder="What topics does this note cover?"><?= e((string)($_POST['description'] ?? '')) ?></textarea>

</div>

<div class="field">

<label for="note_file">Choose document</label>

<input
    id="note_file"
    type="file"
    name="note_file"
    accept=".pdf,.docx,.pptx"
    required>

<div class="help">
Only upload content you have permission to share.
Your name will appear when the note is published.
</div>

</div>

<button class="primary" type="submit" id="submitNote">
Submit for admin approval →
</button>

</form>

<?php endif; ?>

</section>

<aside class="panel">

<div class="eyebrow">CONTRIBUTION REWARDS</div>

<h2>Your points</h2>

<div class="stat"><?= number_format($points) ?></div>

<p class="muted small">
Points are earned only after an administrator approves a student-uploaded note.
</p>

<hr style="border:0;border-top:1px solid var(--border);margin:25px 0">

<h2>What happens next?</h2>

<p class="muted small">
1. Submit your note.<br>
2. An administrator reviews it.<br>
3. Approved notes become visible in the public Notes Hub.<br>
4. Other students can preview, download and rate them.
</p>

</aside>

</div>

<section class="section">

<div class="ul-notes-heading">

<div>
<div class="ul-section-kicker">YOUR CONTRIBUTIONS</div>
<h2>Your uploaded notes</h2>
</div>

<div class="ul-note-tools">

<input
    type="search"
    id="ul-note-search"
    placeholder="Search your uploads…"
    autocomplete="off">

<select id="ul-status-filter">
<option value="all">All statuses</option>
<option value="pending">Pending</option>
<option value="approved">Approved</option>
<option value="rejected">Rejected</option>
</select>

</div>

</div>

<p id="ul-results" class="muted small" aria-live="polite"></p>

<?php if (!$myNotes): ?>

<div class="panel">
<strong>No uploads yet</strong>
<p class="muted">
Your submitted notes and their approval status will appear here.
</p>
</div>

<?php else: ?>

<div class="notes" id="ul-notes-list">

<?php foreach ($myNotes as $n): ?>

<article class="note ul-note"
         data-status="<?= e((string)$n['status']) ?>">

<div>

<div class="eyebrow">
<?= e((string)$n['module_code']) ?>
· <?= e((string)$n['module_name']) ?>
</div>

<h3><?= e((string)$n['title']) ?></h3>

<?php if (!empty($n['description'])): ?>
<p class="muted small">
<?= nl2br(e((string)$n['description'])) ?>
</p>
<?php endif; ?>

<p class="muted small">
<?= e((string)$n['original_filename']) ?>
· <?= number_format((int)$n['file_size'] / 1048576, 2) ?> MB
· <?= e(date('M j, Y', strtotime((string)$n['created_at']))) ?>
</p>

<?php if (
    $n['status'] === 'rejected' &&
    !empty($n['rejection_reason'])
): ?>

<p class="small" style="color:#d34c68">
Reason: <?= e((string)$n['rejection_reason']) ?>
</p>

<?php endif; ?>

</div>

<div style="display:flex;flex-direction:column;align-items:flex-end;gap:8px">

<span class="badge status-<?= e((string)$n['status']) ?>">
<?= e((string)$n['status']) ?>
</span>

<?php if ($n['status'] === 'pending'): ?>

<form method="post"
      onsubmit="return confirm('Delete this pending note?');">

<input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
<input type="hidden" name="action" value="delete">
<input type="hidden" name="note_id" value="<?= (int)$n['id'] ?>">

<button type="submit">
Delete
</button>

</form>

<?php endif; ?>

</div>

</article>

<?php endforeach; ?>

</div>

<div id="ul-no-match" hidden>
No uploads match your search or status filter.
</div>

<?php endif; ?>

</section>

</main>

<script>
(() => {
    'use strict';

    const title = document.getElementById('title');
    const description = document.getElementById('description');

    function counter(input, output, max) {
        if (!input || !output) return;

        const update = () => {
            output.textContent = input.value.length + ' / ' + max;
        };

        input.addEventListener('input', update);
        update();
    }

    counter(
        title,
        document.getElementById('ul-title-count'),
        180
    );

    counter(
        description,
        document.getElementById('ul-desc-count'),
        3000
    );

    const search = document.getElementById('ul-note-search');
    const filter = document.getElementById('ul-status-filter');

    const notes = Array.from(
        document.querySelectorAll('.ul-note')
    );

    const noMatch = document.getElementById('ul-no-match');
    const results = document.getElementById('ul-results');

    function filterNotes() {
        const query = (search?.value || '').toLowerCase().trim();
        const status = filter?.value || 'all';

        let visible = 0;

        notes.forEach(note => {
            const matchesText = note.textContent
                .toLowerCase()
                .includes(query);

            const matchesStatus =
                status === 'all' ||
                note.dataset.status === status;

            const show = matchesText && matchesStatus;

            note.hidden = !show;

            if (show) visible++;
        });

        if (noMatch) {
            noMatch.hidden = visible !== 0;
        }

        if (results) {
            results.textContent =
                visible + ' of ' + notes.length + ' notes shown';
        }
    }

    search?.addEventListener('input', filterNotes);
    filter?.addEventListener('change', filterNotes);

    filterNotes();

    const uploadForm = document.querySelector(
        'form[enctype="multipart/form-data"]'
    );

    if (uploadForm) {
        uploadForm.addEventListener('submit', function(event) {

            if (!uploadForm.checkValidity()) return;

            const button = document.getElementById('submitNote');

            if (button && !button.disabled) {
                button.disabled = true;
                button.textContent = 'Submitting…';
            }
        });
    }

})();
</script>

<?php require_once __DIR__ . '/../includes/student-bot.php'; ?>

</body>
</html>
