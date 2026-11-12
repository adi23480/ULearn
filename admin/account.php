<?php
declare(strict_types=1);
require_once __DIR__.'/../config/bootstrap.php';
$admin=require_login();
if(!in_array((string)$admin['role'],['admin','main_admin'],true)){http_response_code(403);exit('Admin access only');}
$pdo=db();$id=(int)($_GET['id']??$_POST['id']??0);if($id<1){http_response_code(400);exit('Invalid account');}
$notice='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 csrf_check();
 try{
  $action=(string)($_POST['action']??'');
  if($id===(int)$admin['id'])throw new RuntimeException('You cannot modify your own account here.');
  $pdo->beginTransaction();
  $s=$pdo->prepare('SELECT id,role,account_status FROM users WHERE id=? FOR UPDATE');$s->execute([$id]);$target=$s->fetch(PDO::FETCH_ASSOC);
  if(!$target)throw new RuntimeException('Account not found.');
  if(in_array($target['role'],['admin','main_admin'],true))throw new RuntimeException('Administrator accounts require separate privileged management.');
  if($action==='save'){
   $name=trim((string)($_POST['full_name']??''));
   if(mb_strlen($name)<2||mb_strlen($name)>120)throw new RuntimeException('Name must be 2–120 characters.');
   $pdo->prepare('UPDATE users SET full_name=? WHERE id=?')->execute([$name,$id]);
   $audit='update_account';$notice='Account updated.';
  }elseif(in_array($action,['suspend','activate'],true)){
   $new=$action==='suspend'?'suspended':'active';
   $pdo->prepare('UPDATE users SET account_status=?,session_version=session_version+1 WHERE id=?')->execute([$new,$id]);
   $audit=$action.'_account';$notice='Account '.($new==='active'?'reactivated':'suspended').'.';
  }else{throw new RuntimeException('Unknown action.');}
  if($target['role']==='student')$pdo->prepare('INSERT INTO student_account_audit(student_user_id,admin_user_id,action) VALUES (?,?,?)')->execute([$id,$admin['id'],$audit]);
  $pdo->commit();
 }catch(Throwable $ex){if($pdo->inTransaction())$pdo->rollBack();$error=$ex instanceof RuntimeException?$ex->getMessage():'Could not update account.';error_log($ex->getMessage());}
}
$s=$pdo->prepare('SELECT u.id,u.full_name,u.email,u.student_number,u.role,u.account_status,u.created_at,d.name degree_name,b.batch_code FROM users u LEFT JOIN student_academic_profiles sp ON sp.user_id=u.id LEFT JOIN academic_degrees d ON d.id=sp.degree_id LEFT JOIN academic_batches b ON b.id=sp.batch_id WHERE u.id=?');$s->execute([$id]);$account=$s->fetch(PDO::FETCH_ASSOC);if(!$account){http_response_code(400);exit('Account not found');}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Manage Account — ULearn</title>
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
        --danger: #b35c66;
        --danger-light: #ffedf0;
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
        --danger: #e9808d;
        --danger-light: #3d1a22;
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
    button, input, select { font: inherit; }
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
    .sidebar-bottom strong { display: block; overflow-wrap: anywhere; }
    .sidebar-bottom small { color: var(--muted); overflow-wrap: anywhere; }
    
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
    .crumb { font-size: 12px; color: var(--muted); }
    .theme {
        background: var(--surface);
        color: var(--text);
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 10px 14px;
        font-weight: 600;
    }
    
    h1, h2, h3, p { margin-top: 0; }
    h1 { font-size: clamp(28px, 4vw, 44px); letter-spacing: -1.8px; margin: 8px 0; }
    h1 span { color: var(--accent); }
    h2 { font-size: 21px; letter-spacing: -.5px; margin: 0 0 12px; }
    
    .heading {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 20px;
        flex-wrap: wrap;
        margin-bottom: 26px;
    }
    .heading p { margin: 0; max-width: 650px; color: var(--muted); }
    
    .back-link {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 13px;
        font-weight: 650;
        color: var(--accent);
        margin-bottom: 16px;
    }
    .back-link:hover { text-decoration: underline; }

    .tag {
        display: inline-block;
        padding: 6px 14px;
        background: var(--accent-light);
        color: var(--accent);
        border-radius: 99px;
        font-size: 12px;
        font-weight: 750;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .button {
        background: var(--accent);
        color: white;
        border: 0;
        border-radius: 12px;
        padding: 11px 18px;
        font-weight: 750;
        box-shadow: var(--shadow);
    }
    .button:hover { filter: brightness(1.07); }
    
    .button-danger {
        background: var(--danger);
        color: white;
        border: 0;
        border-radius: 12px;
        padding: 11px 18px;
        font-weight: 750;
    }
    .button-danger:hover { filter: brightness(1.07); }

    .panel {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 20px;
        padding: 28px;
        box-shadow: var(--shadow);
        margin-bottom: 24px;
    }

    .alert {
        padding: 14px 17px;
        border-radius: 12px;
        background: var(--accent-light);
        color: var(--accent);
        margin-bottom: 20px;
        font-weight: 600;
    }
    .error { background: var(--danger-light); color: var(--danger); border: 1px solid var(--border); }

    .field { margin-bottom: 20px; }
    .field label { display: block; font-weight: 750; margin-bottom: 8px; font-size: 13px; }
    .field input {
        width: 100%;
        max-width: 480px;
        border: 1px solid var(--border);
        border-radius: 11px;
        padding: 12px 14px;
        background: var(--surface-alt);
        color: var(--text);
    }

    .facts-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: 16px;
        padding: 20px;
        background: var(--surface-alt);
        border: 1px solid var(--border);
        border-radius: 14px;
        margin: 20px 0;
    }
    .fact-item small { display: block; font-size: 11px; font-weight: 750; color: var(--muted); text-transform: uppercase; letter-spacing: 0.5px; }
    .fact-item strong { font-size: 15px; color: var(--text); word-break: break-word; }

    @media (max-width: 900px) {
        .layout { grid-template-columns: 1fr; }
        .sidebar { border-right: 0; border-bottom: 1px solid var(--border); padding: 18px; gap: 18px; }
        .nav { display: flex; flex-wrap: wrap; }
        .sidebar-bottom { display: none; }
        .main { padding-top: 25px; }
    }
</style>
<link rel="stylesheet" href="<?= e(url('assets/css/admin-unified-v1.css?v=1')) ?>">
<script src="<?= htmlspecialchars(url('assets/js/theme-global.js?v=20261112-1'), ENT_QUOTES, 'UTF-8') ?>"></script>
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <a class="brand" href="<?= e(url('admin/index.php')) ?>">u learn<span>.</span></a>
        <nav class="nav" aria-label="Admin navigation">
            <p class="nav-label">System Management</p>
            <div class="nav">
                <a href="<?= e(url('admin/index.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/></svg><span>Overview</span></a>
                <a class="active" href="<?= e(url('admin/users.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg><span>All Registered Users</span></a>
                <a href="<?= e(url('admin/change-requests.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg><span>Academic Requests</span></a>
                <a href="<?= e(url('admin/universities.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l10 7-10 7L2 9l10-7z"/><path d="M2 17l10 5 10-5"/></svg><span>Universities</span></a>
                <a href="<?= e(url('admin/academics.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1-2.5-2.5Z"/><path d="M6 6h10M6 10h10"/></svg><span>Academic Structure</span></a>
                <a href="<?= e(url('admin/lecturers.php')) ?>"><svg class="ul-nav-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg><span>Lecturers</span></a>
            </div>
        </nav>
        <div class="sidebar-bottom">
            <small>Signed in as</small>
            <strong><?= e((string)$admin['full_name']) ?></strong>
            <small><?= e((string)$admin['email']) ?></small>
        </div>
    </aside>

    <main class="main">
        <div class="topbar">
            <div class="crumb">ULearn / Administration / Account Management</div>
            <?php include __DIR__ . '/../includes/theme-toggle-button.php'; ?>

        <a class="back-link" href="<?= e(url('admin/users.php')) ?>">← Back to directory</a>

        <div class="heading">
            <div>
                <div class="eyebrow">SYSTEM MANAGEMENT / ACCOUNTS</div>
                <h1>Manage account<span>.</span></h1>
                <p>Review identity and account access details without altering protected academic records.</p>
            </div>
            <span class="tag"><?= e($account['role']) ?></span>
        </div>

        <?php if($notice): ?><div class="alert"><?= e($notice) ?></div><?php endif ?>
        <?php if($error): ?><div class="alert error"><?= e($error) ?></div><?php endif ?>

        <section class="panel">
            <h2>Account details</h2>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="action" value="save">

                <div class="field">
                    <label for="full_name">Full name</label>
                    <input id="full_name" name="full_name" required maxlength="120" value="<?= e($account['full_name']) ?>">
                </div>

                <div class="facts-grid">
                    <div class="fact-item">
                        <small>Email</small>
                        <strong><?= e($account['email']) ?></strong>
                    </div>
                    <div class="fact-item">
                        <small>University ID</small>
                        <strong><?= e($account['student_number']?:'#'.$id) ?></strong>
                    </div>
                    <div class="fact-item">
                        <small>Degree</small>
                        <strong><?= e($account['degree_name']?:'—') ?></strong>
                    </div>
                    <div class="fact-item">
                        <small>Intake</small>
                        <strong><?= e($account['batch_code']?:'—') ?></strong>
                    </div>
                    <div class="fact-item">
                        <small>Status</small>
                        <strong><?= e(ucfirst($account['account_status'])) ?></strong>
                    </div>
                </div>

                <?php if(!in_array($account['role'],['admin','main_admin'],true)&&$id!==(int)$admin['id']): ?>
                    <button type="submit" class="button">Save profile changes</button>
                <?php endif ?>
            </form>
        </section>

        <section class="panel">
            <h2>Account access</h2>
            <p style="color:var(--muted); margin-bottom: 20px;">Suspending an account blocks access and invalidates active session tokens. Reversible suspension is used instead of permanent deletion to preserve historical data integrity.</p>
            
            <?php if(!in_array($account['role'],['admin','main_admin'],true)&&$id!==(int)$admin['id']): ?>
                <form method="post" onsubmit="return confirm('Confirm account status change?')">
                    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="action" value="<?= $account['account_status']==='active'?'suspend':'activate' ?>">
                    <button type="submit" class="<?= $account['account_status']==='active'?'button-danger':'button' ?>">
                        <?= $account['account_status']==='active'?'Suspend account':'Reactivate account' ?>
                    </button>
                </form>
            <?php else: ?>
                <p style="color:var(--muted); font-style:italic;">Administrator accounts are protected from modification on this view.</p>
            <?php endif ?>
        </section>
    </main>
</div>

<script>
document.getElementById('theme').addEventListener('click', () => {
    let t = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
    document.documentElement.dataset.theme = t;
    localStorage.setItem('ulearn-theme', t);
});
</script>

</body>
</html>
