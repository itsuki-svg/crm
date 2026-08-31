<?php
// ============================================================
//  cron/gmail_sync.php — Gmail受信メール定期同期
//  cronで5分ごとに実行:
//    */5 * * * * php /path/to/crm/cron/gmail_sync.php
// ============================================================
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLIからのみ実行可能です');
}

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/lib/gmail.php';
require_once dirname(__DIR__) . '/lib/notification.php';

echo "[" . date('Y-m-d H:i:s') . "] Gmail同期開始\n";

// Gmail連携している全社員を取得
$stmt = DB::staff()->query('
    SELECT employee_id FROM gmail_tokens WHERE is_active = 1
');

foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $empId) {
    echo "社員ID:{$empId} 同期中... ";
    $result = Gmail::syncInbox((int)$empId);
    echo $result['ok']
        ? "完了 (新着:{$result['synced']}件)\n"
        : "エラー: {$result['error']}\n";
}

echo "[" . date('Y-m-d H:i:s') . "] Gmail同期終了\n";
