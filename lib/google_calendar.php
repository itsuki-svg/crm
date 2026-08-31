<?php
// ============================================================
//  lib/google_calendar.php — Googleカレンダー API 連携
// ============================================================
require_once dirname(__DIR__) . '/config/config.php';

class GCalendar
{
    private const API_BASE = 'https://www.googleapis.com/calendar/v3';

    // --------------------------------------------------------
    //  タスク期限をカレンダーに登録
    // --------------------------------------------------------
    public static function createTaskEvent(
        int    $taskId,
        string $title,
        string $dueDate,
        int    $assignedTo
    ): ?string {
        $token = Gmail::getToken($assignedTo);
        if (!$token) return null;

        $event = [
            'summary'     => "[タスク] {$title}",
            'description' => APP_URL . "/pages/task_detail.php?id={$taskId}",
            'start'       => ['date' => $dueDate],
            'end'         => ['date' => $dueDate],
            'colorId'     => '9',   // ブルー
            'reminders'   => [
                'useDefault' => false,
                'overrides'  => [
                    ['method' => 'email', 'minutes' => 24 * 60],  // 前日メール
                    ['method' => 'popup', 'minutes' => 60],        // 1時間前ポップアップ
                ],
            ],
        ];

        $result = self::apiPost(
            '/calendars/primary/events',
            $token,
            $event
        );

        return $result['id'] ?? null;
    }

    // --------------------------------------------------------
    //  TODOカレンダー登録
    // --------------------------------------------------------
    public static function createTodoEvent(
        int    $todoId,
        string $title,
        string $dueDate,
        int    $createdBy
    ): ?string {
        $token = Gmail::getToken($createdBy);
        if (!$token) return null;

        $event = [
            'summary'     => "[TODO] {$title}",
            'description' => APP_URL . "/pages/todo_detail.php?id={$todoId}",
            'start'       => ['date' => $dueDate],
            'end'         => ['date' => $dueDate],
            'colorId'     => '3',  // パープル
        ];

        $result = self::apiPost('/calendars/primary/events', $token, $event);
        return $result['id'] ?? null;
    }

    // --------------------------------------------------------
    //  商談・訪問予定を登録
    // --------------------------------------------------------
    public static function createMeetingEvent(
        int    $eventId,
        string $title,
        string $startDatetime,
        string $endDatetime,
        int    $createdBy,
        array  $attendeeIds = [],
        string $description = ''
    ): ?string {
        $token = Gmail::getToken($createdBy);
        if (!$token) return null;

        // 参加者のGmailアドレスを取得
        $attendees = [];
        if ($attendeeIds) {
            $placeholders = implode(',', array_fill(0, count($attendeeIds), '?'));
            $stmt = DB::staff()->prepare("
                SELECT g.gmail_address
                FROM gmail_tokens g
                WHERE g.employee_id IN ({$placeholders}) AND g.is_active = 1
            ");
            $stmt->execute($attendeeIds);
            foreach ($stmt->fetchAll() as $row) {
                $attendees[] = ['email' => $row['gmail_address']];
            }
        }

        $event = [
            'summary'     => $title,
            'description' => $description,
            'start'       => [
                'dateTime' => (new DateTime($startDatetime))->format('c'),
                'timeZone' => 'Asia/Tokyo',
            ],
            'end' => [
                'dateTime' => (new DateTime($endDatetime))->format('c'),
                'timeZone' => 'Asia/Tokyo',
            ],
            'attendees'   => $attendees,
            'colorId'     => '1',  // ラベンダー
            'reminders'   => [
                'useDefault' => false,
                'overrides'  => [
                    ['method' => 'email', 'minutes' => 24 * 60],
                    ['method' => 'popup', 'minutes' => 30],
                ],
            ],
        ];

        $result = self::apiPost('/calendars/primary/events', $token, $event);
        return $result['id'] ?? null;
    }

    // --------------------------------------------------------
    //  イベント更新
    // --------------------------------------------------------
    public static function updateEvent(
        string $googleEventId,
        int    $employeeId,
        array  $fields
    ): bool {
        $token = Gmail::getToken($employeeId);
        if (!$token) return false;

        $result = self::apiPatch(
            "/calendars/primary/events/{$googleEventId}",
            $token,
            $fields
        );

        return isset($result['id']);
    }

    // --------------------------------------------------------
    //  イベント削除
    // --------------------------------------------------------
    public static function deleteEvent(string $googleEventId, int $employeeId): bool
    {
        $token = Gmail::getToken($employeeId);
        if (!$token) return false;

        $ch = curl_init(self::API_BASE . "/calendars/primary/events/{$googleEventId}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$token}"],
        ]);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_exec($ch);
        curl_close($ch);

        return $code === 204;
    }

    // --------------------------------------------------------
    //  月間カレンダー取得（CRM表示用）
    // --------------------------------------------------------
    public static function getMonthEvents(int $employeeId, int $year, int $month): array
    {
        $token = Gmail::getToken($employeeId);
        if (!$token) return [];

        $start = sprintf('%04d-%02d-01T00:00:00+09:00', $year, $month);
        $end   = date('c', mktime(0, 0, 0, $month + 1, 1, $year));

        $params = http_build_query([
            'calendarId'   => 'primary',
            'timeMin'      => $start,
            'timeMax'      => $end,
            'singleEvents' => 'true',
            'orderBy'      => 'startTime',
            'maxResults'   => 250,
        ]);

        $result = self::apiGet("/calendars/primary/events?{$params}", $token);
        return $result['items'] ?? [];
    }

    // --------------------------------------------------------
    //  HTTP ヘルパー
    // --------------------------------------------------------
    private static function apiGet(string $endpoint, string $token): ?array
    {
        $ch = curl_init(self::API_BASE . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$token}"],
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        return json_decode($res, true);
    }

    private static function apiPost(string $endpoint, string $token, array $data): ?array
    {
        $ch = curl_init(self::API_BASE . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($data),
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer {$token}",
                'Content-Type: application/json',
            ],
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        return json_decode($res, true);
    }

    private static function apiPatch(string $endpoint, string $token, array $data): ?array
    {
        $ch = curl_init(self::API_BASE . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'PATCH',
            CURLOPT_POSTFIELDS     => json_encode($data),
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer {$token}",
                'Content-Type: application/json',
            ],
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        return json_decode($res, true);
    }
}
