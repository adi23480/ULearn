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
$moduleId = filter_input(INPUT_GET, 'module_id', FILTER_VALIDATE_INT);
$module = null;
$quizzes = [];
$completedCount = 0;
$materials = [];
$approvedNotesCount = 0;
$error = '';

if (!$moduleId || $moduleId < 1) {
    http_response_code(400);
    $error = 'Choose a module from My Enrolled Modules on your dashboard.';
} else {
    try {
        $stmt = $pdo->prepare('SELECT m.id, m.module_code, m.module_name, m.description, e.enrolled_at
            FROM student_module_enrollments e
            JOIN academic_modules m ON m.id = e.module_id
            WHERE e.user_id = ? AND m.id = ? LIMIT 1');
        $stmt->execute([$userId, $moduleId]);
        $module = $stmt->fetch() ?: null;
        if (!$module) {
            http_response_code(403);
            $error = 'You are not enrolled in this module. Enroll through Explore & Enroll first.';
        } else {
            $stmt = $pdo->prepare("SELECT q.id, q.title, q.description, q.quiz_type, q.duration_minutes,
                    q.available_from, q.available_until,
                    (SELECT COUNT(*) FROM quiz_questions qq WHERE qq.quiz_id = q.id) AS question_count
                FROM quizzes q
                WHERE q.module_id = ? AND q.is_published = 1
                ORDER BY q.title, q.id");
            $stmt->execute([$moduleId]);
            $now = time();
            foreach ($stmt->fetchAll() as $quiz) {
                if ((int)$quiz['question_count'] < 1) continue;
                if (!empty($quiz['available_from']) && strtotime((string)$quiz['available_from']) > $now) continue;
                if (!empty($quiz['available_until']) && strtotime((string)$quiz['available_until']) < $now) continue;
                $quizzes[] = $quiz;
            }
            // Published resources: shared module resources or resources for the student's own degree/batch.
            $stmt = $pdo->prepare("SELECT sm.id, sm.title, sm.description, sm.original_filename,
                    sm.file_size, sm.created_at
                FROM study_materials sm
                LEFT JOIN academic_curriculum c ON c.id = sm.curriculum_id
                LEFT JOIN student_academic_profiles sap ON sap.user_id = ?
                WHERE sm.module_id = ? AND sm.is_published = 1
                  AND (sm.curriculum_id IS NULL OR
                       (c.is_active = 1 AND c.module_id = sm.module_id
                        AND sap.degree_id = c.degree_id AND sap.batch_id = c.batch_id))
                ORDER BY sm.created_at DESC, sm.id DESC");
            $stmt->execute([$userId, $moduleId]);
            $materials = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Count only admin-approved community notes for this enrolled module.
            $notesStmt = $pdo->prepare("SELECT COUNT(*) FROM student_notes WHERE module_id = ? AND status = 'approved'");
            $notesStmt->execute([$moduleId]);
            $approvedNotesCount = (int)$notesStmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM quiz_attempts a
                JOIN quizzes q ON q.id = a.quiz_id
                WHERE a.user_id = ? AND q.module_id = ? AND a.status IN ('submitted', 'timed_out')");
            $stmt->execute([$userId, $moduleId]);
            $completedCount = (int)$stmt->fetchColumn();
        }
    } catch (Throwable $exception) {
        error_log('ULearn module learning page error: ' . $exception->getMessage());
        $error = 'This module could not be loaded. Please try again.';
    }
}
// Downloads are served by PHP from private storage; the private path is never exposed.
if ($module && isset($_GET['download'])) {
    $downloadId = filter_var($_GET['download'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $selected = null;
    foreach ($materials as $material) {
        if ($downloadId && (int)$material['id'] === (int)$downloadId) {
            $selected = $material;
            break;
        }
    }
    if (!$selected) {
        http_response_code(404);
        exit('Material not found or not available to your account.');
    }
    $stmt = $pdo->prepare('SELECT stored_filename, mime_type FROM study_materials WHERE id = ? AND is_published = 1');
    $stmt->execute([(int)$selected['id']]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);
    $storage = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'ulearn_private' . DIRECTORY_SEPARATOR . 'materials';
    $storedName = (string)($file['stored_filename'] ?? '');
    $path = $storage . DIRECTORY_SEPARATOR . $storedName;
    if (!$file || $storedName === '' || basename($storedName) !== $storedName || !is_file($path) || !is_readable($path)) {
        http_response_code(404);
        exit('The requested file is unavailable.');
    }
    // Always deliver a recognizable filename and matching MIME type.
    $mimeExtensions = [
        'application/pdf' => 'pdf',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/msword' => 'doc',
        'text/plain' => 'txt',
    ];
    $extensionMime = array_flip($mimeExtensions);
    $original = (string)$selected['original_filename'];
    $original = str_replace('\\', '/', $original);
    $original = basename($original);
    $original = trim((string)preg_replace('/[\x00-\x1F\x7F"\\\/]/', '_', $original), " .\t\n\r\0\x0B");
    $storedExtension = strtolower(pathinfo($storedName, PATHINFO_EXTENSION));
    $originalExtension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $dbMime = strtolower(trim((string)($file['mime_type'] ?? '')));
    $validExtensions = ['pdf', 'pptx', 'ppt', 'docx', 'doc', 'txt'];
    $extension = in_array($originalExtension, $validExtensions, true) ? $originalExtension : null;
    if ($extension === null && isset($mimeExtensions[$dbMime])) {
        $extension = $mimeExtensions[$dbMime];
    }
    if ($extension === null && in_array($storedExtension, $validExtensions, true)) {
        $extension = $storedExtension;
    }
    if ($extension === null) {
        http_response_code(415);
        exit('Unsupported material file type. Contact your lecturer.');
    }
    $baseName = $originalExtension !== '' ? pathinfo($original, PATHINFO_FILENAME) : $original;
    $baseName = trim($baseName, " .\t\n\r\0\x0B");
    if ($baseName === '') $baseName = trim((string)$selected['title']);
    if ($baseName === '') $baseName = 'Lecture Resource';
    // Keep the uploader's original name and add ULearn branding before the extension.
    $downloadName = $baseName . ' - ULearn.' . $extension;
    $contentType = $extensionMime[$extension] ?? 'application/octet-stream';
    $asciiName = preg_replace('/[^A-Za-z0-9._ -]/', '_', $downloadName);
    if ($asciiName === '' || $asciiName === null) $asciiName = 'ULearn-Resource.' . $extension;
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: ' . $contentType);
    header('Content-Disposition: attachment; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $module ? e((string)$module['module_name']) : 'Module Learning' ?> — ULearn</title>
<script>(function(){try{const saved=localStorage.getItem('ulearn-theme');const theme=saved==='light'||saved==='dark'?saved:(window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light');document.documentElement.dataset.theme=theme;}catch(e){}})();</script>
<style>
:root{color-scheme:light;--bg:#f7f8fc;--surface:#fff;--text:#171927;--muted:#777b90;--border:#e7e8f0;--accent:#6756e8;--soft:#f0edff}
:root[data-theme="dark"]{color-scheme:dark;--bg:#0c0e16;--surface:#161924;--text:#f4f4fa;--muted:#a0a4b6;--border:#2b2e3c;--accent:#a99aff;--soft:#292440}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif;line-height:1.55}a{color:inherit;text-decoration:none}.wrap{max-width:1060px;margin:auto;padding:32px 22px 70px}.top{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:28px}.eyebrow{font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);font-weight:800}.button{display:inline-flex;align-items:center;justify-content:center;padding:10px 15px;border:1px solid var(--border);border-radius:11px;background:var(--surface);font-weight:700;font-size:13px}.button.primary{background:var(--accent);border-color:var(--accent);color:#fff}.button:hover,.tile:hover{border-color:var(--accent)}.hero,.tile,.quiz,.empty{background:var(--surface);border:1px solid var(--border);border-radius:19px;padding:25px}.hero{margin-bottom:22px}h1{font-size:clamp(30px,4vw,44px);letter-spacing:-.04em;line-height:1.2;margin:9px 0 14px}h2{font-size:21px;margin:0 0 14px}.muted{color:var(--muted)}.pill{display:inline-block;background:var(--soft);color:var(--accent);padding:6px 11px;border-radius:99px;font-size:12px;font-weight:750}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:15px}.tile{display:block;min-width:0}.tile h3,.quiz h3{margin:10px 0 6px;font-size:18px}.tile p,.quiz p{font-size:14px;color:var(--muted);margin:0 0 15px}.section{margin-top:36px}.quiz-list{display:grid;gap:12px}.material-list{display:grid;gap:12px}.material{display:flex;justify-content:space-between;align-items:center;gap:18px;flex-wrap:wrap;background:var(--surface);border:1px solid var(--border);border-radius:17px;padding:20px}.material-info{flex:1;min-width:220px}.material h3{margin:5px 0 8px;font-size:17px}.material p{margin:0 0 8px;color:var(--muted);font-size:13px}.material-meta{font-size:12px;color:var(--muted);overflow-wrap:anywhere}.quiz{display:flex;align-items:center;justify-content:space-between;gap:20px;flex-wrap:wrap}.quiz-info{flex:1;min-width:220px}.quiz-meta{font-size:12px;color:var(--muted);margin-top:8px}.error{background:#ffedf0;color:#a32143;border-radius:12px;padding:16px}.actions{display:flex;gap:10px;flex-wrap:wrap}@media(max-width:640px){.wrap{padding:20px 15px 55px}.grid{grid-template-columns:1fr}.hero,.tile,.quiz{padding:20px}}
</style>
</head>
<body>
<main class="wrap">
<div class="top"><div><div class="eyebrow">ULearn / Student workspace</div><strong>Module Learning</strong></div><div class="actions"><a class="button" href="explore.php">Explore modules</a><a class="button" href="index.php#modules">← My Enrolled Modules</a></div></div>
<?php if ($error !== ''): ?><div class="error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if ($module): ?>
<section class="hero"><div class="eyebrow"><?= e((string)$module['module_code']) ?> · My learning module</div><h1><?= e((string)$module['module_name']) ?></h1><p class="muted"><?= e((string)($module['description'] ?: 'Your personal space to study this module and practice quizzes.')) ?></p><span class="pill">✓ Enrolled</span> <span class="pill"><?= $completedCount ?> completed <?= $completedCount === 1 ? 'attempt' : 'attempts' ?></span></section>
<section class="section" aria-label="Module tools"><h2>Your module workspace</h2><div class="grid">
<a class="tile" href="quizzes.php"><div class="eyebrow">Practice</div><h3>◇ Quiz Arena →</h3><p>Take published quizzes for your enrolled modules.</p><span class="pill"><?= count($quizzes) ?> available here</span></a>
<a class="tile" href="<?= e(url('dashboard/notes-library.php?module_id=' . (int)$module['id'])) ?>"><div class="eyebrow">Community resources</div><h3>▤ Study Notes →</h3><p>Browse approved PDF, Word and PowerPoint notes shared for this module. Preview, download and rate notes.</p><span class="pill"><?= $approvedNotesCount ?> approved <?= $approvedNotesCount === 1 ? 'note' : 'notes' ?></span></a>
<a class="tile" href="<?= e(url('dashboard/notes.php?module_id=' . (int)$module['id'])) ?>"><div class="eyebrow">Contribute</div><h3>↑ Upload Your Notes →</h3><p>Share a note with your classmates. Student uploads become public only after admin approval.</p><span class="pill">Admin approval required</span></a>
<a class="tile" href="quiz-history.php?module_id=<?= (int)$module['id'] ?>"><div class="eyebrow">Review</div><h3>◷ Quiz History →</h3><p>View this module's completed attempts, scores and answer explanations.</p><span class="pill"><?= $completedCount ?> completed attempts</span></a>
<a class="tile" href="analytics.php?module_id=<?= (int)$module['id'] ?>"><div class="eyebrow">Improve</div><h3>◉ Performance Analytics →</h3><p>Explore your performance for this module.</p></a>
<a class="tile" href="topic-analysis.php?module_id=<?= (int)$module['id'] ?>"><div class="eyebrow">Focus</div><h3>◈ Topic Weaknesses →</h3><p>Find the topics that need more practice.</p></a>
</div></section>
<section class="section" id="community-notes" aria-label="Community study notes">
<h2>Community study notes</h2>
<div class="material"><div class="material-info"><div class="eyebrow">Student, lecturer &amp; admin contributions</div><h3><?= $approvedNotesCount ?> approved <?= $approvedNotesCount === 1 ? 'note' : 'notes' ?> available</h3><p>Open the module's approved notes library to preview documents, download originals, see contributors and rate notes.</p></div><div class="actions"><a class="button primary" href="<?= e(url('dashboard/notes-library.php?module_id=' . (int)$module['id'])) ?>">Browse module notes →</a><a class="button" href="<?= e(url('dashboard/notes.php?module_id=' . (int)$module['id'])) ?>">Upload a note ↑</a></div></div>
</section>
<section class="section" id="materials" aria-label="Study materials">
<h2>Lecture slides &amp; study materials</h2>
<?php if (!$materials): ?>
<div class="empty"><strong>No published materials yet</strong><p class="muted">Lecture slides and learning resources will appear here when your lecturers or administrators publish them for your module and academic group.</p></div>
<?php else: ?>
<div class="material-list">
<?php foreach ($materials as $material): ?>
<article class="material"><div class="material-info"><div class="eyebrow">Study resource</div>
<h3><?= e((string)$material['title']) ?></h3>
<?php if (!empty($material['description'])): ?><p><?= nl2br(e((string)$material['description'])) ?></p><?php endif; ?>
<div class="material-meta"><?= e((string)$material['original_filename']) ?> · <?= number_format((int)$material['file_size'] / 1048576, 2) ?> MB · <?= e(date('M j, Y', strtotime((string)$material['created_at']))) ?></div></div>
<a class="button primary" href="?module_id=<?= (int)$module['id'] ?>&amp;download=<?= (int)$material['id'] ?>">↓ Download</a></article>
<?php endforeach; ?>
</div>
<?php endif; ?>
</section>
<section class="section"><h2>Available quizzes in this module</h2>
<?php if (!$quizzes): ?><div class="empty"><strong>No available quizzes yet</strong><p class="muted">Published quizzes with questions will appear here when they are available.</p></div>
<?php else: ?><div class="quiz-list"><?php foreach ($quizzes as $quiz): ?><article class="quiz"><div class="quiz-info"><div class="eyebrow"><?= e((string)ucfirst((string)$quiz['quiz_type'])) ?></div><h3><?= e((string)$quiz['title']) ?></h3><?php if (!empty($quiz['description'])): ?><p><?= e((string)$quiz['description']) ?></p><?php endif; ?><div class="quiz-meta"><?= (int)$quiz['question_count'] ?> questions · <?= (int)$quiz['duration_minutes'] ?> minutes</div></div><a class="button primary" href="quizzes.php">Open Quiz Arena →</a></article><?php endforeach; ?></div><?php endif; ?>
</section>
<?php endif; ?>
</main>
</body>
</html>
