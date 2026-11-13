<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$admin = require_login();

if (!in_array((string)$admin['role'], ['admin', 'main_admin'], true)) {
    http_response_code(403);
    exit('Admin access only');
}

$pdo = db();

$theme = (string)($admin['theme'] ?? 'system');

$q       = trim((string)($_GET['q'] ?? ''));
$role    = (string)($_GET['role'] ?? '');
$degree  = (int)($_GET['degree'] ?? 0);
$batch   = (int)($_GET['batch'] ?? 0);
$status  = (string)($_GET['status'] ?? '');

$where = [];
$params = [];

/* SEARCH */
if ($q !== '') {
    $where[] = '(
        u.full_name LIKE ?
        OR u.email LIKE ?
        OR u.student_number LIKE ?
        OR CAST(u.id AS CHAR) LIKE ?
    )';

    $term = '%' . substr($q, 0, 100) . '%';

    array_push($params, $term, $term, $term, $term);
}

/* ROLE FILTER */
if (in_array($role, ['student', 'lecturer', 'admin', 'main_admin'], true)) {
    $where[] = 'u.role = ?';
    $params[] = $role;
}

/* DEGREE FILTER */
if ($degree > 0) {
    $where[] = 'sp.degree_id = ?';
    $params[] = $degree;
}

/* BATCH FILTER */
if ($batch > 0) {
    $where[] = 'sp.batch_id = ?';
    $params[] = $batch;
}

/* STATUS FILTER */
if (in_array($status, ['active', 'suspended'], true)) {
    $where[] = 'u.account_status = ?';
    $params[] = $status;
}

/* FETCH USERS */
$sql = '
    SELECT
        u.id,
        u.full_name,
        u.email,
        u.student_number,
        u.role,
        u.account_status,
        u.created_at,
        d.name AS degree_name,
        au.name AS university_name,
        b.batch_code,
        lp.department,
        lp.designation

    FROM users u

    LEFT JOIN student_academic_profiles sp
        ON sp.user_id = u.id

    LEFT JOIN academic_degrees d
        ON d.id = sp.degree_id

    LEFT JOIN awarding_universities au
        ON au.id = d.awarding_university_id

    LEFT JOIN academic_batches b
        ON b.id = sp.batch_id

    LEFT JOIN lecturer_profiles lp
        ON lp.user_id = u.id

    ' . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . '

    ORDER BY u.id DESC

    LIMIT 300
';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* DEGREE OPTIONS */
$degrees = $pdo->query('
    SELECT
        d.id,
        d.name,
        au.name AS university_name

    FROM academic_degrees d

    LEFT JOIN awarding_universities au
        ON au.id = d.awarding_university_id

    ORDER BY au.name, d.name, d.id
')->fetchAll(PDO::FETCH_ASSOC);

/* BATCH OPTIONS */
$batches = $pdo->query('
    SELECT id, batch_code
    FROM academic_batches
    ORDER BY batch_code DESC
')->fetchAll(PDO::FETCH_ASSOC);

?>

<!doctype html>
<html lang="en">

<head>

<meta charset="utf-8">

<meta name="viewport" content="width=device-width, initial-scale=1">

<title>All Registered Users | ULearn</title>

<!-- THEME INITIALIZATION -->
<script>
(function () {
    const preference = <?= json_encode($theme) ?>;

    const saved = localStorage.getItem('ulearn-theme');

    document.documentElement.dataset.theme =
        saved === 'light' || saved === 'dark'
            ? saved
            : (
                preference === 'light' || preference === 'dark'
                    ? preference
                    : (
                        matchMedia('(prefers-color-scheme: dark)').matches
                            ? 'dark'
                            : 'light'
                    )
            );
})();
</script>

<!-- GLOBAL DESIGN SYSTEM -->
<link rel="stylesheet"
      href="<?= e(url('assets/css/ulearn-design-system.css')) ?>">

<!-- ADMIN USERS PAGE CSS -->
<link rel="stylesheet"
      href="<?= e(url('assets/css/admin-users.css?v=1')) ?>">

<!-- GLOBAL THEME SCRIPT -->
<script src="<?= e(url('assets/js/theme-global.js?v=20261107-2')) ?>"></script>

</head>

<body>

<div class="admin-layout">

    <!-- SIDEBAR -->
    <aside class="admin-side">

        <?php require __DIR__ . '/../includes/brand-logo.php'; ?>

        <div class="side-label">
            SYSTEM MANAGEMENT
        </div>

        <a class="side-link active"
           href="<?= e(url('admin/users.php')) ?>">
            ◉ All registered users
        </a>

        <a class="side-link"
           href="<?= e(url('admin/change-requests.php')) ?>">
            ◇ Academic requests
        </a>

        <a class="side-link"
           href="<?= e(url('admin/index.php')) ?>">
            ⌂ Admin overview
        </a>

    </aside>


    <!-- MAIN CONTENT -->
    <main class="admin-main">

        <!-- PAGE HEADING -->
        <div class="heading">

            <div>

                <div class="eyebrow">
                    SYSTEM MANAGEMENT / DIRECTORY
                </div>

                <h1>People &amp; Accounts</h1>

                <p>
                    Search, filter and manage registered
                    student and lecturer accounts.
                </p>

            </div>

            <a class="secondary"
               href="<?= e(url('admin/change-requests.php')) ?>">

                Review requests →

            </a>

        </div>


        <!-- USERS PANEL -->
        <section class="panel">

            <!-- SEARCH AND FILTERS -->
            <form method="get" class="filters">

                <input
                    type="search"
                    name="q"
                    aria-label="Search users"
                    placeholder="Search name, email, university ID..."
                    value="<?= e($q) ?>"
                >


                <!-- ROLE -->
                <select name="role">

                    <option value="">All roles</option>

                    <?php foreach (
                        ['student', 'lecturer', 'admin', 'main_admin']
                        as $v
                    ): ?>

                        <option
                            value="<?= e($v) ?>"
                            <?= $role === $v ? 'selected' : '' ?>
                        >

                            <?= e(ucwords(str_replace('_', ' ', $v))) ?>

                        </option>

                    <?php endforeach; ?>

                </select>


                <!-- DEGREE -->
                <select name="degree">

                    <option value="0">All degrees</option>

                    <?php foreach ($degrees as $d): ?>

                        <option
                            value="<?= (int)$d['id'] ?>"
                            <?= $degree === (int)$d['id'] ? 'selected' : '' ?>
                        >

                            <?= e(
                                ($d['university_name'] ?: 'Unassigned university')
                                . ' — '
                                . $d['name']
                            ) ?>

                        </option>

                    <?php endforeach; ?>

                </select>


                <!-- BATCH -->
                <select name="batch">

                    <option value="0">All intakes</option>

                    <?php foreach ($batches as $b): ?>

                        <option
                            value="<?= (int)$b['id'] ?>"
                            <?= $batch === (int)$b['id'] ? 'selected' : '' ?>
                        >

                            <?= e($b['batch_code']) ?>

                        </option>

                    <?php endforeach; ?>

                </select>


                <!-- STATUS -->
                <select name="status">

                    <option value="">Any status</option>

                    <?php foreach (['active', 'suspended'] as $v): ?>

                        <option
                            value="<?= e($v) ?>"
                            <?= $status === $v ? 'selected' : '' ?>
                        >

                            <?= e(ucfirst($v)) ?>

                        </option>

                    <?php endforeach; ?>

                </select>


                <button type="submit" class="primary">
                    Apply filters
                </button>

            </form>


            <!-- ACCOUNT COUNT -->
            <p class="count">

                <?= count($users) ?> matching accounts
                (up to 300 shown)

            </p>


            <!-- USERS TABLE -->
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

                    <?php if (!$users): ?>

                        <tr>

                            <td colspan="6" style="text-align:center;padding:35px;">

                                No registered users found.

                            </td>

                        </tr>

                    <?php else: ?>

                        <?php foreach ($users as $u): ?>

                            <tr>

                                <!-- ACCOUNT -->
                                <td>

                                    <strong>
                                        <?= e($u['full_name']) ?>
                                    </strong>

                                    <small>
                                        <?= e($u['email']) ?>
                                        ·
                                        <?= e($u['student_number'] ?: '#' . $u['id']) ?>
                                    </small>

                                </td>


                                <!-- ROLE -->
                                <td>

                                    <span class="tag">
                                        <?= e(ucwords(str_replace('_', ' ', $u['role']))) ?>
                                    </span>

                                </td>


                                <!-- DEGREE / DEPARTMENT -->
                                <td>

                                    <?= e(
                                        $u['degree_name']
                                        ?: ($u['department'] ?: '—')
                                    ) ?>

                                    <?php if (
                                        $u['degree_name']
                                        && $u['university_name']
                                    ): ?>

                                        <small>
                                            <?= e($u['university_name']) ?>
                                        </small>

                                    <?php endif; ?>


                                    <?php if ($u['designation']): ?>

                                        <small>
                                            <?= e($u['designation']) ?>
                                        </small>

                                    <?php endif; ?>

                                </td>


                                <!-- INTAKE -->
                                <td>

                                    <?= e($u['batch_code'] ?: '—') ?>

                                </td>


                                <!-- STATUS -->
                                <td>

                                    <span class="tag">
                                        <?= e(ucfirst($u['account_status'] ?? 'Unknown')) ?>
                                    </span>

                                </td>


                                <!-- MANAGE -->
                                <td>

                                    <a
                                        class="manage"
                                        href="<?= e(url('admin/account.php')) ?>?id=<?= (int)$u['id'] ?>"
                                    >

                                        View / Edit →

                                    </a>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </section>

    </main>

</div>

</body>
</html>
