<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../includes/ai-helper.php';
$user = require_login();
if (($user['role'] ?? '') !== 'lecturer') { http_response_code(403); exit('Lecturer access only.'); }
$error = ''; $generated = ''; $source = ''; $count = 5; $difficulty = 'Medium';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $source = trim((string)($_POST['source'] ?? ''));
    $count = (int)($_POST['count'] ?? 5);
    $difficulty = (string)($_POST['difficulty'] ?? 'Medium');
    try {
        if (mb_strlen($source) < 30 || mb_strlen($source) > 20000) throw new RuntimeException('Enter 30–20,000 characters of notes.');
        if ($count < 1 || $count > 20) throw new RuntimeException('Choose 1–20 questions.');
        if (!in_array($difficulty, ['Easy','Medium','Hard'], true)) throw new RuntimeException('Invalid difficulty.');
        $prompt = "Create exactly $count accurate $difficulty multiple-choice questions based ONLY on the reference notes below. If the notes do not support an answer, do not invent facts. Output PLAIN TEXT ONLY, no markdown fences. Each question MUST follow this exact format (with consecutive question numbers):\nQ1: Question?\nA) option\nB) option\nC) option\nD) option\nCorrect: B\nTopic: Short topic\nDifficulty: $difficulty\nExplanation: Explain why the correct answer is right.\nReason A: Explain option A.\nReason B: Explain option B.\nReason C: Explain option C.\nReason D: Explain option D.\n\nSeparate questions with a blank line. All four options must be plausible and only one correct.\n\nReference notes:\n" . $source;
        $generated = trim(ulearnGemini($prompt));
        if (!preg_match('/^Q1\s*:/im', $generated)) throw new RuntimeException('AI returned unexpected formatting. Try generating again.');
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Gemini AI Quiz Studio · ULearn</title><link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
<style>
:root{--ai-ink:#20213d;--ai-purple:#6258e8;--ai-line:#e7e8f2;--ai-muted:#73768c}
body{background:#f7f8fc;color:var(--ai-ink);margin:0;font-family:Manrope,'DM Sans',system-ui,sans-serif}
.ai-shell{max-width:1140px;margin:0 auto;padding:28px 24px 80px}
.ai-top{display:flex;justify-content:space-between;align-items:center;gap:20px;margin-bottom:28px;flex-wrap:wrap}
.ai-brand{display:flex;align-items:center;gap:12px;font-weight:800;font-size:21px;letter-spacing:-.7px}
.ai-brand-mark{display:grid;place-items:center;width:40px;height:40px;border-radius:13px;color:#fff;background:linear-gradient(145deg,#8077f4,#5148d0)}
.ai-back{display:inline-flex;align-items:center;gap:8px;color:#535a7a;text-decoration:none;font-weight:700;font-size:13px;border:1px solid var(--ai-line);background:white;padding:11px 15px;border-radius:12px}
.ai-hero{position:relative;overflow:hidden;background:linear-gradient(115deg,#22234f,#4439a1 67%,#695df0);color:white;border-radius:25px;padding:42px 44px;margin-bottom:24px}
.ai-hero:after{content:'✦';position:absolute;right:50px;top:-46px;font-size:250px;color:rgba(255,255,255,.075);pointer-events:none}
.ai-eyebrow{font-size:12px;letter-spacing:2px;font-weight:800;text-transform:uppercase;color:#c8c3ff}
.ai-hero h1{font-size:clamp(29px,4vw,45px);line-height:1.15;letter-spacing:-1.7px;margin:14px 0 12px;max-width:690px}
.ai-hero p{max-width:640px;color:#e4e2ff;line-height:1.7;margin:0}
.ai-pill{display:inline-flex;background:rgba(255,255,255,.13);border:1px solid rgba(255,255,255,.2);padding:8px 13px;border-radius:99px;margin-top:24px;font-size:12px;font-weight:800}
.ai-grid{display:grid;grid-template-columns:minmax(0,1fr) 285px;gap:22px;align-items:start}
.ai-card{background:white;border:1px solid var(--ai-line);border-radius:20px;padding:28px;box-shadow:0 9px 30px rgba(29,30,80,.035)}
.ai-title{font-size:20px;letter-spacing:-.5px;font-weight:800;margin:0 0 7px}.ai-sub{color:var(--ai-muted);font-size:13px;line-height:1.65;margin:0 0 24px}
.ai-label{font-size:13px;font-weight:800;display:block;margin-bottom:10px}.ai-textarea{width:100%;box-sizing:border-box;min-height:255px;padding:18px;border:1px solid #dfe1ed;border-radius:14px;background:#fcfcff;color:#262746;font:14px/1.7 inherit;resize:vertical;outline:none}
.ai-textarea:focus,.ai-input:focus{border-color:#8077f4;box-shadow:0 0 0 3px rgba(98,88,232,.12)}
.ai-meta{display:flex;justify-content:space-between;color:#8b8da2;font-size:12px;margin-top:8px;gap:12px}
.ai-settings{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-top:24px}
.ai-input{width:100%;box-sizing:border-box;padding:13px 14px;border-radius:12px;border:1px solid #dfe1ed;background:white;color:#292a46;font:inherit;outline:none}
.ai-action{display:flex;align-items:center;justify-content:center;gap:10px;background:var(--ai-purple);color:white;border:0;border-radius:12px;padding:15px 23px;font-weight:800;font:inherit;cursor:pointer;text-decoration:none;margin-top:24px;width:100%;box-sizing:border-box}
.ai-action:hover{background:#4c43ce}.ai-action:disabled{opacity:.65;cursor:wait}
.ai-side-title{font-size:14px;font-weight:800;margin:0 0 15px}.ai-step{display:flex;gap:12px;margin:0 0 20px}.ai-step-num{flex:none;width:29px;height:29px;border-radius:10px;background:#f0eeff;color:#5e52d8;display:grid;place-items:center;font-size:12px;font-weight:800}.ai-step strong{font-size:13px}.ai-step p{font-size:12px;color:var(--ai-muted);line-height:1.6;margin:5px 0 0}
.ai-notice{border:1px solid #dcd8ff;background:#f7f5ff;border-radius:15px;padding:17px;color:#55518a;font-size:12px;line-height:1.7;margin-top:16px}
.ai-error{background:#fff1f0;color:#9d2823;border:1px solid #ffd5d2;border-radius:12px;padding:13px 15px;margin-top:16px;font-size:13px}
.ai-result-card{margin-top:22px}.ai-result-text{min-height:330px;font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:13px}.ai-result-actions{display:flex;gap:12px;flex-wrap:wrap}.ai-result-actions .ai-action{width:auto;flex:1;margin-top:18px}.ai-outline{background:#fff;color:#534bcb;border:1px solid #d9d5ff}.ai-outline:hover{background:#f4f2ff}.ai-footer{font-size:12px;color:#8b8da2;margin-top:25px;text-align:center}
@media(max-width:800px){.ai-grid{grid-template-columns:1fr}.ai-hero{padding:30px 25px}.ai-card{padding:22px}.ai-settings{grid-template-columns:1fr}.ai-shell{padding:18px 14px 50px}}
.ai-layout{display:grid;grid-template-columns:250px minmax(0,1fr);min-height:100vh}.ai-nav{background:#fff;border-right:1px solid #e5e6f1;padding:28px 15px}.ai-nav-brand{display:block;font-size:27px;font-weight:850;color:#292744;text-decoration:none;margin:0 12px 32px}.ai-nav-brand span{color:#6556e8}.ai-nav-title{font-size:11px;letter-spacing:1.5px;color:#8889a1;text-transform:uppercase;margin:0 12px 14px;font-weight:800}.ai-nav a:not(.ai-nav-brand){display:block;padding:12px 14px;border-radius:12px;color:#66677e;text-decoration:none;font-size:13px;font-weight:700;margin-bottom:6px}.ai-nav a:hover,.ai-nav a.current{background:#efedff;color:#5d50d8}.ai-nav a.nested{margin-left:16px;font-size:12px}.ai-shell{min-width:0}@media(max-width:850px){.ai-layout{grid-template-columns:1fr}.ai-nav{border-right:0;border-bottom:1px solid #e5e6f1;padding:14px}.ai-nav-links{display:flex;flex-wrap:wrap;gap:4px}.ai-nav a:not(.ai-nav-brand){margin:0}.ai-nav-brand{margin:0 12px 10px}}
</style>
<script src="<?= htmlspecialchars(url('assets/js/theme-global.js?v=20261110-3'), ENT_QUOTES, 'UTF-8') ?>"></script>
<link rel="stylesheet" href="<?= e(url('assets/css/ulearn-design-system.css?v=1')) ?>">
<script defer src="<?= e(url('assets/js/ulearn-motion.js?v=1')) ?>"></script>
</head><body><div class="ai-layout"><aside class="ai-nav"><a class="ai-nav-brand" href="<?= e(url('lecturer/index.php')) ?>">u learn<span>.</span></a><div class="ai-nav-title">Lecturer workspace</div><nav class="ai-nav-links" aria-label="Lecturer navigation"><a href="<?= e(url('lecturer/index.php')) ?>">▦ Overview</a><a href="<?= e(url('lecturer/index.php#modules')) ?>">▤ My modules</a><a href="<?= e(url('lecturer/materials.php')) ?>">▣ Study materials</a><a href="<?= e(url('lecturer/quizzes.php')) ?>">▣ Quiz management</a><a class="nested current" aria-current="page" href="<?= e(url('lecturer/gemini-test.php')) ?>">✦ Generate quiz with AI</a><a href="<?= e(url('lecturer/consultations.php')) ?>">◷ Consultations</a><a href="<?= e(url('lecturer/profile.php')) ?>">⚙ My profile</a></nav></aside><main class="ai-shell">
<header class="ai-top">
    <div class="ai-brand">
        <span class="ai-brand-mark">✦</span>
        ULearn 
        <span style="font-size:13px;color:#8c8da6;font-weight:700">/ AI Studio</span>
    </div>
    <a class="ai-back" href="<?= e(url('lecturer/index.php')) ?>">← Lecturer dashboard</a>
    <?php require __DIR__ . '/../includes/theme-toggle-button.php'; ?>
</header>
<section class="ai-hero"><div class="ai-eyebrow">Teaching tools · Powered by Gemini</div><h1>Turn your lecture notes into better quizzes.</h1><p>Create multiple-choice questions in seconds, review every answer, and bring the finished set into your existing ULearn quiz workflow.</p><span class="ai-pill">✦ AI-assisted · Lecturer reviewed</span></section>
<div class="ai-grid"><section class="ai-card"><h2 class="ai-title">Create a question set</h2><p class="ai-sub">Paste lecture material, choose your preferences and let Gemini prepare a first draft.</p>
<form method="post" id="ai-form"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
<label class="ai-label" for="source">Lecture notes or reference material</label><textarea class="ai-textarea" id="source" name="source" maxlength="20000" minlength="30" required placeholder="Paste at least 30 characters of lecture notes here..." oninput="document.getElementById('char-count').textContent=this.value.length.toLocaleString() + ' / 20,000 characters' "><?= e($source) ?></textarea><div class="ai-meta"><span>Use specific, factual notes for stronger questions.</span><span id="char-count"><?= mb_strlen($source) ?> / 20,000 characters</span></div>
<div class="ai-settings"><div><label class="ai-label" for="count">Number of questions</label><input class="ai-input" id="count" name="count" type="number" min="1" max="20" value="<?= (int)$count ?>" required></div><div><label class="ai-label" for="difficulty">Difficulty level</label><select class="ai-input" id="difficulty" name="difficulty"><?php foreach (['Easy','Medium','Hard'] as $level): ?><option value="<?= e($level) ?>" <?= $difficulty === $level ? 'selected' : '' ?>><?= e($level) ?></option><?php endforeach; ?></select></div></div>
<button class="ai-action" type="submit" id="generate-button">✦ Generate quiz questions</button></form>
<?php if ($error !== ''): ?><div class="ai-error" role="alert"><?= e($error) ?></div><?php endif; ?>
</section><aside><div class="ai-card"><h3 class="ai-side-title">How it works</h3><div class="ai-step"><span class="ai-step-num">01</span><div><strong>Add your content</strong><p>Paste lecture notes or material you want students to learn.</p></div></div><div class="ai-step"><span class="ai-step-num">02</span><div><strong>Generate with Gemini</strong><p>Choose the difficulty and number of questions.</p></div></div><div class="ai-step"><span class="ai-step-num">03</span><div><strong>Review and edit</strong><p>Correct any errors before using the questions.</p></div></div><div class="ai-step" style="margin-bottom:0"><span class="ai-step-num">04</span><div><strong>Publish in ULearn</strong><p>Paste into Quiz Management, preview, select degree and intake, then publish.</p></div></div></div><div class="ai-notice"><strong>You're in control.</strong> Gemini never publishes questions automatically. Always verify the correct answers and explanations before sharing with students.</div></aside></div>
<?php if ($generated !== ''): ?><section class="ai-card ai-result-card" id="generated-results"><h2 class="ai-title">Your generated questions are ready</h2><p class="ai-sub">You can edit the text below. Copy it into Quiz Management → Paste question set → Preview pasted questions.</p><label class="ai-label" for="result">Editable question set</label><textarea id="result" class="ai-textarea ai-result-text" spellcheck="true"><?= e($generated) ?></textarea><div class="ai-result-actions"><button class="ai-action" type="button" id="copy-button">Copy question set</button><a class="ai-action ai-outline" href="<?= e(url('lecturer/quizzes.php')) ?>">Open Quiz Management →</a></div></section><?php endif; ?>
<div class="ai-footer">ULearn AI Studio · Built for better learning</div></main><script>
(function(){const form=document.getElementById('ai-form'),button=document.getElementById('generate-button');if(form&&button)form.addEventListener('submit',function(){if(form.checkValidity()){button.disabled=true;button.textContent='✦ Generating questions…';}});const copy=document.getElementById('copy-button');if(copy)copy.addEventListener('click',async function(){const area=document.getElementById('result');try{await navigator.clipboard.writeText(area.value);copy.textContent='✓ Copied to clipboard';}catch(e){area.focus();area.select();copy.textContent='Select text and press Ctrl+C';}});})();
</script></div></body></html>
