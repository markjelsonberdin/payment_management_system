document.addEventListener('DOMContentLoaded', () => {
  'use strict';
  const app = document.getElementById('paymentSecurityApp');
  if (!app) return;
  const byId = id => document.getElementById(id);
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
  const state = {personnelPage: 1, eventPage: 1, personnelPages: 1, eventPages: 1};
  let currentPersonnel = [];
  let currentEvents = [];
  const eventModal = new bootstrap.Modal(byId('securityEventModal'));

  function query() {
    const params = new URLSearchParams();
    new FormData(byId('personnelFilters')).forEach((value, key) => { if (String(value).trim()) params.set(key, String(value).trim()); });
    new FormData(byId('eventFilters')).forEach((value, key) => { if (String(value).trim()) params.set(key, String(value).trim()); });
    params.set('personnel_page', String(state.personnelPage));
    params.set('event_page', String(state.eventPage));
    if (!params.has('page_size')) params.set('page_size', '25');
    return params;
  }

  function displayLabel(value) {
    return String(value ?? '').replaceAll('_', ' ').toLowerCase().replace(/\b\w/g, char => char.toUpperCase());
  }
  function badge(value, kind) {
    return `<span class="badge mis-status-badge bg-${kind}">${esc(displayLabel(value))}</span>`;
  }
  function roleLabel(role) {
    return role === 'accounting_admin' ? 'Accounting Admin' : role === 'cashier' ? 'Cashier' : 'Accounting Officer';
  }
  function dateTime(value) {
    if (!value) return '—';
    const date = new Date(String(value).replace(' ', 'T') + '+08:00');
    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString('en-PH', {
      timeZone: 'Asia/Manila', month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit'
    });
  }
  function notice(text = '') {
    const el = byId('securityNotice');
    if (!text) {
      el.className = 'd-none';
      el.textContent = '';
      return;
    }
    el.className = 'alert alert-danger d-flex align-items-center gap-2 py-2 px-3 mb-3';
    el.innerHTML = `<i class="ti ti-alert-triangle" aria-hidden="true"></i><span>${esc(text)}</span>`;
  }

  function renderPager(id, page, pages, onChange) {
    const host = byId(id);
    const link = (number, label = String(number), current = false, ariaLabel = '') =>
      current
        ? `<li class="page-item active"><span class="page-link" aria-current="page">${label}</span></li>`
        : `<li class="page-item"><a class="page-link" href="#" data-page="${number}" aria-label="${ariaLabel || `Go to page ${number}`}">${label}</a></li>`;
    const disabled = (label, aria) => `<li class="page-item disabled"><span class="page-link" aria-disabled="true" aria-label="${aria}">${label}</span></li>`;
    let html = page > 1 ? link(page - 1, '‹ Previous', false, 'Previous page') : disabled('‹ Previous', 'Previous page');
    const start = Math.max(1, page - 2);
    const end = Math.min(pages, page + 2);
    if (start > 1) {
      html += link(1);
      if (start > 2) html += '<li class="page-item disabled" aria-hidden="true"><span class="page-link">…</span></li>';
    }
    for (let number = start; number <= end; number += 1) html += link(number, String(number), number === page);
    if (end < pages) {
      if (end < pages - 1) html += '<li class="page-item disabled" aria-hidden="true"><span class="page-link">…</span></li>';
      html += link(pages);
    }
    html += page < pages ? link(page + 1, 'Next ›', false, 'Next page') : disabled('Next ›', 'Next page');
    host.innerHTML = html;
    host.querySelectorAll('[data-page]').forEach(item => item.addEventListener('click', event => {
      event.preventDefault();
      onChange(Number(item.dataset.page));
    }));
  }

  function severityBadge(value) {
    const tone = value === 'warning' ? 'warning' : value === 'danger' || value === 'critical' ? 'danger' : 'info';
    return `<span class="security-severity is-${tone}">${esc(displayLabel(value || 'info'))}</span>`;
  }

  function renderSummary(summary) {
    const cards = [
      ['Locked Accounts', summary.locked_accounts, 'danger', 'Currently locked Payment personnel.', 'ti-lock-clock', 'Current state'],
      ['Failed Attempts', summary.accounts_with_failed_attempts, 'warning', 'Accounts with current failed counters.', 'ti-password', 'Counter state'],
      ['Disabled Accounts', summary.disabled_accounts, 'neutral', 'Administratively disabled personnel.', 'ti-user-off', 'Administrative'],
      ['Revoked Sessions', summary.recent_session_revocations, 'info', 'Persisted revocations in the last 30 days.', 'ti-logout', 'Recorded events']
    ];
    byId('securitySummaryCards').innerHTML = cards.map(([label, value, tone, description, icon, source]) =>
      `<article class="security-summary-card is-${tone}"><div class="security-summary-top"><span>${esc(label)}</span><span class="security-summary-icon"><i class="ti ${icon}" aria-hidden="true"></i></span></div>` +
      `<div class="security-summary-value">${Number(value)}<small>${Number(value) === 1 ? 'record' : 'records'}</small></div><p>${esc(description)}</p>` +
      `<div class="security-summary-footer"><span><i class="ti ti-database" aria-hidden="true"></i>${esc(source)}</span><small>Live</small></div></article>`
    ).join('');

    byId('accountSecuritySnapshot').innerHTML = [
      ['Authentication locks', summary.locked_accounts, 'ti-lock', 'danger'],
      ['Accounts with failed counters', summary.accounts_with_failed_attempts, 'ti-alert-triangle', 'warning'],
      ['Administrative disables', summary.disabled_accounts, 'ti-user-off', 'neutral'],
      ['Recent session revocations', summary.recent_session_revocations, 'ti-logout', 'info']
    ].map(([label, value, icon, tone]) =>
      `<div class="security-snapshot-row is-${tone}"><span class="security-snapshot-icon"><i class="ti ${icon}" aria-hidden="true"></i></span><div><strong>${esc(label)}</strong><span>Authoritative current or persisted state</span></div><b>${Number(value)}</b></div>`
    ).join('');
  }

  function renderPersonnel(data) {
    currentPersonnel = data.items;
    byId('personnelSecurityRows').innerHTML = data.items.length ? data.items.map(user => {
      const locked = user.security_state === 'LOCKED';
      const action = locked && app.dataset.canUnlock === '1'
        ? `<button type="button" class="btn btn-sm btn-outline-info unlock-account" data-id="${Number(user.id)}" title="Unlock account" aria-label="Unlock account for ${esc(user.full_name)}"><i class="ti ti-lock-open" aria-hidden="true"></i></button>`
        : '<span class="text-muted">—</span>';
      return `<tr><td><div class="security-personnel"><span>${esc(user.full_name).slice(0, 1).toUpperCase()}</span><div><strong>${esc(user.full_name)}</strong><small>@${esc(user.username)} · ${esc(user.email)}</small></div></div></td><td>${esc(roleLabel(user.role_key))}</td>` +
        `<td>${badge(user.administrative_state, user.administrative_state === 'ACTIVE' ? 'success' : 'secondary')}</td>` +
        `<td>${badge(user.security_state, locked ? 'danger' : user.security_state === 'LOCK_EXPIRED' ? 'warning text-dark' : 'success')}</td>` +
        `<td><strong>${Number(user.current_failed_attempts)}</strong></td><td>${dateTime(user.locked_until)}</td><td>${dateTime(user.last_security_activity)}</td><td class="text-end">${action}</td></tr>`;
    }).join('') : '<tr><td colspan="8"><div class="security-empty-state"><i class="ti ti-users-off" aria-hidden="true"></i><span>No Payment personnel match the active filters.</span></div></td></tr>';
    state.personnelPages = data.pages;
    state.personnelPage = data.page;
    byId('personnelCount').textContent = `${data.total} account(s) · Page ${data.page} of ${data.pages}`;
    renderPager('personnelPagination', data.page, data.pages, page => { state.personnelPage = page; load(); });
  }

  function recentIcon(type) {
    const icons = {
      ACCOUNT_LOCKED: 'ti-lock', LOGIN_FAILED: 'ti-login-2', PAYMENT_USER_PASSWORD_RESET: 'ti-key',
      PAYMENT_USER_UNLOCKED: 'ti-lock-open', PAYMENT_USER_ROLE_CHANGED: 'ti-user-cog',
      PAYMENT_USER_ACTIVATED: 'ti-user-check', PAYMENT_USER_DEACTIVATED: 'ti-user-off'
    };
    return icons[type] || 'ti-shield';
  }

  function renderRecentActivity(data) {
    const items = data.items.slice(0, 5);
    byId('recentSecurityActivity').innerHTML = items.length ? items.map(item =>
      `<button type="button" class="security-recent-item view-security-event" data-id="${Number(item.id)}"><span class="security-recent-icon"><i class="ti ${recentIcon(item.event_type)}" aria-hidden="true"></i></span>` +
      `<span class="security-recent-copy"><strong>${esc(item.event)}</strong><small>${esc(item.target)} · ${dateTime(item.created_at)}</small></span>${severityBadge(item.severity)}</button>`
    ).join('') : '<div class="security-empty-state"><i class="ti ti-shield-check" aria-hidden="true"></i><span>No attributable security events match the current filters.</span></div>';
  }

  function renderEvents(data) {
    currentEvents = data.items;
    byId('securityEventRows').innerHTML = data.items.length ? data.items.map(item =>
      `<tr><td><span class="security-date">${dateTime(item.created_at)}</span></td><td><div class="security-event-name"><i class="ti ${recentIcon(item.event_type)}" aria-hidden="true"></i><strong>${esc(item.event)}</strong></div></td>` +
      `<td><strong>${esc(item.target)}</strong><small>${esc(item.actor === 'System' ? 'System policy event' : 'Actor: ' + item.actor)}</small></td><td>${esc(roleLabel(item.role_key))}</td>` +
      `<td>${severityBadge(item.severity)}</td><td>${badge(item.result, item.result === 'SUCCESS' ? 'success' : item.result === 'LOCKED' ? 'danger' : 'warning text-dark')}</td>` +
      `<td class="text-end"><button type="button" class="btn btn-sm btn-light view-security-event" data-id="${Number(item.id)}" aria-label="View ${esc(item.event)} details"><i class="ti ti-eye" aria-hidden="true"></i></button></td></tr>`
    ).join('') : '<tr><td colspan="7"><div class="security-empty-state"><i class="ti ti-database-off" aria-hidden="true"></i><span>No security events match the active filters.</span></div></td></tr>';
    state.eventPages = data.pages;
    state.eventPage = data.page;
    byId('eventCount').textContent = `${data.total} event(s) · Page ${data.page} of ${data.pages}`;
    renderPager('eventPagination', data.page, data.pages, page => { state.eventPage = page; load(); });
    renderRecentActivity(data);
  }

  function openEvent(id) {
    const item = currentEvents.find(event => Number(event.id) === Number(id));
    if (!item) return;
    byId('securityEventModalTitle').textContent = item.event;
    const detail = (label, value) => `<div class="security-modal-detail"><span>${esc(label)}</span><strong>${esc(value || 'Unavailable')}</strong></div>`;
    byId('securityEventModalBody').innerHTML =
      `<div class="security-modal-status">${severityBadge(item.severity)}${badge(item.result, item.result === 'SUCCESS' ? 'success' : item.result === 'LOCKED' ? 'danger' : 'warning text-dark')}</div>` +
      `<div class="security-modal-grid">${detail('Timestamp (UTC+08:00)', dateTime(item.created_at))}${detail('Personnel account', item.target)}${detail('Payment role', roleLabel(item.role_key))}${detail('Recorded actor', item.actor)}${detail('Safe context', item.safe_context)}${detail('Correlation ID', item.correlation_id || 'Not recorded')}${detail('Origin IP', item.ip_address || 'Not recorded')}</div>`;
    eventModal.show();
  }

  async function load() {
    notice();
    app.setAttribute('aria-busy', 'true');
    byId('securityRefresh').disabled = true;
    byId('securityRefresh').querySelector('i')?.classList.add('fa-spin');
    try {
      const response = await fetch(`${app.dataset.api}?${query()}`, {
        credentials: 'same-origin', cache: 'no-store', headers: {Accept: 'application/json'}
      });
      const body = await response.json();
      if (!response.ok || !body.ok) throw new Error(body.message || 'Security monitoring is unavailable.');
      renderSummary(body.data.summary);
      renderPersonnel(body.data.personnel);
      renderEvents(body.data.events);
    } catch (error) {
      notice(error.message || 'Security monitoring is unavailable.');
      byId('securitySummaryCards').innerHTML = '<div class="security-empty-state is-error">Unable to load security summary.</div>';
      byId('accountSecuritySnapshot').innerHTML = '<div class="security-empty-state is-error">Account security state unavailable.</div>';
      byId('recentSecurityActivity').innerHTML = '<div class="security-empty-state is-error">Recent events unavailable.</div>';
      byId('personnelSecurityRows').innerHTML = '<tr><td colspan="8"><div class="security-empty-state is-error">Unable to load account security data.</div></td></tr>';
      byId('securityEventRows').innerHTML = '<tr><td colspan="7"><div class="security-empty-state is-error">Unable to load security events.</div></td></tr>';
    } finally {
      app.removeAttribute('aria-busy');
      byId('securityRefresh').disabled = false;
      byId('securityRefresh').querySelector('i')?.classList.remove('fa-spin');
    }
  }

  async function unlock(user) {
    if (!confirm(`Unlock ${user.full_name}?\n\nRole: ${roleLabel(user.role_key)}\nAdministrative status: ${user.administrative_state}\nSecurity status: ${user.security_state}\n\nThis clears only the authentication lock.`)) return;
    const response = await fetch(app.dataset.personnelApi, {
      method: 'POST', credentials: 'same-origin', cache: 'no-store',
      headers: {'Content-Type': 'application/json', 'X-CSRF-Token': app.dataset.csrf},
      body: JSON.stringify({action: 'unlock', user_id: Number(user.id)})
    });
    const body = await response.json();
    if (!response.ok || !body.ok) throw new Error(body.message || 'Unable to unlock the account.');
    if (body.csrf_token) app.dataset.csrf = body.csrf_token;
    await load();
  }

  byId('securityRefresh').addEventListener('click', load);
  byId('personnelFilters').addEventListener('submit', event => { event.preventDefault(); state.personnelPage = 1; load(); });
  byId('eventFilters').addEventListener('submit', event => { event.preventDefault(); state.eventPage = 1; load(); });
  byId('personnelReset').addEventListener('click', () => { byId('personnelFilters').reset(); state.personnelPage = 1; load(); });
  byId('eventReset').addEventListener('click', () => { byId('eventFilters').reset(); state.eventPage = 1; load(); });
  byId('personnelSecurityRows').addEventListener('click', event => {
    const button = event.target.closest('.unlock-account');
    if (!button) return;
    const user = currentPersonnel.find(item => Number(item.id) === Number(button.dataset.id));
    if (user) unlock(user).catch(error => notice(error.message));
  });
  [byId('securityEventRows'), byId('recentSecurityActivity')].forEach(host => host.addEventListener('click', event => {
    const button = event.target.closest('.view-security-event');
    if (button) openEvent(Number(button.dataset.id));
  }));

  const initial = new URLSearchParams(location.search);
  if (initial.get('state') === 'locked') byId('lockState').value = 'locked';
  if (initial.get('filter') === 'failed_attempts') byId('failedAttempts').value = 'present';
  load();
});
