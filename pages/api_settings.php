<?php
// ============================================================
//  pages/api_settings.php — Google API設定（管理者専用）
// ============================================================
$requiredRole = 'admin';
$pageTitle    = 'Google API設定';
$pageId       = 'api_settings';
$activeNav    = 'api_settings';

require __DIR__ . '/layout.php';

// 現在の設定値を取得（config.phpから）
$currentClientId     = GOOGLE_CLIENT_ID     ?? '';
$currentClientSecret = GOOGLE_CLIENT_SECRET ?? '';
$currentRedirectUri  = GOOGLE_REDIRECT_URI  ?? '';

// 保存処理
$successMsg = '';
$errorMsg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $clientId     = trim($_POST['google_client_id']     ?? '');
    $clientSecret = trim($_POST['google_client_secret'] ?? '');

    if (!$clientId || !$clientSecret) {
        $errorMsg = 'クライアントIDとクライアントシークレットは必須です';
    } else {
        // system_settingsテーブルに保存
        $db = DB::staff();
        $settings = [
            'google_client_id'     => $clientId,
            'google_client_secret' => $clientSecret,
        ];
        foreach ($settings as $key => $value) {
            $exists = $db->prepare('SELECT COUNT(*) FROM system_settings WHERE setting_key = ?');
            $exists->execute([$key]);
            if ($exists->fetchColumn() > 0) {
                $db->prepare('UPDATE system_settings SET value = ?, updated_by = ? WHERE setting_key = ?')
                   ->execute([$value, $user['id'], $key]);
            } else {
                $db->prepare('INSERT INTO system_settings (setting_key, value, updated_by) VALUES (?, ?, ?)')
                   ->execute([$key, $value, $user['id']]);
            }
        }
        $successMsg = 'Google API設定を保存しました';
        $currentClientId     = $clientId;
        $currentClientSecret = $clientSecret;

        // config.phpも更新
        $configPath = dirname(__DIR__) . '/config/config.php';
        $configContent = file_get_contents($configPath);
        $configContent = preg_replace(
            "/define\('GOOGLE_CLIENT_ID',\s*'[^']*'\)/",
            "define('GOOGLE_CLIENT_ID', '" . addslashes($clientId) . "')",
            $configContent
        );
        $configContent = preg_replace(
            "/define\('GOOGLE_CLIENT_SECRET',\s*'[^']*'\)/",
            "define('GOOGLE_CLIENT_SECRET', '" . addslashes($clientSecret) . "')",
            $configContent
        );
        file_put_contents($configPath, $configContent);
    }
}

// DB から設定を読み込み
try {
    $db = DB::staff();
    $stmt = $db->query("SELECT setting_key, value FROM system_settings WHERE setting_key IN ('google_client_id','google_client_secret')");
    foreach ($stmt->fetchAll() as $row) {
        if ($row['setting_key'] === 'google_client_id')     $currentClientId     = $row['value'];
        if ($row['setting_key'] === 'google_client_secret') $currentClientSecret = $row['value'];
    }
} catch (Exception $e) {}

// API接続テスト
$apiStatus = '';
if ($currentClientId && $currentClientSecret) {
    $apiStatus = 'configured';
}
?>

<div class="topbar">
  <span class="topbar-title">Google API設定</span>
  <span class="badge badge-red">管理者専用</span>
</div>

<div class="page-content">

  <?php if ($successMsg): ?>
  <div class="alert alert-success" style="margin-bottom:14px">
    <i class="ti ti-circle-check"></i><span><?= h($successMsg) ?></span>
  </div>
  <?php endif; ?>

  <?php if ($errorMsg): ?>
  <div class="alert alert-warning" style="margin-bottom:14px">
    <i class="ti ti-alert-triangle"></i><span><?= h($errorMsg) ?></span>
  </div>
  <?php endif; ?>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">

    <!-- 設定フォーム -->
    <div>
      <div class="card">
        <div class="card-title"><i class="ti ti-brand-google" style="color:#4285F4;font-size:14px"></i>Google OAuth2 設定</div>

        <div class="alert alert-info" style="margin-bottom:14px;font-size:11px">
          <i class="ti ti-info-circle"></i>
          <div>
            <strong>取得場所：</strong>Google Cloud Console → APIとサービス → 認証情報<br>
            <a href="https://console.cloud.google.com/" target="_blank" style="color:#185FA5">console.cloud.google.com を開く →</a>
          </div>
        </div>

        <form method="POST" action="">
          <input type="hidden" name="csrf_token" value="<?= h(Auth::csrfToken()) ?>">

          <div class="form-group">
            <label class="form-label">
              クライアントID <span class="form-required">*</span>
              <span style="font-weight:400;color:#aaa">（xxx.apps.googleusercontent.com）</span>
            </label>
            <input type="text" name="google_client_id"
                   value="<?= h($currentClientId) ?>"
                   placeholder="123456789-xxxxxxxxxxxxxxxxxxxxxxxx.apps.googleusercontent.com"
                   style="font-size:11px">
          </div>

          <div class="form-group">
            <label class="form-label">
              クライアントシークレット <span class="form-required">*</span>
            </label>
            <div style="display:flex;gap:6px">
              <input type="password" name="google_client_secret" id="secret-input"
                     value="<?= h($currentClientSecret) ?>"
                     placeholder="GOCSPX-xxxxxxxxxxxxxxxxxxxxxxxx"
                     style="font-size:11px;flex:1">
              <button type="button" class="btn btn-sm" onclick="toggleSecret()"
                      style="flex-shrink:0" id="toggle-btn">
                <i class="ti ti-eye" id="toggle-icon" style="font-size:13px"></i>
              </button>
            </div>
          </div>

          <div class="form-group">
            <label class="form-label">リダイレクトURI <span style="font-weight:400;color:#aaa">（自動設定）</span></label>
            <input type="text" value="<?= h($currentRedirectUri) ?>" readonly
                   style="font-size:11px;background:#f8f8f5;color:#666;cursor:default">
            <div class="form-hint">Google Cloud ConsoleのリダイレクトURIにこのURLを登録してください</div>
          </div>

          <button type="submit" class="btn btn-primary" style="width:100%;margin-top:4px">
            <i class="ti ti-device-floppy"></i>設定を保存する
          </button>
        </form>
      </div>

      <!-- APIの有効化確認 -->
      <div class="card">
        <div class="card-title"><i class="ti ti-list-check" style="color:#059669;font-size:13px"></i>有効にするAPI</div>
        <div style="display:flex;flex-direction:column;gap:8px">
          <div style="display:flex;align-items:center;gap:10px;padding:8px 10px;background:#f8f8f5;border-radius:7px">
            <i class="ti ti-mail" style="color:#EA4335;font-size:16px"></i>
            <div style="flex:1">
              <div style="font-size:12px;font-weight:500">Gmail API</div>
              <div style="font-size:10px;color:#888">メール送受信・同期に必要</div>
            </div>
            <span class="badge <?= $apiStatus === 'configured' ? 'badge-green' : 'badge-gray' ?>">
              <?= $apiStatus === 'configured' ? '設定済み' : '未設定' ?>
            </span>
          </div>
          <div style="display:flex;align-items:center;gap:10px;padding:8px 10px;background:#f8f8f5;border-radius:7px">
            <i class="ti ti-calendar" style="color:#4285F4;font-size:16px"></i>
            <div style="flex:1">
              <div style="font-size:12px;font-weight:500">Google Calendar API</div>
              <div style="font-size:10px;color:#888">カレンダー連携に必要</div>
            </div>
            <span class="badge <?= $apiStatus === 'configured' ? 'badge-green' : 'badge-gray' ?>">
              <?= $apiStatus === 'configured' ? '設定済み' : '未設定' ?>
            </span>
          </div>
        </div>
      </div>
    </div>

    <!-- セットアップ手順 -->
    <div>
      <div class="card">
        <div class="card-title"><i class="ti ti-help-circle" style="color:#185FA5;font-size:13px"></i>設定手順</div>

        <div style="display:flex;flex-direction:column;gap:10px">
          <?php
          $steps = [
            ['1', 'Google Cloud Consoleにアクセス', 'console.cloud.google.com にログイン', '#4285F4'],
            ['2', 'プロジェクト作成', '「新しいプロジェクト」→ 名前を入力して作成', '#EA4335'],
            ['3', 'APIを有効化', '「APIとサービス」→「ライブラリ」→「Gmail API」と「Google Calendar API」を有効化', '#FBBC05'],
            ['4', 'OAuth同意画面の設定', '「OAuth同意画面」→ ユーザーの種類「内部」→ アプリ名・メールを入力', '#34A853'],
            ['5', 'スコープを追加', 'gmail.readonly / gmail.send / calendar の3つを追加', '#4285F4'],
            ['6', '認証情報を作成', '「認証情報」→「OAuth クライアントID」→「ウェブアプリケーション」→ リダイレクトURIを追加', '#EA4335'],
            ['7', 'IDとシークレットをコピー', '生成されたクライアントIDとシークレットを左の画面に貼り付けて保存', '#34A853'],
          ];
          foreach ($steps as [$num, $title, $desc, $color]):
          ?>
          <div style="display:flex;gap:10px;align-items:flex-start">
            <div style="width:24px;height:24px;border-radius:50%;background:<?= $color ?>;color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:500;flex-shrink:0"><?= $num ?></div>
            <div>
              <div style="font-size:12px;font-weight:500"><?= h($title) ?></div>
              <div style="font-size:10px;color:#888;margin-top:2px;line-height:1.5"><?= h($desc) ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <div class="divider"></div>

        <div style="font-size:11px;color:#555">
          <div style="font-weight:500;margin-bottom:5px">リダイレクトURIの登録（重要）</div>
          <div style="background:#1E2936;color:#A8D8A8;font-family:monospace;font-size:11px;padding:8px 10px;border-radius:6px;word-break:break-all">
            <?= h($currentRedirectUri) ?>
          </div>
          <div style="color:#888;font-size:10px;margin-top:4px">このURLをGoogle Cloud ConsoleのリダイレクトURIに追加してください</div>
        </div>
      </div>

      <!-- 現在の接続状態 -->
      <div class="card">
        <div class="card-title"><i class="ti ti-wifi" style="color:#059669;font-size:13px"></i>接続状態</div>
        <?php if ($apiStatus === 'configured'): ?>
        <div style="display:flex;flex-direction:column;gap:7px">
          <div style="display:flex;justify-content:space-between;font-size:12px;padding:6px 0;border-bottom:1px solid #f0f0f0">
            <span style="color:#888">クライアントID</span>
            <span style="font-weight:500"><?= $currentClientId ? substr(h($currentClientId), 0, 20) . '...' : '未設定' ?></span>
          </div>
          <div style="display:flex;justify-content:space-between;font-size:12px;padding:6px 0;border-bottom:1px solid #f0f0f0">
            <span style="color:#888">クライアントシークレット</span>
            <span style="font-weight:500"><?= $currentClientSecret ? '設定済み ●●●●●●●●' : '未設定' ?></span>
          </div>
          <div style="display:flex;justify-content:space-between;font-size:12px;padding:6px 0">
            <span style="color:#888">Gmail連携社員数</span>
            <span style="font-weight:500">
              <?php
              $gmailCount = DB::staff()->query('SELECT COUNT(*) FROM gmail_tokens WHERE is_active=1')->fetchColumn();
              echo (int)$gmailCount . '名';
              ?>
            </span>
          </div>
        </div>
        <div style="margin-top:10px">
          <a href="<?= APP_URL ?>/auth/google_login.php" class="btn btn-primary btn-sm">
            <svg width="13" height="13" viewBox="0 0 48 48" style="margin-right:2px">
              <path fill="#fff" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
            </svg>
            管理者アカウントでGmail連携
          </a>
        </div>
        <?php else: ?>
        <div style="text-align:center;padding:20px;color:#aaa">
          <i class="ti ti-plug-x" style="font-size:32px;color:#ddd;display:block;margin-bottom:8px"></i>
          <div style="font-size:12px">APIキーが未設定です</div>
          <div style="font-size:10px;margin-top:4px">左のフォームから設定してください</div>
        </div>
        <?php endif; ?>
      </div>
    </div>

  </div>
</div>

<script>
function toggleSecret() {
  const input = document.getElementById('secret-input');
  const icon  = document.getElementById('toggle-icon');
  if (input.type === 'password') {
    input.type = 'text';
    icon.className = 'ti ti-eye-off';
  } else {
    input.type = 'password';
    icon.className = 'ti ti-eye';
  }
}
</script>

<?php require __DIR__ . '/layout_end.php'; ?>