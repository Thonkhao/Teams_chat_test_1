<?php
// Microsoft redirect กลับมาที่นี่หลัง login — แลก code เป็น token แล้วเก็บใน session (= login เข้าระบบแล้ว)
// URL ของไฟล์นี้ต้องลงทะเบียนเป็น Redirect URI ของ App ใน Azure และตรงกับ graph_redirect_uri ใน config.php
session_start();
require_once __DIR__ . '/graph_common.php';

function callback_fail($message)
{
    exit(htmlspecialchars($message, ENT_QUOTES) . ' — <a href="chat.php">Login ใหม่</a>');
}

$expectedState = $_SESSION['teams_graph_state'] ?? '';
unset($_SESSION['teams_graph_state']);

if ($expectedState === '' || !hash_equals($expectedState, (string) ($_GET['state'] ?? ''))) {
    callback_fail('state ไม่ถูกต้อง');
}
if (isset($_GET['error'])) {
    callback_fail('Microsoft ปฏิเสธ: ' . ($_GET['error_description'] ?? $_GET['error']));
}
if (empty($_GET['code'])) {
    callback_fail('ไม่พบ code จาก Microsoft');
}

$token = teams_graph_fetch_token([
    'grant_type'   => 'authorization_code',
    'code'         => $_GET['code'],
    'redirect_uri' => teams_graph_config()['redirect_uri'],
]);
if (isset($token['error'])) {
    callback_fail('ขอ token ไม่สำเร็จ: ' . $token['error']);
}

// id ของเจ้าของ token ใช้ตอนสร้างแชท 1:1
[$code, $me] = teams_graph_call('GET', '/me?$select=id,displayName,mail,userPrincipalName', $token['access_token']);
if ($code !== 200) {
    callback_fail('อ่านข้อมูลบัญชีจาก Microsoft ไม่สำเร็จ (HTTP ' . $code . ')');
}

// login สำเร็จ — เปลี่ยน session id กัน session fixation
session_regenerate_id(true);
$_SESSION['teams_graph']    = $token;
$_SESSION['teams_graph_me'] = ['id' => $me['id'], 'name' => $me['displayName'], 'mail' => $me['mail'] ?: $me['userPrincipalName']];
header('Location: chat.php');
exit;
