<?php
// ============================================================
//  api/tasks.php — タスク管理 CRUD API
// ============================================================
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/auth/auth.php';
require_once dirname(__DIR__) . '/lib/helpers.php';

Auth::check();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';

match(true) {
    $method === 'GET'  && $action === 'list'          => taskList(),
    $method === 'GET'  && $action === 'detail'        => taskDetail(),
    $method === 'POST' && $action === 'create'        => taskCreate(),
    $method === 'POST' && $action === 'update'        => taskUpdate(),
    $method === 'POST' && $action === 'update_status' => taskUpdateStatus(),
    $method === 'POST' && $action === 'delete'        => taskDelete(),
    $method === 'POST' && $action === 'comment'       => taskComment(),
    default => jsonResponse(['ok' => false, 'error' => 'Not Found'], 404),
};

// --------------------------------------------------------
//  タスク一覧
// --------------------------------------------------------
function taskList(): never
{
    $db     = DB::crm();
    $user   = Auth::user();
    $where  = ['t.is_deleted = 0'];
    $params = [];

    // 一般社員は自分のタスクのみ
    if (!Auth::isManager()) {
        $where[]  = 't.assigned_to = ?';
        $params[] = $user['id'];
    } elseif ($assignedTo = $_GET['assigned_to'] ?? '') {
        $where[]  = 't.assigned_to = ?';
        $params[] = (int)$assignedTo;
    }

    if ($status = $_GET['status'] ?? '') {
        $where[]  = 't.status = ?';
        $params[] = $status;
    }

    if ($customerId = $_GET['customer_id'] ?? '') {
        $where[]  = 't.customer_id = ?';
        $params[] = (int)$customerId;
    }

    if ($q = trim($_GET['q'] ?? '')) {
        $where[]  = 't.title LIKE ?';
        $params[] = "%{$q}%";
    }

    $whereStr = 'WHERE ' . implode(' AND ', $where);

    // カンバン用: status別グループ取得
    $sql = "
        SELECT t.id, t.title, t.status, t.priority, t.due_date,
               t.assigned_to, t.customer_id, t.google_event_id,
               c.company_name AS customer_name
        FROM tasks t
        LEFT JOIN customers c ON c.id = t.customer_id
        {$whereStr}
        ORDER BY
            FIELD(t.priority,'high','medium','low'),
            t.due_date ASC
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // カンバン形式でグループ化
    $kanban = ['todo' => [], 'in_progress' => [], 'review' => [], 'done' => []];
    foreach ($rows as $row) {
        $kanban[$row['status']][] = $row;
    }

    jsonResponse(['ok' => true, 'kanban' => $kanban, 'flat' => $rows]);
}

// --------------------------------------------------------
//  タスク詳細
// --------------------------------------------------------
function taskDetail(): never
{
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonResponse(['ok' => false, 'error' => 'IDが必要です'], 400);

    $db = DB::crm();

    $stmt = $db->prepare('
        SELECT t.*, c.company_name AS customer_name
        FROM tasks t
        LEFT JOIN customers c ON c.id = t.customer_id
        WHERE t.id = ? AND t.is_deleted = 0
    ');
    $stmt->execute([$id]);
    $task = $stmt->fetch();

    if (!$task) jsonResponse(['ok' => false, 'error' => 'タスクが見つかりません'], 404);

    // コメント取得
    $comments = $db->prepare('
        SELECT id, employee_id, content, created_at
        FROM task_comments
        WHERE task_id = ?
        ORDER BY created_at ASC
    ');
    $comments->execute([$id]);

    jsonResponse([
        'ok'       => true,
        'task'     => $task,
        'comments' => $comments->fetchAll(),
    ]);
}

// --------------------------------------------------------
//  タスク作成
// --------------------------------------------------------
function taskCreate(): never
{
    verifyCsrf();

    $user = Auth::user();
    $data = [
        'customer_id'  => ($_POST['customer_id'] ?? '') ?: null,
        'title'        => trim($_POST['title']        ?? ''),
        'description'  => trim($_POST['description']  ?? ''),
        'assigned_to'  => (int)($_POST['assigned_to'] ?? $user['id']),
        'created_by'   => $user['id'],
        'priority'     => $_POST['priority'] ?? 'medium',
        'status'       => 'todo',
        'due_date'     => $_POST['due_date'] ?: null,
    ];

    if (!$data['title']) {
        jsonResponse(['ok' => false, 'error' => 'タスク名は必須です'], 422);
    }

    $db = DB::crm();
    $db->prepare('
        INSERT INTO tasks
            (customer_id, title, description, assigned_to, created_by,
             priority, status, due_date)
        VALUES
            (:customer_id, :title, :description, :assigned_to, :created_by,
             :priority, :status, :due_date)
    ')->execute($data);

    $newId = (int)$db->lastInsertId();

    // Googleカレンダー登録（期限あり）
    if ($data['due_date']) {
        $eventId = GCalendar::createTaskEvent($newId, $data['title'], $data['due_date'], $data['assigned_to']);
        if ($eventId) {
            $db->prepare('UPDATE tasks SET google_event_id = ? WHERE id = ?')
               ->execute([$eventId, $newId]);
        }
        // 前日リマインダー通知
        Notification::scheduleTaskReminder($newId, $data['title'], $data['due_date'], $data['assigned_to']);
    }

    writeLog('info', 'タスク作成', ['id' => $newId, 'title' => $data['title']]);
    jsonResponse(['ok' => true, 'id' => $newId, 'message' => 'タスクを作成しました']);
}

// --------------------------------------------------------
//  タスク更新
// --------------------------------------------------------
function taskUpdate(): never
{
    verifyCsrf();

    $id = (int)($_POST['id'] ?? 0);
    if (!$id) jsonResponse(['ok' => false, 'error' => 'IDが必要です'], 400);

    $data = [
        'title'       => trim($_POST['title']       ?? ''),
        'description' => trim($_POST['description'] ?? ''),
        'assigned_to' => (int)($_POST['assigned_to'] ?? 0),
        'priority'    => $_POST['priority'] ?? 'medium',
        'due_date'    => $_POST['due_date'] ?: null,
        'id'          => $id,
    ];

    if (!$data['title']) jsonResponse(['ok' => false, 'error' => 'タスク名は必須です'], 422);

    DB::crm()->prepare('
        UPDATE tasks
        SET title = :title, description = :description,
            assigned_to = :assigned_to, priority = :priority, due_date = :due_date
        WHERE id = :id AND is_deleted = 0
    ')->execute($data);

    writeLog('info', 'タスク更新', ['id' => $id]);
    jsonResponse(['ok' => true, 'message' => 'タスクを更新しました']);
}

// --------------------------------------------------------
//  ステータス更新（カンバンのドラッグ＆ドロップ用）
// --------------------------------------------------------
function taskUpdateStatus(): never
{
    verifyCsrf();

    $id     = (int)($_POST['id']     ?? 0);
    $status = $_POST['status'] ?? '';
    $allowed = ['todo', 'in_progress', 'review', 'done'];

    if (!$id || !in_array($status, $allowed, true)) {
        jsonResponse(['ok' => false, 'error' => '不正なパラメータ'], 400);
    }

    $db   = DB::crm();
    $user = Auth::user();

    $completed = $status === 'done' ? 'NOW()' : 'NULL';
    $db->prepare("
        UPDATE tasks
        SET status = ?, completed_at = {$completed}
        WHERE id = ? AND is_deleted = 0
    ")->execute([$status, $id]);

    // 完了時: 上長（manager/admin）に通知
    if ($status === 'done') {
        $task = $db->prepare('SELECT title, assigned_to FROM tasks WHERE id = ?');
        $task->execute([$id]);
        $t = $task->fetch();
        Notification::notifyTaskDone($t['title'], $t['assigned_to'], $user['id']);
    }

    jsonResponse(['ok' => true, 'message' => 'ステータスを更新しました']);
}

// --------------------------------------------------------
//  タスク削除（論理削除）
// --------------------------------------------------------
function taskDelete(): never
{
    verifyCsrf();

    $id = (int)($_POST['id'] ?? 0);
    if (!$id) jsonResponse(['ok' => false, 'error' => 'IDが必要です'], 400);

    DB::crm()->prepare('UPDATE tasks SET is_deleted = 1 WHERE id = ?')
        ->execute([$id]);

    writeLog('warn', 'タスク削除', ['id' => $id]);
    jsonResponse(['ok' => true, 'message' => 'タスクを削除しました']);
}

// --------------------------------------------------------
//  コメント投稿
// --------------------------------------------------------
function taskComment(): never
{
    verifyCsrf();

    $taskId  = (int)($_POST['task_id'] ?? 0);
    $content = trim($_POST['content']  ?? '');

    if (!$taskId || !$content) {
        jsonResponse(['ok' => false, 'error' => 'タスクIDとコメント内容は必須です'], 400);
    }

    $db = DB::crm();
    $db->prepare('
        INSERT INTO task_comments (task_id, employee_id, content)
        VALUES (?, ?, ?)
    ')->execute([$taskId, Auth::user()['id'], $content]);

    $newId = (int)$db->lastInsertId();
    jsonResponse(['ok' => true, 'id' => $newId, 'message' => 'コメントを投稿しました']);
}

// --------------------------------------------------------
//  スタブ（別ファイルで実装）
// --------------------------------------------------------
class GCalendar
{
    public static function createTaskEvent(int $taskId, string $title, string $date, int $empId): ?string
    {
        // lib/google_calendar.php に実装
        return null;
    }
}

class Notification
{
    public static function scheduleTaskReminder(int $taskId, string $title, string $dueDate, int $empId): void
    {
        // lib/notification.php に実装
    }

    public static function notifyTaskDone(string $title, int $assignedTo, int $doneBy): void
    {
        // lib/notification.php に実装
    }
}
