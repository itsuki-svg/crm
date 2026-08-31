<?php
// ============================================================
//  api/leads.php — MAリード受信API（APIキー認証）
// ============================================================
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/lib/helpers.php';

header('Access-Control-Allow-Origin: ' . APP_URL);
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$action = $_GET['action'] ?? 'receive';

if (in_array($action, ['list', 'update', 'convert'])) {
    require_once dirname(__DIR__) . '/auth/auth.php';
    Auth::check('general');
    if ($action === 'list')    { leadList();    exit; }
    if ($action === 'update')  { leadUpdate();  exit; }
    if ($action === 'convert') { leadConvert(); exit; }
}

if ($action === 'receive') {
    $apiKey   = $_SERVER['HTTP_X_API_KEY'] ?? ($_GET['api_key'] ?? '');
    $validKey = defined('MA_API_KEY') ? MA_API_KEY : 'mK7xP2rL9wQ4nB1j';
    if ($apiKey !== $validKey) {
        jsonResponse(['ok' => false, 'error' => 'Unauthorized'], 401);
    }
    leadReceive();
    exit;
}

jsonResponse(['ok' => false, 'error' => 'Invalid action'], 400);

// --------------------------------------------------------
//  外部受信（ホームページフォームから）
// --------------------------------------------------------
function leadReceive(): void
{
    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $name    = trim($body['name']    ?? ($_POST['name']    ?? ''));
    $email   = trim($body['email']   ?? ($_POST['email']   ?? ''));
    $message = trim($body['message'] ?? ($_POST['message'] ?? ''));
    $source  = trim($body['source']  ?? ($_POST['source']  ?? 'website'));

    if (!$name || !$email) {
        jsonResponse(['ok' => false, 'error' => 'name and email are required'], 422);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['ok' => false, 'error' => 'invalid email'], 422);
    }

    $db   = DB::crm();
    $stmt = $db->prepare('INSERT INTO leads (name, email, message, source, ip_address) VALUES (?,?,?,?,?)');
    $stmt->execute([$name, $email, $message, $source, $_SERVER['REMOTE_ADDR'] ?? '']);
    $id = $db->lastInsertId();

    jsonResponse(['ok' => true, 'id' => $id]);
}

// --------------------------------------------------------
//  リード一覧（CRM内部用）
// --------------------------------------------------------
function leadList(): void
{
    $status = $_GET['status'] ?? '';
    $where  = [];
    $params = [];
    if ($status) {
        $where[]  = 'status = ?';
        $params[] = $status;
    }
    $sql  = 'SELECT * FROM leads';
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY created_at DESC LIMIT 100';

    $stmt = DB::crm()->prepare($sql);
    $stmt->execute($params);
    jsonResponse(['ok' => true, 'leads' => $stmt->fetchAll()]);
}

// --------------------------------------------------------
//  リード更新（ステータス・担当者）
// --------------------------------------------------------
function leadUpdate(): void
{
    $d  = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $id = (int)($d['id'] ?? 0);
    DB::crm()->prepare('UPDATE leads SET status=?, assigned_to=?, updated_at=NOW() WHERE id=?')
        ->execute([$d['status'] ?? 'new', $d['assigned_to'] ?: null, $id]);
    jsonResponse(['ok' => true]);
}

// --------------------------------------------------------
//  顧客に変換
// --------------------------------------------------------
function leadConvert(): void
{
    require_once dirname(__DIR__) . '/auth/auth.php';
    $d    = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $id   = (int)($d['id'] ?? 0);
    $stmt = DB::crm()->prepare('SELECT * FROM leads WHERE id=?');
    $stmt->execute([$id]);
    $lead = $stmt->fetch();
    if (!$lead) {
        jsonResponse(['ok' => false, 'error' => 'リードが見つかりません'], 404);
    }

    $user = Auth::user();
    $cStmt = DB::crm()->prepare('
        INSERT INTO customers
            (company_name, contact_name, email, notes, assigned_to, created_by, source)
        VALUES (?,?,?,?,?,?,?)
    ');
    $cStmt->execute([
        $d['company_name'] ?? $lead['name'],
        $lead['name'],
        $lead['email'],
        $lead['message'],
        $user['id'],
        $user['id'],
        'web',
    ]);
    $customerId = DB::crm()->lastInsertId();

    DB::crm()->prepare('UPDATE leads SET status="converted", customer_id=?, updated_at=NOW() WHERE id=?')
        ->execute([$customerId, $id]);

    jsonResponse(['ok' => true, 'customer_id' => $customerId]);
}