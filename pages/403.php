<?php
require_once dirname(__DIR__) . '/config/config.php';
/**
 * 403 アクセス拒否ページ
 * 403 アクセス拒否ページ
 * 
 * 使い方:
 *   require_once __DIR__ . '/../pages/403.php';
 *   render403(); exit;
 */

function render403(string $required_role = 'manager'): void {
    $role_labels = [
        'admin'     => '管理者',
        'executive' => '幹部職',
        'manager'   => 'マネージャー',
        'general'   => '一般',
    ];
    $required_label = $role_labels[$required_role] ?? $required_role;

    http_response_code(403);
    ?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>アクセス権限がありません — 社内CRM</title>
  <link rel="stylesheet" href="<?= defined('APP_URL') ? APP_URL : '/crm' ?>/public/css/style.css">
  <style>
    .err-page {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      background: var(--bg-secondary, #f5f5f5);
      font-family: system-ui, 'Hiragino Sans', sans-serif;
      padding: 2rem;
    }
    .err-card {
      background: #fff;
      border: 1px solid rgba(0,0,0,0.1);
      border-radius: 16px;
      padding: 3rem 2.5rem;
      max-width: 440px;
      width: 100%;
      text-align: center;
      box-shadow: 0 4px 24px rgba(0,0,0,0.06);
    }
    .err-icon-wrap {
      position: relative;
      display: inline-block;
      margin-bottom: 1.75rem;
    }
    .err-icon-circle {
      width: 88px;
      height: 88px;
      border-radius: 50%;
      background: #f5f5f5;
      border: 1px solid rgba(0,0,0,0.08);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 36px;
    }
    .err-badge {
      position: absolute;
      top: -4px;
      right: -12px;
      background: #fff0f0;
      color: #c0392b;
      font-size: 10px;
      font-weight: 600;
      padding: 2px 7px;
      border-radius: 20px;
      border: 1px solid #f5c6c6;
      letter-spacing: 0.3px;
    }
    .err-title {
      font-size: 20px;
      font-weight: 600;
      color: #111;
      margin: 0 0 0.6rem;
    }
    .err-sub {
      font-size: 14px;
      color: #666;
      line-height: 1.75;
      margin: 0 0 2rem;
    }
    .err-actions {
      display: flex;
      gap: 10px;
      justify-content: center;
      flex-wrap: wrap;
      margin-bottom: 1.75rem;
    }
    .btn-back {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 9px 20px;
      background: #111;
      color: #fff;
      border: none;
      border-radius: 8px;
      font-size: 14px;
      cursor: pointer;
      text-decoration: none;
      transition: background 0.15s;
    }
    .btn-back:hover { background: #333; }
    .btn-dash {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 9px 20px;
      background: transparent;
      color: #555;
      border: 1px solid rgba(0,0,0,0.15);
      border-radius: 8px;
      font-size: 14px;
      cursor: pointer;
      text-decoration: none;
      transition: background 0.15s;
    }
    .btn-dash:hover { background: #f5f5f5; }
    .err-info {
      background: #fafafa;
      border-radius: 10px;
      border: 1px solid rgba(0,0,0,0.07);
      padding: 12px 16px;
      text-align: left;
    }
    .err-info-row {
      display: flex;
      align-items: flex-start;
      gap: 9px;
      font-size: 12.5px;
      color: #666;
      padding: 6px 0;
    }
    .err-info-row + .err-info-row {
      border-top: 1px solid rgba(0,0,0,0.06);
    }
    .err-info-row svg {
      flex-shrink: 0;
      margin-top: 1px;
      opacity: 0.45;
    }
  </style>
</head>
<body>
<div class="err-page">
  <div class="err-card">

    <div class="err-icon-wrap">
      <div class="err-icon-circle">🔒</div>
      <span class="err-badge">403</span>
    </div>

    <p class="err-title">アクセス権限がありません</p>
    <p class="err-sub">
      このページを表示するには<br>
      <strong><?= htmlspecialchars($required_label) ?>以上</strong>の権限が必要です。
    </p>

    <div class="err-actions">
      <a href="javascript:history.back()" class="btn-back">
        ← 前のページへ戻る
      </a>
      <a href="<?= defined('APP_URL') ? APP_URL : '/crm' ?>/dashboard" class="btn-dash">
        ダッシュボード
      </a>
    </div>

    <div class="err-info">
      <div class="err-info-row">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
        <span>権限の付与は管理者（EMP-001）までご連絡ください</span>
      </div>
      <div class="err-info-row">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <span>現在の権限は社員詳細ページで確認できます</span>
      </div>
    </div>

  </div>
</div>
</body>
</html>
<?php
}