document.addEventListener('DOMContentLoaded', () => {
  'use strict';
  const app = document.getElementById('paymentSecurityApp');
  if (!app) return;
  const byId = id => document.getElementById(id);
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
  const state = {personnelPage: 1, eventPage: 1, personnelPages: 1, eventPages: 1};
  let currentPersonnel = [];

  function query() {
    const params = new URLSearchParams();
    new FormData(byId('personnelFilters')).forEach((value, key) => { if (String(value).trim()) params.set(key, String(value).trim()); });
    new FormData(byId('eventFilters')).forEach((value, key) => { if (String(value).trim()) params.set(key, String(value).trim()); });
    params.set('personnel_page', String(state.personnelPage));
    params.set('event_page', String(state.eventPage));
    if (!params.has('page_size')) params.set('page_size', '25');
    return params;
  }

  function displayLabel(value) { return String(value ?? '').replaceAll('_', ' ').toLowerCase().replace(/\b\w/g, char => char.toUpperCase()); }
  function badge(value, kind) { return `<span class="badge mis-status-badge bg-${kind}">${esc(displayLabel(value))}</span>`; }
  function roleLabel(role) { return role === 'accounting_admin' ? 'Accounting Admin' : role === 'cashier' ? 'Cashier' : 'Accounting Officer'; }
  function dateTime(value) { return value ? new Date(String(value).replace(' ', 'T') + '+08:00').toLocaleString() : '—'; }
  function notice(text, type = '') {
    const el = byId('securityNotice'); el.className = type ? `alert alert-${type} mb-3` : 'small text-muted mb-3'; el.textContent = text;
  }
  function renderPager(id, page, pages, change) {
    const host = byId(id);
    const item = (number, text, current = false, label = '') => `<li class="page-item${current ? ' active' : ''}"><a class="page-link" href="#" data-page="${number}"${current ? ' aria-current="page"' : ''}${label ? ` aria-label="${label}"` : ''}>${text}</a></li>`;
    const disabled = (text, label) => `<li class="page-item disabled"><span class="page-link" aria-disabled="true" aria-label="${label}">${text}</span></li>`;
    let html = page > 1 ? item(page - 1, '‹', false, 'Previous page') : disabled('‹', 'Previous page');
    const start = Math.max(1, page - 2), end = Math.min(pages, page + 2);
    if (start > 1) { html += item(1, '1'); if (start > 2) html += '<li class="page-item disabled" aria-hidden="true"><span class="page-link">…</span></li>'; }
    for (let number = start; number <= end; number++) html += item(number, String(number), number === page);
    if (end < pages) { if (end < pages - 1) html += '<li class="page-item disabled" aria-hidden="true"><span class="page-link">…</span></li>'; html += item(pages, String(pages)); }
    html += page < pages ? item(page + 1, '›', false, 'Next page') : disabled('›', 'Next page');
    host.innerHTML = html;
    host.querySelectorAll('[data-page]').forEach(link => link.addEventListener('click', event => { event.preventDefault(); change(Number(link.dataset.page)); }));
  }

  function renderSummary(summary) {
    const cards = [
      ['Locked Payment Accounts', summary.locked_accounts, 'danger', 'lock_state=locked', 'ti-lock'],
      ['Accounts With Failed Attempts', summary.accounts_with_failed_attempts, 'warning', 'failed_attempts=present', 'ti-alert-triangle'],
      ['Disabled Payment Accounts', summary.disabled_accounts, 'secondary', 'administrative_status=inactive', 'ti-user-off'],
      ['Recent Session Revocations', summary.recent_session_revocations, 'info', '', 'ti-logout'],
    ];
    byId('securitySummaryCards').innerHTML = cards.map(([label, value, color, filter, icon]) =>
      `<div class="col-sm-6 col-xl-3"><div class="card payment-card h-100"><div class="card-body d-flex align-items-start gap-3"><span class="payment-metric-icon" aria-hidden="true"><i class="ti ${icon}"></i></span><div><div class="text-muted small">${esc(label)}</div><div class="display-6 fw-semibold text-${color}">${Number(value)}</div>${filter ? `<button class="btn btn-link btn-sm p-0 summary-filter" data-filter="${esc(filter)}">View accounts</button>` : '<span class="small text-muted">Last 30 days</span>'}</div></div></div></div>`
    ).join('');
  }

  function renderPersonnel(data) {
    currentPersonnel = data.items;
    byId('personnelSecurityRows').innerHTML = data.items.length ? data.items.map(user => {
      const locked = user.security_state === 'LOCKED';
      const action = locked && app.dataset.canUnlock === '1' ? `<button class="btn btn-sm btn-outline-info unlock-account" data-id="${Number(user.id)}">Unlock</button>` : '<span class="text-muted">—</span>';
      return `<tr><td><div class="fw-semibold">${esc(user.full_name)}</div><small class="text-muted">${esc(user.username)} · ${esc(user.email)}</small></td><td>${esc(roleLabel(user.role_key))}</td>` +
        `<td>${badge(user.administrative_state, user.administrative_state === 'ACTIVE' ? 'success' : 'secondary')}</td>` +
        `<td>${badge(user.security_state, locked ? 'danger' : user.security_state === 'LOCK_EXPIRED' ? 'warning text-dark' : 'success')}</td>` +
        `<td>${Number(user.current_failed_attempts)}</td><td>${dateTime(user.locked_until)}</td><td>${dateTime(user.last_security_activity)}</td><td>${action}</td></tr>`;
    }).join('') : '<tr><td colspan="8" class="text-center py-4 text-muted">No Payment users found.</td></tr>';
    state.personnelPages = data.pages; state.personnelPage = data.page;
    byId('personnelCount').textContent = `${data.total} account(s) · Page ${data.page} of ${data.pages}`;
    renderPager('personnelPagination', data.page, data.pages, page => { state.personnelPage = page; load(); });
  }

  function renderEvents(data) {
    byId('securityEventRows').innerHTML = data.items.length ? data.items.map(event =>
      `<tr><td>${dateTime(event.created_at)}</td><td>${esc(event.event)}</td><td>${esc(event.actor)}</td><td>${esc(event.target)}</td><td>${esc(roleLabel(event.role_key))}</td>` +
      `<td>${badge(event.result, event.result === 'SUCCESS' ? 'success' : event.result === 'LOCKED' ? 'danger' : 'warning text-dark')}</td><td>${esc(event.safe_context)}${app.dataset.canViewAudit === '1' ? ` <a href="${esc(app.dataset.auditUrl)}?event_id=${Number(event.id)}">Audit detail</a>` : ''}</td><td><code>${esc(event.correlation_id || '—')}</code></td></tr>`
    ).join('') : '<tr><td colspan="8" class="text-center py-4 text-muted">No security events found for the selected filters.</td></tr>';
    state.eventPages = data.pages; state.eventPage = data.page;
    byId('eventCount').textContent = `${data.total} event(s) · Page ${data.page} of ${data.pages}`;
    renderPager('eventPagination', data.page, data.pages, page => { state.eventPage = page; load(); });
  }

  async function load() {
    notice('Loading security information…');
    try {
      const response = await fetch(`${app.dataset.api}?${query()}`, {credentials: 'same-origin', cache: 'no-store', headers: {Accept: 'application/json'}});
      const body = await response.json();
      if (!response.ok || !body.ok) throw new Error(body.message || 'Security monitoring is unavailable.');
      renderSummary(body.data.summary); renderPersonnel(body.data.personnel); renderEvents(body.data.events);
      notice('Security monitoring updated.', 'success');
    } catch (error) {
      notice(error.message || 'Security monitoring is unavailable.', 'danger');
      byId('personnelSecurityRows').innerHTML = '<tr><td colspan="8" class="text-center py-4 text-danger">Unable to load account security data.</td></tr>';
      byId('securityEventRows').innerHTML = '<tr><td colspan="8" class="text-center py-4 text-danger">Unable to load security events.</td></tr>';
    }
  }

  async function unlock(user) {
    if (!confirm(`Unlock ${user.full_name}?\n\nRole: ${roleLabel(user.role_key)}\nAdministrative status: ${user.administrative_state}\nSecurity status: ${user.security_state}\n\nThis clears only the authentication lock.`)) return;
    const response = await fetch(app.dataset.personnelApi, {method: 'POST', credentials: 'same-origin', cache: 'no-store',
      headers: {'Content-Type': 'application/json', 'X-CSRF-Token': app.dataset.csrf}, body: JSON.stringify({action: 'unlock', user_id: Number(user.id)})});
    const body = await response.json();
    if (!response.ok || !body.ok) throw new Error(body.message || 'Unable to unlock the account.');
    if (body.csrf_token) app.dataset.csrf = body.csrf_token;
    notice('Account security lock cleared. Administrative status was preserved.', 'success');
    await load();
  }

  byId('securityRefresh').addEventListener('click', load);
  byId('personnelFilters').addEventListener('submit', event => { event.preventDefault(); state.personnelPage = 1; load(); });
  byId('eventFilters').addEventListener('submit', event => { event.preventDefault(); state.eventPage = 1; load(); });
  byId('personnelReset').addEventListener('click', () => { byId('personnelFilters').reset(); state.personnelPage = 1; load(); });
  byId('eventReset').addEventListener('click', () => { byId('eventFilters').reset(); state.eventPage = 1; load(); });
  byId('personnelSecurityRows').addEventListener('click', event => {
    const button = event.target.closest('.unlock-account'); if (!button) return;
    const user = currentPersonnel.find(item => Number(item.id) === Number(button.dataset.id));
    if (user) unlock(user).catch(error => notice(error.message, 'danger'));
  });
  byId('securitySummaryCards').addEventListener('click', event => {
    const button = event.target.closest('.summary-filter'); if (!button) return;
    const [key, value] = button.dataset.filter.split('='); const field = byId(key === 'lock_state' ? 'lockState' : key === 'failed_attempts' ? 'failedAttempts' : 'adminState');
    if (field) field.value = value; state.personnelPage = 1; load();
  });
  const initial = new URLSearchParams(location.search);
  if (initial.get('state') === 'locked') byId('lockState').value = 'locked';
  if (initial.get('filter') === 'failed_attempts') byId('failedAttempts').value = 'present';
  load();
});
