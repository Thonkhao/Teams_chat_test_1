<?php
// หน้าทดลองส่งแชทส่วนตัว (1:1) ใน Teams ถึงคนตามอีเมล ผ่าน Microsoft Graph
session_start();
require_once __DIR__ . '/graph_common.php';

// login ด้วยบัญชี Microsoft เลย (ไม่มีระบบหลัก) — ยังไม่ login หรือ token หมดอายุ → ไปหน้า login ของ Microsoft
if (!teams_graph_access_token() || empty($_SESSION['teams_graph_me'])) {
    header('Location: graph_connect.php');
    exit;
}
$me = $_SESSION['teams_graph_me'];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
    <title>ทดลองส่งแชท Teams</title>
    <style>
        body { font-family: Tahoma, sans-serif; background: #f4f5f7; margin: 0; padding: 32px 16px; }
        .box { max-width: 560px; margin: 0 auto; background: #fff; border-radius: 8px; padding: 24px; box-shadow: 0 1px 4px rgba(0,0,0,.1); }
        h1 { font-size: 20px; margin: 0 0 16px; }
        .who { font-size: 13px; color: #555; background: #f0f0fa; padding: 8px 12px; border-radius: 4px; }
        label { display: block; font-size: 13px; color: #555; margin: 12px 0 4px; }
        input, textarea { width: 100%; box-sizing: border-box; padding: 8px 10px; border: 1px solid #ccc; border-radius: 4px; font: inherit; }
        textarea { min-height: 120px; resize: vertical; }
        button, .btn { display: inline-block; margin-top: 16px; padding: 9px 20px; background: #5b5fc7; color: #fff; border: 0; border-radius: 4px; font: inherit; cursor: pointer; text-decoration: none; }
        button:disabled { opacity: .6; cursor: default; }
        .fmt { display: flex; align-items: center; gap: 4px; margin-bottom: 4px; }
        .fmt button { margin: 0; padding: 2px 10px; background: #eee; color: #333; border: 1px solid #ccc; }
        .fmt span { font-size: 12px; color: #888; margin-left: 6px; }
        #result { margin-top: 16px; padding: 10px 12px; border-radius: 4px; display: none; }
        #result.ok { display: block; background: #e6f4ea; color: #1e7b34; }
        #result.err { display: block; background: #fdecea; color: #b3261e; }
    </style>
</head>
<body>
<div class="box">
    <h1>ทดลองส่งแชทส่วนตัวใน Microsoft Teams</h1>

        <div class="who">ส่งในนาม: <?= htmlspecialchars($me['name'] . ' (' . $me['mail'] . ')', ENT_QUOTES) ?> — <a href="logout.php">ออกจากระบบ</a></div>
        <form id="chat_form">
            <label for="ct_recipient">อีเมลผู้รับ</label>
            <input type="email" id="ct_recipient" placeholder="name@hatari.co.th" required>

            <label for="ct_message">ข้อความ</label>
            <div class="fmt">
                <button type="button" data-tag="b" title="ตัวหนา"><b>B</b></button>
                <button type="button" data-tag="u" title="ขีดเส้นใต้"><u>U</u></button>
                <button type="button" data-tag="i" title="ตัวเอียง"><i>I</i></button>
                <span>เลือกข้อความแล้วกดปุ่ม หรือพิมพ์ &lt;b&gt;...&lt;/b&gt; เอง</span>
            </div>
            <textarea id="ct_message" maxlength="4000" required></textarea>

            <button type="submit" id="ct_send">ส่งแชท</button>
        </form>
        <div id="result"></div>
</div>
<script src="chat.js"></script>
</body>
</html>
