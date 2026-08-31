<?php
// ============================================================
//  cron/reminder.php — cronで毎朝9時に実行するリマインダー
//  設定例（さくらサーバー）:
//    0 9 * * * php /path/to/crm/cron/reminder.php >> /path/to/logs/cron.log 2>&1
// ============================================================
// WEB経由のアクセスを拒否
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLIからのみ実行可能です');
}

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/lib/gmail.php';
require_once dirname(__DIR__) . '/lib/google_calendar.php';
require_once dirname(__DIR__) . '/lib/notification.php';

echo "[" . date('Y-m-d H:i:s') . "] CRONジョブ開始\n";

// 1. タスクリマインダー
echo "タスクリマインダー送信中...\n";
Notification::sendTaskReminders();
echo "完了\n";

// 2. TODOリマインダー
echo "TODOリマインダー送信中...\n";
Notification::sendTodoReminders();
echo "完了\n";

echo "[" . date('Y-m-d H:i:s') . "] CRONジョブ終了\n";

// ============================================================
//  cron/gmail_sync.php — Gmail同期（5分ごと）
//  設定例:
//    */5 * * * * php /path/to/crm/cron/gmail_sync.php >> /path/to/logs/sync.log 2>&1
// ============================================================
