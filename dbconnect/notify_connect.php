<?php
// เปิดผ่านเว็บเพื่อเชื่อมบัญชีกลางสำหรับ worker
session_start();
require_once __DIR__ . '/Connect.php';
require_once __DIR__ . '/notify_lib.php';

try {
    $email = notify_env('MS_NOTIFY_EMAIL');
    $redirectUri = notify_redirect_uri();
    $state = bin2hex(random_bytes(32));
    $verifier = bin2hex(random_bytes(32));
    $params = [
        'client_id' => notify_env('MS_CLIENT_ID'),
        'redirect_uri' => $redirectUri,
        'response_type' => 'code',
        'response_mode' => 'query',
        'scope' => NOTIFY_SCOPE,
        'state' => $state,
        'login_hint' => $email,
        'prompt' => 'select_account',
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
        'code_challenge_method' => 'S256',
    ];
    $_SESSION['notify_oauth'] = [
        'state' => $state, 'verifier' => $verifier,
        'redirect_uri' => $redirectUri, 'expires_at' => time() + 600,
    ];
    header('Location: https://login.microsoftonline.com/' . rawurlencode(notify_env('MS_TENANT_ID'))
        . '/oauth2/v2.0/authorize?' . http_build_query($params));
} catch (Throwable $e) {
    error_log('notify_connect: ' . $e->getMessage());
    http_response_code(500);
    echo 'เชื่อมบัญชีไม่ได้ กรุณาตรวจ config และ PHP error log';
}
exit;
