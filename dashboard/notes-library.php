<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
$user = require_login();
if ((string)$user['role'] !== 'student') { http_response_code(403); exit('Student access only.'); }
$pdo = db();
$userId = (int)$user['id'];
$storage = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'ulearn_private' . DIRECTORY_SEPARATOR . 'notes';
$base = 'dashboard/notes-library.php';
$notice = '';
$error = '';

function noteIdFrom($value): int {
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    return $id === false || $id === null ? 0 : (int)$id;
}
function libraryNote(PDO $pdo, int $id, int $uid): ?array {
    $stmt = $pdo->prepare("SELECT n.*, m.module_code, m.module_name, u.full_name AS author_name, u.role AS author_role,
        (SELECT ROUND(AVG(r.rating),1) FROM student_note_ratings r WHERE r.note_id=n.id) AS avg_rating,
        (SELECT COUNT(*) FROM student_note_ratings r WHERE r.note_id=n.id) AS rating_count,
        (SELECT r.rating FROM student_note_ratings r WHERE r.note_id=n.id AND r.user_id=?) AS my_rating
        FROM student_notes n JOIN academic_modules m ON m.id=n.module_id
        JOIN users u ON u.id=n.uploaded_by
        WHERE n.id=? AND n.status='approved' AND EXISTS
          (SELECT 1 FROM student_module_enrollments e WHERE e.user_id=? AND e.module_id=n.module_id)
        LIMIT 1");
    $stmt->execute([$uid, $id, $uid]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
function libraryFileError(int $code, string $message): never { http_response_code($code); exit($message); }

$viewId = noteIdFrom($_GET['view'] ?? null);
$fileId = noteIdFrom($_GET['id'] ?? null);
if (isset($_GET['file'])) {
    $mode = (string)$_GET['file'];
    if (!in_array($mode, ['preview','download'], true) || !$fileId) libraryFileError(400, 'Invalid file request.');
    $note = libraryNote($pdo, $fileId, $userId);
    if (!$note) libraryFileError(404, 'Note not found or not available for your enrolled modules.');
    $originalName = basename(str_replace('\\','/', (string)$note['original_filename']));
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $types = [
        'pdf'=>'application/pdf',
        'docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'pptx'=>'application/vnd.openxmlformats-officedocument.presentationml.presentation'
    ];
    if (!isset($types[$ext])) libraryFileError(415, 'Unsupported note format.');
    $stored = (string)$note['stored_filename'];
    if ($stored === '' || basename($stored) !== $stored || str_contains($stored, "\0") || strtolower(pathinfo($stored, PATHINFO_EXTENSION)) !== $ext) libraryFileError(404, 'Note file unavailable.');
    $originalPath = $storage . DIRECTORY_SEPARATOR . $stored;
    if (!is_file($originalPath) || !is_readable($originalPath)) libraryFileError(404, 'Note file unavailable.');
    $path = $originalPath;
    if ($mode === 'preview' && $ext !== 'pdf') {
        $preview = (string)($note['preview_filename'] ?? '');
        if ($preview !== '' && basename($preview) === $preview && strtolower(pathinfo($preview, PATHINFO_EXTENSION)) === 'pdf' && is_file($storage . DIRECTORY_SEPARATOR . $preview)) {
            $path = $storage . DIRECTORY_SEPARATOR . $preview;
        } else {
            // Generate a private PDF preview on the server; browser users do not need LibreOffice.
            $candidates = ['C:\\Program Files\\LibreOffice\\program\\soffice.exe','C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe','/usr/bin/libreoffice'];
            $soffice = null;
            foreach ($candidates as $candidate) { if (is_file($candidate)) { $soffice = $candidate; break; } }
            if (!$soffice || !function_exists('exec')) libraryFileError(503, 'Preview requires LibreOffice on the ULearn server. You can download the original instead.');
            $workRoot = $storage . DIRECTORY_SEPARATOR . 'previews';
            if (!is_dir($workRoot) && !@mkdir($workRoot, 0700, true) && !is_dir($workRoot)) libraryFileError(503, 'Preview storage unavailable.');
            $work = $workRoot . DIRECTORY_SEPARATOR . bin2hex(random_bytes(12));
            if (!@mkdir($work, 0700)) libraryFileError(503, 'Preview storage unavailable.');
            $input = $work . DIRECTORY_SEPARATOR . 'note.' . $ext;
            $output = $work . DIRECTORY_SEPARATOR . 'note.pdf';
            $profile = $work . DIRECTORY_SEPARATOR . 'lo-profile';
            $ok = false;
            try {
                if (copy($originalPath, $input) && mkdir($profile, 0700)) {
                    $profileUri = DIRECTORY_SEPARATOR === '\\' ? 'file:///' . str_replace('\\', '/', $profile) : 'file://' . $profile;
                    $cmd = escapeshellarg($soffice) . ' ' . escapeshellarg('-env:UserInstallation=' . $profileUri) . ' --headless --convert-to pdf --outdir ' . escapeshellarg($work) . ' ' . escapeshellarg($input) . ' 2>&1';
                    $outputLines=[]; $exit=1;
                    exec($cmd, $outputLines, $exit);
                    if ($exit === 0 && is_file($output) && filesize($output) > 0) {
                        $generated = 'note-' . $fileId . '-' . bin2hex(random_bytes(12)) . '.pdf';
                        $destination = $storage . DIRECTORY_SEPARATOR . $generated;
                        if (rename($output, $destination)) {
                            $update = $pdo->prepare("UPDATE student_notes SET preview_filename=? WHERE id=? AND status='approved'");
                            $update->execute([$generated, $fileId]);
                            $path = $destination;
                            $ok = true;
                        }
                    }
                }
            } finally {
                if (is_file($input)) @unlink($input);
                if (is_file($output)) @unlink($output);
                if (is_dir($profile)) @rmdir($profile);
                @rmdir($work);
            }
            if (!$ok) libraryFileError(503, 'Could not generate a PDF preview. Download the original to view it.');
        }
    }
    if ($mode === 'download') {
        $pdo->prepare("UPDATE student_notes SET download_count=download_count+1 WHERE id=? AND status='approved'")->execute([$fileId]);
    }
    $mime = $mode === 'preview' ? 'application/pdf' : $types[$ext];
    $downloadName = $mode === 'preview' ? 'ULearn-Note-Preview.pdf' : ($originalName ?: 'ULearn-Note.' . $ext);
    $ascii = preg_replace('/[^A-Za-z0-9._ -]/','_', $downloadName) ?: 'ULearn-Note';
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: ' . $mime);
    header('Content-Disposition: ' . ($mode === 'preview' ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Content-Security-Policy: sandbox');
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $noteId = noteIdFrom($_POST['note_id'] ?? null);
    $rating = noteIdFrom($_POST['rating'] ?? null);
    if (!$noteId || $rating < 1 || $rating > 5 || empty($_SESSION['ulearn_notes_viewed'][$noteId])) {
        $error = 'Open the note first, then choose a rating from 1 to 5.';
    } elseif (!libraryNote($pdo, $noteId, $userId)) {
        $error = 'This note is not available for rating.';
    } else {
        try {
            $stmt = $pdo->prepare('INSERT INTO student_note_ratings (note_id,user_id,rating) VALUES (?,?,?) ON DUPLICATE KEY UPDATE rating=VALUES(rating), updated_at=CURRENT_TIMESTAMP');
            $stmt->execute([$noteId,$userId,$rating]);
            go($base . '?view=' . $noteId . '&rated=1');
        } catch (Throwable $ex) {
            error_log('ULearn note rating error: ' . $ex->getMessage());
            $error = 'Could not save your rating. Please try again.';
        }
    }
    $viewId = $noteId;
}

$viewNote = $viewId ? libraryNote($pdo, $viewId, $userId) : null;
if ($viewNote) {
    if (!isset($_SESSION['ulearn_notes_viewed']) || !is_array($_SESSION['ulearn_notes_viewed'])) $_SESSION['ulearn_notes_viewed'] = [];
    $_SESSION['ulearn_notes_viewed'][$viewId] = true;
    if (count($_SESSION['ulearn_notes_viewed']) > 200) $_SESSION['ulearn_notes_viewed'] = [$viewId => true];
}
if ($viewId && !$viewNote) { http_response_code(404); $error = 'This note is unavailable or does not belong to one of your enrolled modules.'; }
if (isset($_GET['rated']) && $viewNote) $notice = 'Your rating was saved successfully.';
$search = trim((string)($_GET['q'] ?? ''));
$search = mb_substr($search, 0, 100);
$moduleFilter = noteIdFrom($_GET['module_id'] ?? null);
$typeFilter = (string)($_GET['type'] ?? 'all');
if (!in_array($typeFilter, ['all','pdf','docx','pptx'], true)) $typeFilter = 'all';
$sort = (string)($_GET['sort'] ?? 'newest');
if (!in_array($sort, ['newest','top','downloads'], true)) $sort = 'newest';
$viewStyle = (string)($_GET['layout'] ?? 'grid');
if (!in_array($viewStyle, ['grid','list'], true)) $viewStyle = 'grid';
$modulesStmt = $pdo->prepare('SELECT DISTINCT m.id,m.module_code,m.module_name FROM student_module_enrollments e JOIN academic_modules m ON m.id=e.module_id WHERE e.user_id=? ORDER BY m.module_code,m.module_name');
$modulesStmt->execute([$userId]);
$modules = $modulesStmt->fetchAll(PDO::FETCH_ASSOC);
$pointsStmt = $pdo->prepare('SELECT COALESCE(SUM(points),0) FROM student_note_points WHERE user_id=?');
$pointsStmt->execute([$userId]);
$points = (int)$pointsStmt->fetchColumn();
$where = ["n.status='approved'", 'EXISTS (SELECT 1 FROM student_module_enrollments e WHERE e.user_id=? AND e.module_id=n.module_id)'];
$params = [$userId];
if ($moduleFilter) { $where[]='n.module_id=?'; $params[]=$moduleFilter; }
if ($search !== '') { $where[]='(n.title LIKE ? OR n.description LIKE ? OR m.module_code LIKE ? OR m.module_name LIKE ? OR u.full_name LIKE ?)'; for($i=0;$i<5;$i++) $params[]='%'.$search.'%'; }
if ($typeFilter !== 'all') { $where[]='LOWER(n.original_filename) LIKE ?'; $params[]='%.' . $typeFilter; }
$order = $sort === 'top' ? 'rating_avg DESC, rating_count DESC, n.approved_at DESC' : ($sort === 'downloads' ? 'n.download_count DESC, n.approved_at DESC' : 'n.approved_at DESC, n.id DESC');
$sql = 'SELECT n.id,n.title,n.description,n.original_filename,n.file_size,n.download_count,n.approved_at,n.module_id,m.module_code,m.module_name,u.full_name AS author_name,u.role AS author_role, (SELECT ROUND(AVG(r.rating),1) FROM student_note_ratings r WHERE r.note_id=n.id) AS rating_avg, (SELECT COUNT(*) FROM student_note_ratings r WHERE r.note_id=n.id) AS rating_count FROM student_notes n JOIN academic_modules m ON m.id=n.module_id JOIN users u ON u.id=n.uploaded_by WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $order . ' LIMIT 120';
$stmt = $pdo->prepare($sql); $stmt->execute($params); $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
$theme = (string)($user['theme'] ?? 'system');
function noteExt(array $note): string { return strtoupper((string)pathinfo((string)$note['original_filename'], PATHINFO_EXTENSION)); }
function noteLink(array $query): string { return url('dashboard/notes-library.php?' . http_build_query($query)); }
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Explore Notes — ULearn</title>
<script>(function(){let p=<?= json_encode($theme, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;if(p==='system')p=matchMedia('(prefers-color-scheme:dark)').matches?'dark':'light';document.documentElement.dataset.theme=p;})();</script>
<style>
:root{color-scheme:light;--bg:#f7f8fc;--card:#fff;--text:#171927;--muted:#777b90;--border:#e7e8f0;--accent:#6756e8;--soft:#f0edff;--shadow:0 10px 35px #1719270b} :root[data-theme=dark]{color-scheme:dark;--bg:#0c0e16;--card:#161924;--text:#f4f4fa;--muted:#a0a4b6;--border:#2b2e3c;--accent:#a99aff;--soft:#292440;--shadow:none}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif;line-height:1.5}a{color:inherit;text-decoration:none}button,input,select{font:inherit}button{cursor:pointer}.shell{max-width:1440px;margin:auto;padding:30px clamp(16px,3vw,46px) 80px}.top{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap}.brand{font-weight:850;font-size:26px;letter-spacing:-1.5px}.brand b{color:var(--accent)}.toplinks{display:flex;gap:10px;flex-wrap:wrap}.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;background:var(--card);border:1px solid var(--border);border-radius:11px;padding:10px 15px;font-size:13px;font-weight:750}.btn:hover{border-color:var(--accent)}.btn.primary{background:var(--accent);color:#fff;border-color:var(--accent)}.hero{display:flex;align-items:center;justify-content:space-between;gap:20px;margin:38px 0 25px}.eyebrow{font-size:11px;text-transform:uppercase;letter-spacing:1.6px;font-weight:800;color:var(--muted)}h1{font-size:clamp(32px,4vw,50px);letter-spacing:-1.7px;line-height:1.1;margin:8px 0 12px}h2{margin:0 0 8px;font-size:23px;letter-spacing:-.5px}.muted{color:var(--muted)}.points{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:17px 24px;min-width:170px}.points strong{display:block;font-size:28px;color:var(--accent)}.filters{background:var(--card);border:1px solid var(--border);border-radius:17px;padding:18px;display:grid;grid-template-columns:minmax(170px,2fr) minmax(160px,1.5fr) repeat(2,minmax(110px,1fr)) auto;gap:10px;align-items:end}.field{display:grid;gap:5px;font-size:12px;font-weight:750}.field input,.field select{min-width:0;width:100%;border:1px solid var(--border);background:var(--bg);color:var(--text);border-radius:10px;padding:10px 11px}.sectionbar{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;margin:30px 0 18px}.layoutswitch{display:flex;gap:6px}.layoutswitch a{padding:7px 12px;border-radius:9px;border:1px solid var(--border);font-size:12px;font-weight:750}.layoutswitch a.active{background:var(--soft);color:var(--accent)}.cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:17px}.cards.list{grid-template-columns:1fr}.card{background:var(--card);border:1px solid var(--border);border-radius:17px;overflow:hidden;min-width:0;box-shadow:var(--shadow);display:flex;flex-direction:column;transition:transform .15s,border-color .15s}.card:hover{transform:translateY(-3px);border-color:var(--accent)}.cover{aspect-ratio:16/9;display:flex;align-items:center;justify-content:center;position:relative;color:white;overflow:hidden}.cover:before{content:'';position:absolute;inset:-30%;background:repeating-linear-gradient(40deg,transparent 0 38px,#ffffff13 38px 78px);transform:rotate(8deg)}.cover span{position:relative;font-weight:900;font-size:31px;letter-spacing:2px;text-shadow:0 2px 12px #0002}.cover small{position:absolute;bottom:12px;left:15px;font-weight:800;font-size:11px;background:#0004;padding:4px 9px;border-radius:20px}.tone0{background:linear-gradient(130deg,#6658e8,#b48aff)}.tone1{background:linear-gradient(130deg,#1889c9,#55c1ed)}.tone2{background:linear-gradient(130deg,#efab46,#ffce7c)}.tone3{background:linear-gradient(130deg,#0eaa8b,#52d2aa)}.tone4{background:linear-gradient(130deg,#da5e8e,#f8a3b8)}.tone5{background:linear-gradient(130deg,#425c9d,#8da8e4)}.cardbody{padding:18px;display:flex;flex-direction:column;flex:1;gap:8px}.card h3{font-size:17px;line-height:1.35;margin:0;letter-spacing:-.3px;overflow-wrap:anywhere}.small{font-size:12px;color:var(--muted)}.clamp{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}.metrics{display:flex;align-items:center;gap:12px;flex-wrap:wrap;font-size:12px;color:var(--muted);margin-top:auto;padding-top:10px}.star{color:#e5a21a}.cardfoot{padding-top:12px}.cardfoot a{width:100%}.empty,.detail{background:var(--card);border:1px solid var(--border);border-radius:18px;padding:25px}.detail{margin:25px 0}.detailhead{display:flex;justify-content:space-between;align-items:start;gap:18px;flex-wrap:wrap}.pill{display:inline-block;background:var(--soft);color:var(--accent);font-size:12px;font-weight:800;border-radius:99px;padding:6px 11px}.viewer{width:100%;height:min(75vh,800px);min-height:400px;border:1px solid var(--border);border-radius:12px;background:white;margin:18px 0}.rate{border-top:1px solid var(--border);padding-top:20px;margin-top:20px}.rate form{display:flex;align-items:end;flex-wrap:wrap;gap:10px}.rate select{padding:10px 14px;border:1px solid var(--border);border-radius:10px;background:var(--bg);color:var(--text)}.alert{padding:13px 17px;border-radius:12px;background:var(--soft);color:var(--accent);margin:15px 0}.alert.error{background:#ffedf0;color:#9e2240}.cards.list .card{display:grid;grid-template-columns:240px 1fr}.cards.list .cover{aspect-ratio:auto;min-height:175px}@media(max-width:1200px){.cards{grid-template-columns:repeat(3,minmax(0,1fr))}.filters{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:820px){.cards{grid-template-columns:repeat(2,minmax(0,1fr))}.hero{align-items:flex-start}.filters{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:580px){.shell{padding:20px 14px 55px}.cards{grid-template-columns:1fr}.filters{grid-template-columns:1fr}.hero{flex-direction:column}.cards.list .card{grid-template-columns:1fr}.cards.list .cover{aspect-ratio:16/9}.viewer{min-height:330px}}
.rating-heading{display:flex;justify-content:space-between;align-items:center;gap:20px;flex-wrap:wrap}.rating-heading h2{margin:6px 0}.rating-current{font-size:23px;font-weight:850;display:grid;grid-template-columns:auto auto;column-gap:6px;align-items:center}.rating-current small{grid-column:1/-1;font-size:11px;font-weight:500;color:var(--muted)}.rating-form{display:flex;align-items:end;gap:22px;flex-wrap:wrap;margin-top:20px}.rating-fieldset{border:0;padding:0;margin:0;min-width:0}.rating-fieldset legend{font-size:13px;font-weight:750;margin-bottom:9px}.rating-stars{display:flex;flex-direction:row-reverse;justify-content:flex-end;gap:5px}.rating-stars input{position:absolute;width:1px;height:1px;opacity:0}.rating-stars label{font-size:clamp(32px,5vw,43px);line-height:1.2;color:var(--border);cursor:pointer;transition:color .15s,transform .15s;user-select:none}.rating-stars label:hover{transform:translateY(-3px)}.rating-stars label:hover,.rating-stars label:hover~label,.rating-stars input:checked~label{color:#f3b53f}.rating-stars input:focus-visible+label{outline:2px solid var(--accent);outline-offset:3px;border-radius:4px}.rating-feedback{display:block;min-height:20px;margin-top:8px;color:var(--muted);font-size:12px}.rating-submit{min-height:46px;padding:12px 20px}.rate{background:var(--card);border:1px solid var(--border);border-radius:17px;padding:23px;margin-top:24px}.rate .eyebrow{color:var(--accent)}@media(prefers-reduced-motion:reduce){.rating-stars label{transition:none}}
/* ULearn Notes — unified premium interface (presentation only) */
:root{--bg:#f5f8f7;--card:#fff;--text:#10233a;--muted:#627386;--border:#dce7e5;--accent:#087b57;--soft:#eaf6f1;--shadow:0 10px 30px rgba(14,40,57,.045)}
:root[data-theme=dark]{--bg:#0b1522;--card:#142235;--text:#f1f7fa;--muted:#9eafbf;--border:#293d50;--accent:#43d5a1;--soft:#173b37;--shadow:0 10px 35px rgba(0,0,0,.10)}
body{font-family:Inter,ui-sans-serif,system-ui,-apple-system,'Segoe UI',sans-serif;letter-spacing:-.008em}
.shell{max-width:1360px}.brand{letter-spacing:-1.2px}.top{padding-bottom:22px;border-bottom:1px solid var(--border)}
.btn{transition:background .18s,border-color .18s,transform .18s,box-shadow .18s;border-radius:12px;min-height:42px}
.btn:hover{transform:translateY(-1px)}.btn:focus-visible,a:focus-visible,input:focus-visible,select:focus-visible{outline:3px solid var(--accent);outline-offset:3px}
.btn.primary{background:#087b57;border-color:#087b57;color:#fff}.btn.primary:hover{background:#066447;border-color:#066447;box-shadow:0 8px 22px rgba(8,123,87,.18)}
.hero{margin:34px 0 28px}.hero h1{font-weight:830;letter-spacing:-.05em}.points,.filters,.detail,.rate,.card{box-shadow:var(--shadow);border-radius:20px}
.points{border-top:3px solid var(--accent)}.points strong{letter-spacing:-.05em}.field input,.field select{min-height:44px;background:var(--card)}
.cards{grid-template-columns:repeat(3,minmax(0,1fr));gap:18px}.card{transition:transform .2s ease,box-shadow .2s ease,border-color .2s ease}.card:hover{transform:translateY(-4px);box-shadow:0 18px 35px rgba(8,40,50,.10)}
.cover{background:linear-gradient(145deg,var(--soft),var(--bg))!important;color:var(--accent)!important}.cover span{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:8px 12px;box-shadow:var(--shadow)}
.cardbody{padding:20px}.card h3{letter-spacing:-.025em}.metrics{border-top:1px solid var(--border);padding-top:12px;margin-top:14px}.star{color:#e6a329}
.detail{overflow:hidden}.detailhead{gap:24px}.viewer{width:100%;min-height:550px;border:1px solid var(--border);border-radius:14px;background:#fff}
.rate{background:var(--soft);border-color:var(--border);padding:26px}.rating-stars{gap:7px}.rating-stars label{color:#aebbc4;filter:drop-shadow(0 2px 1px rgba(0,0,0,.05))}.rating-stars input:checked~label,.rating-stars label:hover,.rating-stars label:hover~label{color:#e8a927}.rating-feedback{font-weight:650}.rating-current{color:var(--text)}
@media(max-width:1050px){.cards{grid-template-columns:repeat(2,minmax(0,1fr))}.filters{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:650px){.cards{grid-template-columns:1fr}.filters{grid-template-columns:1fr}.shell{padding:18px 15px 55px}.detailhead{flex-direction:column}.viewer{min-height:380px}.hero{align-items:stretch;flex-direction:column}.rating-form{align-items:stretch;flex-direction:column;gap:14px}.rating-submit{width:100%}}
@media(prefers-reduced-motion:reduce){.btn,.card{transition:none!important}.btn:hover,.card:hover{transform:none}}
</style>
<script src="<?= htmlspecialchars(url('assets/js/theme-global.js?v=20261107-2'), ENT_QUOTES, 'UTF-8') ?>"></script>
<link rel="stylesheet" href="<?= e(url('assets/css/ulearn-design-system.css?v=1')) ?>">
<script defer src="<?= e(url('assets/js/ulearn-motion.js?v=1')) ?>"></script>
<link rel="stylesheet" href="<?= e(url('assets/css/ulearn-student-workspaces.css?v=3')) ?>">
</head><body class="ul3-notes"><main class="shell">
<header class="top"><a class="brand" href="<?= e(url('dashboard/index.php')) ?>">u learn<b>.</b></a><nav class="toplinks"><a class="btn" href="<?= e(url('dashboard/index.php')) ?>">← Dashboard</a><a class="btn" href="<?= e(url('dashboard/notes.php')) ?>">My uploads</a><a class="btn primary" href="<?= e(url('dashboard/notes.php')) ?>">＋ Upload note</a></nav></header>
<section class="hero"><div><div class="eyebrow">ULearn / Student community</div><h1>Explore notes<span style="color:var(--accent)">.</span></h1><p class="muted">Browse approved notes for your enrolled modules. Preview, download and rate useful resources.</p></div><div class="points"><div class="eyebrow">Your contribution points</div><strong><?= number_format($points) ?></strong><span class="small">+10 per approved upload</span></div></section>
<?php if ($error !== ''): ?><div class="alert error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if ($notice !== ''): ?><div class="alert" role="status"><?= e($notice) ?></div><?php endif; ?>
<?php if ($viewNote): ?>
<section class="detail" id="note"><div class="detailhead"><div><div class="eyebrow"><?= e((string)$viewNote['module_code']) ?> · <?= e((string)$viewNote['module_name']) ?></div><h2 style="font-size:30px;margin:10px 0"><?= e((string)$viewNote['title']) ?></h2><p class="muted"><?= nl2br(e((string)($viewNote['description'] ?? ''))) ?></p><p class="small">Uploaded by <strong><?= e((string)$viewNote['author_name']) ?></strong> (<?= e(ucwords(str_replace('_',' ',(string)$viewNote['author_role']))) ?>) · <?= e(noteExt($viewNote)) ?> · <?= number_format((int)$viewNote['download_count']) ?> downloads</p><p class="small"><span class="star">★</span> <?= $viewNote['avg_rating'] !== null ? e((string)$viewNote['avg_rating']) . '/5' : 'Not rated yet' ?> · <?= (int)$viewNote['rating_count'] ?> ratings</p></div><div class="toplinks"><a class="btn" href="<?= e(url($base)) ?>">← All notes</a><a class="btn primary" href="<?= e(noteLink(['id'=>(int)$viewNote['id'],'file'=>'download'])) ?>">↓ Download original</a></div></div>
<iframe class="viewer" title="Document preview: <?= e((string)$viewNote['title']) ?>" src="<?= e(noteLink(['id'=>(int)$viewNote['id'],'file'=>'preview'])) ?>" loading="lazy"></iframe>
<p class="small">If the preview says LibreOffice is required, the ULearn server needs document conversion configured. You can still download the original.</p>
<div class="rate"><div class="rating-heading"><div><div class="eyebrow">COMMUNITY FEEDBACK</div><h2>How useful was this note?</h2><p class="small">Select a star rating to help fellow students. You can update it anytime.</p></div><div class="rating-current"><span class="star">★</span> <?= $viewNote['avg_rating'] !== null ? e((string)$viewNote['avg_rating']) : '—' ?><small>Average rating</small></div></div>
<form class="rating-form" method="post" action="<?= e(url($base . '?view=' . (int)$viewNote['id'])) ?>"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="note_id" value="<?= (int)$viewNote['id'] ?>"><fieldset class="rating-fieldset"><legend>Your rating</legend><div class="rating-stars" aria-label="Rate this note from one to five stars"><?php for($r=1;$r<=5;$r++): ?><input type="radio" id="note-rating-<?= $r ?>" name="rating" value="<?= $r ?>" <?= (int)$viewNote['my_rating']===$r?'checked':'' ?> required><label for="note-rating-<?= $r ?>" title="<?= $r ?> out of 5 stars" aria-label="<?= $r ?> out of 5 stars">★</label><?php endfor; ?></div><span class="rating-feedback" id="rating-feedback" aria-live="polite"><?= (int)$viewNote['my_rating'] ? 'Your rating: '.(int)$viewNote['my_rating'].' / 5' : 'Choose a rating' ?></span></fieldset><button class="btn primary rating-submit" type="submit">Save my rating <span aria-hidden="true">→</span></button></form></div></section>
<?php endif; ?>
<form class="filters" method="get" action="<?= e(url($base)) ?>"><label class="field">Search notes<input type="search" name="q" value="<?= e($search) ?>" placeholder="Title, module or uploader..."></label><label class="field">Module<select name="module_id"><option value="">All enrolled modules</option><?php foreach($modules as $m): ?><option value="<?= (int)$m['id'] ?>" <?= $moduleFilter===(int)$m['id']?'selected':'' ?>><?= e((string)$m['module_code'].' · '.$m['module_name']) ?></option><?php endforeach; ?></select></label><label class="field">File type<select name="type"><?php foreach(['all'=>'All types','pdf'=>'PDF','pptx'=>'PowerPoint','docx'=>'Word'] as $k=>$v): ?><option value="<?= e($k) ?>" <?= $typeFilter===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?></select></label><label class="field">Sort by<select name="sort"><?php foreach(['newest'=>'Newest','top'=>'Highest rated','downloads'=>'Most downloaded'] as $k=>$v): ?><option value="<?= e($k) ?>" <?= $sort===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?></select></label><button class="btn primary" type="submit">Apply filters</button></form>
<div class="sectionbar"><div><h2>Community notes</h2><span class="small"><?= count($notes) ?> approved notes shown · Only your enrolled modules</span></div><div class="layoutswitch"><a class="<?= $viewStyle==='grid'?'active':'' ?>" href="<?= e(noteLink(['q'=>$search,'module_id'=>$moduleFilter?:null,'type'=>$typeFilter,'sort'=>$sort,'layout'=>'grid'])) ?>">▦ Grid</a><a class="<?= $viewStyle==='list'?'active':'' ?>" href="<?= e(noteLink(['q'=>$search,'module_id'=>$moduleFilter?:null,'type'=>$typeFilter,'sort'=>$sort,'layout'=>'list'])) ?>">☷ List</a></div></div>
<?php if (!$notes): ?><div class="empty"><strong>No approved notes match your filters.</strong><p class="muted">Try another module or search term. Notes become visible after admin approval.</p></div><?php else: ?><div class="cards <?= $viewStyle==='list'?'list':'' ?>">
<?php foreach($notes as $n): ?><article class="card"><a class="cover tone<?= (int)$n['id']%6 ?>" href="<?= e(noteLink(['view'=>(int)$n['id']])) ?>" aria-label="Open <?= e((string)$n['title']) ?>"><span><?= e(noteExt($n)) ?></span><small><?= e((string)$n['module_code']) ?></small></a><div class="cardbody"><div class="eyebrow"><?= e((string)$n['module_code']) ?> · <?= e((string)$n['module_name']) ?></div><h3 class="clamp"><?= e((string)$n['title']) ?></h3><div class="small clamp"><?= e((string)($n['description'] ?: 'Shared study notes')) ?></div><div class="small">By <strong><?= e((string)$n['author_name']) ?></strong> · <?= e(ucwords(str_replace('_',' ',(string)$n['author_role']))) ?></div><div class="metrics"><span><span class="star">★</span> <?= $n['rating_avg']!==null?e((string)$n['rating_avg']):'New' ?> (<?= (int)$n['rating_count'] ?>)</span><span>↓ <?= number_format((int)$n['download_count']) ?></span><span><?= e(noteExt($n)) ?></span></div><div class="cardfoot"><a class="btn primary" href="<?= e(noteLink(['view'=>(int)$n['id']])) ?>">Open note →</a></div></div></article><?php endforeach; ?></div><?php endif; ?>
</main><script>(function(){const stars=document.querySelectorAll('.rating-stars input');const feedback=document.getElementById('rating-feedback');const labels=['','Not useful','Needs improvement','Good','Very helpful','Excellent'];stars.forEach(star=>star.addEventListener('change',()=>{if(feedback)feedback.textContent=labels[Number(star.value)]+' · '+star.value+'/5';}));})();</script><?php require_once __DIR__ . '/../includes/student-bot.php'; ?></body></html>
