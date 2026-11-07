<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$admin = require_login();

if (!in_array($admin['role'], ['admin', 'main_admin'], true)) {
    http_response_code(403);
    exit('Access denied. Admin permission required.');
}

$pdo = db();
$error = '';
$success = '';

function lecturerRedirect(): never
{
    go('admin/lecturers.php');
}

if (isset($_SESSION['lecturer_success'])) {
    $success = (string)$_SESSION['lecturer_success'];
    unset($_SESSION['lecturer_success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'invite') {
            require_once __DIR__ . '/../config/otp-mailer.php';
            $email = strtolower(trim((string)($_POST['email'] ?? '')));
            $title = (string)($_POST['title'] ?? 'Mr.');
            $department = trim((string)($_POST['department'] ?? ''));
            $designation = trim((string)($_POST['designation'] ?? ''));
            $universityId=(int)($_POST['university_id']??0); $degreeId=(int)($_POST['degree_id']??0);
            $batchId=(int)($_POST['batch_id']??0); $studyYear=(int)($_POST['study_year']??0);
            $semester=(int)($_POST['semester']??0);
            if (!$universityId || !$degreeId || !$batchId || $studyYear<1 || $studyYear>6 || !in_array($semester,[1,2],true)) throw new RuntimeException('Select university, programme, intake, year and semester.');
            if (!$department || !$designation) throw new RuntimeException('Select a department and designation.');
            $scope=$pdo->prepare('SELECT COUNT(*) FROM academic_degrees d JOIN academic_degree_intakes adi ON adi.degree_id=d.id JOIN academic_batches b ON b.id=adi.batch_id WHERE d.id=? AND d.awarding_university_id=? AND b.id=? AND adi.is_active=1 AND d.is_active=1 AND b.is_active=1 AND d.duration_years>=?');
            $scope->execute([$degreeId,$universityId,$batchId,$studyYear]);
            if (!(int)$scope->fetchColumn()) throw new RuntimeException('This programme/intake/year combination is unavailable.');
            // Programme-level invitation does not require a curriculum/module row.
            // Module assignments are managed separately after lecturer activation.
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid email.');
            if (!in_array($title, ['Mr.','Mrs.','Ms.','Dr.','Prof.'], true)) throw new RuntimeException('Invalid title.');
            if (mb_strlen($department)>150 || mb_strlen($designation)>150) throw new RuntimeException('Department/designation too long.');
            $exists=$pdo->prepare('SELECT id FROM users WHERE email=? LIMIT 1');$exists->execute([$email]);
            if ($exists->fetchColumn()) throw new RuntimeException('An account already uses this email. Invite a different email; existing accounts are never silently promoted.');
            $q=$pdo->prepare('SELECT sent_at FROM lecturer_invitations WHERE email=? LIMIT 1');$q->execute([$email]);$previous=$q->fetchColumn();
            if ($previous && strtotime((string)$previous)>time()-60) throw new RuntimeException('Wait 60 seconds before resending.');
            $code=(string)random_int(100000,999999);
            $hash=password_hash($code,PASSWORD_DEFAULT);
            $q=$pdo->prepare('INSERT INTO lecturer_invitations(email,code_hash,title,department,designation,invited_by,university_id,degree_id,batch_id,study_year,semester,expires_at,sent_at,attempts) VALUES (?,?,?,?,?,?,?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 24 HOUR),NOW(),0) ON DUPLICATE KEY UPDATE code_hash=VALUES(code_hash),title=VALUES(title),department=VALUES(department),designation=VALUES(designation),invited_by=VALUES(invited_by),university_id=VALUES(university_id),degree_id=VALUES(degree_id),batch_id=VALUES(batch_id),study_year=VALUES(study_year),semester=VALUES(semester),expires_at=VALUES(expires_at),sent_at=NOW(),attempts=0,used_at=NULL');
            $q->execute([$email,$hash,$title,$department,$designation,(int)$admin['id'],$universityId,$degreeId,$batchId,$studyYear,$semester]);
            try { sendUlearnLecturerInvitation($email, $code, ulearnLecturerActivationUrl()); }
            catch(Throwable $mailError) { error_log('Lecturer invitation delivery failed: '.$mailError->getMessage());throw new RuntimeException('Invitation could not be emailed. Check mail settings and try again.'); }
            $_SESSION['lecturer_success']='Invitation email requested for '.$email.'. It includes the activation code and setup link. Check inbox or spam.';
            lecturerRedirect();
        }

        if ($action === 'toggle') {
            $lecturerId = filter_var(
                $_POST['lecturer_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            if (!$lecturerId || $lecturerId < 1) {
                throw new RuntimeException('Invalid lecturer.');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                "SELECT lp.id, lp.is_active, u.full_name, u.role
                 FROM lecturer_profiles lp
                 JOIN users u ON u.id = lp.user_id
                 WHERE lp.id = ?
                 LIMIT 1
                 FOR UPDATE"
            );

            $stmt->execute([$lecturerId]);
            $lecturer = $stmt->fetch();

            if (!$lecturer || $lecturer['role'] !== 'lecturer') {
                throw new RuntimeException('Lecturer not found.');
            }

            $newStatus = (int)$lecturer['is_active'] === 1 ? 0 : 1;

            $stmt = $pdo->prepare(
                "UPDATE lecturer_profiles
                 SET is_active = ?
                 WHERE id = ?"
            );

            $stmt->execute([$newStatus, $lecturerId]);

            $pdo->commit();

            $_SESSION['lecturer_success'] =
                $lecturer['full_name'] .
                ($newStatus ? ' activated.' : ' suspended.');

            lecturerRedirect();
        }

        throw new RuntimeException('Unknown action.');

    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($exception instanceof RuntimeException) {
            $error = $exception->getMessage();
        } else {
            error_log('Lecturer management error: ' . $exception->getMessage());
            $error = 'Something went wrong. Please try again.';
        }
    }
}

$stmt = $pdo->query(
    "SELECT
        lp.id,
        lp.title,
        lp.department,
        lp.designation,
        lp.is_active,
        u.full_name,
        u.email,
        u.email_verified_at,
        (
            SELECT COUNT(*)
            FROM lecturer_module_assignments lma
            WHERE lma.lecturer_id = lp.id
              AND lma.is_active = 1
        ) AS module_count
     FROM lecturer_profiles lp
     JOIN users u ON u.id = lp.user_id
     WHERE u.role = 'lecturer'
     ORDER BY lp.is_active DESC, u.full_name ASC"
);

$lecturers = $stmt->fetchAll();
$activeCount = count(array_filter(
    $lecturers,
    fn($item) => (int)$item['is_active'] === 1
));

$universities=$pdo->query('SELECT id,name FROM awarding_universities ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$degrees=$pdo->query('SELECT id,name,awarding_university_id AS university_id,duration_years FROM academic_degrees WHERE is_active=1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$intakes=$pdo->query('SELECT adi.degree_id,adi.batch_id,b.batch_code FROM academic_degree_intakes adi JOIN academic_batches b ON b.id=adi.batch_id WHERE adi.is_active=1 AND b.is_active=1 ORDER BY b.batch_code DESC')->fetchAll(PDO::FETCH_ASSOC);
// Invitation years are derived from each degree's duration; semesters 1 and 2 are available for each year.
// Use existing department/designation records where available, plus common starter options.
$departments=array_values(array_unique(array_merge(['Computing','Engineering','Business','Information Technology'],array_filter($pdo->query("SELECT DISTINCT department FROM lecturer_profiles WHERE department IS NOT NULL AND department<>''")->fetchAll(PDO::FETCH_COLUMN)))));
$designations=array_values(array_unique(array_merge(['Assistant Lecturer','Lecturer','Senior Lecturer','Visiting Lecturer','Professor'],array_filter($pdo->query("SELECT DISTINCT designation FROM lecturer_profiles WHERE designation IS NOT NULL AND designation<>''")->fetchAll(PDO::FETCH_COLUMN)))));
sort($departments);sort($designations);
$theme = (string)($admin['theme'] ?? 'system');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Manage Lecturers — ULearn</title>
<script>
const preferredTheme = <?= json_encode($theme) ?>;
const savedTheme = localStorage.getItem('ulearn-theme');
const systemDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
document.documentElement.dataset.theme = ['light','dark'].includes(savedTheme) ? savedTheme : (['light','dark'].includes(preferredTheme) ? preferredTheme : (systemDark ? 'dark' : 'light'));
function toggleTheme(){const next=document.documentElement.dataset.theme==='dark'?'light':'dark';document.documentElement.dataset.theme=next;localStorage.setItem('ulearn-theme',next);updateThemeButton();}
function updateThemeButton(){const b=document.getElementById('themeButton');if(b){const dark=document.documentElement.dataset.theme==='dark';b.textContent=dark?'☀':'☾';b.setAttribute('aria-label',dark?'Switch to light mode':'Switch to dark mode');}}
document.addEventListener('DOMContentLoaded',updateThemeButton);
</script>
<style>
:root{color-scheme:light;--bg:#f5f6fc;--card:rgba(255,255,255,.88);--solid:#fff;--text:#171827;--muted:#74798e;--line:rgba(111,116,150,.18);--accent:#7157f2;--accent2:#5d43e6;--soft:#f0edff;--input:#f8f8fd;--shadow:0 24px 70px rgba(37,34,86,.08);--okbg:#e8fbf2;--ok:#176b49;--badbg:#fff0f2;--bad:#a52b47}
[data-theme=dark]{color-scheme:dark;--bg:#0b0c14;--card:rgba(24,26,39,.9);--solid:#181a27;--text:#f5f5fb;--muted:#a2a7bd;--line:rgba(169,171,207,.15);--accent:#aa98ff;--accent2:#8d75ff;--soft:#29233f;--input:#202231;--shadow:0 24px 70px rgba(0,0,0,.2);--okbg:#142b25;--ok:#9be8c4;--badbg:#321d28;--bad:#ffb0c0}
*{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;min-height:100vh;background:radial-gradient(ellipse at 12% 0%,rgba(126,102,255,.12),transparent 34rem),radial-gradient(ellipse at 96% 28%,rgba(84,177,255,.07),transparent 28rem),var(--bg);color:var(--text);font-family:Inter,ui-sans-serif,-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif;line-height:1.6;-webkit-font-smoothing:antialiased}button,input,select{font:inherit}button{cursor:pointer}.wrap{width:min(1320px,100%);margin:auto;padding:26px clamp(18px,4vw,54px) 76px}.topbar,.top-actions,.heading,.lecturer-head{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}.topbar{min-height:48px}.brand{font-size:27px;font-weight:850;letter-spacing:-1.7px;text-decoration:none;color:var(--text)}.brand span,h1 span{color:var(--accent)}.top-actions{gap:18px}.eyebrow{color:var(--muted);font-size:11px;letter-spacing:1.8px;font-weight:850;text-transform:uppercase}.theme-btn{width:44px;height:44px;display:grid;place-items:center;background:var(--card);color:var(--text);border:1px solid var(--line);border-radius:15px;font-size:20px;transition:.2s}.theme-btn:hover{transform:translateY(-2px);border-color:var(--accent)}.intro{margin:clamp(44px,7vw,78px) 0 34px;max-width:820px;animation:rise .55s ease both}.intro .eyebrow{display:flex;align-items:center;gap:10px}.intro .eyebrow:before{content:"";width:25px;height:2px;background:var(--accent);border-radius:9px}h1{font-size:clamp(40px,6.3vw,70px);letter-spacing:-.065em;line-height:1.02;margin:18px 0 17px;font-weight:820}.subtitle{color:var(--muted);max-width:660px;font-size:clamp(15px,1.7vw,18px);margin:0;line-height:1.8}.grid{display:grid;grid-template-columns:minmax(310px,.82fr) minmax(0,1.35fr);gap:24px;align-items:start}.card{position:relative;min-width:0;padding:clamp(22px,3vw,32px);background:var(--card);border:1px solid var(--line);border-radius:26px;box-shadow:var(--shadow);backdrop-filter:blur(18px);overflow:hidden;animation:rise .65s ease both}.card:before{content:"";position:absolute;inset:0 0 auto;height:1px;background:linear-gradient(90deg,transparent,rgba(170,152,255,.7),transparent);pointer-events:none}.directory{animation-delay:.1s}.form-top{display:flex;align-items:flex-start;gap:15px;margin-bottom:25px}.form-icon{width:52px;height:52px;flex:0 0 52px;display:grid;place-items:center;border-radius:17px;color:white;background:linear-gradient(145deg,#8c78ff,#6245e8);box-shadow:0 12px 26px rgba(113,87,242,.28)}.form-icon svg{width:24px;height:24px}.card h2{font-size:21px;margin:0 0 7px;letter-spacing:-.7px;font-weight:800}.hint{color:var(--muted);font-size:13px;margin:0;line-height:1.75}.kicker{display:block;color:var(--accent);font-size:10px;letter-spacing:1.7px;font-weight:850;text-transform:uppercase;margin-bottom:4px}.divider{height:1px;background:var(--line);margin:22px 0 20px}.field{margin-top:18px}label{display:flex;align-items:center;justify-content:space-between;gap:8px;color:var(--text);font-size:12px;font-weight:750;margin:0 0 8px}.optional{color:var(--muted);font-size:10px;font-weight:650;letter-spacing:.4px;text-transform:uppercase}.input-wrap{position:relative}.input-wrap svg{position:absolute;left:15px;top:50%;width:17px;height:17px;transform:translateY(-50%);color:var(--muted);pointer-events:none}input,select{display:block;width:100%;min-height:52px;background:var(--input);color:var(--text);border:1px solid var(--line);border-radius:14px;padding:13px 15px;outline:none;transition:border-color .2s,box-shadow .2s,background .2s}.input-wrap input{padding-left:44px}input::placeholder{color:#85899d;opacity:.85}input:focus,select:focus{border-color:var(--accent);box-shadow:0 0 0 4px rgba(113,87,242,.15);background:var(--solid)}select{padding-right:42px}.field-note{color:var(--muted);font-size:11px;margin:7px 0 0}.form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}.primary{display:flex;align-items:center;justify-content:center;gap:11px;width:100%;min-height:54px;margin-top:26px;padding:14px 18px;border:1px solid rgba(255,255,255,.14);border-radius:15px;background:linear-gradient(110deg,var(--accent2),var(--accent));color:#fff;font-size:13px;font-weight:800;box-shadow:0 13px 28px rgba(105,78,232,.23);transition:transform .2s,box-shadow .2s,filter .2s}.primary:hover{transform:translateY(-2px);box-shadow:0 17px 32px rgba(105,78,232,.32);filter:saturate(1.08)}.primary svg{width:17px;height:17px;transition:transform .2s}.primary:hover svg{transform:translateX(3px)}.security{display:flex;align-items:flex-start;gap:9px;color:var(--muted);font-size:11px;line-height:1.6;margin-top:16px}.security svg{flex:0 0 15px;width:15px;height:15px;margin-top:2px;color:var(--accent)}.directory-head{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;margin-bottom:9px}.count{display:inline-flex;align-items:center;gap:8px;white-space:nowrap;padding:8px 12px;border:1px solid rgba(113,87,242,.2);border-radius:999px;background:var(--soft);color:var(--accent);font-size:11px;font-weight:800}.count:before{content:"";width:6px;height:6px;border-radius:50%;background:currentColor;box-shadow:0 0 0 4px rgba(113,87,242,.12)}.summary{display:flex;gap:10px;align-items:center;margin:20px 0 23px;padding:13px 15px;border:1px solid var(--line);background:var(--input);border-radius:15px;color:var(--muted);font-size:12px}.summary svg{width:17px;height:17px;color:var(--accent);flex:0 0 17px}.lecturer{padding:20px 0;border-top:1px solid var(--line)}.lecturer:first-of-type{border-top:none}.lecturer-name{font-size:15px;font-weight:800;letter-spacing:-.2px}.status{display:inline-flex;align-items:center;gap:7px;padding:6px 10px;border-radius:999px;background:var(--okbg);color:var(--ok);font-size:10px;font-weight:850}.status:before{content:"";width:6px;height:6px;border-radius:50%;background:currentColor}.status.off{background:var(--badbg);color:var(--bad)}.details{color:var(--muted);font-size:12px;line-height:1.9;margin:10px 0 14px;overflow-wrap:anywhere}.small-btn{padding:9px 13px;border:1px solid var(--line);border-radius:11px;background:var(--input);color:var(--text);font-size:11px;font-weight:750;transition:.2s}.small-btn:hover{border-color:var(--accent);color:var(--accent)}.empty{padding:42px 20px;text-align:center;border:1px dashed var(--line);border-radius:18px;background:rgba(127,127,127,.025)}.empty-icon{width:56px;height:56px;margin:0 auto 15px;display:grid;place-items:center;border-radius:18px;background:var(--soft);color:var(--accent)}.empty-icon svg{width:27px;height:27px}.empty strong{display:block;font-size:14px}.empty p{max-width:340px;margin:7px auto 0;color:var(--muted);font-size:12px;line-height:1.8}.message{padding:14px 17px;border-radius:14px;margin:0 0 22px;font-size:13px;border:1px solid transparent;animation:rise .3s ease both}.success{background:var(--okbg);color:var(--ok);border-color:rgba(23,107,73,.15)}.error{background:var(--badbg);color:var(--bad);border-color:rgba(165,43,71,.15)}@keyframes rise{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}@media(prefers-reduced-motion:reduce){*,*:before,*:after{animation-duration:.01ms!important;transition-duration:.01ms!important;scroll-behavior:auto!important}}@media(max-width:900px){.grid{grid-template-columns:1fr}.directory{order:2}.form-card{order:1}}@media(max-width:540px){.wrap{padding:20px 15px 48px}.intro{margin:48px 0 25px}h1{letter-spacing:-.05em}.card{border-radius:21px;padding:21px}.form-row{grid-template-columns:1fr;gap:0}.top-actions{gap:11px}}
</style>

<script src="<?= htmlspecialchars(url('assets/js/theme-global.js?v=20261107-2'), ENT_QUOTES, 'UTF-8') ?>"></script>
<link rel="stylesheet" href="<?= e(url('assets/css/admin-unified-v1.css?v=1')) ?>">
</head>
<body>
<div class="wrap">
<header class="topbar">
<a class="brand" href="<?= e(url()) ?>">u learn<span>.</span></a>
<div class="top-actions"><span class="eyebrow"><?= e(str_replace('_',' ',$admin['role'])) ?></span><button id="themeButton" class="theme-btn" type="button" onclick="toggleTheme()" aria-label="Toggle theme">☾</button></div>
</header>
<section class="intro"><div class="eyebrow">Administration / People</div><h1>Manage lecturers<span>.</span></h1><p class="subtitle">Invite lecturers, manage teaching profiles and control access.</p></section>
<?php if ($success): ?><div class="message success" role="status"><?= e($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="message error" role="alert"><?= e($error) ?></div><?php endif; ?>
<div class="grid">
<section class="card form-card">
<div class="form-top"><div class="form-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/></svg></div><div><span class="kicker">Lecturer access</span><h2>Invite a lecturer</h2><p class="hint">Invite new lecturers to create their teaching accounts.</p></div></div>
<div class="divider"></div>
<form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="invite">
<div class="field"><label for="email">Lecturer email <span class="optional">Required</span></label><div class="input-wrap"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg><input id="email" name="email" type="email" placeholder="name@university.edu" maxlength="254" autocomplete="email" required></div><p class="field-note">We will email a one-time activation code to this address.</p></div>
<div class="form-row"><div class="field"><label for="title">Professional title</label><select id="title" name="title"><option>Mr.</option><option>Mrs.</option><option>Ms.</option><option>Dr.</option><option>Prof.</option></select></div><div class="field"><label for="university_id">University *</label><select id="university_id" name="university_id" required><option value="">Choose university</option><?php foreach($universities as $u): ?><option value="<?= (int)$u['id'] ?>"><?= e($u['name']) ?></option><?php endforeach; ?></select></div></div>
<div class="form-row"><div class="field"><label for="department">Department *</label><select id="department" name="department" required><option value="">Choose department</option><?php foreach($departments as $d): ?><option value="<?= e($d) ?>"><?= e($d) ?></option><?php endforeach; ?></select></div><div class="field"><label for="designation">Designation *</label><select id="designation" name="designation" required><option value="">Choose designation</option><?php foreach($designations as $d): ?><option value="<?= e($d) ?>"><?= e($d) ?></option><?php endforeach; ?></select></div></div>
<div class="form-row"><div class="field"><label for="degree_id">Degree programme *</label><select id="degree_id" name="degree_id" required><option value="">Choose programme</option></select></div><div class="field"><label for="batch_id">Intake *</label><select id="batch_id" name="batch_id" required><option value="">Choose intake</option></select></div></div>
<div class="form-row"><div class="field"><label for="study_year">Academic year *</label><select id="study_year" name="study_year" required><option value="">Choose year</option></select></div><div class="field"><label for="semester">Semester *</label><select id="semester" name="semester" required><option value="">Choose semester</option></select></div></div>
<p class="field-note">The first programme/intake/year/semester assignment activates after account setup. Assign modules later from Lecturer Assignments.</p>
<script>
const degreeData=<?= json_encode($degrees,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
const intakeData=<?= json_encode($intakes,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
const fields=['university_id','degree_id','batch_id','study_year','semester'].map(id=>document.getElementById(id));
function fillSelect(el,options,label){
  const previous=el.value;
  el.replaceChildren(new Option(label,''));
  options.forEach(([value,name])=>el.add(new Option(name,String(value))));
  if([...el.options].some(o=>o.value===previous)) el.value=previous;
}
function updateScope(){
  const [u,d,b,y,se]=fields;
  const ds=degreeData.filter(x=>String(x.university_id)===u.value);
  fillSelect(d,ds.map(x=>[x.id,x.name]),'Choose programme');
  const bs=intakeData.filter(x=>String(x.degree_id)===d.value);
  fillSelect(b,bs.map(x=>[x.batch_id,x.batch_code]),'Choose intake');
  const chosen=ds.find(x=>String(x.id)===d.value);
  const duration=chosen ? Math.min(6,Math.max(0,Number(chosen.duration_years)||0)) : 0;
  const years=b.value ? Array.from({length:duration},(_,i)=>[i+1,'Year '+(i+1)]) : [];
  fillSelect(y,years,'Choose year');
  fillSelect(se,y.value ? [[1,'Semester 1'],[2,'Semester 2']] : [],'Choose semester');
}
fields.slice(0,4).forEach(el=>el.addEventListener('change',updateScope));
updateScope();
</script>
<button class="primary" type="submit">Send lecturer invitation <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M12 5l7 7-7 7"/></svg></button>
<div class="security"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 1 1 8 0v3M9 15l2 2 4-4"/></svg><span>Lecturers create their own accounts using the emailed activation code.</span></div>
</form>
</section>
<section class="card directory">
<p><a href="<?= e(url('admin/module-lecturers.php')) ?>" style="color:var(--accent);font-weight:750">Manage programme, intake and module assignments →</a></p>
<div class="directory-head"><div><span class="kicker">People &amp; permissions</span><h2>Lecturer directory</h2><p class="hint">Manage approved lecturers and their current access status.</p></div><span class="count"><?= $activeCount ?> active</span></div>
<div class="summary"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg><span>Changes to lecturer access are reflected here after the action is saved.</span></div>
<?php if (!$lecturers): ?><div class="empty"><div class="empty-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M16 11h6"/></svg></div><strong>Your lecturer directory is ready</strong><p>No activated lecturers yet. Invite a lecturer to get started.</p></div><?php endif; ?>
<?php foreach ($lecturers as $lecturer): ?>
<article class="lecturer"><div class="lecturer-head"><div class="lecturer-name"><?= e($lecturer['title']) ?> <?= e($lecturer['full_name']) ?></div><span class="status <?= (int)$lecturer['is_active'] === 1 ? '' : 'off' ?>"><?= (int)$lecturer['is_active'] === 1 ? 'Active' : 'Suspended' ?></span></div>
<div class="details"><?= e($lecturer['email']) ?><br><?= e($lecturer['department'] ?: 'No department') ?> · <?= e($lecturer['designation'] ?: 'Lecturer') ?><br><?= (int)$lecturer['module_count'] ?> teaching assignments</div>
<form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="lecturer_id" value="<?= (int)$lecturer['id'] ?>"><button class="small-btn" type="submit" onclick="return confirm('Change lecturer access for this account?')"><?= (int)$lecturer['is_active'] === 1 ? 'Suspend access' : 'Reactivate access' ?></button></form></article>
<?php endforeach; ?>
</section>
</div>
</div>
</body>
</html>
