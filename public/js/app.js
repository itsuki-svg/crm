/* ============================================================
   app.js — 社内CRM メインJavaScript
   ============================================================ */

'use strict';

// ============================================================
//  API ヘルパー
// ============================================================
const API = {
  csrfToken: () => document.querySelector('meta[name="csrf-token"]')?.content ?? '',

  async get(url) {
    const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    return res.json();
  },

  async post(url, data = {}) {
    if (data instanceof FormData) {
      data.append('csrf_token', this.csrfToken());
    } else {
      const fd = new FormData();
      fd.append('csrf_token', this.csrfToken());
      Object.entries(data).forEach(([k, v]) => fd.append(k, v ?? ''));
      data = fd;
    }
    const res = await fetch(url, {
      method: 'POST',
      body: data,
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
    return res.json();
  },
};

// ============================================================
//  トースト通知
// ============================================================
const Toast = {
  container: null,

  init() {
    this.container = document.getElementById('toast-container');
    if (!this.container) {
      this.container = document.createElement('div');
      this.container.id = 'toast-container';
      this.container.className = 'toast-container';
      document.body.appendChild(this.container);
    }
  },

  show(message, type = 'default', duration = 3000) {
    this.init();
    const icons = { success: 'ti-circle-check', error: 'ti-alert-circle', warning: 'ti-alert-triangle', default: 'ti-info-circle' };
    const el = document.createElement('div');
    el.className = `toast ${type}`;
    el.innerHTML = `<i class="ti ${icons[type] || icons.default}" style="font-size:15px;flex-shrink:0"></i>${message}`;
    this.container.appendChild(el);
    setTimeout(() => { el.style.opacity = '0'; el.style.transition = 'opacity .3s'; setTimeout(() => el.remove(), 300); }, duration);
  },

  success(msg) { this.show(msg, 'success'); },
  error(msg)   { this.show(msg, 'error', 4000); },
  warning(msg) { this.show(msg, 'warning'); },
};

// ============================================================
//  ローディングオーバーレイ
// ============================================================
const Loading = {
  show() { document.getElementById('loading-overlay')?.classList.remove('hidden'); },
  hide() { document.getElementById('loading-overlay')?.classList.add('hidden'); },
};

// ============================================================
//  モーダル
// ============================================================
const Modal = {
  open(id)  { document.getElementById(id)?.classList.remove('hidden'); },
  close(id) { document.getElementById(id)?.classList.add('hidden'); },

  confirm(message, onOk) {
    if (!confirm(message)) return;
    onOk();
  },
};

// ============================================================
//  顧客管理
// ============================================================
const Customers = {
  async load(params = {}) {
    const qs = new URLSearchParams({ action: 'list', ...params }).toString();
    const data = await API.get(`/crm/api/customers.php?${qs}`);
    if (!data.ok) return Toast.error(data.error);
    this.render(data.data);
    this.renderPagination(data.pagination);
  },

  render(rows) {
    const tbody = document.getElementById('customer-tbody');
    if (!tbody) return;
    if (!rows.length) {
      tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:20px;color:#aaa">顧客データがありません</td></tr>';
      return;
    }
    tbody.innerHTML = rows.map(r => `
      <tr>
        <td><div class="flex items-center gap-6">
          <div class="avatar avatar-sm av-teal">${r.company_name.charAt(0)}</div>
          <a href="/crm/customers/${r.id}" class="text-primary">${h(r.company_name)}</a>
        </div></td>
        <td>${h(r.contact_name)}</td>
        <td>${h(r.phone)}</td>
        <td class="font-xs text-primary">${h(r.email)}</td>
        <td>${r.assigned_to}</td>
        <td>${statusBadge('customer', r.status)}</td>
        <td><span class="badge badge-blue">${r.email_count}件</span></td>
        <td class="text-faint font-xs">${fmtDate(r.updated_at)}</td>
        <td><a href="/crm/customers/${r.id}" class="btn btn-sm">詳細</a></td>
      </tr>
    `).join('');
  },

  renderPagination(pg) {
    const el = document.getElementById('customer-pagination');
    if (!el || !pg) return;
    el.innerHTML = renderPagination(pg, page => this.load({ page }));
  },

  async delete(id) {
    Modal.confirm('この顧客を削除しますか？', async () => {
      Loading.show();
      const res = await API.post('/crm/api/customers.php?action=delete', { id });
      Loading.hide();
      res.ok ? (Toast.success(res.message), this.load()) : Toast.error(res.error);
    });
  },
};

// ============================================================
//  タスク管理（カンバン）
// ============================================================
const Tasks = {
  async load(params = {}) {
    const qs = new URLSearchParams({ action: 'list', ...params }).toString();
    const data = await API.get(`/crm/api/tasks.php?${qs}`);
    if (!data.ok) return Toast.error(data.error);
    this.renderKanban(data.kanban);
  },

  renderKanban(kanban) {
    const cols = { todo: 'todo', in_progress: 'in_progress', review: 'review', done: 'done' };
    const labels = { todo: '未着手', in_progress: '進行中', review: '確認待ち', done: '完了' };
    const colColors = { todo: 'kanban-col-todo', in_progress: 'kanban-col-in_progress', review: 'kanban-col-review', done: 'kanban-col-done' };

    Object.keys(cols).forEach(status => {
      const col = document.getElementById(`kanban-${status}`);
      if (!col) return;
      const cards = (kanban[status] || []).map(t => `
        <div class="kanban-card ${status === 'done' ? 'done-card' : ''}"
             onclick="Tasks.openDetail(${t.id})">
          <div class="kanban-card-title ${status === 'done' ? 'done-text' : ''}">${h(t.title)}</div>
          <div class="kanban-card-meta">
            <span class="badge badge-${priorityColor(t.priority)}" style="font-size:9px">${priorityLabel(t.priority)}</span>
            ${t.due_date ? `<span class="text-faint"><i class="ti ti-calendar" style="font-size:10px"></i>${fmtDate(t.due_date)}</span>` : ''}
            ${t.google_event_id ? '<span style="font-size:9px;color:#0F9D58;background:#d1fae5;padding:1px 4px;border-radius:5px"><i class="ti ti-calendar" style="font-size:9px"></i>登録済</span>' : ''}
          </div>
        </div>
      `).join('');

      col.innerHTML = `
        <div class="kanban-col-title">${labels[status]} <span style="font-weight:400;color:#888">(${(kanban[status] || []).length})</span></div>
        ${cards}
      `;
    });
  },

  async updateStatus(taskId, status) {
    const res = await API.post('/crm/api/tasks.php?action=update_status', { id: taskId, status });
    res.ok ? (Toast.success(res.message), this.load()) : Toast.error(res.error);
  },

  async openDetail(id) {
    const data = await API.get(`/crm/api/tasks.php?action=detail&id=${id}`);
    if (!data.ok) return Toast.error(data.error);
    TaskDetail.render(data.task, data.comments);
    Modal.open('task-detail-modal');
  },

  async submitComment(taskId) {
    const input = document.getElementById('task-comment-input');
    const content = input?.value.trim();
    if (!content) return;
    const res = await API.post('/crm/api/tasks.php?action=comment', { task_id: taskId, content });
    if (res.ok) {
      input.value = '';
      this.openDetail(taskId);
    } else {
      Toast.error(res.error);
    }
  },
};

// ============================================================
//  タスク詳細モーダル
// ============================================================
const TaskDetail = {
  render(task, comments) {
    const el = document.getElementById('task-detail-body');
    if (!el) return;

    const statusOptions = ['todo','in_progress','review','done'].map(s => `
      <option value="${s}" ${task.status === s ? 'selected' : ''}>${{ todo:'未着手', in_progress:'進行中', review:'確認待ち', done:'完了' }[s]}</option>
    `).join('');

    el.innerHTML = `
      <div style="display:flex;gap:12px">
        <div style="width:185px;flex-shrink:0">
          <div style="font-size:11px;font-weight:500;color:#185FA5;margin-bottom:8px">タスク情報</div>
          <div class="detail-row"><div class="detail-label">ステータス</div>
            <select id="task-status-sel" onchange="Tasks.updateStatus(${task.id}, this.value)" style="font-size:11px;padding:3px 6px;width:auto">
              ${statusOptions}
            </select>
          </div>
          <div class="detail-row"><div class="detail-label">優先度</div><div class="detail-val">${statusBadge('priority', task.priority)}</div></div>
          <div class="detail-row"><div class="detail-label">担当者</div><div class="detail-val">ID:${task.assigned_to}</div></div>
          <div class="detail-row"><div class="detail-label">期限</div><div class="detail-val" style="color:${isOverdue(task.due_date)?'#dc2626':'inherit'}">${fmtDate(task.due_date)}</div></div>
          ${task.customer_name ? `<div class="detail-row"><div class="detail-label">顧客</div><div class="detail-val text-primary">${h(task.customer_name)}</div></div>` : ''}
          <div class="detail-row" style="border:none"><div class="detail-label">作成日</div><div class="detail-val text-faint">${fmtDate(task.created_at)}</div></div>
        </div>
        <div style="flex:1;display:flex;flex-direction:column;gap:10px;min-width:0">
          ${task.description ? `<div style="font-size:12px;color:#555;line-height:1.7;background:#f8f8f5;padding:10px;border-radius:7px">${h(task.description)}</div>` : ''}
          <div style="font-size:11px;font-weight:500;color:#333;margin-bottom:5px">コメント</div>
          <div id="task-comments-thread" style="max-height:200px;overflow-y:auto">
            ${comments.map(c => `
              <div class="comment ${c.employee_id == currentUserId ? 'own' : ''}">
                <div class="avatar avatar-sm av-blue">${c.employee_id}</div>
                <div><div class="bubble">
                  <div class="bubble-name">ID:${c.employee_id}</div>
                  ${h(c.content)}
                  <div class="bubble-time">${fmtDatetime(c.created_at)}</div>
                </div></div>
              </div>
            `).join('') || '<div style="text-align:center;color:#aaa;font-size:11px;padding:10px">まだコメントはありません</div>'}
          </div>
          <div class="comment-input">
            <input type="text" id="task-comment-input" placeholder="コメントを追加..." style="font-size:11px">
            <button class="btn btn-primary btn-sm" onclick="Tasks.submitComment(${task.id})">
              <i class="ti ti-send" style="font-size:11px"></i>送信
            </button>
          </div>
        </div>
      </div>
    `;
  },
};

// ============================================================
//  社内TODO
// ============================================================
const Todos = {
  async load(params = {}) {
    const qs = new URLSearchParams({ action: 'list', ...params }).toString();
    const data = await API.get(`/crm/api/todos.php?${qs}`);
    if (!data.ok) return Toast.error(data.error);
    this.render(data.data);
  },

  render(todos) {
    const container = document.getElementById('todo-list');
    if (!container) return;
    if (!todos.length) {
      container.innerHTML = '<div style="text-align:center;color:#aaa;padding:30px;font-size:12px">TODOはありません</div>';
      return;
    }

    const grouped = { high: [], medium: [], low: [], done: [] };
    todos.forEach(t => {
      if (t.status === 'done') grouped.done.push(t);
      else grouped[t.priority]?.push(t);
    });

    const pLabels = { high: '高優先度', medium: '中優先度', low: '低優先度', done: '完了済み' };
    const pColors = { high: '#ef4444', medium: '#f59e0b', low: '#10b981' };

    let html = '';
    ['high','medium','low','done'].forEach(p => {
      if (!grouped[p].length) return;
      html += `<div style="font-size:10px;color:#888;font-weight:500;margin:10px 0 6px;display:flex;align-items:center;gap:4px">
        ${p !== 'done' ? `<div style="width:7px;height:7px;border-radius:50%;background:${pColors[p]}"></div>` : '<i class="ti ti-circle-check" style="color:#10b981;font-size:12px"></i>'}
        ${pLabels[p]} (${grouped[p].length}件)</div>`;

      grouped[p].forEach(t => {
        const isOwn = t.status !== 'done';
        const overdue = isOwn && t.due_date && new Date(t.due_date) < new Date();
        html += `
          <div class="todo-item" id="todo-${t.id}">
            <div class="todo-header" onclick="Todos.toggle(${t.id})">
              <div class="todo-check ${t.status === 'done' ? 'checked' : ''}" onclick="event.stopPropagation();Todos.toggleDone(${t.id}, ${t.status === 'done' ? 0 : 1})">
                ${t.status === 'done' ? '<i class="ti ti-check" style="font-size:10px"></i>' : ''}
              </div>
              <div style="flex:1">
                <div class="todo-title ${t.status === 'done' ? 'done-text' : ''}">${h(t.title)}</div>
                <div class="todo-meta">
                  ${t.assigned_to ? `<div class="avatar avatar-sm av-blue">${t.assigned_to}</div>` : '<span style="font-size:10px;color:#aaa">全員</span>'}
                  ${t.due_date ? `<span style="font-size:10px;${overdue ? 'color:#dc2626;font-weight:500' : 'color:#888'}"><i class="ti ti-calendar" style="font-size:10px"></i> ${fmtDate(t.due_date)}${overdue ? ' 期限超過' : ''}</span>` : ''}
                  ${statusBadge('priority', t.priority, 'font-size:9px')}
                  ${t.comment_count > 0 ? `<span style="font-size:10px;color:#888"><i class="ti ti-message-circle" style="font-size:10px"></i> ${t.comment_count}件</span>` : ''}
                  ${t.attach_count > 0 ? `<span style="font-size:10px;color:#888"><i class="ti ti-paperclip" style="font-size:10px"></i> ${t.attach_count}件</span>` : ''}
                </div>
              </div>
              <i class="ti ti-chevron-down" id="todo-icon-${t.id}" style="font-size:13px;color:#ccc"></i>
            </div>
            <div class="todo-body hidden" id="todo-body-${t.id}">
              <div id="todo-detail-${t.id}">
                <div style="text-align:center;color:#aaa;font-size:11px;padding:8px">読み込み中...</div>
              </div>
            </div>
          </div>
        `;
      });
    });

    container.innerHTML = html;
  },

  async toggle(id) {
    const body = document.getElementById(`todo-body-${id}`);
    const icon = document.getElementById(`todo-icon-${id}`);
    if (!body) return;

    const isOpen = !body.classList.contains('hidden');
    if (isOpen) {
      body.classList.add('hidden');
      icon?.classList.replace('ti-chevron-up', 'ti-chevron-down');
    } else {
      body.classList.remove('hidden');
      icon?.classList.replace('ti-chevron-down', 'ti-chevron-up');
      // 詳細を非同期ロード
      await this.loadDetail(id);
    }
  },

  async loadDetail(id) {
    const el = document.getElementById(`todo-detail-${id}`);
    if (!el) return;

    const data = await API.get(`/crm/api/todos.php?action=detail&id=${id}`);
    if (!data.ok) { el.innerHTML = '<div style="color:red">読み込みエラー</div>'; return; }

    const { todo, comments, attachments } = data;

    el.innerHTML = `
      ${todo.description ? `<div style="font-size:11px;color:#555;line-height:1.6;margin-bottom:9px">${h(todo.description)}</div>` : ''}
      ${attachments.length ? `
        <div class="attach-list" style="margin-bottom:8px">
          ${attachments.map(a => `
            <a href="/api/todo_download.php?id=${a.id}" class="attach-item" target="_blank">
              <i class="ti ti-file-text" style="font-size:12px;color:#185FA5"></i>
              ${h(a.original_name)}
            </a>
          `).join('')}
        </div>
      ` : ''}
      <div class="divider"></div>
      <div style="font-size:10px;color:#888;font-weight:500;margin-bottom:8px">コメント</div>
      <div style="max-height:200px;overflow-y:auto;margin-bottom:9px">
        ${comments.map(c => `
          <div class="comment ${c.employee_id == currentUserId ? 'own' : ''}">
            <div class="avatar avatar-sm av-blue">${c.employee_id}</div>
            <div><div class="bubble">
              <div class="bubble-name">ID:${c.employee_id}</div>
              ${h(c.content)}
              <div class="bubble-time">${fmtDatetime(c.created_at)}</div>
            </div></div>
          </div>
        `).join('') || '<div style="text-align:center;color:#aaa;font-size:11px;padding:8px">まだコメントはありません</div>'}
      </div>
      <div class="comment-input">
        <input type="text" id="todo-comment-${id}" placeholder="コメントを追加..." style="font-size:11px">
        <label class="btn btn-xs" title="ファイル添付">
          <i class="ti ti-paperclip" style="font-size:12px"></i>
          <input type="file" style="display:none" onchange="Todos.uploadFile(${id}, this)">
        </label>
        <button class="btn btn-primary btn-sm" onclick="Todos.postComment(${id})">
          <i class="ti ti-send" style="font-size:11px"></i>送信
        </button>
      </div>
    `;
  },

  async toggleDone(id, done) {
    const res = await API.post('/crm/api/todos.php?action=done', { id, done });
    res.ok ? (Toast.success(res.message), this.load()) : Toast.error(res.error);
  },

  async postComment(todoId) {
    const input = document.getElementById(`todo-comment-${todoId}`);
    const content = input?.value.trim();
    if (!content) return;
    const res = await API.post('/crm/api/todos.php?action=comment', { todo_id: todoId, content });
    if (res.ok) { input.value = ''; await this.loadDetail(todoId); Toast.success(res.message); }
    else Toast.error(res.error);
  },

  async uploadFile(todoId, input) {
    if (!input.files.length) return;
    const fd = new FormData();
    fd.append('todo_id', todoId);
    fd.append('file', input.files[0]);
    const res = await API.post('/crm/api/todos.php?action=upload', fd);
    res.ok ? (Toast.success(res.message), this.loadDetail(todoId)) : Toast.error(res.error);
    input.value = '';
  },

  async delete(id) {
    Modal.confirm('このTODOを削除しますか？', async () => {
      const res = await API.post('/crm/api/todos.php?action=delete', { id });
      res.ok ? (Toast.success(res.message), this.load()) : Toast.error(res.error);
    });
  },
};

// ============================================================
//  Gmail
// ============================================================
const GmailView = {
  async loadInbox() {
    const data = await API.get('/crm/api/gmail_api.php?action=inbox');
    if (!data.ok) return Toast.error(data.error);
    this.renderList(data.data);
  },

  renderList(mails) {
    const el = document.getElementById('mail-list');
    if (!el) return;
    if (!mails.length) {
      el.innerHTML = '<div style="text-align:center;color:#aaa;padding:20px;font-size:12px">メールはありません</div>';
      return;
    }
    el.innerHTML = mails.map(m => `
      <div class="mail-row ${m.is_read ? '' : 'unread'}" onclick="GmailView.openMail(${m.id})">
        <div class="avatar avatar-sm av-teal">${m.customer_name ? m.customer_name.charAt(0) : '?'}</div>
        <div style="flex:1;min-width:0">
          <div style="font-size:10px;color:#222;font-weight:${m.is_read ? '400' : '600'}">${h(m.from_name || m.from_address)}</div>
          <div class="mail-subj">${h(m.subject)}</div>
          <div class="mail-prev">${h(m.body_preview || '')}</div>
        </div>
        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:2px">
          <div class="mail-time">${fmtDate(m.sent_at, 'M/d')}</div>
          ${m.is_read ? '' : '<div class="unread-dot"></div>'}
        </div>
      </div>
    `).join('');
  },

  async openMail(id) {
    const data = await API.get(`/crm/api/gmail_api.php?action=detail&id=${id}`);
    if (!data.ok) return Toast.error(data.error);
    this.renderDetail(data.mail);
  },

  renderDetail(mail) {
    const el = document.getElementById('mail-detail');
    if (!el) return;
    el.innerHTML = `
      <div style="padding:12px 14px;border-bottom:1px solid #eee">
        <div style="font-size:13px;font-weight:500;margin-bottom:5px">${h(mail.subject)}</div>
        <div style="display:flex;justify-content:space-between;margin-bottom:5px">
          <div style="font-size:10px;color:#888">${h(mail.from_address)}</div>
          <div style="font-size:10px;color:#aaa">${fmtDatetime(mail.sent_at)}</div>
        </div>
        ${mail.customer_name ? `<div style="display:flex;gap:5px;align-items:center">
          <span style="font-size:10px;color:#888">顧客:</span>
          <a href="/crm/customers/${mail.customer_id}" class="badge badge-green">🔗 ${h(mail.customer_name)}</a>
          <span style="font-size:10px;color:#aaa">自動マッチング済</span>
        </div>` : ''}
      </div>
      <div style="padding:14px;flex:1;overflow:auto;font-size:12px;line-height:1.8;color:#333;white-space:pre-wrap">${h(mail.body_plain || '')}</div>
      <div style="padding:9px 14px;border-top:1px solid #eee;display:flex;gap:6px">
        <button class="btn btn-primary btn-sm" onclick="GmailView.reply(${mail.id})"><i class="ti ti-corner-up-left" style="font-size:11px"></i>返信</button>
        <button class="btn btn-sm" onclick="Tasks.createFromMail(${mail.id})"><i class="ti ti-checklist" style="font-size:11px"></i>タスク作成</button>
        <button class="btn btn-sm" onclick="Todos.createFromMail(${mail.id})"><i class="ti ti-list-check" style="font-size:11px"></i>TODO作成</button>
      </div>
    `;
  },
};

// ============================================================
//  ユーティリティ関数
// ============================================================
function h(str) {
  if (str == null) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function fmtDate(dt, fmt = 'Y/m/d') {
  if (!dt) return '—';
  const d = new Date(dt);
  if (isNaN(d)) return dt;
  const Y = d.getFullYear(), m = String(d.getMonth()+1).padStart(2,'0'), day = String(d.getDate()).padStart(2,'0');
  return fmt.replace('Y', Y).replace('m', m).replace('d', day).replace('M', d.getMonth()+1);
}

function fmtDatetime(dt) {
  if (!dt) return '—';
  const d = new Date(dt);
  return `${d.getFullYear()}/${String(d.getMonth()+1).padStart(2,'0')}/${String(d.getDate()).padStart(2,'0')} ${String(d.getHours()).padStart(2,'0')}:${String(d.getMinutes()).padStart(2,'0')}`;
}

function isOverdue(dateStr) {
  if (!dateStr) return false;
  return new Date(dateStr) < new Date();
}

function priorityLabel(p) {
  return { high:'高', medium:'中', low:'低' }[p] || p;
}

function priorityColor(p) {
  return { high:'red', medium:'amber', low:'green' }[p] || 'gray';
}

function statusBadge(type, val, extraStyle = '') {
  const maps = {
    customer: {
      prospect:    ['見込み',   'amber'],
      negotiating: ['商談中',   'blue'],
      contracted:  ['契約中',   'green'],
      pending:     ['保留',     'gray'],
      lost:        ['失注',     'red'],
    },
    task: {
      todo:        ['未着手',   'gray'],
      in_progress: ['進行中',   'blue'],
      review:      ['確認待ち', 'amber'],
      done:        ['完了',     'green'],
    },
    priority: {
      high:   ['高', 'red'],
      medium: ['中', 'amber'],
      low:    ['低', 'green'],
    },
    role: {
      admin:     ['管理者',       'red'],
      executive: ['幹部職',       'purple'],
      manager: ['マネージャー', 'amber'],
      general: ['一般',         'blue'],
    },
  };
  const [label, color] = maps[type]?.[val] || [val, 'gray'];
  return `<span class="badge badge-${color}" style="${extraStyle}">${label}</span>`;
}

function renderPagination(pg, onPage) {
  if (pg.total_pages <= 1) return '';
  const pages = [];
  for (let i = 1; i <= pg.total_pages; i++) {
    pages.push(`<button class="page-btn ${i === pg.current ? 'active' : ''}" onclick="(${onPage.toString()})(${i})">${i}</button>`);
  }
  return `<div class="pagination">
    <button class="page-btn" onclick="(${onPage.toString()})(${pg.current - 1})" ${!pg.has_prev ? 'disabled' : ''}><i class="ti ti-chevron-left" style="font-size:12px"></i></button>
    ${pages.join('')}
    <button class="page-btn" onclick="(${onPage.toString()})(${pg.current + 1})" ${!pg.has_next ? 'disabled' : ''}><i class="ti ti-chevron-right" style="font-size:12px"></i></button>
  </div>`;
}

// ============================================================
//  現在のユーザーID（ページから埋め込む）
// ============================================================
let currentUserId = parseInt(document.querySelector('meta[name="user-id"]')?.content ?? '0');

// ============================================================
//  モーダルをESCで閉じる
// ============================================================
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal-overlay:not(.hidden)').forEach(el => el.classList.add('hidden'));
  }
});

// ============================================================
//  ページ初期化
// ============================================================
document.addEventListener('DOMContentLoaded', () => {
  // ログアウトボタン
  document.getElementById('logout-btn')?.addEventListener('click', async () => {
    if (!confirm('ログアウトしますか？')) return;
    const logoutForm = new FormData(); logoutForm.append("csrf_token", document.querySelector("meta[name=csrf-token]").content); await fetch("/crm/auth/logout.php", { method: "POST", body: logoutForm });
    location.href = '/crm/public/login.php';
  });

  // ページ固有の初期化
  const page = document.body.dataset.page;
  if (page === 'customers')  Customers.load();
  if (page === 'tasks')      Tasks.load();
  if (page === 'todos')      Todos.load();
  if (page === 'mail')       GmailView.loadInbox();
});