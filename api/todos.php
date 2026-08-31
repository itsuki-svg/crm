<?php
// ============================================================
//  api/todos.php — 社内共有TODO CRUD API
// ============================================================
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/auth/auth.php';
require_once dirname(__DIR__) . '/lib/helpers.php';

Auth::check();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';

match(true) {
    $method === 'GET'  && $action === 'list'    => todoList(),
    $method === 'GET'  && $action === 'detail'  => todoDetail(),
    $method === 'POST' && $action === 'create'  => todoCreate(),
    $method === 'POST' && $action === 'update'  => todoUpdate(),
    $method === 'POST' && $action === 'done'    => todoDone(),
    $method === 'POST' && $action === 'delete'  => todoDelete(),
    $method === 'POST' && $action === 'comment' => todoComment(),
    $method === 'POST' && $action === 'upload'  => todoUpload(),
    default => jsonResponse(['ok' => false, 'error' => 'Not Found'], 404),
};

// --------------------------------------------------------
//  TODO一覧
// --------------------------------------------------------
function todoList(): never
{
    $db     = DB::crm();
    $where  = ['t.is_deleted = 0'];
    $params = [];

    if ($status = $_GET['status'] ?? '') {
        $where[]  = 't.status = ?';
        $params[] = $status;
    }

    if ($priority = $_GET['priority'] ?? '') {
        $where[]  = 't.priority = ?';
        $params[] = $priority;
    }

    if ($assignedTo = $_GET['assigned_to'] ?? '') {
        $where[]  = '(t.assigned_to = ? OR t.assigned_to IS NULL)';
        $params[] = (int)$assignedTo;
    }

    if ($q = trim($_GET['q'] ?? '')) {
        $where[]  = 't.title LIKE ?';
        $params[] = "%{$q}%";
    }

    $whereStr = 'WHERE ' . implode(' AND ', $where);

    $sql = "
        SELECT t.id, t.title, t.description, t.assigned_to, t.created_by,
               t.priority, t.status, t.due_date, t.completed_at, t.completed_by,
               t.created_at,
               (SELECT COUNT(*) FROM todo_comments tc WHERE tc.todo_id = t.id) AS comment_count,
               (SELECT COUNT(*) FROM todo_attachments ta WHERE ta.todo_id = t.id) AS attach_count
        FROM todos t
        {$whereStr}
        ORDER BY
            t.status ASC,
            FIELD(t.priority,'high','medium','low'),
            t.due_date ASC
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    jsonResponse(['ok' => true, 'data' => $stmt->fetchAll()]);
}

// --------------------------------------------------------
//  TODO詳細（コメント・添付ファイル込み）
// --------------------------------------------------------
function todoDetail(): never
{
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonResponse(['ok' => false, 'error' => 'IDが必要です'], 400);

    $db = DB::crm();

    $stmt = $db->prepare('SELECT * FROM todos WHERE id = ? AND is_deleted = 0');
    $stmt->execute([$id]);
    $todo = $stmt->fetch();

    if (!$todo) jsonResponse(['ok' => false, 'error' => 'TODOが見つかりません'], 404);

    // コメント
    $comments = $db->prepare('
        SELECT id, employee_id, content, created_at, updated_at
        FROM todo_comments
        WHERE todo_id = ?
        ORDER BY created_at ASC
    ');
    $comments->execute([$id]);

    // 添付ファイル
    $attachments = $db->prepare('
        SELECT id, employee_id, original_name, file_size, mime_type, created_at
        FROM todo_attachments
        WHERE todo_id = ?
        ORDER BY created_at ASC
    ');
    $attachments->execute([$id]);

    jsonResponse([
        'ok'          => true,
        'todo'        => $todo,
        'comments'    => $comments->fetchAll(),
        'attachments' => $attachments->fetchAll(),
    ]);
}

// --------------------------------------------------------
//  TODO作成
// --------------------------------------------------------
function todoCreate(): never
{
    verifyCsrf();

    $user = Auth::user();
    $data = [
        'title'       => trim($_POST['title']       ?? ''),
        'description' => trim($_POST['description'] ?? ''),
        'assigned_to' => ($_POST['assigned_to'] ?? '') ?: null,
        'created_by'  => $user['id'],
        'priority'    => $_POST['priority'] ?? 'medium',
        'due_date'    => $_POST['due_date'] ?: null,
    ];

    if (!$data['title']) {
        jsonResponse(['ok' => false, 'error' => 'タイトルは必須です'], 422);
    }

    $db = DB::crm();
    $db->prepare('
        INSERT INTO todos
            (title, description, assigned_to, created_by, priority, due_date)
        VALUES
            (:title, :description, :assigned_to, :created_by, :priority, :due_date)
    ')->execute($data);

    $newId = (int)$db->lastInsertId();

    writeLog('info', 'TODO作成', ['id' => $newId, 'title' => $data['title']]);
    jsonResponse(['ok' => true, 'id' => $newId, 'message' => 'TODOを作成しました']);
}

// --------------------------------------------------------
//  TODO更新
// --------------------------------------------------------
function todoUpdate(): never
{
    verifyCsrf();

    $id = (int)($_POST['id'] ?? 0);
    if (!$id) jsonResponse(['ok' => false, 'error' => 'IDが必要です'], 400);

    $db = DB::crm();

    // 作成者または管理者のみ編集可
    $user = Auth::user();
    if (!Auth::isManager()) {
        $row = $db->prepare('SELECT created_by FROM todos WHERE id = ?');
        $row->execute([$id]);
        $t = $row->fetch();
        if (!$t || $t['created_by'] != $user['id']) {
            jsonResponse(['ok' => false, 'error' => '編集権限がありません'], 403);
        }
    }

    $data = [
        'title'       => trim($_POST['title']       ?? ''),
        'description' => trim($_POST['description'] ?? ''),
        'assigned_to' => ($_POST['assigned_to'] ?? '') ?: null,
        'priority'    => $_POST['priority'] ?? 'medium',
        'due_date'    => $_POST['due_date'] ?: null,
        'id'          => $id,
    ];

    if (!$data['title']) jsonResponse(['ok' => false, 'error' => 'タイトルは必須です'], 422);

    $db->prepare('
        UPDATE todos
        SET title = :title, description = :description,
            assigned_to = :assigned_to, priority = :priority, due_date = :due_date
        WHERE id = :id AND is_deleted = 0
    ')->execute($data);

    jsonResponse(['ok' => true, 'message' => 'TODOを更新しました']);
}

// --------------------------------------------------------
//  TODO完了・未完了切り替え
// --------------------------------------------------------
function todoDone(): never
{
    verifyCsrf();

    $id   = (int)($_POST['id']   ?? 0);
    $done = (int)($_POST['done'] ?? 1);

    if (!$id) jsonResponse(['ok' => false, 'error' => 'IDが必要です'], 400);

    $user = Auth::user();

    if ($done) {
        DB::crm()->prepare('
            UPDATE todos
            SET status = \'done\', completed_at = NOW(), completed_by = ?
            WHERE id = ? AND is_deleted = 0
        ')->execute([$user['id'], $id]);
    } else {
        DB::crm()->prepare('
            UPDATE todos
            SET status = \'open\', completed_at = NULL, completed_by = NULL
            WHERE id = ? AND is_deleted = 0
        ')->execute([$id]);
    }

    jsonResponse(['ok' => true, 'message' => $done ? '完了にしました' : '未完了に戻しました']);
}

// --------------------------------------------------------
//  TODO削除（論理削除・作成者または管理者）
// --------------------------------------------------------
function todoDelete(): never
{
    verifyCsrf();

    $id   = (int)($_POST['id'] ?? 0);
    $user = Auth::user();

    $db  = DB::crm();
    $row = $db->prepare('SELECT created_by FROM todos WHERE id = ?');
    $row->execute([$id]);
    $t = $row->fetch();

    if (!$t) jsonResponse(['ok' => false, 'error' => 'TODOが見つかりません'], 404);
    if (!Auth::isAdmin() && $t['created_by'] != $user['id']) {
        jsonResponse(['ok' => false, 'error' => '削除権限がありません'], 403);
    }

    $db->prepare('UPDATE todos SET is_deleted = 1 WHERE id = ?')->execute([$id]);

    writeLog('warn', 'TODO削除', ['id' => $id]);
    jsonResponse(['ok' => true, 'message' => 'TODOを削除しました']);
}

// --------------------------------------------------------
//  コメント投稿
// --------------------------------------------------------
function todoComment(): never
{
    verifyCsrf();

    $todoId  = (int)($_POST['todo_id'] ?? 0);
    $content = trim($_POST['content']  ?? '');

    if (!$todoId || !$content) {
        jsonResponse(['ok' => false, 'error' => 'TODOIDとコメント内容は必須です'], 400);
    }

    $db = DB::crm();
    $db->prepare('
        INSERT INTO todo_comments (todo_id, employee_id, content)
        VALUES (?, ?, ?)
    ')->execute([$todoId, Auth::user()['id'], $content]);

    $newId = (int)$db->lastInsertId();
    jsonResponse(['ok' => true, 'id' => $newId, 'message' => 'コメントを投稿しました']);
}

// --------------------------------------------------------
//  ファイルアップロード
// --------------------------------------------------------
function todoUpload(): never
{
    verifyCsrf();

    $todoId = (int)($_POST['todo_id'] ?? 0);
    if (!$todoId) jsonResponse(['ok' => false, 'error' => 'TODOID必須'], 400);

    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(['ok' => false, 'error' => 'ファイルアップロードに失敗しました'], 400);
    }

    $file = $_FILES['file'];

    // ファイルサイズ上限（10MB）
    if ($file['size'] > 10 * 1024 * 1024) {
        jsonResponse(['ok' => false, 'error' => 'ファイルサイズは10MB以内にしてください'], 422);
    }

    // 許可拡張子
    $allowed = ['pdf','doc','docx','xls','xlsx','ppt','pptx','png','jpg','jpeg','gif','zip','txt','csv'];
    $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) {
        jsonResponse(['ok' => false, 'error' => 'このファイル形式は許可されていません'], 422);
    }

    // 保存
    $stored  = sprintf('%s_%s.%s', date('YmdHis'), bin2hex(random_bytes(4)), $ext);
    $dir     = UPLOAD_DIR . 'todos/' . date('Y/m/');
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $path    = $dir . $stored;

    if (!move_uploaded_file($file['tmp_name'], $path)) {
        jsonResponse(['ok' => false, 'error' => 'ファイル保存に失敗しました'], 500);
    }

    $db = DB::crm();
    $db->prepare('
        INSERT INTO todo_attachments
            (todo_id, employee_id, original_name, stored_name, file_path, file_size, mime_type)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ')->execute([
        $todoId,
        Auth::user()['id'],
        $file['name'],
        $stored,
        $path,
        $file['size'],
        $file['type'],
    ]);

    jsonResponse(['ok' => true, 'id' => (int)$db->lastInsertId(), 'message' => 'ファイルをアップロードしました']);
}
