<?php
// ============================================================
//  pages/unknown_notices.php — 未登録通知（管理者のみ）
// ============================================================
$requiredRole = 'general';
$pageTitle = '未登録アドレス通知';
$pageId    = 'unknown_notices';
$activeNav = 'unknown';
require __DIR__ . '/layout.php';
?>
<div class="topbar">
  <span class="topbar-title" style="display:flex;align-items:center;gap:6px">
    <i class="ti ti-alert-triangle" style="color:#f97316;font-size:16px"></i>未登録アドレス通知
    <span style="font-size:10px;color:#888;font-weight:400">管理者のみ表示</span>
  </span>
  <span class="badge badge-orange" id="pending-count"></span>
</div>

<div class="page-content">
  <div class="alert alert-warning" style="margin-bottom:12px">
    <i class="ti ti-info-circle"></i>
    <span>顧客DBに未登録のメールアドレスから受信した場合に表示されます。「顧客登録」すると次回から受信メールがCRMに表示されます。</span>
  </div>

  <div class="filter-bar">
    <div class="chip active" onclick="loadUnknown('pending',this)">未対応</div>
    <div class="chip" onclick="loadUnknown('registered',this)">登録済み</div>
    <div class="chip" onclick="loadUnknown('ignored',this)">無視済み</div>
  </div>

  <div id="unknown-list"></div>
</div>

<?php $extraJs = <<<JS
let currentStatus = 'pending';

async function loadUnknown(status = 'pending', chipEl = null) {
  currentStatus = status;
  if (chipEl) {
    document.querySelectorAll('.chip').forEach(c => c.classList.remove('active'));
    chipEl.classList.add('active');
  }
  const data = await API.get('/crm/api/gmail_api.php?action=unknown_list&status=' + status);
  if (!data.ok) return Toast.error(data.error);
  document.getElementById('pending-count').textContent = data.data.length + '件';
  renderUnknown(data.data, status);
}

function renderUnknown(items, status) {
  const el = document.getElementById('unknown-list');
  if (!items.length) {
    el.innerHTML = '<div style="text-align:center;color:#aaa;padding:40px;font-size:12px">該当する通知はありません</div>';
    return;
  }
  el.innerHTML = items.map(item => `
    <div class="unk-item">
      <div class="unk-hd">
        <div style="width:34px;height:34px;border-radius:50%;background:#ffedd5;display:flex;align-items:center;justify-content:center;flex-shrink:0">
          <i class="ti ti-user-question" style="font-size:16px;color:#f97316"></i>
        </div>
        <div style="flex:1">
          <div style="display:flex;align-items:center;gap:7px;margin-bottom:3px">
            <span style="font-size:12px;font-weight:500">未登録の送信者</span>
            \${item.status === 'pending' ? '<span class="badge badge-orange">未対応</span>' : item.status === 'registered' ? '<span class="badge badge-green">登録済み</span>' : '<span class="badge badge-gray">無視済み</span>'}
            <span style="font-size:10px;color:#aaa;margin-left:auto">\${fmtDatetime(item.received_at)}</span>
          </div>
          <div style="font-size:11px;color:#555;display:flex;flex-direction:column;gap:2px">
            <div><span style="color:#888;width:45px;display:inline-block">差出人</span><span style="color:#c2410c;font-weight:500">\${h(item.from_address)}</span></div>
            <div><span style="color:#888;width:45px;display:inline-block">件名</span>\${h(item.subject)}</div>
          </div>
        </div>
      </div>
      \${status === 'pending' ? `
      <div class="unk-bd">
        \${item.body_preview ? `<div class="unk-preview">\${h(item.body_preview)}</div>` : ''}
        <div style="display:flex;gap:7px;align-items:center">
          <a href="/crm/customers?prefill_email=\${encodeURIComponent(item.from_address)}" class="btn btn-primary btn-sm">
            <i class="ti ti-user-plus" style="font-size:11px"></i>顧客登録する
          </a>
          <button class="btn btn-sm" style="color:#888" onclick="ignoreUnknown(\${item.id})">
            <i class="ti ti-eye-off" style="font-size:11px"></i>無視する
          </button>
          <button class="btn btn-sm" style="color:#dc2626;border-color:#fca5a5" onclick="deleteUnknown(\${item.id})">
            <i class="ti ti-trash" style="font-size:11px"></i>削除する
          </button>
        </div>
      </div>` : ''}
    </div>
  `).join('');
}

async function deleteUnknown(id) {
  Modal.confirm('この通知を削除しますか？', async () => {
    const res = await API.post('/crm/api/gmail_api.php?action=unknown_delete', { id });
    if (res.ok) { Toast.success(res.message); loadUnknown(currentStatus); }
    else Toast.error(res.error);
  });
}

async function ignoreUnknown(id) {
  Modal.confirm('このアドレスからの通知を今後表示しないようにしますか？', async () => {
    const res = await API.post('/crm/api/gmail_api.php?action=unknown_ignore', { id });
    if (res.ok) { Toast.success(res.message); loadUnknown('pending'); }
    else Toast.error(res.error);
  });
}

document.addEventListener('DOMContentLoaded', () => loadUnknown('pending'));
JS; ?>
<?php require __DIR__ . '/layout_end.php'; ?>