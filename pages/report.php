<?php
$pageTitle = 'レポート・分析';
$pageId    = 'report';
$activeNav = 'report';
require __DIR__ . '/layout.php';

$year  = (int)($_GET['year']  ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));
$start = sprintf('%04d-%02d-01', $year, $month);
$end   = date('Y-m-t', strtotime($start));

$crmDb = DB::crm();

// 今月の新規顧客
$newCustomers = (int)$crmDb->prepare('SELECT COUNT(*) FROM customers WHERE DATE(created_at) BETWEEN ? AND ? AND is_deleted=0')->execute([$start,$end]) ? (int)$crmDb->query("SELECT COUNT(*) FROM customers WHERE DATE(created_at) BETWEEN '{$start}' AND '{$end}' AND is_deleted=0")->fetchColumn() : 0;

// 完了タスク
$doneTasks = (int)$crmDb->query("SELECT COUNT(*) FROM tasks WHERE DATE(completed_at) BETWEEN '{$start}' AND '{$end}'")->fetchColumn();

// TODO完了率
$totalTodos = (int)$crmDb->query("SELECT COUNT(*) FROM todos WHERE is_deleted=0")->fetchColumn();
$doneTodos  = (int)$crmDb->query("SELECT COUNT(*) FROM todos WHERE status='done' AND is_deleted=0")->fetchColumn();
$todoRate   = $totalTodos ? round($doneTodos/$totalTodos*100) : 0;

// メール件数
$sentMails = (int)$crmDb->query("SELECT COUNT(*) FROM email_histories WHERE direction='outbound' AND DATE(sent_at) BETWEEN '{$start}' AND '{$end}'")->fetchColumn();
$recvMails = (int)$crmDb->query("SELECT COUNT(*) FROM email_histories WHERE direction='inbound'  AND DATE(sent_at) BETWEEN '{$start}' AND '{$end}'")->fetchColumn();
$unknownMails = (int)DB::staff()->query("SELECT COUNT(*) FROM unknown_email_notices WHERE DATE(received_at) BETWEEN '{$start}' AND '{$end}'")->fetchColumn();
$regFromUnknown = (int)DB::staff()->query("SELECT COUNT(*) FROM unknown_email_notices WHERE status='registered' AND DATE(received_at) BETWEEN '{$start}' AND '{$end}'")->fetchColumn();

// 顧客ステータス分布
$statusDist = $crmDb->query("SELECT status, COUNT(*) AS cnt FROM customers WHERE is_deleted=0 GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$totalCust  = array_sum($statusDist) ?: 1;

// 月別新規顧客（過去6ヶ月）
$monthlyNew = [];
for ($i = 5; $i >= 0; $i--) {
    $y = date('Y', strtotime("-{$i} month"));
    $m = date('n', strtotime("-{$i} month"));
    $s = sprintf('%04d-%02d-01', $y, $m);
    $e2 = date('Y-m-t', strtotime($s));
    $cnt = (int)$crmDb->query("SELECT COUNT(*) FROM customers WHERE DATE(created_at) BETWEEN '{$s}' AND '{$e2}' AND is_deleted=0")->fetchColumn();
    $monthlyNew[] = ['label' => $m.'月', 'count' => $cnt, 'current' => ($i === 0)];
}

// 社員別タスク
$empTasks = $crmDb->query("
    SELECT t.assigned_to,
           COUNT(*) AS total,
           SUM(t.status='done') AS done_cnt,
           SUM(t.status='in_progress') AS prog_cnt,
           SUM(t.status!='done' AND t.due_date < CURDATE()) AS overdue_cnt
    FROM tasks t
    WHERE t.is_deleted=0
    GROUP BY t.assigned_to
")->fetchAll();

$empNames = DB::staff()->query("SELECT id, CONCAT(last_name,' ',first_name) AS name FROM employees WHERE is_active=1")->fetchAll(PDO::FETCH_KEY_PAIR);
?>
<div class="topbar">
  <span class="topbar-title">レポート・分析</span>
  <form method="GET" style="display:flex;gap:6px;align-items:center">
    <select name="year" style="font-size:11px">
      <?php for ($y=date('Y'); $y>=date('Y')-2; $y--): ?>
      <option value="<?= $y ?>" <?= $y==$year?'selected':'' ?>><?= $y ?>年</option>
      <?php endfor; ?>
    </select>
    <select name="month" style="font-size:11px">
      <?php for ($m=1; $m<=12; $m++): ?>
      <option value="<?= $m ?>" <?= $m==$month?'selected':'' ?>><?= $m ?>月</option>
      <?php endfor; ?>
    </select>
    <button type="submit" class="btn btn-sm">表示</button>
  </form>
  <button class="btn btn-sm" onclick="window.print()"><i class="ti ti-download"></i>印刷</button>
</div>

<div class="page-content">
  <div class="stat-grid stat-grid-4">
    <div class="stat-card"><div class="stat-label">今月の新規顧客</div><div class="stat-val" style="color:#185FA5">+<?= $newCustomers ?></div></div>
    <div class="stat-card"><div class="stat-label">完了タスク</div><div class="stat-val" style="color:#065f46"><?= $doneTasks ?></div></div>
    <div class="stat-card"><div class="stat-label">メール送信数</div><div class="stat-val"><?= $sentMails ?></div></div>
    <div class="stat-card"><div class="stat-label">TODO完了率</div><div class="stat-val" style="color:#5b21b6"><?= $todoRate ?>%</div></div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
    <!-- 月次新規顧客棒グラフ -->
    <div class="card">
      <div class="card-title"><i class="ti ti-chart-bar" style="color:#185FA5;font-size:13px"></i>新規顧客数（過去6ヶ月）</div>
      <?php $maxV = max(array_column($monthlyNew, 'count')) ?: 1; ?>
      <div class="bar-chart">
        <?php foreach ($monthlyNew as $mn): ?>
        <div style="display:flex;flex-direction:column;align-items:center;flex:1">
          <div class="bar-val"><?= $mn['count'] ?></div>
          <div class="bar <?= $mn['current'] ? 'bar-current' : '' ?>" style="height:<?= round($mn['count']/$maxV*100) ?>%"></div>
          <div class="bar-label" style="<?= $mn['current'] ? 'color:#185FA5;font-weight:500' : '' ?>"><?= $mn['label'] ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- 顧客ステータス内訳 -->
    <div class="card">
      <div class="card-title"><i class="ti ti-chart-pie" style="color:#065f46;font-size:13px"></i>顧客ステータス内訳</div>
      <?php
      $statusInfo = [
        'contracted'  => ['契約中', '#10b981', '#065f46'],
        'negotiating' => ['商談中', '#3b82f6', '#185FA5'],
        'prospect'    => ['見込み', '#f59e0b', '#92400e'],
        'pending'     => ['保留',   '#9ca3af', '#374151'],
        'lost'        => ['失注',   '#ef4444', '#991b1b'],
      ];
      foreach ($statusInfo as $key => [$label, $color, $textColor]):
        $cnt = $statusDist[$key] ?? 0;
        $pct = round($cnt / $totalCust * 100);
      ?>
      <div style="margin-bottom:9px">
        <div style="display:flex;justify-content:space-between;font-size:11px;margin-bottom:2px">
          <span><?= $label ?></span>
          <span style="font-weight:500;color:<?= $textColor ?>"><?= $cnt ?>社 (<?= $pct ?>%)</span>
        </div>
        <div class="pbar"><div class="pfill" style="width:<?= $pct ?>%;background:<?= $color ?>"></div></div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- 社員別タスク -->
    <div class="card" style="margin-bottom:0">
      <div class="card-title"><i class="ti ti-trophy" style="color:#92400e;font-size:13px"></i>社員別タスク完了数</div>
      <table>
        <thead><tr><th>社員</th><th>完了</th><th>進行中</th><th>遅延</th><th>完了率</th></tr></thead>
        <tbody>
          <?php foreach ($empTasks as $et): ?>
          <?php
          $name = $empNames[$et['assigned_to']] ?? 'ID:'.$et['assigned_to'];
          $rate = $et['total'] ? round($et['done_cnt']/$et['total']*100) : 0;
          $initial = mb_substr($name, 0, 1);
          ?>
          <tr>
            <td><div class="flex items-center gap-4"><div class="avatar avatar-sm av-blue"><?= h($initial) ?></div><?= h($name) ?></div></td>
            <td><?= $et['done_cnt'] ?></td>
            <td><?= $et['prog_cnt'] ?></td>
            <td style="<?= $et['overdue_cnt'] > 0 ? 'color:#dc2626;font-weight:500' : '' ?>"><?= $et['overdue_cnt'] ?></td>
            <td><span style="color:<?= $rate>=70?'#065f46':($rate>=40?'#92400e':'#991b1b') ?>;font-weight:500"><?= $rate ?>%</span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Gmail活動 -->
    <div class="card" style="margin-bottom:0">
      <div class="card-title"><i class="ti ti-mail" style="color:#EA4335;font-size:13px"></i>Gmail活動（今月）</div>
      <div style="display:flex;flex-direction:column;gap:7px">
        <div style="display:flex;justify-content:space-between;padding:7px 10px;background:#f8f8f5;border-radius:6px;font-size:12px"><span>送信メール数</span><span style="font-weight:500"><?= $sentMails ?>件</span></div>
        <div style="display:flex;justify-content:space-between;padding:7px 10px;background:#f8f8f5;border-radius:6px;font-size:12px"><span>受信メール数（登録顧客）</span><span style="font-weight:500"><?= $recvMails ?>件</span></div>
        <div style="display:flex;justify-content:space-between;padding:7px 10px;background:#fff7ed;border-radius:6px;font-size:12px;border-left:3px solid #f97316"><span>未登録アドレスからの受信</span><span style="font-weight:500;color:#c2410c"><?= $unknownMails ?>件</span></div>
        <div style="display:flex;justify-content:space-between;padding:7px 10px;background:#d1fae5;border-radius:6px;font-size:12px;border-left:3px solid #10b981"><span>新規顧客登録につながった件数</span><span style="font-weight:500;color:#065f46"><?= $regFromUnknown ?>件</span></div>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/layout_end.php'; ?>
