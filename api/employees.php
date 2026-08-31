<?php
// ============================================================
//  api/employees.php — 社員管理 CRUD API
// ============================================================
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/auth/auth.php';
require_once dirname(__DIR__) . '/lib/helpers.php';

Auth::check();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';

match(true) {
    $method === 'GET'  && $action === 'list'   => empList(),
    $method === 'GET'  && $action === 'detail' => empDetail(),
    $method === 'POST' && $action === 'create' => empCreate(),
    $method === 'POST' && $action === 'update' => empUpdate(),
    $method === 'POST' && $action === 'toggle' => empToggle(),
    $method === 'POST' && $action === 'reset_password' => empResetPassword(),
    default => jsonResponse(['ok' => false, 'error' => 'Not Found'], 404),
};

function empList(): never
{
    // 一般社員は自分の情報のみ
    if (!Auth::isManager()) {
        $stmt = DB::staff()->prepare('
            SELECT e.*, d.name AS dept_name,
                   (SELECT gmail_address FROM gmail_tokens WHERE employee_id = e.id AND is_active = 1) AS gmail_address
            FROM employees e JOIN departments d ON d.id = e.department_id
            WHERE e.id = ?
        ');
        $stmt->execute([Auth::user()['id']]);
        jsonResponse(['ok' => true, 'data' => [$stmt->fetch()]]);
    }

    $where  = ['e.is_active = 1'];
    $params = [];

    if ($dept = $_GET['department_id'] ?? '') {
        $where[]  = 'e.department_id = ?';
        $params[] = (int)$dept;
    }
    if ($role = $_GET['role'] ?? '') {
        $where[]  = 'e.role = ?';
        $params[] = $role;
    }

    $whereStr = 'WHERE ' . implode(' AND ', $where);
    $stmt = DB::staff()->prepare("
        SELECT e.id, e.employee_code, e.last_name, e.first_name,
               e.email, e.role, e.is_active, e.position, e.joined_at,
               d.name AS dept_name,
               (SELECT gmail_address FROM gmail_tokens WHERE employee_id = e.id AND is_active = 1) AS gmail_address,
               0 AS customer_count,
               0 AS task_count
        FROM employees e
        JOIN departments d ON d.id = e.department_id
        {$whereStr}
        ORDER BY e.department_id ASC, e.id ASC
    ");
    $stmt->execute($params);
    $employees = $stmt->fetchAll();
    // 顧客数をCRMのDBから取得
    $crmDb = DB::crm();
    foreach ($employees as &$emp) {
        $cStmt = $crmDb->prepare('SELECT COUNT(*) FROM customers WHERE assigned_to = ? AND is_deleted = 0');
        $cStmt->execute([$emp['id']]);
        $emp['customer_count'] = (int)$cStmt->fetchColumn();
    }
    unset($emp);
    jsonResponse(['ok' => true, 'data' => $employees]);
}

function empDetail(): never
{
    $id   = (int)($_GET['id'] ?? Auth::user()['id']);
    $user = Auth::user();

    if (!Auth::isManager() && $id !== $user['id']) {
        jsonResponse(['ok' => false, 'error' => 'アクセス権限がありません'], 403);
    }

    $stmt = DB::staff()->prepare('
        SELECT e.*, d.name AS dept_name,
               (SELECT gmail_address FROM gmail_tokens WHERE employee_id = e.id AND is_active = 1) AS gmail_address,
               a.last_login_at, a.last_login_ip
        FROM employees e
        JOIN departments d ON d.id = e.department_id
        JOIN auth a ON a.employee_id = e.id
        WHERE e.id = ?
    ');
    $stmt->execute([$id]);
    $emp = $stmt->fetch();
    if (!$emp) jsonResponse(['ok' => false, 'error' => '社員が見つかりません'], 404);

    // 担当タスク
    $tasks = DB::crm()->prepare("
        SELECT id, title, status, priority, due_date
        FROM tasks WHERE assigned_to = ? AND is_deleted = 0
        ORDER BY due_date ASC LIMIT 10
    ");
    $tasks->execute([$id]);

    jsonResponse(['ok' => true, 'employee' => $emp, 'tasks' => $tasks->fetchAll()]);
}

function empCreate(): never
{
    Auth::check('admin'); // 管理者のみ
    verifyCsrf();

    $data = [
        'employee_code'   => trim($_POST['employee_code']   ?? ''),
        'last_name'       => trim($_POST['last_name']       ?? ''),
        'first_name'      => trim($_POST['first_name']      ?? ''),
        'last_name_kana'  => trim($_POST['last_name_kana']  ?? ''),
        'first_name_kana' => trim($_POST['first_name_kana'] ?? ''),
        'department_id'   => (int)($_POST['department_id']  ?? 0),
        'position'        => trim($_POST['position']        ?? ''),
        'email'           => trim($_POST['email']           ?? ''),
        'phone'           => trim($_POST['phone']           ?? ''),
        'role'            => $_POST['role'] ?? 'general',
        'joined_at'       => $_POST['joined_at'] ?: date('Y-m-d'),
    ];
    $password = $_POST['password'] ?? '';

    if (!$data['employee_code'] || !$data['last_name'] || !$data['email'] || !$password) {
        jsonResponse(['ok' => false, 'error' => '必須項目を入力してください'], 422);
    }

    $db = DB::staff();
    $dup = $db->prepare('SELECT id FROM employees WHERE employee_code = ? OR email = ?');
    $dup->execute([$data['employee_code'], $data['email']]);
    if ($dup->fetch()) jsonResponse(['ok' => false, 'error' => '社員番号またはメールアドレスが重複しています'], 422);

    $db->prepare('
        INSERT INTO employees
            (employee_code, last_name, first_name, last_name_kana, first_name_kana,
             department_id, position, email, phone, role, joined_at)
        VALUES
            (:employee_code, :last_name, :first_name, :last_name_kana, :first_name_kana,
             :department_id, :position, :email, :phone, :role, :joined_at)
    ')->execute($data);

    $newId = (int)$db->lastInsertId();

    $db->prepare('INSERT INTO auth (employee_id, password_hash) VALUES (?, ?)')
        ->execute([$newId, password_hash($password, PASSWORD_BCRYPT)]);

    writeLog('info', '社員作成', ['id' => $newId]);
    jsonResponse(['ok' => true, 'id' => $newId, 'message' => '社員を登録しました']);
}

function empUpdate(): never
{
    verifyCsrf();

    $id   = (int)($_POST['id'] ?? 0);
    $user = Auth::user();

    // 自分以外の編集は管理者のみ
    if ($id !== $user['id'] && !Auth::isAdmin()) {
        jsonResponse(['ok' => false, 'error' => '編集権限がありません'], 403);
    }

    $data = [
        'last_name'       => trim($_POST['last_name']       ?? ''),
        'first_name'      => trim($_POST['first_name']      ?? ''),
        'last_name_kana'  => trim($_POST['last_name_kana']  ?? ''),
        'first_name_kana' => trim($_POST['first_name_kana'] ?? ''),
        'position'        => trim($_POST['position']        ?? ''),
        'phone'           => trim($_POST['phone']           ?? ''),
        'id'              => $id,
    ];

    // 管理者のみ変更可
    if (Auth::isAdmin()) {
        $data['department_id'] = (int)($_POST['department_id'] ?? 0);
        $data['role']          = $_POST['role'] ?? 'general';
    }

    $setData = $data;
    unset($setData['id']);
    $setClauses = array_map(fn($k) => "{$k} = :{$k}", array_keys($setData));
    DB::staff()->prepare('UPDATE employees SET ' . implode(', ', $setClauses) . ' WHERE id = :id')
        ->execute($data);

    writeLog('info', '社員更新', ['id' => $id]);
    jsonResponse(['ok' => true, 'message' => '社員情報を更新しました']);
}

function empToggle(): never
{
    Auth::check('admin');
    verifyCsrf();

    $id     = (int)($_POST['id']     ?? 0);
    $active = (int)($_POST['active'] ?? 1);

    DB::staff()->prepare('UPDATE employees SET is_active = ? WHERE id = ?')
        ->execute([$active, $id]);

    jsonResponse(['ok' => true, 'message' => $active ? '有効化しました' : '無効化しました']);
}

function empResetPassword(): never
{
    Auth::check('admin');
    verifyCsrf();

    $id       = (int)($_POST['id']       ?? 0);
    $password = $_POST['new_password']   ?? '';

    if (!$id || strlen($password) < 8) {
        jsonResponse(['ok' => false, 'error' => 'パスワードは8文字以上で入力してください'], 422);
    }

    DB::staff()->prepare('UPDATE auth SET password_hash = ?, login_failure_count = 0, locked_until = NULL WHERE employee_id = ?')
        ->execute([password_hash($password, PASSWORD_BCRYPT), $id]);

    writeLog('warn', 'パスワードリセット', ['target' => $id, 'by' => Auth::user()['id']]);
    jsonResponse(['ok' => true, 'message' => 'パスワードをリセットしました']);
}