<?php
declare(strict_types=1);
/**
 * uLearn AI diagnostics — open http://localhost/ulearn/ai-test.php
 * Works only while LOCAL_DEVELOPMENT is true. DELETE this file before deploying.
 */
require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/includes/ai-helper.php';

if (!(defined('LOCAL_DEVELOPMENT') && LOCAL_DEVELOPMENT) || !in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('Diagnostics are only available on localhost.');
}
@set_time_limit(120);

function row(string $label, bool $ok, string $detail = ''): void
{
    echo '<tr><td>' . htmlspecialchars($label) . '</td><td class="' . ($ok ? 'ok' : 'bad') . '">' . ($ok ? 'OK' : 'PROBLEM') . '</td><td>' . htmlspecialchars($detail) . '</td></tr>';
}
function mask(string $k): string { return strlen($k) > 10 ? substr($k, 0, 4) . '…' . substr($k, -3) . ' (' . strlen($k) . ' chars)' : '(empty)'; }

?><!DOCTYPE html><html><head><meta charset="utf-8"><title>uLearn AI diagnostics</title>
<style>body{font:15px system-ui;background:#0b0f19;color:#e8eaf3;max-width:900px;margin:30px auto;padding:0 16px}
table{width:100%;border-collapse:collapse;margin:14px 0}td{border-bottom:1px solid #263050;padding:8px 10px;vertical-align:top}
.ok{color:#3ddc97;font-weight:700}.bad{color:#ff6b7a;font-weight:700}pre{background:#141a2b;padding:12px;border-radius:8px;white-space:pre-wrap}h1{margin-bottom:0}small{color:#8a93b2}</style></head><body>
<h1>uLearn AI diagnostics</h1><small>Delete this file before deploying.</small>
<table>
<?php
row('PHP version ≥ 8.1', PHP_VERSION_ID >= 80100, PHP_VERSION);
row('cURL extension', extension_loaded('curl'));
row('mbstring extension', extension_loaded('mbstring'));
row('openssl extension', extension_loaded('openssl'));
row('Gemini key set', uai_key_ok(GEMINI_API_KEY), mask(GEMINI_API_KEY));
row('OpenRouter key set', uai_key_ok(OPENROUTER_API_KEY), mask(OPENROUTER_API_KEY));
$u = me();
row('Logged in (open the dashboard first, same browser)', (bool)$u, $u ? $u['full_name'] . ' (' . $u['role'] . ')' : 'not logged in — the bot needs a logged-in session');
row('ai-chat.php exists', is_file(__DIR__ . '/dashboard/ai-chat.php'));
row('student-bot.php exists', is_file(__DIR__ . '/includes/student-bot.php'));

$models = uai_discover_models(true);
row('Google reachable + model list', (bool)$models, $models ? implode(', ', array_slice($models, 0, 8)) : 'could not list models (bad key, no internet or blocked)');

$t = microtime(true);
$r = uai_generate('Reply with exactly: Hello from uLearn Buddy!', ['max_tokens' => 256, 'timeout' => 30]);
row('Test generation', $r['ok'], $r['ok'] ? $r['model'] . ' → "' . $r['text'] . '" (' . round(microtime(true) - $t, 1) . 's)' : 'all providers failed');
?>
</table>
<?php if (!$r['ok'] || $r['debug']): ?><h3>Provider errors</h3><pre><?= htmlspecialchars(implode("\n\n", $r['debug']) ?: 'None') ?></pre><?php endif; ?>
</body></html>