<?php
// ส่งผู้ใช้ไปหน้า login ของ Microsoft — ใช้เป็นการ login เข้าระบบ และขอสิทธิ์ส่งแชทในนามตัวเองไปพร้อมกัน
session_start();
require_once __DIR__ . '/graph_common.php';

$cfg = teams_graph_config();
if ($cfg['redirect_uri'] === '') {
    exit('ยังไม่ได้ตั้งค่า graph_redirect_uri ใน config.php');
}

// state กันการปลอม callback (CSRF ของ OAuth)
$_SESSION['teams_graph_state'] = bin2hex(random_bytes(16));

$params = [
    'client_id'     => $cfg['client_id'],
    'redirect_uri'  => $cfg['redirect_uri'],
    'response_type' => 'code',
    'response_mode' => 'query',
    'scope'         => TEAMS_GRAPH_SCOPES,
    'state'         => $_SESSION['teams_graph_state'],
];

header('Location: https://login.microsoftonline.com/' . rawurlencode($cfg['tenant']) . '/oauth2/v2.0/authorize?' . http_build_query($params));
exit;
