<?php
$pageTitle = '社内共有TODO';
$pageId    = 'todos';
$activeNav = 'todos';
require __DIR__ . '/layout.php';
$employees = DB::staff()->query("SELECT id, CONCAT(last_name,' ',first_name) AS name FROM employees WHERE is_active=1")->fetchAll(PDO::FETCH_KEY_PAIR);
?>
<div class="topbar">
  <span class="topbar-title" style="display:flex;align-items:center;gap:6px">
    <i class="ti ti-list-check" style="color:#7c3aed;font-size:15px"></i>社内共有TODO
    <span style="font-size:10px;color:#888;font-weight:400">全社員が閲覧・編集できます</span>
  </span>
  <button class="btn btn-purple" onclick="Modal.open('modal-create-todo')"><i class="ti ti-plus"></i>TODO追加</button>
</div>

<div class="page-content">
  <!-- フィルター -->
  <div class="filter-bar">
    <div class="chip active" id="chip-all" onclick="filterTodos('all',this)">すべて</div>
    <div class="chip" id="chip-open" onclick="filterTodos('open',this)">未完了</div>
    <div class="chip" id="chip-done" onclick="filterTodos('done',this)">完了</div>
    <div style="width:1px;height:20px;background:#eee;margin:0 2px"></div>
    <div class="chip" id="chip-high" onclick="filterPriority('high',this)">🔴 高</div>
    <div class="chip" id="chip-medium" onclick="filterPriority('medium',this)">🟡 中</div>
    <div class="chip" id="chip-low" onclick="filterPriority('low',this)">🟢 低</div>
    <div style="width:1px;height:20px;background:#eee;margin:0 2px"></div>
    <select id="filter-emp" onchange="Todos.load(getParams())" style="font-size:11px;height:28px;padding:2px 7px">
      <option value="">担当者：全員</option>
      <?php foreach ($employees as $eid => $ename): ?>
      <option value="<?= $eid ?>"><?= h($ename) ?></option>
      <?php endforeach; ?>
    </select>
    <input type="text" id="todo-search" placeholder="検索..." style="font-size:11px;height:28px;width:110px;margin-left:auto" oninput="debounce(()=>Todos.load(getParams()),400)">
  </div>

  <div id="todo-list">
    <div style="text-align:center;color:#aaa;padding:30px;font-size:12px">読み込み中...</div>
  </div>
</div>

<!-- TODO作成モーダル -->
<div id="modal-create-todo" class="modal-overlay hidden">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title"><i class="ti ti-plus" style="color:#7c3aed"></i> TODO追加</span>
      <i class="ti ti-x modal-close" onclick="Modal.close('modal-create-todo')"></i>
    </div>
    <div class="modal-body">
      <form id="form-create-todo" onsubmit="submitCreateTodo(event)">
        <div class="form-group"><label class="form-label">タイトル<span class="form-required">*</span></label><input type="text" name="title" required placeholder="TODOのタイトル"></div>
        <div class="form-group"><label class="form-label">詳細説明</label><textarea name="description" rows="3" placeholder="詳細..."></textarea></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <div class="form-group"><label class="form-label">担当者</label>
            <select name="assigned_to">
              <option value="">全員（誰でも対応可）</option>
              <?php foreach ($employees as $eid => $ename): ?>
              <option value="<?= $eid ?>"><?= h($ename) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label class="form-label">優先度</label>
            <select name="priority"><option value="high">高</option><option value="medium" selected>中</option><option value="low">低</option></select>
          </div>
          <div class="form-group"><label class="form-label">期限日</label><input type="date" name="due_date"></div>
        </div>
        <div class="modal-footer" style="padding:0;border:none;margin-top:4px">
          <button type="button" class="btn" onclick="Modal.close('modal-create-todo')">キャンセル</button>
          <button type="submit" class="btn btn-purple"><i class="ti ti-check"></i>追加する</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php $extraJs = <<<JS
let currentStatus = '', currentPriority = '';

function filterTodos(status, el) {
  document.querySelectorAll('[id^="chip-all"],[id^="chip-open"],[id^="chip-done"]').forEach(e => e.classList.remove('active'));
  el.classList.add('active');
  currentStatus = status === 'all' ? '' : status;
  Todos.load(getParams());
}
function filterPriority(p, el) {
  const wasActive = el.classList.contains('active');
  document.querySelectorAll('[id^="chip-high"],[id^="chip-medium"],[id^="chip-low"]').forEach(e => e.classList.remove('active'));
  if (!wasActive) { el.classList.add('active'); currentPriority = p; }
  else currentPriority = '';
  Todos.load(getParams());
}
function getParams() {
  return {
    status:      currentStatus,
    priority:    currentPriority,
    assigned_to: document.getElementById('filter-emp')?.value || '',
    q:           document.getElementById('todo-search')?.value || '',
  };
}

let debTimer;
function debounce(fn, ms) { clearTimeout(debTimer); debTimer = setTimeout(fn, ms); }

async function submitCreateTodo(e) {
  e.preventDefault();
  Loading.show();
  const res = await API.post('/crm/api/todos.php?action=create', new FormData(e.target));
  Loading.hide();
  if (res.ok) { Toast.success(res.message); Modal.close('modal-create-todo'); e.target.reset(); Todos.load(getParams()); }
  else Toast.error(res.error);
}

document.addEventListener('DOMContentLoaded', () => Todos.load());
JS; ?>
<?php require __DIR__ . '/layout_end.php'; ?>
