<?php
// ============================================================
//  api/gmail_token.php — Gmailトークン管理 API
// ============================================================
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/auth/auth.php';
require_once dirname(__DIR__) . '/lib/helpers.php';
require_once dirname(__DIR__) . '/lib/gmail.php';

Auth::check();
$action = $_GET['action'] ?? '';

// 自分の連携解除
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'unlink') {
    verifyCsrf();
    DB::staff()->prepare('UPDATE gmail_tokens SET is_active = 0 WHERE employee_id = ?')
        ->execute([Auth::user()['id']]);
    header('Location: ' . APP_URL . '/pages/gmail_setting.php?msg=unlinked');
    exit;
}

// 管理者による他社員の連携解除
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'admin_unlink') {
    Auth::check('admin');
    verifyCsrf();
    $empId = (int)($_POST['employee_id'] ?? 0);
    DB::staff()->prepare('UPDATE gmail_tokens SET is_active = 0 WHERE employee_id = ?')->execute([$empId]);
    header('Location: ' . APP_URL . '/pages/gmail_setting.php?msg=unlinked');
    exit;
}

// 連携案内メール送信
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'prompt') {
    Auth::check('admin');
    verifyCsrf();
    $empId = (int)($_POST['employee_id'] ?? 0);
    $emp = DB::staff()->prepare('SELECT email, CONCAT(last_name," ",first_name) AS name FROM employees WHERE id = ?');
    $emp->execute([$empId]);
    $empRow = $emp->fetch();
    if (!$empRow) jsonResponse(['ok' => false, 'error' => '社員が見つかりません'], 404);

    // 管理者のGmailから案内メールを送信
    $subject = '[社内CRM] GmailアカウントのCRM連携をお願いします';
    $body    = implode("\n", [
        $empRow['name'] . ' 様',
        '',
        '社内CRMのGmail連携をお願いいたします。',
        '連携することで、顧客とのメールが自動的にCRMに記録されます。',
        '',
        '以下のURLから連携してください：',
        APP_URL . '/auth/google_login.php',
        '',
        '社内CRM 管理者',
    ]);

    $ok = Gmail::sendMail(Auth::user()['id'], $empRow['email'], $subject, $body);
    $ok ? jsonResponse(['ok' => true, 'message' => '案内メールを送信しました']) : jsonResponse(['ok' => false, 'error' => 'メール送信に失敗しました'], 500);
}

jsonResponse(['ok' => false, 'error' => 'Not Found'], 404);
