
<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/otp-mailer.php';

if (me()) {
    go('dashboard/index.php');
}

$error = '';
$name = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $name = trim((string)($_POST['name'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    if (too_many_attempts('register', 6, 3600)) {
        $error = 'Too many registration attempts. Please try again later.';
    } elseif (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
        $error = 'Enter your full name (2–120 characters).';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
        $error = 'Enter a valid email address.';
    } elseif (
        strlen($password) < 8 ||
        !preg_match('/[A-Z]/', $password) ||
        !preg_match('/[0-9]/', $password) ||
        !preg_match('/[^a-zA-Z0-9]/', $password)
    ) {
        $error = 'Password needs at least 8 characters, an uppercase letter, a number and a symbol.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        record_attempt('register');

        try {
            $pdo = db();

            // Avoid creating another account for an existing email.
            $check = $pdo->prepare(
                'SELECT id FROM users WHERE email = ? LIMIT 1'
            );
            $check->execute([$email]);

            if ($check->fetch()) {
                $error = 'This email is already registered. Please log in.';
            } else {
                $pdo->beginTransaction();

                $insert = $pdo->prepare(
                    "INSERT INTO users
                     (full_name, email, password_hash, role)
                     VALUES (?, ?, ?, 'student')"
                );

                $insert->execute([
                    $name,
                    $email,
                    password_hash($password, PASSWORD_DEFAULT)
                ]);

                $userId = (int)$pdo->lastInsertId();
                $code = (string)random_int(100000, 999999);

                $tokenHash = hash('sha256', $userId . ':' . $code);

                $insertToken = $pdo->prepare(
                    'INSERT INTO email_verification_tokens
                     (user_id, token_hash, expires_at)
                     VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))'
                );

                $insertToken->execute([$userId, $tokenHash]);

                $pdo->commit();

                $_SESSION['pending_verification_user'] = $userId;
                $_SESSION['verification_attempts'] = 0;
                $_SESSION['otp_last_sent'] = 0;
                $_SESSION['otp_resends'] = 0;

                // Remove the old development-only OTP.
                unset($_SESSION['dev_verification_code']);

                try {
                    if (!sendUlearnOtp($email, $code)) {
                        throw new RuntimeException('Email sending failed.');
                    }

                    $_SESSION['otp_last_sent'] = time();
                    $_SESSION['otp_notice'] =
                        'We sent a verification code to your email address.';
                } catch (Throwable $mailError) {
                    error_log('U Learn OTP mail error: ' . $mailError->getMessage());

                    $_SESSION['otp_notice'] =
                        'Your account was created, but we could not send the code. Please try Resend code.';
                }

                go('auth/verify.php');
            }

        } catch (PDOException $exception) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = $exception->getCode() === '23000'
                ? 'This email is already registered.'
                : 'Registration is unavailable. Please check the database.';
        }
    }
}

ob_start();
?>

<style>
.password-hint {
    margin: 8px 0 0;
    font-size: 12px;
    line-height: 1.6;
    color: var(--muted, #a0a3b5);
}
.password-hint.good { color: #39b88b; }
.password-hint.bad { color: #ee7786; }
</style>

<h2>Create your account</h2>
<p class="sub">Start learning with your personal email address.</p>

<?php if ($error): ?>
    <div class="message error" role="alert">
        <?= e($error) ?>
    </div>
<?php endif; ?>

<form method="post" autocomplete="on">

    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">

    <div class="field">
        <label for="name">Full name</label>
        <input
            id="name"
            name="name"
            value="<?= e($name) ?>"
            maxlength="120"
            autocomplete="name"
            required
        >
    </div>

    <div class="field">
        <label for="email">Email address</label>
        <input
            id="email"
            name="email"
            type="email"
            value="<?= e($email) ?>"
            maxlength="254"
            autocomplete="email"
            required
        >
    </div>

    <div class="field">
        <label for="password">Password</label>
        <input
            id="password"
            name="password"
            type="password"
            minlength="8"
            autocomplete="new-password"
            required
        >
        <p class="password-hint">
            At least 8 characters, one uppercase letter,
            one number and one symbol.
        </p>
    </div>

    <div class="field">
        <label for="confirm_password">Confirm password</label>
        <input
            id="confirm_password"
            name="confirm_password"
            type="password"
            minlength="8"
            autocomplete="new-password"
            required
        >
        <p
            id="password-match"
            class="password-hint"
            aria-live="polite"
        ></p>
    </div>

    <button class="btn" type="submit">
        Create account &rarr;
    </button>

</form>

<p class="switch">
    Already registered?
    <a href="<?= e(url('auth/login.php')) ?>">Log in</a>
</p>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const password = document.getElementById('password');
    const confirm = document.getElementById('confirm_password');
    const message = document.getElementById('password-match');

    function updateMatch() {
        if (!confirm.value) {
            message.textContent = '';
            message.className = 'password-hint';
            return;
        }

        const matches = password.value === confirm.value;

        message.textContent = matches
            ? 'Passwords match'
            : 'Passwords do not match';

        message.className = matches
            ? 'password-hint good'
            : 'password-hint bad';
    }

    password.addEventListener('input', updateMatch);
    confirm.addEventListener('input', updateMatch);
});
</script>

<?php
$content = ob_get_clean();
$title = 'Create account';
require __DIR__ . '/../includes/auth-layout.php';
