(() => {
  'use strict';
  const byId = id => document.getElementById(id);
  if (!byId('misOverview')) return;
  let version = 0;
  const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  }[char]));
  const count = value => Number.isFinite(Number(value)) ? Number(value).toLocaleString() : 'Unavailable';
  const links = window.MIS_OVERVIEW_LINKS || {};
  function clear() {
    byId('adminKpis').replaceChildren();
    byId('roleCounts').textContent = 'Account counts unavailable.';
    byId('securitySummary').textContent = 'Security counters unavailable.';
    byId('integrationSummary').textContent = 'Integration configuration unavailable.';
    byId('recentActivityRows').innerHTML = '<tr><td colspan="2">Administrative activity unavailable.</td></tr>';
    byId('adminLastUpdated').textContent = '';
  }
  function render(data) {
    const accounts = data.accounts;
    byId('adminKpis').innerHTML = [
      ['Payment Personnel', 'total', links.users], ['Active Personnel', 'active', links.users],
      ['Inactive Personnel', 'inactive', links.users], ['Locked Accounts', 'locked', (links.security || '') + '?state=locked']
    ].map(([label, key, href]) => '<div class="col-sm-6 col-xl-3"><div class="card mis-card"><div class="card-body">' +
      '<div class="mis-card-label">' + label + '</div><div class="mis-card-value">' +
      (accounts ? count(accounts[key]) : 'Unavailable') + '</div><a class="small stretched-link" href="' + esc(href || '#') + '">View details</a></div></div></div>').join('');
    if (accounts) {
      byId('roleCounts').innerHTML = [['Accounting Admin', 'accounting_admin'], ['Accounting Officer', 'accounting_officer'], ['Cashier', 'cashier']]
        .map(([label, key]) => '<div class="d-flex justify-content-between border-bottom py-2"><span>' + label + '</span><strong>' + count(accounts.by_role[key]) + '</strong></div>').join('');
      byId('securitySummary').textContent = 'Current failed-login attempt counters: ' + count(accounts.failed_attempts);
    }
    const gateway = data.paymongo;
    byId('integrationSummary').innerHTML = '<div class="mb-2"><strong>PayMongo</strong><div>' +
      (gateway ? esc(gateway.status) + (gateway.environment ? ' · ' + esc(gateway.environment) + ' environment' : '') +
        '<div class="small text-muted">API credentials: ' + (gateway.api_credentials ? 'Present' : 'Missing') +
        ' · Webhook secret: ' + (gateway.webhook_secret ? 'Present' : 'Missing') + '</div>' : 'Configuration unavailable') +
      '</div></div><div class="mb-2"><strong>Google OCR</strong><div>' + esc(data.integrations.ocr) +
      '</div></div>';
    const activity = data.activity;
    byId('recentActivityRows').innerHTML = activity === null
      ? '<tr><td colspan="2">Administrative activity unavailable.</td></tr>'
      : activity.length ? activity.map(row => '<tr><td>' + esc(row.created_at) + ' (UTC+08:00)</td><td>' + esc(row.event) + '</td></tr>').join('')
      : '<tr><td colspan="2">No recorded MIS personnel activity.</td></tr>';
    const partial = Object.values(data.section_status).includes('error');
    byId('dashboardNotice').className = partial ? 'alert alert-warning' : 'd-none';
    byId('dashboardNotice').textContent = partial ? 'Some dashboard information is currently unavailable.' : '';
    byId('adminLastUpdated').textContent = 'Last updated: ' + new Date(data.generated_at).toLocaleString();
  }
  async function load() {
    const current = ++version;
    clear();
    byId('dashboardNotice').className = 'd-none';
    byId('dashboardNotice').textContent = '';
    try {
      const response = await fetch(window.MIS_OVERVIEW_API, {credentials: 'same-origin', cache: 'no-store', headers: {Accept: 'application/json'}});
      const data = await response.json();
      if (current !== version) return;
      if (!response.ok || !data.ok) throw new Error('Unavailable');
      render(data);
    } catch {
      if (current !== version) return;
      clear();
      byId('dashboardNotice').className = 'alert alert-danger';
      byId('dashboardNotice').textContent = 'MIS Overview is temporarily unavailable. Please refresh or sign in again.';
    }
  }
  byId('refreshDashboard').addEventListener('click', load);
  load();
})();
