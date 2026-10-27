<?php
// หน้าเชื่อมบัญชีกลางอยู่ที่โฟลเดอร์หลักของโปรเจกต์
// เปิดผ่านเบราว์เซอร์ หลัง login ระบบหลักแล้ว
require_once __DIR__ . '/dbconnect/Connect.php';   // โหลด .env ให้ด้วย
require_once __DIR__ . '/notify_lib.php';
session_start();
// เฉพาะ UserID ที่กำหนดใน .env: NOTIFY_ADMIN_UIDS=12,34
$allowed = array_filter(array_map('trim', explode(',', $_ENV['NOTIFY_ADMIN_UIDS'] ?? '')));
if (empty($_SESSION['uid']) || !in_array((string) $_SESSION['uid'], $allowed, true)) {
   http_response_code(403);
   exit('ไม่มีสิทธิ์เข้าหน้านี้ (ต้อง login และอยู่ใน NOTIFY_ADMIN_UIDS)');
}
$state = 'notify_' . bin2hex(random_bytes(16));
$_SESSION['notify_state'] = $state;
$tenant = rawurlencode($_ENV['MS_TENANT_ID']);
$url = "https://login.microsoftonline.com/$tenant/oauth2/v2.0/authorize?" . http_build_query([
   'client_id'     => $_ENV['MS_CLIENT_ID'],
   'response_type' => 'code',
   'redirect_uri'  => $_ENV['MS_REDIRECT_URI'],   // ใช้ตัวเดิม ไม่ต้องเพิ่มใน Azure
   'response_mode' => 'query',
   'scope'         => NOTIFY_SCOPE,
   'state'         => $state,
   'prompt'        => 'select_account',            // ให้เลือก/ใส่บัญชีกลาง ไม่ใช้บัญชีที่ login ค้างอยู่
]);
header('Location: ' . $url);
exit;
