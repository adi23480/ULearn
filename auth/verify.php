
<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/otp-mailer.php';

$error = '';
$success = false;
$notice = (string)($_SESSION['otp_notice'] ?? '');
unset($_SESSION['otp_notice']);

$userId = (int)($_SESSION['pending_verification_user'] ?? 0);
$attempts = (int)($_SESSION['verification_attempts'] ?? 0);
$lastSent = (int)($_SESSION['otp_last_sent'] ?? 0);
$resends = (int)($_SESSION['otp_resends'] ?? 0);

$email = '';

if ($userId > 0) {
    $userQuery = db()->prepare(
        'SELECT email, email_verified_at FROM users WHERE id = ?'
    );
    $userQuery->execute([$userId]);
    $pendingUser = $userQuery->fetch();

    if (!$pendingUser || $pendingUser['email_verified_at'] !== null) {
        unset(
            $_SESSION['pending_verification_user'],
            $_SESSION['verification_attempts'],
            $_SESSION['otp_last_sent'],
            $_SESSION['otp_resends'],
            $_SESSION['dev_verification_code']
        );
        $userId = 0;
    } else {
        $email = (string)$pendingUser['email'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action = (string)($_POST['action'] ?? 'verify');

    if (!$userId) {
        $error = 'No pending verification was found. Please log in.';
    } elseif ($action === 'resend') {

        $lastSent = (int)($_SESSION['otp_last_sent'] ?? 0);
        $resends = (int)($_SESSION['otp_resends'] ?? 0);

        if ($resends >= 3) {
            $error = 'Resend limit reached. Please try again later.';
        } elseif ($lastSent > 0 && time() - $lastSent < 60) {
            $error = 'Please wait 60 seconds before requesting another code.';
        } else {
            $_SESSION['otp_last_sent'] = time();
            $_SESSION['otp_resends'] = $resends + 1;

            try {
                $pdo = db();

                $code = (string)random_int(100000, 999999);
                $tokenHash = hash('sha256', $userId . ':' . $code);

                $pdo->beginTransaction();

                $expire = $pdo->prepare(
                    'UPDATE email_verification_tokens
                     SET used_at = NOW()
                     WHERE user_id = ? AND used_at IS NULL'
                );
                $expire->execute([$userId]);

                $insert = $pdo->prepare(
                    'INSERT INTO email_verification_tokens
                     (user_id, token_hash, expires_at)
                     VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))'
                );
                $insert->execute([$userId, $tokenHash]);

                $pdo->commit();

                if (!sendUlearnOtp($email, $code)) {
                    throw new RuntimeException('Email service rejected request.');
                }

                $_SESSION['verification_attempts'] = 0;
                $attempts = 0;

                $notice = 'A new verification code has been sent.';

            } catch (Throwable $exception) {
                if (isset($pdo) && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log(
                    'U Learn resend error: ' . $exception->getMessage()
                );

                $error = 'We could not send a new code. Please try again later.';
            }
        }

    } elseif ($action === 'verify') {

        $code = trim((string)($_POST['code'] ?? ''));
        $attempts = (int)($_SESSION['verification_attempts'] ?? 0);

        if ($attempts >= 5) {
            $error = 'Too many incorrect attempts. Request a new code.';
        } elseif (!preg_match('/^[0-9]{6}$/', $code)) {
            $error = 'Enter a valid 6-digit verification code.';
        } else {
            $_SESSION['verification_attempts'] = $attempts + 1;
            $attempts++;

            try {
                $pdo = db();
                $pdo->beginTransaction();

                $query = $pdo->prepare(
                    'SELECT id, token_hash, expires_at
                     FROM email_verification_tokens
                     WHERE user_id = ?
                       AND used_at IS NULL
                     ORDER BY id DESC
                     LIMIT 1
                     FOR UPDATE'
                );

                $query->execute([$userId]);
                $token = $query->fetch();

                $expectedHash = hash(
                    'sha256',
                    $userId . ':' . $code
                );

                $valid = $token
                    && strtotime($token['expires_at']) > time()
                    && hash_equals(
                        (string)$token['token_hash'],
                        $expectedHash
                    );

                if ($valid) {
                    $updateUser = $pdo->prepare(
                        'UPDATE users
                         SET email_verified_at =
                             COALESCE(email_verified_at, NOW())
                         WHERE id = ?'
                    );
                    $updateUser->execute([$userId]);

                    $updateToken = $pdo->prepare(
                        'UPDATE email_verification_tokens
                         SET used_at = NOW()
                         WHERE id = ?'
                    );
                    $updateToken->execute([$token['id']]);

                    $pdo->commit();

                    unset(
                        $_SESSION['pending_verification_user'],
                        $_SESSION['verification_attempts'],
                        $_SESSION['otp_last_sent'],
                        $_SESSION['otp_resends'],
                        $_SESSION['dev_verification_code']
                    );

                    $success = true;
                } else {
                    $pdo->rollBack();
                    $error = 'Incorrect or expired code. Please try again.';
                }

            } catch (PDOException $exception) {
                if (isset($pdo) && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log(
                    'U Learn verification error: ' . $exception->getMessage()
                );

                $error = 'Verification is temporarily unavailable.';
            }
        }

    } else {
        $error = 'Invalid verification action.';
    }
}

$lastSent = (int)($_SESSION['otp_last_sent'] ?? 0);
$resends = (int)($_SESSION['otp_resends'] ?? 0);
$attempts = (int)($_SESSION['verification_attempts'] ?? 0);

$waitSeconds = $lastSent > 0
    ? max(0, 60 - (time() - $lastSent))
    : 0;

$maskedEmail = $email;

if ($email && strpos($email, '@') !== false) {
    [$local, $domain] = explode('@', $email, 2);
    $maskedEmail = substr($local, 0, 2) . '***@' . $domain;
}

ob_start();
?>

<style>
.otp-destination {
    margin: 16px 0 26px;
    padding: 14px 18px;
    border: 1px solid var(--line, #353545);
    border-radius: 12px;
    color: var(--muted, #a0a3b5);
    font-size: 14px;
    overflow-wrap: anywhere;
}

.otp-destination strong {
    display: block;
    margin-top: 5px;
    color: inherit;
    font-size: 16px;
}

.otp-input {
    text-align: center;
    letter-spacing: 10px;
    font-size: 26px;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
}

.otp-help {
    margin-top: 16px;
    font-size: 13px;
    line-height: 1.7;
    color: var(--muted, #a0a3b5);
}

.otp-resend {
    margin-top: 22px;
    text-align: center;
}

.otp-resend button {
    border: 0;
    padding: 8px 12px;
    background: transparent;
    color: #9384ff;
    font: inherit;
    font-weight: 650;
    cursor: pointer;
}

.otp-resend button:disabled {
    opacity: .5;
    cursor: not-allowed;
}
</style>

<?php if ($success): ?>

    <h2>Email verified.</h2>

    <p class="sub">
        Your U Learn account is ready.
    </p>

    <div class="message success">
        Your email has been verified successfully.
    </div>

    <a class="btn" href="<?= e(url('auth/login.php')) ?>">
        Continue to login &rarr;
    </a>

<?php else: ?>

    <h2>Check your inbox.</h2>

    <p class="sub">
        Enter the six-digit code sent to your email address.
    </p>

    <?php if ($email): ?>
        <div class="otp-destination">
            Verification email sent to
            <strong><?= e($maskedEmail) ?></strong>
        </div>
    <?php endif; ?>

    <?php if ($notice): ?>
        <div class="message success">
            <?= e($notice) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="message error" role="alert">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($userId && $attempts < 5): ?>

        <form method="post">

            <input
                type="hidden"
                name="csrf"
                value="<?= e(csrf()) ?>"
            >

            <input
                type="hidden"
                name="action"
                value="verify"
            >

            <div class="field">
                <label for="code">Verification code</label>

                <input
                    class="otp-input"
                    id="code"
                    name="code"
                    type="text"
                    inputmode="numeric"
                    pattern="[0-9]{6}"
                    minlength="6"
                    maxlength="6"
                    autocomplete="one-time-code"
                    placeholder="000000"
                    required
                    autofocus
                >
            </div>

            <button class="btn" type="submit">
                Verify email &rarr;
            </button>

        </form>

    <?php endif; ?>

    <?php if ($userId && $resends < 3): ?>

        <form method="post" class="otp-resend">

            <input
                type="hidden"
                name="csrf"
                value="<?= e(csrf()) ?>"
            >

            <input
                type="hidden"
                name="action"
                value="resend"
            >

            <button
                id="resend-button"
                type="submit"
                <?= $waitSeconds > 0 ? 'disabled' : '' ?>
            >
                Resend verification code
            </button>

            <p class="otp-help" id="resend-status">
                <?= $waitSeconds > 0
                    ? 'You can request another code in ' . $waitSeconds . ' seconds.'
                    : 'Did not receive the email? Check Spam or request another code.' ?>
            </p>

        </form>

    <?php elseif ($userId): ?>

        <p class="otp-help">
            You have reached the resend limit for this session.
        </p>

    <?php else: ?>

        <p class="otp-help">
            No pending verification was found.
            If you have already verified your email, please log in.
        </p>

    <?php endif; ?>

    <p class="otp-help">
        Verification codes expire after 10 minutes.
        Only the latest code can be used.
    </p>

    <p class="switch">
        Already verified?
        <a href="<?= e(url('auth/login.php')) ?>">Log in</a>
    </p>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const button = document.getElementById('resend-button');
        const status = document.getElementById('resend-status');

        let seconds = <?= (int)$waitSeconds ?>;

        if (!button || !status || seconds <= 0) {
            return;
        }

        const timer = setInterval(function () {
            seconds--;

            if (seconds <= 0) {
                clearInterval(timer);
                button.disabled = false;
                status.textContent =
                    'You can request a new verification code.';
                return;
            }

            status.textContent =
                'You can request another code in ' +
                seconds + ' seconds.';
        }, 1000);
    });
    </script>

<?php endif; ?>

<?php
$content = ob_get_clean();
$title = 'Verify email';
require __DIR__ . '/../includes/auth-layout.php';
