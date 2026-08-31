<?php
// ============================================================
//  api/deals.php — 商談管理API
// ============================================================
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/auth/auth.php';
require_once dirname(__DIR__) . '/lib/helpers.php';

Auth::check('general');
if ($_SERVER['REQUEST_METHOD'] === 'POST') verifyCsrf();

$action = $_GET['action'] ?? '';

match($action) {
    'list'        => dealList(),
    'get'         => dealGet(),
    'create'      => dealCreate(),
    'update'      => dealUpdate(),
    'delete'      => dealDelete(),
    'stage'       => dealStage(),
    'activity'    => dealActivity(),
    'products'    => dealProducts(),
    default       => jsonResponse(['ok' => false, 'error' => 'Invalid action'], 400),
};

function dealList(): void {
    $user = Auth::user();
    $role = $user['role'];
    $uid  = $user['id'];

    $status = $_GET['status'] ?? 'open';
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = 20;
    $offset = ($page - 1) * $limit;

    $where = ['d.is_deleted = 0'];
    $params = [];

    if ($status !== 'all') {
        $where[] = 'd.status = ?';
        $params[] = $status;
    }
    if (in_array($role, ['general'], true)) {
        $where[] = 'd.assigned_to = ?';
        $params[] = $uid;
    }
    if (!empty($_GET['stage'])) {
        $where[] = 'd.stage = ?';
        $params[] = $_GET['stage'];
    }
    if (!empty($_GET['q'])) {
        $where[] = '(d.title LIKE ? OR c.company_name LIKE ?)';
        $params[] = '%' . $_GET['q'] . '%';
        $params[] = '%' . $_GET['q'] . '%';
    }

    $sql = 'SELECT d.*, c.company_name
            FROM deals d
            LEFT JOIN customers c ON d.customer_id = c.id
            WHERE ' . implode(' AND ', $where) .
            ' ORDER BY d.updated_at DESC LIMIT ? OFFSET ?';
    $params[] = $limit;
    $params[] = $offset;

    $stmt = DB::crm()->prepare($sql);
    $stmt->execute($params);
    $deals = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $countSql = 'SELECT COUNT(*) FROM deals d LEFT JOIN customers c ON d.customer_id = c.id WHERE ' . implode(' AND ', $where);
    $countParams = array_slice($params, 0, -2);
    $stmtC = DB::crm()->prepare($countSql);
    $stmtC->execute($countParams);
    $total = (int)$stmtC->fetchColumn();

    jsonResponse(['ok' => true, 'deals' => $deals, 'total' => $total, 'page' => $page]);
}

function dealGet(): void {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = DB::crm()->prepare('SELECT d.*, c.company_name
        FROM deals d
        LEFT JOIN customers c ON d.customer_id = c.id
        WHERE d.id = ? AND d.is_deleted = 0');
    $stmt->execute([$id]);
    $deal = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$deal) jsonResponse(['ok' => false, 'error' => '商談が見つかりません'], 404);

    $stmt2 = DB::crm()->prepare('SELECT da.*
        FROM deal_activities da
        WHERE da.deal_id = ? ORDER BY da.activity_at DESC');
    $stmt2->execute([$id]);
    $activities = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    $stmt3 = DB::crm()->prepare('SELECT * FROM deal_products WHERE deal_id = ?');
    $stmt3->execute([$id]);
    $products = $stmt3->fetchAll(PDO::FETCH_ASSOC);

    // 担当者名をstaff_dbから取得
    $assigned_name = '';
    if ($deal['assigned_to']) {
        $empStmt = DB::staff()->prepare('SELECT CONCAT(last_name, first_name) as name FROM employees WHERE id = ?');
        $empStmt->execute([$deal['assigned_to']]);
        $assigned_name = $empStmt->fetchColumn() ?: '';
    }
    $deal['assigned_name'] = $assigned_name;
    jsonResponse(['ok' => true, 'deal' => $deal, 'activities' => $activities, 'products' => $products]);
}

function dealCreate(): void {
    $user = Auth::user();
    $d = $_POST;

    $code = 'DEAL-' . strtoupper(substr(uniqid(), -6));

    $stmt = DB::crm()->prepare('INSERT INTO deals
        (deal_code, title, customer_id, stage, amount, probability, assigned_to, expected_close, description)
        VALUES (?,?,?,?,?,?,?,?,?)');
    $stmt->execute([
        $code,
        $d['title'] ?? '新規商談',
        $d['customer_id'] ?: null,
        $d['stage'] ?? 'リード',
        (int)($d['amount'] ?? 0),
        (int)($d['probability'] ?? 0),
        $d['assigned_to'] ?: $user['id'],
        $d['expected_close'] ?: null,
        $d['description'] ?? '',
    ]);
    $id = DB::crm()->lastInsertId();

    logActivity($id, $user['id'], 'note', '商談を作成しました', '');
    jsonResponse(['ok' => true, 'id' => $id, 'deal_code' => $code]);
}

function dealUpdate(): void {
    $user = Auth::user();
    $d = $_POST;
    $id = (int)($d['id'] ?? 0);

    $stmt = DB::crm()->prepare('UPDATE deals SET
        title=?, customer_id=?, amount=?, probability=?,
        assigned_to=?, expected_close=?, description=?, updated_at=NOW()
        WHERE id=? AND is_deleted=0');
    $stmt->execute([
        $d['title'],
        $d['customer_id'] ?: null,
        (int)$d['amount'],
        (int)$d['probability'],
        $d['assigned_to'] ?: $user['id'],
        $d['expected_close'] ?: null,
        $d['description'] ?? '',
        $id,
    ]);
    jsonResponse(['ok' => true]);
}

function dealDelete(): void {
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int)($d['id'] ?? 0);
    DB::crm()->prepare('UPDATE deals SET is_deleted=1 WHERE id=?')->execute([$id]);
    jsonResponse(['ok' => true]);
}

function dealStage(): void {
    $user = Auth::user();
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $id    = (int)($d['id'] ?? 0);
    $stage = $d['stage'] ?? '';
    $status = $d['status'] ?? 'open';

    $stmt = DB::crm()->prepare('SELECT stage FROM deals WHERE id=?');
    $stmt->execute([$id]);
    $old = $stmt->fetchColumn();

    DB::crm()->prepare('UPDATE deals SET stage=?, status=?, closed_at=?, lost_reason=?, updated_at=NOW() WHERE id=?')
        ->execute([$stage, $status, in_array($status, ['won','lost']) ? date('Y-m-d') : null, $d['lost_reason'] ?? null, $id]);

    logActivity($id, $user['id'], 'stage_change', "ステージ変更: {$old} → {$stage}", '');
    jsonResponse(['ok' => true]);
}

function dealActivity(): void {
    $user = Auth::user();
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    logActivity((int)$d['deal_id'], $user['id'], $d['type'], $d['title'], $d['body'] ?? '');
    jsonResponse(['ok' => true]);
}

function dealProducts(): void {
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int)($d['deal_id'] ?? 0);

    DB::crm()->prepare('DELETE FROM deal_products WHERE deal_id=?')->execute([$id]);
    $total = 0;
    foreach ($d['products'] ?? [] as $p) {
        $amt = (int)$p['unit_price'] * (int)$p['quantity'];
        DB::crm()->prepare('INSERT INTO deal_products (deal_id, name, unit_price, quantity, amount) VALUES (?,?,?,?,?)')
            ->execute([$id, $p['name'], (int)$p['unit_price'], (int)$p['quantity'], $amt]);
        $total += $amt;
    }
    DB::crm()->prepare('UPDATE deals SET amount=?, updated_at=NOW() WHERE id=?')->execute([$total, $id]);
    jsonResponse(['ok' => true, 'total' => $total]);
}

function logActivity(int $dealId, int $empId, string $type, string $title, string $body): void {
    DB::crm()->prepare('INSERT INTO deal_activities (deal_id, employee_id, type, title, body) VALUES (?,?,?,?,?)')
        ->execute([$dealId, $empId, $type, $title, $body]);
}
