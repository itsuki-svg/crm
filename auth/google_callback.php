<?php
// ============================================================
//  auth/google_callback.php — Google OAuth2 コールバック
// ============================================================
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/auth/auth.php';
require_once dirname(__DIR__) . '/lib/gmail.php';

Auth::check();

session_start();

$code  = $_GET['code']  ?? '';
$state = $_GET['state'] ?? '';
$error = $_GET['error'] ?? '';

if ($error || !$code) {
    header('Location: ' . APP_URL . '/pages/gmail_setting.php?msg=google_error');
    exit;
}

$ok = Gmail::handleCallback($code, $state);

if ($ok) {
    header('Location: ' . APP_URL . '/pages/gmail_setting.php?msg=linked');
} else {
    header('Location: ' . APP_URL . '/pages/gmail_setting.php?msg=link_failed');
}
exit;
