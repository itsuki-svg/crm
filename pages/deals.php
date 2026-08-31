<?php
// ============================================================
//  pages/deals.php — 商談管理（パイプライン）
// ============================================================
$requiredRole = 'general';
$pageTitle = '商談管理';
$pageId    = 'deals';
$activeNav = 'deals';
require __DIR__ . '/layout.php';
?>
<div class="topbar">
  <span class="topbar-title">商談管理</span>
  <button class="btn btn-primary" id="btn-create-deal"><i class="ti ti-plus"></i>新規商談</button>
</div>

<div class="page-content">

  <div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;align-items:center">
    <input type="text" id="search-q" placeholder="商談名・会社名で検索" style="width:220px">
    <select id="filter-status">
      <option value="open">進行中</option>
      <option value="won">受注済</option>
      <option value="lost">失注</option>
      <option value="all">すべて</option>
    </select>
    <div style="margin-left:auto;display:flex;gap:6px">
      <button class="btn" id="btn-pipeline" style="background:#111;color:#fff">パイプライン</button>
      <button class="btn" id="btn-list">リスト</button>
    </div>
  </div>

  <!-- パイプラインビュー -->
  <div id="pipeline-view">
    <div id="pipeline-board" style="display:flex;gap:12px;overflow-x:auto;padding-bottom:12px"></div>
  </div>

  <!-- リストビュー -->
  <div id="list-view" style="display:none">
    <table class="data-table">
      <thead><tr>
        <th>商談コード</th><th>商談名</th><th>会社名</th><th>ステージ</th>
        <th>金額</th><th>確度</th><th>担当者</th><th>期待締結日</th><th></th>
      </tr></thead>
      <tbody id="deal-tbody"></tbody>
    </table>
    <div id="pagination" style="margin-top:12px;display:flex;gap:6px;justify-content:center"></div>
  </div>

</div>

<!-- 新規・編集モーダル -->
<div class="modal-overlay" id="deal-modal" style="display:none">
  <div class="modal" style="width:560px">
    <div class="modal-header">
      <span id="modal-title">新規商談</span>
      <button class="modal-close" id="btn-close-modal">×</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="deal-id">
      <div class="form-group"><label class="form-label">商談名 <span style="color:red">*</span></label>
        <input type="text" id="deal-title" placeholder="例：〇〇社 システム導入提案"></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div class="form-group"><label class="form-label">顧客</label>
          <select id="deal-customer"><option value="">-- 選択 --</option></select></div>
        <div class="form-group"><label class="form-label">ステージ</label>
          <select id="deal-stage"></select></div>
        <div class="form-group"><label class="form-label">金額（円）</label>
          <input type="number" id="deal-amount" placeholder="0"></div>
        <div class="form-group"><label class="form-label">確度（%）</label>
          <input type="number" id="deal-probability" min="0" max="100" placeholder="0"></div>
        <div class="form-group"><label class="form-label">担当者</label>
          <select id="deal-assigned"></select></div>
        <div class="form-group"><label class="form-label">期待締結日</label>
          <input type="date" id="deal-close"></div>
      </div>
      <div class="form-group"><label class="form-label">メモ</label>
        <textarea id="deal-desc" rows="3"></textarea></div>
    </div>
    <div class="modal-footer">
      <button class="btn" id="btn-cancel-modal">キャンセル</button>
      <button class="btn btn-primary" id="btn-save-deal" onclick="saveDeal()">保存する</button>
    </div>
  </div>
</div>

<!-- 詳細モーダル -->
<div class="modal-overlay" id="detail-modal" style="display:none">
  <div class="modal" style="width:680px;max-height:85vh;overflow-y:auto">
    <div class="modal-header">
      <span id="detail-title"></span>
      <button class="modal-close" onclick="document.getElementById('detail-modal').style.display='none'">×</button>
    </div>
    <div class="modal-body" id="detail-body"></div>
  </div>
</div>

<?php
$extraJs = <<<'JS'
const STAGES = [];
let pipeline = null;
let currentView = 'pipeline';
let currentPage = 1;
let editingId = null;
let debTimer = null;

async function init() {
  try { await loadStages(); } catch(e) { console.warn('loadStages error', e); }
  try { await loadCustomers(); } catch(e) { console.warn('loadCustomers error', e); }
  try { await loadEmployees(); } catch(e) { console.warn('loadEmployees error', e); }
  loadDeals();
}

async function loadStages() {
  const res = await API.get('/crm/api/pipelines.php?action=get');
  if (res.ok && res.stages) {
    res.stages.forEach(s => STAGES.push(s));
  } else {
    ['リード','アプローチ','ヒアリング','提案','見積','交渉'].forEach(s => STAGES.push(s));
  }
  const sel = document.getElementById('deal-stage');
  STAGES.forEach(s => sel.innerHTML += `<option value="${s}">${s}</option>`);
}

async function loadCustomers() {
  const res = await API.get('/crm/api/customers.php?action=list&limit=200');
  const sel = document.getElementById('deal-customer');
  (res.data || res.customers || []).forEach(c => sel.innerHTML += `<option value="${c.id}">${c.company_name}</option>`);
}

async function loadEmployees() {
  const res = await API.get('/crm/api/employees.php?action=list');
  const sel = document.getElementById('deal-assigned');
  (res.employees || res.data || []).forEach(e => sel.innerHTML += `<option value="${e.id}">${e.last_name}${e.first_name}</option>`);
}

async function loadDeals() {
  const status = document.getElementById('filter-status').value;
  const q = document.getElementById('search-q').value;
  const res = await API.get(`/crm/api/deals.php?action=list&status=${status}&q=${encodeURIComponent(q)}&page=${currentPage}&limit=200`);
  if (!res.ok) return;

  if (currentView === 'pipeline') renderPipeline(res.deals || []);
  else renderList(res.deals || [], res.total);
}

function renderPipeline(deals) {
  const board = document.getElementById('pipeline-board');
  board.innerHTML = '';
  STAGES.forEach(stage => {
    const cards = deals.filter(d => d.stage === stage);
    const total = cards.reduce((s, d) => s + parseInt(d.amount || 0), 0);
    const col = document.createElement('div');
    col.style.cssText = 'min-width:200px;flex:1;background:#f9f9f9;border-radius:10px;padding:10px;border:1px solid rgba(0,0,0,0.07)';
    col.innerHTML = `
      <div style="font-size:12px;font-weight:600;color:#555;margin-bottom:8px;display:flex;justify-content:space-between">
        <span>${stage}</span><span style="color:#999">${cards.length}件</span>
      </div>
      <div style="font-size:11px;color:#aaa;margin-bottom:10px">¥${total.toLocaleString()}</div>
      <div id="col-${stage.replace(/\s/g,'_')}">
        ${cards.map(d => dealCard(d)).join('')}
      </div>`;
    board.appendChild(col);
  });
}

function dealCard(d) {
  const prob = parseInt(d.probability || 0);
  const color = prob >= 70 ? '#10b981' : prob >= 40 ? '#f59e0b' : '#9ca3af';
  return `<div onclick="openDetail(${d.id})" style="background:#fff;border-radius:8px;padding:10px;margin-bottom:8px;border:1px solid rgba(0,0,0,0.08);cursor:pointer;transition:box-shadow .15s" onmouseover="this.style.boxShadow='0 2px 8px rgba(0,0,0,0.1)'" onmouseout="this.style.boxShadow='none'">
    <div style="font-size:13px;font-weight:500;margin-bottom:4px;line-height:1.4">${escHtml(d.title)}</div>
    ${d.company_name ? `<div style="font-size:11px;color:#888;margin-bottom:6px">${escHtml(d.company_name)}</div>` : ''}
    <div style="display:flex;justify-content:space-between;align-items:center">
      <span style="font-size:12px;font-weight:500">¥${parseInt(d.amount||0).toLocaleString()}</span>
      <span style="font-size:11px;color:${color};font-weight:500">${prob}%</span>
    </div>
    ${d.expected_close ? `<div style="font-size:11px;color:#aaa;margin-top:4px">締結: ${d.expected_close}</div>` : ''}
  </div>`;
}

function renderList(deals, total) {
  const tbody = document.getElementById('deal-tbody');
  tbody.innerHTML = deals.length ? deals.map(d => `
    <tr onclick="openDetail(${d.id})" style="cursor:pointer">
      <td style="font-size:11px;color:#aaa">${escHtml(d.deal_code)}</td>
      <td style="font-weight:500">${escHtml(d.title)}</td>
      <td>${escHtml(d.company_name || '—')}</td>
      <td><span class="badge bg-blue">${escHtml(d.stage)}</span></td>
      <td>¥${parseInt(d.amount||0).toLocaleString()}</td>
      <td>${d.probability}%</td>
      <td>${escHtml(d.assigned_name || '—')}</td>
      <td>${d.expected_close || '—'}</td>
      <td><button class="btn btn-sm" onclick="event.stopPropagation();openEdit(${d.id})">編集</button></td>
    </tr>`).join('') : '<tr><td colspan="9" style="text-align:center;color:#aaa;padding:2rem">商談がありません</td></tr>';
}

async function openDetail(id) {
  const res = await API.get(`/crm/api/deals.php?action=get&id=${id}`);
  if (!res.ok) return;
  const d = res.deal;
  const acts = res.activities || [];
  const typeLabel = {call:'📞 電話',visit:'🤝 訪問',email:'✉️ メール',meeting:'👥 会議',note:'📝 メモ',stage_change:'🔄 ステージ変更'};

  document.getElementById('detail-title').textContent = d.title;
  document.getElementById('detail-body').innerHTML = `
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px">
      <div>
        <div style="font-size:11px;color:#aaa">会社名</div><div style="font-weight:500">${escHtml(d.company_name||'—')}</div>
      </div>
      <div>
        <div style="font-size:11px;color:#aaa">ステージ</div>
        <div style="display:flex;gap:6px;align-items:center;margin-top:4px">
          <select id="detail-stage" style="font-size:12px;width:auto">${STAGES.map(s=>`<option value="${s}" ${d.stage===s?'selected':''}>${s}</option>`).join('')}</select>
          <button class="btn btn-sm btn-success" onclick="changeStage(${d.id},'won')">受注</button>
          <button class="btn btn-sm btn-danger" onclick="changeStage(${d.id},'lost')">失注</button>
        </div>
      </div>
      <div><div style="font-size:11px;color:#aaa">金額</div><div style="font-weight:600;font-size:18px">¥${parseInt(d.amount||0).toLocaleString()}</div></div>
      <div><div style="font-size:11px;color:#aaa">確度</div><div style="font-weight:500">${d.probability}%</div></div>
      <div><div style="font-size:11px;color:#aaa">担当者</div><div>${escHtml(d.assigned_name||'—')}</div></div>
      <div><div style="font-size:11px;color:#aaa">期待締結日</div><div>${d.expected_close||'—'}</div></div>
    </div>
    <div style="border-top:1px solid rgba(0,0,0,0.07);padding-top:16px;margin-bottom:12px">
      <div style="font-size:12px;font-weight:600;margin-bottom:10px">活動ログ</div>
      <div style="display:flex;gap:8px;margin-bottom:10px">
        <select id="act-type" style="font-size:12px;width:auto">
          <option value="call">電話</option><option value="visit">訪問</option>
          <option value="email">メール</option><option value="meeting">会議</option><option value="note">メモ</option>
        </select>
        <input type="text" id="act-title" placeholder="活動内容" style="flex:1;font-size:12px">
        <button class="btn btn-sm btn-primary" onclick="addActivity(${d.id})">追加</button>
      </div>
      ${acts.length ? acts.map(a => `
        <div style="padding:8px 0;border-bottom:1px solid rgba(0,0,0,0.05)">
          <div style="display:flex;justify-content:space-between;font-size:11px;color:#aaa">
            <span>${typeLabel[a.type]||a.type} · ${escHtml(a.employee_name||'')}</span>
            <span>${a.activity_at.substring(0,16)}</span>
          </div>
          <div style="font-size:13px;margin-top:2px">${escHtml(a.title)}</div>
        </div>`).join('') : '<div style="color:#aaa;font-size:13px">活動記録がありません</div>'}
    </div>
    <div style="text-align:right;margin-top:8px">
      <button class="btn btn-sm" onclick="openEditFromDetail(${d.id})">編集</button>
    </div>`;
  document.getElementById('detail-modal').style.display = 'flex';
}

async function addActivity(dealId) {
  const type = document.getElementById('act-type').value;
  const title = document.getElementById('act-title').value.trim();
  if (!title) return Toast.error('活動内容を入力してください');
  await API.post('/crm/api/deals.php?action=activity', {deal_id: dealId, type, title});
  openDetail(dealId);
}

async function changeStage(id, status) {
  const stage = status === 'won' ? '受注' : '失注';
  await API.post('/crm/api/deals.php?action=stage', {id, stage, status});
  document.getElementById('detail-modal').style.display = 'none';
  Toast.success(status === 'won' ? '受注おめでとうございます！🎉' : '失注として記録しました');
  loadDeals();
}

function openCreateModal() {
  editingId = null;
  document.getElementById('modal-title').textContent = '新規商談';
  document.getElementById('deal-id').value = '';
  document.getElementById('deal-title').value = '';
  document.getElementById('deal-amount').value = '';
  document.getElementById('deal-probability').value = '';
  document.getElementById('deal-close').value = '';
  document.getElementById('deal-desc').value = '';
  document.getElementById('deal-modal').style.display = 'flex';
}

async function openEdit(id) {
  const res = await API.get(`/crm/api/deals.php?action=get&id=${id}`);
  if (!res.ok) return;
  const d = res.deal;
  editingId = id;
  document.getElementById('modal-title').textContent = '商談を編集';
  document.getElementById('deal-id').value = d.id;
  document.getElementById('deal-title').value = d.title;
  document.getElementById('deal-customer').value = d.customer_id || '';
  document.getElementById('deal-stage').value = d.stage;
  document.getElementById('deal-amount').value = d.amount;
  document.getElementById('deal-probability').value = d.probability;
  document.getElementById('deal-assigned').value = d.assigned_to || '';
  document.getElementById('deal-close').value = d.expected_close || '';
  document.getElementById('deal-desc').value = d.description || '';
  document.getElementById('deal-modal').style.display = 'flex';
}

function openEditFromDetail(id) {
  document.getElementById('detail-modal').style.display = 'none';
  openEdit(id);
}

async function saveDeal() {
  const title = document.getElementById('deal-title').value.trim();
  if (!title) return Toast.error('商談名を入力してください');
  const data = {
    id: document.getElementById('deal-id').value,
    title,
    customer_id: document.getElementById('deal-customer').value,
    stage: document.getElementById('deal-stage').value,
    amount: document.getElementById('deal-amount').value,
    probability: document.getElementById('deal-probability').value,
    assigned_to: document.getElementById('deal-assigned').value,
    expected_close: document.getElementById('deal-close').value,
    description: document.getElementById('deal-desc').value,
  };
  Loading.show();
  const action = data.id ? 'update' : 'create';
  const res = await API.post(`/crm/api/deals.php?action=${action}`, data);
  Loading.hide();
  if (res.ok) {
    closeModal();
    Toast.success(data.id ? '更新しました' : '商談を作成しました');
    loadDeals();
  } else {
    Toast.error(res.error || 'エラーが発生しました');
  }
}

function closeModal() {
  document.getElementById('deal-modal').style.display = 'none';
}

function setView(v) {
  currentView = v;
  const pv = document.getElementById('pipeline-view');
  const lv = document.getElementById('list-view');
  if (pv) pv.style.display = v === 'pipeline' ? '' : 'none';
  if (lv) lv.style.display = v === 'list' ? '' : 'none';
  const bp = document.getElementById('btn-pipeline');
  const bl = document.getElementById('btn-list');
  if (bp) bp.style.cssText = v === 'pipeline' ? 'background:#111;color:#fff' : '';
  if (bl) bl.style.cssText = v === 'list' ? 'background:#111;color:#fff' : '';
  loadDeals();
}

function debounceLoad() {
  clearTimeout(debTimer);
  debTimer = setTimeout(loadDeals, 300);
}

function escHtml(s) {
  return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function bindEvents() {
  init();
  document.getElementById('btn-pipeline').addEventListener('click', () => setView('pipeline'));
  document.getElementById('filter-status').addEventListener('change', loadDeals);
  document.getElementById('search-q').addEventListener('input', debounceLoad);
  document.getElementById('btn-list').addEventListener('click', () => setView('list'));
  document.getElementById('btn-create-deal').addEventListener('click', openCreateModal);
  document.getElementById('btn-close-modal').addEventListener('click', closeModal);
  document.getElementById('btn-cancel-modal').addEventListener('click', closeModal);
  // btn-save-deal uses inline onclick
}
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', bindEvents);
} else {
  bindEvents();
}
JS;
?>
<?php require __DIR__ . '/layout_end.php';
