<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';

function qa_require_user(): array {
    return require_login();
}
function qa_dashboard_url(): string {

    $user = require_login();

    if (($user['role'] ?? '') === 'lecturer') {
        return url('lecturer/index.php');
    }

    return url('dashboard/index.php');
}

function qa_redirect(string $path): never {
    go('dashboard/group-peer/' . $path);
}

function qa_post(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit('Method not allowed');
    }

    csrf_check();
}

function qa_image_upload(string $field = 'image'): ?array {

    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $f = $_FILES[$field];

    if (
        $f['error'] === UPLOAD_ERR_INI_SIZE ||
        $f['error'] === UPLOAD_ERR_FORM_SIZE
    ) {
        throw new RuntimeException('Image exceeds the server upload limit.');
    }

    if ($f['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Image upload failed.');
    }

    if ((int)$f['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('Maximum image size is 5 MB.');
    }

    if (!is_uploaded_file($f['tmp_name'])) {
        throw new RuntimeException('Invalid uploaded file.');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp'
    ];

    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Only JPG, PNG, GIF and WEBP images are allowed.');
    }

    $dir = __DIR__ . '/uploads';

    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create uploads folder.');
    }

    $name = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];

    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
        throw new RuntimeException('Could not save image.');
    }

    return [
        'path' => 'dashboard/group-peer/uploads/' . $name,
        'original_name' => (string)$f['name'],
        'mime_type' => $mime,
        'file_size' => (int)$f['size']
    ];
}

function qa_save_attachment(
    PDO $pdo,
    int $questionId,
    ?int $answerId,
    int $userId,
    array $file
): void {

    $sql = 'INSERT INTO qa_attachments
        (question_id, answer_id, uploaded_by, file_path, original_name, mime_type, file_size)
        VALUES (?, ?, ?, ?, ?, ?, ?)';

    $pdo->prepare($sql)->execute([
        $answerId === null ? $questionId : null,
        $answerId,
        $userId,
        $file['path'],
        $file['original_name'],
        $file['mime_type'],
        $file['file_size']
    ]);
}

function qa_image_url(string $path): string {
    return url($path);
}

function qa_h(string $value): string {
    return e($value);
}

function qa_header(string $title): void {
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title><?= e($title) ?> | Group Peer & Q&A</title>


<style>
:root {
    color-scheme: light;
    --page-bg: #f5f8f7;
    --panel: #fff;
    --text: #15263a;
    --muted: #607386;
    --border: #dce8e4;
    --accent: #087a51;
    --accent2: #055d42;
    --link: #087a51;
    --button: #087a51;
    --button-text: #fff;
    --tag-bg: #e7f5ee;
    --input-bg: #fff;
    --input-border: #cddbd6;
    --code-bg: #101c2d;
    --code-text: #f0f6fc;
    --shadow: 0 12px 36px rgba(12,38,32,.045);



html[data-theme="dark"] {
    color-scheme: dark;
    --page-bg: #0b1120;
    --panel: #141d2e;
    --text: #eff5f7;
    --muted: #a1b1c1;
    --border: #29364b;
    --accent: #43d29b;
    --accent2: #087a51;
    --link: #65e0ae;
    --button: #0d865c;
    --button-text: #fff;
    --tag-bg: #173b35;
    --input-bg: #101a2b;
    --input-border: #3a4b60;
    --code-bg: #090e18;
    --code-text: #f0f6fc;
    --shadow: 0 12px 36px rgba(0,0,0,.16);
}

* {
    box-sizing: border-box;
}

html {
    scroll-behavior: smooth;
}

body {
    font-family: Inter, ui-sans-serif, system-ui, -apple-system,
        "Segoe UI", sans-serif;
    background: var(--page-bg);
    color: var(--text);
    margin: 0;
    min-height: 100vh;
    line-height: 1.55;
    transition: background .2s, color .2s;
}

.wrap {
    max-width: 1120px;
    margin: 0 auto;
    padding: 34px 24px 75px;
}

.top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    flex-wrap: wrap;
    margin-bottom: 32px;
    padding: 18px 22px;
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 18px;
    box-shadow: var(--shadow);
}

.top h2 {
    font-size: 1.15rem;
    letter-spacing: -.03em;
    margin: 0;
    font-weight: 800;
}

.top h2 a {
    color: var(--text);
    text-decoration: none;
}

.top h2 a:before {
    content: '✦';
    color: var(--accent);
    margin-right: 10px;
}

.top-tools {
    display: flex;
    align-items: center;
    gap: 12px;
}

.top-tools svg {
    width: 18px;
    height: 18px;
    stroke: currentColor;
    stroke-width: 2;
    fill: none;
    stroke-linecap: round;
    stroke-linejoin: round;
}

.dashboard-link {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    text-decoration: none;
    color: var(--accent);
    font-weight: 650;
    font-size: .9rem;
    background: var(--tag-bg);
    padding: 9px 13px;
    border-radius: 10px;
}

.dashboard-link:hover {
    color: var(--accent);
}

.panel, .item {
    background: var(--panel);
    color: var(--text);
    border: 1px solid var(--border);
    border-radius: 20px;
    padding: 26px;
    margin: 16px 0;
    box-shadow: var(--shadow);
    transition: border-color .2s, transform .2s, box-shadow .2s;
}

.item:hover {
    border-color: var(--accent);
    transform: translateY(-2px);
}

h3 {
    margin: 10px 0;
    font-size: 1.17rem;
    letter-spacing: -.025em;
}

h3 a {
    color: var(--text);
    text-decoration: none;
}

h3 a:hover {
    color: var(--accent);
}

p {
    line-height: 1.65;
}

a {
    color: var(--link);
}

.btn, button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: var(--button);
    color: var(--button-text);
    border: 1px solid transparent;
    border-radius: 11px;
    padding: 11px 17px;
    text-decoration: none;
    cursor: pointer;
    font: inherit;
    font-size: .91rem;
    font-weight: 700;
    transition: transform .18s, background .18s, box-shadow .18s;
}

.btn:hover, button:hover {
    transform: translateY(-1px);
    background: var(--accent2);
}

.theme-toggle {
    background: var(--panel);
    color: var(--text);
    border: 1px solid var(--border);
    padding: 9px 11px;
    box-shadow: none;
}

.theme-toggle:hover {
    color: var(--accent);
    background: var(--tag-bg);
}

.muted {
    color: var(--muted);
    font-size: .91rem;
}

label {
    font-size: .9rem;
    font-weight: 700;
}

input, textarea, select {
    width: 100%;
    padding: 12px 14px;
    border: 1px solid var(--input-border);
    border-radius: 11px;
    margin: 7px 0 16px;
    background: var(--input-bg);
    color: var(--text);
    font: inherit;
    outline: none;
    transition: border-color .2s, box-shadow .2s;
}

input:focus, textarea:focus, select:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(17,155,104,.13);
}

input::placeholder, textarea::placeholder {
    color: var(--muted);
}

textarea {
    min-height: 140px;
    resize: vertical;
}

pre {
    background: var(--code-bg);
    color: var(--code-text);
    padding: 20px;
    border-radius: 13px;
    overflow: auto;
}

.tag {
    display: inline-flex;
    align-items: center;
    background: var(--tag-bg);
    color: var(--accent);
    padding: 6px 12px;
    border-radius: 100px;
    font-size: .8rem;
    font-weight: 700;
}

.error {
    color: #ef626b;
}

.success {
    color: #18a875;
}

img.attachment {
    max-width: 100%;
    height: auto;
    border-radius: 13px;
    margin-top: 14px;
}

.actions {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    margin: 13px 0;
}

.actions form {
    display: inline;
}

.actions select {
    width: auto;
    min-width: 155px;
    margin: 0;
}

.actions a, .actions button {
    font-weight: 700;
}

.actions a[href*="edit-"] {
    background: var(--tag-bg);
    color: var(--accent);
    border: 1px solid var(--border);
}

.actions form button {
    background: transparent;
    color: #d94c57;
    border: 1px solid var(--border);
}

.actions form button:hover {
    background: #d94c57;
    color: white;
}

.btn-upvote {
    background: var(--button);
}

.btn-downvote {
    background: #5c687a;
}

.btn-solved {
    background: #12875e;
}

form.panel .actions {
    margin-top: 3px;
}

form.panel .actions select {
    flex: 1;
    min-width: 135px;
}

::selection {
    background: rgba(32,177,122,.25);
}

.qa-nav {
    display: flex;
    align-items: center;
    gap: 7px;
    flex-wrap: wrap;
    margin: 0 0 24px;
    padding: 7px;
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 15px;
}

.qa-nav a {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
    color: var(--muted);
    font-size: .9rem;
    font-weight: 700;
    padding: 10px 15px;
    border-radius: 10px;
    transition: background .18s, color .18s;
}

.qa-nav a:hover,
.qa-nav a[aria-current="page"] {
    background: var(--tag-bg);
    color: var(--accent);
}

.qa-nav a:focus-visible,
.dashboard-link:focus-visible {
    outline: 3px solid var(--accent);
    outline-offset: 3px;
}

.qa-brand-sub {
    font-size: .76rem;
    color: var(--muted);
    font-weight: 600;
    letter-spacing: .04em;
    text-transform: uppercase;
}

/* Q&A usability improvements */

.qa-answer-cta {
    margin-left: auto;
    padding: 9px 14px;
    font-size: .86rem;
    white-space: nowrap;
}

.qa-page-intro {
    margin: 14px 0 26px;
}

.qa-page-intro h1 {
    font-size: clamp(1.65rem,3vw,2.25rem);
    letter-spacing: -.045em;
    margin: 8px 0;
}

.qa-page-intro p {
    margin: 0;
}

.qa-eyebrow {
    display: block;
    color: var(--accent);
    font-size: .72rem;
    font-weight: 800;
    letter-spacing: .13em;
    margin-top: 20px;
}

.qa-back {
    color: var(--muted);
    text-decoration: none;
    font-size: .9rem;
    font-weight: 700;
}

.qa-back:hover {
    color: var(--accent);
}

.qa-ask-form {
    padding: clamp(20px,4vw,38px);
}

.qa-step-label {
    display: flex;
    gap: 13px;
    align-items: flex-start;
    margin-bottom: 18px;
}

.qa-step-label h2 {
    margin: 0;
    font-size: 1.12rem;
    letter-spacing: -.025em;
}

.qa-step-label p {
    margin: 3px 0 0;
}

.qa-step-number {
    display: grid;
    place-items: center;
    background: var(--tag-bg);
    color: var(--accent);
    width: 34px;
    height: 34px;
    border-radius: 11px;
    font-weight: 800;
    flex-shrink: 0;
}

.qa-step-spaced {
    margin-top: 35px;
}

.qa-type-grid {
    display: grid;
    grid-template-columns: repeat(2,minmax(0,1fr));
    gap: 12px;
    border: 0;
    padding: 0;
    margin: 0;
}

.qa-type-card {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 5px;
    padding: 19px;
    border: 1.5px solid var(--border);
    border-radius: 15px;
    cursor: pointer;
    background: var(--input-bg);
    transition: border-color .18s, background .18s, transform .18s;
}

.qa-type-card:hover {
    border-color: var(--accent);
    transform: translateY(-1px);
}

.qa-type-card:has(input:checked) {
    border-color: var(--accent);
    background: var(--tag-bg);
    box-shadow: inset 0 0 0 1px var(--accent);
}

.qa-type-card:has(input:focus-visible) {
    outline: 3px solid var(--accent);
    outline-offset: 3px;
}

.qa-type-card input {
    position: absolute;
    right: 14px;
    top: 14px;
    width: 16px;
    height: 16px;
    accent-color: var(--accent);
    margin: 0;
}

.qa-type-icon {
    display: grid;
    place-items: center;
    width: 38px;
    height: 38px;
    background: var(--tag-bg);
    border-radius: 11px;
    color: var(--accent);
    font-size: 1.1rem;
    font-weight: 800;
}

.qa-type-card strong {
    font-size: .96rem;
}

.qa-type-card small {
    font-size: .8rem;
    color: var(--muted);
    font-weight: 500;
}

.qa-required {
    color: var(--accent);
}

.qa-optional-section {
    border-top: 1px solid var(--border);
    margin-top: 14px;
    padding-top: 20px;
}

.qa-optional-section[hidden] {
    display: none;
}

.qa-section-head {
    display: flex;
    gap: 10px;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 14px;
}

.qa-extra {
    margin: 12px 0 20px;
    border: 1px solid var(--border);
    border-radius: 13px;
    padding: 16px;
}

.qa-extra summary {
    cursor: pointer;
    font-weight: 700;
}

.qa-extra p {
    margin-bottom: 6px;
}

.qa-submit-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
    flex-wrap: wrap;
    border-top: 1px solid var(--border);
    padding-top: 22px;
}

.qa-submit-row button {
    margin-left: auto;
}

.qa-view-shortcuts {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin: 12px 0 20px;
}

.qa-answer-anchor {
    scroll-margin-top: 30px;
}

.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0,0,0,0);
    white-space: nowrap;
}

@media(max-width:650px) {
    .wrap {
        padding: 16px 13px 45px;
    }

    .top {
        padding: 15px;
        gap: 12px;
        margin-bottom: 22px;
    }

    .top h2 {
        font-size: 1rem;
    }

    .panel, .item {
        padding: 18px;
        border-radius: 15px;
    }

    .actions {
        gap: 8px;
    }

    .actions select {
        width: 100%;
    }

    form.panel .actions select {
        min-width: 100%;
    }

    .qa-nav {
        gap: 2px;
    }

    .qa-nav a {
        padding: 9px 11px;
        font-size: .82rem;
    }

    .qa-type-grid {
        grid-template-columns: 1fr;
    }

    .qa-answer-cta {
        margin-left: 0;
    }

    .qa-submit-row button {
        width: 100%;
    }
}

@media(prefers-reduced-motion:reduce) {
    *, *:before, *:after {
        transition: none !important;
        animation: none !important;
        scroll-behavior: auto !important;
    }
}
</style>

<!-- Use the same global theme system as the other ULearn pages. -->
<script src="<?= htmlspecialchars(
    url('assets/js/theme-global.js?v=20261109-3'),
    ENT_QUOTES,
    'UTF-8'
) ?>"></script>

<link rel="stylesheet" href="<?= e(url('assets/css/ulearn-community-phase4.css?v=1')) ?>">

</head>

<body>
<main class="wrap">

<div class="top">

    <h2>
        <a href="<?= e(url('dashboard/group-peer/index.php')) ?>"
           style="text-decoration:none">
            Group Peer & Q&A
        </a>
    </h2>

    <div class="top-tools">

        <button
            type="button"
            class="theme-toggle"
            id="qaThemeToggle"
            aria-label="Switch theme"
            title="Switch theme">
        </button>

        <a class="dashboard-link" href="<?= e(qa_dashboard_url()) ?>">
            <svg viewBox="0 0 24 24" width="20" height="20">
                <line x1="19" y1="12" x2="5" y2="12"></line>
                <polyline points="12 19 5 12 12 5"></polyline>
            </svg>
            Dashboard
        </a>

    </div>
</div>

<nav class="qa-nav" aria-label="Q&A navigation">

    <?php
    $qaPage = basename(
        (string)parse_url(
            $_SERVER['REQUEST_URI'] ?? '',
            PHP_URL_PATH
        )
    );
    ?>

    <a href="<?= e(url('dashboard/group-peer/index.php')) ?>"
       <?= $qaPage === 'index.php' && !isset($_GET['mine']) ? 'aria-current="page"' : '' ?>>
        Questions
    </a>

    <a href="<?= e(url('dashboard/group-peer/ask.php')) ?>"
       <?= $qaPage === 'ask.php' ? 'aria-current="page"' : '' ?>>
        Ask a Question
    </a>

    <a href="<?= e(url('dashboard/group-peer/index.php?mine=1')) ?>"
       <?= isset($_GET['mine']) ? 'aria-current="page"' : '' ?>>
        My Questions
    </a>

</nav>

<?php
}

function qa_footer(): void {
?>
</main>

<script>
function copyCode(id) {
    const el = document.getElementById(id);

    if (!el) return;

    navigator.clipboard.writeText(el.textContent)
        .then(() => alert('Code copied!'))
        .catch(() => alert('Copy failed.'));
}
</script>

<?php require_once __DIR__ . '/../../includes/student-bot.php'; ?>

</body>
</html>
<?php
}

function qa_attachments(PDO $pdo, string $column, int $id): array {

    if (!in_array($column, ['question_id', 'answer_id'], true)) {
        return [];
    }

    $q = $pdo->prepare(
        "SELECT * FROM qa_attachments
         WHERE {$column}=?
         ORDER BY id"
    );

    $q->execute([$id]);

    return $q->fetchAll();
}