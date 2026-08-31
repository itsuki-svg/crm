<?php
// ============================================================
//  pages/leads.php — リード管理（MA）
// ============================================================
$requiredRole = 'general';
$pageTitle = 'リード管理';
$pageId    = 'leads';
$activeNav = 'leads';
require __DIR__ . '/layout.php';
?>
<div class="topbar">
  <span class="topbar-title">リード管理</span>
  <span style="font-size:11px;color:#aaa">ホームページからの問い合わせ一覧</span>
</div>

<div class="page-content">
  <div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;align-items:center">
    <select id="filter-status" onchange="loadLeads()" style="font-size:12px">
      <option value="">すべて</option>
      <option value="new">新規</option>
      <option value="contacted">対応中</option>
      <option value="converted">顧客化済</option>
      <option value="lost">見送り</option>
    </select>
    <div id="lead-stats" style="display:flex;gap:8px;margin-left:auto"></div>
  </div>

  <div class="card card-0pad">
    <table>
      <thead>
        <tr>
          <th>受信日時</th>
          <th>お名前</th>
          <th>メール</th>
          <th>相談内容</th>
          <th>ステータス</th>
          <th>操作</th>
        </tr>
      </thead>
      <tbody id="leads-tbody">
        <tr><td colspan="6" style="text-align:center;padding:30px;color:#aaa">読み込み中...</td></tr>
      </tbody>
    </table>
  </div>
</div>

<!-- 詳細・対応モーダル -->
<div id="modal-lead" class="modal-overlay hidden">
  <div class="modal" style="width:560px">
    <div class="modal-header">
      <span class="modal-title" id="lead-modal-title">リード詳細</span>
      <i class="ti ti-x modal-close" onclick="Modal.close('modal-lead')"></i>
    </div>
    <div class="modal-body" id="lead-modal-body"></div>
  </div>
</div>

<!-- 顧客変換モーダル -->
<div id="modal-convert" class="modal-overlay hidden">
  <div class="modal" style="width:420px">
    <div class="modal-header">
      <span class="modal-title"><i class="ti ti-user-plus" style="color:#185FA5"></i> 顧客として登録</span>
      <i class="ti ti-x modal-close" onclick="Modal.close('modal-convert')"></i>
    </div>
    <div class="modal-body">
      <form id="form-convert" onsubmit="submitConvert(event)">
        <input type="hidden" id="convert-lead-id">
        <div class="form-group">
          <label class="form-label">会社名</label>
          <input type="text" id="convert-company" placeholder="株式会社〇〇（個人の場合はお名前）" required>
        </div>
        <p style="font-size:12px;color:#888;margin-top:8px">
          <i class="ti ti-info-circle"></i>
          リードのメール・名前・相談内容が顧客情報に引き継がれます
        </p>
        <div class="modal-footer" style="padding:0;border:none;margin-top:12px">
          <button type="button" class="btn" onclick="Modal.close('modal-convert')">キャンセル</button>
          <button type="submit" class="btn btn-primary"><i class="ti ti-check"></i>顧客登録する</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php $extraJs = <<<'JS'
const STATUS_LABEL = { new:'新規', contacted:'対応中', converted:'顧客化済', lost:'見送り' };
const STATUS_CLASS = { new:'badge-blue', contacted:'badge-amber', converted:'badge-green', lost:'badge-gray' };

async function loadLeads() {
  const status = document.getElementById('filter-status').value;
  const res = await API.get('/crm/api/leads.php?action=list' + (status ? '&status=' + status : ''));
  if (!res.ok) return Toast.error(res.error);

  const leads = res.leads || [];
  const tbody = document.getElementById('leads-tbody');

  if (!leads.length) {
    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:30px;color:#aaa">リードがありません</td></tr>';
    renderStats([]);
    return;
  }

  tbody.innerHTML = leads.map(l => `
    <tr>
      <td style="font-size:11px;color:#aaa;white-space:nowrap">${fmtDatetime(l.created_at)}</td>
      <td style="font-weight:500">${h(l.name)}</td>
      <td style="font-size:12px;color:#185FA5">${h(l.email)}</td>
      <td style="font-size:11px;color:#666;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${h(l.message || '—')}</td>
      <td>
        <select onchange="updateStatus(${l.id}, this.value)" style="font-size:11px;width:auto">
          ${Object.entries(STATUS_LABEL).map(([v,t]) => `<option value="${v}" ${l.status===v?'selected':''}>${t}</option>`).join('')}
        </select>
      </td>
      <td style="display:flex;gap:4px">
        <button class="btn btn-sm" onclick="openDetail(${l.id})">詳細</button>
        ${l.status !== 'converted' ? `<button class="btn btn-sm btn-primary" onclick="openConvert(${l.id}, '${h(l.name)}')">顧客化</button>` : `<a href="/crm/customers/${l.customer_id}" class="btn btn-sm btn-success">顧客へ</a>`}
      </td>
    </tr>
  `).join('');

  renderStats(leads);
}

function renderStats(leads) {
  const el = document.getElementById('lead-stats');
  const counts = { new:0, contacted:0, converted:0, lost:0 };
  leads.forEach(l => { if (counts[l.status] !== undefined) counts[l.status]++; });
  el.innerHTML = Object.entries(counts).map(([s, c]) =>
    `<span class="badge ${STATUS_CLASS[s]}" style="font-size:11px">${STATUS_LABEL[s]} ${c}</span>`
  ).join('');
}

async function updateStatus(id, status) {
  await API.post('/crm/api/leads.php?action=update', { id, status });
  Toast.success('ステータスを更新しました');
  loadLeads();
}

let currentLeadId = null;
function openDetail(id) {
  const row = document.querySelector(`tr:has(button[onclick="openDetail(${id})"])`);
  if (!row) return;
  const cells = row.querySelectorAll('td');
  const name    = cells[1]?.textContent || '';
  const email   = cells[2]?.textContent || '';
  const message = cells[3]?.title || cells[3]?.textContent || '';

  document.getElementById('lead-modal-title').textContent = name + ' のリード';
  document.getElementById('lead-modal-body').innerHTML = `
    <div class="detail-row"><div class="detail-label">お名前</div><div class="detail-val">${h(name)}</div></div>
    <div class="detail-row"><div class="detail-label">メール</div><div class="detail-val" style="color:#185FA5">${h(email)}</div></div>
    <div class="detail-row" style="border:none"><div class="detail-label">相談内容</div></div>
    <div style="background:#f8f9fa;padding:12px;border-radius:8px;font-size:13px;line-height:1.7;white-space:pre-wrap;margin-top:4px">${h(message)}</div>
    <div style="margin-top:16px;display:flex;gap:8px;justify-content:flex-end">
      <a href="mailto:${h(email)}" class="btn btn-primary"><i class="ti ti-mail"></i>メール送信</a>
      <button class="btn" onclick="Modal.close('modal-lead')">閉じる</button>
    </div>
  `;
  Modal.open('modal-lead');
}

function openConvert(id, name) {
  currentLeadId = id;
  document.getElementById('convert-lead-id').value = id;
  document.getElementById('convert-company').value = '';
  document.getElementById('convert-company').placeholder = name + '（個人の場合はお名前）';
  Modal.open('modal-convert');
}

async function submitConvert(e) {
  e.preventDefault();
  const id = document.getElementById('convert-lead-id').value;
  const company = document.getElementById('convert-company').value.trim();
  if (!company) return Toast.error('会社名を入力してください');

  Loading.show();
  const res = await API.post('/crm/api/leads.php?action=convert', { id, company_name: company });
  Loading.hide();

  if (res.ok) {
    Toast.success('顧客として登録しました');
    Modal.close('modal-convert');
    loadLeads();
    setTimeout(() => location.href = '/crm/customers/' + res.customer_id, 1000);
  } else {
    Toast.error(res.error || 'エラーが発生しました');
  }
}

document.addEventListener('DOMContentLoaded', loadLeads);
JS;
?>
<?php require __DIR__ . '/layout_end.php'; ?>
