<?php
// ============================================================
//  lib/notification.php — 通知ライブラリ
//  ※ cron で cron/reminder.php を毎朝9時に実行
// ============================================================
require_once dirname(__DIR__) . '/config/config.php';

class Notification
{
    // --------------------------------------------------------
    //  タスク期限リマインダーを送信（cron経由で毎日実行）
    // --------------------------------------------------------
    public static function sendTaskReminders(): void
    {
        $tomorrow = date('Y-m-d', strtotime('+1 day'));

        // 明日が期限のタスク（done以外）
        $stmt = DB::crm()->prepare("
            SELECT t.id, t.title, t.due_date, t.assigned_to,
                   c.company_name AS customer_name
            FROM tasks t
            LEFT JOIN customers c ON c.id = t.customer_id
            WHERE t.due_date = ? AND t.status != 'done' AND t.is_deleted = 0
        ");
        $stmt->execute([$tomorrow]);

        $setting = DB::staff()->query("
            SELECT value FROM system_settings WHERE setting_key = 'task_reminder_enabled'
        ")->fetchColumn();

        if (!$setting) return;

        foreach ($stmt->fetchAll() as $task) {
            self::sendReminder(
                (int)$task['assigned_to'],
                "[リマインダー] タスク期限が明日です: {$task['title']}",
                self::buildTaskReminderBody($task)
            );
        }
    }

    // --------------------------------------------------------
    //  TODOリマインダー
    // --------------------------------------------------------
    public static function sendTodoReminders(): void
    {
        $tomorrow = date('Y-m-d', strtotime('+1 day'));

        $stmt = DB::crm()->prepare("
            SELECT id, title, due_date, assigned_to, created_by
            FROM todos
            WHERE due_date = ? AND status = 'open' AND is_deleted = 0
        ");
        $stmt->execute([$tomorrow]);

        foreach ($stmt->fetchAll() as $todo) {
            $targetId = $todo['assigned_to'] ?? $todo['created_by'];
            self::sendReminder(
                (int)$targetId,
                "[リマインダー] TODOの期限が明日です: {$todo['title']}",
                self::buildTodoReminderBody($todo)
            );
        }
    }

    // --------------------------------------------------------
    //  タスク完了時に上長へ通知
    // --------------------------------------------------------
    public static function notifyTaskDone(string $title, int $assignedTo, int $doneBy): void
    {
        $setting = DB::staff()->query("
            SELECT value FROM system_settings WHERE setting_key = 'task_done_notify_enabled'
        ")->fetchColumn();

        if (!$setting) return;

        // 上長（マネージャー・管理者）を取得
        $managers = DB::staff()->query("
            SELECT id FROM employees
            WHERE role IN ('admin','manager') AND is_active = 1
        ")->fetchAll(PDO::FETCH_COLUMN);

        // 担当者名取得
        $doneByName = self::getEmployeeName($doneBy);
        $assignedName = self::getEmployeeName($assignedTo);

        $subject = "[社内CRM] タスク完了: {$title}";
        $body    = implode("\n", [
            "タスクが完了しました。",
            "",
            "タスク名: {$title}",
            "担当者:   {$assignedName}",
            "完了者:   {$doneByName}",
            "完了日時: " . date('Y/m/d H:i'),
            "",
            APP_URL . "/pages/tasks.php",
        ]);

        foreach ($managers as $managerId) {
            if ($managerId == $assignedTo) continue; // 本人には送らない
            self::sendReminder((int)$managerId, $subject, $body);
        }
    }

    // --------------------------------------------------------
    //  Gmailでリマインダー送信
    // --------------------------------------------------------
    private static function sendReminder(int $employeeId, string $subject, string $body): void
    {
        $token = Gmail::getToken($employeeId);
        if (!$token) return;

        $rawMessage = implode("\r\n", [
            'To: me',
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            base64_encode($body),
        ]);

        $encoded = rtrim(strtr(base64_encode($rawMessage), '+/', '-_'), '=');

        $ch = curl_init('https://www.googleapis.com/gmail/v1/users/me/messages/send');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['raw' => $encoded]),
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer {$token}",
                'Content-Type: application/json',
            ],
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    // --------------------------------------------------------
    //  メール本文ビルダー
    // --------------------------------------------------------
    private static function buildTaskReminderBody(array $task): string
    {
        $customer = $task['customer_name'] ? "顧客: {$task['customer_name']}\n" : '';
        return implode("\n", [
            "以下のタスクの期限が明日に迫っています。",
            "",
            "タスク名: {$task['title']}",
            $customer,
            "期限: {$task['due_date']}",
            "",
            "詳細はこちら:",
            APP_URL . "/pages/task_detail.php?id={$task['id']}",
        ]);
    }

    private static function buildTodoReminderBody(array $todo): string
    {
        return implode("\n", [
            "以下のTODOの期限が明日に迫っています。",
            "",
            "TODO: {$todo['title']}",
            "期限: {$todo['due_date']}",
            "",
            "詳細はこちら:",
            APP_URL . "/pages/todos.php",
        ]);
    }

    private static function getEmployeeName(int $id): string
    {
        $stmt = DB::staff()->prepare('SELECT CONCAT(last_name," ",first_name) FROM employees WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetchColumn() ?: "社員ID:{$id}";
    }
}
