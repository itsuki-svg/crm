<?php
// ============================================================
//  login.php — ログインページ
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../auth/auth.php';

// すでにログイン済みならダッシュボードへ
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!empty($_SESSION['employee_id'])) {
    // DBセッション照合（有効なセッションか確認）
    $validSession = false;
    try {
        $chkStmt = DB::staff()->prepare('
            SELECT employee_id FROM sessions
            WHERE session_id = ? AND employee_id = ? AND expires_at > NOW()
        ');
        $chkStmt->execute([session_id(), $_SESSION['employee_id']]);
        $validSession = (bool)$chkStmt->fetch();
    } catch (Exception $e) {}

    if ($validSession) {
        header('Location: ' . APP_URL . '/dashboard');
        exit;
    }
    // 無効なセッションはクリア
    session_destroy();
    session_start();
}

$error = '';
$msg   = htmlspecialchars($_GET['msg'] ?? '', ENT_QUOTES, 'UTF-8');

// POST処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim($_POST['employee_code'] ?? '');
    $pass = $_POST['password'] ?? '';

    if (!$code || !$pass) {
        $error = '社員番号とパスワードを入力してください';
    } else {
        $result = Auth::login($code, $pass);
        if ($result['ok']) {
            header('Location: ' . APP_URL . '/dashboard');
            exit;
        }
        $error = $result['error'];
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= APP_NAME ?> — ログイン</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
<style>
*{box-sizing:border-box;margin:0;padding:0;font-family:'Hiragino Sans','Meiryo',sans-serif}
body{min-height:100vh;background:linear-gradient(135deg,#e0f0ff 0%,#f0f0eb 60%,#e8f5e9 100%);display:flex;flex-direction:column;align-items:center;justify-content:center;padding:20px}
.wrap{width:100%;max-width:400px}
.logo-area{text-align:center;margin-bottom:28px}
.logo-icon{width:64px;height:64px;background:#185FA5;border-radius:16px;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;box-shadow:0 4px 16px rgba(24,95,165,.25)}
.logo-icon i{font-size:34px;color:#fff}
.logo-name{font-size:22px;font-weight:600;color:#1e3a5f}
.logo-sub{font-size:12px;color:#888;margin-top:4px}
.card{background:#fff;border-radius:16px;padding:32px;box-shadow:0 8px 32px rgba(0,0,0,.08)}
.field{margin-bottom:16px}
.field-label{font-size:12px;color:#555;font-weight:500;margin-bottom:5px;display:flex;align-items:center;gap:5px}
.field-label i{font-size:14px;color:#185FA5}
input[type=text],input[type=password]{width:100%;border:1.5px solid #e5e7eb;border-radius:8px;padding:10px 12px;font-size:13px;outline:none;font-family:inherit;transition:border-color .15s}
input:focus{border-color:#185FA5;box-shadow:0 0 0 3px rgba(24,95,165,.08)}
.remember{display:flex;align-items:center;gap:6px;margin-bottom:20px}
.remember input{width:auto;accent-color:#185FA5}
.remember label{font-size:12px;color:#666;cursor:pointer}
.btn-login{width:100%;padding:11px;font-size:13px;font-weight:600;background:#185FA5;color:#fff;border:none;border-radius:8px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;transition:background .15s;margin-bottom:12px}
.btn-login:hover{background:#1466b8}
.btn-google{width:100%;padding:10px;font-size:13px;font-weight:500;background:#fff;color:#333;border:1.5px solid #e5e7eb;border-radius:8px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;text-decoration:none;transition:border-color .15s}
.btn-google:hover{border-color:#185FA5;background:#f8fbff}
.divider{display:flex;align-items:center;gap:10px;margin:14px 0;color:#bbb;font-size:12px}
.divider::before,.divider::after{content:'';flex:1;height:1px;background:#eee}
.error-msg{background:#fee2e2;border:1px solid #fca5a5;border-radius:7px;padding:9px 12px;font-size:12px;color:#991b1b;margin-bottom:14px;display:flex;align-items:center;gap:6px}
.info-msg{background:#dbeafe;border:1px solid #bfdbfe;border-radius:7px;padding:9px 12px;font-size:12px;color:#1e3a8a;margin-bottom:14px}
.forgot{text-align:center;font-size:11px;color:#aaa;margin-top:16px}
.version{text-align:center;font-size:10px;color:#bbb;margin-top:22px}
</style>
</head>
<body>
<div class="wrap">
  <div class="logo-area">
    <div class="logo-icon"><i class="ti ti-building-store"></i></div>
    <div class="logo-name"><?= APP_NAME ?></div>
    <div class="logo-sub">Powered by Google Workspace</div>
  </div>

  <div class="card">
    <?php if ($msg): ?>
    <div class="info-msg"><i class="ti ti-info-circle" style="font-size:14px;flex-shrink:0"></i><?= $msg ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="error-msg"><i class="ti ti-alert-circle" style="font-size:15px;flex-shrink:0"></i><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <form method="POST" action="">
      <div class="field">
        <div class="field-label"><i class="ti ti-id-badge"></i>社員番号</div>
        <input type="text" name="employee_code"
               value="<?= htmlspecialchars($_POST['employee_code'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
               placeholder="例: EMP-001" autocomplete="username" required>
      </div>
      <div class="field">
        <div class="field-label"><i class="ti ti-lock"></i>パスワード</div>
        <input type="password" name="password" placeholder="パスワードを入力" autocomplete="current-password" required>
      </div>
      <div class="remember">
        <input type="checkbox" id="rem" name="remember" value="1">
        <label for="rem">ログイン状態を保持する</label>
      </div>
      <button type="submit" class="btn-login">
        <i class="ti ti-login" style="font-size:16px"></i>ログイン
      </button>
    </form>

    <div class="divider">または</div>

    <a href="<?= APP_URL ?>/auth/google_login.php" class="btn-google">
      <svg width="16" height="16" viewBox="0 0 48 48">
        <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
        <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
        <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
        <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.18 1.48-4.97 2.36-8.16 2.36-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
      </svg>
      Googleアカウントでログイン
    </a>

    <div class="forgot">パスワードを忘れた場合は <a href="mailto:admin@company.com" style="color:#185FA5">管理者へご連絡ください</a></div>
  </div>

  <div class="version"><?= APP_NAME ?> v1.0 &nbsp;|&nbsp; © <?= date('Y') ?></div>
</div>
</body>
</html>