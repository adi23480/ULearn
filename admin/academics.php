<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$u = require_login();

if (!in_array((string)$u['role'], ['admin','main_admin'], true)) { http_response_code(403); exit('Admin permission required.'); }

$pdo = db();

$error = '';
$notice = (string)($_SESSION['academic_notice'] ?? '');
unset($_SESSION['academic_notice']);

function ai(string $key): int { $n = filter_var($_POST[$key] ?? null, FILTER_VALIDATE_INT); return $n === false || $n === null ? 0 : (int)$n; }

function required_id(string $key): int { $n=ai($key); if($n<1) throw new RuntimeException('Please select a valid record.'); return $n; }

function exists_active(PDO $pdo, string $table, int $id): bool {
    $allowed=['awarding_universities','academic_degrees','academic_batches','academic_modules'];
    if (!in_array($table,$allowed,true)) return false;
    $q=$pdo->prepare("SELECT 1 FROM $table WHERE id=? AND is_active=1"); $q->execute([$id]); return (bool)$q->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_check();
    try {
        $action=(string)($_POST['action'] ?? '');
        $id=ai('id');
        if ($action==='degree_save') {
            $name=trim((string)($_POST['name']??'')); $code=strtoupper(trim((string)($_POST['code']??'')));
            $uni=required_id('university_id'); $duration=ai('duration_years');
            if ($name===''||$code===''||strlen($name)>200||strlen($code)>30||$duration<1||$duration>5||!exists_active($pdo,'awarding_universities',$uni)) throw new RuntimeException('Check the university, degree name, code and duration (1–5 years).');
            if ($id) { $q=$pdo->prepare('UPDATE academic_degrees SET name=?, code=?, awarding_university_id=?, duration_years=? WHERE id=?'); $q->execute([$name,$code,$uni,$duration,$id]); }
            else { $q=$pdo->prepare('INSERT INTO academic_degrees (name,code,awarding_university_id,duration_years,is_active) VALUES (?,?,?,?,1)'); $q->execute([$name,$code,$uni,$duration]); }
            $notice='Degree programme saved.';
        } elseif ($action==='degree_toggle') {
            $id=required_id('id'); $q=$pdo->prepare('UPDATE academic_degrees SET is_active=1-is_active WHERE id=?'); $q->execute([$id]); $notice='Degree status updated.';
        } elseif ($action==='batch_save') {
            $code=trim((string)($_POST['batch_code']??'')); $year=ai('intake_year'); $number=ai('intake_number');
            if (!preg_match('/^[0-9]{2}\.[0-9]{1,2}$/',$code)||$year<2000||$year>2100||$number<1||$number>20||$code!==substr((string)$year,-2).'.'.$number) throw new RuntimeException('Use a matching intake code, for example 25.1 for year 2025, intake 1.');
            if ($id) {$q=$pdo->prepare('UPDATE academic_batches SET batch_code=?,intake_year=?,intake_number=? WHERE id=?');$q->execute([$code,$year,$number,$id]);}
            else {$q=$pdo->prepare('INSERT INTO academic_batches (batch_code,intake_year,intake_number,is_active) VALUES (?,?,?,1)');$q->execute([$code,$year,$number]);}
            $notice='Intake saved.';
        } elseif ($action==='batch_toggle') {
            $q=$pdo->prepare('UPDATE academic_batches SET is_active=1-is_active WHERE id=?');$q->execute([required_id('id')]);$notice='Intake status updated.';
        } elseif ($action==='intake_link') {
            $degree=required_id('degree_id');$batch=required_id('batch_id');
            if (!exists_active($pdo,'academic_degrees',$degree)||!exists_active($pdo,'academic_batches',$batch)) throw new RuntimeException('Select an active degree and intake.');
            $q=$pdo->prepare('INSERT INTO academic_degree_intakes (degree_id,batch_id,is_active) VALUES (?,?,1) ON DUPLICATE KEY UPDATE is_active=1');$q->execute([$degree,$batch]);$notice='Intake linked to degree.';
        } elseif ($action==='intake_unlink') {
            $degree=required_id('degree_id');$batch=required_id('batch_id');
            $q=$pdo->prepare('SELECT COUNT(*) FROM academic_curriculum WHERE degree_id=? AND batch_id=? AND is_active=1');$q->execute([$degree,$batch]);
            if ((int)$q->fetchColumn()>0) throw new RuntimeException('Archive the curriculum assignments for this intake before unlinking it.');
            $q=$pdo->prepare('UPDATE academic_degree_intakes SET is_active=0 WHERE degree_id=? AND batch_id=?');$q->execute([$degree,$batch]);$notice='Degree intake archived.';
        } elseif ($action==='module_save') {
            $code=strtoupper(trim((string)($_POST['module_code']??'')));$name=trim((string)($_POST['module_name']??''));$desc=trim((string)($_POST['description']??''));
            if ($code===''||$name===''||strlen($code)>30||strlen($name)>200||strlen($desc)>5000) throw new RuntimeException('Enter a valid module code, name and description.');
            if ($id) {$q=$pdo->prepare('UPDATE academic_modules SET module_code=?,module_name=?,description=? WHERE id=?');$q->execute([$code,$name,$desc,$id]);}
            else {$q=$pdo->prepare('INSERT INTO academic_modules (module_code,module_name,description,is_active) VALUES (?,?,?,1)');$q->execute([$code,$name,$desc]);}
            $notice='Module saved.';
        } elseif ($action==='module_toggle') {
            $q=$pdo->prepare('UPDATE academic_modules SET is_active=1-is_active WHERE id=?');$q->execute([required_id('id')]);$notice='Module status updated.';
        } elseif ($action==='curriculum_add') {
            $degree=required_id('degree_id');$batch=required_id('batch_id');$module=required_id('module_id');$year=ai('study_year');$semester=ai('semester');
            $q=$pdo->prepare('SELECT duration_years FROM academic_degrees WHERE id=? AND is_active=1');$q->execute([$degree]);$duration=(int)$q->fetchColumn();
            $q=$pdo->prepare('SELECT 1 FROM academic_degree_intakes WHERE degree_id=? AND batch_id=? AND is_active=1');$q->execute([$degree,$batch]);
            if (!$duration||$year<1||$year>$duration||$semester<1||$semester>2||!$q->fetchColumn()||!exists_active($pdo,'academic_modules',$module)) throw new RuntimeException('Select a linked intake, valid study year, semester and active module.');
            $q=$pdo->prepare('INSERT INTO academic_curriculum (degree_id,batch_id,module_id,study_year,semester,is_active) VALUES (?,?,?,?,?,1) ON DUPLICATE KEY UPDATE study_year=VALUES(study_year),semester=VALUES(semester),is_active=1');$q->execute([$degree,$batch,$module,$year,$semester]);$notice='Module assigned to curriculum.';
        } elseif ($action==='curriculum_toggle') {
            $q=$pdo->prepare('UPDATE academic_curriculum SET is_active=1-is_active WHERE id=?');$q->execute([required_id('id')]);$notice='Curriculum assignment status updated.';
        } else throw new RuntimeException('Unknown action.');

        $_SESSION['academic_notice']=$notice;
        $back=(string)($_POST['return_to']??'');
        if (!preg_match('/^\?university=[0-9]+(&degree=[0-9]+)?(&batch=[0-9]+)?$/',$back)) $back='';
        header('Location: '.url('admin/academics.php').$back);exit;
    } catch(PDOException $ex) {error_log('ULearn academic structure: '.$ex->getMessage());$error=(string)$ex->getCode()==='23000'?'This code or assignment already exists.':'Unable to save. Ensure migration 003 was imported successfully.';}
    catch(RuntimeException $ex) {$error=$ex->getMessage();}
}

$universities=$pdo->query('SELECT id,name,code,is_active FROM awarding_universities ORDER BY is_active DESC,name')->fetchAll(PDO::FETCH_ASSOC);
$degrees=$pdo->query('SELECT id,name,code,awarding_university_id,duration_years,is_active FROM academic_degrees ORDER BY is_active DESC,name')->fetchAll(PDO::FETCH_ASSOC);
$batches=$pdo->query('SELECT id,batch_code,intake_year,intake_number,is_active FROM academic_batches ORDER BY intake_year DESC,intake_number')->fetchAll(PDO::FETCH_ASSOC);
$modules=$pdo->query('SELECT id,module_code,module_name,description,is_active FROM academic_modules ORDER BY module_code')->fetchAll(PDO::FETCH_ASSOC);
$links=$pdo->query('SELECT degree_id,batch_id,is_active FROM academic_degree_intakes ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
$curriculum=$pdo->query('SELECT c.id,c.degree_id,c.batch_id,c.module_id,c.study_year,c.semester,c.is_active,m.module_code,m.module_name FROM academic_curriculum c JOIN academic_modules m ON m.id=c.module_id ORDER BY c.study_year,c.semester,m.module_code')->fetchAll(PDO::FETCH_ASSOC);

$universityId=max(0,(int)($_GET['university']??0));$degreeId=max(0,(int)($_GET['degree']??0));$batchId=max(0,(int)($_GET['batch']??0));
$selectedUniversity=null;$selectedDegree=null;$selectedBatch=null;
foreach($universities as $uItem) if((int)$uItem['id']===$universityId)$selectedUniversity=$uItem;
foreach($degrees as $d)if((int)$d['id']===$degreeId&&(int)$d['awarding_university_id']===$universityId)$selectedDegree=$d;
foreach($batches as $b)if((int)$b['id']===$batchId)$selectedBatch=$b;
if (!$selectedUniversity) {$selectedDegree=null;$selectedBatch=null;}
if (!$selectedDegree) $selectedBatch=null;
if ($selectedBatch) { $valid=false; foreach($links as $link) if((int)$link['degree_id']===$degreeId && (int)$link['batch_id']===$batchId && (int)$link['is_active']===1) $valid=true; if(!$valid) $selectedBatch=null; }

$base=url('admin/academics.php');
$back='?university='.$universityId.($selectedDegree?'&degree='.$degreeId:'').($selectedBatch?'&batch='.$batchId:'');
$csrf=e(csrf());

$fullName = (string)($u['full_name'] ?? 'Administrator');
$nameParts = preg_split('/\s+/', trim($fullName));
$firstName = $nameParts[0] ?: 'Administrator';
$initial = strtoupper(substr($firstName, 0, 1));
$roleLabel = $u['role'] === 'main_admin' ? 'Main Administrator' : 'Administrator';
$theme = (string)($u['theme'] ?? 'system');

$notificationStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0"
);
$notificationStmt->execute([(int)$u['id']]);
$unreadNotifications = (int)$notificationStmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Academic Structure — U Learn</title>
<style>
    :root {
        color-scheme: light;
        --bg: #f7f8fc;
        --surface: #ffffff;
        --surface-alt: #f1f2f8;
        --text: #171927;
        --muted: #777b90;
        --border: #e7e8f0;
        --accent: #6756e8;
        --accent-light: #f0edff;
        --shadow: 0 10px 40px rgba(30,30,60,.04);
        --green: #087e60;
        --soft: #e7f7ef;
    }
    :root[data-theme="dark"] {
        color-scheme: dark;
        --bg: #0c0e16;
        --surface: #161924;
        --surface-alt: #202431;
        --text: #f4f4fa;
        --muted: #a0a4b6;
        --border: #2b2e3c;
        --accent: #a99aff;
        --accent-light: #292440;
        --shadow: none;
        --green: #5ce3b5;
        --soft: #153d34;
    }
    * { box-sizing: border-box; }
    html { scroll-behavior: smooth; }
    body {
        margin: 0;
        background: var(--bg);
        color: var(--text);
        font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif;
        line-height: 1.6;
    }
    a { color: inherit; text-decoration: none; }
    button, select, input, textarea { font: inherit; }
    button { cursor: pointer; }
    
    .layout {
        display: grid;
        grid-template-columns: 250px minmax(0, 1fr);
        min-height: 100vh;
    }
    .sidebar {
        background: var(--surface);
        border-right: 1px solid var(--border);
        padding: 32px 20px;
        display: flex;
        flex-direction: column;
        gap: 40px;
    }
    .brand {
        font-size: 26px;
        font-weight: 800;
        letter-spacing: -1.5px;
        padding-left: 12px;
    }
    .brand span { color: var(--accent); }
    .nav-label, .eyebrow {
        font-size: 11px;
        font-weight: 750;
        letter-spacing: 1.7px;
        text-transform: uppercase;
        color: var(--muted);
    }
    .nav-label { margin: 0 12px 14px; }
    .nav { display: grid; gap: 6px; }
    .nav a {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px 14px;
        border-radius: 12px;
        font-size: 14px;
        font-weight: 600;
        transition: background .2s, color .2s;
    }
    .nav a:hover { background: var(--surface-alt); }
    .nav a.active {
        background: var(--accent-light);
        color: var(--accent);
    }
    .ul-nav-icon {
        width: 20px;
        height: 20px;
        flex-shrink: 0;
    }
    .sidebar-bottom {
        margin-top: auto;
        border: 1px solid var(--border);
        border-radius: 16px;
        padding: 16px;
        font-size: 13px;
    }
    .sidebar-bottom strong {
        display: block;
        overflow-wrap: anywhere;
    }
    .sidebar-bottom small {
        color: var(--muted);
        overflow-wrap: anywhere;
    }
    .main {
        min-width: 0;
        padding: 34px clamp(20px, 5vw, 76px) 80px;
    }
    .topbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 20px;
        margin-bottom: 40px;
    }
    .topbar-right {
        display: flex;
        align-items: center;
        gap: 16px;
    }
    .notification-bell {
        width: 42px; height: 42px; position: relative;
        display: inline-grid; place-items: center;
        background: var(--surface); color: var(--text);
        border: 1px solid var(--border); border-radius: 12px;
        box-shadow: var(--shadow);
        transition: transform .18s ease, background .18s ease, border-color .18s ease;
    }
    .notification-bell:hover { transform: translateY(-2px); background: var(--accent-light); border-color: var(--accent); }
    .notification-bell svg { width: 21px; height: 21px; stroke: currentColor; fill: none; stroke-width: 1.9; stroke-linecap: round; stroke-linejoin: round; }
    .notification-count {
        position: absolute; top: -8px; right: -9px;
        min-width: 20px; height: 20px; padding: 0 5px;
        display: inline-grid; place-items: center;
        border-radius: 999px; background: #e34878; color: #fff;
        border: 2px solid var(--bg); font-size: 10px; font-weight: 800;
        line-height: 1;
    }
    .theme-toggle {
        border: 1px solid var(--border);
        background: var(--surface);
        color: var(--text);
        border-radius: 12px;
        width: 42px;
        height: 42px;
        display: grid;
        place-items: center;
        font-size: 18px;
        box-shadow: var(--shadow);
        transition: transform .18s ease, background .18s ease, border-color .18s ease;
    }
    .theme-toggle:hover {
        background: var(--accent-light);
        border-color: var(--accent);
        transform: translateY(-2px);
    }
    .avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: var(--accent-light);
        color: var(--accent);
        display: grid;
        place-items: center;
        font-weight: 800;
        transition: transform .18s ease;
    }
    .avatar:hover { transform: translateY(-2px); }
    .logout {
        background: transparent;
        border: 0;
        color: var(--muted);
        font-size: 13px;
        font-weight: 650;
        cursor: pointer;
    }
    .logout:hover { color: var(--text); }
    
    h1, h2, h3, p { margin-top: 0; }
    h1 { font-size: clamp(28px, 4vw, 44px); letter-spacing: -1.5px; margin: 6px 0 10px; }
    h1 span { color: var(--accent); }
    .lead { color: var(--muted); font-size: 14px; max-width: 680px; margin-bottom: 0; }
    
    .section-heading {
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        gap: 15px;
        margin: 40px 0 20px;
    }
    .section-heading h2 {
        margin: 0;
        font-size: 22px;
        letter-spacing: -.5px;
    }
    .section-heading small { color: var(--muted); font-weight: 600; }

    .card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 20px;
        padding: 28px;
        margin-bottom: 18px;
        box-shadow: var(--shadow);
    }
    
    .stage9-management {
        margin: 4px 0 12px;
        padding: 10px 12px;
        border: 1px solid var(--border);
        border-radius: 14px;
        background: var(--surface-alt);
    }
    .stage9-management summary {
        cursor: pointer;
        font-weight: 750;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 1.2px;
        color: var(--muted);
    }
    .stage9-management a {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 12px;
        margin-top: 6px;
        border-radius: 10px;
        color: var(--text);
        font-size: 13px;
        font-weight: 600;
    }
    .stage9-management a:hover {
        background: var(--accent-light);
        color: var(--accent);
    }

    .grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 16px;
    }
    .item {
        display: block;
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 20px;
        padding: 24px;
        box-shadow: var(--shadow);
        transition: .2s;
    }
    .item:hover { border-color: var(--accent); transform: translateY(-2px); }
    .item h3 { margin: 12px 0 6px; font-size: 18px; line-height: 1.35; letter-spacing: -.5px; }
    .icon-box {
        width: 40px;
        height: 40px;
        border-radius: 12px;
        background: var(--accent-light);
        color: var(--accent);
        display: grid;
        place-items: center;
        flex-shrink: 0;
    }
    .pill {
        display: inline-block;
        border-radius: 100px;
        padding: 5px 11px;
        background: var(--soft);
        color: var(--green);
        font-size: 11px;
        font-weight: 750;
        white-space: nowrap;
    }
    .pill.off { background: var(--surface-alt); color: var(--muted); }
    
    .row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 13px;
        flex-wrap: wrap;
    }
    .row + .row {
        border-top: 1px solid var(--border);
        padding-top: 14px;
        margin-top: 14px;
    }
    .row strong { display: block; font-size: 14px; }
    .row small { display: block; color: var(--muted); font-size: 12px; margin-top: 2px; }
    .actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
    .actions form { margin: 0; }
    
    .message {
        padding: 14px 18px;
        border-radius: 14px;
        margin: 22px 0;
        font-size: 14px;
    }
    .success { background: var(--soft); color: var(--green); }
    .error { background: #ffedf0; color: #a32143; }
    
    .breadcrumbs {
        display: flex;
        gap: 9px;
        flex-wrap: wrap;
        margin: 20px 0;
        font-weight: 700;
        color: var(--accent);
        font-size: 13px;
    }
    .breadcrumbs span { color: var(--muted); }
    
    .year {
        border: 1px solid var(--border);
        border-radius: 20px;
        margin: 16px 0;
        overflow: hidden;
        background: var(--surface);
        box-shadow: var(--shadow);
    }
    .year summary {
        padding: 18px 24px;
        font-weight: 800;
        cursor: pointer;
        background: var(--surface-alt);
        font-size: 16px;
    }
    .year-body { padding: 20px 24px; }
    .sem { margin: 16px 0 10px; font-weight: 800; font-size: 13px; text-transform: uppercase; letter-spacing: 1px; color: var(--muted); }
    .hint { font-size: 13px; color: var(--muted); }
    
    .overlay {
        position: fixed;
        inset: 0;
        background: rgba(10, 10, 25, 0.4);
        backdrop-filter: blur(4px);
        display: none;
        justify-content: flex-end;
        z-index: 20;
    }
    .overlay.open { display: flex; }
    .drawer {
        height: 100%;
        width: min(100%, 490px);
        overflow: auto;
        background: var(--surface);
        padding: 32px;
        box-shadow: -10px 0 45px rgba(0,0,0,0.1);
    }
    .drawer h2 { font-size: 24px; letter-spacing: -.5px; margin: 6px 0 24px; }
    .field { margin: 18px 0; }
    .field label { display: block; font-weight: 700; margin-bottom: 8px; font-size: 13px; }
    .field input, .field select, .field textarea {
        width: 100%;
        border: 1px solid var(--border);
        border-radius: 11px;
        padding: 12px 14px;
        background: var(--surface-alt);
        color: var(--text);
        outline: none;
        transition: border-color .18s ease;
    }
    .field input:focus, .field select:focus, .field textarea:focus { border-color: var(--accent); }
    .field textarea { min-height: 100px; resize: vertical; }
    .drawer-buttons { display: flex; justify-content: space-between; gap: 10px; margin-top: 28px; }
    
    .hidden { display: none !important; }
    .action-btn {
        border: 1px solid var(--border);
        background: var(--surface-alt);
        color: var(--text);
        padding: 8px 12px;
        border-radius: 10px;
        cursor: pointer;
        font-size: 12px;
        font-weight: 650;
        transition: border-color .18s ease, color .18s ease;
    }
    .action-btn:hover { border-color: var(--accent); color: var(--accent); }
    .action-btn.danger { color: #b43d50; }
    .action-btn.danger:hover { border-color: #b43d50; color: #b43d50; }
    
    .btn-primary {
        background: var(--accent);
        color: #fff;
        border: 0;
        border-radius: 12px;
        padding: 13px 20px;
        font-size: 13px;
        font-weight: 700;
        cursor: pointer;
        box-shadow: 0 8px 20px rgba(103,86,232,.2);
        transition: transform .18s ease, filter .18s ease;
    }
    .btn-primary:hover { filter: brightness(1.08); transform: translateY(-2px); }

    .empty { padding: 30px; color: var(--muted); border: 1px dashed var(--border); border-radius: 16px; text-align: center; font-size: 13px; }
    .search { width: 100%; max-width: 360px; padding: 12px 14px; background: var(--surface-alt); border: 1px solid var(--border); color: var(--text); border-radius: 11px; outline: none; transition: border-color .18s ease; margin-bottom: 20px; }
    .search:focus { border-color: var(--accent); }

    .footer {
        margin-top: 50px;
        padding-top: 20px;
        border-top: 1px solid var(--border);
        font-size: 12px;
        color: var(--muted);
    }

    @media (max-width: 900px) {
        .layout { grid-template-columns: 1fr; }
        .sidebar { border-right: 0; border-bottom: 1px solid var(--border); padding: 18px; gap: 18px; }
        .nav { display: flex; flex-wrap: wrap; }
        .sidebar-bottom { display: none; }
        .main { padding-top: 25px; }
    }
    @media (max-width: 600px) {
        .grid { grid-template-columns: 1fr; }
        .drawer { padding: 22px; }
    }
</style>
<link rel="stylesheet" href="<?= e(url('assets/css/admin-unified-v1.css?v=1')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/ulearn-design-system.css?v=1')) ?>">
<script defer src="<?= e(url('assets/js/ulearn-motion.js?v=1')) ?>"></script>
<script src="<?= htmlspecialchars(url('assets/js/theme-global.js?v=20261112-1'), ENT_QUOTES, 'UTF-8') ?>"></script>
</head>
<body>
<div class="layout">
    <aside class="sidebar" id="adminSidebar">
        <a class="brand" href="<?= e(url('admin/index.php')) ?>">u learn<span>.</span></a>
        
        <nav aria-label="Admin navigation">
            <p class="nav-label">System management</p>
            <details class="stage9-management">
                <summary>System Access</summary>
                <a href="<?= e(url('admin/users.php')) ?>">
                    <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    All Registered Users
                </a>
                <a href="<?= e(url('admin/change-requests.php')) ?>">
                    <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                    Academic Requests
                </a>
            </details>

            <p class="nav-label" style="margin-top: 18px;">Your workspace</p>

            <div class="nav ul-nav">
                <a href="<?= e(url('admin/index.php')) ?>">
                    <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                    <span>Overview</span>
                </a>
                <a href="<?= e(url('admin/lecturers.php')) ?>">
                    <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    <span>Lecturers</span>
                </a>
                <a class="active" href="<?= e($base) ?>">
                    <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                    <span>Academics</span>
                </a>
                <a href="<?= e(url('admin/universities.php')) ?>">
                     <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="m2 9 10-5 10 5-10 5z"/><path d="M6 12v5c4 3 8 3 12 0v-5M22 9v7"/></svg>
                     <span>Universities</span>
                </a>
                <a href="<?= e(url('admin/quizzes.php')) ?>">
                    <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    <span>Quizzes</span>
                </a>
                <a href="<?= e(url('admin/assignments.php')) ?>">
                    <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-2 2"/><path d="M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l2-2"/></svg>
                    <span>Assignments</span>
                </a>
                <a href="<?= e(url('admin/announcements.php')) ?>">
                    <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                    <span>Announcements</span>
                </a>
                <a href="<?= e(url('admin/platform-settings.php')) ?>">
                    <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l-.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                    <span>Platform Settings</span>
                </a>
                <a href="<?= e(url('admin/profile.php')) ?>">
                    <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <span>My Profile &amp; Settings</span>
                </a>
                <a href="<?= e(url('admin/consultations.php')) ?>">
                    <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <span>Consultations</span>
                </a>
                <a href="<?= e(url('admin/kuppiya.php')) ?>">
                    <svg class="ul-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3"/><path d="M3 20v-2a6 6 0 0 1 12 0v2"/><path d="M17 5a3 3 0 0 1 0 6"/><path d="M18 15a5 5 0 0 1 3 5"/></svg>
                    <span>Kuppiya Sessions</span>
                </a>
            </div>
        </nav>

        <div class="sidebar-bottom">
            <small>Signed in as</small>
            <strong><?= e((string)$u['full_name']) ?></strong>
            <small><?= e((string)$u['email']) ?></small>
        </div>
    </aside>

    <main class="main">
        <header class="topbar">
            <span class="eyebrow">
                U LEARN / ADMIN SPACE / ACADEMICS
            </span>

            <div class="topbar-right">
                <a class="notification-bell" href="<?= e(url('dashboard/notifications.php')) ?>"
                   aria-label="Notifications<?= $unreadNotifications ? ' (' . $unreadNotifications . ' unread)' : '' ?>"
                   title="Open notification center">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg>
                    <?php if ($unreadNotifications > 0): ?>
                        <span class="notification-count" aria-hidden="true"><?= $unreadNotifications > 99 ? '99+' : $unreadNotifications ?></span>
                    <?php endif; ?>
                </a>

                <?php require __DIR__ . '/../includes/theme-toggle-button.php'; ?>

                <a class="avatar" href="<?= e(url('admin/profile.php')) ?>" aria-label="Open My Profile and Settings" title="My Profile & Settings">
                    <?= e($initial) ?>
                </a>

                <form method="post" action="<?= e(url('auth/logout.php')) ?>" style="margin:0;">
                    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                    <button type="submit" class="logout">
                        Log out ↗
                    </button>
                </form>
            </div>
        </header>

        <section>
            <div class="eyebrow">ADMINISTRATION WORKSPACE</div>
            <h1>Academic structure<span>.</span></h1>
            <p class="lead">Organize universities, degree programmes, intake batches, study years, semesters and modules in one workspace.</p>
        </section>

        <?php if($notice):?><div class="message success" role="status"><?= e($notice) ?></div><?php endif;?>
        <?php if($error):?><div class="message error" role="alert"><?= e($error) ?></div><?php endif;?>

        <div class="breadcrumbs">
            <a href="<?= e($base) ?>">Universities</a>
            <?php if($selectedUniversity):?><span>›</span><a href="?university=<?= $universityId ?>"><?= e($selectedUniversity['name']) ?></a><?php endif;?>
            <?php if($selectedDegree):?><span>›</span><a href="?university=<?= $universityId ?>&amp;degree=<?= $degreeId ?>"><?= e($selectedDegree['name']) ?></a><?php endif;?>
            <?php if($selectedBatch):?><span>›</span><span><?= e($selectedBatch['batch_code']) ?></span><?php endif;?>
        </div>

        <?php if(!$selectedUniversity):?>
            <div class="section-heading">
                <h2>Choose an awarding university</h2>
                <button class="btn-primary" type="button" onclick="window.location.href='<?= e(url('admin/universities.php')) ?>'">＋ Add university</button>
            </div>
            <input id="search" class="search" placeholder="Search university…" aria-label="Search university">
            <div class="grid" id="cards">
                <?php foreach($universities as $uItem):?>
                    <a class="item" data-search="<?= e(strtolower($uItem['name'].' '.$uItem['code'])) ?>" href="?university=<?= (int)$uItem['id'] ?>">
                        <div class="icon-box">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><path d="m2 9 10-5 10 5-10 5z"/><path d="M6 12v5c4 3 8 3 12 0v-5M22 9v7"/></svg>
                        </div>
                        <h3><?= e($uItem['name']) ?></h3>
                        <div class="row">
                            <span class="hint"><?= e($uItem['code']) ?></span>
                            <span class="pill <?= (int)$uItem['is_active'] ? '' : 'off' ?>"><?= (int)$uItem['is_active']?'Active':'Archived' ?></span>
                        </div>
                    </a>
                <?php endforeach;?>
            </div>

        <?php elseif(!$selectedDegree):?>
            <div class="section-heading">
                <h2><?= e($selectedUniversity['name']) ?> · Degree programmes</h2>
                <button class="btn-primary" type="button" onclick="openForm('degree',null)">＋ Add degree</button>
            </div>
            <div class="grid">
                <?php foreach($degrees as $d): if((int)$d['awarding_university_id']!==$universityId)continue; ?>
                    <div class="item">
                        <a href="?university=<?= $universityId ?>&amp;degree=<?= (int)$d['id'] ?>">
                            <div class="icon-box">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                            </div>
                            <h3><?= e($d['name']) ?></h3>
                            <p class="hint"><?= e($d['code']) ?> · <?= (int)$d['duration_years'] ?> study years</p>
                        </a>
                        <div class="row">
                            <span class="pill <?= (int)$d['is_active'] ? '' : 'off' ?>"><?= (int)$d['is_active']?'Active':'Archived' ?></span>
                            <div class="actions">
                                <button class="action-btn" type="button" onclick='openForm("degree",<?= json_encode($d,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>Edit</button>
                                <form method="post" onsubmit="return confirm('Change degree active status?')">
                                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                                    <input type="hidden" name="action" value="degree_toggle">
                                    <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                                    <input type="hidden" name="return_to" value="<?= e($back) ?>">
                                    <button class="action-btn" type="submit"><?= (int)$d['is_active']?'Archive':'Restore' ?></button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach;?>
            </div>

        <?php elseif(!$selectedBatch):?>
            <div class="section-heading">
                <h2><?= e($selectedDegree['name']) ?> · Intake batches</h2>
                <div class="actions">
                    <a class="action-btn" href="<?= e(url('admin/module-lecturers.php')) ?>?degree_id=<?= $degreeId ?>">Manage degree modules ↗</a>
                    <button class="btn-primary" type="button" onclick="openForm('intake',null)">＋ Link intake</button>
                </div>
            </div>
            <div class="grid">
                <?php 
                $hasLinks=false;
                foreach($links as $link):
                    if((int)$link['degree_id']!==$degreeId||(int)$link['is_active']!==1)continue;
                    foreach($batches as $b):
                        if((int)$b['id']!==(int)$link['batch_id'])continue;
                        $hasLinks=true;
                ?>
                    <div class="item">
                        <a href="?university=<?= $universityId ?>&amp;degree=<?= $degreeId ?>&amp;batch=<?= (int)$b['id'] ?>">
                            <div class="icon-box">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                            </div>
                            <h3>Intake <?= e($b['batch_code']) ?></h3>
                            <p class="hint"><?= (int)$b['intake_year'] ?> · Intake <?= (int)$b['intake_number'] ?></p>
                        </a>
                        <div class="row" style="border-top:1px solid var(--border); margin-top:14px; padding-top:14px;">
                            <span class="pill">Linked</span>
                            <form method="post" onsubmit="return confirm('Archive this degree intake?')">
                                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                                <input type="hidden" name="action" value="intake_unlink">
                                <input type="hidden" name="degree_id" value="<?= $degreeId ?>">
                                <input type="hidden" name="batch_id" value="<?= (int)$b['id'] ?>">
                                <input type="hidden" name="return_to" value="<?= e($back) ?>">
                                <button class="action-btn danger" type="submit">Archive intake</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; endforeach;?>
            </div>
            <?php if(!$hasLinks):?><div class="empty">No intake batches linked to this degree yet.</div><?php endif;?>
            
            <div class="section-heading" style="margin-top: 50px;">
                <h2>Intake catalogue</h2>
                <button class="action-btn" onclick="openForm('batch',null)">＋ Create intake batch</button>
            </div>
            <div class="card">
                <div class="records">
                    <?php foreach($batches as $b):?>
                        <div class="row">
                            <div>
                                <strong><?= e($b['batch_code']) ?></strong>
                                <small><?= (int)$b['intake_year'] ?> · Intake <?= (int)$b['intake_number'] ?></small>
                            </div>
                            <div class="actions">
                                <span class="pill <?= (int)$b['is_active'] ? '' : 'off' ?>"><?= (int)$b['is_active'] ? 'Active' : 'Archived' ?></span>
                                <button class="action-btn" onclick='openForm("batch",<?= json_encode($b,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>Edit</button>
                                <form method="post">
                                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                                    <input type="hidden" name="action" value="batch_toggle">
                                    <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                                    <input type="hidden" name="return_to" value="<?= e($back) ?>">
                                    <button class="action-btn" type="submit"><?= (int)$b['is_active']?'Archive':'Restore' ?></button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach;?>
                </div>
            </div>

        <?php else:?>
            <div class="section-heading">
                <div>
                    <h2><?= e($selectedDegree['name']) ?> · Intake <?= e($selectedBatch['batch_code']) ?></h2>
                    <p class="lead" style="margin-top:4px;">Curriculum across <?= (int)$selectedDegree['duration_years'] ?> study years. Modules are shared catalogue records; assignments are intake-specific.</p>
                </div>
                <div class="actions">
                    <a class="action-btn" href="<?= e(url('admin/module-lecturers.php')) ?>?degree_id=<?= $degreeId ?>&amp;batch_id=<?= $batchId ?>">Manage module lecturers ↗</a>
                    <button class="btn-primary" onclick="openForm('curriculum',null)">＋ Assign module</button>
                </div>
            </div>

            <?php for($y=1;$y<=(int)$selectedDegree['duration_years'];$y++):?>
                <details class="year" <?= $y===1?'open':'' ?>>
                    <summary>Year <?= $y ?></summary>
                    <div class="year-body">
                        <?php for($s=1;$s<=2;$s++):?>
                            <div class="sem">Semester <?= $s ?></div>
                            <?php 
                            $found=false;
                            foreach($curriculum as $c):
                                if((int)$c['degree_id']!==$degreeId||(int)$c['batch_id']!==$batchId||(int)$c['study_year']!==$y||(int)$c['semester']!==$s)continue;
                                $found=true;
                            ?>
                                <div class="row">
                                    <div>
                                        <strong><?= e($c['module_name']) ?></strong>
                                        <small><?= e($c['module_code']) ?> · <?= (int)$c['is_active'] ? 'Active' : 'Archived' ?></small>
                                    </div>
                                    <div class="actions">
                                        <a class="action-btn" href="<?= e(url('admin/module-lecturers.php')) ?>?degree_id=<?= $degreeId ?>&amp;batch_id=<?= $batchId ?>&amp;year=<?= $y ?>&amp;semester=<?= $s ?>">Assign lecturers ↗</a>
                                        <button class="action-btn" onclick='openForm("curriculum",<?= json_encode($c,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>Edit placement</button>
                                        <form method="post">
                                            <input type="hidden" name="csrf" value="<?= $csrf ?>">
                                            <input type="hidden" name="action" value="curriculum_toggle">
                                            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                            <input type="hidden" name="return_to" value="<?= e($back) ?>">
                                            <button class="action-btn" type="submit"><?= (int)$c['is_active']?'Archive':'Restore' ?></button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; if(!$found):?><div class="hint">No modules assigned yet.</div><?php endif;?>
                        <?php endfor;?>
                    </div>
                </details>
            <?php endfor;?>

            <div class="section-heading" style="margin-top: 50px;">
                <h2>Module catalogue</h2>
                <button class="action-btn" onclick="openForm('module',null)">＋ Create module</button>
            </div>
            <div class="card">
                <div class="records">
                    <?php foreach($modules as $m):?>
                        <div class="row">
                            <div>
                                <strong><?= e($m['module_name']) ?></strong>
                                <small><?= e($m['module_code']) ?></small>
                            </div>
                            <div class="actions">
                                <span class="pill <?= (int)$m['is_active'] ? '' : 'off' ?>"><?= (int)$m['is_active'] ? 'Active' : 'Archived' ?></span>
                                <button class="action-btn" onclick='openForm("module",<?= json_encode($m,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>Edit</button>
                                <form method="post">
                                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                                    <input type="hidden" name="action" value="module_toggle">
                                    <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                                    <input type="hidden" name="return_to" value="<?= e($back) ?>">
                                    <button class="action-btn" type="submit"><?= (int)$m['is_active']?'Archive':'Restore' ?></button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach;?>
                </div>
            </div>
        <?php endif;?>

        <footer class="footer">
            © <?= date('Y') ?> U Learn · Administration Workspace
        </footer>
    </main>
</div>

<div class="overlay" id="overlay">
    <div class="drawer" role="dialog" aria-modal="true" aria-labelledby="formTitle">
        <div class="row">
            <span class="eyebrow">Academic management</span>
            <button class="action-btn" type="button" onclick="closeForm()">✕</button>
        </div>
        <h2 id="formTitle">Manage record</h2>
        <form method="post" id="editForm">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="action" id="action">
            <input type="hidden" name="id" id="recordId">
            <input type="hidden" name="return_to" value="<?= e($back) ?>">
            <div id="fields"></div>
            <div class="drawer-buttons">
                <button type="button" class="action-btn" onclick="closeForm()">Cancel</button>
                <button class="btn-primary" type="submit">Save changes →</button>
            </div>
        </form>
    </div>
</div>

<script>
const universities=<?= json_encode(array_values(array_filter($universities,fn($u)=>(int)$u['is_active']===1)),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
const batches=<?= json_encode(array_values(array_filter($batches,fn($b)=>(int)$b['is_active']===1)),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
const modules=<?= json_encode(array_values(array_filter($modules,fn($m)=>(int)$m['is_active']===1)),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
const linked=<?= json_encode(array_values(array_filter($links,fn($l)=>(int)$l['degree_id']===$degreeId&&(int)$l['is_active']===1)),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
const uid=<?= $universityId ?>,did=<?= $degreeId ?>,bid=<?= $batchId ?>,duration=<?= (int)($selectedDegree['duration_years']??4) ?>;
const overlay=document.getElementById('overlay');

function field(label,name,value='',type='text',extra=''){return `<div class="field"><label>${label}</label><input name="${name}" type="${type}" value="${esc(value)}" ${extra} required></div>`}
function esc(s){return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))}
function select(label,name,options,value){return `<div class="field"><label>${label}</label><select name="${name}" required>${options.map(o=>`<option value="${esc(o.value)}" ${String(o.value)===String(value)?'selected':''}>${esc(o.label)}</option>`).join('')}</select></div>`}

function openForm(kind,obj){
    obj=obj||{};
    document.getElementById('editForm').reset();
    document.getElementById('recordId').value=obj.id||'';
    let title='',action='',html='';
    if(kind==='degree'){
        title=obj.id?'Edit degree programme':'Add degree programme';action='degree_save';
        html=field('Degree name','name',obj.name,'text','maxlength="200"')+field('Degree code','code',obj.code,'text','maxlength="30"')+select('Awarding university','university_id',universities.map(u=>({value:u.id,label:u.name})),obj.awarding_university_id||uid)+select('Duration (study years)','duration_years',[1,2,3,4,5].map(n=>({value:n,label:n+' year'+(n===1?'':'s')})),obj.duration_years||4);
    } else if(kind==='batch'){
        title=obj.id?'Edit intake':'Create intake';action='batch_save';
        html=field('Intake code (e.g. 25.1)','batch_code',obj.batch_code,'text','pattern="[0-9]{2}\\\\.[0-9]{1,2}"')+field('Calendar year','intake_year',obj.intake_year||new Date().getFullYear(),'number','min="2000" max="2100"')+field('Intake number','intake_number',obj.intake_number||1,'number','min="1" max="20"');
    } else if(kind==='intake'){
        title='Link intake to degree';action='intake_link';
        html=`<input type="hidden" name="degree_id" value="${did}">`+select('Existing intake','batch_id',batches.map(b=>({value:b.id,label:b.batch_code+' · '+b.intake_year})),batches[0]?.id);
    } else if(kind==='module'){
        title=obj.id?'Edit module':'Create module';action='module_save';
        html=field('Module code','module_code',obj.module_code,'text','maxlength="30"')+field('Module name','module_name',obj.module_name,'text','maxlength="200"')+`<div class="field"><label>Description</label><textarea name="description" maxlength="5000">${esc(obj.description)}</textarea></div>`;
    } else if(kind==='curriculum'){
        title='Assign module to curriculum';action='curriculum_add';
        html=`<input type="hidden" name="degree_id" value="${did}"><input type="hidden" name="batch_id" value="${bid}">`+select('Module','module_id',modules.map(m=>({value:m.id,label:m.module_code+' · '+m.module_name})),obj.module_id||modules[0]?.id)+select('Study year','study_year',Array.from({length:duration},(_,i)=>({value:i+1,label:'Year '+(i+1)})),obj.study_year||1)+select('Semester','semester',[{value:1,label:'Semester 1'},{value:2,label:'Semester 2'}],obj.semester||1);
    } else return;

    document.getElementById('formTitle').textContent=title;
    document.getElementById('action').value=action;
    document.getElementById('fields').innerHTML=html;
    overlay.classList.add('open');
    document.body.style.overflow='hidden';
}
function closeForm(){overlay.classList.remove('open');document.body.style.overflow='';}
overlay.addEventListener('click',e=>{if(e.target===overlay)closeForm()});
document.addEventListener('keydown',e=>{if(e.key==='Escape')closeForm()});

const search=document.getElementById('search');
if(search)search.addEventListener('input',()=>document.querySelectorAll('#cards .item').forEach(c=>c.classList.toggle('hidden',!c.dataset.search.includes(search.value.toLowerCase()))));
</script>

</body>
</html>
