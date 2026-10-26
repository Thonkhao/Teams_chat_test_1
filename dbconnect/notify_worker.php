<?php
// CLI only: invoke via PHP CLI / Windows Task Scheduler.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

ini_set('display_errors', '0');
ini_set('log_errors', '1');
date_default_timezone_set('Asia/Bangkok');

// รับ --send/--queue เดิมไว้ด้วย เพื่อให้คำสั่งเก่ายังอ่าน --id/--preview ได้ถูกต้อง
$options = getopt('', ['help', 'id:', 'preview', 'send', 'queue']);
if (isset($options['help'])) {
    echo "Preview only: php notify_worker.php --preview [--id=123]\n";
    echo "Send one: php notify_worker.php --id=123\n";
    echo "Scheduled queue (up to 20): php notify_worker.php\n";
    exit(0);
}
$id = $options['id'] ?? null;
$send = !isset($options['preview']);
if ($id !== null && (!is_string($id) || filter_var($id, FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 2147483647]]) === false)) {
    fwrite(STDERR, "Use a positive integer --id=123.\n");
    exit(1);
}

$conn = null;
$currentId = null;
$exitCode = 0;
try {
    // Worker อยู่ใน dbconnect ใช้ Connect.php เดิม ไม่สร้าง PDO หรือโหลด .env ซ้ำ
    require_once __DIR__ . '/Connect.php';
    $conn = DBConnection::getConnection();
    if ($send) {
        require_once __DIR__ . '/notify_lib.php';
        // All instances of this worker share one SQL Server session lock.
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

    $table = '[WNV_LN].[dbo].[WNV_MSTeamsAlert]';
    $columns = 'Trans_Num, MailTo, Subject, MailMsg';
    $filter = "Stat = :stat AND DocType = :doctype AND SendDate IS NULL
        AND StartDate <= GETDATE()
        AND (SendRemark IS NULL OR SendRemark NOT LIKE 'TEAMS:%')";
    $params = ['stat' => 'N', 'doctype' => 'LNLOAD'];
    if ($id !== null) {
        $filter .= ' AND Trans_Num = :selected_id';
        $params['selected_id'] = (int) $id;
    }
    $query = $conn->prepare("SELECT TOP (20) $columns FROM $table WHERE $filter ORDER BY Trans_Num");
    $query->execute($params);
    $rows = $query->fetchAll(PDO::FETCH_ASSOC);
    $query->closeCursor();

    if (!$send) {
        echo json_encode(['mode' => 'preview', 'rows' => $rows],
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
    } else {
        // เตรียม SQL ครั้งเดียว แล้วใช้ซ้ำกับทุกรายการ
        $read = $conn->prepare("SELECT $columns FROM $table WITH (UPDLOCK, ROWLOCK)
            WHERE Trans_Num = :claim_id AND $filter");
        $claim = $conn->prepare("UPDATE $table SET SendRemark = 'TEAMS:PROCESSING'
            WHERE Trans_Num = :claim_id AND $filter");
        // SendStat ยังไม่แก้จนกว่าจะยืนยันค่าที่ใช้จริง
        $update = $conn->prepare("UPDATE $table
            SET Stat = :stat,
                SendDate = CASE WHEN :sent = 1 THEN GETDATE() ELSE NULL END,
                SendRemark = :remark
            WHERE Trans_Num = :id AND SendRemark = 'TEAMS:PROCESSING'");
        foreach ($rows as $q) {
            $currentId = $q['Trans_Num'];
            // Claim before sending. Completed, failed and interrupted claims are excluded next run.
            $conn->beginTransaction();
            $read->execute($params + ['claim_id' => $currentId]);
            $freshRows = $read->fetchAll(PDO::FETCH_ASSOC);
            $read->closeCursor();
            if (!$freshRows) { $conn->rollBack(); continue; }
            if (count($freshRows) !== 1) { throw new RuntimeException('Trans_Num must identify one row.'); }
            $q = $freshRows[0];
            $claim->execute($params + ['claim_id' => $currentId]);
            $affected = $claim->rowCount();
            if ($affected === 0) { $conn->rollBack(); continue; }
            if ($affected !== 1) { throw new RuntimeException('Claim must identify exactly one row.'); }
            $conn->commit();

            $message = trim((string) $q['MailMsg']);
            // notify_lib.php ตรวจอีเมลและคืน success/message อยู่แล้ว
            $r = $message === ''
                ? ['success' => false, 'message' => 'MailMsg is empty.']
                : teams_notify(trim((string) $q['MailTo']), (string) $q['Subject'], $message);
            $ok = $r['success'];
            $update->execute(['stat' => $ok ? 'y' : 'N', 'sent' => $ok ? 1 : 0,
                'remark' => $ok ? 'TEAMS:SENT' : 'TEAMS:FAILED', 'id' => $currentId]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Could not save result. Check Teams before retrying.');
            }
            echo json_encode(['id' => $currentId, 'success' => $ok, 'message' => $r['message'] ?? ''],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
            $currentId = null;
            if (!$ok) { $exitCode = 1; break; }
            sleep(1);
        }
        if (!$rows) { echo "No matching pending rows.\n"; }
    }
} catch (Throwable $e) {
    if ($conn instanceof PDO && $conn->inTransaction()) { $conn->rollBack(); }
    error_log('notify_worker id=' . ($currentId ?? '-') . ': ' . $e->getMessage());
    fwrite(STDERR, "Worker failed; check PHP error log and processing rows before retrying.\n");
    $exitCode = 1;
} finally {
    unset($stmt, $query, $read, $claim, $update);
    $conn = null; // Releases the SQL Server session application lock.
}
exit($exitCode);
