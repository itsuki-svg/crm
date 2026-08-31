<?php
// ============================================================
//  auth/google_login.php — Google OAuth2 開始
// ============================================================
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/auth/auth.php';
require_once dirname(__DIR__) . '/lib/gmail.php';

Auth::check(); // ログイン済みでないと連携できない

$url = Gmail::getAuthUrl(Auth::user()['id']);
header('Location: ' . $url);
exit;
