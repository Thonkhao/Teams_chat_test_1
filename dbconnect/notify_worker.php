<?php
/**
 * notify_worker.php
 * อ่านคิวจากตาราง WNV_MSTeamsAlert แล้วส่งข้อความ Teams ทีละแถว (ผ่าน teams_notify() ใน notify_lib.php)
 *
 * วิธีใช้ (command line เท่านั้น):
 *   php notify_worker.php --preview [--id=123]   ดูรายการที่รอส่ง ไม่ส่งจริง
 *   php notify_worker.php --id=123               ส่งเฉพาะแถวนั้น
 *   php notify_worker.php                        ส่งคิวทั้งหมด (สูงสุด 20 แถว) ใช้กับ Task Scheduler
 */
// ห้ามเปิดผ่านเว็บ
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
ini_set('display_errors', '0');
ini_set('log_errors', '1');
date_default_timezone_set('Asia/Bangkok');
// --send / --queue รับไว้เฉยๆ เพื่อให้คำสั่งเก่ายังอ่าน --id / --preview ได้ถูกต้อง
$options = getopt('', ['help', 'id:', 'preview', 'send', 'queue']);
if (isset($options['help'])) {
    echo "Preview only: php notify_worker.php --preview [--id=123]\n";
    echo "Send one: php notify_worker.php --id=123\n";
    echo "Scheduled queue (up to 20): php notify_worker.php\n";
    exit(0);
}
// ตรวจ --id ต้องเป็นเลขจำนวนเต็มบวก
$id = $options['id'] ?? null;
$send = !isset($options['preview']);   // ไม่ใส่ --preview = ส่งจริง
if ($id !== null && (!is_string($id) || filter_var($id, FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 2147483647]]) === false)) {
    fwrite(STDERR, "Use a positive integer --id=123.\n");
    exit(1);
}
$conn = null;
$currentId = null;
$exitCode = 0;
try {
    // ใช้ Connect.php เดิม (โหลด .env และเชื่อม DB ให้)
    require_once __DIR__ . '/Connect.php';
    $conn = DBConnection::getConnection();
    if ($send) {
        require_once __DIR__ . '/notify_lib.php';   // มี teams_notify()
        // ขอ lock ของ SQL Server กันรันซ้อน ถ้ามีตัวอื่นรันอยู่จะหยุดทันที
        // (lock หายเองเมื่อปิด connection ท้ายไฟล์)
        $stmt = $conn->prepare("SET NOCOUNT ON;
            DECLARE @result int;
            EXEC @result = sys.sp_getapplock
                @Resource = 'notify_worker:WNV_LN:WNV_MSTeamsAlert',
                @LockMode = 'Exclusive', @LockOwner = 'Session', @LockTimeout = 0;
            SELECT @result;");
        $stmt->execute();
        $lockResult = $stmt->fetchColumn();
        $stmt->closeCursor();
        $conn->exec('SET NOCOUNT OFF');
        if ($lockResult === false || (int) $lockResult < 0) {
            throw new RuntimeException('Another worker is running or lock acquisition failed.');
        }
    }
    // เงื่อนไขแถวที่ "ต้องส่ง" ใช้ร่วมกันทั้งตอนดูรายการและตอนจองแถว
    // - Stat = N ยังไม่ส่ง, ชนิด LNLOAD, ยังไม่มีวันที่ส่ง, ถึงเวลาส่งแล้ว
    // - SendRemark ที่ขึ้นต้น TEAMS: คือถูกจอง/ส่งแล้ว/ล้มเหลว จะไม่ถูกหยิบซ้ำ
    $table = '[WNV_LN].[dbo].[WNV_MSTeamsAlert]';
    $filter = "Stat = :stat AND DocType = :doctype AND SendDate IS NULL
        AND StartDate <= GETDATE()
        AND (SendRemark IS NULL OR SendRemark NOT LIKE 'TEAMS:%')";
    $params = ['stat' => 'N', 'doctype' => 'LNLOAD'];
    if ($id !== null) {
        $filter .= ' AND Trans_Num = :selected_id';
        $params['selected_id'] = (int) $id;
    }
    // ดึงรายการที่ต้องส่ง (สูงสุด 20 แถว)
    $query = $conn->prepare("SELECT TOP (20) Trans_Num, MailTo, Subject, MailMsg
        FROM $table WHERE $filter ORDER BY Trans_Num");
    $query->execute($params);
    $rows = $query->fetchAll(PDO::FETCH_ASSOC);
    $query->closeCursor();
    if (!$send) {
        // โหมด preview: แสดงเป็น JSON แล้วจบ ไม่แตะข้อมูล
        echo json_encode(['mode' => 'preview', 'rows' => $rows],
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
    } elseif (!$rows) {
        echo "No matching pending rows.\n";
    } else {
        // จองแถว + อ่านข้อมูลในคำสั่งเดียว (atomic): ถ้ามีตัวอื่นแย่งจองไปก่อน จะไม่ได้แถวกลับมา
        // แทนการ SELECT ล็อก -> UPDATE ในทรานแซกชันแบบเดิม
        $claim = $conn->prepare("UPDATE $table SET SendRemark = 'TEAMS:PROCESSING'
            OUTPUT inserted.Trans_Num, inserted.MailTo, inserted.Subject, inserted.MailMsg
            WHERE Trans_Num = :claim_id AND $filter");
        // บันทึกผลการส่ง: Stat และ SendStat ได้ค่าเดียวกันเสมอ
        // สำเร็จ = Y (พร้อมวันที่ส่ง), ล้มเหลว = N
        $update = $conn->prepare("UPDATE $table
            SET Stat = :stat,
                SendStat = :sendstat,
                SendDate = CASE WHEN :sent = 1 THEN GETDATE() ELSE NULL END,
                SendRemark = :remark
            WHERE Trans_Num = :id AND SendRemark = 'TEAMS:PROCESSING'");
        foreach ($rows as $row) {
            $currentId = $row['Trans_Num'];
            // 1) จองแถวก่อนส่ง (ถ้าไม่ได้แถว = ตัวอื่นจองไปแล้ว ข้าม)
            $claim->execute($params + ['claim_id' => $currentId]);
            $q = $claim->fetch(PDO::FETCH_ASSOC);
            $claim->closeCursor();
            if (!$q) { continue; }
            // 2) ส่งข้อความ (อีเมลผู้รับถูกตรวจใน teams_notify() แล้ว)
            $message = trim((string) $q['MailMsg']);
            $r = $message === ''
                ? ['success' => false, 'message' => 'MailMsg is empty.']
                : teams_notify(trim((string) $q['MailTo']), (string) $q['Subject'], $message);
            $ok = $r['success'];
            // 3) บันทึกผล
            $update->execute([
                'stat'   => $ok ? 'Y' : 'N',
                'sendstat' => $ok ? 'Y' : 'N',
                'sent'   => $ok ? 1 : 0,
                'remark' => $ok ? 'TEAMS:SENT' : 'TEAMS:FAILED',
                'id'     => $currentId,
            ]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Could not save result. Check Teams before retrying.');
            }
            echo json_encode(['id' => $currentId, 'success' => $ok, 'message' => $r['message'] ?? ''],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
            $currentId = null;
            // ล้มเหลวแถวไหนหยุดทั้งรอบ (แถวนั้นเป็น TEAMS:FAILED จะไม่ถูกลองใหม่เอง)
            if (!$ok) { $exitCode = 1; break; }
            sleep(1);   // เว้นจังหวะ กันโดน Microsoft จำกัดความเร็ว
        }
    }
} catch (Throwable $e) {
    error_log('notify_worker id=' . ($currentId ?? '-') . ': ' . $e->getMessage());
    fwrite(STDERR, "Worker failed; check PHP error log and processing rows before retrying.\n");
    $exitCode = 1;
} finally {
    $query = $claim = $update = $stmt = null;
    $conn = null;   // ปิด connection = ปล่อย lock
}
exit($exitCode);
