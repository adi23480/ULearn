<?php
declare(strict_types=1);
require_once __DIR__.'/../config/bootstrap.php';

$admin=require_login();
if(!in_array((string)$admin['role'],['admin','main_admin'],true)){
    http_response_code(403);
    exit('Admin access only');
}

$pdo=db();
$theme=(string)($admin['theme']??'system');
$q=trim((string)($_GET['q']??''));
$role=(string)($_GET['role']??'');
$degree=(int)($_GET['degree']??0);
$batch=(int)($_GET['batch']??0);
$status=(string)($_GET['status']??'');

$where=[];
$params=[];

if($q!==''){
    $where[]='(u.full_name LIKE ? OR u.email LIKE ? OR u.student_number LIKE ? OR CAST(u.id AS CHAR) LIKE ?)';
    $term='%'.substr($q,0,100).'%';
    array_push($params,$term,$term,$term,$term);
}

if(in_array($role,['student','lecturer','admin','main_admin'],true)){
    $where[]='u.role=?';
    $params[]=$role;
}

if($degree>0){
    $where[]='sp.degree_id=?';
    $params[]=$degree;
}

if($batch>0){
    $where[]='sp.batch_id=?';
    $params[]=$batch;
}

if(in_array($status,['active','suspended'],true)){
    $where[]='u.account_status=?';
    $params[]=$status;
}

$sql='SELECT u.id,u.full_name,u.email,u.student_number,u.role,u.account_status,u.created_at,d.name degree_name,au.name university_name,b.batch_code,lp.department,lp.designation FROM users u LEFT JOIN student_academic_profiles sp ON sp.user_id=u.id LEFT JOIN academic_degrees d ON d.id=sp.degree_id LEFT JOIN awarding_universities au ON au.id=d.awarding_university_id LEFT JOIN academic_batches b ON b.id=sp.batch_id LEFT JOIN lecturer_profiles lp ON lp.user_id=u.id'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY u.id DESC LIMIT 300';

$stmt=$pdo->prepare($sql);
$stmt->execute($params);
$users=$stmt->fetchAll(PDO::FETCH_ASSOC);

$degrees=$pdo->query('SELECT d.id,d.name,au.name AS university_name FROM academic_degrees d LEFT JOIN awarding_universities au ON au.id=d.awarding_university_id ORDER BY au.name,d.name,d.id')->fetchAll(PDO::FETCH_ASSOC);

$batches=$pdo->query('SELECT id,batch_code FROM academic_batches ORDER BY batch_code DESC')->fetchAll(PDO::FETCH_ASSOC);

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">

<title>All registered users | ULearn</title>

<script>
const p=<?= json_encode($theme) ?>;
const l=localStorage.getItem('ulearn-theme');

document.documentElement.dataset.theme=
    l==='light'||l==='dark'
        ? l
        : (p==='light'||p==='dark'
            ? p
            : (matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'));
</script>

<link rel="stylesheet" href="<?= e(url('assets/css/admin-stage9.css')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/stage9-profile-v3.css')) ?>">

<script src="<?= htmlspecialchars(url('assets/js/theme-global.js?v=20261107-2'), ENT_QUOTES, 'UTF-8') ?>"></script>

<link rel="stylesheet" href="<?= e(url('assets/css/admin-unified-v1.css?v=1')) ?>">

<style>

/* =========================================
   ULEARN - ALL REGISTERED USERS
   GREEN THEME
========================================= */

:root {
    --users-bg: #f5f8f7;
    --users-surface: #ffffff;
    --users-soft: #eef5f1;
    --users-text: #171927;
    --users-muted: #777b90;
    --users-border: #e2ebe5;
    --users-accent: #087f59;
    --users-accent-soft: #e7f5ee;
}

:root[data-theme="dark"] {
    --users-bg: #0c1720;
    --users-surface: #14241e;
    --users-soft: #1b3028;
    --users-text: #f4f4fa;
    --users-muted: #a0b5aa;
    --users-border: #294638;
    --users-accent: #59dba2;
    --users-accent-soft: #17392e;
}

* {
    box-sizing: border-box;
}

body {
    margin: 0 !important;
    background: var(--users-bg) !important;
    color: var(--users-text) !important;
    font-family: Inter, "Segoe UI", Arial, sans-serif !important;
}

/* Main layout */

.admin-layout {
    display: grid !important;
    grid-template-columns: 255px minmax(0, 1fr) !important;
    min-height: 100vh;
    width: 100%;
}

/* Sidebar */

.admin-side {
    width: 255px;
    min-height: 100vh;
    padding: 30px 20px;
    background: var(--users-surface);
    border-right: 1px solid var(--users-border);
    display: flex;
    flex-direction: column;
    gap: 28px;
}

.admin-side > a.side-link {
    display: flex;
    align-items: center;
    gap: 12px;
    width: 100%;
    padding: 12px 14px;
    border-radius: 11px;
    color: var(--users-muted);
    font-size: 13px;
    font-weight: 650;
    text-decoration: none;
    transition: .2s ease;
}

.admin-side > a.side-link:hover,
.admin-side > a.side-link.active {
    background: var(--users-accent-soft);
    color: var(--users-accent);
}

.admin-side .side-label {
    color: var(--users-muted);
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 1.5px;
    margin: 10px 5px 0;
}

/* Main content */

.admin-main {
    min-width: 0;
    width: 100%;
    padding: 38px clamp(22px, 4vw, 65px) 70px;
}

.heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    margin-bottom: 30px;
}

.heading h1 {
    color: var(--users-text);
    font-size: clamp(30px, 4vw, 43px);
    font-weight: 800;
    letter-spacing: -1.5px;
    margin: 13px 0;
}

.heading p {
    color: var(--users-muted);
    font-size: 14px;
    margin: 0;
}

.eyebrow {
    color: var(--users-muted);
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 1.7px;
}

/* Secondary button */

.secondary {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 11px 16px;
    border: 1px solid var(--users-border);
    border-radius: 10px;
    color: var(--users-accent);
    background: var(--users-surface);
    font-size: 13px;
    font-weight: 700;
    text-decoration: none;
    white-space: nowrap;
}

/* Main panel */

.panel {
    width: 100%;
    padding: 24px;
    background: var(--users-surface);
    border: 1px solid var(--users-border);
    border-radius: 18px;
    box-shadow: 0 10px 35px rgba(20, 20, 50, .04);
}

/* Search and filters */

.filters {
    display: grid;
    grid-template-columns: minmax(180px, 2fr) repeat(4, minmax(110px, 1fr)) auto;
    gap: 10px;
    align-items: center;
}

.filters input,
.filters select {
    width: 100%;
    min-width: 0;
    height: 43px;
    padding: 0 12px;
    border: 1px solid var(--users-border);
    border-radius: 10px;
    background: var(--users-bg);
    color: var(--users-text);
    font-size: 12px;
    outline: none;
}

.filters input:focus,
.filters select:focus {
    border-color: var(--users-accent);
    box-shadow: 0 0 0 3px var(--users-accent-soft);
}

.filters button,
button.primary {
    height: 43px;
    padding: 0 17px;
    border: 0;
    border-radius: 10px;
    background: var(--users-accent);
    color: white;
    font-weight: 750;
    font-size: 12px;
    cursor: pointer;
    white-space: nowrap;
}

.count {
    color: var(--users-muted);
    font-size: 12px;
    margin: 22px 0 15px;
}

/* Table */

.scroll {
    width: 100%;
    overflow-x: auto;
    border: 1px solid var(--users-border);
    border-radius: 13px;
}

.scroll table {
    width: 100%;
    min-width: 800px;
    border-collapse: collapse;
    text-align: left;
}

.scroll thead th {
    padding: 15px 16px;
    background: var(--users-soft);
    color: var(--users-muted);
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 1px;
    white-space: nowrap;
}

.scroll tbody td {
    padding: 15px 16px;
    border-top: 1px solid var(--users-border);
    color: var(--users-text);
    font-size: 12px;
    vertical-align: middle;
}

.scroll tbody tr {
    transition: background .2s;
}

.scroll tbody tr:hover {
    background: var(--users-soft);
}

.scroll td strong {
    display: block;
    font-size: 13px;
    font-weight: 750;
}

.scroll td small {
    display: block;
    margin-top: 4px;
    color: var(--users-muted);
    font-size: 11px;
    overflow-wrap: anywhere;
}

/* Status tags */

.tag {
    display: inline-flex;
    align-items: center;
    padding: 5px 10px;
    border-radius: 30px;
    background: var(--users-soft);
    color: var(--users-muted);
    font-size: 10px;
    font-weight: 800;
    white-space: nowrap;
}

/* Manage link */

.manage {
    display: inline-flex;
    align-items: center;
    padding: 8px 12px;
    border-radius: 9px;
    background: var(--users-accent-soft);
    color: var(--users-accent);
    font-size: 11px;
    font-weight: 750;
    white-space: nowrap;
    text-decoration: none;
}

.manage:hover {
    filter: brightness(.95);
}

/* Responsive */

@media (max-width: 1150px) {
    .filters {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .filters input {
        grid-column: span 2;
    }
}

@media (max-width: 850px) {
    .admin-layout {
        grid-template-columns: 1fr !important;
    }

    .admin-side {
        width: 100%;
        min-height: auto;
        padding: 18px;
        border-right: 0;
        border-bottom: 1px solid var(--users-border);
        gap: 12px;
    }

    .admin-main {
        padding: 25px 20px 50px;
    }
}

@media (max-width: 600px) {
    .filters {
        grid-template-columns: 1fr;
    }

    .filters input {
        grid-column: auto;
    }

    .heading {
        align-items: flex-start;
        flex-direction: column;
    }

    .panel {
        padding: 14px;
    }
}

</style>
</head>

<body>

<div class="admin-layout">

    <aside class="admin-side">

        <?php require __DIR__ . '/../includes/brand-logo.php'; ?>

        <div class="side-label">SYSTEM MANAGEMENT</div>

        <a class="side-link active" href="<?= e(url('admin/users.php')) ?>">
            ◉ All registered users
        </a>

        <a class="side-link" href="<?= e(url('admin/change-requests.php')) ?>">
            ◇ Academic requests
        </a>

        <a class="side-link" href="<?= e(url('admin/index.php')) ?>">
            ⌂ Admin overview
        </a>

    </aside>

    <main class="admin-main">

        <div class="heading">

            <div>
                <div class="eyebrow">SYSTEM MANAGEMENT / DIRECTORY</div>

                <h1>People &amp; Accounts</h1>

                <p>
                    Search, filter and manage registered student and lecturer accounts.
                </p>
            </div>

            <a class="secondary" href="<?= e(url('admin/change-requests.php')) ?>">
                Review requests →
            </a>

        </div>

        <section class="panel">

            <form method="get" class="filters">

                <input
                    name="q"
                    aria-label="Search"
                    placeholder="Search name, email, university ID…"
                    value="<?= e($q) ?>"
                >

                <select name="role">
                    <option value="">All roles</option>

                    <?php foreach(['student','lecturer','admin','main_admin'] as $v): ?>
                        <option value="<?= e($v) ?>" <?= $role===$v?'selected':'' ?>>
                            <?= e(ucwords(str_replace('_',' ',$v))) ?>
                        </option>
                    <?php endforeach ?>
                </select>

                <select name="degree">
                    <option value="0">All degrees</option>

                    <?php foreach($degrees as $d): ?>
                        <option
                            value="<?= (int)$d['id'] ?>"
                            <?= $degree===(int)$d['id']?'selected':'' ?>
                        >
                            <?= e(($d['university_name'] ?: 'Unassigned university').' — '.$d['name']) ?>
                        </option>
                    <?php endforeach ?>
                </select>

                <select name="batch">
                    <option value="0">All intakes</option>

                    <?php foreach($batches as $b): ?>
                        <option
                            value="<?= (int)$b['id'] ?>"
                            <?= $batch===(int)$b['id']?'selected':'' ?>
                        >
                            <?= e($b['batch_code']) ?>
                        </option>
                    <?php endforeach ?>
                </select>

                <select name="status">
                    <option value="">Any status</option>

                    <?php foreach(['active','suspended'] as $v): ?>
                        <option value="<?= e($v) ?>" <?= $status===$v?'selected':'' ?>>
                            <?= ucfirst($v) ?>
                        </option>
                    <?php endforeach ?>
                </select>

                <button class="primary">Apply filters</button>

            </form>

            <p class="count">
                <?= count($users) ?> matching accounts (up to 300 shown)
            </p>

            <div class="scroll">

                <table>

                    <thead>
                        <tr>
                            <th>Account</th>
                            <th>Role</th>
                            <th>Degree / Department</th>
                            <th>Intake</th>
                            <th>Status</th>
                            <th>Manage</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach($users as $u): ?>

                            <tr>

                                <td>
                                    <strong><?= e($u['full_name']) ?></strong>

                                    <small>
                                        <?= e($u['email']) ?> ·
                                        <?= e($u['student_number']?:'#'.$u['id']) ?>
                                    </small>
                                </td>

                                <td>
                                    <span class="tag">
                                        <?= e($u['role']) ?>
                                    </span>
                                </td>

                                <td>
                                    <?= e($u['degree_name']?:($u['department']?:'—')) ?>

                                    <?php if($u['degree_name'] && $u['university_name']): ?>
                                        <small><?= e($u['university_name']) ?></small>
                                    <?php endif ?>

                                    <?php if($u['designation']): ?>
                                        <small><?= e($u['designation']) ?></small>
                                    <?php endif ?>
                                </td>

                                <td>
                                    <?= e($u['batch_code']?:'—') ?>
                                </td>

                                <td>
                                    <span class="tag">
                                        <?= e($u['account_status']) ?>
                                    </span>
                                </td>

                                <td>
                                    <a
                                        class="manage"
                                        href="<?= e(url('admin/account.php')) ?>?id=<?= (int)$u['id'] ?>"
                                    >
                                        View / Edit →
                                    </a>
                                </td>

                            </tr>

                        <?php endforeach ?>

                    </tbody>

                </table>

            </div>

        </section>

    </main>

</div>

</body>
</html>
