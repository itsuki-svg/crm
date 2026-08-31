<?php
$pageTitle = '顧客管理';
$pageId    = 'customers';
$activeNav = 'customers';

require __DIR__ . '/layout.php';

$departments = DB::staff()->query('SELECT id, name FROM departments ORDER BY sort_order')->fetchAll();
$employees   = DB::staff()->query("SELECT id, CONCAT(last_name,' ',first_name) AS name FROM employees WHERE is_active=1 ORDER BY id")->fetchAll();

// JSに渡す社員マップをPHP側で生成
$empMapJs = '';
foreach ($employees as $e) {
    $empMapJs .= 'empMap[' . (int)$e['id'] . '] = ' . json_encode($e['name'], JSON_UNESCAPED_UNICODE) . ';' . "\n";
}
?>

<div class="topbar">
  <span class="topbar-title">顧客管理</span>
  <button class="btn btn-primary" onclick="Modal.open('modal-create-customer')">
    <i class="ti ti-plus"></i>新規顧客登録
  </button>
  <button class="btn" onclick="location.href='/crm/api/customers.php?action=export'">
    <i class="ti ti-download"></i>CSV出力
  </button>
</div>

<div class="page-content">
  <div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap">
    <input type="text" id="search-q" placeholder="顧客名・担当者・メールで検索..." style="flex:1;min-width:200px" oninput="debounceSearch()">
    <select id="filter-status" onchange="loadCustomers()">
      <option value="">全ステータス</option>
      <option value="prospect">見込み</option>
      <option value="negotiating">商談中</option>
      <option value="contracted">契約中</option>
      <option value="pending">保留</option>
      <option value="lost">失注</option>
    </select>
    <?php if (Auth::isManager()): ?>
    <select id="filter-assigned" onchange="loadCustomers()">
      <option value="">全担当者</option>
      <?php foreach ($employees as $e): ?>
      <option value="<?= $e['id'] ?>"><?= h($e['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
  </div>

  <div class="stat-grid stat-grid-4" id="customer-stats" style="margin-bottom:12px"></div>

  <div class="card card-0pad">
    <table>
      <thead>
        <tr>
          <th>会社名</th><th>担当者名</th><th>電話番号</th><th>メール</th>
          <th>担当社員</th><th>ステータス</th><th>メール</th><th>最終連絡</th><th>操作</th>
        </tr>
      </thead>
      <tbody id="customer-tbody">
        <tr><td colspan="9" style="text-align:center;padding:30px;color:#aaa">読み込み中...</td></tr>
      </tbody>
    </table>
  </div>
  <div id="customer-pagination"></div>
</div>

<!-- 新規登録モーダル -->
<div id="modal-create-customer" class="modal-overlay hidden">
  <div class="modal" style="width:620px">
    <div class="modal-header">
      <span class="modal-title"><i class="ti ti-user-plus" style="color:#185FA5"></i> 新規顧客登録</span>
      <i class="ti ti-x modal-close" onclick="Modal.close('modal-create-customer')"></i>
    </div>
    <div class="modal-body">
      <form id="form-create-customer" onsubmit="submitCreateCustomer(event)">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <div>
            <div class="form-section-title"><i class="ti ti-building"></i> 会社情報</div>
            <div class="form-group"><label class="form-label">会社名<span class="form-required">*</span></label><input type="text" name="company_name" required placeholder="株式会社〇〇"></div>
            <div class="form-group"><label class="form-label">業種</label>
              <select name="industry"><option value="">選択してください</option><option>製造業</option><option>IT・通信</option><option>商社・卸売</option><option>小売業</option><option>サービス業</option><option>その他</option></select>
            </div>
            <div class="form-group"><label class="form-label">電話番号</label><input type="text" name="phone" placeholder="03-XXXX-XXXX"></div>
            <div class="form-group"><label class="form-label">住所</label><input type="text" name="address" placeholder="東京都〇〇区..."></div>
            <div class="form-group"><label class="form-label">ウェブサイト</label><input type="text" name="website" placeholder="https://example.com"></div>
          </div>
          <div>
            <div class="form-section-title"><i class="ti ti-user"></i> 担当者情報</div>
            <div class="form-group"><label class="form-label">担当者名<span class="form-required">*</span></label><input type="text" name="contact_name" required placeholder="山田 太郎"></div>
            <div class="form-group"><label class="form-label">役職</label><input type="text" name="contact_title" placeholder="部長・課長など"></div>
            <div class="form-group"><label class="form-label">メールアドレス<span class="form-required">*</span></label><input type="email" name="email" required placeholder="yamada@example.com"><div class="form-hint"><i class="ti ti-mail" style="font-size:11px;color:#185FA5"></i> このアドレスからのメールがCRMに表示されます</div></div>
            <div class="form-group"><label class="form-label">携帯電話</label><input type="text" name="contact_phone" placeholder="090-XXXX-XXXX"></div>
            <div class="form-section-title" style="margin-top:8px"><i class="ti ti-clipboard"></i> CRM情報</div>
            <div class="form-group"><label class="form-label">ステータス</label>
              <select name="status"><option value="prospect">見込み</option><option value="negotiating">商談中</option><option value="contracted">契約中</option><option value="pending">保留</option></select>
            </div>
            <div class="form-group"><label class="form-label">担当社員<span class="form-required">*</span></label>
              <select name="assigned_to" required>
                <?php foreach ($employees as $e): ?>
                <option value="<?= $e['id'] ?>" <?= $e['id'] == $user['id'] ? 'selected' : '' ?>><?= h($e['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group"><label class="form-label">流入経路</label>
              <select name="source"><option value="exhibition">展示会</option><option value="referral">紹介</option><option value="web">WEB問い合わせ</option><option value="cold_call">飛び込み</option><option value="other">その他</option></select>
            </div>
            <div class="form-group"><label class="form-label">備考</label><textarea name="notes" rows="2" placeholder="メモ..."></textarea></div>
          </div>
        </div>
        <div class="modal-footer" style="padding:0;border:none;margin-top:4px">
          <button type="button" class="btn" onclick="Modal.close('modal-create-customer')">キャンセル</button>
          <button type="submit" class="btn btn-success"><i class="ti ti-check"></i>登録する</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// 社員マップ（PHP側で生成）
const empMap = {};
<?= $empMapJs ?>

let searchTimer;
function debounceSearch() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(loadCustomers, 400);
}

async function loadCustomers(page = 1) {
  const params = {
    page,
    q:           document.getElementById('search-q')?.value || '',
    status:      document.getElementById('filter-status')?.value || '',
    assigned_to: document.getElementById('filter-assigned')?.value || '',
  };
  const qs   = new URLSearchParams({ action: 'list', ...params }).toString();
  const data = await API.get('/crm/api/customers.php?' + qs);
  if (!data.ok) return Toast.error(data.error);
  renderCustomers(data.data);
  renderStats(data.stats);
  renderPagDiv(data.pagination);
}

function renderCustomers(rows) {
  const tbody = document.getElementById('customer-tbody');
  if (!rows.length) {
    tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:30px;color:#aaa">顧客データがありません</td></tr>';
    return;
  }
  const statusMap = {
    prospect:    ['見込み',  'badge-amber'],
    negotiating: ['商談中',  'badge-blue'],
    contracted:  ['契約中',  'badge-green'],
    pending:     ['保留',    'badge-gray'],
    lost:        ['失注',    'badge-red'],
  };
  tbody.innerHTML = rows.map(r => {
    const [sl, sc] = statusMap[r.status] || [r.status, 'badge-gray'];
    const initial  = (r.company_name || '?').charAt(0);
    return `<tr>
      <td><div class="flex items-center gap-6">
        <div class="avatar avatar-sm av-teal">${initial}</div>
        <a href="${APP_URL}/customers/${r.id}" class="text-primary">${h(r.company_name)}</a>
      </div></td>
      <td>${h(r.contact_name)}</td>
      <td>${h(r.phone)}</td>
      <td class="font-xs text-primary">${h(r.email)}</td>
      <td>${h(empMap[r.assigned_to] || String(r.assigned_to))}</td>
      <td><span class="badge ${sc}">${sl}</span></td>
      <td><span class="badge badge-blue">${r.email_count}件</span></td>
      <td class="text-faint font-xs">${fmtDate(r.updated_at,'M/d')}</td>
      <td><a href="${APP_URL}/customers/${r.id}" class="btn btn-sm">詳細</a></td>
    </tr>`;
  }).join('');
}

function renderStats(stats) {
  if (!stats) return;
  const el = document.getElementById('customer-stats');
  if (!el) return;
  const items = [
    ['契約中', stats.contracted || 0, 'color:#065f46'],
    ['商談中', stats.negotiating || 0, 'color:#185FA5'],
    ['見込み', stats.prospect || 0, 'color:#92400e'],
    ['保留',   stats.pending || 0,   'color:#888'],
  ];
  el.innerHTML = items.map(([label, val, style]) =>
    `<div class="stat-card"><div class="stat-label">${label}</div><div class="stat-val" style="${style}">${val}</div></div>`
  ).join('');
}

function renderPagDiv(pg) {
  const el = document.getElementById('customer-pagination');
  if (!el || !pg || pg.total_pages <= 1) { if(el) el.innerHTML=''; return; }
  let html = '<div class="pagination">';
  html += `<button class="page-btn" onclick="loadCustomers(${pg.current-1})" ${!pg.has_prev?'disabled':''}>‹</button>`;
  for (let i=1; i<=pg.total_pages; i++) html += `<button class="page-btn ${i===pg.current?'active':''}" onclick="loadCustomers(${i})">${i}</button>`;
  html += `<button class="page-btn" onclick="loadCustomers(${pg.current+1})" ${!pg.has_next?'disabled':''}>›</button></div>`;
  el.innerHTML = html;
}

async function submitCreateCustomer(e) {
  e.preventDefault();
  Loading.show();
  const fd = new FormData(e.target);
  const res = await API.post('/crm/api/customers.php?action=create', fd);
  Loading.hide();
  if (res.ok) {
    Toast.success(res.message);
    Modal.close('modal-create-customer');
    e.target.reset();
    loadCustomers();
  } else {
    Toast.error(res.error);
  }
}

document.addEventListener('DOMContentLoaded', () => {
  loadCustomers();
  // 未登録通知からの遷移時にメールアドレスを自動入力
  const params = new URLSearchParams(location.search);
  const prefillEmail = params.get('prefill_email');
  if (prefillEmail) {
    Modal.open('modal-create-customer');
    setTimeout(() => {
      const emailInput = document.querySelector('#modal-create-customer input[name="email"]');
      if (emailInput) {
        emailInput.value = prefillEmail;
        emailInput.style.background = '#fffbeb';
        emailInput.style.borderColor = '#f97316';
      }
    }, 100);
  }
});
</script>

<?php require __DIR__ . '/layout_end.php'; ?>