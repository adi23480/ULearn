<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

/*
|--------------------------------------------------------------------------
| U LEARN - ROLE BASED LOGIN
|--------------------------------------------------------------------------
*/


function loginDestination(string $role): string
{
    switch ($role) {
        case 'lecturer':
            return 'lecturer/index.php';

        case 'admin':
        case 'main_admin':
            return 'admin/index.php';

        case 'student':
        default:
            return 'dashboard/index.php';
    }
}


// Already logged in? Send user to correct dashboard.
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
                account_status,
                session_version,
                role
             FROM users
             WHERE email = ? OR username = ?
             LIMIT 1"
        );

        $stmt->execute([$email, $email]);

        $user = $stmt->fetch();

        if (
            !$user ||
            !password_verify(
                $password,
                (string)$user['password_hash']
            )
        ) {

            $error = 'Invalid email or password.';

        } elseif ($user['account_status'] === 'suspended') {

            $error = 'This account is suspended. Contact the administrator.';

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
                $_SESSION['session_version'] =
                    (int)$user['session_version'];

                unset($_SESSION['attempts']['login']);

                // Show the welcome sequence only after verified credentials.
                // The intro page consumes this one-time session flag.
                $_SESSION['ulearn_post_login_intro'] = true;
                go('auth/welcome.php');
            }
        }
    }
}

ob_start();
?>

<style>
.password-wrapper {
    position: relative;
    width: 100%;
}

.password-wrapper input {
    width: 100%;
    padding-right: 48px !important;
    box-sizing: border-box;
}

.password-toggle {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0;
    border: none;
    background: transparent;
    color: #888;
    cursor: pointer;
    z-index: 2;
}

.password-toggle:hover {
    color: #555;
}

.password-toggle svg {
    width: 19px;
    height: 19px;
    stroke: currentColor;
    fill: none;
    stroke-width: 2;
    stroke-linecap: round;
    stroke-linejoin: round;
}

.password-toggle svg[hidden] {
    display: none !important;
}
</style>

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
            Email address or username
        </label>

        <input
            type="text"
            id="email"
            name="email"
            value="<?= e($email) ?>"
            autocomplete="username"
            required
        >

    </div>

    <div class="field">

        <label for="password">
            Password
        </label>

        <div class="password-wrapper">

            <input
                type="password"
                id="password"
                name="password"
                autocomplete="current-password"
                required
            >

            <button
                type="button"
                class="password-toggle"
                id="togglePassword"
                aria-label="Show password"
                aria-pressed="false"
            >

                <!-- Eye icon -->
                <svg class="icon-eye" viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/>
                    <circle cx="12" cy="12" r="3"/>
                </svg>

                <!-- Eye slash icon -->
                <svg class="icon-eye-off" viewBox="0 0 24 24"
                     aria-hidden="true" hidden>
                    <path d="M3 3l18 18"/>
                    <path d="M10.6 10.6a2 2 0 0 0 2.8 2.8"/>
                    <path d="M9.9 5.2A10.5 10.5 0 0 1 12 5c6.5 0 10 7 10 7a16 16 0 0 1-3.1 3.9"/>
                    <path d="M6.2 6.2C3.5 8 2 12 2 12s3.5 7 10 7a10 10 0 0 0 3-.5"/>
                </svg>

            </button>

        </div>

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

<script>
document.addEventListener('DOMContentLoaded', function () {

    const password = document.getElementById('password');
    const toggle = document.getElementById('togglePassword');

    const eye = toggle.querySelector('.icon-eye');
    const eyeOff = toggle.querySelector('.icon-eye-off');

    toggle.addEventListener('click', function () {

        const isHidden = password.type === 'password';

        password.type = isHidden ? 'text' : 'password';

        eye.hidden = isHidden;
        eyeOff.hidden = !isHidden;

        toggle.setAttribute(
            'aria-label',
            isHidden ? 'Hide password' : 'Show password'
        );

        toggle.setAttribute(
            'aria-pressed',
            isHidden ? 'true' : 'false'
        );

    });

});
</script>

<?php
$content = ob_get_clean();

$title = 'Log in';

require __DIR__ . '/../includes/auth-layout.php';
