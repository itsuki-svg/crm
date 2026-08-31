<?php
// ============================================================
//  api/settings.php — システム設定保存 API
// ============================================================
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/auth/auth.php';
require_once dirname(__DIR__) . '/lib/helpers.php';

Auth::check('admin');
$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save') {
    verifyCsrf();
    $db      = DB::staff();
    $allowed = [
        'company_name','system_name','task_reminder_enabled','task_done_notify_enabled',
        'unknown_mail_notify_enabled','unknown_mail_notify_admins','duplicate_suppress_enabled',
        'login_max_failures','login_lock_minutes','session_lifetime_days','records_per_page',
        'show_alert_banner',
    ];
    $saved = 0;
    foreach ($allowed as $key) {
        if (!isset($_POST[$key])) continue;
        $db->prepare('
            UPDATE system_settings SET value = ?, updated_by = ? WHERE setting_key = ?
        ')->execute([$_POST[$key], Auth::user()['id'], $key]);
        $saved++;
    }
    jsonResponse(['ok' => true, 'message' => "設定を保存しました（{$saved}項目）"]);
} else {
    jsonResponse(['ok' => false, 'error' => 'Not Found'], 404);
}
