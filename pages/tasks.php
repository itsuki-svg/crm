<?php
$pageTitle = 'タスク管理';
$pageId    = 'tasks';
$activeNav = 'tasks';
require __DIR__ . '/layout.php';
$employees = DB::staff()->query("SELECT id, CONCAT(last_name,' ',first_name) AS name FROM employees WHERE is_active=1")->fetchAll(PDO::FETCH_KEY_PAIR);
$customers = DB::crm()->query("SELECT id, company_name FROM customers WHERE is_deleted=0 ORDER BY company_name")->fetchAll(PDO::FETCH_KEY_PAIR);
?>
<div class="topbar">
  <span class="topbar-title">タスク管理</span>
  <?php if (Auth::isManager()): ?>
  <select id="filter-assigned" onchange="Tasks.load({assigned_to:this.value})" style="font-size:11px;height:28px;padding:2px 8px;width:auto;max-width:120px">
    <option value="">全担当者</option>
    <?php foreach ($employees as $id => $name): ?><option value="<?= $id ?>"><?= h($name) ?></option><?php endforeach; ?>
  </select>
  <?php endif; ?>
  <button class="btn btn-primary" onclick="Modal.open('modal-create-task')"><i class="ti ti-plus"></i>新規タスク</button>
</div>

<div class="page-content">
  <div style="margin-bottom:10px;padding:9px 12px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;font-size:11px;display:flex;align-items:center;gap:8px">
    <i class="ti ti-bell" style="color:#92400e;font-size:14px"></i>
    <span><strong>自動通知：</strong>期限1日前にGmailでリマインダー送信 ・ 完了時にマネージャーへ通知</span>
  </div>
  <div class="kanban-wrap" id="kanban-wrap">
    <div class="kanban-col kanban-col-todo"    id="kanban-todo"><div class="kanban-col-title">読み込み中...</div></div>
    <div class="kanban-col kanban-col-in_progress" id="kanban-in_progress"></div>
    <div class="kanban-col kanban-col-review"  id="kanban-review"></div>
    <div class="kanban-col kanban-col-done"    id="kanban-done"></div>
  </div>
</div>

<!-- タスク作成モーダル -->
<div id="modal-create-task" class="modal-overlay hidden">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title"><i class="ti ti-plus" style="color:#185FA5"></i> 新規タスク</span>
      <i class="ti ti-x modal-close" onclick="Modal.close('modal-create-task')"></i>
    </div>
    <div class="modal-body">
      <form id="form-create-task" onsubmit="submitCreateTask(event)">
        <div class="form-group"><label class="form-label">タスク名<span class="form-required">*</span></label><input type="text" name="title" required placeholder="タスク名を入力"></div>
        <div class="form-group"><label class="form-label">詳細説明</label><textarea name="description" rows="3" placeholder="詳細..."></textarea></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <div class="form-group"><label class="form-label">関連顧客</label>
            <select name="customer_id">
              <option value="">顧客なし</option>
              <?php foreach ($customers as $cid => $cname): ?><option value="<?= $cid ?>"><?= h($cname) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label class="form-label">担当者<span class="form-required">*</span></label>
            <select name="assigned_to" required>
              <?php foreach ($employees as $eid => $ename): ?>
              <option value="<?= $eid ?>" <?= $eid == $user['id'] ? 'selected' : '' ?>><?= h($ename) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label class="form-label">優先度</label>
            <select name="priority"><option value="high">高</option><option value="medium" selected>中</option><option value="low">低</option></select>
          </div>
          <div class="form-group"><label class="form-label">期限日</label><input type="date" name="due_date"></div>
        </div>
        <div class="modal-footer" style="padding:0;border:none;margin-top:4px">
          <button type="button" class="btn" onclick="Modal.close('modal-create-task')">キャンセル</button>
          <button type="submit" class="btn btn-success"><i class="ti ti-check"></i>作成する</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- タスク詳細モーダル -->
<div id="task-detail-modal" class="modal-overlay hidden">
  <div class="modal" style="width:620px">
    <div class="modal-header">
      <span class="modal-title" id="task-detail-title">タスク詳細</span>
      <i class="ti ti-x modal-close" onclick="Modal.close('task-detail-modal')"></i>
    </div>
    <div class="modal-body" id="task-detail-body"></div>
  </div>
</div>

<?php $extraJs = <<<JS
document.addEventListener('DOMContentLoaded', () => Tasks.load());

async function submitCreateTask(e) {
  e.preventDefault();
  Loading.show();
  const res = await API.post('/crm/api/tasks.php?action=create', new FormData(e.target));
  Loading.hide();
  if (res.ok) { Toast.success(res.message); Modal.close('modal-create-task'); e.target.reset(); Tasks.load(); }
  else Toast.error(res.error);
}
JS; ?>
<?php require __DIR__ . '/layout_end.php'; ?>
