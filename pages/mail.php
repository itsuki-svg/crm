<?php
$pageTitle = 'Gmail 受信メール';
$pageId    = 'mail';
$activeNav = 'mail';
require __DIR__ . '/layout.php';
?>
<div class="topbar">
  <span class="topbar-title">Gmail 受信メール</span>
  <div class="google-status"><i class="ti ti-circle-check" style="font-size:11px"></i>登録顧客のみ表示</div>
  <button class="btn btn-sm" onclick="syncMail()"><i class="ti ti-refresh"></i>今すぐ同期</button>
  <button class="btn btn-primary" onclick="Modal.open('modal-compose')"><i class="ti ti-send"></i>新規メール</button>
</div>

<div class="page-content" style="display:flex;gap:10px;height:calc(100% - 48px);overflow:hidden">

  <!-- 左: メール一覧 -->
  <div style="width:230px;flex-shrink:0;border:1px solid #eee;border-radius:10px;overflow:hidden;display:flex;flex-direction:column">
    <div style="padding:7px 10px;background:#fafafa;border-bottom:1px solid #eee;display:flex;gap:5px">
      <div class="chip active" id="box-inbox" onclick="switchBox('inbox',this)" style="padding:2px 8px">受信 <span id="inbox-count" style="background:#ef4444;color:#fff;border-radius:8px;padding:1px 5px;font-size:9px"></span></div>
      <div class="chip" id="box-sent" onclick="switchBox('sent',this)" style="padding:2px 8px">送信済み</div>
    </div>
    <div id="mail-list" style="flex:1;overflow-y:auto">
      <div style="text-align:center;color:#aaa;padding:20px;font-size:11px">読み込み中...</div>
    </div>
    <div style="padding:7px 10px;border-top:1px solid #eee;background:#fff7ed;cursor:pointer" onclick="location.href=APP_URL+'/unknown'">
      <div style="font-size:10px;color:#c2410c;display:flex;align-items:center;gap:4px;font-weight:500">
        <i class="ti ti-alert-triangle" style="font-size:12px;color:#f97316"></i>
        未登録アドレス <span id="unknown-count"></span>件
        <span style="margin-left:auto">確認 →</span>
      </div>
    </div>
  </div>

  <!-- 右: メール本文 -->
  <div style="flex:1;border:1px solid #eee;border-radius:10px;overflow:hidden;display:flex;flex-direction:column">
    <div id="mail-detail" style="flex:1;display:flex;flex-direction:column">
      <div style="flex:1;display:flex;align-items:center;justify-content:center;color:#aaa;font-size:12px;flex-direction:column;gap:8px">
        <i class="ti ti-mail" style="font-size:40px;color:#ddd"></i>
        メールを選択してください
      </div>
    </div>
  </div>
</div>

<!-- 新規メール作成モーダル -->
<div id="modal-compose" class="modal-overlay hidden">
  <div class="modal" style="width:560px">
    <div class="modal-header">
      <span class="modal-title"><i class="ti ti-send" style="color:#185FA5"></i> メール作成</span>
      <i class="ti ti-x modal-close" onclick="Modal.close('modal-compose')"></i>
    </div>
    <div class="modal-body">
      <form onsubmit="composeSend(event)">
        <div class="form-group"><label class="form-label">宛先<span class="form-required">*</span></label><input type="email" name="to" required placeholder="to@example.com"></div>
        <div class="form-group"><label class="form-label">件名<span class="form-required">*</span></label><input type="text" name="subject" required placeholder="件名"></div>
        <div class="form-group"><label class="form-label">本文<span class="form-required">*</span></label><textarea name="body" rows="8" required placeholder="本文..."></textarea></div>
        <div class="modal-footer" style="padding:0;border:none;margin-top:4px">
          <button type="button" class="btn" onclick="Modal.close('modal-compose')">キャンセル</button>
          <button type="submit" class="btn btn-primary"><i class="ti ti-send"></i>送信</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php $extraJs = <<<JS
let currentBox = 'inbox';
let currentMailId = null;

async function loadMailList(box = 'inbox') {
  const action = box === 'sent' ? 'inbox&direction=outbound' : 'inbox';
  const data = await API.get('/crm/api/gmail_api.php?action=' + action);
  if (!data.ok) return Toast.error(data.error);
  renderMailList(data.data);
  // 未登録件数
  const unk = await API.get('/crm/api/gmail_api.php?action=unknown_list');
  document.getElementById('unknown-count').textContent = unk.ok ? (unk.data?.length || 0) : 0;
}

function renderMailList(mails) {
  const el = document.getElementById('mail-list');
  if (!mails.length) { el.innerHTML = '<div style="text-align:center;color:#aaa;padding:20px;font-size:11px">メールはありません</div>'; return; }
  el.innerHTML = mails.map(m => `
    <div class="mail-row unread" onclick="openMail(\${m.id})" style="cursor:pointer">
      <div class="avatar avatar-sm av-teal">\${(m.customer_name||'?').charAt(0)}</div>
      <div style="flex:1;min-width:0">
        <div style="font-size:10px;font-weight:600;color:#222">\${h(m.customer_name||m.from_address)}</div>
        <div class="mail-subj">\${h(m.subject)}</div>
        <div class="mail-prev">\${h(m.body_preview||'')}</div>
      </div>
      <div style="display:flex;flex-direction:column;align-items:flex-end;gap:2px">
        <div class="mail-time">\${fmtDate(m.sent_at,'M/d')}</div>
      </div>
    </div>
  `).join('');
}

async function openMail(id) {
  currentMailId = id;
  const data = await API.get('/crm/api/gmail_api.php?action=detail&id=' + id);
  if (!data.ok) return Toast.error(data.error);
  const m = data.mail;
  const el = document.getElementById('mail-detail');
  el.innerHTML = `
    <div style="padding:12px 14px;border-bottom:1px solid #eee;flex-shrink:0">
      <div style="font-size:13px;font-weight:500;margin-bottom:5px">\${h(m.subject)}</div>
      <div style="display:flex;justify-content:space-between;margin-bottom:5px">
        <div style="font-size:10px;color:#888">\${h(m.from_address)} → \${h(m.to_address)}</div>
        <div style="font-size:10px;color:#aaa">\${fmtDatetime(m.sent_at)}</div>
      </div>
      \${m.customer_name ? `<div style="display:flex;gap:5px;align-items:center"><span style="font-size:10px;color:#888">顧客:</span><a href="\${APP_URL}/customers/\${m.customer_id}" class="badge badge-green">🔗 \${h(m.customer_name)}</a><span style="font-size:10px;color:#aaa">自動マッチング済</span></div>` : ''}
    </div>
    <div style="padding:14px;flex:1;overflow:auto;font-size:12px;line-height:1.8;color:#333;white-space:pre-wrap">\${h(m.body_plain||'')}</div>
    <div style="padding:9px 14px;border-top:1px solid #eee;display:flex;gap:6px;flex-shrink:0">
      <button class="btn btn-primary btn-sm" onclick="Modal.open('modal-compose');document.querySelector('#modal-compose input[name=to]').value='\${h(m.from_address)}';document.querySelector('#modal-compose input[name=subject]').value='Re: \${h(m.subject)}'">
        <i class="ti ti-corner-up-left" style="font-size:11px"></i>返信
      </button>
      <button class="btn btn-sm" onclick="createTaskFromMail(\${m.id})"><i class="ti ti-checklist" style="font-size:11px"></i>タスク作成</button>
      <button class="btn btn-sm" onclick="createTodoFromMail(\${m.id})"><i class="ti ti-list-check" style="font-size:11px"></i>TODO作成</button>
    </div>
  `;
}

async function syncMail() {
  Loading.show();
  const res = await API.post('/crm/api/gmail_api.php?action=sync', {});
  Loading.hide();
  if (res.ok) { Toast.success(`同期完了: 新着${res.synced}件`); loadMailList(currentBox); }
  else Toast.error(res.error);
}

function switchBox(box, el) {
  currentBox = box;
  document.querySelectorAll('[id^="box-"]').forEach(e => e.classList.remove('active'));
  el.classList.add('active');
  loadMailList(box);
}

async function composeSend(e) {
  e.preventDefault();
  Loading.show();
  const res = await API.post('/crm/api/gmail_api.php?action=send', new FormData(e.target));
  Loading.hide();
  if (res.ok) { Toast.success(res.message); Modal.close('modal-compose'); e.target.reset(); }
  else Toast.error(res.error);
}

function createTaskFromMail(mailId) { Toast.warning('タスク作成はタスク管理ページから行えます'); }
function createTodoFromMail(mailId) { Toast.warning('TODO作成はTODO管理ページから行えます'); }

document.addEventListener('DOMContentLoaded', () => loadMailList('inbox'));
JS; ?>
<?php require __DIR__ . '/layout_end.php'; ?>
