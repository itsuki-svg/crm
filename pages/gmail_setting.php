<?php
$pageTitle = 'Gmail 設定';
$pageId    = 'gmail_setting';
$activeNav = 'gmail_setting';
require __DIR__ . '/layout.php';

$msg = $_GET['msg'] ?? '';
$msgMap = [
    'linked'      => ['success', 'Gmailアカウントの連携が完了しました！'],
    'link_failed' => ['error',   'Gmail連携に失敗しました。再度お試しください。'],
    'google_error'=> ['error',   'Googleの認証でエラーが発生しました。'],
    'unlinked'    => ['success', 'Gmail連携を解除しました。'],
];
[$alertType, $alertMsg] = $msgMap[$msg] ?? ['', ''];

// 現在のユーザーのGmailトークン
$myToken = DB::staff()->prepare('SELECT gmail_address, linked_at FROM gmail_tokens WHERE employee_id = ? AND is_active = 1');
$myToken->execute([$user['id']]);
$myGmail = $myToken->fetch();

// 全社員の連携状況（管理者のみ）
$empList = [];
if (Auth::isAdmin()) {
    $empList = DB::staff()->query("
        SELECT e.id, CONCAT(e.last_name,' ',e.first_name) AS name, e.email,
               g.gmail_address, g.linked_at
        FROM employees e
        LEFT JOIN gmail_tokens g ON g.employee_id = e.id AND g.is_active = 1
        WHERE e.is_active = 1
        ORDER BY e.id ASC
    ")->fetchAll();
}
?>
<div class="topbar"><span class="topbar-title">Gmail 設定</span></div>

<div class="page-content">
  <?php if ($alertMsg): ?>
  <div class="alert alert-<?= $alertType === 'success' ? 'success' : 'warning' ?>" style="margin-bottom:12px">
    <i class="ti ti-<?= $alertType === 'success' ? 'circle-check' : 'alert-circle' ?>"></i>
    <span><?= h($alertMsg) ?></span>
  </div>
  <?php endif; ?>

  <!-- 自分の連携状況 -->
  <div class="form-section-title"><i class="ti ti-user-check"></i> あなたのGmailアカウント連携</div>
  <div class="card" style="display:flex;align-items:center;gap:12px;margin-bottom:14px">
    <div class="avatar avatar-md av-blue"><?= h(mb_substr($user['name'], 0, 1)) ?></div>
    <div style="flex:1">
      <div style="font-size:13px;font-weight:500"><?= h($user['name']) ?></div>
      <?php if ($myGmail): ?>
      <div style="font-size:11px;color:#065f46;margin-top:2px">
        <i class="ti ti-circle-check" style="font-size:12px"></i>
        <?= h($myGmail['gmail_address']) ?> — 連携済み (<?= fmtDate($myGmail['linked_at']) ?>)
      </div>
      <?php else: ?>
      <div style="font-size:11px;color:#888;margin-top:2px">未連携</div>
      <?php endif; ?>
    </div>
    <?php if ($myGmail): ?>
    <form method="POST" action="/crm/api/gmail_token.php?action=unlink" onsubmit="return confirm('Gmail連携を解除しますか？')">
      <input type="hidden" name="csrf_token" value="<?= h(Auth::csrfToken()) ?>">
      <button type="submit" class="btn btn-sm" style="color:#dc2626;border-color:#fca5a5"><i class="ti ti-unlink"></i>連携解除</button>
    </form>
    <?php else: ?>
    <a href="<?= APP_URL ?>/auth/google_login.php" class="btn btn-primary btn-sm">
      <svg width="13" height="13" viewBox="0 0 48 48" style="margin-right:2px"><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.18 1.48-4.97 2.36-8.16 2.36-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/></svg>
      Googleアカウントで連携
    </a>
    <?php endif; ?>
  </div>

  <?php if (Auth::isAdmin()): ?>
  <!-- 社員一覧 -->
  <div class="form-section-title"><i class="ti ti-users"></i> 社員の連携状況（管理者のみ）</div>
  <div class="card card-0pad" style="margin-bottom:14px">
    <table>
      <thead><tr><th>社員名</th><th>社内メール</th><th>Gmailアカウント</th><th>連携状態</th><th>連携日</th><th>操作</th></tr></thead>
      <tbody>
        <?php foreach ($empList as $e): ?>
        <tr>
          <td><div class="flex items-center gap-6"><div class="avatar avatar-sm av-blue"><?= h(mb_substr($e['name'],0,1)) ?></div><?= h($e['name']) ?></div></td>
          <td class="font-xs"><?= h($e['email']) ?></td>
          <td class="font-xs"><?= $e['gmail_address'] ? h($e['gmail_address']) : '<span style="color:#aaa">未連携</span>' ?></td>
          <td>
            <?php if ($e['gmail_address']): ?>
            <span class="badge badge-green"><i class="ti ti-circle-check" style="font-size:11px"></i> 連携済み</span>
            <?php else: ?>
            <span class="badge badge-gray">未連携</span>
            <?php endif; ?>
          </td>
          <td class="text-faint font-xs"><?= $e['linked_at'] ? fmtDate($e['linked_at']) : '—' ?></td>
          <td>
            <?php if ($e['gmail_address']): ?>
            <form method="POST" action="/crm/api/gmail_token.php?action=admin_unlink" style="display:inline">
              <input type="hidden" name="csrf_token" value="<?= h(Auth::csrfToken()) ?>">
              <input type="hidden" name="employee_id" value="<?= $e['id'] ?>">
              <button type="submit" class="btn btn-sm" style="color:#dc2626;border-color:#fca5a5" onclick="return confirm('連携を解除しますか？')"><i class="ti ti-unlink"></i>解除</button>
            </form>
            <?php else: ?>
            <button class="btn btn-sm btn-primary" style="font-size:10px" onclick="promptLink(<?= $e['id'] ?>, '<?= h($e['name']) ?>')">
              <i class="ti ti-mail"></i>連携を促す
            </button>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- 未登録通知設定 -->
  <div class="form-section-title"><i class="ti ti-bell"></i> 未登録アドレス通知設定（管理者のみ）</div>
  <?php $settings = DB::staff()->query('SELECT setting_key, value FROM system_settings')->fetchAll(PDO::FETCH_KEY_PAIR); ?>
  <div class="card">
    <div class="set-row">
      <div><div class="set-label">未登録アドレスを管理者へ通知する</div><div class="set-desc">未登録アドレスからメールが届いたとき管理者のGmailへ通知</div></div>
      <div class="toggle <?= ($settings['unknown_mail_notify_enabled']??'1')==='1'?'on':'' ?>" onclick="toggleNotify(this,'unknown_mail_notify_enabled')"></div>
    </div>
    <div class="set-row">
      <div><div class="set-label">ダッシュボードにバナーを表示する</div></div>
      <div class="toggle on" onclick="toggleNotify(this,'show_alert_banner')"></div>
    </div>
    <div class="set-row" style="border:none">
      <div><div class="set-label">重複通知の抑制（1日1回）</div><div class="set-desc">同じアドレスからの通知を1日1回のみに制限</div></div>
      <div class="toggle <?= ($settings['duplicate_suppress_enabled']??'1')==='1'?'on':'' ?>" onclick="toggleNotify(this,'duplicate_suppress_enabled')"></div>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php $extraJs = <<<JS
async function toggleNotify(el, key) {
  el.classList.toggle('on');
  const val = el.classList.contains('on') ? '1' : '0';
  await API.post('/crm/api/settings.php?action=save', { [key]: val });
}

async function promptLink(empId, name) {
  if (!confirm(name + 'さんにGmail連携の案内メールを送りますか？')) return;
  const res = await API.post('/crm/api/gmail_token.php?action=prompt', { employee_id: empId });
  res.ok ? Toast.success(res.message) : Toast.error(res.error);
}
JS; ?>
<?php require __DIR__ . '/layout_end.php'; ?>
