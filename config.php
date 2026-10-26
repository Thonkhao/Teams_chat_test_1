<?php
// ตั้งค่าหน้าแชทส่วนตัว (chat.php)
// ค่า App ใน Azure (MS_TENANT_ID / MS_CLIENT_ID / MS_CLIENT_SECRET) อยู่ใน C:\inetpub\config\ms_teams\.env

return [
    // URL เต็มของ graph_callback.php ในโฟลเดอร์นี้
    // ต้องเพิ่ม URL เดียวกันนี้เป็น Redirect URI (แบบ Web) ของ App ใน Azure ด้วย
    'graph_redirect_uri' => 'https://dev-msteams.hatari.co.th/graph_callback.php',
    // เชื่อมบัญชีกลางสำหรับ worker: เพิ่ม URL นี้เป็น Redirect URI แบบ Web ใน Azure
    'notify_redirect_uri' => 'https://dev-msteams.hatari.co.th/dbconnect/notify_callback.php',
];
