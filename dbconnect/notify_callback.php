<?php
session_start();
require_once __DIR__ . '/Connect.php';
require_once __DIR__ . '/notify_lib.php';
header('Cache-Control: no-store');
header('Content-Type: text/plain; charset=utf-8');

$oauth = $_SESSION['notify_oauth'] ?? [];
unset($_SESSION['notify_oauth']);
if (empty($oauth['state']) || ($oauth['expires_at'] ?? 0) < time()
    || !is_string($_GET['state'] ?? null) || !hash_equals($oauth['state'], $_GET['state'])) {
    http_response_code(400);
    exit('คำขอไม่ถูกต้องหรือหมดอายุ กรุณาเปิด notify_connect.php ใหม่');
}
if (isset($_GET['error']) || !is_string($_GET['code'] ?? null) || $_GET['code'] === '') {
    http_response_code(400);
    exit('Microsoft ไม่ได้อนุมัติการเชื่อมบัญชี กรุณาเปิด notify_connect.php ใหม่');
}

try {
    $r = notify_http('POST', notify_token_endpoint(),
        ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
            'grant_type' => 'authorization_code',
            'client_id' => notify_env('MS_CLIENT_ID'),
            'client_secret' => notify_env('MS_CLIENT_SECRET'),
            'code' => $_GET['code'],
            'redirect_uri' => $oauth['redirect_uri'],
            'code_verifier' => $oauth['verifier'],
            'scope' => NOTIFY_SCOPE,
        ]));
    $token = $r['json'];
    if ($r['code'] !== 200 || empty($token['access_token']) || empty($token['refresh_token'])) {
        throw new RuntimeException('Token exchange failed or refresh token missing. HTTP ' . $r['code']);
    }
    $me = notify_graph('GET', '/me?$select=id,mail,userPrincipalName', $token['access_token']);
    if ($me['code'] !== 200 || empty($me['json']['id'])) {
        throw new RuntimeException('Cannot read sender account. HTTP ' . $me['code']);
    }
    $sender = trim(notify_env('MS_NOTIFY_EMAIL'));
    if (strcasecmp($sender, $me['json']['mail'] ?? '') !== 0
        && strcasecmp($sender, $me['json']['userPrincipalName'] ?? '') !== 0) {
        http_response_code(403);
        exit('กรุณาเชื่อมด้วยบัญชีกลางที่ระบุใน MS_NOTIFY_EMAIL');
    }
    notify_token_save([
        'access_token' => $token['access_token'],
        'refresh_token' => $token['refresh_token'],
        'expires_at' => time() + (int) ($token['expires_in'] ?? 3600),
        'me_id' => $me['json']['id'],
    ]);
    session_regenerate_id(true);
    echo 'เชื่อมบัญชีกลางสำเร็จแล้ว สามารถรัน notify_worker.php ได้';
} catch (Throwable $e) {
    error_log('notify_callback: ' . $e->getMessage());
    http_response_code(500);
    echo 'เชื่อมบัญชีไม่สำเร็จ กรุณาตรวจ PHP error log แล้วเปิด notify_connect.php ใหม่';
}
