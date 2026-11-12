<?php
declare(strict_types=1);
require_once __DIR__.'/../config/bootstrap.php';
$user=require_login();
if (!in_array((string)$user['role'],['admin','main_admin'],true)) {http_response_code(403);exit('Admin permission required.');}
$pdo=db();
function ps_e($s):string{return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
$stats=[];
foreach (['Students'=>"SELECT COUNT(*) FROM users WHERE role='student'",
'Lecturers'=>"SELECT COUNT(*) FROM users WHERE role='lecturer'",
'Modules'=>"SELECT COUNT(*) FROM academic_modules",
'Announcements'=>"SELECT COUNT(*) FROM announcements"] as $label=>$query) {
  try {$stats[$label]=(int)$pdo->query($query)->fetchColumn();}catch(Throwable $e){$stats[$label]=null;error_log('Platform settings overview: '.$e->getMessage());}
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ULearn · Platform Settings</title>
<style>
@import url("https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap");
:root{--bg:#f5f7fb;--card:#fff;--text:#14213a;--muted:#65748b;--line:#dce3ed;--green:#087d62;--tint:#e8f7f1}
:root[data-theme="dark"]{--bg:#0d1220;--card:#171f30;--text:#eef4ff;--muted:#b0bed1;--line:#334056;--green:#53d5ab;--tint:#163a34}
*{box-sizing:border-box}body{background:var(--bg);color:var(--text);margin:0;font:15px/1.55 Inter,system-ui,-apple-system,Segoe UI,sans-serif}
main{max-width:1250px;margin:auto;padding:32px 22px 70px}.top{display:flex;justify-content:space-between;gap:14px;align-items:center;flex-wrap:wrap}
a{color:var(--green)}.back{padding:10px 16px;border:1px solid var(--line);border-radius:12px;text-decoration:none;font-weight:700}
h1{font-size:clamp(28px,4vw,42px);letter-spacing:-.04em;margin:28px 0 6px}h2{font-size:21px;margin:0 0 8px}p{color:var(--muted);margin:0 0 20px}
.grid{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:18px;margin:24px 0}.card{grid-column:span 6;min-height:250px;background:var(--card);border:1px solid var(--line);border-radius:22px;padding:28px;min-width:0}
.controls{display:flex;gap:9px;flex-wrap:wrap}.controls button{cursor:pointer;padding:11px 19px;border-radius:11px;border:1px solid var(--line);background:var(--card);color:var(--text);font:inherit;font-weight:700}
.controls button[aria-pressed="true"]{background:var(--green);color:var(--bg);border-color:var(--green)}
.stat{display:flex;justify-content:space-between;border-top:1px solid var(--line);padding:11px 0}.stat strong{font-variant-numeric:tabular-nums}
.links{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.links a{display:block;border:1px solid var(--line);border-radius:12px;padding:13px;text-decoration:none;font-weight:650}.links a:hover{background:var(--tint)}
.note{border-left:3px solid var(--green);padding:10px 15px;background:var(--tint);border-radius:6px;color:var(--text)}
@media(max-width:700px){.grid,.links{grid-template-columns:1fr}.card{grid-column:1/-1}}

.eyebrow{font-size:12px;font-weight:800;letter-spacing:.16em;text-transform:uppercase;color:var(--green)}
.hero{position:relative;overflow:hidden;background:linear-gradient(115deg,#064b44,#087e66 65%,#087a89);border-radius:25px;padding:36px 38px;margin:30px 0 22px;color:white}
.hero:after{content:"";position:absolute;width:250px;height:250px;border:1px solid rgba(255,255,255,.18);border-radius:50%;right:-60px;top:-130px;box-shadow:0 0 0 60px rgba(255,255,255,.035),0 0 0 130px rgba(255,255,255,.025);pointer-events:none}
.hero h1{margin:9px 0;font-size:clamp(30px,4vw,48px);color:white}.hero p,.hero .eyebrow{color:#c8f4e8}.hero p{margin-bottom:0;max-width:650px}
.card{box-shadow:0 8px 30px rgba(11,35,59,.035);transition:transform .2s ease,box-shadow .2s ease}.card:hover{box-shadow:0 14px 40px rgba(11,35,59,.07)}
.card h2{letter-spacing:-.035em;display:flex;align-items:center;gap:11px;font-size:22px}
.icon-tile{width:40px;height:40px;border-radius:13px;display:inline-flex;align-items:center;justify-content:center;background:var(--tint);color:var(--green);flex-shrink:0}
.icon-tile svg{width:20px;height:20px;stroke:currentColor;fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
.controls button{min-width:108px;transition:transform .2s,background .2s,border-color .2s}.controls button:hover{transform:translateY(-2px);border-color:var(--green)}
.controls button[aria-pressed=true]{color:white}
.stat{align-items:center}.stat strong{font-size:23px;letter-spacing:-.05em;color:var(--green)}
.links a{transition:transform .2s,background .2s,border-color .2s;display:flex;align-items:center;justify-content:space-between}.links a:hover{transform:translateY(-2px);border-color:var(--green)}
.note{line-height:1.75}
@media(prefers-reduced-motion:reduce){*,*:before,*:after{transition:none!important}}
</style><link rel="stylesheet" href="<?= e(url('assets/css/admin-unified-v1.css?v=1')) ?>">
<script src="<?= htmlspecialchars(url('assets/js/theme-global.js?v=20261112-1'), ENT_QUOTES, 'UTF-8') ?>"></script>
</head><body><main>
<div class="top"><strong><span style="color:var(--green)">◈</span> ULEARN <span style="color:var(--muted);font-weight:500">/ ADMINISTRATION</span></strong><a class="back" href="<?=ps_e(url('admin/index.php'))?>">← Admin Dashboard</a></div>
<section class="hero"><div class="eyebrow">ADMINISTRATION / CONTROL CENTER</div><h1>Platform Settings<span style="color:#9ce9cd">.</span></h1><p>A unified space for your administration tools, workspace appearance and live platform insights.</p></section>
<div class="grid">
<section class="card"><h2><span class="icon-tile"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><circle cx="8" cy="9" r="1"/><circle cx="14" cy="7" r="1"/><circle cx="17" cy="12" r="1"/><path d="M12 21c-2-2-1-5 2-5h1"/></svg></span> Appearance</h2><p>Choose a preference for all ULearn pages in this browser. System follows your device settings.</p>
<div class="controls" role="group" aria-label="Appearance preference"><button type="button" data-theme-choice="light">☀️ Light</button><button type="button" data-theme-choice="dark">🌙 Dark</button><button type="button" data-theme-choice="system">💻 System</button></div>
<p style="margin-top:15px;font-size:13px">Preference is saved automatically.</p></section>
<section class="card"><h2><span class="icon-tile"><svg viewBox="0 0 24 24"><path d="M4 20V11h4v9M10 20V4h4v16M16 20v-7h4v7M3 20h18"/></svg></span> Platform overview</h2><p>Live counts from the existing database.</p>
<?php foreach($stats as $label=>$value):?><div class="stat"><span><?=ps_e($label)?></span><strong><?= $value===null?'Unavailable':number_format($value)?></strong></div><?php endforeach;?></section>
<section class="card"><h2><span class="icon-tile"><svg viewBox="0 0 24 24"><path d="M12 3v3m0 12v3M3 12h3m12 0h3M5.6 5.6l2.2 2.2m8.4 8.4 2.2 2.2m0-12.8-2.2 2.2m-8.4 8.4-2.2 2.2"/><circle cx="12" cy="12" r="4"/></svg></span> Administration tools</h2><p>Open existing tools without searching the dashboard.</p><div class="links">
<a href="<?=ps_e(url('admin/lecturers.php'))?>">Lecturers</a>
<a href="<?=ps_e(url('admin/academics.php'))?>">Academics</a>
<a href="<?=ps_e(url('admin/assignments.php'))?>">Assignments</a>
<a href="<?=ps_e(url('admin/announcements.php'))?>">Announcements</a>
<a href="<?=ps_e(url('admin/consultations.php'))?>">Consultations</a>
<a href="<?=ps_e(url('admin/kuppiya.php'))?>">Kuppiya Sessions</a>
<a href="<?=ps_e(url('admin/profile.php'))?>">My Profile</a>
<a href="<?=ps_e(url('admin/notes.php'))?>">Notes Review</a>
</div></section>
<section class="card"><h2><span class="icon-tile"><svg viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg></span> Configuration scope</h2><p>These settings are safe account/browser preferences and operational shortcuts.</p>
<div class="note">Site-wide settings such as registration rules, scoring rates and permissions are not editable here. Those require audited database-backed controls and explicit main-admin authorization.</div></section>
</div></main>
<script>
(function(){
 const buttons=[...document.querySelectorAll('[data-theme-choice]')];
 const selected=()=>{try{return localStorage.getItem('ulearn-theme-preference')||'system'}catch(e){return 'system'}};
 function paint(){const current=selected();buttons.forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.themeChoice===current)));}
 buttons.forEach(b=>b.addEventListener('click',()=>{try{localStorage.setItem('ulearn-theme-preference',b.dataset.themeChoice)}catch(e){};if(window.ULearnTheme?.setPreference){window.ULearnTheme.setPreference(b.dataset.themeChoice)}else{const value=b.dataset.themeChoice;document.documentElement.dataset.theme=value==='system'?(matchMedia('(prefers-color-scheme:dark)').matches?'dark':'light'):value};paint()}));
 paint();
})();
</script>
</body></html>
