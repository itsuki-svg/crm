<?php
// ============================================================
//  api/contact_history.php — 連絡履歴 API
// ============================================================
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/auth/auth.php';
require_once dirname(__DIR__) . '/lib/helpers.php';

Auth::check();
$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create') {
    verifyCsrf();
    $data = [
        'customer_id'  => (int)($_POST['customer_id']  ?? 0),
        'employee_id'  => Auth::user()['id'],
        'type'         => $_POST['type']  ?? 'memo',
        'title'        => trim($_POST['title']   ?? ''),
        'content'      => trim($_POST['content'] ?? ''),
        'contacted_at' => $_POST['contacted_at'] ?: date('Y-m-d H:i:s'),
    ];
    if (!$data['customer_id'] || !$data['title']) {
        jsonResponse(['ok' => false, 'error' => '必須項目が不足しています'], 422);
    }
    DB::crm()->prepare('
        INSERT INTO contact_histories (customer_id, employee_id, type, title, content, contacted_at)
        VALUES (:customer_id, :employee_id, :type, :title, :content, :contacted_at)
    ')->execute($data);
    jsonResponse(['ok' => true, 'message' => '連絡履歴を記録しました']);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'list') {
    $customerId = (int)($_GET['customer_id'] ?? 0);
    if (!$customerId) jsonResponse(['ok' => false, 'error' => 'customer_id必須'], 400);
    $stmt = DB::crm()->prepare('
        SELECT * FROM contact_histories WHERE customer_id = ? ORDER BY contacted_at DESC LIMIT 30
    ');
    $stmt->execute([$customerId]);
    jsonResponse(['ok' => true, 'data' => $stmt->fetchAll()]);
}

jsonResponse(['ok' => false, 'error' => 'Not Found'], 404);
