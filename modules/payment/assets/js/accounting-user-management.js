document.addEventListener('DOMContentLoaded', () => {
  'use strict';
  const app = document.getElementById('accountingUsersApp');
  if (!app) return;
  const can = name => app.dataset[name] === '1';
  const rows = document.getElementById('accountingUsersRows');
  const message = document.getElementById('accountingUsersMessage');
  const form = document.getElementById('officerForm');
  const resetForm = document.getElementById('resetPasswordForm');
  const modal = new bootstrap.Modal(document.getElementById('officerModal'));
  const resetModal = new bootstrap.Modal(document.getElementById('resetPasswordModal'));
  const searchInput = document.getElementById('userSearch');
  const roleFilter = document.getElementById('roleFilter');
  const statusFilter = document.getElementById('statusFilter');
  const paginationControls = document.getElementById('paginationControls');
  const paginationSummary = document.getElementById('paginationSummary');
  const PAGE_SIZE = 8;

  let users = [];
  let originalRole = '';
  let resetUser = null;
  let page = 1;

  const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  }[char]));

  async function call(action, data = {}) {
    const response = await fetch(app.dataset.api, {
      method: 'POST', credentials: 'same-origin', cache: 'no-store',
      headers: {'Content-Type': 'application/json', 'X-CSRF-Token': app.dataset.csrf},
      body: JSON.stringify({action, ...data})
    });
    const body = await response.json();
    if (!response.ok || !body.ok) throw new Error(body.message || body.error || 'Request failed.');
    if (body.csrf_token) app.dataset.csrf = body.csrf_token;
    return body.data;
  }
  function show(text, type = 'success') {
    message.innerHTML = `<div class="alert alert-${type}">${esc(text)}</div>`;
  }
  function roleLabel(role) {
    return role === 'accounting_admin' ? 'Accounting Admin' : role === 'cashier' ? 'Cashier' : 'Accounting Officer';
  }
  function isLocked(user) {
    if (user.status === 'locked' && !user.locked_until) return true;
    if (!user.locked_until) return false;
    return new Date(String(user.locked_until).replace(' ', 'T') + '+08:00').getTime() > Date.now();
  }
  function initials(name) {
    const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return '?';
    return (parts[0][0] + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase();
  }
  function formatLastLogin(user) {
    const raw = user.last_login_at || user.last_login || null;
    if (!raw) return '<span class="text-muted">Never logged in</span>';
    const date = new Date(String(raw).replace(' ', 'T') + '+08:00');
    if (isNaN(date.getTime())) return '<span class="text-muted">—</span>';
    const dateText = date.toLocaleDateString('en-US', {month: 'short', day: '2-digit', year: 'numeric'});
    const timeText = date.toLocaleTimeString('en-US', {hour: '2-digit', minute: '2-digit'});
    const ip = user.last_login_ip ? `<div class="mis-last-login-ip">${esc(user.last_login_ip)}</div>` : '';
    return `<div class="mis-last-login-date">${dateText} · ${timeText}</div>${ip}`;
  }
  function actionButton(css, id, label, style) {
    const icons = {edit: 'ti-edit', activate: 'ti-user-check', deactivate: 'ti-user-off', unlock: 'ti-lock-open', reset: 'ti-key'};
    const variant = css === 'deactivate' ? 'danger' : style;
    return `<button type="button" class="btn btn-sm btn-outline-${variant} ${css}" data-id="${Number(id)}" title="${label}" aria-label="${label}"><i class="ti ${icons[css] || 'ti-settings'}" aria-hidden="true"></i></button>`;
  }

  function filteredUsers() {
    const term = (searchInput.value || '').trim().toLowerCase();
    const role = roleFilter.value;
    const status = statusFilter.value;
    return users.filter(user => {
      if (role && user.role_key !== role) return false;
      if (status && user.status !== status) return false;
      if (term) {
        const haystack = `${user.full_name} ${user.username} ${user.email}`.toLowerCase();
        if (!haystack.includes(term)) return false;
      }
      return true;
    });
  }

  function renderStats() {
    const total = users.length;
    const active = users.filter(u => u.status === 'active').length;
    const locked = users.filter(isLocked).length;
    const force = users.filter(u => Number(u.must_change_password)).length;
    document.getElementById('statTotal').textContent = total;
    document.getElementById('statActive').textContent = active;
    document.getElementById('statLocked').textContent = locked;
    document.getElementById('statForce').textContent = force;
    document.getElementById('statActivePct').textContent = total
      ? `${((active / total) * 100).toFixed(1)}% of personnel`
      : 'Authenticated & operational';
    const lockedSub = document.getElementById('statLockedSub');
    lockedSub.textContent = locked ? `${locked} user${locked > 1 ? 's' : ''} locked by security trigger` : 'No locked accounts';
    lockedSub.classList.toggle('text-danger', locked > 0);
    lockedSub.classList.toggle('text-muted', locked === 0);
  }

  function renderPagination(total) {
    const pageCount = Math.max(1, Math.ceil(total / PAGE_SIZE));
    if (page > pageCount) page = pageCount;
    const start = total ? (page - 1) * PAGE_SIZE + 1 : 0;
    const end = Math.min(page * PAGE_SIZE, total);
    paginationSummary.textContent = total
      ? `Showing ${start}–${end} of ${total} payment account${total === 1 ? '' : 's'}`
      : 'Showing 0 of 0 payment accounts';
    paginationControls.innerHTML = `
      <button type="button" class="btn btn-outline-secondary btn-sm" id="pagePrev" ${page <= 1 ? 'disabled' : ''}><i class="fas fa-chevron-left"></i></button>
      <button type="button" class="btn btn-outline-secondary btn-sm disabled">${page} / ${pageCount}</button>
      <button type="button" class="btn btn-outline-secondary btn-sm" id="pageNext" ${page >= pageCount ? 'disabled' : ''}><i class="fas fa-chevron-right"></i></button>`;
    document.getElementById('pagePrev')?.addEventListener('click', () => { page -= 1; render(); });
    document.getElementById('pageNext')?.addEventListener('click', () => { page += 1; render(); });
  }

  function render() {
    const list = filteredUsers();
    renderStats();
    const pageCount = Math.max(1, Math.ceil(list.length / PAGE_SIZE));
    if (page > pageCount) page = pageCount;
    const pageItems = list.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE);

    rows.innerHTML = pageItems.length ? pageItems.map(user => {
      const locked = isLocked(user);
      const actions = [];
      if (can('canUpdate') || can('canAssignRole')) actions.push(actionButton('edit', user.id, 'Edit', 'primary'));
      if (user.status === 'inactive') {
        if (can('canActivate')) actions.push(actionButton('activate', user.id, 'Activate', 'success'));
      } else if (can('canDeactivate')) actions.push(actionButton('deactivate', user.id, 'Deactivate', 'secondary'));
      if (locked && can('canUnlock')) actions.push(actionButton('unlock', user.id, 'Unlock', 'info'));
      if (can('canResetPassword')) actions.push(actionButton('reset', user.id, 'Reset password', 'warning'));
      return `<tr class="${user.status !== 'active' || locked ? 'mis-row-attention' : ''}">` +
        `<td class="ps-4"><div class="d-flex align-items-center gap-2">` +
        `<div class="mis-avatar">${esc(initials(user.full_name))}</div>` +
        `<div><div class="fw-semibold">${esc(user.full_name)}</div><div class="mis-user-handle">@${esc(user.username)}</div></div>` +
        `</div></td>` +
        `<td><span class="badge bg-light text-dark border">${esc(roleLabel(user.role_key))}</span></td>` +
        `<td>${esc(user.email)}</td>` +
        `<td><span class="badge mis-status-badge ${user.status === 'active' ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary'}"><i class="fas fa-circle fa-2xs"></i>${esc(user.status === 'active' ? 'Active' : 'Inactive')}</span></td>` +
        `<td>${locked ? '<span class="badge mis-status-badge bg-danger-subtle text-danger"><i class="fas fa-lock fa-2xs"></i>Locked</span>' : '<span class="badge mis-status-badge bg-success-subtle text-success"><i class="fas fa-unlock fa-2xs"></i>Unlocked</span>'}</td>` +
        `<td>${Number(user.must_change_password) ? '<span class="badge bg-warning-subtle text-warning-emphasis">Required</span>' : '<span class="text-muted">Not required</span>'}</td>` +
        `<td>${formatLastLogin(user)}</td>` +
        `<td class="text-end pe-4"><div class="d-inline-flex flex-wrap justify-content-end gap-1">${actions.join('')}</div></td></tr>`;
    }).join('') : `<tr><td colspan="8" class="text-center py-4 text-muted">${users.length ? 'No matching Payment users.' : 'No Payment users found.'}</td></tr>`;

    renderPagination(list.length);
  }

  async function load() { users = await call('list'); page = 1; render(); }

  [searchInput, roleFilter, statusFilter].forEach(el => {
    el.addEventListener('input', () => { page = 1; render(); });
    el.addEventListener('change', () => { page = 1; render(); });
  });
  document.getElementById('refreshUsers')?.addEventListener('click', () => load().catch(error => show(error.message, 'danger')));

  function bindPolicy(targetForm, identity) {
    const password = targetForm.querySelector('[name=password]');
    const confirm = targetForm.querySelector('[name=password_confirm]');
    const feedback = targetForm.querySelector('.password-feedback');
    const rules = Object.fromEntries([...targetForm.querySelectorAll('[data-rule]')].map(item => [item.dataset.rule, item]));
    const update = () => {
      const value = password.value;
      const lower = value.toLowerCase();
      const username = (identity()?.username || targetForm.querySelector('[name=username]')?.value || '').toLowerCase();
      const email = (identity()?.email || targetForm.querySelector('[name=email]')?.value || '').toLowerCase();
      const local = email.split('@')[0];
      const checks = {length: value.length >= 12 && value.length <= 128, upper: /[A-Z]/.test(value),
        lower: /[a-z]/.test(value), number: /\d/.test(value), special: /[^A-Za-z0-9]/.test(value),
        identifier: !(username.length >= 3 && lower.includes(username)) && !(local.length >= 3 && lower.includes(local)),
        match: !!value && value === confirm.value};
      Object.entries(checks).forEach(([key, ok]) => {
        const item = rules[key];
        if (!item) return;
        item.classList.toggle('text-success', ok); item.classList.toggle('text-danger', !ok);
        item.textContent = (ok ? '✓ ' : '○ ') + item.textContent.replace(/^[✓○] /, '');
      });
      const met = Object.values(checks).filter(Boolean).length;
      feedback.className = 'password-feedback small mt-2 ' + (met === 7 ? 'text-success' : 'text-muted');
      feedback.textContent = !value ? 'Start typing to check your password.' : met === 7 ? 'Strong password — ready to save.' : `${met}/7 requirements met`;
      return checks;
    };
    password.addEventListener('input', update); confirm.addEventListener('input', update);
    targetForm.querySelector('[name=username]')?.addEventListener('input', update);
    targetForm.querySelector('[name=email]')?.addEventListener('input', update);
    return update;
  }
  const createPolicy = bindPolicy(form, () => null);
  const resetPolicy = bindPolicy(resetForm, () => resetUser);

  document.getElementById('newOfficer')?.addEventListener('click', () => {
    form.reset(); form.user_id.value = ''; form.role_key.value = 'accounting_officer';
    form.role_key.disabled = false; form.password.required = true; form.password_confirm.required = true;
    document.querySelector('.password-fields').hidden = false;
    document.getElementById('officerModalTitle').textContent = 'Add Payment User';
    originalRole = ''; createPolicy(); modal.show();
  });

  rows.addEventListener('click', async event => {
    const button = event.target.closest('button');
    if (!button) return;
    const user = users.find(item => Number(item.id) === Number(button.dataset.id));
    if (!user) return;
    if (button.classList.contains('edit')) {
      form.user_id.value = user.id; form.role_key.value = user.role_key; originalRole = user.role_key;
      form.role_key.disabled = !can('canAssignRole');
      form.full_name.value = user.full_name; form.username.value = user.username; form.email.value = user.email;
      form.full_name.disabled = form.username.disabled = form.email.disabled = !can('canUpdate');
      form.password.value = ''; form.password_confirm.value = '';
      form.password.required = false; form.password_confirm.required = false;
      document.querySelector('.password-fields').hidden = true;
      document.getElementById('officerModalTitle').textContent = `Edit ${roleLabel(user.role_key)}`;
      modal.show(); return;
    }
    try {
      if (button.classList.contains('activate') || button.classList.contains('deactivate')) {
        const action = button.classList.contains('activate') ? 'activate' : 'deactivate';
        if (!confirm(`${action === 'activate' ? 'Activate' : 'Deactivate'} this user?`)) return;
        await call(action, {user_id: Number(user.id)}); show(`User ${action}d.`); await load();
      } else if (button.classList.contains('unlock')) {
        if (!confirm('Unlock this account? Administrative status and password will remain unchanged.')) return;
        await call('unlock', {user_id: Number(user.id)}); show('Account security lock cleared.'); await load();
      } else if (button.classList.contains('reset')) {
        resetUser = user; resetForm.reset(); resetForm.user_id.value = user.id;
        document.getElementById('resetPasswordTarget').textContent = `${user.full_name} • ${user.username}`;
        resetPolicy(); resetModal.show();
      }
    } catch (error) { show(error.message, 'danger'); }
  });

  form.addEventListener('submit', async event => {
    event.preventDefault();
    const data = new FormData(form); const id = Number(data.get('user_id') || 0);
    try {
      if (!id) {
        if (data.get('password') !== data.get('password_confirm')) throw new Error('Password confirmation does not match.');
        await call('create', {role_key: data.get('role_key'), full_name: data.get('full_name'),
          username: data.get('username'), email: data.get('email'), password: data.get('password'),
          password_confirm: data.get('password_confirm')});
      } else {
        if (can('canUpdate')) await call('update_profile', {user_id: id, full_name: data.get('full_name'),
          username: data.get('username'), email: data.get('email')});
        const selectedRole = form.role_key.value;
        if (can('canAssignRole') && selectedRole !== originalRole) await call('assign_role', {user_id: id, role_key: selectedRole});
      }
      modal.hide(); show(id ? 'Payment user updated.' : 'Payment user created.'); await load();
    } catch (error) { show(error.message, 'danger'); }
  });

  resetForm.addEventListener('submit', async event => {
    event.preventDefault(); const data = new FormData(resetForm);
    try {
      if (data.get('password') !== data.get('password_confirm')) throw new Error('Password confirmation does not match.');
      await call('reset_password', {user_id: Number(data.get('user_id')), password: data.get('password'),
        password_confirm: data.get('password_confirm')});
      resetModal.hide(); show('Temporary password saved and existing sessions revoked.'); await load();
    } catch (error) { show(error.message, 'danger'); }
  });
  load().catch(error => show(error.message, 'danger'));
});
