<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

/*
|--------------------------------------------------------------------------
| U LEARN - ROLE BASED LOGIN
|--------------------------------------------------------------------------
| student    -> Student Dashboard
| lecturer   -> Lecturer Dashboard
| admin      -> Admin Dashboard
| main_admin -> Admin Dashboard
|--------------------------------------------------------------------------
*/

function loginDestination(string $role): string
{
    switch ($role) {
        case 'lecturer':
            return 'lecturer/index.php';

        case 'admin':
        case 'main_admin':
            return 'admin/lecturers.php';

        case 'student':
        default:
            return 'dashboard/index.php';
    }
}

// Already logged in? Send user to the correct dashboard.
$currentUser = me();

if ($currentUser) {
    go(loginDestination((string)$currentUser['role']));
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_check();

    $email = strtolower(
        trim((string)($_POST['email'] ?? ''))
    );

    $password = (string)($_POST['password'] ?? '');

    if (too_many_attempts('login', 8, 900)) {

        $error = 'Too many login attempts. Try again in 15 minutes.';

    } else {

        record_attempt('login');

        $stmt = db()->prepare(
            "SELECT
                id,
                password_hash,
                email_verified_at,
                role
             FROM users
             WHERE email = ?
             LIMIT 1"
        );

        $stmt->execute([$email]);

        $user = $stmt->fetch();

        if (
            !$user ||
            !password_verify(
                $password,
                (string)$user['password_hash']
            )
        ) {

            $error = 'Invalid email or password.';

        } elseif (empty($user['email_verified_at'])) {

            $error = 'Please verify your email before logging in.';

        } else {

            // Lecturer access must also be active.
            if ($user['role'] === 'lecturer') {

                $check = db()->prepare(
                    "SELECT id
                     FROM lecturer_profiles
                     WHERE user_id = ?
                       AND is_active = 1
                     LIMIT 1"
                );

                $check->execute([(int)$user['id']]);

                if (!$check->fetch()) {
                    $error =
                        'Your lecturer access is not active. ' .
                        'Please contact an administrator.';
                }
            }

            if ($error === '') {

                session_regenerate_id(true);

                $_SESSION['uid'] = (int)$user['id'];

                unset($_SESSION['attempts']['login']);

                go(
                    loginDestination(
                        (string)$user['role']
                    )
                );
            }
        }
    }
}

ob_start();
?>

<h2>Welcome back</h2>

<p class="sub">
    Log in to continue your U Learn journey.
</p>

<?php if ($error): ?>

    <div class="message error" role="alert">
        <?= e($error) ?>
    </div>

<?php endif; ?>

<form method="post" autocomplete="on">

    <input
        type="hidden"
        name="csrf"
        value="<?= e(csrf()) ?>"
    >

    <div class="field">

        <label for="email">
            Email address
        </label>

        <input
            type="email"
            id="email"
            name="email"
            value="<?= e($email) ?>"
            autocomplete="email"
            required
        >

    </div>

    <div class="field">

        <label for="password">
            Password
        </label>

        <input
            type="password"
            id="password"
            name="password"
            autocomplete="current-password"
            required
        >

    </div>

    <button class="btn" type="submit">
        Log in &rarr;
    </button>

</form>

<p class="switch">
    New to U Learn?
    <a href="<?= e(url('auth/register.php')) ?>">
        Create an account
    </a>
</p>

<?php
$content = ob_get_clean();

$title = 'Log in';

require __DIR__ . '/../includes/auth-layout.php';
