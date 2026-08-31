<?php
$pageTitle = 'カレンダー';
$pageId    = 'calendar';
$activeNav = 'calendar';
require __DIR__ . '/layout.php';

$year  = (int)($_GET['year']  ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));

// 月の計算
$prevMonth = $month === 1 ? ['year' => $year-1, 'month' => 12] : ['year' => $year, 'month' => $month-1];
$nextMonth = $month === 12 ? ['year' => $year+1, 'month' => 1]  : ['year' => $year, 'month' => $month+1];

// カレンダーイベント（CRM内の予定）
$startDate = sprintf('%04d-%02d-01', $year, $month);
$endDate   = date('Y-m-t', strtotime($startDate));

$events = DB::crm()->prepare('
    SELECT ce.id, ce.title, ce.start_datetime, ce.end_datetime, ce.is_all_day,
           ce.customer_id, ce.task_id, ce.todo_id,
           c.company_name AS customer_name
    FROM calendar_events ce
    LEFT JOIN customers c ON c.id = ce.customer_id
    WHERE ce.is_deleted = 0
      AND DATE(ce.start_datetime) BETWEEN ? AND ?
    ORDER BY ce.start_datetime ASC
');
$events->execute([$startDate, $endDate]);
$eventRows = $events->fetchAll();

// 日付ごとにグループ化
$eventsByDate = [];
foreach ($eventRows as $ev) {
    $day = date('j', strtotime($ev['start_datetime']));
    $eventsByDate[$day][] = $ev;
}

// タスク期限をカレンダーに追加
$taskDeadlines = DB::crm()->prepare("
    SELECT id, title, due_date, status
    FROM tasks
    WHERE YEAR(due_date) = ? AND MONTH(due_date) = ? AND is_deleted = 0 AND status != 'done'
");
$taskDeadlines->execute([$year, $month]);
foreach ($taskDeadlines->fetchAll() as $t) {
    $day = (int)date('j', strtotime($t['due_date']));
    $eventsByDate[$day][] = ['title' => '[タスク] '.$t['title'], 'type' => 'task', 'status' => $t['status']];
}

// TODO期限
$todoDeadlines = DB::crm()->prepare("
    SELECT id, title, due_date FROM todos
    WHERE YEAR(due_date) = ? AND MONTH(due_date) = ? AND is_deleted = 0 AND status = 'open'
");
$todoDeadlines->execute([$year, $month]);
foreach ($todoDeadlines->fetchAll() as $td) {
    $day = (int)date('j', strtotime($td['due_date']));
    $eventsByDate[$day][] = ['title' => '[TODO] '.$td['title'], 'type' => 'todo'];
}

$firstDay = (int)date('w', strtotime($startDate));
$daysInMonth = (int)date('t', strtotime($startDate));
$today = (int)date('j');
$isCurrentMonth = ($year == date('Y') && $month == date('n'));

$employees = DB::staff()->query("SELECT id, CONCAT(last_name,' ',first_name) AS name FROM employees WHERE is_active=1")->fetchAll(PDO::FETCH_KEY_PAIR);
?>
<div class="topbar">
  <a href="?year=<?= $prevMonth['year'] ?>&month=<?= $prevMonth['month'] ?>" class="btn btn-sm" style="padding:3px 8px"><i class="ti ti-chevron-left"></i></a>
  <span class="topbar-title" style="flex:none"><?= $year ?>年 <?= $month ?>月</span>
  <a href="?year=<?= $nextMonth['year'] ?>&month=<?= $nextMonth['month'] ?>" class="btn btn-sm" style="padding:3px 8px"><i class="ti ti-chevron-right"></i></a>
  <span style="flex:1"></span>
  <a href="?year=<?= date('Y') ?>&month=<?= date('n') ?>" class="btn btn-sm">今月</a>
  <div class="google-status"><i class="ti ti-brand-google" style="font-size:12px"></i>Googleカレンダー同期中</div>
  <button class="btn btn-primary btn-sm" onclick="Modal.open('modal-create-event')"><i class="ti ti-calendar-plus"></i>予定追加</button>
</div>

<div class="page-content">
  <div class="cal-header-row">
    <?php foreach (['日','月','火','水','木','金','土'] as $d): ?>
    <div class="cal-header-cell"><?= $d ?></div>
    <?php endforeach; ?>
  </div>

  <div class="cal-grid">
    <?php for ($i = 0; $i < $firstDay; $i++): ?>
    <div class="cal-day" style="background:#fafafa"></div>
    <?php endfor; ?>

    <?php for ($day = 1; $day <= $daysInMonth; $day++): ?>
    <?php $isToday = $isCurrentMonth && $day === $today; ?>
    <div class="cal-day <?= $isToday ? 'today' : '' ?>">
      <div class="cal-day-num"><?= $day ?></div>
      <?php foreach ($eventsByDate[$day] ?? [] as $ev): ?>
      <?php
        $cls = 'ev-b';
        if (($ev['type'] ?? '') === 'task') $cls = 'ev-r';
        elseif (($ev['type'] ?? '') === 'todo') $cls = 'ev-p';
        elseif (strpos($ev['title'], '[TODO]') === 0) $cls = 'ev-p';
      ?>
      <div class="cal-ev <?= $cls ?>" title="<?= h($ev['title']) ?>"><?= h($ev['title']) ?></div>
      <?php endforeach; ?>
    </div>
    <?php endfor; ?>

    <?php $remaining = (7 - (($firstDay + $daysInMonth) % 7)) % 7; ?>
    <?php for ($i = 0; $i < $remaining; $i++): ?>
    <div class="cal-day" style="background:#fafafa"></div>
    <?php endfor; ?>
  </div>

  <div class="cal-legend">
    <div class="cal-legend-item"><div class="cal-legend-dot ev-b"></div>商談・訪問</div>
    <div class="cal-legend-item"><div class="cal-legend-dot ev-g"></div>タスク</div>
    <div class="cal-legend-item"><div class="cal-legend-dot ev-p"></div>社内TODO</div>
    <div class="cal-legend-item"><div class="cal-legend-dot ev-a"></div>締切・期限</div>
    <div class="cal-legend-item"><div class="cal-legend-dot ev-r"></div>緊急</div>
    <div style="margin-left:auto;font-size:10px;color:#0F9D58;display:flex;align-items:center;gap:3px">
      <i class="ti ti-brand-google" style="font-size:12px"></i>Googleカレンダー同期中
    </div>
  </div>
</div>

<!-- 予定追加モーダル -->
<div id="modal-create-event" class="modal-overlay hidden">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title"><i class="ti ti-calendar-plus"></i> 予定追加</span>
      <i class="ti ti-x modal-close" onclick="Modal.close('modal-create-event')"></i>
    </div>
    <div class="modal-body">
      <form onsubmit="createEvent(event)">
        <div class="form-group"><label class="form-label">タイトル<span class="form-required">*</span></label><input type="text" name="title" required placeholder="予定タイトル"></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <div class="form-group"><label class="form-label">開始<span class="form-required">*</span></label><input type="datetime-local" name="start_datetime" required></div>
          <div class="form-group"><label class="form-label">終了<span class="form-required">*</span></label><input type="datetime-local" name="end_datetime" required></div>
        </div>
        <div class="form-group"><label class="form-label">関連顧客</label>
          <select name="customer_id">
            <option value="">選択なし</option>
            <?php $custs = DB::crm()->query('SELECT id, company_name FROM customers WHERE is_deleted=0 ORDER BY company_name')->fetchAll(); ?>
            <?php foreach ($custs as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['company_name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label class="form-label">詳細</label><textarea name="description" rows="3" placeholder="詳細..."></textarea></div>
        <div class="modal-footer" style="padding:0;border:none;margin-top:4px">
          <button type="button" class="btn" onclick="Modal.close('modal-create-event')">キャンセル</button>
          <button type="submit" class="btn btn-primary"><i class="ti ti-check"></i>追加する</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php $extraJs = <<<JS
async function createEvent(e) {
  e.preventDefault();
  Loading.show();
  const res = await API.post('/crm/api/calendar_api.php?action=create', new FormData(e.target));
  Loading.hide();
  if (res.ok) { Toast.success(res.message); Modal.close('modal-create-event'); setTimeout(()=>location.reload(),800); }
  else Toast.error(res.error);
}
JS; ?>
<?php require __DIR__ . '/layout_end.php'; ?>
