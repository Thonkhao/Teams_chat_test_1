<?php
// ออกจากระบบ — ล้าง session ของเว็บนี้ แล้ว logout ออกจาก Microsoft ด้วย
// (ไม่งั้นกลับมาหน้า chat.php จะ login อัตโนมัติด้วยบัญชีเดิมทันที)
session_start();
require_once __DIR__ . '/graph_common.php';

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();

header('Location: https://login.microsoftonline.com/' . rawurlencode(teams_graph_config()['tenant']) . '/oauth2/v2.0/logout');
exit;
