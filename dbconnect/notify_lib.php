<?php

/**

 * notify_lib.php

 * teams_notify($email, $title, $message, $link = null) => ['success'=>bool,'message'=>string]

 * ส่งแชท 1:1 ใน Teams ในนามบัญชีกลาง (delegated) โดยใช้ refresh token ที่เก็บแบบเข้ารหัส

 * ต้องมี $_ENV: MS_TENANT_ID, MS_CLIENT_ID, MS_CLIENT_SECRET (โหลดจาก .env ก่อน require ไฟล์นี้)

 */

const NOTIFY_SCOPE = 'offline_access User.Read User.ReadBasic.All Chat.Create ChatMessage.Send';

function notify_env($k)

{

    $v = $_ENV[$k] ?? getenv($k);

    if ($v === false || $v === null || $v === '') {

        throw new RuntimeException("Missing env: $k");

    }

    return $v;

}

function notify_token_file()

{

    return __DIR__ . '/storage/notify_token.dat';

}

function notify_key()

{

    return hash('sha256', notify_env('MS_CLIENT_SECRET'), true);

}

function notify_token_save(array $data)

{

    $dir = dirname(notify_token_file());

    if (!is_dir($dir)) { @mkdir($dir, 0700, true); }

    $iv = random_bytes(12);

    $tag = '';

    $enc = openssl_encrypt(json_encode($data), 'aes-256-gcm', notify_key(), OPENSSL_RAW_DATA, $iv, $tag);

    if ($enc === false) { throw new RuntimeException('Encrypt failed'); }

    if (file_put_contents(notify_token_file(), base64_encode($iv . $tag . $enc), LOCK_EX) === false) {

        throw new RuntimeException('Cannot write token file (check folder permission)');

    }

}

function notify_token_load()

{

    $f = notify_token_file();

    if (!is_file($f)) { return null; }

    $raw = base64_decode((string) file_get_contents($f), true);

    if ($raw === false || strlen($raw) < 29) { return null; }

    $json = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', notify_key(), OPENSSL_RAW_DATA,

        substr($raw, 0, 12), substr($raw, 12, 16));

    $d = $json === false ? null : json_decode($json, true);

    return is_array($d) ? $d : null;

}

function notify_http($method, $url, array $headers = [], $body = null)

{

    $ch = curl_init($url);

    curl_setopt_array($ch, [

        CURLOPT_CUSTOMREQUEST  => $method,

        CURLOPT_RETURNTRANSFER => true,

        CURLOPT_HTTPHEADER     => $headers,

        CURLOPT_TIMEOUT        => 30,

        CURLOPT_HEADER         => true,

    ]);

    if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }

    $resp = curl_exec($ch);

    if ($resp === false) {

        $e = curl_error($ch);

        curl_close($ch);

        throw new RuntimeException('HTTP error: ' . $e);

    }

    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);

    curl_close($ch);

    $hdr = substr($resp, 0, $hsize);

    $retry = preg_match('/^Retry-After:\s*(\d+)/mi', $hdr, $m) ? (int) $m[1] : 3;

    return ['code' => $code, 'json' => json_decode(substr($resp, $hsize), true), 'retry' => $retry];
}
// อ่าน callback URL สำหรับเชื่อมบัญชีกลางผ่านเว็บจาก config.php
function notify_redirect_uri()
{
    $config = require __DIR__ . '/../config.php';
    $uri = $config['notify_redirect_uri'] ?? '';
    if (!is_string($uri) || !filter_var($uri, FILTER_VALIDATE_URL)
        || parse_url($uri, PHP_URL_SCHEME) !== 'https') {
        throw new RuntimeException('กรุณาตั้ง notify_redirect_uri ใน config.php เป็น URL callback แบบ HTTPS');
    }
    return $uri;
}

function notify_token_endpoint()

{

    return 'https://login.microsoftonline.com/' . rawurlencode(notify_env('MS_TENANT_ID')) . '/oauth2/v2.0/token';

}

/** ขอ access token ใหม่จาก refresh token (และเก็บ refresh token ตัวใหม่) */

function notify_access_token()

{

    $t = notify_token_load();

    if (!$t || empty($t['refresh_token'])) {

        throw new RuntimeException('ยังไม่ได้เชื่อมบัญชีกลาง: กรุณาเปิด /dbconnect/notify_connect.php ผ่านเว็บเพื่อล็อกอิน Microsoft');

    }

    if (!empty($t['access_token']) && ($t['expires_at'] ?? 0) > time() + 120) {

        return $t['access_token'];

    }

    $r = notify_http('POST', notify_token_endpoint(),

        ['Content-Type: application/x-www-form-urlencoded'],

        http_build_query([

            'grant_type'    => 'refresh_token',

            'client_id'     => notify_env('MS_CLIENT_ID'),

            'client_secret' => notify_env('MS_CLIENT_SECRET'),

            'refresh_token' => $t['refresh_token'],

            'scope'         => NOTIFY_SCOPE,

        ]));

    if ($r['code'] !== 200 || empty($r['json']['access_token'])) {

        $err = $r['json']['error_description'] ?? ('HTTP ' . $r['code']);

        throw new RuntimeException('Refresh token ใช้ไม่ได้ ต้องเชื่อมบัญชีกลางใหม่: ' . substr($err, 0, 200));

    }

    $t['access_token']  = $r['json']['access_token'];

    $t['refresh_token'] = $r['json']['refresh_token'] ?? $t['refresh_token'];

    $t['expires_at']    = time() + (int) ($r['json']['expires_in'] ?? 3600);

    notify_token_save($t);

    return $t['access_token'];

}

/** เรียก Graph API (ลองใหม่ 1 ครั้งถ้าโดน 429) */

function notify_graph($method, $path, $token, $body = null)

{

    $h = ['Authorization: Bearer ' . $token, 'Content-Type: application/json'];

    $json = $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE);

    $r = notify_http($method, 'https://graph.microsoft.com/v1.0' . $path, $h, $json);

    if ($r['code'] === 429) {

        sleep(max(1, min(10, $r['retry'])));

        $r = notify_http($method, 'https://graph.microsoft.com/v1.0' . $path, $h, $json);

    }

    return $r;

}

function notify_graph_error($r, $what)

{

    $m = $r['json']['error']['message'] ?? ('HTTP ' . $r['code']);

    return "$what: " . substr($m, 0, 300);

}

function notify_html($s)

{

    return nl2br(htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);

}

function teams_notify($email, $title, $message, $link = null)

{

    try {

        $email = trim((string) $email);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

            return ['success' => false, 'message' => 'อีเมลผู้รับไม่ถูกต้อง'];

        }

        $token = notify_access_token();

        // id ของบัญชีกลาง (เก็บ cache ในไฟล์ token)

        $t = notify_token_load();

        if (empty($t['me_id'])) {

            $me = notify_graph('GET', '/me?$select=id', $token);

            if ($me['code'] !== 200) { return ['success' => false, 'message' => notify_graph_error($me, 'อ่านบัญชีกลาง')]; }

            $t['me_id'] = $me['json']['id'];

            notify_token_save($t);

        }

        // หา id ผู้รับจากอีเมล

        $u = notify_graph('GET', '/users/' . rawurlencode($email) . '?$select=id', $token);

        if ($u['code'] !== 200) { return ['success' => false, 'message' => notify_graph_error($u, 'ไม่พบผู้รับ ' . $email)]; }

        $uid = $u['json']['id'];

        // สร้าง/เปิดแชท 1:1 (ถ้ามีแล้ว Graph คืนแชทเดิม)

        $bind = fn($id) => [

            '@odata.type'     => '#microsoft.graph.aadUserConversationMember',

            'roles'           => ['owner'],

            'user@odata.bind' => "https://graph.microsoft.com/v1.0/users('$id')",

        ];

        $chat = notify_graph('POST', '/chats', $token, [

            'chatType' => 'oneOnOne',

            'members'  => [$bind($t['me_id']), $bind($uid)],

        ]);

        if (!in_array($chat['code'], [200, 201], true) || empty($chat['json']['id'])) {

            return ['success' => false, 'message' => notify_graph_error($chat, 'สร้างแชท')];

        }

        $html = '';

        if ($title !== '' && $title !== null) { $html .= '<b>' . notify_html($title) . '</b><br>'; }

        $html .= notify_html($message);

        if ($link) {

            $l = htmlspecialchars((string) $link, ENT_QUOTES, 'UTF-8');

            $html .= '<br><a href="' . $l . '">เปิดดูในระบบ</a>';

        }

        $html .= '<br><br><i>ข้อความอัตโนมัติจากระบบ กรุณาอย่าตอบกลับ</i>';

        $msg = notify_graph('POST', '/chats/' . rawurlencode($chat['json']['id']) . '/messages', $token,

            ['body' => ['contentType' => 'html', 'content' => $html]]);

        if (!in_array($msg['code'], [200, 201], true)) {

            return ['success' => false, 'message' => notify_graph_error($msg, 'ส่งข้อความ')];

        }

        return ['success' => true, 'message' => 'sent'];

    } catch (Throwable $e) {

        return ['success' => false, 'message' => substr($e->getMessage(), 0, 400)];

    }

}
