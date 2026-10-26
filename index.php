<?php
// เปิดโฟลเดอร์ตรงๆ (/) ให้ไปหน้าแชทส่วนตัว
// ถ้ามี code/error จาก Microsoft ติดมาด้วย (Redirect URI ใน Azure ชี้มาที่โฟลเดอร์) ส่งต่อให้ graph_callback.php
$query  = $_SERVER['QUERY_STRING'] ?? '';
$target = isset($_GET['code']) || isset($_GET['error']) ? 'graph_callback.php' : 'chat.php';
header('Location: ' . $target . ($query !== '' ? '?' . $query : ''));
exit;
?>