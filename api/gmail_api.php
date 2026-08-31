<?php
// ============================================================
//  api/gmail_api.php — Gmail 送受信・未登録通知 API
// ============================================================
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/auth/auth.php';
require_once dirname(__DIR__) . '/lib/helpers.php';
require_once dirname(__DIR__) . '/lib/gmail.php';

Auth::check();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

match(true) {
    $method === 'GET'  && $action === 'inbox'          => gmailInbox(),
    $method === 'GET'  && $action === 'detail'         => gmailDetail(),
    $method === 'POST' && $action === 'send'           => gmailSend(),
    $method === 'POST' && $action === 'sync'           => gmailSync(),
    $method === 'GET'  && $action === 'unknown_list'   => unknownList(),
    $method === 'POST' && $action === 'unknown_ignore'  => unknownIgnore(),
    $method === 'POST' && $action === 'unknown_delete'  => unknownDelete(),
    default => jsonResponse(['ok' => false, 'error' => 'Not Found'], 404),
};

// --------------------------------------------------------
//  受信メール一覧（登録顧客のみ）
// --------------------------------------------------------
function gmailInbox(): never
{
    $user   = Auth::user();
    $db     = DB::crm();
    $limit  = min(50, (int)($_GET['limit'] ?? 20));
    $page   = max(1, (int)($_GET['page']  ?? 1));
    $offset = ($page - 1) * $limit;

    $where  = ["e.direction = 'inbound'"];
    $params = [];

    // 担当者フィルター（管理者はオプション、一般は自分のみ）
    if (!Auth::isManager()) {
        $where[]  = 'e.employee_id = ?';
        $params[] = $user['id'];
    } elseif ($empId = $_GET['employee_id'] ?? '') {
        $where[]  = 'e.employee_id = ?';
        $params[] = (int)$empId;
    }

    if ($customerId = $_GET['customer_id'] ?? '') {
        $where[]  = 'e.customer_id = ?';
        $params[] = (int)$customerId;
    }

    $whereStr = 'WHERE ' . implode(' AND ', $where);

    $total = (int)$db->prepare("SELECT COUNT(*) FROM email_histories e {$whereStr}")
        ->execute($params) ? (int)$db->query("SELECT COUNT(*) FROM email_histories e {$whereStr}")->fetchColumn() : 0;

    $stmt = $db->prepare("
        SELECT e.id, e.subject, e.from_address, e.sent_at,
               e.has_attachment, e.gmail_thread_id,
               c.id AS customer_id, c.company_name AS customer_name,
               LEFT(e.body_plain, 100) AS body_preview
        FROM email_histories e
        JOIN customers c ON c.id = e.customer_id
        {$whereStr}
        ORDER BY e.sent_at DESC
        LIMIT {$limit} OFFSET {$offset}
    ");
    $stmt->execute($params);

    jsonResponse([
        'ok'   => true,
        'data' => $stmt->fetchAll(),
        'pagination' => paginate($total, $limit, $page),
    ]);
}

// --------------------------------------------------------
//  メール詳細
// --------------------------------------------------------
function gmailDetail(): never
{
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonResponse(['ok' => false, 'error' => 'IDが必要です'], 400);

    $stmt = DB::crm()->prepare('
        SELECT e.*, c.company_name AS customer_name, c.id AS customer_id
        FROM email_histories e
        JOIN customers c ON c.id = e.customer_id
        WHERE e.id = ?
    ');
    $stmt->execute([$id]);
    $mail = $stmt->fetch();

    if (!$mail) jsonResponse(['ok' => false, 'error' => 'メールが見つかりません'], 404);

    // スレッド取得（同じthread_id）
    $thread = DB::crm()->prepare('
        SELECT id, direction, from_address, to_address, subject, sent_at, body_plain
        FROM email_histories
        WHERE gmail_thread_id = ? AND customer_id = ?
        ORDER BY sent_at ASC
    ');
    $thread->execute([$mail['gmail_thread_id'], $mail['customer_id']]);

    jsonResponse(['ok' => true, 'mail' => $mail, 'thread' => $thread->fetchAll()]);
}

// --------------------------------------------------------
//  メール送信
// --------------------------------------------------------
function gmailSend(): never
{
    verifyCsrf();

    $user       = Auth::user();
    $to         = trim($_POST['to']          ?? '');
    $subject    = trim($_POST['subject']     ?? '');
    $body       = trim($_POST['body']        ?? '');
    $customerId = (int)($_POST['customer_id'] ?? 0);

    if (!$to || !$subject || !$body) {
        jsonResponse(['ok' => false, 'error' => '宛先・件名・本文は必須です'], 422);
    }

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['ok' => false, 'error' => '宛先のメールアドレスが正しくありません'], 422);
    }

    $ok = Gmail::sendMail($user['id'], $to, $subject, $body, $customerId ?: null);

    if ($ok) {
        // 連絡履歴にも記録
        if ($customerId) {
            DB::crm()->prepare('
                INSERT INTO contact_histories
                    (customer_id, employee_id, type, title, content, contacted_at)
                VALUES (?, ?, \'email\', ?, ?, NOW())
            ')->execute([$customerId, $user['id'], $subject, $body]);
        }
        jsonResponse(['ok' => true, 'message' => 'メールを送信しました']);
    } else {
        jsonResponse(['ok' => false, 'error' => 'メール送信に失敗しました。Gmail連携を確認してください'], 500);
    }
}

// --------------------------------------------------------
//  手動同期（ボタン押下時）
// --------------------------------------------------------
function gmailSync(): never
{
    verifyCsrf();
    $user   = Auth::user();
    $result = Gmail::syncInbox($user['id']);
    jsonResponse($result);
}

// --------------------------------------------------------
//  未登録通知一覧（管理者のみ）
// --------------------------------------------------------
function unknownList(): never
{
    Auth::check();
    $user   = Auth::user();
    $status = $_GET['status'] ?? 'pending';
    $stmt = DB::staff()->prepare('
        SELECT id, from_address, from_name, subject, body_preview,
               received_at, status, handled_at, employee_id
        FROM unknown_email_notices
        WHERE status = ? AND employee_id = ?
        ORDER BY received_at DESC
        LIMIT 100
    ');
    $stmt->execute([$status, $user['id']]);
    jsonResponse(['ok' => true, 'data' => $stmt->fetchAll()]);
}

// --------------------------------------------------------
//  未登録通知を無視
// --------------------------------------------------------
function unknownIgnore(): never
{
    Auth::check();
    verifyCsrf();

    $id   = (int)($_POST['id']   ?? 0);
    $user = Auth::user();

    DB::staff()->prepare('
        UPDATE unknown_email_notices
        SET status = \'ignored\', handled_by = ?, handled_at = NOW()
        WHERE id = ?
    ')->execute([$user['id'], $id]);

    jsonResponse(['ok' => true, 'message' => '無視リストに追加しました']);
}

// --------------------------------------------------------
//  未登録通知を削除
// --------------------------------------------------------
function unknownDelete(): never
{
    Auth::check();
    verifyCsrf();

    $id   = (int)($_POST['id'] ?? 0);
    $user = Auth::user();

    // 自分のレコードのみ削除可能
    DB::staff()->prepare('
        DELETE FROM unknown_email_notices
        WHERE id = ? AND employee_id = ?
    ')->execute([$id, $user['id']]);

    jsonResponse(['ok' => true, 'message' => '削除しました']);
}