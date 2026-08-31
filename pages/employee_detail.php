<?php
// ============================================================
//  pages/employee_detail.php — 社員詳細・プロフィール
// ============================================================
require_once dirname(__DIR__) . '/config/config.php';
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . APP_URL . '/employees'); exit; }

$pageTitle = '社員詳細';
$pageId    = 'employee_detail';
$activeNav = 'employees';
require __DIR__ . '/layout.php';

// 権限チェック：自分以外は管理者・マネージャーのみ
if (!Auth::isManager() && $id !== $user['id']) {
    echo '<div style="padding:30px;color:red">このページへのアクセス権限がありません</div>';
    require __DIR__ . '/layout_end.php'; exit;
}

$stmt = DB::staff()->prepare('
    SELECT e.*, d.name AS dept_name,
           g.gmail_address, g.linked_at AS gmail_linked_at,
           a.last_login_at, a.last_login_ip, a.login_failure_count
    FROM employees e
    JOIN departments d ON d.id = e.department_id
    LEFT JOIN gmail_tokens g ON g.employee_id = e.id AND g.is_active = 1
    JOIN auth a ON a.employee_id = e.id
    WHERE e.id = ?
');
$stmt->execute([$id]);
$emp = $stmt->fetch();

if (!$emp) {
    echo '<div style="padding:30px;color:#aaa">社員が見つかりません</div>';
    require __DIR__ . '/layout_end.php'; exit;
}

$pageTitle = h($emp['last_name'] . ' ' . $emp['first_name']);

// 担当タスク
$tasks = DB::crm()->prepare("
    SELECT t.id, t.title, t.status, t.priority, t.due_date,
           c.company_name AS customer_name
    FROM tasks t
    LEFT JOIN customers c ON c.id = t.customer_id
    WHERE t.assigned_to = ? AND t.is_deleted = 0
    ORDER BY FIELD(t.status,'in_progress','review','todo','done'), t.due_date ASC
    LIMIT 10
");
$tasks->execute([$id]);
$taskRows = $tasks->fetchAll();

// 統計 — 担当顧客数を正しく取得
$custStmt = DB::crm()->prepare('SELECT COUNT(*) FROM customers WHERE assigned_to = ? AND is_deleted = 0');
$custStmt->execute([$id]);
$stats = [
    'customers'   => (int)$custStmt->fetchColumn(),
    'in_progress' => 0,
    'done_month'  => 0,
    'overdue'     => 0,
];

$taskStatsStmt = DB::crm()->prepare("
    SELECT status, COUNT(*) AS cnt,
           SUM(CASE WHEN due_date < CURDATE() AND status != 'done' THEN 1 ELSE 0 END) AS ov
    FROM tasks
    WHERE assigned_to = ? AND is_deleted = 0
    GROUP BY status
");
$taskStatsStmt->execute([$id]);
$taskStatsRows = $taskStatsStmt->fetchAll();
foreach ($taskStatsRows as $ts) {
    if ($ts['status'] === 'in_progress') $stats['in_progress'] = (int)$ts['cnt'];
    if ($ts['status'] === 'done')        $stats['done_month']  = (int)$ts['cnt'];
    $stats['overdue'] += (int)$ts['ov'];
}

$roleMap  = ['admin'=>['管理者','badge-red'], 'executive'=>['幹部職','badge-purple'], 'manager'=>['マネージャー','badge-amber'], 'general'=>['一般','badge-blue']];
[$roleLabel, $roleClass] = $roleMap[$emp['role']] ?? [$emp['role'], 'badge-gray'];

$departments = DB::staff()->query('SELECT id, name FROM departments ORDER BY sort_order')->fetchAll();
?>

<div class="topbar">
  <a href="<?= APP_URL ?>/employees" class="btn btn-sm"><i class="ti ti-arrow-left"></i>一覧へ</a>
  <span class="topbar-title" style="margin-left:8px"><?= h($emp['last_name'].' '.$emp['first_name']) ?> — プロフィール</span>
  <?php if (Auth::isAdmin() || $id === $user['id']): ?>
  <button class="btn btn-sm" onclick="Modal.open('modal-edit-emp')"><i class="ti ti-edit"></i>編集</button>
  <?php endif; ?>
  <?php if (Auth::isAdmin() && $id !== $user['id']): ?>
  <button class="btn btn-sm" style="color:#dc2626;border-color:#fca5a5" onclick="Modal.open('modal-reset-pw')"><i class="ti ti-key"></i>PW変更</button>
  <?php endif; ?>
</div>

<div class="page-content" style="display:flex;gap:12px">

  <!-- 左カラム -->
  <div style="width:210px;flex-shrink:0">
    <div class="card" style="text-align:center">
      <div class="avatar avatar-xl av-blue" style="margin:0 auto 10px"><?= h(mb_substr($emp['last_name'],0,1)) ?></div>
      <div style="font-size:15px;font-weight:500"><?= h($emp['last_name'].' '.$emp['first_name']) ?></div>
      <div style="font-size:11px;color:#888;margin-top:3px"><?= h($emp['dept_name']) ?> · <?= h($emp['position']) ?></div>
      <span class="badge <?= $roleClass ?>" style="margin-top:7px;display:inline-block"><?= $roleLabel ?></span>
      <div class="divider"></div>
      <div class="detail-row"><div class="detail-label">社員番号</div><div class="detail-val text-faint"><?= h($emp['employee_code']) ?></div></div>
      <div class="detail-row"><div class="detail-label">入社日</div><div class="detail-val"><?= fmtDate($emp['joined_at']) ?></div></div>
      <div class="detail-row"><div class="detail-label">社内メール</div><div class="detail-val" style="font-size:10px;color:#185FA5;word-break:break-all"><?= h($emp['email']) ?></div></div>
      <div class="detail-row"><div class="detail-label">Gmail</div>
        <div class="detail-val">
          <?php if ($emp['gmail_address']): ?>
          <span class="badge badge-green" style="font-size:9px"><i class="ti ti-circle-check" style="font-size:10px"></i>連携済み</span>
          <?php else: ?>
          <span class="badge badge-gray" style="font-size:9px">未連携</span>
          <?php endif; ?>
        </div>
      </div>
      <div class="detail-row" style="border:none"><div class="detail-label">最終ログイン</div><div class="detail-val text-faint" style="font-size:10px"><?= fmtDatetime($emp['last_login_at']) ?></div></div>
    </div>

    <!-- ステータス稼働状況 -->
    <div class="card" style="margin-bottom:0">
      <div class="card-title"><i class="ti ti-chart-bar" style="color:#185FA5;font-size:13px"></i>稼働状況</div>
      <div style="display:flex;flex-direction:column;gap:8px">
        <div class="stat-card" style="padding:8px 10px">
          <div class="stat-label">担当顧客数</div>
          <div class="stat-val" style="font-size:18px"><?= $stats['customers'] ?></div>
        </div>
        <div class="stat-card" style="padding:8px 10px">
          <div class="stat-label">進行中タスク</div>
          <div class="stat-val" style="font-size:18px;color:#185FA5"><?= $stats['in_progress'] ?></div>
        </div>
        <div class="stat-card" style="padding:8px 10px">
          <div class="stat-label">完了タスク（累計）</div>
          <div class="stat-val" style="font-size:18px;color:#065f46"><?= $stats['done_month'] ?></div>
        </div>
        <?php if ($stats['overdue'] > 0): ?>
        <div class="stat-card" style="padding:8px 10px;border:1px solid #fca5a5;background:#fff5f5">
          <div class="stat-label" style="color:#991b1b">期限超過タスク</div>
          <div class="stat-val" style="font-size:18px;color:#dc2626"><?= $stats['overdue'] ?></div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- 右カラム -->
  <div style="flex:1;display:flex;flex-direction:column;gap:10px">

    <!-- 担当タスク -->
    <div class="card">
      <div class="card-title"><i class="ti ti-checklist" style="color:#185FA5;font-size:13px"></i>担当中のタスク</div>
      <?php if ($taskRows): ?>
      <table>
        <thead><tr><th>タスク名</th><th>関連顧客</th><th>優先度</th><th>期限</th><th>状態</th></tr></thead>
        <tbody>
          <?php foreach ($taskRows as $t):
            $overdue = $t['due_date'] && $t['due_date'] < date('Y-m-d') && $t['status'] !== 'done';
            $statusMap = ['todo'=>['未着手','badge-gray'], 'in_progress'=>['進行中','badge-blue'], 'review'=>['確認待ち','badge-amber'], 'done'=>['完了','badge-green']];
            [$sl, $sc] = $statusMap[$t['status']] ?? [$t['status'],'badge-gray'];
            $prioMap = ['high'=>['高','badge-red'], 'medium'=>['中','badge-amber'], 'low'=>['低','badge-green']];
            [$pl, $pc] = $prioMap[$t['priority']] ?? [$t['priority'],'badge-gray'];
          ?>
          <tr>
            <td><?= h($t['title']) ?></td>
            <td><?= h($t['customer_name'] ?? '—') ?></td>
            <td><span class="badge <?= $pc ?>"><?= $pl ?></span></td>
            <td style="<?= $overdue ? 'color:#dc2626;font-weight:500' : '' ?>"><?= $t['due_date'] ? fmtDate($t['due_date']) : '—' ?></td>
            <td><span class="badge <?= $sc ?>"><?= $sl ?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php else: ?>
      <div style="text-align:center;color:#aaa;padding:15px;font-size:11px">担当中のタスクはありません</div>
      <?php endif; ?>
    </div>

  </div>
</div>

<!-- 編集モーダル -->
<div id="modal-edit-emp" class="modal-overlay hidden">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title"><i class="ti ti-edit"></i> 社員情報編集</span>
      <i class="ti ti-x modal-close" onclick="Modal.close('modal-edit-emp')"></i>
    </div>
    <div class="modal-body">
      <form onsubmit="submitEditEmp(event)">
        <input type="hidden" name="id" value="<?= $id ?>">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <div class="form-group"><label class="form-label">姓</label><input type="text" name="last_name" value="<?= h($emp['last_name']) ?>" required></div>
          <div class="form-group"><label class="form-label">名</label><input type="text" name="first_name" value="<?= h($emp['first_name']) ?>" required></div>
          <div class="form-group"><label class="form-label">姓（ふりがな）</label><input type="text" name="last_name_kana" value="<?= h($emp['last_name_kana']) ?>"></div>
          <div class="form-group"><label class="form-label">名（ふりがな）</label><input type="text" name="first_name_kana" value="<?= h($emp['first_name_kana']) ?>"></div>
          <div class="form-group"><label class="form-label">役職</label><input type="text" name="position" value="<?= h($emp['position']) ?>"></div>
          <div class="form-group"><label class="form-label">電話番号</label><input type="text" name="phone" value="<?= h($emp['phone']) ?>"></div>
          <?php if (Auth::isAdmin()): ?>
          <div class="form-group"><label class="form-label">部署</label>
            <select name="department_id">
              <?php foreach ($departments as $d): ?>
              <option value="<?= $d['id'] ?>" <?= $d['id']==$emp['department_id']?'selected':'' ?>><?= h($d['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label class="form-label">権限</label>
            <select name="role">
              <option value="general"   <?= $emp['role']==='general'   ?'selected':'' ?>>一般</option>
              <option value="manager"   <?= $emp['role']==='manager'   ?'selected':'' ?>>マネージャー</option>
              <option value="executive" <?= $emp['role']==='executive' ?'selected':'' ?>>幹部職</option>
              <option value="admin"     <?= $emp['role']==='admin'     ?'selected':'' ?>>管理者</option>
            </select>
          </div>
          <?php endif; ?>
        </div>
        <div class="modal-footer" style="padding:0;border:none;margin-top:8px">
          <button type="button" class="btn" onclick="Modal.close('modal-edit-emp')">キャンセル</button>
          <button type="submit" class="btn btn-success"><i class="ti ti-check"></i>保存する</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- パスワードリセットモーダル（管理者のみ） -->
<?php if (Auth::isAdmin() && $id !== $user['id']): ?>
<div id="modal-reset-pw" class="modal-overlay hidden">
  <div class="modal" style="width:380px">
    <div class="modal-header">
      <span class="modal-title"><i class="ti ti-key"></i> パスワード変更</span>
      <i class="ti ti-x modal-close" onclick="Modal.close('modal-reset-pw')"></i>
    </div>
    <div class="modal-body">
      <form onsubmit="submitResetPw(event)">
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="form-group">
          <label class="form-label">新しいパスワード<span class="form-required">*</span></label>
          <input type="password" name="new_password" required placeholder="8文字以上">
        </div>
        <div class="modal-footer" style="padding:0;border:none;margin-top:8px">
          <button type="button" class="btn" onclick="Modal.close('modal-reset-pw')">キャンセル</button>
          <button type="submit" class="btn btn-danger"><i class="ti ti-key"></i>変更する</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php $extraJs = <<<JS
async function submitEditEmp(e) {
  e.preventDefault();
  Loading.show();
  const res = await API.post('/crm/api/employees.php?action=update', new FormData(e.target));
  Loading.hide();
  if (res.ok) { Toast.success(res.message); Modal.close('modal-edit-emp'); setTimeout(()=>location.reload(), 800); }
  else Toast.error(res.error);
}

async function submitResetPw(e) {
  e.preventDefault();
  if (!confirm('パスワードをリセットします。よろしいですか？')) return;
  Loading.show();
  const res = await API.post('/crm/api/employees.php?action=reset_password', new FormData(e.target));
  Loading.hide();
  res.ok ? (Toast.success(res.message), Modal.close('modal-reset-pw')) : Toast.error(res.error);
}
JS; ?>
<?php require __DIR__ . '/layout_end.php'; ?>
