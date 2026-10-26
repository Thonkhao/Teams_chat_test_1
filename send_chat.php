<?php
// ส่งแชทส่วนตัว (1:1) ใน Teams ถึงผู้รับตามอีเมล ในนามผู้ใช้ที่ login อยู่ (หน้า chat.php)
// ขั้นตอนหาผู้รับ/สร้างแชท/ส่งข้อความอยู่ใน teams_graph_send_direct() (graph_common.php)

ini_set('display_errors', 0);
ini_set('log_errors', 1);

session_start();
require_once __DIR__ . '/graph_common.php';
header('Content-Type: application/json; charset=utf-8');

function chat_respond($httpCode, $success, $message, $extra = [])
{
    http_response_code($httpCode);
    echo json_encode(['success' => $success, 'message' => $message] + $extra);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $input['csrf_token'])) {
    chat_respond(403, false, 'CSRF token ไม่ถูกต้อง');
}

$recipient = trim((string) ($input['recipient'] ?? ''));
$message   = trim((string) ($input['message'] ?? ''));

if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
    chat_respond(400, false, 'อีเมลผู้รับไม่ถูกต้อง');
}
if ($message === '' || mb_strlen($message) > 4000) {
    chat_respond(400, false, 'กรุณากรอกข้อความ (ไม่เกิน 4000 ตัวอักษร)');
}

// login ด้วยบัญชี Microsoft = มี token ใน session (chat.js จะโหลดหน้าใหม่ → chat.php พาไป login อีกครั้ง)
$token = teams_graph_access_token();
if (!$token || empty($_SESSION['teams_graph_me']['id'])) {
    chat_respond(401, false, 'Session หมดอายุ กำลังพาไป Login ด้วย Microsoft ใหม่', ['reconnect' => true]);
}

// ส่งเป็น HTML เพื่อให้ขึ้นบรรทัดใหม่ได้ (Enter หรือ <br>) — teams_text_to_html() escape ส่วนอื่นทั้งหมดแล้ว
$result = teams_graph_send_direct($token, $_SESSION['teams_graph_me']['id'], $recipient, ['contentType' => 'html', 'content' => teams_text_to_html($message)]);

if ($result['ok']) {
    chat_respond(200, true, $result['message']);
}
if ($result['no_permission']) {
    // token เดิมยังไม่มี scope ที่ต้องใช้ — ล้างทิ้งเพื่อให้หน้า chat.php แสดงปุ่มเชื่อมต่อใหม่ (ขอ scope ครบ)
    unset($_SESSION['teams_graph'], $_SESSION['teams_graph_me']);
    chat_respond(403, false, $result['message'], ['reconnect' => true]);
}
if ($result['throttled']) {
    chat_respond(429, false, $result['message'], ['throttled' => true]);
}
chat_respond($result['code'] === 404 || $result['code'] === 400 ? $result['code'] : 502, false, $result['message']);
