<?php
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: /crm/customers'); exit; }

$pageTitle = '顧客詳細';
$pageId    = 'customer_detail';
$activeNav = 'customers';

require __DIR__ . '/layout.php';

$stmt = DB::crm()->prepare('SELECT * FROM customers WHERE id = ? AND is_deleted = 0');
$stmt->execute([$id]);
$customer = $stmt->fetch();
if (!$customer) { echo '<p style="padding:20px">顧客が見つかりません</p>'; require __DIR__ . '/layout_end.php'; exit; }

if (!Auth::isManager() && $customer['assigned_to'] != $user['id']) {
    echo '<p style="padding:20px;color:red">このページへのアクセス権限がありません</p>';
    require __DIR__ . '/layout_end.php'; exit;
}

$pageTitle = h($customer['company_name']);

$statusMap = [
    'prospect'    => ['見込み',  'badge-amber'],
    'negotiating' => ['商談中',  'badge-blue'],
    'contracted'  => ['契約中',  'badge-green'],
    'pending'     => ['保留',    'badge-gray'],
    'lost'        => ['失注',    'badge-red'],
];
[$statusLabel, $statusClass] = $statusMap[$customer['status']] ?? [$customer['status'], 'badge-gray'];

$employees = DB::staff()->query("SELECT id, CONCAT(last_name,' ',first_name) AS name FROM employees WHERE is_active=1")->fetchAll(PDO::FETCH_KEY_PAIR);
$assignedName = $employees[$customer['assigned_to']] ?? 'ID:'.$customer['assigned_to'];
?>

<div class="topbar">
  <a href="<?= APP_URL ?>/customers" class="btn btn-sm"><i class="ti ti-arrow-left"></i>一覧へ</a>
  <span class="topbar-title" style="margin-left:8px"><?= h($customer['company_name']) ?></span>
  <span class="badge <?= $statusClass ?>"><?= $statusLabel ?></span>
  <button class="btn btn-sm" onclick="Modal.open('modal-edit-customer')"><i class="ti ti-edit"></i>編集</button>
  <?php if (Auth::isAdmin()): ?>
  <button class="btn btn-sm" style="color:#dc2626;border-color:#fca5a5" onclick="deleteCustomer(<?= $id ?>)"><i class="ti ti-trash"></i>削除</button>
  <?php endif; ?>
</div>

<div class="page-content" style="display:flex;gap:12px;height:calc(100% - 48px);overflow:hidden">

  <div style="width:210px;flex-shrink:0;overflow-y:auto">
    <div class="card">
      <div style="text-align:center;padding:8px 0 12px">
        <div class="avatar avatar-lg av-teal" style="margin:0 auto 8px"><?= h(mb_substr($customer['company_name'], 0, 1)) ?></div>
        <div style="font-size:14px;font-weight:500"><?= h($customer['company_name']) ?></div>
        <div style="font-size:11px;color:#888;margin-top:2px"><?= h($customer['industry']) ?></div>
      </div>
      <div class="divider"></div>
      <div class="detail-row"><div class="detail-label">担当者</div><div class="detail-val"><?= h($customer['contact_name']) ?></div></div>
      <div class="detail-row"><div class="detail-label">役職</div><div class="detail-val"><?= h($customer['contact_title']) ?></div></div>
      <div class="detail-row"><div class="detail-label">電話</div><div class="detail-val"><?= h($customer['phone']) ?></div></div>
      <div class="detail-row"><div class="detail-label">メール</div><div class="detail-val" style="color:#185FA5;font-size:10px;word-break:break-all"><?= h($customer['email']) ?></div></div>
      <div class="detail-row"><div class="detail-label">担当社員</div><div class="detail-val"><?= h($assignedName) ?></div></div>
      <div class="detail-row"><div class="detail-label">契約額</div><div class="detail-val" style="font-weight:500">¥<?= number_format($customer['contract_amount']) ?>/年</div></div>
      <?php if ($customer['contract_end']): ?>
      <div class="detail-row"><div class="detail-label">次回更新</div>
        <div class="detail-val" style="color:<?= $customer['contract_end'] < date('Y-m-d', strtotime('+30 days')) ? '#dc2626' : '#333' ?>;font-weight:500">
          <?= fmtDate($customer['contract_end']) ?>
        </div>
      </div>
      <?php endif; ?>
      <?php if ($customer['website']): ?>
      <div class="detail-row" style="border:none"><div class="detail-label">WEB</div>
        <div class="detail-val"><a href="<?= h($customer['website']) ?>" target="_blank" style="font-size:10px;color:#185FA5">外部リンク</a></div>
      </div>
      <?php endif; ?>
    </div>

    <div class="card" id="related-tasks-card">
      <div class="card-title"><i class="ti ti-checklist" style="color:#185FA5;font-size:13px"></i>関連タスク</div>
      <div style="font-size:11px;color:#aaa">読み込み中...</div>
    </div>

    <?php if ($customer['notes']): ?>
    <div class="card" style="margin-bottom:0">
      <div class="card-title"><i class="ti ti-notes" style="color:#888;font-size:13px"></i>メモ</div>
      <div style="font-size:11px;color:#555;line-height:1.6;white-space:pre-wrap"><?= h($customer['notes']) ?></div>
    </div>
    <?php endif; ?>
  </div>

  <div style="flex:1;display:flex;flex-direction:column;overflow:hidden">
    <div class="tab-bar">
      <div class="tab-item active" id="tab-mail" onclick="switchTab('mail')"><i class="ti ti-mail"></i> メール履歴</div>
      <div class="tab-item" id="tab-hist" onclick="switchTab('hist')"><i class="ti ti-timeline"></i> 連絡履歴</div>
      <div class="tab-item" id="tab-events" onclick="switchTab('events')"><i class="ti ti-calendar"></i> 予定</div>
      <div class="tab-item" id="tab-deals" onclick="switchTab('deals')"><i class="ti ti-briefcase"></i> 商談</div>
      <div style="margin-left:auto;align-self:center;display:flex;gap:6px">
        <button class="btn btn-primary btn-sm" onclick="Modal.open('modal-send-mail')"><i class="ti ti-send"></i>メール送信</button>
        <button class="btn btn-sm" onclick="Modal.open('modal-add-history')"><i class="ti ti-plus"></i>連絡記録</button>
      </div>
    </div>

    <div id="panel-mail" style="flex:1;overflow-y:auto;padding:10px 0">
      <div id="mail-thread" style="display:flex;flex-direction:column;gap:2px"></div>
    </div>
    <div id="panel-hist" style="flex:1;overflow-y:auto;padding:10px 0;display:none">
      <div id="contact-history-list"></div>
    </div>
    <div id="panel-deals" style="flex:1;overflow-y:auto;padding:10px 0;display:none">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <span style="font-size:13px;font-weight:500">商談一覧</span>
        <button class="btn btn-sm btn-primary" onclick="openNewDeal()"><i class="ti ti-plus"></i> 新規商談</button>
      </div>
      <div id="deals-list">読み込み中...</div>
    </div>
    <div id="panel-events" style="flex:1;overflow-y:auto;padding:10px 0;display:none">
      <div id="event-list"></div>
    </div>
  </div>
</div>

<!-- メール送信モーダル -->
<div id="modal-send-mail" class="modal-overlay hidden">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title"><i class="ti ti-send" style="color:#185FA5"></i> メール送信</span>
      <i class="ti ti-x modal-close" onclick="Modal.close('modal-send-mail')"></i>
    </div>
    <div class="modal-body">
      <form onsubmit="sendMail(event)">
        <input type="hidden" name="customer_id" value="<?= $id ?>">
        <div class="form-group"><label class="form-label">宛先</label><input type="email" name="to" value="<?= h($customer['email']) ?>" required></div>
        <div class="form-group"><label class="form-label">件名</label><input type="text" name="subject" required placeholder="件名を入力"></div>
        <div class="form-group"><label class="form-label">本文</label><textarea name="body" rows="6" required placeholder="本文を入力..."></textarea></div>
        <div class="modal-footer" style="padding:0;border:none;margin-top:4px">
          <button type="button" class="btn" onclick="Modal.close('modal-send-mail')">キャンセル</button>
          <button type="submit" class="btn btn-primary"><i class="ti ti-send"></i>送信</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- 連絡記録モーダル -->
<div id="modal-add-history" class="modal-overlay hidden">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title"><i class="ti ti-plus"></i> 連絡履歴を記録</span>
      <i class="ti ti-x modal-close" onclick="Modal.close('modal-add-history')"></i>
    </div>
    <div class="modal-body">
      <form onsubmit="addHistory(event)">
        <input type="hidden" name="customer_id" value="<?= $id ?>">
        <div class="form-group"><label class="form-label">種別</label>
          <select name="type">
            <option value="email">メール</option>
            <option value="phone">電話</option>
            <option value="visit">訪問</option>
            <option value="memo">メモ</option>
            <option value="other">その他</option>
          </select>
        </div>
        <div class="form-group"><label class="form-label">タイトル</label><input type="text" name="title" placeholder="件名・タイトル" required></div>
        <div class="form-group"><label class="form-label">内容</label><textarea name="content" rows="4" required placeholder="内容を入力..."></textarea></div>
        <div class="form-group"><label class="form-label">対応日時</label><input type="datetime-local" name="contacted_at" value="<?= date('Y-m-d\TH:i') ?>"></div>
        <div class="modal-footer" style="padding:0;border:none;margin-top:4px">
          <button type="button" class="btn" onclick="Modal.close('modal-add-history')">キャンセル</button>
          <button type="submit" class="btn btn-success"><i class="ti ti-check"></i>記録する</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- 編集モーダル -->
<div id="modal-edit-customer" class="modal-overlay hidden">
  <div class="modal" style="width:620px">
    <div class="modal-header">
      <span class="modal-title"><i class="ti ti-edit" style="color:#185FA5"></i> 顧客情報を編集</span>
      <i class="ti ti-x modal-close" onclick="Modal.close('modal-edit-customer')"></i>
    </div>
    <div class="modal-body">
      <form id="form-edit-customer" onsubmit="submitEditCustomer(event)">
        <input type="hidden" name="id" value="<?= $id ?>">

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <div>
            <div class="form-section-title"><i class="ti ti-building"></i> 会社情報</div>
            <div class="form-group"><label class="form-label">会社名</label><input type="text" name="company_name" value="<?= h($customer['company_name']) ?>" required></div>
            <div class="form-group"><label class="form-label">電話番号</label><input type="text" name="phone" value="<?= h($customer['phone']) ?>"></div>
            <div class="form-group"><label class="form-label">住所</label><input type="text" name="address" value="<?= h($customer['address']) ?>"></div>
            <div class="form-group"><label class="form-label">ウェブサイト</label><input type="text" name="website" value="<?= h($customer['website']) ?>"></div>
          </div>
          <div>
            <div class="form-section-title"><i class="ti ti-user"></i> 顧客担当者情報</div>
            <div class="form-group"><label class="form-label">担当者名</label><input type="text" name="contact_name" value="<?= h($customer['contact_name']) ?>"></div>
            <div class="form-group"><label class="form-label">役職</label><input type="text" name="contact_title" value="<?= h($customer['contact_title']) ?>"></div>
            <div class="form-group"><label class="form-label">メール</label><input type="email" name="email" value="<?= h($customer['email']) ?>"></div>
            <div class="form-group"><label class="form-label">備考</label><textarea name="notes" rows="2"><?= h($customer['notes']) ?></textarea></div>
          </div>
        </div>

        <div style="margin-top:12px;padding:12px;background:#f8f9fa;border-radius:8px;border:1px solid rgba(0,0,0,0.07)">
          <div class="form-section-title" style="margin-bottom:10px"><i class="ti ti-building-store"></i> 社内管理情報</div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div class="form-group"><label class="form-label">ステータス</label>
              <select name="status">
                <option value="prospect" <?= $customer['status']==='prospect'?'selected':'' ?>>見込み</option>
                <option value="negotiating" <?= $customer['status']==='negotiating'?'selected':'' ?>>商談中</option>
                <option value="contracted" <?= $customer['status']==='contracted'?'selected':'' ?>>契約中</option>
                <option value="pending" <?= $customer['status']==='pending'?'selected':'' ?>>保留</option>
                <option value="lost" <?= $customer['status']==='lost'?'selected':'' ?>>失注</option>
              </select>
            </div>
            <div class="form-group"><label class="form-label">担当社員</label>
              <select name="assigned_to">
                <?php foreach ($employees as $empId => $empName): ?>
                <option value="<?= $empId ?>" <?= $customer['assigned_to'] == $empId ? 'selected' : '' ?>><?= h($empName) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>

        <div class="modal-footer" style="padding:0;border:none;margin-top:12px">
          <button type="button" class="btn" onclick="Modal.close('modal-edit-customer')">キャンセル</button>
          <button type="submit" class="btn btn-success"><i class="ti ti-check"></i>保存する</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php $extraJs = <<<'JS'

async function loadDetail() {
  const data = await API.get('/crm/api/customers.php?action=detail&id=' + CUSTOMER_ID);
  if (!data.ok) return Toast.error(data.error);
  renderRelatedTasks(data.tasks);
  renderMailThread(data.emails);
  renderHistories(data.histories);
  renderEvents(data.events);
}

function renderRelatedTasks(tasks) {
  const el = document.getElementById('related-tasks-card');
  if (!el) return;
  const statusMap = { todo:'badge-gray', in_progress:'badge-blue', review:'badge-amber', done:'badge-green' };
  const statusLabel = { todo:'未着手', in_progress:'進行中', review:'確認待ち', done:'完了' };
  if (!tasks.length) {
    el.innerHTML = '<div class="card-title"><i class="ti ti-checklist" style="color:#185FA5;font-size:13px"></i>関連タスク</div><div style="font-size:11px;color:#aaa">タスクはありません</div>';
    return;
  }
  el.innerHTML = '<div class="card-title"><i class="ti ti-checklist" style="color:#185FA5;font-size:13px"></i>関連タスク</div>' +
    tasks.map(t => `<div style="display:flex;align-items:center;gap:5px;margin-bottom:5px;font-size:11px">
      <span class="badge ${statusMap[t.status]}">${statusLabel[t.status]}</span>
      ${h(t.title)}
    </div>`).join('');
}

function renderMailThread(emails) {
  const el = document.getElementById('mail-thread');
  if (!emails.length) { el.innerHTML = '<div style="text-align:center;color:#aaa;padding:20px;font-size:11px">メール履歴はありません</div>'; return; }
  el.innerHTML = emails.map(m => `
    <div style="padding:10px 0;border-bottom:1px solid #f0f0f0">
      <div style="display:flex;justify-content:space-between;margin-bottom:3px">
        <div style="font-size:11px;font-weight:500">${h(m.subject)}</div>
        <div style="font-size:10px;color:#aaa">${fmtDatetime(m.sent_at)}</div>
      </div>
      <div style="font-size:10px;color:${m.direction==='inbound'?'#888':'#185FA5'};margin-bottom:4px">
        ${m.direction==='inbound'?'↓受信':'↑送信'}: ${h(m.from_address)}
      </div>
    </div>
  `).join('');
}

function renderHistories(histories) {
  const el = document.getElementById('contact-history-list');
  const icons = { email:'ti-mail', phone:'ti-phone', visit:'ti-map-pin', memo:'ti-notes', other:'ti-dots' };
  const colors = { email:'#dbeafe', phone:'#d1fae5', visit:'#fef3c7', memo:'#f3f4f6', other:'#f3f4f6' };
  if (!histories.length) { el.innerHTML = '<div style="text-align:center;color:#aaa;padding:20px;font-size:11px">連絡履歴はありません</div>'; return; }
  el.innerHTML = histories.map(h2 => `
    <div class="tl-item">
      <div class="tl-icon" style="background:${colors[h2.type]||'#f3f4f6'}">
        <i class="ti ${icons[h2.type]||'ti-dots'}" style="font-size:12px"></i>
      </div>
      <div class="tl-content">
        <div class="tl-title">${h(h2.title)}</div>
        <div class="tl-meta">${fmtDatetime(h2.contacted_at)}</div>
        ${h2.content ? `<div style="font-size:11px;color:#555;margin-top:3px;line-height:1.5">${h(h2.content)}</div>` : ''}
      </div>
    </div>
  `).join('');
}

function renderEvents(events) {
  const el = document.getElementById('event-list');
  if (!events.length) { el.innerHTML = '<div style="text-align:center;color:#aaa;padding:20px;font-size:11px">予定はありません</div>'; return; }
  el.innerHTML = events.map(ev => `
    <div style="padding:9px;background:#eff6ff;border-radius:7px;border-left:3px solid #185FA5;margin-bottom:8px">
      <div style="font-size:12px;font-weight:500">${h(ev.title)}</div>
      <div style="font-size:10px;color:#888;margin-top:3px">${fmtDatetime(ev.start_datetime)} 〜 ${fmtDatetime(ev.end_datetime)}</div>
    </div>
  `).join('');
}

async function loadDeals() {
  const res = await API.get(`/crm/api/deals.php?action=list&status=all&customer_id=${CUSTOMER_ID}&limit=100`);
  const list = document.getElementById('deals-list');
  if (!res.ok || !res.deals.length) {
    list.innerHTML = '<div style="color:#aaa;font-size:13px;padding:1rem 0">商談がありません</div>';
    return;
  }
  const statusLabel = {open:'進行中',won:'受注',lost:'失注'};
  const statusColor = {open:'#3b82f6',won:'#10b981',lost:'#ef4444'};
  list.innerHTML = res.deals.map(d => `
    <div style="padding:12px 0;border-bottom:1px solid rgba(0,0,0,0.06);cursor:pointer" onclick="location.href='/crm/deals'">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">
        <span style="font-size:13px;font-weight:500">${d.title}</span>
        <span style="font-size:11px;color:${statusColor[d.status]};font-weight:500">${statusLabel[d.status]||d.status}</span>
      </div>
      <div style="display:flex;gap:12px;font-size:12px;color:#888">
        <span>${d.stage}</span>
        <span>¥${parseInt(d.amount||0).toLocaleString()}</span>
        <span>確度 ${d.probability}%</span>
        ${d.expected_close ? '<span>締結 ' + d.expected_close + '</span>' : ''}
      </div>
    </div>`).join('');
}

function openNewDeal() {
  location.href = `/crm/deals`;
}

function switchTab(tab) {
  ['mail','hist','events','deals'].forEach(t => {
    const el = document.getElementById('panel-'+t);
    const tabEl = document.getElementById('tab-'+t);
    if (tabEl) tabEl.classList.toggle('active', t===tab);
    if (el) el.style.display = 'none';
  });
  const panel = document.getElementById('panel-'+tab);
  if (panel) { panel.style.display = 'flex'; panel.style.flexDirection = 'column'; }
  if (tab === 'deals') loadDeals();
}

async function sendMail(e) {
  e.preventDefault();
  Loading.show();
  const res = await API.post('/crm/api/gmail_api.php?action=send', new FormData(e.target));
  Loading.hide();
  if (res.ok) { Toast.success(res.message); Modal.close('modal-send-mail'); loadDetail(); }
  else Toast.error(res.error);
}

async function addHistory(e) {
  e.preventDefault();
  Loading.show();
  const res = await API.post('/crm/api/contact_history.php?action=create', new FormData(e.target));
  Loading.hide();
  if (res.ok) { Toast.success(res.message); Modal.close('modal-add-history'); loadDetail(); }
  else Toast.error(res.error);
}

async function submitEditCustomer(e) {
  e.preventDefault();
  Loading.show();
  const fd = new FormData(e.target);
  const res = await API.post('/crm/api/customers.php?action=update', fd);
  Loading.hide();
  if (res.ok) {
    Toast.success(res.message);
    Modal.close('modal-edit-customer');
    location.reload();
  } else {
    Toast.error(res.error);
  }
}

async function deleteCustomer(id) {
  Modal.confirm('この顧客を削除しますか？この操作は取り消せません。', async () => {
    const res = await API.post('/crm/api/customers.php?action=delete', { id });
    if (res.ok) { Toast.success(res.message); setTimeout(() => location.href=APP_URL+'/customers', 1000); }
    else Toast.error(res.error);
  });
}

document.addEventListener('DOMContentLoaded', loadDetail);
JS;
?>
<script>
const CUSTOMER_ID = <?= (int)$id ?>;
<?= $extraJs ?>
</script>

<?php $extraJs = ''; require __DIR__ . '/layout_end.php'; ?>
