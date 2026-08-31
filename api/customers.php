<?php
// ============================================================
//  api/customers.php — 顧客管理 CRUD API
// ============================================================
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/auth/auth.php';
require_once dirname(__DIR__) . '/lib/helpers.php';

Auth::check();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';

match(true) {
    $method === 'GET'  && $action === 'list'   => customerList(),
    $method === 'GET'  && $action === 'detail' => customerDetail(),
    $method === 'POST' && $action === 'create' => customerCreate(),
    $method === 'POST' && $action === 'update' => customerUpdate(),
    $method === 'POST' && $action === 'delete' => customerDelete(),
    $method === 'GET'  && $action === 'export' => customerExport(),
    default => jsonResponse(['ok' => false, 'error' => 'Not Found'], 404),
};

// --------------------------------------------------------
//  顧客一覧
// --------------------------------------------------------
function customerList(): never
{
    $db = DB::crm();

    $where  = ['c.is_deleted = 0'];
    $params = [];

    // 検索
    if ($q = trim($_GET['q'] ?? '')) {
        $where[]  = '(c.company_name LIKE ? OR c.contact_name LIKE ? OR c.email LIKE ?)';
        $like = "%{$q}%";
        array_push($params, $like, $like, $like);
    }

    // ステータス絞り込み
    if ($status = $_GET['status'] ?? '') {
        $where[]  = 'c.status = ?';
        $params[] = $status;
    }

    // 担当者絞り込み（管理者・マネージャー以外は自分のみ）
    $user = Auth::user();
    if (!Auth::isManager()) {
        $where[]  = 'c.assigned_to = ?';
        $params[] = $user['id'];
    } elseif ($assignedTo = $_GET['assigned_to'] ?? '') {
        $where[]  = 'c.assigned_to = ?';
        $params[] = (int)$assignedTo;
    }

    $whereStr = 'WHERE ' . implode(' AND ', $where);

    // 件数
    $count = $db->prepare("SELECT COUNT(*) FROM customers c {$whereStr}");
    $count->execute($params);
    $total = (int)$count->fetchColumn();

    // ページネーション
    $perPage = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $pg      = paginate($total, $perPage, $page);

    // データ取得
    $sql = "
        SELECT c.id, c.company_name, c.contact_name, c.email,
               c.phone, c.status, c.contract_amount, c.contract_end,
               c.assigned_to, c.updated_at,
               (SELECT COUNT(*) FROM email_histories eh WHERE eh.customer_id = c.id) AS email_count
        FROM customers c
        {$whereStr}
        ORDER BY c.updated_at DESC
        LIMIT {$pg['per_page']} OFFSET {$pg['offset']}
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // ステータス集計
    $statsStmt = $db->query("SELECT status, COUNT(*) as cnt FROM customers WHERE is_deleted=0 GROUP BY status");
    $stats = [];
    foreach ($statsStmt->fetchAll() as $s) $stats[$s['status']] = (int)$s['cnt'];
    jsonResponse(['ok' => true, 'data' => $rows, 'pagination' => $pg, 'stats' => $stats]);
}

// --------------------------------------------------------
//  顧客詳細
// --------------------------------------------------------
function customerDetail(): never
{
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonResponse(['ok' => false, 'error' => 'IDが必要です'], 400);

    $db = DB::crm();

    $stmt = $db->prepare('
        SELECT * FROM customers WHERE id = ? AND is_deleted = 0
    ');
    $stmt->execute([$id]);
    $customer = $stmt->fetch();

    if (!$customer) {
        jsonResponse(['ok' => false, 'error' => '顧客が見つかりません'], 404);
    }

    // 権限チェック（一般社員は自分の担当のみ）
    $user = Auth::user();
    if (!Auth::isManager() && $customer['assigned_to'] != $user['id']) {
        jsonResponse(['ok' => false, 'error' => 'アクセス権限がありません'], 403);
    }

    // 関連タスク
    $tasks = $db->prepare('
        SELECT id, title, status, priority, due_date, assigned_to
        FROM tasks
        WHERE customer_id = ? AND is_deleted = 0
        ORDER BY due_date ASC
    ');
    $tasks->execute([$id]);

    // メール履歴（最新20件）
    $emails = $db->prepare('
        SELECT id, direction, from_address, to_address, subject, sent_at, has_attachment
        FROM email_histories
        WHERE customer_id = ?
        ORDER BY sent_at DESC
        LIMIT 20
    ');
    $emails->execute([$id]);

    // 連絡履歴（最新20件）
    $histories = $db->prepare('
        SELECT id, type, title, content, contacted_at, employee_id
        FROM contact_histories
        WHERE customer_id = ?
        ORDER BY contacted_at DESC
        LIMIT 20
    ');
    $histories->execute([$id]);

    // カレンダー予定
    $events = $db->prepare('
        SELECT id, title, start_datetime, end_datetime, is_all_day
        FROM calendar_events
        WHERE customer_id = ? AND is_deleted = 0
          AND end_datetime >= NOW()
        ORDER BY start_datetime ASC
    ');
    $events->execute([$id]);

    jsonResponse([
        'ok'       => true,
        'customer' => $customer,
        'tasks'    => $tasks->fetchAll(),
        'emails'   => $emails->fetchAll(),
        'histories'=> $histories->fetchAll(),
        'events'   => $events->fetchAll(),
    ]);
}

// --------------------------------------------------------
//  顧客登録
// --------------------------------------------------------
function customerCreate(): never
{
    verifyCsrf();

    $data = [
        'company_name'  => trim($_POST['company_name']  ?? ''),
        'industry'      => trim($_POST['industry']      ?? ''),
        'address'       => trim($_POST['address']       ?? ''),
        'phone'         => trim($_POST['phone']         ?? ''),
        'website'       => trim($_POST['website']       ?? ''),
        'contact_name'  => trim($_POST['contact_name']  ?? ''),
        'contact_title' => trim($_POST['contact_title'] ?? ''),
        'email'         => trim($_POST['email']         ?? ''),
        'contact_phone' => trim($_POST['contact_phone'] ?? ''),
        'assigned_to'   => (int)($_POST['assigned_to']  ?? Auth::user()['id']),
        'status'        => $_POST['status']             ?? 'prospect',
        'contract_amount' => (float)($_POST['contract_amount'] ?? 0),
        'contract_start'  => $_POST['contract_start']  ?: null,
        'contract_end'    => $_POST['contract_end']    ?: null,
        'source'          => $_POST['source']          ?? 'other',
        'first_contact_at'=> $_POST['first_contact_at'] ?: null,
        'notes'           => trim($_POST['notes']       ?? ''),
        'created_by'      => Auth::user()['id'],
    ];

    // バリデーション
    if (!$data['company_name']) jsonResponse(['ok' => false, 'error' => '会社名は必須です'], 422);
    if (!$data['email'])        jsonResponse(['ok' => false, 'error' => 'メールアドレスは必須です'], 422);
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['ok' => false, 'error' => 'メールアドレスの形式が正しくありません'], 422);
    }

    $db = DB::crm();

    // メールアドレス重複チェック
    $dup = $db->prepare('SELECT id FROM customers WHERE email = ? AND is_deleted = 0');
    $dup->execute([$data['email']]);
    if ($dup->fetch()) {
        jsonResponse(['ok' => false, 'error' => 'このメールアドレスはすでに登録されています'], 422);
    }

    $sql = '
        INSERT INTO customers
            (company_name, industry, address, phone, website,
             contact_name, contact_title, email, contact_phone,
             assigned_to, status, contract_amount, contract_start, contract_end,
             source, first_contact_at, notes, created_by)
        VALUES
            (:company_name, :industry, :address, :phone, :website,
             :contact_name, :contact_title, :email, :contact_phone,
             :assigned_to, :status, :contract_amount, :contract_start, :contract_end,
             :source, :first_contact_at, :notes, :created_by)
    ';
    $db->prepare($sql)->execute($data);
    $newId = (int)$db->lastInsertId();

    // unknown_email_notices に pending があれば registered に更新
    DB::staff()->prepare('
        UPDATE unknown_email_notices
        SET status = \'registered\', handled_by = ?, handled_at = NOW()
        WHERE from_address = ? AND status = \'pending\'
    ')->execute([Auth::user()['id'], $data['email']]);

    writeLog('info', '顧客登録', ['id' => $newId, 'company' => $data['company_name']]);

    jsonResponse(['ok' => true, 'id' => $newId, 'message' => '顧客を登録しました']);
}

// --------------------------------------------------------
//  顧客更新
// --------------------------------------------------------
function customerUpdate(): never
{
    verifyCsrf();
    if (!Auth::isManager()) {
        jsonResponse(['ok' => false, 'error' => '更新権限がありません'], 403);
    }

    $id = (int)($_POST['id'] ?? 0);
    if (!$id) jsonResponse(['ok' => false, 'error' => 'IDが必要です'], 400);

    $db   = DB::crm();
    $data = [
        'company_name'    => trim($_POST['company_name']    ?? ''),
        'industry'        => trim($_POST['industry']        ?? ''),
        'address'         => trim($_POST['address']         ?? ''),
        'phone'           => trim($_POST['phone']           ?? ''),
        'website'         => trim($_POST['website']         ?? ''),
        'contact_name'    => trim($_POST['contact_name']    ?? ''),
        'contact_title'   => trim($_POST['contact_title']   ?? ''),
        'email'           => trim($_POST['email']           ?? ''),
        'contact_phone'   => trim($_POST['contact_phone']   ?? ''),
        'assigned_to'     => (int)($_POST['assigned_to']    ?? 0),
        'status'          => $_POST['status']               ?? 'prospect',
        'contract_amount' => (float)($_POST['contract_amount'] ?? 0),
        'contract_start'  => $_POST['contract_start']       ?: null,
        'contract_end'    => $_POST['contract_end']         ?: null,
        'notes'           => trim($_POST['notes']           ?? ''),
        'id'              => $id,
    ];

    if (!$data['company_name']) jsonResponse(['ok' => false, 'error' => '会社名は必須です'], 422);

    $db->prepare('
        UPDATE customers SET
            company_name = :company_name, industry = :industry, address = :address,
            phone = :phone, website = :website, contact_name = :contact_name,
            contact_title = :contact_title, email = :email, contact_phone = :contact_phone,
            assigned_to = :assigned_to, status = :status, contract_amount = :contract_amount,
            contract_start = :contract_start, contract_end = :contract_end, notes = :notes
        WHERE id = :id AND is_deleted = 0
    ')->execute($data);

    writeLog('info', '顧客更新', ['id' => $id]);
    jsonResponse(['ok' => true, 'message' => '顧客情報を更新しました']);
}

// --------------------------------------------------------
//  顧客削除（論理削除・管理者のみ）
// --------------------------------------------------------
function customerDelete(): never
{
    verifyCsrf();
    if (!Auth::isAdmin()) {
        jsonResponse(['ok' => false, 'error' => '削除は管理者のみ実行できます'], 403);
    }

    $id = (int)($_POST['id'] ?? 0);
    if (!$id) jsonResponse(['ok' => false, 'error' => 'IDが必要です'], 400);

    DB::crm()->prepare('UPDATE customers SET is_deleted = 1 WHERE id = ?')
        ->execute([$id]);

    writeLog('warn', '顧客削除', ['id' => $id, 'by' => Auth::user()['id']]);
    jsonResponse(['ok' => true, 'message' => '顧客を削除しました']);
}

// --------------------------------------------------------
//  顧客一覧CSVエクスポート
// --------------------------------------------------------
function customerExport(): never
{
    if (!Auth::isManager()) {
        jsonResponse(['ok' => false, 'error' => 'エクスポート権限がありません'], 403);
    }

    $db   = DB::crm();
    $user = Auth::user();

    $where  = ['c.is_deleted = 0'];
    $params = [];

    if (!Auth::isManager()) {
        $where[]  = 'c.assigned_to = ?';
        $params[] = $user['id'];
    }

    $stmt = $db->prepare("
        SELECT c.company_name, c.industry, c.address, c.phone, c.website,
               c.contact_name, c.contact_title, c.email, c.contact_phone,
               c.status, c.contract_amount, c.contract_start, c.contract_end,
               c.source, c.first_contact_at, c.notes, c.created_at
        FROM customers c
        WHERE " . implode(' AND ', $where) . "
        ORDER BY c.created_at DESC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $headers = [
        '会社名','業種','住所','電話番号','ウェブサイト',
        '担当者名','担当者役職','メール','担当者携帯',
        'ステータス','契約金額','契約開始日','契約終了日',
        '流入経路','初回接触日','備考','登録日'
    ];

    $filename = '顧客一覧_' . date('Ymd') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache');

    $out = fopen('php://output', 'w');
    // BOM（Excelで文字化けしないように）
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers);

    foreach ($rows as $row) {
        fputcsv($out, array_values($row));
    }
    fclose($out);
    exit;
}