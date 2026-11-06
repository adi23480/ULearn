<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/bootstrap.php';

function qa_require_user(): array { return require_login(); }
function qa_redirect(string $path): never { go('dashboard/group-peer/' . $path); }
function qa_post(): void { if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed'); } csrf_check(); }
function qa_image_upload(string $field = 'image'): ?array {
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) return null;
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Image upload failed.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $allowed = ['image/jpeg'=>'jpg', 'image/png'=>'png', 'image/gif'=>'gif', 'image/webp'=>'webp'];
    if (!isset($allowed[$mime])) throw new RuntimeException('Only JPG, PNG, GIF and WEBP images are allowed.');
    $dir = __DIR__ . '/uploads';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) throw new RuntimeException('Cannot create uploads folder.');
    $name = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) throw new RuntimeException('Could not save image.');
    return ['path'=>'dashboard/group-peer/uploads/' . $name, 'original_name'=>(string)$f['name'], 'mime_type'=>$mime, 'file_size'=>(int)$f['size']];
}
function qa_save_attachment(PDO $pdo, int $questionId, ?int $answerId, int $userId, array $file): void {
    $sql = 'INSERT INTO qa_attachments (question_id, answer_id, uploaded_by, file_path, original_name, mime_type, file_size) VALUES (?, ?, ?, ?, ?, ?, ?)';
    $pdo->prepare($sql)->execute([$answerId === null ? $questionId : null, $answerId, $userId, $file['path'], $file['original_name'], $file['mime_type'], $file['file_size']]);
}
function qa_image_url(string $path): string { return url($path); }
function qa_h(string $value): string { return e($value); }
function qa_header(string $title): void {
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= e($title) ?> | Group Peer &amp; Q&amp;A</title><style>/* Upvote button */
.btn-upvote {
    background: #2563eb;
    color: white;
    margin: 5px;
    transition: 0.3s ease;
}

.btn-upvote:hover {
    background: #1d4ed8;
    transform: translateY(-2px);
}

/* Downvote button */
.btn-downvote {
    background: #dc2626;
    color: white;
    margin: 5px;
    transition: 0.3s ease;
}

.btn-downvote:hover {
    background: #b91c1c;
    transform: translateY(-2px);
}

/* Mark as solved button */
.btn-solved {
    background: #16a34a;
    color: white;
    margin: 5px;
    transition: 0.3s ease;
}

.btn-solved:hover {
    background: #15803d;
    transform: translateY(-2px);
}
    :root{color-scheme:light;--accent:#7c3aed;--page-bg:#f5f7fb;--text:#172033;--muted:#687386;--panel:#fff;--border:#e2e7f0;--link:#2457b2;--button:#2457b2;--button-text:#fff;--tag-bg:#eaf0ff;--input-bg:#fff;--input-border:#ccd4e0;--code-bg:#101827;--code-text:#e8eef8;--shadow:0 2px 10px rgba(20,35,60,.04)}
    html[data-theme="dark"]{color-scheme:dark;--accent:#a78bfa;--page-bg:#111827;--text:#e5e7eb;--muted:#a5afbf;--panel:#1f2937;--border:#374151;--link:#93c5fd;--button:#2563eb;--button-text:#fff;--tag-bg:#263b60;--input-bg:#111827;--input-border:#4b5563;--code-bg:#080d18;--code-text:#e8eef8;--shadow:0 2px 12px rgba(0,0,0,.18)}
    *{box-sizing:border-box}body{font-family:Arial,sans-serif;background:var(--page-bg);color:var(--text);margin:0;min-height:100vh;transition:background .2s,color .2s}.wrap{max-width:1000px;margin:28px auto;padding:0 16px}.top{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}.top h2{margin:8px 0}.top a{color:var(--link)}.top-tools{display:flex;align-items:center;gap:12px;flex-wrap:wrap}.panel,.item{background:var(--panel);color:var(--text);border:1px solid var(--border);border-radius:12px;padding:18px;margin:14px 0;box-shadow:var(--shadow)}a{color:var(--link)}.btn,button{display:inline-block;background:var(--button);color:var(--button-text);border:0;border-radius:8px;padding:9px 14px;text-decoration:none;cursor:pointer;font:inherit}.theme-toggle{background:var(--panel);color:var(--text);border:1px solid var(--border);padding:8px 12px;display:flex;align-items:center;justify-content:center;cursor:pointer}.muted{color:var(--muted);font-size:.92rem}input,textarea,select{box-sizing:border-box;width:100%;padding:10px;border:1px solid var(--input-border);border-radius:8px;margin:6px 0 12px;background:var(--input-bg);color:var(--text)}input::placeholder,textarea::placeholder{color:var(--muted)}textarea{min-height:120px}pre{background:var(--code-bg);color:var(--code-text);padding:14px;border-radius:8px;overflow:auto}.tag{display:inline-block;background:var(--tag-bg);color:var(--text);padding:4px 8px;border-radius:20px;font-size:.8rem}.error{color:#f87171}.success{color:#34d399}img.attachment{max-width:100%;height:auto;border-radius:8px;margin-top:10px}.actions{display:flex;gap:10px;flex-wrap:wrap}.actions form{display:inline}.actions select{width:auto;min-width:150px}
    .top-tools svg { width: 20px; height: 20px; stroke: currentColor; stroke-width: 2; fill: none; stroke-linecap: round; stroke-linejoin: round; }
    .dashboard-link { display: inline-flex; align-items: center; gap: 6px; text-decoration: none; }
    .actions a,
.actions button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 9px 16px;
    border-radius: 8px;
    text-decoration: none;
    font-size: 14px;
    font-weight: 600;
    transition: .2s ease;
}

/* Edit button */
.actions a[href*="edit-question"],
.actions a[href*="edit-answer"] {
    background: #f0edff;
    color: #6856e8;
    border: 1px solid #6856e8;
}

.actions a[href*="edit-question"]:hover,
.actions a[href*="edit-answer"]:hover {
    background: #6856e8;
    color: white;
}

/* Delete button */
.actions form button {
    background: #fff0f0;
    color: #dc3545;
    border: 1px solid #dc3545;
}

.actions form button:hover {
    background: #dc3545;
    color: white;
}</style></head><body><main class="wrap"><div class="top"><h2><a href="<?= e(url('dashboard/group-peer/index.php')) ?>" style="text-decoration:none">Group Peer &amp; Q&amp;A</a></h2><div class="top-tools">
    <button type="button" class="theme-toggle" id="qaThemeToggle" aria-label="Switch theme"></button>
    <a class="dashboard-link" href="<?= e(url('dashboard/index.php')) ?>">
        <svg viewBox="0 0 24 24" width="20" height="20"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
        Dashboard
    </a>
    </div></div><?php
}
function qa_footer(): void { ?></main><script>
(function(){
 const key='ulearn-theme';
 const root=document.documentElement;
 const toggle=document.getElementById('qaThemeToggle');
 function preference(){try{return localStorage.getItem(key)||'system'}catch(e){return 'system'}}
 function applyTheme(choice){
   const actual=choice==='system'?(window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'):choice;
   root.dataset.theme=actual;
   if(toggle){
     if(actual==='dark'){
       toggle.innerHTML='<svg viewBox="0 0 24 24" width="20" height="20"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>';
       toggle.setAttribute('aria-label','Switch to light mode');
     } else {
       toggle.innerHTML='<svg viewBox="0 0 24 24" width="20" height="20"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>';
       toggle.setAttribute('aria-label','Switch to dark mode');
     }
   }
 }
 applyTheme(preference());
 if(toggle){toggle.addEventListener('click',function(){const next=root.dataset.theme==='dark'?'light':'dark';try{localStorage.setItem(key,next)}catch(e){}applyTheme(next);});}
 if(window.matchMedia){const media=window.matchMedia('(prefers-color-scheme: dark)');if(media.addEventListener){media.addEventListener('change',function(){if(preference()==='system')applyTheme('system')})}}
})();
function copyCode(id){const el=document.getElementById(id);if(!el)return;navigator.clipboard.writeText(el.textContent).then(()=>alert('Code copied!')).catch(()=>alert('Copy failed.'));}
</script></body></html><?php }
function qa_attachments(PDO $pdo, string $column, int $id): array {
    if (!in_array($column, ['question_id','answer_id'], true)) return [];
    $q=$pdo->prepare("SELECT * FROM qa_attachments WHERE {$column}=? ORDER BY id");$q->execute([$id]);return $q->fetchAll();
}