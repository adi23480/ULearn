<?php
declare(strict_types=1);
const APP_BASE = '/ulearn';
const LOCAL_DEVELOPMENT = true; // MUST be false for public deployment
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params(['httponly'=>true,'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off','samesite'=>'Lax','path'=>'/']);
    session_start();
}
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

function db(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        $config = require __DIR__ . '/local.php';

        $dsn = 'mysql:host=' . $config['db_host']
             . ';dbname=' . $config['db_name']
             . ';charset=utf8mb4';

        $pdo = new PDO(
            $dsn,
            $config['db_user'],
            $config['db_password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]
        );
    }

    return $pdo;
}

function e(string $s): string {return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function url(string $path=''): string {return APP_BASE.'/'.$path;}
function go(string $path): never {header('Location: '.url($path),true,303);exit;}
function csrf(): string {return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));}
function csrf_check(): void {if (!hash_equals(csrf(),(string)($_POST['csrf']??''))) {http_response_code(419);exit('Form expired. Refresh the page and try again.');}}
function me(): ?array {
    if (!isset($_SESSION['uid'])) return null;
    $q=db()->prepare('SELECT id,full_name,email,role,theme,email_verified_at,student_number,account_status,session_version FROM users WHERE id=?');
    $q->execute([$_SESSION['uid']]);
    $u=$q->fetch() ?: null;
    if (!$u || $u['account_status']==='suspended' || empty($u['email_verified_at']) || (int)($u['session_version']??0)!==(int)($_SESSION['session_version']??0)) {
        unset($_SESSION['uid'],$_SESSION['session_version']); return null;
    }
    return $u;
}
function require_login(): array {
    $u=me();if (!$u) go('auth/login.php');
    return $u;
}
function too_many_attempts(string $key,int $limit,int $seconds): bool {
    $now=time();$times=array_filter($_SESSION['attempts'][$key]??[],fn($t)=>$t>$now-$seconds);
    $_SESSION['attempts'][$key]=array_values($times);
    return count($times)>=$limit;
}
function record_attempt(string $key): void {$_SESSION['attempts'][$key][]=time();}
