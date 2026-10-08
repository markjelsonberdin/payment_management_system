(() => {
  'use strict';
  const byId = id => document.getElementById(id);
  if (!byId('misOverview')) return;

  const links = window.MIS_OVERVIEW_LINKS || {};
  let requestVersion = 0;

  const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  }[char]));
  const number = value => Number.isFinite(Number(value)) ? Number(value).toLocaleString() : '—';
  const titleCase = value => String(value || '').toLowerCase().replace(/(^|[_\s-])\w/g, part => part.toUpperCase()).replaceAll('_', ' ');

  function statusPill(label, tone = 'neutral') {
    return '<span class="mis-dashboard-status is-' + tone + '"><span></span>' + esc(label) + '</span>';
  }

  function setLoading() {
    byId('adminKpis').innerHTML = Array.from({length: 4}, () =>
      '<div class="mis-dashboard-kpi is-loading"><span></span><span></span><span></span></div>').join('');
    byId('integrationHealth').innerHTML = '<div class="mis-dashboard-state"><i class="ti ti-loader-2"></i>Loading integration configuration…</div>';
    byId('recentSecurityEvents').innerHTML = '<div class="mis-dashboard-state"><i class="ti ti-loader-2"></i>Loading security events…</div>';
  }

  function kpiCard({label, value, detail, description, icon, tone, status, href}) {
    return '<article class="mis-dashboard-kpi is-' + tone + '">' +
      '<div class="mis-dashboard-kpi-top"><span>' + esc(label) + '</span><span class="mis-dashboard-kpi-icon"><i class="ti ' + esc(icon) + '" aria-hidden="true"></i></span></div>' +
      '<div class="mis-dashboard-kpi-value">' + esc(value) + (detail ? '<small>' + esc(detail) + '</small>' : '') + '</div>' +
      '<p>' + esc(description) + '</p><div class="mis-dashboard-kpi-footer">' + status +
      (href ? '<a href="' + esc(href) + '" aria-label="View ' + esc(label) + ' details"><i class="ti ti-arrow-up-right"></i></a>' : '') + '</div></article>';
  }

  function renderKpis(overview, security) {
    const accounts = overview.accounts;
    const summary = security?.summary || null;
    const alertCount = summary ? Number(summary.locked_accounts || 0) + Number(summary.accounts_with_failed_attempts || 0) : null;
    const ocr = overview.ocr;
    const paymongo = overview.paymongo;
    const ocrState = !ocr ? 'Unavailable' : (ocr.enabled ? (ocr.status === 'Technical configuration saved' ? 'Enabled' : 'Error') : 'Disabled');
    const paymongoConfigured = !!(paymongo && paymongo.api_credentials && paymongo.webhook_secret);
    const paymongoState = !paymongo ? 'Unavailable' : (paymongoConfigured ? 'Configured' : 'Not Configured');

    byId('adminKpis').innerHTML = [
      kpiCard({
        label: 'Active Personnel', value: accounts ? number(accounts.active) : 'Unavailable',
        detail: accounts ? 'of ' + number(accounts.total) + ' accounts' : '', description: 'Enabled personnel accounts.',
        icon: 'ti-id-badge-2', tone: 'primary',
        status: statusPill(accounts && Number(accounts.active) > 0 ? 'Operational' : 'No active accounts', accounts && Number(accounts.active) > 0 ? 'success' : 'neutral'),
        href: links.users
      }),
      kpiCard({
        label: 'Security Alerts', value: alertCount === null ? 'Unavailable' : number(alertCount),
        detail: alertCount === 1 ? 'condition' : 'conditions', description: 'Requires investigation.',
        icon: 'ti-shield-exclamation', tone: alertCount > 0 ? 'danger' : 'success',
        status: statusPill(alertCount > 0 ? 'Needs attention' : 'No current alerts', alertCount > 0 ? 'danger' : 'success'),
        href: links.security
      }),
      kpiCard({
        label: 'Google OCR', value: ocrState, detail: '', description: 'Receipt scanning status.',
        icon: 'ti-scan', tone: ocrState === 'Enabled' ? 'success' : 'neutral',
        status: statusPill(ocr?.status || 'Configuration unavailable', ocrState === 'Enabled' ? 'success' : 'neutral'),
        href: links.ocr
      }),
      kpiCard({
        label: 'PayMongo QR Ph', value: paymongoState, detail: paymongo?.environment ? titleCase(paymongo.environment) + ' mode' : '',
        description: 'QR Ph integration status.', icon: 'ti-qrcode', tone: paymongoConfigured ? 'success' : 'neutral',
        status: statusPill(paymongo?.status || 'Configuration unavailable', paymongoConfigured ? 'success' : 'neutral'),
        href: links.paymongo
      })
    ].join('');
  }

  function step(label, detail, state) {
    const icon = state === 'ok' ? 'ti-circle-check-filled' : state === 'error' ? 'ti-alert-circle-filled' : 'ti-circle';
    return '<div class="mis-integration-step is-' + state + '"><i class="ti ' + icon + '" aria-hidden="true"></i><div><strong>' +
      esc(label) + '</strong><span>' + esc(detail) + '</span></div></div>';
  }

  function integrationCard(title, icon, badge, tone, steps) {
    return '<article class="mis-integration-card"><div class="mis-integration-title"><div><i class="ti ' + esc(icon) +
      '" aria-hidden="true"></i><strong>' + esc(title) + '</strong></div>' + statusPill(badge, tone) +
      '</div><div class="mis-integration-steps">' + steps.join('') + '</div></article>';
  }

  function renderIntegrations(data) {
    const paymongo = data.paymongo;
    const ocr = data.ocr;
    const payConfigured = !!(paymongo && paymongo.api_credentials && paymongo.webhook_secret);
    const ocrConfigured = !!(ocr && ocr.status === 'Technical configuration saved');
    byId('integrationHealth').innerHTML =
      integrationCard('PayMongo QR Ph', 'ti-qrcode', payConfigured ? 'Configured' : 'Incomplete', payConfigured ? 'success' : 'warning', [
        step('Configuration', paymongo?.environment ? titleCase(paymongo.environment) + ' mode saved' : 'Environment unavailable', paymongo?.environment ? 'ok' : 'pending'),
        step('API credentials', paymongo?.api_credentials ? 'Protected credential present' : 'Credential missing', paymongo?.api_credentials ? 'ok' : 'error'),
        step('Webhook secret', paymongo?.webhook_secret ? 'Protected secret present' : 'Secret missing', paymongo?.webhook_secret ? 'ok' : 'error'),
        step('Provider connectivity', 'Not checked by this dashboard', 'pending')
      ]) +
      integrationCard('Google OCR', 'ti-scan', ocrConfigured && ocr?.enabled ? 'Enabled' : (ocrConfigured ? 'Disabled' : 'Incomplete'),
        ocrConfigured && ocr?.enabled ? 'success' : 'warning', [
        step('Configuration', ocr?.status || 'Configuration unavailable', ocrConfigured ? 'ok' : 'error'),
        step('Receipt scanning', ocr?.enabled ? 'Enabled by configuration' : 'Disabled by configuration', ocr?.enabled ? 'ok' : 'pending'),
        step('Provider authentication', 'Not checked by this dashboard', 'pending'),
        step('Live OCR processing', 'Unverified', 'pending')
      ]);
  }

  function eventIcon(type) {
    const map = {LOGIN_FAILED: 'ti-login-2', ACCOUNT_LOCKED: 'ti-lock', PAYMENT_USER_UNLOCKED: 'ti-lock-open',
      PAYMENT_USER_PASSWORD_RESET: 'ti-key', PAYMENT_USER_DEACTIVATED: 'ti-user-off',
      PAYMENT_USER_ACTIVATED: 'ti-user-check', PAYMENT_USER_ROLE_CHANGED: 'ti-user-cog'};
    return map[type] || 'ti-shield';
  }

  function formatDate(value) {
    if (!value) return 'Time unavailable';
    const date = new Date(String(value).replace(' ', 'T') + '+08:00');
    return Number.isNaN(date.getTime()) ? 'Time unavailable' : date.toLocaleString('en-PH', {
      month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit'
    });
  }

  function renderSecurity(security) {
    const target = byId('recentSecurityEvents');
    if (!security) {
      target.innerHTML = '<div class="mis-dashboard-state is-warning"><i class="ti ti-shield-off"></i>Security events are unavailable or not authorized.</div>';
      return;
    }
    const items = (security.events?.items || []).slice(0, 5);
    if (!items.length) {
      target.innerHTML = '<div class="mis-dashboard-state"><i class="ti ti-shield-check"></i>No recent Payment personnel security events.</div>';
      return;
    }
    target.innerHTML = items.map(item => '<div class="mis-security-event"><span class="mis-security-event-icon"><i class="ti ' +
      eventIcon(item.event_type) + '" aria-hidden="true"></i></span><div class="mis-security-event-copy"><strong>' +
      esc(item.event) + '</strong><span>' + esc(item.target || item.actor || 'Payment personnel') + ' · ' +
      esc(formatDate(item.created_at)) + '</span></div><div class="mis-security-event-meta">' +
      '<span class="mis-event-severity is-' + esc(item.severity || 'info') + '">' + esc(titleCase(item.severity || 'info')) +
      '</span><small>' + esc(titleCase(item.result || 'unknown')) + '</small></div></div>').join('');
  }

  async function fetchJson(url) {
    const response = await fetch(url, {credentials: 'same-origin', cache: 'no-store', headers: {Accept: 'application/json'}});
    const body = await response.json();
    if (!response.ok || !body.ok) throw new Error(body.message || 'Unavailable');
    return body.data || body;
  }

  async function load() {
    const current = ++requestVersion;
    setLoading();
    byId('dashboardNotice').className = 'd-none';

    const [overviewResult, securityResult] = await Promise.allSettled([
      fetchJson(window.MIS_OVERVIEW_API),
      fetchJson(window.MIS_SECURITY_API + '?page_size=25&event_page=1')
    ]);
    if (current !== requestVersion) return;

    if (overviewResult.status === 'rejected') {
      byId('dashboardNotice').className = 'alert alert-danger';
      byId('dashboardNotice').textContent = 'MIS Admin dashboard is temporarily unavailable. Please refresh or sign in again.';
      byId('adminKpis').innerHTML = '<div class="mis-dashboard-state is-error">Dashboard summary unavailable.</div>';
      byId('integrationHealth').innerHTML = '<div class="mis-dashboard-state is-error">Integration configuration unavailable.</div>';
      renderSecurity(securityResult.status === 'fulfilled' ? securityResult.value : null);
    } else {
      const overview = overviewResult.value;
      const security = securityResult.status === 'fulfilled' ? securityResult.value : null;
      renderKpis(overview, security);
      renderIntegrations(overview);
      renderSecurity(security);
      const partial = Object.values(overview.section_status || {}).includes('error') || !security;
      byId('dashboardNotice').className = partial ? 'alert alert-warning' : 'd-none';
      byId('dashboardNotice').textContent = partial ? 'Some dashboard information is currently unavailable.' : '';
    }
  }

  load();
})();
