<?php
// ============================================================
//  auth/logout.php — ログアウト処理
// ============================================================
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/auth/auth.php';

Auth::logout();
header('Location: ' . APP_URL . '/login?msg=' . urlencode('ログアウトしました'));
exit;