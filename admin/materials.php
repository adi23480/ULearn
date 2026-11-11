<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
$user = require_login();
if (!in_array((string)$user['role'], ['admin', 'main_admin'], true)) {
    http_response_code(403);
    exit('Administrator access required.');
}
$pdo = db();
$assignedSql = 'SELECT c.id AS curriculum_id, c.module_id, m.module_code, m.module_name, d.name AS degree_name, b.batch_code FROM academic_curriculum c JOIN academic_modules m ON m.id = c.module_id JOIN academic_degrees d ON d.id = c.degree_id JOIN academic_batches b ON b.id = c.batch_id WHERE c.is_active = 1 AND m.is_active = 1 AND d.is_active = 1 AND b.is_active = 1 ORDER BY m.module_name, d.name, b.batch_code';
$assignments = $pdo->query($assignedSql)->fetchAll(PDO::FETCH_ASSOC);
$allowed = [];
foreach ($assignments as $assignment) {
    $allowed[(int)$assignment['curriculum_id']] = $assignment;
}
// Storage is outside C:\xampp\htdocs\ulearn and outside the Apache document root.
$storage = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'ulearn_private' . DIRECTORY_SEPARATOR . 'materials';
$maxBytes = 20 * 1024 * 1024;
$types = ['pdf'=>'application/pdf','doc'=>'application/msword','docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document','ppt'=>'application/vnd.ms-powerpoint','pptx'=>'application/vnd.openxmlformats-officedocument.presentationml.presentation','txt'=>'text/plain'];
function materialError(string $message): void { $_SESSION['materials_flash'] = ['error', $message]; header('Location: ' . url('admin/materials.php')); exit; }
function materialSuccess(string $message): void { $_SESSION['materials_flash'] = ['success', $message]; header('Location: ' . url('admin/materials.php')); exit; }
function materialOwned(PDO $pdo, int $id): ?array {
    $q = $pdo->prepare('SELECT * FROM study_materials WHERE id = ? LIMIT 1');
    $q->execute([$id]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}
if (isset($_GET['download'])) {
    $id=filter_var($_GET['download'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $item=$id ? materialOwned($pdo,(int)$id) : null;
    if (!$item) { http_response_code(404); exit('File not found.'); }
    // Never use an untrusted stored path or expose the private storage directory.
    $storedName = (string)$item['stored_filename'];
    if (!preg_match('/\A[a-f0-9]{48}\.(pdf|doc|docx|ppt|pptx|txt)\z/iD', $storedName)) {
        http_response_code(404); exit('File not found.');
    }
    $path = $storage . DIRECTORY_SEPARATOR . $storedName;
    if (!is_file($path) || !is_readable($path)) { http_response_code(404); exit('File missing from storage.'); }

    $extension = strtolower(pathinfo($storedName, PATHINFO_EXTENSION));
    if (!isset($types[$extension])) { http_response_code(404); exit('Unsupported file type.'); }
    $original = basename(str_replace('\\', '/', (string)$item['original_filename']));
    $baseName = pathinfo($original, PATHINFO_FILENAME);
    // Strip characters that can cause header injection or invalid local filenames.
    $baseName = preg_replace('/[\x00-\x1F\x7F\\\/<>:"|?*]+/u', ' ', $baseName);
    $baseName = trim((string)$baseName, " .\t\n\r");
    if ($baseName === '') $baseName = 'Lecture Material';
    $downloadName = $baseName . ' - ULearn.' . $extension;
    // ASCII fallback for browsers that do not support RFC 5987 filename*.
    $asciiName = preg_replace('/[^A-Za-z0-9 ._()-]/', '_', $downloadName);
    $asciiName = str_replace(['\r', '\n', '"', '\\'], '_', (string)$asciiName);
    header('Content-Type: ' . $types[$extension]);
    header('Content-Disposition: attachment; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check(); $action=(string)($_POST['action'] ?? '');
    if ($action === 'upload') {
        $cid=filter_var($_POST['curriculum_id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if (!$cid || !isset($allowed[(int)$cid])) materialError('Choose an active curriculum module and batch.');
        $title=trim((string)($_POST['title'] ?? '')); $description=trim((string)($_POST['description'] ?? ''));
        if ($title === '' || mb_strlen($title)>200 || mb_strlen($description)>5000) materialError('Enter a title (up to 200 characters) and a description under 5000 characters.');
        $file=$_FILES['material'] ?? null;
        if (!is_array($file) || is_array($file['error'] ?? null) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) materialError('Upload failed. Check the file and PHP upload limits.');
        $size=(int)($file['size'] ?? 0); $tmp=(string)($file['tmp_name'] ?? '');
        if ($size<1 || $size>$maxBytes || !is_uploaded_file($tmp)) materialError('File must be between 1 byte and 20 MB.');
        $original=basename(str_replace('\\','/',(string)$file['name']));
        $ext=strtolower(pathinfo($original,PATHINFO_EXTENSION));
        if (!isset($types[$ext]) || strlen($original)>255) materialError('Only PDF, DOC, DOCX, PPT, PPTX and TXT files are allowed.');
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $accepted=[$types[$ext]];
        if (in_array($ext,['doc','ppt','docx','pptx'],true)) $accepted[]='application/octet-stream';
        if (in_array($ext,['docx','pptx'],true)) $accepted[]='application/zip';
        if ($ext==='txt') $accepted[]='application/x-empty';
        if (!in_array($mime,$accepted,true)) materialError('The file content does not match its extension.');
        if (in_array($ext,['docx','pptx'],true)) {
            $zip=new ZipArchive(); if ($zip->open($tmp)!==true) materialError('Invalid Office document.');
            $expected=$ext==='docx'?'word/document.xml':'ppt/presentation.xml';
            $valid=$zip->locateName($expected)!==false; $zip->close(); if (!$valid) materialError('Invalid Office document structure.');
        }
        if (!is_dir($storage) && !mkdir($storage,0700,true) && !is_dir($storage)) materialError('Unable to create private storage directory.');
        $stored=bin2hex(random_bytes(24)).'.'.$ext; $dest=$storage.DIRECTORY_SEPARATOR.$stored;
        if (!move_uploaded_file($tmp,$dest)) materialError('Unable to save the uploaded file.');
        @chmod($dest,0600);
        try {
            $q=$pdo->prepare('INSERT INTO study_materials (module_id,curriculum_id,uploaded_by,title,description,original_filename,stored_filename,mime_type,file_size,is_published) VALUES (?,?,?,?,?,?,?,?,?,0)');
            $q->execute([(int)$allowed[(int)$cid]['module_id'],(int)$cid,(int)$user['id'],$title,$description,$original,$stored,$mime,$size]);
        } catch (Throwable $e) { @unlink($dest); error_log('ULearn material insert: '.$e->getMessage()); materialError('Database could not save this material.'); }
        materialSuccess('Material uploaded as a draft. Publish it when ready.');
    }
    if (in_array($action,['publish','unpublish','delete'],true)) {
        $id=filter_var($_POST['id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $item=$id ? materialOwned($pdo,(int)$id) : null;
        if (!$item) materialError('Material not found or access denied.');
        if ($action==='delete') {
            $q=$pdo->prepare('DELETE FROM study_materials WHERE id = ?');
            $q->execute([(int)$id]);
            if ($q->rowCount()) @unlink($storage.DIRECTORY_SEPARATOR.$item['stored_filename']);
            materialSuccess('Material removed.');
        }
        $q=$pdo->prepare('UPDATE study_materials SET is_published = ? WHERE id = ?');
        $q->execute([$action==='publish'?1:0,(int)$id]);
        materialSuccess($action==='publish'?'Material published.':'Material changed to draft.');
    }
    materialError('Unknown action.');
}
$q = $pdo->query('SELECT sm.*, m.module_code, m.module_name, d.name AS degree_name, b.batch_code, u.full_name AS uploader_name FROM study_materials sm JOIN academic_modules m ON m.id = sm.module_id LEFT JOIN academic_curriculum c ON c.id = sm.curriculum_id LEFT JOIN academic_degrees d ON d.id = c.degree_id LEFT JOIN academic_batches b ON b.id = c.batch_id JOIN users u ON u.id = sm.uploaded_by ORDER BY sm.created_at DESC, sm.id DESC');
$materials = $q->fetchAll(PDO::FETCH_ASSOC);
$flash=$_SESSION['materials_flash'] ?? null; unset($_SESSION['materials_flash']);
$theme=in_array(($user['theme'] ?? 'system'),['light','dark','system'],true)?$user['theme']:'system';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Admin Study Materials | U Learn</title>
<script>(function(){let t=localStorage.getItem('ulearn-theme');if(t!=='light'&&t!=='dark'){const p=<?= json_encode($theme) ?>;t=p==='system'?(matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'):p;}document.documentElement.dataset.theme=t;})();</script>
<style>:root{--bg:#f7f8fc;--card:#fff;--text:#1b1d2c;--muted:#777b90;--border:#e7e8ef;--accent:#6856e8;--soft:#f0edff}[data-theme=dark]{--bg:#10121c;--card:#1b1e2b;--text:#f5f5fa;--muted:#a2a6b8;--border:#303344;--accent:#b1a4ff;--soft:#2c2745}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif}a{color:var(--accent)}.layout{display:grid;grid-template-columns:245px minmax(0,1fr);min-height:100vh}.sidebar{background:var(--card);border-right:1px solid var(--border);padding:30px 20px}.brand{font-size:27px;font-weight:800;letter-spacing:-1.5px;margin-bottom:45px}.brand span{color:var(--accent)}.nav-label,.eyebrow{color:var(--muted);font-size:11px;font-weight:750;letter-spacing:1.5px}.nav a{display:block;padding:13px 15px;margin-bottom:7px;border-radius:12px;text-decoration:none;color:var(--text)}.nav a:hover,.nav a.active{background:var(--soft);color:var(--accent)}main{min-width:0;padding:35px clamp(20px,5vw,75px)}.top{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:50px}.theme{border:1px solid var(--border);background:var(--card);color:var(--text);width:44px;height:44px;display:inline-flex;align-items:center;justify-content:center;font-size:23px;border-radius:14px;cursor:pointer;transition:transform .18s,background .18s}.theme:hover{transform:translateY(-2px);background:var(--soft)}.theme:focus-visible{outline:3px solid var(--accent);outline-offset:3px}h1{font-size:clamp(32px,4vw,52px);letter-spacing:-2px;margin:12px 0}h1 span{color:var(--accent)}.muted{color:var(--muted);line-height:1.6}.grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:28px}.card{background:var(--card);border:1px solid var(--border);border-radius:18px;padding:26px}.card h2{margin-top:0}label{display:block;font-size:13px;font-weight:650;margin:15px 0 7px}input[type=text],textarea,select,input[type=file]{display:block;width:100%;border:1px solid var(--border);background:var(--bg);color:var(--text);border-radius:10px;padding:12px;font:inherit}textarea{min-height:95px;resize:vertical}button,.btn{border:0;border-radius:10px;background:var(--accent);color:#fff;padding:11px 16px;cursor:pointer;font:inherit;font-weight:650}.btn.secondary,button.secondary{background:var(--soft);color:var(--accent)}button.danger{background:#b94359}.actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:15px}.actions form{display:inline}.item{padding:18px 0;border-top:1px solid var(--border)}.item:first-of-type{border-top:0}.item strong{display:block;margin-bottom:7px}.tag{display:inline-block;padding:4px 9px;border-radius:20px;background:var(--soft);color:var(--accent);font-size:12px;font-weight:700}.flash{padding:14px 18px;border:1px solid var(--border);background:var(--soft);border-radius:12px;margin-top:15px}.flash.error{background:#fff0f1;color:#a12235}.small{font-size:12px}.empty{padding:20px;border:1px dashed var(--border);border-radius:12px;color:var(--muted)}@media(max-width:900px){.layout{grid-template-columns:1fr}.sidebar{border-right:0;border-bottom:1px solid var(--border);padding:20px}.brand{margin-bottom:18px}.grid{grid-template-columns:1fr}main{padding:26px 20px}}</style>
<script src="<?= htmlspecialchars(url('assets/js/theme-global.js?v=20261107-2'), ENT_QUOTES, 'UTF-8') ?>"></script>
<link rel="stylesheet" href="<?= e(url('assets/css/admin-unified-v1.css?v=1')) ?>">
</head>
<body><div class="layout"><aside class="sidebar"><?php require __DIR__ . '/../includes/brand-logo.php'; ?><div class="nav-label">ADMIN WORKSPACE</div><nav class="nav"><a href="<?= e(url('admin/index.php')) ?>">◫ Overview</a><a href="<?= e(url('admin/academics.php')) ?>">▤ Academics</a><a class="active" href="<?= e(url('admin/materials.php')) ?>">▣ Study Materials</a><a href="<?= e(url('admin/lecturers.php')) ?>">♧ Lecturers</a></nav></aside><main><header class="top"><div class="eyebrow">U LEARN / ADMIN PORTAL</div><button class="theme" type="button" id="themeButton" aria-label="Toggle light and dark mode" title="Toggle light and dark mode"><span id="themeIcon" aria-hidden="true">☾</span></button></header><div class="eyebrow">TEACHING RESOURCES</div><h1>Study Materials<span>.</span></h1><p class="muted">Manage learning resources across all academic modules, degrees, and batches.</p>
<?php if ($flash): ?><div class="flash <?= $flash[0]==='error'?'error':'' ?>" role="status"><?= e((string)$flash[1]) ?></div><?php endif; ?>
<div class="grid"><section class="card"><h2>Upload material</h2><?php if (!$assignments): ?><div class="empty">No active curriculum entries are available. Set up a degree, batch, and curriculum entry first.</div><?php else: ?><form method="post" enctype="multipart/form-data" action="<?= e(url('admin/materials.php')) ?>"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="upload"><label for="curriculum">Module, degree and batch</label><select id="curriculum" name="curriculum_id" required><option value="">Select a module</option><?php foreach ($assignments as $a): ?><option value="<?= (int)$a['curriculum_id'] ?>"><?= e($a['module_code'].' — '.$a['module_name'].' / '.$a['degree_name'].' / Batch '.$a['batch_code']) ?></option><?php endforeach; ?></select><label for="title">Title</label><input id="title" name="title" type="text" maxlength="200" required><label for="description">Description (optional)</label><textarea id="description" name="description" maxlength="5000"></textarea><label for="material">File</label><input id="material" name="material" type="file" accept=".pdf,.doc,.docx,.ppt,.pptx,.txt" required><p class="muted small">PDF, Word, PowerPoint or TXT; maximum 20 MB. Uploads start as drafts.</p><button type="submit">Upload material</button></form><?php endif; ?></section>
<section class="card"><h2>All study materials</h2><?php if (!$materials): ?><div class="empty">No study materials have been uploaded yet.</div><?php else: ?><?php foreach ($materials as $m): ?><article class="item"><strong><?= e($m['title']) ?></strong><span class="tag"><?= (int)$m['is_published']===1?'Published':'Draft' ?></span><p class="muted small"><?= e($m['module_code'].' · '.($m['degree_name'] ?? 'All degrees').' · Batch '.($m['batch_code'] ?? 'All').' · Uploaded by '.$m['uploader_name']) ?></p><?php if ($m['description']): ?><p class="muted small"><?= nl2br(e((string)$m['description'])) ?></p><?php endif; ?><p class="muted small"><?= e($m['original_filename']) ?> · <?= number_format((int)$m['file_size']/1048576,2) ?> MB</p><div class="actions"><a class="btn secondary" href="<?= e(url('admin/materials.php').'?download='.(int)$m['id']) ?>">Download</a><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><input type="hidden" name="action" value="<?= (int)$m['is_published']===1?'unpublish':'publish' ?>"><button type="submit" class="secondary"><?= (int)$m['is_published']===1?'Unpublish':'Publish' ?></button></form><form method="post" onsubmit="return confirm('Permanently delete this material?')"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><input type="hidden" name="action" value="delete"><button class="danger" type="submit">Delete</button></form></div></article><?php endforeach; ?><?php endif; ?></section></div></main></div><script>const themeButton=document.getElementById('themeButton');const themeIcon=document.getElementById('themeIcon');function updateThemeIcon(){const dark=document.documentElement.dataset.theme==='dark';themeIcon.textContent=dark?'☀':'☾';themeButton.setAttribute('aria-label',dark?'Switch to light mode':'Switch to dark mode');themeButton.title=dark?'Switch to light mode':'Switch to dark mode';}updateThemeIcon();themeButton.addEventListener('click',function(){const t=document.documentElement.dataset.theme==='dark'?'light':'dark';document.documentElement.dataset.theme=t;try{localStorage.setItem('ulearn-theme',t);}catch(e){}updateThemeIcon();});</script></body></html>
