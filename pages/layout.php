<?php
// ============================================================
//  pages/layout.php — 共通レイアウト（ヘッダー・サイドバー）
//  使い方:
//    $pageTitle  = 'ダッシュボード';
//    $pageId     = 'dashboard';   // body[data-page]
//    $activeNav  = 'dashboard';   // nav-itemのactive判定
//    require __DIR__ . '/layout.php';
//    ... ページ固有コンテンツ ...
//    require __DIR__ . '/layout_end.php';
// ============================================================

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/auth/auth.php';
require_once dirname(__DIR__) . '/lib/helpers.php';

// 認証チェック（$requiredRole が設定されていれば使用）
Auth::check($requiredRole ?? 'general');

$user = Auth::user();

// 未読件数取得（ダッシュボード用）
function getUnreadCounts(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $counts = ['mail' => 0, 'task_overdue' => 0, 'todo_pending' => 0, 'unknown' => 0];

    try {
        // 未読メール数（受信）
        $stmt = DB::crm()->prepare('
            SELECT COUNT(*) FROM email_histories
            WHERE employee_id = ? AND direction = \'inbound\'
            AND DATE(sent_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        ');
        $stmt->execute([Auth::user()['id']]);
        $counts['mail'] = (int)$stmt->fetchColumn();

        // 期限超過タスク数
        $stmt2 = DB::crm()->prepare("
            SELECT COUNT(*) FROM tasks
            WHERE assigned_to = ? AND status != 'done'
            AND due_date < CURDATE() AND is_deleted = 0
        ");
        $stmt2->execute([Auth::user()['id']]);
        $counts['task_overdue'] = (int)$stmt2->fetchColumn();

        // 未完了TODO
        $stmt3 = DB::crm()->query("
            SELECT COUNT(*) FROM todos WHERE status = 'open' AND is_deleted = 0
        ");
        $counts['todo_pending'] = (int)$stmt3->fetchColumn();

        // 未登録通知（各自自分の分のみ）
        $stmt4 = DB::staff()->prepare("
            SELECT COUNT(*) FROM unknown_email_notices WHERE status = 'pending' AND employee_id = ?
        ");
        $stmt4->execute([Auth::user()['id']]);
        $counts['unknown'] = (int)$stmt4->fetchColumn();
    } catch (Exception $e) {
        // エラー時はデフォルト値
    }

    $cache = $counts;
    return $cache;
}

$counts = getUnreadCounts();
$navItems = [
    ['id' => 'dashboard', 'label' => 'ダッシュボード', 'icon' => 'ti-layout-dashboard', 'url' => '/dashboard'],
    ['id' => 'customers',  'label' => '顧客管理',       'icon' => 'ti-users',            'url' => '/customers'],
    ['id' => 'leads', 'label' => 'リード管理', 'icon' => 'ti-user-star', 'url' => '/leads'],
    ['id' => 'deals', 'label' => '商談管理', 'icon' => 'ti-briefcase', 'url' => '/deals'],
    ['id' => 'tasks',      'label' => 'タスク管理',     'icon' => 'ti-checklist',        'url' => '/tasks',     'badge' => $counts['task_overdue'], 'badgeClass' => 'nav-badge-red'],
    ['id' => 'todos',      'label' => '社内TODO',        'icon' => 'ti-list-check',       'url' => '/todos',     'badge' => $counts['todo_pending'],  'badgeClass' => 'nav-badge-blue'],
];

$googleNavItems = [
    ['id' => 'mail',    'label' => 'Gmail',      'icon' => 'ti-mail',            'url' => '/mail',            'badge' => $counts['mail'], 'badgeClass' => 'nav-badge-red'],
    ['id' => 'unknown', 'label' => '未登録通知', 'icon' => 'ti-alert-triangle',  'url' => '/unknown', 'badge' => $counts['unknown'], 'badgeClass' => 'nav-badge-amber',
     'style' => 'color:#c2410c'],
    ['id' => 'calendar','label' => 'カレンダー', 'icon' => 'ti-calendar',        'url' => '/calendar'],
];

$manageNavItems = [
    ['id' => 'employees',    'label' => '社員管理',  'icon' => 'ti-id-badge',    'url' => '/employees'],
    ['id' => 'report',       'label' => 'レポート',  'icon' => 'ti-chart-bar',   'url' => '/report'],
    ['id' => 'settings',     'label' => '設定',      'icon' => 'ti-settings',    'url' => '/settings'],
    ['id' => 'gmail_setting','label' => 'Gmail設定', 'icon' => 'ti-brand-google','url' => '/gmail-setting'],
];

// 管理者専用メニュー
$adminNavItems = [];
if (Auth::isAdmin()) {
    $adminNavItems = [
        ['id' => 'api_settings', 'label' => 'Google API設定', 'icon' => 'ti-api', 'url' => '/api-settings'],
    ];
}

// Gmail連携チェック
$hasGmail = false;
try {
    $stmt = DB::staff()->prepare('SELECT id FROM gmail_tokens WHERE employee_id = ? AND is_active = 1');
    $stmt->execute([$user['id']]);
    $hasGmail = (bool)$stmt->fetch();
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/svg+xml" href="<?= APP_URL ?>/public/favicon.svg">
<meta name="csrf-token" content="<?= h(Auth::csrfToken()) ?>">
<meta name="user-id" content="<?= $user['id'] ?>">
<title><?= h($pageTitle ?? 'CRM') ?> — <?= APP_NAME ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
<link rel="stylesheet" href="<?= APP_URL ?>/public/css/style.css">
</head>
<body class="layout" data-page="<?= h($pageId ?? '') ?>">
<script>const APP_URL = <?= json_encode(APP_URL) ?>;</script>

<!-- ローディングオーバーレイ -->
<div id="loading-overlay" class="loading-overlay hidden">
  <div class="spinner" style="width:32px;height:32px;border-width:3px"></div>
</div>

<!-- ===== サイドバー ===== -->
<div class="sidebar">
  <div class="sidebar-logo">
    <i class="ti ti-building-store"></i><?= APP_NAME ?>
  </div>

  <div class="nav-section">メイン</div>
  <?php foreach ($navItems as $nav): ?>
  <a href="<?= APP_URL . $nav['url'] ?>"
     class="nav-item <?= ($activeNav ?? '') === $nav['id'] ? 'active' : '' ?>"
     <?= isset($nav['style']) ? 'style="' . h($nav['style']) . '"' : '' ?>>
    <i class="ti <?= $nav['icon'] ?>"></i>
    <?= h($nav['label']) ?>
    <?php if (!empty($nav['badge'])): ?>
    <span class="nav-badge <?= $nav['badgeClass'] ?? 'nav-badge-blue' ?>"><?= (int)$nav['badge'] ?></span>
    <?php endif; ?>
  </a>
  <?php endforeach; ?>

  <div class="nav-section">Google連携</div>
  <?php foreach ($googleNavItems as $nav): ?>
  <a href="<?= APP_URL . $nav['url'] ?>"
     class="nav-item <?= ($activeNav ?? '') === $nav['id'] ? 'active' : '' ?>"
     <?= isset($nav['style']) ? 'style="' . h($nav['style']) . '"' : '' ?>>
    <i class="ti <?= $nav['icon'] ?>" <?= ($nav['id'] === 'unknown') ? 'style="color:#f97316"' : '' ?>></i>
    <?= h($nav['label']) ?>
    <?php if (!empty($nav['badge'])): ?>
    <span class="nav-badge <?= $nav['badgeClass'] ?? 'nav-badge-blue' ?>"><?= (int)$nav['badge'] ?></span>
    <?php endif; ?>
  </a>
  <?php endforeach; ?>

  <div class="nav-section">管理</div>
  <?php foreach ($manageNavItems as $nav): ?>
  <a href="<?= APP_URL . $nav['url'] ?>"
     class="nav-item <?= ($activeNav ?? '') === $nav['id'] ? 'active' : '' ?>">
    <i class="ti <?= $nav['icon'] ?>"></i>
    <?= h($nav['label']) ?>
  </a>
  <?php endforeach; ?>

  <?php if (Auth::isAdmin() && !empty($adminNavItems)): ?>
  <div class="nav-section">管理者専用</div>
  <?php foreach ($adminNavItems as $nav): ?>
  <a href="<?= APP_URL . $nav['url'] ?>"
     class="nav-item <?= ($activeNav ?? '') === $nav['id'] ? 'active' : '' ?>"
     style="color:#dc2626">
    <i class="ti <?= $nav['icon'] ?>" style="color:#dc2626"></i>
    <?= h($nav['label']) ?>
  </a>
  <?php endforeach; ?>
  <?php endif; ?>
  <div class="sidebar-user">
    <div class="avatar avatar-sm av-blue">
      <?= h(mb_substr($user['name'], 0, 1)) ?>
    </div>
    <div>
      <div class="sidebar-user-name"><?= h($user['name']) ?></div>
      <div class="sidebar-user-role"><?= h(roleLabel($user['role'])['label']) ?></div>
    </div>
    <i class="ti ti-logout logout-btn" id="logout-btn" title="ログアウト"></i>
  </div>
</div>

<!-- ===== メインエリア ===== -->
<div class="main">
<?php
