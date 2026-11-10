<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/otp-mailer.php';

if (me()) {
    go('dashboard/index.php');
}

$error = '';
$name = '';
$email = '';
$studentNumber = '';
$degreeId = 0; $batchId = 0;
$pdo = db();
$degreeOptions = $pdo->query("SELECT d.id,d.name,d.awarding_university_id,COALESCE(au.name,'Unassigned university') AS university_name FROM academic_degrees d LEFT JOIN awarding_universities au ON au.id=d.awarding_university_id WHERE d.is_active=1 AND au.is_active=1 ORDER BY au.name,d.name")->fetchAll();
$intakeOptions = $pdo->query("SELECT adi.degree_id,adi.batch_id,b.batch_code FROM academic_degree_intakes adi JOIN academic_batches b ON b.id=adi.batch_id WHERE adi.is_active=1 AND b.is_active=1 ORDER BY b.intake_year DESC,b.intake_number")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $name = trim((string)($_POST['name'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');
    $studentNumber = trim((string)($_POST['student_number'] ?? ''));
    $degreeId = (int)($_POST['degree_id'] ?? 0); $batchId = (int)($_POST['batch_id'] ?? 0);

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
    } elseif (!preg_match('/^[A-Za-z0-9._\/-]{3,60}$/', $studentNumber)) {
        $error = 'Enter your valid university student ID (3–60 characters).';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        record_attempt('register');

        try {
            $pdo = db();
            $valid = $pdo->prepare('SELECT 1 FROM academic_degree_intakes adi JOIN academic_degrees d ON d.id=adi.degree_id JOIN awarding_universities au ON au.id=d.awarding_university_id JOIN academic_batches b ON b.id=adi.batch_id WHERE adi.degree_id=? AND adi.batch_id=? AND adi.is_active=1 AND d.is_active=1 AND au.is_active=1 AND b.is_active=1');
            $valid->execute([$degreeId,$batchId]);
            if (!$valid->fetchColumn()) throw new RuntimeException('Select a valid university, degree and linked intake.');
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
                     (full_name, email, password_hash, student_number, role)
                     VALUES (?, ?, ?, ?, 'student')"
                );

                $insert->execute([
                    $name,
                    $email,
                    password_hash($password, PASSWORD_DEFAULT),
                    $studentNumber
                ]);

                $userId = (int)$pdo->lastInsertId();
                $profileInsert=$pdo->prepare('INSERT INTO student_academic_profiles(user_id,degree_id,batch_id) VALUES (?,?,?)');
                $profileInsert->execute([$userId,$degreeId,$batchId]);
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

                // Registration is pending: do not authenticate until the OTP is verified.
                unset($_SESSION['uid'], $_SESSION['session_version']);
                session_regenerate_id(true);
                go('auth/verify.php');
            }

        } catch (RuntimeException $exception) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            $error = $exception->getMessage();
        } catch (PDOException $exception) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = $exception->getCode() === '23000'
                ? 'Email or student ID is already registered.'
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


    <div class="field"><label for="student_number">University student ID</label><input id="student_number" name="student_number" maxlength="60" value="<?= e($studentNumber) ?>" required></div>
    <div class="field"><label for="university_id">University</label><select id="university_id" required><option value="">Choose university</option><?php $seen=[];foreach($degreeOptions as $d):if(isset($seen[$d['awarding_university_id']]))continue;$seen[$d['awarding_university_id']]=true;?><option value="<?= (int)$d['awarding_university_id'] ?>"><?= e($d['university_name']) ?></option><?php endforeach;?></select></div>
    <div class="field"><label for="degree_id">Degree programme</label><select name="degree_id" id="degree_id" required><option value="">Choose degree</option></select></div>
    <div class="field"><label for="batch_id">Intake</label><select name="batch_id" id="batch_id" required><option value="">Choose intake</option></select></div>

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
    const degrees=<?= json_encode($degreeOptions,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
    const intakes=<?= json_encode($intakeOptions,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
    const uni=document.getElementById('university_id'),deg=document.getElementById('degree_id'),batch=document.getElementById('batch_id');
    uni.addEventListener('change',()=>{deg.innerHTML='<option value="">Choose degree</option>';degrees.filter(d=>String(d.awarding_university_id)===uni.value).forEach(d=>deg.add(new Option(d.name,d.id)));deg.dispatchEvent(new Event('change'));});
    deg.addEventListener('change',()=>{batch.innerHTML='<option value="">Choose intake</option>';intakes.filter(b=>String(b.degree_id)===deg.value).forEach(b=>batch.add(new Option(b.batch_code,b.batch_id)));});
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
