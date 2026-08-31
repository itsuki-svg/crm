<?php
// ============================================================
//  pages/dashboard.php — ダッシュボード
// ============================================================
$pageTitle = 'ダッシュボード';
$pageId    = 'dashboard';
$activeNav = 'dashboard';

require __DIR__ . '/layout.php';

// ---- データ取得 ----
$crmDb   = DB::crm();
$staffDb = DB::staff();
$userId  = $user['id'];

// 統計
$totalCustomers  = (int)$crmDb->query('SELECT COUNT(*) FROM customers WHERE is_deleted=0')->fetchColumn();
$pendingTodos    = (int)$crmDb->query("SELECT COUNT(*) FROM todos WHERE status='open' AND is_deleted=0")->fetchColumn();
$unreadMails     = $counts['mail'];
$unknownCount    = $counts['unknown'];

// 商談サマリー
$dealsOpen    = (int)$crmDb->query("SELECT COUNT(*) FROM deals WHERE status='open' AND is_deleted=0")->fetchColumn();
$dealsWon     = (int)$crmDb->query("SELECT COUNT(*) FROM deals WHERE status='won' AND is_deleted=0")->fetchColumn();
$dealsForecast = (int)$crmDb->query("SELECT COALESCE(SUM(amount * probability / 100), 0) FROM deals WHERE status='open' AND is_deleted=0")->fetchColumn();
$dealsWonAmount = (int)$crmDb->query("SELECT COALESCE(SUM(amount), 0) FROM deals WHERE status='won' AND is_deleted=0")->fetchColumn();

// 最近の商談
$recentDeals = $crmDb->query("
    SELECT d.id, d.title, d.stage, d.amount, d.probability, d.status,
           c.company_name
    FROM deals d
    LEFT JOIN customers c ON c.id = d.customer_id
    WHERE d.is_deleted = 0
    ORDER BY d.updated_at DESC LIMIT 5
")->fetchAll();

// 最近のタスク
$recentTasks = $crmDb->prepare("
    SELECT t.id, t.title, t.status, t.priority, t.due_date,
           c.company_name AS customer_name
    FROM tasks t
    LEFT JOIN customers c ON c.id = t.customer_id
    WHERE t.assigned_to = ? AND t.is_deleted = 0
    ORDER BY t.updated_at DESC LIMIT 5
");
$recentTasks->execute([$userId]);
$recentTasks = $recentTasks->fetchAll();

// 未読メール（登録顧客）
$recentMails = $crmDb->prepare("
    SELECT e.id, e.subject, e.from_address, e.sent_at,
           c.company_name AS customer_name, c.id AS customer_id
    FROM email_histories e
    JOIN customers c ON c.id = e.customer_id
    WHERE e.employee_id = ? AND e.direction = 'inbound'
    ORDER BY e.sent_at DESC LIMIT 5
");
$recentMails->execute([$userId]);
$recentMails = $recentMails->fetchAll();

// 今日のTODO
$todayTodos = $crmDb->query("
    SELECT id, title, priority, assigned_to
    FROM todos
    WHERE status = 'open' AND due_date = CURDATE() AND is_deleted = 0
    LIMIT 5
")->fetchAll();

// 今日の予定
$todayEvents = $crmDb->query("
    SELECT ce.title, ce.start_datetime, ce.end_datetime,
           c.company_name AS customer_name
    FROM calendar_events ce
    LEFT JOIN customers c ON c.id = ce.customer_id
    WHERE DATE(ce.start_datetime) = CURDATE() AND ce.is_deleted = 0
    ORDER BY ce.start_datetime ASC
    LIMIT 5
")->fetchAll();

// タスク進捗集計
$taskStats = $crmDb->query("
    SELECT status, COUNT(*) AS cnt
    FROM tasks
    WHERE is_deleted = 0
    GROUP BY status
")->fetchAll(PDO::FETCH_KEY_PAIR);
$totalTasks = array_sum($taskStats) ?: 1;
?>

<!-- トップバー -->
<div class="topbar">
  <span class="topbar-title">ダッシュボード</span>
  <span style="font-size:10px;color:#aaa"><?= date('Y年m月d日（'.['日','月','火','水','木','金','土'][date('w')].'）') ?></span>
  <?php if ($hasGmail): ?>
  <div class="google-status"><i class="ti ti-circle-check" style="font-size:11px"></i>Google連携中</div>
  <?php endif; ?>
</div>

<div class="page-content">

  <?php if (Auth::isAdmin() && $unknownCount > 0): ?>
  <div class="alert alert-warning">
    <i class="ti ti-alert-triangle"></i>
    <div style="flex:1">
      <div style="font-weight:500">未登録アドレスから<?= $unknownCount ?>件のメールが届いています</div>
    </div>
    <a href="<?= APP_URL ?>/unknown" class="btn btn-sm" style="border-color:#f97316;color:#c2410c">確認する →</a>
  </div>
  <?php endif; ?>

  <!-- スタットカード -->
  <div class="stat-grid stat-grid-5">
    <div class="stat-card">
      <div class="stat-label"><i class="ti ti-users" style="font-size:11px"></i> 顧客数</div>
      <div class="stat-val"><?= $totalCustomers ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label"><i class="ti ti-checklist" style="font-size:11px"></i> 進行中タスク</div>
      <div class="stat-val"><?= $taskStats['in_progress'] ?? 0 ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label"><i class="ti ti-list-check" style="font-size:11px"></i> 未完了TODO</div>
      <div class="stat-val" style="color:#5b21b6"><?= $pendingTodos ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label"><i class="ti ti-mail" style="font-size:11px"></i> 未読メール</div>
      <div class="stat-val" style="color:#185FA5"><?= $unreadMails ?></div>
    </div>
    <?php if (Auth::isAdmin()): ?>
    <div class="stat-card" style="<?= $unknownCount > 0 ? 'border:1px solid #fed7aa;background:#fff7ed' : '' ?>">
      <div class="stat-label" style="<?= $unknownCount > 0 ? 'color:#c2410c' : '' ?>"><i class="ti ti-alert-triangle" style="font-size:11px"></i> 未登録通知</div>
      <div class="stat-val" style="color:<?= $unknownCount > 0 ? '#f97316' : '#aaa' ?>"><?= $unknownCount ?></div>
    </div>
    <?php endif; ?>
  </div>

  <!-- 商談サマリー -->
  <div class="card" style="margin-bottom:10px">
    <div class="card-title"><i class="ti ti-briefcase" style="color:#185FA5;font-size:13px"></i>商談サマリー
      <a href="<?= APP_URL ?>/deals" style="margin-left:auto;font-size:11px;color:#185FA5;font-weight:400">すべて見る →</a>
    </div>
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px">
      <div style="text-align:center;padding:10px;background:#f0f9ff;border-radius:8px">
        <div style="font-size:11px;color:#888;margin-bottom:4px">進行中</div>
        <div style="font-size:22px;font-weight:600;color:#185FA5"><?= $dealsOpen ?></div>
        <div style="font-size:10px;color:#aaa">件</div>
      </div>
      <div style="text-align:center;padding:10px;background:#f0fdf4;border-radius:8px">
        <div style="font-size:11px;color:#888;margin-bottom:4px">受注済</div>
        <div style="font-size:22px;font-weight:600;color:#065f46"><?= $dealsWon ?></div>
        <div style="font-size:10px;color:#aaa">件</div>
      </div>
      <div style="text-align:center;padding:10px;background:#fffbeb;border-radius:8px">
        <div style="font-size:11px;color:#888;margin-bottom:4px">受注予測</div>
        <div style="font-size:18px;font-weight:600;color:#92400e">¥<?= number_format($dealsForecast) ?></div>
        <div style="font-size:10px;color:#aaa">確度加重平均</div>
      </div>
      <div style="text-align:center;padding:10px;background:#f0fdf4;border-radius:8px">
        <div style="font-size:11px;color:#888;margin-bottom:4px">受注金額合計</div>
        <div style="font-size:18px;font-weight:600;color:#065f46">¥<?= number_format($dealsWonAmount) ?></div>
        <div style="font-size:10px;color:#aaa">累計</div>
      </div>
    </div>
    <?php if ($recentDeals): ?>
    <table>
      <thead><tr><th>商談名</th><th>会社名</th><th>ステージ</th><th>金額</th><th>確度</th></tr></thead>
      <tbody>
        <?php foreach ($recentDeals as $d):
          $statusColor = ['open'=>'#185FA5','won'=>'#065f46','lost'=>'#dc2626'][$d['status']] ?? '#888';
          $statusLabel = ['open'=>'進行中','won'=>'受注','lost'=>'失注'][$d['status']] ?? $d['status'];
        ?>
        <tr>
          <td><a href="<?= APP_URL ?>/deals" class="text-primary"><?= h($d['title']) ?></a></td>
          <td><?= h($d['company_name'] ?? '—') ?></td>
          <td><span class="badge badge-blue"><?= h($d['stage']) ?></span></td>
          <td style="font-weight:500">¥<?= number_format($d['amount']) ?></td>
          <td style="color:<?= $statusColor ?>;font-weight:500"><?= $d['probability'] ?>%</td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
    <div style="text-align:center;color:#aaa;padding:15px;font-size:11px">商談がありません</div>
    <?php endif; ?>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">

    <!-- 最近のタスク -->
    <div class="card">
      <div class="card-title"><i class="ti ti-clock" style="color:#185FA5;font-size:13px"></i>最近のタスク</div>
      <?php if ($recentTasks): ?>
      <table>
        <thead><tr><th>タスク名</th><th>顧客</th><th>期限</th><th>状態</th></tr></thead>
        <tbody>
          <?php foreach ($recentTasks as $t): ?>
          <tr>
            <td><a href="<?= APP_URL ?>/tasks" class="text-primary"><?= h($t['title']) ?></a></td>
            <td><?= h($t['customer_name'] ?? '—') ?></td>
            <td style="<?= ($t['due_date'] && $t['due_date'] < date('Y-m-d')) ? 'color:#dc2626;font-weight:500' : 'color:#aaa' ?>"><?= $t['due_date'] ? fmtDate($t['due_date']) : '—' ?></td>
            <td><?php $sl = taskStatusLabel($t['status']); ?><span class="badge <?= $sl['class'] ?>"><?= $sl['label'] ?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php else: ?>
      <div style="text-align:center;color:#aaa;padding:15px;font-size:11px">タスクはありません</div>
      <?php endif; ?>
    </div>

    <!-- 未読メール -->
    <div class="card">
      <div class="card-title"><i class="ti ti-mail" style="color:#EA4335;font-size:13px"></i>未読メール（登録顧客）</div>
      <?php if ($recentMails): ?>
      <?php foreach ($recentMails as $m): ?>
      <div class="mail-row unread">
        <div class="avatar avatar-sm av-teal"><?= h(mb_substr($m['customer_name'], 0, 1)) ?></div>
        <div style="flex:1;min-width:0">
          <div class="mail-subj"><?= h($m['subject']) ?></div>
          <div style="font-size:10px;color:#888"><?= h($m['customer_name']) ?></div>
        </div>
        <div class="mail-time"><?= fmtDate($m['sent_at'], 'M/d') ?></div>
      </div>
      <?php endforeach; ?>
      <?php else: ?>
      <div style="text-align:center;color:#aaa;padding:15px;font-size:11px">未読メールはありません</div>
      <?php endif; ?>
      <div style="margin-top:8px;padding:5px 8px;background:#f8f8f5;border-radius:6px;font-size:10px;color:#888;text-align:center">
        <i class="ti ti-lock" style="font-size:11px"></i> 未登録アドレスのメールは非表示
      </div>
    </div>

    <!-- 今日のTODO -->
    <div class="card" style="margin-bottom:0">
      <div class="card-title"><i class="ti ti-list-check" style="color:#5b21b6;font-size:13px"></i>今日の社内TODO</div>
      <?php if ($todayTodos): ?>
      <?php foreach ($todayTodos as $t): $pl = priorityLabel($t['priority']); ?>
      <div style="display:flex;align-items:center;gap:7px;padding:6px 8px;background:#faf5ff;border-radius:6px;border-left:3px solid #7c3aed;margin-bottom:6px">
        <div style="flex:1;font-size:11px;font-weight:500"><?= h($t['title']) ?></div>
        <span class="badge badge-<?= ['high'=>'red','medium'=>'amber','low'=>'green'][$t['priority']] ?? 'gray' ?>" style="font-size:9px"><?= $pl['label'] ?></span>
      </div>
      <?php endforeach; ?>
      <?php else: ?>
      <div style="text-align:center;color:#aaa;padding:15px;font-size:11px">今日期限のTODOはありません</div>
      <?php endif; ?>
    </div>

    <!-- タスク進捗 -->
    <div class="card" style="margin-bottom:0">
      <div class="card-title"><i class="ti ti-chart-pie" style="color:#065f46;font-size:13px"></i>タスク進捗</div>
      <?php
      $statusMap = ['done'=>['完了','#10b981'], 'in_progress'=>['進行中','#3b82f6'], 'review'=>['確認待ち','#f59e0b'], 'todo'=>['未着手','#9ca3af']];
      foreach ($statusMap as $st => [$label, $color]):
        $cnt = $taskStats[$st] ?? 0;
        $pct = round($cnt / $totalTasks * 100);
      ?>
      <div style="margin-bottom:9px">
        <div style="display:flex;justify-content:space-between;font-size:11px;margin-bottom:2px">
          <span><?= $label ?></span>
          <span style="font-weight:500;color:<?= $color ?>"><?= $pct ?>%</span>
        </div>
        <div class="pbar"><div class="pfill" style="width:<?= $pct ?>%;background:<?= $color ?>"></div></div>
      </div>
      <?php endforeach; ?>
    </div>

  </div>
</div>

<?php require __DIR__ . '/layout_end.php';
