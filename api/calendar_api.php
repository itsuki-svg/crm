<?php
// ============================================================
//  api/calendar_api.php — カレンダーイベント API
// ============================================================
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/auth/auth.php';
require_once dirname(__DIR__) . '/lib/helpers.php';
require_once dirname(__DIR__) . '/lib/gmail.php';
require_once dirname(__DIR__) . '/lib/google_calendar.php';

Auth::check();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

match(true) {
    $method === 'GET'  && $action === 'list'   => calList(),
    $method === 'POST' && $action === 'create' => calCreate(),
    $method === 'POST' && $action === 'delete' => calDelete(),
    default => jsonResponse(['ok' => false, 'error' => 'Not Found'], 404),
};

function calList(): never
{
    $customerId = (int)($_GET['customer_id'] ?? 0);
    $db = DB::crm();
    $where = ['ce.is_deleted = 0'];
    $params = [];
    if ($customerId) { $where[] = 'ce.customer_id = ?'; $params[] = $customerId; }

    $stmt = $db->prepare('
        SELECT ce.*, c.company_name AS customer_name
        FROM calendar_events ce LEFT JOIN customers c ON c.id = ce.customer_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY ce.start_datetime ASC LIMIT 50
    ');
    $stmt->execute($params);
    jsonResponse(['ok' => true, 'data' => $stmt->fetchAll()]);
}

function calCreate(): never
{
    verifyCsrf();
    $user = Auth::user();
    $data = [
        'customer_id'     => ($_POST['customer_id'] ?? '') ?: null,
        'created_by'      => $user['id'],
        'title'           => trim($_POST['title'] ?? ''),
        'description'     => trim($_POST['description'] ?? ''),
        'start_datetime'  => $_POST['start_datetime'] ?? '',
        'end_datetime'    => $_POST['end_datetime']   ?? '',
        'is_all_day'      => (int)($_POST['is_all_day'] ?? 0),
    ];

    if (!$data['title'] || !$data['start_datetime']) {
        jsonResponse(['ok' => false, 'error' => 'タイトルと開始日時は必須です'], 422);
    }

    $db = DB::crm();
    $db->prepare('
        INSERT INTO calendar_events
            (customer_id, created_by, title, description, start_datetime, end_datetime, is_all_day)
        VALUES (:customer_id, :created_by, :title, :description, :start_datetime, :end_datetime, :is_all_day)
    ')->execute($data);
    $newId = (int)$db->lastInsertId();

    // Googleカレンダー登録
    $attendeeIds = isset($_POST['attendee_ids']) ? array_map('intval', (array)$_POST['attendee_ids']) : [$user['id']];
    $googleId = GCalendar::createMeetingEvent($newId, $data['title'], $data['start_datetime'], $data['end_datetime'], $user['id'], $attendeeIds, $data['description']);
    if ($googleId) {
        $db->prepare('UPDATE calendar_events SET google_event_id = ? WHERE id = ?')->execute([$googleId, $newId]);
        // 参加者登録
        foreach ($attendeeIds as $aid) {
            $db->prepare('INSERT IGNORE INTO calendar_event_attendees (event_id, employee_id) VALUES (?,?)')->execute([$newId, $aid]);
        }
    }

    jsonResponse(['ok' => true, 'id' => $newId, 'message' => '予定を追加しました']);
}

function calDelete(): never
{
    verifyCsrf();
    $id = (int)($_POST['id'] ?? 0);
    DB::crm()->prepare('UPDATE calendar_events SET is_deleted = 1 WHERE id = ?')->execute([$id]);
    jsonResponse(['ok' => true, 'message' => '予定を削除しました']);
}
