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
  let users = [];
  let originalRole = '';
  let resetUser = null;
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
  function actionButton(css, id, label, style) {
    return `<button class="btn btn-sm btn-outline-${style} ${css}" data-id="${Number(id)}">${label}</button>`;
  }
  function render() {
    rows.innerHTML = users.length ? users.map(user => {
      const locked = isLocked(user);
      const actions = [];
      if (can('canUpdate') || can('canAssignRole')) actions.push(actionButton('edit', user.id, 'Edit', 'primary'));
      if (user.status === 'inactive') {
        if (can('canActivate')) actions.push(actionButton('activate', user.id, 'Activate', 'success'));
      } else if (can('canDeactivate')) actions.push(actionButton('deactivate', user.id, 'Deactivate', 'secondary'));
      if (locked && can('canUnlock')) actions.push(actionButton('unlock', user.id, 'Unlock', 'info'));
      if (can('canResetPassword')) actions.push(actionButton('reset', user.id, 'Reset password', 'warning'));
      return `<tr><td class="ps-4 fw-semibold">${esc(user.full_name)}</td>` +
        `<td><span class="badge bg-light text-dark border">${esc(roleLabel(user.role_key))}</span></td>` +
        `<td>${esc(user.username)}</td><td>${esc(user.email)}</td>` +
        `<td><span class="badge ${user.status === 'active' ? 'bg-success' : 'bg-secondary'}">${esc(user.status)}</span></td>` +
        `<td>${locked ? '<span class="badge bg-danger">Locked</span>' : '<span class="text-muted">Normal</span>'}</td>` +
        `<td>${Number(user.must_change_password) ? 'Yes' : 'No'}</td>` +
        `<td class="text-end pe-4"><div class="d-inline-flex flex-wrap justify-content-end gap-1">${actions.join('')}</div></td></tr>`;
    }).join('') : '<tr><td colspan="8" class="text-center py-4 text-muted">No Payment personnel accounts.</td></tr>';
  }
  async function load() { users = await call('list'); render(); }

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
