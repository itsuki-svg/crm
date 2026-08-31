<?php
// ============================================================
//  pages/settings.php — システム設定（管理者のみ）
// ============================================================
$requiredRole = 'admin';
$pageTitle = '設定';
$pageId    = 'settings';
$activeNav = 'settings';
require __DIR__ . '/layout.php';

// 設定値取得
$settings = DB::staff()->query('SELECT setting_key, value FROM system_settings')->fetchAll(PDO::FETCH_KEY_PAIR);
?>
<div class="topbar">
  <span class="topbar-title">設定</span>
  <button class="btn btn-success" onclick="saveSettings()"><i class="ti ti-check"></i>保存する</button>
</div>

<div class="page-content">
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
    <div>
      <div class="form-section-title"><i class="ti ti-building"></i> 会社基本設定</div>
      <div class="form-group"><label class="form-label">会社名</label><input type="text" id="s-company_name" value="<?= h($settings['company_name'] ?? '') ?>" placeholder="株式会社〇〇"></div>
      <div class="form-group"><label class="form-label">システム名</label><input type="text" id="s-system_name" value="<?= h($settings['system_name'] ?? '社内CRM') ?>"></div>

      <div class="form-section-title"><i class="ti ti-bell"></i> 通知設定</div>
      <div class="set-row">
        <div><div class="set-label">タスク期限リマインダー（前日）</div><div class="set-desc">期限1日前にGmailで担当者へ通知</div></div>
        <div class="toggle <?= ($settings['task_reminder_enabled'] ?? '1') === '1' ? 'on' : '' ?>" id="t-task_reminder_enabled" onclick="toggleSetting(this)"></div>
      </div>
      <div class="set-row">
        <div><div class="set-label">タスク完了時に上長へ通知</div></div>
        <div class="toggle <?= ($settings['task_done_notify_enabled'] ?? '1') === '1' ? 'on' : '' ?>" id="t-task_done_notify_enabled" onclick="toggleSetting(this)"></div>
      </div>
      <div class="set-row">
        <div><div class="set-label">未登録メール通知（管理者）</div><div class="set-desc">未登録アドレスから届いたとき管理者へ通知</div></div>
        <div class="toggle <?= ($settings['unknown_mail_notify_enabled'] ?? '1') === '1' ? 'on' : '' ?>" id="t-unknown_mail_notify_enabled" onclick="toggleSetting(this)"></div>
      </div>
      <div class="set-row" style="border:none">
        <div><div class="set-label">重複通知の抑制（1日1回）</div><div class="set-desc">同じアドレスからの通知を1日1回のみに制限</div></div>
        <div class="toggle <?= ($settings['duplicate_suppress_enabled'] ?? '1') === '1' ? 'on' : '' ?>" id="t-duplicate_suppress_enabled" onclick="toggleSetting(this)"></div>
      </div>
    </div>

    <div>
      <div class="form-section-title"><i class="ti ti-lock"></i> セキュリティ・権限</div>
      <div class="set-row">
        <div><div class="set-label">ログイン失敗上限回数</div></div>
        <select id="s-login_max_failures" style="font-size:11px;width:auto">
          <?php foreach ([3,5,10] as $v): ?><option value="<?= $v ?>" <?= ($settings['login_max_failures']??'5')==$v?'selected':'' ?>><?= $v ?>回</option><?php endforeach; ?>
        </select>
      </div>
      <div class="set-row">
        <div><div class="set-label">ロック時間</div></div>
        <select id="s-login_lock_minutes" style="font-size:11px;width:auto">
          <?php foreach ([15,30,60] as $v): ?><option value="<?= $v ?>" <?= ($settings['login_lock_minutes']??'30')==$v?'selected':'' ?>><?= $v ?>分</option><?php endforeach; ?>
        </select>
      </div>
      <div class="set-row" style="border:none">
        <div><div class="set-label">セッション有効期間</div></div>
        <select id="s-session_lifetime_days" style="font-size:11px;width:auto">
          <?php foreach ([1,7,30] as $v): ?><option value="<?= $v ?>" <?= ($settings['session_lifetime_days']??'7')==$v?'selected':'' ?>><?= $v ?>日</option><?php endforeach; ?>
        </select>
      </div>

      <div class="form-section-title"><i class="ti ti-palette"></i> 表示設定</div>
      <div class="set-row">
        <div><div class="set-label">ダッシュボードの警告バナー表示</div></div>
        <div class="toggle on" id="t-show_alert_banner" onclick="toggleSetting(this)"></div>
      </div>
      <div class="set-row" style="border:none">
        <div><div class="set-label">1ページの表示件数</div></div>
        <select id="s-records_per_page" style="font-size:11px;width:auto">
          <?php foreach ([20,50,100] as $v): ?><option value="<?= $v ?>" <?= ($settings['records_per_page']??'20')==$v?'selected':'' ?>><?= $v ?>件</option><?php endforeach; ?>
        </select>
      </div>
    </div>
  </div>
</div>

<?php $extraJs = <<<JS
function toggleSetting(el) { el.classList.toggle('on'); }

async function saveSettings() {
  const data = {};
  document.querySelectorAll('[id^="s-"]').forEach(el => {
    data[el.id.replace('s-', '')] = el.value;
  });
  document.querySelectorAll('[id^="t-"]').forEach(el => {
    data[el.id.replace('t-', '')] = el.classList.contains('on') ? '1' : '0';
  });
  Loading.show();
  const res = await API.post('/crm/api/settings.php?action=save', data);
  Loading.hide();
  res.ok ? Toast.success(res.message) : Toast.error(res.error);
}
JS; ?>
<?php require __DIR__ . '/layout_end.php'; ?>
