<?php
// ไฟล์นี้ถูก require จากหน้า callback login เดิม เมื่อ state ขึ้นต้นด้วย notify_
// (ไม่ได้เรียกตรงๆ) ใช้ $_SESSION และ $_ENV ที่หน้าเดิมโหลดไว้แล้ว
require_once __DIR__ . '/notify_lib.php';
function notify_cb_fail($msg)
{
    http_response_code(400);
    echo 'เชื่อมบัญชีกลางไม่สำเร็จ: ' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
    exit;
}
$allowed = array_filter(array_map('trim', explode(',', $_ENV['NOTIFY_ADMIN_UIDS'] ?? '')));
if (empty($_SESSION['uid']) || !in_array((string) $_SESSION['uid'], $allowed, true)) {
    http_response_code(403);
    exit('ไม่มีสิทธิ์');
}
$expected = $_SESSION['notify_state'] ?? '';
unset($_SESSION['notify_state']);
if ($expected === '' || !hash_equals($expected, (string) ($_GET['state'] ?? ''))) {
    notify_cb_fail('state ไม่ตรง ให้เริ่มใหม่จาก notify_connect.php');
}
if (isset($_GET['error'])) {
    notify_cb_fail((string) ($_GET['error_description'] ?? $_GET['error']));
}
if (empty($_GET['code'])) {
    notify_cb_fail('ไม่มี code');
}
$r = notify_http('POST', notify_token_endpoint(),
    ['Content-Type: application/x-www-form-urlencoded'],
    http_build_query([
        'client_id'     => notify_env('MS_CLIENT_ID'),
        'client_secret' => notify_env('MS_CLIENT_SECRET'),
        'code'          => $_GET['code'],
        'redirect_uri'  => notify_env('MS_REDIRECT_URI'),
        'grant_type'    => 'authorization_code',
        'scope'         => NOTIFY_SCOPE,
    ]));
if ($r['code'] !== 200 || empty($r['json']['refresh_token'])) {
    notify_cb_fail($r['json']['error_description'] ?? ('HTTP ' . $r['code']));
}
$access = $r['json']['access_token'];
$me = notify_graph('GET', '/me?$select=id,mail,userPrincipalName', $access);
if ($me['code'] !== 200) {
    notify_cb_fail('อ่านข้อมูลบัญชีไม่ได้');
}
$mail = strtolower($me['json']['mail'] ?? $me['json']['userPrincipalName'] ?? '');
// ถ้ากำหนด NOTIFY_ACCOUNT ใน .env ต้อง login ด้วยบัญชีนั้นเท่านั้น
$want = strtolower(trim($_ENV['NOTIFY_ACCOUNT'] ?? ''));
if ($want !== '' && $mail !== $want) {
    notify_cb_fail("login ด้วย $mail แต่ต้องใช้ $want");
}
try {
    notify_token_save([
        'access_token'  => $access,
        'refresh_token' => $r['json']['refresh_token'],
        'expires_at'    => time() + (int) $r['json']['expires_in'],
        'me_id'         => $me['json']['id'],
        'account'       => $mail,
    ]);
} catch (Throwable $e) {
    notify_cb_fail($e->getMessage());
}
echo 'เชื่อมบัญชีกลางสำเร็จ: ' . htmlspecialchars($mail, ENT_QUOTES, 'UTF-8') . ' (ปิดหน้านี้ได้)';
exit;
 