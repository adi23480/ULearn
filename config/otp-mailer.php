
<?php

/**
 * U Learn - Google Apps Script OTP Mailer
 *
 * Sends verification emails from the PHP server.
 * Never call Apps Script directly from browser JavaScript.
 */

function sendUlearnOtp(string $email, string $code): bool
{
    $configPath = 'C:/xampp/ulearn-private/mail-config.php';

    if (!is_file($configPath)) {
        throw new RuntimeException('Private email configuration is missing.');
    }

    $config = require $configPath;

    $url = (string)($config['apps_script_url'] ?? '');
    $secret = (string)($config['apps_script_secret'] ?? '');

    if (
        !preg_match(
            '~^https://script\.google\.com/macros/s/[^/]+/exec$~',
            $url
        )
    ) {
        throw new RuntimeException('Invalid Apps Script deployment URL.');
    }

    if (strlen($secret) < 32) {
        throw new RuntimeException('Apps Script secret is not configured.');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Invalid recipient email.');
    }

    if (!preg_match('/^[0-9]{6}$/', $code)) {
        throw new InvalidArgumentException('Invalid verification code.');
    }

    if (!function_exists('curl_init')) {
        throw new RuntimeException(
            'PHP cURL extension is not enabled in XAMPP.'
        );
    }

    $payload = json_encode([
        'secret' => $secret,
        'email' => $email,
        'code' => $code
    ], JSON_THROW_ON_ERROR);

    $curl = curl_init($url);

    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    try {
        $response = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        if ($response === false) {
            throw new RuntimeException(
                'Email service connection failed: ' . curl_error($curl)
            );
        }

        if ($status !== 200) {
            throw new RuntimeException(
                'Email service returned HTTP ' . $status
            );
        }

        $result = json_decode($response, true);

        if (!is_array($result) || ($result['success'] ?? false) !== true) {
            throw new RuntimeException(
                'Email service did not confirm delivery.'
            );
        }

        return true;

    } finally {
        curl_close($curl);
    }
}
