<?php
// ฟังก์ชันกลางสำหรับเรียก Microsoft Graph แบบ delegated (สิทธิ์ของผู้ใช้ที่ login)
// ใช้ App เดียวกับการ login ระบบหลัก (MS_TENANT_ID / MS_CLIENT_ID / MS_CLIENT_SECRET)
// ค่าเหล่านี้โหลดเข้า $_ENV โดย dbconnect/Connect.php จาก C:\inetpub\config\ms_teams\.env (นอก wwwroot)
// แต่ขอ scope เพิ่มสำหรับแชท และใช้ redirect URI ของโฟลเดอร์นี้เอง (graph_redirect_uri ใน config.php)

require_once __DIR__ . '/dbconnect/Connect.php';

const TEAMS_GRAPH_BASE   = 'https://graph.microsoft.com/v1.0';
// User.ReadBasic.All ใช้ค้นหาผู้รับจากอีเมล (จำเป็นสำหรับบัญชี Guest ที่ UPN ไม่ตรงกับอีเมล)
const TEAMS_GRAPH_SCOPES = 'openid offline_access User.Read User.ReadBasic.All Chat.Create ChatMessage.Send';

function teams_graph_config()
{
    $configFile = __DIR__ . '/config.php';
    $config     = is_file($configFile) ? require $configFile : [];

    return [
        'tenant'        => $_ENV['MS_TENANT_ID'] ?? '',
        'client_id'     => $_ENV['MS_CLIENT_ID'] ?? '',
        'client_secret' => $_ENV['MS_CLIENT_SECRET'] ?? '',
        'redirect_uri'  => $config['graph_redirect_uri'] ?? '',
    ];
}

function teams_graph_token_url()
{
    return 'https://login.microsoftonline.com/' . rawurlencode(teams_graph_config()['tenant']) . '/oauth2/v2.0/token';
}

// ยิงขอ token (ใช้ทั้งแลก code ครั้งแรก และ refresh) แล้วเก็บลง session
function teams_graph_request_token(array $params)
{
    $token = teams_graph_fetch_token($params, $_SESSION['teams_graph']['refresh_token'] ?? '');
    if (isset($token['error'])) {
        return $token;
    }
    $_SESSION['teams_graph'] = $token;
    return ['access_token' => $token['access_token']];
}

// ยิงขอ token จาก Microsoft คืน ['access_token','refresh_token','expires_at'] หรือ ['error'] — ไม่เก็บที่ไหน
// $oldRefreshToken ใช้ต่อเมื่อ Microsoft ไม่ได้ส่ง refresh token ใบใหม่กลับมา
function teams_graph_fetch_token(array $params, $oldRefreshToken = '')
{
    $cfg = teams_graph_config();
    $params += [
        'client_id'     => $cfg['client_id'],
        'client_secret' => $cfg['client_secret'],
        'scope'         => TEAMS_GRAPH_SCOPES,
    ];

    $ch = curl_init(teams_graph_token_url());
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $token = json_decode((string) $response, true);
    if (empty($token['access_token'])) {
        return ['error' => $token['error_description'] ?? 'ขอ token จาก Microsoft ไม่สำเร็จ'];
    }

    return [
        'access_token'  => $token['access_token'],
        'refresh_token' => $token['refresh_token'] ?? $oldRefreshToken,
        'expires_at'    => time() + (int) ($token['expires_in'] ?? 3600) - 60,
    ];
}

// คืน access token ที่ยังใช้ได้ (refresh ให้อัตโนมัติถ้าหมดอายุ) หรือ null ถ้ายังไม่ได้เชื่อมต่อ
function teams_graph_access_token()
{
    $t = $_SESSION['teams_graph'] ?? null;
    if (!$t) {
        return null;
    }
    if (time() < $t['expires_at']) {
        return $t['access_token'];
    }
    if (empty($t['refresh_token'])) {
        unset($_SESSION['teams_graph']);
        return null;
    }

    $result = teams_graph_request_token(['grant_type' => 'refresh_token', 'refresh_token' => $t['refresh_token']]);
    if (isset($result['error'])) {
        unset($_SESSION['teams_graph']);
        return null;
    }
    return $result['access_token'];
}

// เรียก Graph API คืน [httpCode, ข้อมูล JSON ที่ decode แล้ว]
function teams_graph_call($method, $path, $accessToken, $body = null)
{
    $ch = curl_init(TEAMS_GRAPH_BASE . $path);
    $headers = ['Authorization: Bearer ' . $accessToken, 'Accept: application/json'];
    $opts = [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
    ];
    if ($body !== null) {
        $headers[]                = 'Content-Type: application/json';
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return [0, ['error' => ['message' => 'เชื่อมต่อ Graph ไม่ได้: ' . $curlErr]]];
    }
    return [$httpCode, json_decode((string) $response, true)];
}

function teams_graph_error_message($data)
{
    return $data['error']['message'] ?? 'ไม่ทราบสาเหตุ';
}

// แปลงข้อความธรรมดาเป็น HTML สำหรับ chatMessage — escape ทุกอย่าง กันการแทรก HTML
// ยกเว้น 2 อย่าง:
//   - ขึ้นบรรทัดใหม่: ทั้ง Enter และ <br> / <br/> / <br /> ที่พิมพ์มา จะกลายเป็น <br>
//   - ตัวหนา/ขีดเส้นใต้/ตัวเอียง: <b> <strong> <u> <i> <em> (เปิด/ปิด ไม่มี attribute) ใช้ได้ตามที่พิมพ์
function teams_text_to_html($text)
{
    $text = str_replace(["\r\n", "\r"], "\n", (string) $text);
    $html = htmlspecialchars($text, ENT_QUOTES);
    $html = preg_replace('#&lt;br\s*/?&gt;#i', '<br>', $html);
    $html = preg_replace_callback('#&lt;(/?)(b|strong|u|i|em)&gt;#i', function ($m) {
        return '<' . $m[1] . strtolower($m[2]) . '>';
    }, $html);
    return teams_close_open_tags(str_replace("\n", '<br>', $html));
}

// ปิดแท็กจัดรูปแบบที่ลืมปิด (ไม่งั้นตัวหนาจะลามไปถึงข้อความท้ายสุด) และทิ้งแท็กปิดที่ไม่มีคู่
function teams_close_open_tags($html)
{
    $open = [];
    $html = preg_replace_callback('#<(/?)(b|strong|u|i|em)>#', function ($m) use (&$open) {
        if ($m[1] === '') {
            $open[] = $m[2];
            return $m[0];
        }
        $pos = array_search($m[2], array_reverse($open, true), true);
        if ($pos === false) {
            return '';
        }
        // ปิดแท็กที่ซ้อนอยู่ข้างในก่อน แล้วค่อยปิดตัวนี้
        $closing = '';
        while (count($open) > $pos) {
            $closing .= '</' . array_pop($open) . '>';
        }
        return $closing;
    }, $html);
    while ($open) {
        $html .= '</' . array_pop($open) . '>';
    }
    return $html;
}

// ส่งแชท 1:1 ถึงผู้รับตามอีเมล ในนามเจ้าของ $accessToken ($myId = id ของเจ้าของ token)
// $body = ['contentType' => 'text'|'html', 'content' => '...']
// คืน ['ok' => bool, 'code' => HTTP, 'message' => string, 'throttled' => bool, 'no_permission' => bool]
function teams_graph_send_direct($accessToken, $myId, $recipient, array $body)
{
    $fail = function ($code, $message, $extra = []) {
        return ['ok' => false, 'code' => $code, 'message' => $message, 'throttled' => $code === 429, 'no_permission' => false] + $extra;
    };

    // 1) หา id ของผู้รับจากอีเมล — ค้นจาก mail ก่อน (Guest มี UPN แบบ xxx#EXT#@tenant.onmicrosoft.com
    //    ไม่ตรงกับอีเมลจริง) ถ้าไม่เจอค่อยค้นจาก userPrincipalName (บัญชีในองค์กรปกติ)
    $odataEmail  = str_replace("'", "''", $recipient);
    $recipientId = null;
    foreach (['mail', 'userPrincipalName'] as $field) {
        [$code, $found] = teams_graph_call('GET', '/users?$select=id,displayName&$filter=' . rawurlencode($field . " eq '" . $odataEmail . "'"), $accessToken);
        if ($code === 403) {
            return $fail(403, 'ไม่มีสิทธิ์ค้นหาผู้ใช้ (User.ReadBasic.All) — ให้ admin เพิ่มสิทธิ์ใน Azure แล้วเชื่อมต่อบัญชี Microsoft ใหม่', ['no_permission' => true]);
        }
        if ($code === 429) {
            return $fail(429, 'Microsoft จำกัดการส่งชั่วคราว');
        }
        if ($code === 200 && !empty($found['value'][0]['id'])) {
            $recipientId = $found['value'][0]['id'];
            break;
        }
    }
    if (!$recipientId) {
        return $fail(404, 'ไม่พบ ' . $recipient . ' ในองค์กร — ส่งได้เฉพาะบัญชีในองค์กรหรือ Guest ที่ถูกเชิญเข้ามาแล้ว');
    }
    if ($recipientId === $myId) {
        return $fail(400, 'ส่งแชทหาตัวเองไม่ได้');
    }

    // 2) สร้าง (หรือได้แชทเดิม — Graph คืนแชท 1:1 เดิมถ้ามีอยู่แล้ว)
    $member = function ($userId) {
        return [
            '@odata.type'     => '#microsoft.graph.aadUserConversationMember',
            'roles'           => ['owner'],
            'user@odata.bind' => TEAMS_GRAPH_BASE . "/users('" . $userId . "')",
        ];
    };
    [$code, $chat] = teams_graph_call('POST', '/chats', $accessToken, [
        'chatType' => 'oneOnOne',
        'members'  => [$member($myId), $member($recipientId)],
    ]);
    if ($code < 200 || $code >= 300 || empty($chat['id'])) {
        return $fail($code, 'สร้างแชทไม่สำเร็จ (HTTP ' . $code . '): ' . teams_graph_error_message($chat));
    }

    // 3) ส่งข้อความ
    [$code, $sent] = teams_graph_call('POST', '/chats/' . rawurlencode($chat['id']) . '/messages', $accessToken, ['body' => $body]);
    if ($code < 200 || $code >= 300) {
        return $fail($code, 'ส่งข้อความไม่สำเร็จ (HTTP ' . $code . '): ' . teams_graph_error_message($sent));
    }

    return ['ok' => true, 'code' => $code, 'message' => 'ส่งแชทถึง ' . $recipient . ' แล้ว', 'throttled' => false, 'no_permission' => false];
}
