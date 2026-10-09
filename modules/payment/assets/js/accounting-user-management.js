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
  const paymentUserRole = document.getElementById('paymentUserRole');
  const paymentUserRoleIcon = document.getElementById('paymentUserRoleIcon');
  const generateResetPassword = document.getElementById('generateResetPassword');
  const copyResetPassword = document.getElementById('copyResetPassword');
  const resetPasswordCopyFeedback = document.getElementById('resetPasswordCopyFeedback');
  const resetPasswordSubmit = resetForm?.querySelector('[type="submit"]');
  const deactivateFromReset = document.getElementById('deactivateFromReset');
  const PAGE_SIZE = 8;

  let users = [];
  let originalRole = '';
  let resetUser = null;
  let resettingPassword = false;
  let page = 1;

  function updateRoleIcon() {
    if (!paymentUserRoleIcon || !paymentUserRole) return;
    const roleIcons = {
      accounting_admin: 'ti ti-shield-lock',
      accounting_officer: 'ti ti-user-shield',
      cashier: 'ti ti-cash-register'
    };
    paymentUserRoleIcon.className = `${roleIcons[paymentUserRole.value] || 'ti ti-user-shield'} payment-user-field-icon`;
  }
  paymentUserRole?.addEventListener('change', updateRoleIcon);
  updateRoleIcon();

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
  function clearModalMessage(targetForm) {
    const target = targetForm.querySelector('.modal-form-message');
    if (target) target.innerHTML = '';
    targetForm.classList.remove('was-validated');
    targetForm.querySelectorAll('[data-server-error="1"]').forEach(field => {
      field.setCustomValidity('');
      field.removeAttribute('data-server-error');
      const feedback = field.parentElement?.querySelector('.invalid-feedback');
      if (feedback?.dataset.defaultMessage) feedback.textContent = feedback.dataset.defaultMessage;
    });
  }
  function showModalError(targetForm, text) {
    const target = targetForm.querySelector('.modal-form-message');
    if (!target) return;
    target.innerHTML = `<div class="alert alert-danger py-2 mb-3"><i class="fas fa-circle-exclamation me-1" aria-hidden="true"></i>${esc(text)}</div>`;
    target.scrollIntoView({block: 'nearest'});
  }
  function validateRequiredFields(targetForm) {
    targetForm.classList.add('was-validated');
    if (targetForm.checkValidity()) return true;
    showModalError(targetForm, 'Complete the required fields highlighted below.');
    targetForm.querySelector(':invalid')?.focus();
    return false;
  }

  function showIdentityConflict(messageText) {
    const lower = String(messageText || '').toLowerCase();
    const conflicts = [];
    if (lower.includes('username')) conflicts.push(['username', 'This username is already in use.']);
    if (lower.includes('email')) conflicts.push(['email', 'This email address is already in use.']);
    conflicts.forEach(([name, warning]) => {
      const field = form.querySelector(`[name="${name}"]`);
      const feedback = field?.parentElement?.querySelector('.invalid-feedback');
      if (!field || !feedback) return;
      if (!feedback.dataset.defaultMessage) feedback.dataset.defaultMessage = feedback.textContent;
      feedback.textContent = warning;
      field.dataset.serverError = '1';
      field.setCustomValidity(warning);
      field.addEventListener('input', () => {
        field.setCustomValidity('');
        field.removeAttribute('data-server-error');
        feedback.textContent = feedback.dataset.defaultMessage || '';
      }, {once: true});
    });
    if (conflicts.length) {
      form.classList.add('was-validated');
      form.querySelector('[data-server-error="1"]')?.focus();
      return true;
    }
    return false;
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
    const first = Math.max(1, page - 2);
    const last = Math.min(pageCount, page + 2);
    const button = (target, label, { active = false, disabled = false, aria = '' } = {}) =>
      `<li class="page-item${active ? ' active' : ''}${disabled ? ' disabled' : ''}">${disabled || active
        ? `<span class="page-link"${active ? ' aria-current="page"' : ' aria-disabled="true"'}>${label}</span>`
        : `<button type="button" class="page-link" data-page="${target}"${aria ? ` aria-label="${aria}"` : ''}>${label}</button>`}</li>`;
    let controls = button(page - 1, '‹ Previous', { disabled: page <= 1, aria: 'Previous page' });
    if (first > 1) {
      controls += button(1, '1');
      if (first > 2) controls += '<li class="page-item disabled" aria-hidden="true"><span class="page-link">…</span></li>';
    }
    for (let current = first; current <= last; current += 1) {
      controls += button(current, String(current), { active: current === page });
    }
    if (last < pageCount) {
      if (last < pageCount - 1) controls += '<li class="page-item disabled" aria-hidden="true"><span class="page-link">…</span></li>';
      controls += button(pageCount, String(pageCount));
    }
    controls += button(page + 1, 'Next ›', { disabled: page >= pageCount, aria: 'Next page' });
    paginationControls.innerHTML = `<nav class="payment-pagination" aria-label="Payment user pages"><ul class="pagination pagination-sm mb-0">${controls}</ul></nav>`;
    paginationControls.querySelectorAll('[data-page]').forEach(control => {
      control.addEventListener('click', () => { page = Number(control.dataset.page); render(); });
    });
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
      }
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
      const minLength = targetForm === resetForm ? Number(password.minLength) || 12 : 12;
      const checks = {length: value.length >= minLength && value.length <= 128, upper: /[A-Z]/.test(value),
        lower: /[a-z]/.test(value), number: /\d/.test(value), special: /[^A-Za-z0-9]/.test(value),
        identifier: !!value && ![username, email, local].filter(identifier => identifier.length >= 3).some(identifier => lower.includes(identifier)),
        not_blank: value.trim() !== '',
        match: !!value && value === confirm.value};
      Object.entries(checks).forEach(([key, ok]) => {
        const item = rules[key];
        if (!item) return;
        const wasValid = item.dataset.valid === 'true';
        item.dataset.valid = String(ok);
        item.classList.toggle('is-valid', ok); item.classList.toggle('is-invalid', !ok);
        if (targetForm !== resetForm) {
          item.classList.toggle('text-success', ok);
          item.classList.toggle('text-danger', !ok);
        }
        const icon = item.querySelector('i');
        if (icon) icon.className = ok ? 'ti ti-check' : 'ti ti-circle';
        if (ok !== wasValid) {
          item.classList.remove('is-changing');
          void item.offsetWidth;
          item.classList.add('is-changing');
          window.setTimeout(() => item.classList.remove('is-changing'), 240);
        }
      });
      const met = Object.values(checks).filter(Boolean).length;
      if (copyResetPassword) copyResetPassword.disabled = !(value && confirm.value && value === confirm.value);
      if (targetForm === resetForm && resetPasswordCopyFeedback) {
        resetPasswordCopyFeedback.textContent = '';
        resetPasswordCopyFeedback.removeAttribute('data-state');
        if (copyResetPassword) {
          copyResetPassword.innerHTML = '<i class="ti ti-copy" aria-hidden="true"></i>';
          copyResetPassword.classList.remove('is-copied');
          copyResetPassword.setAttribute('aria-label', 'Copy password');
        }
      }
      feedback.className = 'password-feedback small mt-2 ' + (met === Object.keys(checks).length ? 'text-success' : 'text-muted');
      feedback.textContent = !value ? 'Start typing to check your password.' : met === Object.keys(checks).length
        ? 'All live requirements met; common/default password policy is checked when submitted.'
        : `${met}/${Object.keys(checks).length} live requirements met; common/default password policy is checked when submitted.`;
      return checks;
    };
    password.addEventListener('input', update); confirm.addEventListener('input', update);
    targetForm.querySelector('[name=username]')?.addEventListener('input', update);
    targetForm.querySelector('[name=email]')?.addEventListener('input', update);
    return update;
  }
  const createPolicy = bindPolicy(form, () => null);
  const resetPolicy = bindPolicy(resetForm, () => resetUser);

  function generatedPassword() {
    const sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '!@#$%^&*'];
    const randomCharacter = set => set[crypto.getRandomValues(new Uint32Array(1))[0] % set.length];
    const characters = sets.map(randomCharacter);
    const all = sets.join('');
    while (characters.length < 16) characters.push(randomCharacter(all));
    for (let index = characters.length - 1; index > 0; index -= 1) {
      const swap = crypto.getRandomValues(new Uint32Array(1))[0] % (index + 1);
      [characters[index], characters[swap]] = [characters[swap], characters[index]];
    }
    return characters.join('');
  }

  generateResetPassword?.addEventListener('click', () => {
    if (!resetUser || generateResetPassword.disabled) return;
    generateResetPassword.disabled = true;
    generateResetPassword.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span><span>Generating…</span>';
    window.setTimeout(() => {
      let password = generatedPassword();
      const username = String(resetUser.username || '').toLowerCase();
      const emailLocal = String(resetUser.email || '').split('@')[0].toLowerCase();
      while ((username.length >= 3 && password.toLowerCase().includes(username)) || (emailLocal.length >= 3 && password.toLowerCase().includes(emailLocal))) password = generatedPassword();
      resetForm.password.value = password;
      resetForm.password_confirm.value = password;
      resetForm.password.dispatchEvent(new Event('input', {bubbles: true}));
      resetForm.password_confirm.dispatchEvent(new Event('input', {bubbles: true}));
      generateResetPassword.innerHTML = '<i class="ti ti-check" aria-hidden="true"></i><span>Password generated</span>';
      window.setTimeout(() => {
        generateResetPassword.innerHTML = '<i class="ti ti-refresh" aria-hidden="true"></i><span>Auto-generate</span>';
        generateResetPassword.disabled = false;
      }, 900);
    }, 1200);
  });

  resetForm?.querySelectorAll('[data-password-toggle]').forEach(button => button.addEventListener('click', () => {
    const input = document.getElementById(button.dataset.passwordToggle);
    if (!input) return;
    const reveal = input.type === 'password';
    input.type = reveal ? 'text' : 'password';
    button.setAttribute('aria-pressed', String(reveal));
    const target = input.name === 'password' ? 'new temporary password' : 'confirmation password';
    button.setAttribute('aria-label', `${reveal ? 'Hide' : 'Show'} ${target}`);
    button.title = `${reveal ? 'Hide' : 'Show'} password`;
    button.innerHTML = `<i class="ti ${reveal ? 'ti-eye-off' : 'ti-eye'}" aria-hidden="true"></i>`;
  }));

  copyResetPassword?.addEventListener('click', async () => {
    const password = resetForm.password.value;
    if (!password || !resetForm.password_confirm.value || password !== resetForm.password_confirm.value) {
      if (resetPasswordCopyFeedback) {
        resetPasswordCopyFeedback.dataset.state = 'error';
        resetPasswordCopyFeedback.textContent = 'Enter matching passwords before copying.';
      }
      return;
    }
    try {
      await navigator.clipboard.writeText(password);
      copyResetPassword.innerHTML = '<i class="ti ti-check" aria-hidden="true"></i>';
      copyResetPassword.classList.add('is-copied');
      copyResetPassword.setAttribute('aria-label', 'Password copied');
      if (resetPasswordCopyFeedback) {
        resetPasswordCopyFeedback.dataset.state = 'success';
        resetPasswordCopyFeedback.textContent = 'Password copied.';
      }
    } catch {
      if (resetPasswordCopyFeedback) {
        resetPasswordCopyFeedback.dataset.state = 'error';
        resetPasswordCopyFeedback.textContent = 'Clipboard access is unavailable in this browser context.';
      }
    } finally {
      window.setTimeout(() => {
        copyResetPassword.innerHTML = '<i class="ti ti-copy" aria-hidden="true"></i>';
        copyResetPassword.classList.remove('is-copied');
        copyResetPassword.setAttribute('aria-label', 'Copy password');
      }, 1400);
    }
  });

  deactivateFromReset?.addEventListener('click', async () => {
    if (!resetUser || resetUser.status === 'inactive' || !can('canDeactivate')) return;
    if (!confirm(`Deactivate ${resetUser.full_name}? Current sessions will be revoked.`)) return;
    deactivateFromReset.disabled = true;
    try {
      await call('deactivate', {user_id: Number(resetUser.id)});
      resetModal.hide();
      show('User deactivated and current sessions revoked.');
      await load();
    } catch (error) {
      showModalError(resetForm, error.message);
    } finally {
      deactivateFromReset.disabled = false;
    }
  });

  document.getElementById('newOfficer')?.addEventListener('click', () => {
    form.reset(); form.user_id.value = ''; form.role_key.value = 'accounting_officer';
    updateRoleIcon();
    message.innerHTML = '';
    clearModalMessage(form);
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
      message.innerHTML = '';
      clearModalMessage(form);
      form.user_id.value = user.id; form.role_key.value = user.role_key; originalRole = user.role_key;
      updateRoleIcon();
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
        resetUser = user; resetForm.reset(); message.innerHTML = ''; clearModalMessage(resetForm); resetForm.user_id.value = user.id;
        document.getElementById('resetAccountStatus').textContent = user.status === 'active' ? 'Active account' : 'Inactive account';
        resetForm.password.type = resetForm.password_confirm.type = 'password';
        resetForm.querySelectorAll('[data-password-toggle]').forEach(button => {
          button.setAttribute('aria-pressed', 'false');
          button.innerHTML = '<i class="ti ti-eye" aria-hidden="true"></i>';
          button.setAttribute('aria-label', button.dataset.passwordToggle === 'resetTempPassword' ? 'Show new temporary password' : 'Show confirmation password');
          button.title = 'Show password';
        });
        if (copyResetPassword) {
          copyResetPassword.disabled = true;
          copyResetPassword.innerHTML = '<i class="ti ti-copy" aria-hidden="true"></i>';
          copyResetPassword.classList.remove('is-copied');
          copyResetPassword.setAttribute('aria-label', 'Copy password');
        }
        if (resetPasswordCopyFeedback) { resetPasswordCopyFeedback.textContent = ''; resetPasswordCopyFeedback.removeAttribute('data-state'); }
        if (deactivateFromReset) {
          deactivateFromReset.disabled = user.status === 'inactive' || !can('canDeactivate');
          deactivateFromReset.title = user.status === 'inactive' ? 'Account is already inactive' : '';
        }
        document.getElementById('resetPasswordTarget').textContent = `${user.full_name} • ${user.username}`;
        resetPolicy(); resetModal.show();
      }
    } catch (error) { show(error.message, 'danger'); }
  });

  form.addEventListener('submit', async event => {
    event.preventDefault();
    const data = new FormData(form); const id = Number(data.get('user_id') || 0);
    clearModalMessage(form);
    if (!validateRequiredFields(form)) return;
    if (!id && !Object.values(createPolicy()).every(Boolean)) {
      showModalError(form, 'The temporary password must meet all requirements below.');
      form.password.focus();
      return;
    }
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
    } catch (error) {
      if (!showIdentityConflict(error.message)) showModalError(form, error.message);
    }
  });

  resetForm.addEventListener('submit', async event => {
    event.preventDefault(); const data = new FormData(resetForm);
    if (resettingPassword) return;
    clearModalMessage(resetForm);
    if (!validateRequiredFields(resetForm)) return;
    if (!Object.values(resetPolicy()).every(Boolean)) {
      showModalError(resetForm, 'The temporary password must meet all requirements below.');
      resetForm.password.focus();
      return;
    }
    resettingPassword = true;
    if (resetPasswordSubmit) {
      resetPasswordSubmit.disabled = true;
      resetPasswordSubmit.setAttribute('aria-busy', 'true');
      resetPasswordSubmit.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span><span>Resetting…</span>';
    }
    try {
      if (data.get('password') !== data.get('password_confirm')) throw new Error('Password confirmation does not match.');
      await call('reset_password', {user_id: Number(data.get('user_id')), password: data.get('password'),
        password_confirm: data.get('password_confirm')});
      resetModal.hide(); show('Temporary password saved and existing sessions revoked.'); await load();
    } catch (error) { showModalError(resetForm, error.message); }
    finally {
      resettingPassword = false;
      if (resetPasswordSubmit) {
        resetPasswordSubmit.disabled = false;
        resetPasswordSubmit.removeAttribute('aria-busy');
        resetPasswordSubmit.innerHTML = '<i class="ti ti-key" aria-hidden="true"></i>Reset password';
      }
    }
  });
  load().catch(error => show(error.message, 'danger'));
});
