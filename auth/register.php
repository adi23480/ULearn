
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
        preg_match_all('/./us', $password) < 8 ||
        !preg_match('/[A-Z]/', $password) ||
        !preg_match('/[0-9]/', $password) ||
        preg_match_all('/[^\\p{L}\\p{N}\\s]/u', $password) < 2
    ) {
        $error = 'Password needs at least 8 characters, an uppercase letter, a number and at least 2 symbols.';
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

        <div class="password-input-wrap">
            <input
                id="password"
                name="password"
                type="password"
                minlength="8"
                autocomplete="new-password"
                aria-describedby="password-rules"
                required
            >

            <button
                type="button"
                class="password-toggle"
                data-password-toggle="password"
                aria-label="Show password"
                aria-pressed="false"
            >
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7z"></path>
                    <circle cx="12" cy="12" r="3"></circle>
                </svg>
            </button>
        </div>

        <div class="password-strength" id="password-rules" aria-live="polite">
            <div class="strength-heading">
                <span>Password requirements</span>
                <span id="strength-label">Not started</span>
            </div>

            <div class="strength-track">
                <span id="strength-bar"></span>
            </div>

            <ul class="password-rules">
                <li data-rule="length"><span class="rule-icon">○</span> At least 8 characters</li>
                <li data-rule="uppercase"><span class="rule-icon">○</span> At least one uppercase letter (A–Z)</li>
                <li data-rule="number"><span class="rule-icon">○</span> At least one number (0–9)</li>
                <li data-rule="symbols"><span class="rule-icon">○</span> At least 2 special symbols (!, @, #, $, etc.)</li>
            </ul>
        </div>
    </div>

    <div class="field">
        <label for="confirm_password">Confirm password</label>

        <div class="password-input-wrap">
            <input
                id="confirm_password"
                name="confirm_password"
                type="password"
                minlength="8"
                autocomplete="new-password"
                required
            >

            <button
                type="button"
                class="password-toggle"
                data-password-toggle="confirm_password"
                aria-label="Show confirm password"
                aria-pressed="false"
            >
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7z"></path>
                    <circle cx="12" cy="12" r="3"></circle>
                </svg>
            </button>
        </div>

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
    const matchMessage = document.getElementById('password-match');
    const strengthLabel = document.getElementById('strength-label');
    const strengthBar = document.getElementById('strength-bar');
function updatePasswordRules() {
        const value = password.value;
        const symbolMatches = value.match(/[^\p{L}\p{N}\s]/gu);
        const rules = {
            length: Array.from(value).length >= 8,
            uppercase: /[A-Z]/.test(value),
            number: /[0-9]/.test(value),
            symbols: (symbolMatches ? symbolMatches.length : 0) >= 2
        };

        let passed = 0;

        Object.keys(rules).forEach(function (ruleName) {
            const item = document.querySelector('[data-rule="' + ruleName + '"]');
            const icon = item.querySelector('.rule-icon');
            item.classList.remove('good', 'bad');

            if (rules[ruleName]) {
                item.classList.add('good');
                icon.textContent = '✓';
                passed++;
            } else if (value.length > 0) {
                item.classList.add('bad');
                icon.textContent = '✕';
            } else {
                icon.textContent = '○';
            }
        });

        const labels = ['Not started', 'Weak', 'Fair', 'Good', 'Strong'];
        strengthLabel.textContent = value.length === 0 ? labels[0] : labels[passed];
        strengthBar.style.width = (passed * 25) + '%';
        strengthBar.classList.toggle('complete', passed === 4);

        updateMatch();
    }

    function updateMatch() {
        if (!confirm.value) {
            matchMessage.textContent = '';
            matchMessage.className = 'password-hint';
            return;
        }

        const matches = password.value === confirm.value;
        matchMessage.textContent = matches
            ? '✓ Passwords match'
            : '✕ Passwords do not match';

        matchMessage.className = matches
            ? 'password-hint good'
            : 'password-hint bad';
    }

    password.addEventListener('input', updatePasswordRules);
    confirm.addEventListener('input', updateMatch);
    updatePasswordRules();
});
</script>

<?php
$content = ob_get_clean();
$title = 'Create account';
require __DIR__ . '/../includes/auth-layout.php';
