<?php
$pageTitle = '社員管理';
$pageId    = 'employees';
$activeNav = 'employees';
require __DIR__ . '/layout.php';
$departments = DB::staff()->query('SELECT id, name FROM departments ORDER BY sort_order')->fetchAll();
?>
<div class="topbar">
  <span class="topbar-title">社員管理</span>
  <?php if (Auth::isAdmin()): ?>
  <button class="btn btn-primary" onclick="Modal.open('modal-create-emp')"><i class="ti ti-plus"></i>社員追加</button>
  <?php endif; ?>
</div>

<div class="page-content">
  <div style="display:flex;gap:8px;margin-bottom:12px">
    <select id="filter-dept" onchange="loadEmployees()" style="font-size:11px">
      <option value="">全部署</option>
      <?php foreach ($departments as $d): ?><option value="<?= $d['id'] ?>"><?= h($d['name']) ?></option><?php endforeach; ?>
    </select>
    <select id="filter-role" onchange="loadEmployees()" style="font-size:11px">
      <option value="">全権限</option>
      <option value="admin">管理者</option>
      <option value="manager">マネージャー</option>
      <option value="general">一般</option>
    </select>
  </div>

  <div class="card card-0pad">
    <table>
      <thead><tr>
        <th>氏名</th><th>社員番号</th><th>部署</th><th>役職</th>
        <th>担当顧客</th><th>タスク</th><th>Gmail連携</th><th>権限</th><th>操作</th>
      </tr></thead>
      <tbody id="emp-tbody">
        <tr><td colspan="9" style="text-align:center;padding:30px;color:#aaa">読み込み中...</td></tr>
      </tbody>
    </table>
  </div>
</div>

<!-- 社員作成モーダル -->
<div id="modal-create-emp" class="modal-overlay hidden">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title"><i class="ti ti-user-plus"></i> 社員追加</span>
      <i class="ti ti-x modal-close" onclick="Modal.close('modal-create-emp')"></i>
    </div>
    <div class="modal-body">
      <form id="form-create-emp" onsubmit="submitCreateEmp(event)">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <div class="form-group"><label class="form-label">社員番号<span class="form-required">*</span></label><input type="text" name="employee_code" required placeholder="EMP-001"></div>
          <div class="form-group"><label class="form-label">部署<span class="form-required">*</span></label>
            <select name="department_id" required>
              <?php foreach ($departments as $d): ?><option value="<?= $d['id'] ?>"><?= h($d['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label class="form-label">姓<span class="form-required">*</span></label><input type="text" name="last_name" required placeholder="田中"></div>
          <div class="form-group"><label class="form-label">名<span class="form-required">*</span></label><input type="text" name="first_name" required placeholder="一郎"></div>
          <div class="form-group"><label class="form-label">役職</label><input type="text" name="position" placeholder="課長・担当など"></div>
          <div class="form-group"><label class="form-label">権限</label>
            <select name="role"><option value="general">一般</option><option value="manager">マネージャー</option><option value="executive">幹部職</option><option value="admin">管理者</option></select>
          </div>
          <div class="form-group"><label class="form-label">メールアドレス<span class="form-required">*</span></label><input type="email" name="email" required placeholder="tanaka@company.com"></div>
          <div class="form-group"><label class="form-label">入社日</label><input type="date" name="joined_at" value="<?= date('Y-m-d') ?>"></div>
          <div class="form-group"><label class="form-label">初期パスワード<span class="form-required">*</span></label><input type="password" name="password" required placeholder="8文字以上"></div>
        </div>
        <div class="modal-footer" style="padding:0;border:none;margin-top:4px">
          <button type="button" class="btn" onclick="Modal.close('modal-create-emp')">キャンセル</button>
          <button type="submit" class="btn btn-success"><i class="ti ti-check"></i>登録する</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php $extraJs = <<<JS
const roleMap = { admin:['管理者','badge-red'], executive:['幹部職','badge-purple'], manager:['マネージャー','badge-amber'], general:['一般','badge-blue'] };

async function loadEmployees() {
  const params = {
    department_id: document.getElementById('filter-dept')?.value || '',
    role:          document.getElementById('filter-role')?.value || '',
  };
  const qs = new URLSearchParams({ action:'list', ...params }).toString();
  const data = await API.get('/crm/api/employees.php?' + qs);
  if (!data.ok) return Toast.error(data.error);
  const tbody = document.getElementById('emp-tbody');
  if (!data.data.length) { tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:30px;color:#aaa">社員がいません</td></tr>'; return; }
  tbody.innerHTML = data.data.map(e => {
    const [rl, rc] = roleMap[e.role] || [e.role, 'badge-gray'];
    const gmailBadge = e.gmail_address
      ? `<span class="badge badge-green" style="font-size:9px"><i class="ti ti-check" style="font-size:10px"></i>連携済</span>`
      : `<span class="badge badge-amber" style="font-size:9px">未連携</span>`;
    return `<tr>
      <td><div class="flex items-center gap-6"><div class="avatar avatar-sm av-blue">\${(e.last_name||'?').charAt(0)}</div>\${h(e.last_name+' '+e.first_name)}</div></td>
      <td class="text-faint font-xs">\${h(e.employee_code)}</td>
      <td>\${h(e.dept_name)}</td>
      <td>\${h(e.position)}</td>
      <td>\${e.customer_count}</td>
      <td>\${e.task_count}</td>
      <td>\${gmailBadge}</td>
      <td><span class="badge \${rc}">\${rl}</span></td>
      <td><a href="\${APP_URL}/employees/\${e.id}" class="btn btn-sm">詳細</a></td>
    </tr>`;
  }).join('');
}

async function submitCreateEmp(e) {
  e.preventDefault();
  Loading.show();
  const res = await API.post('/crm/api/employees.php?action=create', new FormData(e.target));
  Loading.hide();
  if (res.ok) { Toast.success(res.message); Modal.close('modal-create-emp'); e.target.reset(); loadEmployees(); }
  else Toast.error(res.error);
}

document.addEventListener('DOMContentLoaded', loadEmployees);
JS; ?>
<?php require __DIR__ . '/layout_end.php'; ?>