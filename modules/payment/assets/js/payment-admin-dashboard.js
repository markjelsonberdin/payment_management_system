(() => {
  'use strict';

  const byId = id => document.getElementById(id);
  const charts = {};
  const money = value => value == null || !Number.isFinite(Number(value))
    ? 'Unavailable'
    : '₱' + Number(value).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const count = value => value == null || !Number.isFinite(Number(value))
    ? 'Unavailable'
    : Number(value).toLocaleString('en-PH');
  const esc = value => String(value ?? '—').replace(/[&<>"']/g, char => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  }[char]));
  const sectionState = (data, key) => data.section_status?.[key] || 'error';

  function showChartState(id, stateId, message) {
    if (charts[id]) {
      charts[id].destroy();
      delete charts[id];
    }
    const canvas = byId(id);
    if (canvas) canvas.classList.add('d-none');
    byId(stateId).textContent = message;
  }

  function drawChart(id, type, labels, values, options = {}) {
    if (sectionState(options.data, options.section) === 'error') {
      showChartState(id, options.stateId, options.errorMessage);
      return;
    }
    if (!labels.length || !values.some(value => Number(value) > 0)) {
      showChartState(id, options.stateId, options.emptyMessage);
      return;
    }
    const canvas = byId(id);
    if (!canvas || typeof Chart === 'undefined') {
      showChartState(id, options.stateId, 'Chart display is unavailable.');
      return;
    }
    if (charts[id]) charts[id].destroy();
    canvas.classList.remove('d-none');
    byId(options.stateId).textContent = '';
    charts[id] = new Chart(canvas, {
      type,
      data: { labels, datasets: [{
        label: options.label || 'Online allocations', data: values,
        backgroundColor: options.colors || (type === 'line' ? 'rgba(37, 99, 235, .12)' : '#2563eb'),
        borderColor: type === 'line' ? '#2563eb' : (options.colors || '#2563eb'),
        borderWidth: type === 'line' ? 2 : 0, fill: type === 'line', tension: .3, borderRadius: type === 'bar' ? 5 : 0
      }] },
      options: {
        responsive: true, maintainAspectRatio: false, indexAxis: options.horizontal ? 'y' : 'x',
        interaction: { mode: 'index', intersect: false },
        plugins: { legend: { display: type === 'doughnut', position: 'bottom' } },
        scales: type === 'doughnut' ? {} : options.horizontal
          ? { x: { beginAtZero: true, ticks: { callback: value => money(value) } } }
          : { y: { beginAtZero: true, ticks: { callback: value => money(value) } } }
      }
    });
  }

  function renderKpis(data) {
    const kpis = data.kpis || {};
    const unavailable = sectionState(data, 'kpis') === 'error';
    const statusText = unavailable ? 'Status unavailable' : 'Failed ' + count(kpis.failed_count) + ' · Expired ' + count(kpis.expired_count);
    const cards = [
      ['ti-list-numbers', 'Online Payments', count(kpis.online_count), 'All online attempts · every environment', 'primary'],
      ['ti-circle-check', 'Verified Payments', count(kpis.verified_count), 'Live online attempts only', 'success'],
      ['ti-hourglass', 'Pending Payments', count(kpis.pending_count), 'Actual pending attempts', 'warning'],
      ['ti-alert-circle', 'Failed / Expired', unavailable ? 'Unavailable' : 'Failed ' + count(kpis.failed_count) + ' · Expired ' + count(kpis.expired_count), statusText, 'danger'],
      ['ti-currency-peso', 'Online Amount Collected', unavailable ? 'Unavailable' : money(kpis.online_amount), 'Verified Live allocation totals', 'primary']
    ];
    byId('adminKpis').innerHTML = cards.map(([icon, label, value, note, color]) =>
      '<div class="col-sm-6 col-xl"><div class="card border-0 shadow-sm h-100"><div class="card-body">' +
      '<div class="d-flex justify-content-between align-items-start gap-2"><div class="small text-muted text-uppercase fw-semibold">' + label +
      '</div><span class="rounded bg-' + color + '-subtle text-' + color + ' p-2"><i class="ti ' + icon + '" aria-hidden="true"></i></span></div>' +
      '<div class="h5 fw-bold mt-2 mb-1">' + value + '</div><small class="text-muted">' + note + '</small></div></div></div>'
    ).join('');
  }

  function renderTrend(data) {
    const trend = data.trend || {};
    drawChart('paymentTrendChart', 'line', trend.labels || [], trend.current || [], {
      data, section: 'trend', stateId: 'trendState', label: 'Verified Live allocations',
      errorMessage: 'Trend data is temporarily unavailable.', emptyMessage: 'No verified Live online collections for this period.'
    });
  }

  function renderStatuses(data) {
    const rows = data.online_status || [];
    const palette = { Verified: '#16a34a', Pending: '#f59e0b', Failed: '#dc2626', Expired: '#b91c1c',
      Cancelled: '#64748b', Rejected: '#7f1d1d', Unknown: '#94a3b8' };
    drawChart('onlineStatusChart', 'doughnut', rows.map(row => row.status), rows.map(row => Number(row.count)), {
      data, section: 'online_status', stateId: 'statusState', colors: rows.map(row => palette[row.status] || palette.Unknown),
      errorMessage: 'Online payment status is temporarily unavailable.', emptyMessage: 'No online attempts for this period.'
    });
  }

  function renderChannels(data) {
    const channels = (data.channel_breakdown || []).filter(row => Number(row.amount) > 0);
    drawChart('channelChart', 'bar', channels.map(row => row.label), channels.map(row => Number(row.amount)), {
      data, section: 'channel_breakdown', stateId: 'channelState', horizontal: true,
      errorMessage: 'Channel totals are temporarily unavailable.', emptyMessage: 'No verified Live channel collections for this period.'
    });
  }

  function renderRecent(data) {
    const body = byId('recentActivityRows');
    if (sectionState(data, 'recent_activity') === 'error') {
      body.innerHTML = '<tr><td colspan="7" class="text-center text-danger py-4">Recent online transactions are temporarily unavailable.</td></tr>';
      return;
    }
    const rows = data.recent_activity || [];
    body.innerHTML = rows.length ? rows.map(row => {
      const timestamp = row.payment_status === 'Verified' ? row.verified_at : row.created_at;
      const date = timestamp ? new Date(String(timestamp).replace(' ', 'T') + '+08:00').toLocaleString('en-PH') : '—';
      const amount = row.payment_status === 'Verified' ? money(row.applied_amount) : money(row.attempted_amount);
      const statusClass = row.payment_status === 'Verified' ? 'success' : row.payment_status === 'Pending' ? 'warning' :
        ['Failed', 'Expired', 'Rejected'].includes(row.payment_status) ? 'danger' : 'secondary';
      const environment = row.gateway_environment === 'live' ? 'Live' : row.gateway_environment === 'test' ? 'Test' : 'Unknown';
      return '<tr><td class="text-nowrap">' + esc(date) + '</td><td>' + esc(row.full_name || row.student_number) +
        '<small class="d-block text-muted">' + esc(row.student_number || '') + '</small></td><td>' +
        esc(row.receipt_number || row.reference_number || '—') + '</td><td>' + esc(row.payment_channel || 'Unknown') +
        '</td><td class="text-end fw-semibold">' + amount + '</td><td><span class="badge text-bg-' + statusClass + '">' +
        esc(row.payment_status) + '</span></td><td><span class="badge text-bg-' + (environment === 'Live' ? 'success' : 'secondary') + '">' + environment + '</span></td></tr>';
    }).join('') : '<tr><td colspan="7" class="text-center text-muted py-4">No online transactions for this period.</td></tr>';
  }

  function renderGateway(data, gatewayData = null) {
    const host = byId('receivingChannels');
    host.replaceChildren();
    if (sectionState(data, 'configuration') === 'error') {
      byId('gatewayMode').textContent = 'Gateway configuration unavailable';
      byId('gatewayReadiness').textContent = 'Protected readiness information is temporarily unavailable.';
      host.textContent = 'Channel configuration unavailable.';
      return;
    }
    const config = data.configuration || {};
    byId('gatewayMode').textContent = (config.gateway_mode || 'Unknown').toUpperCase() + ' environment';
    if (gatewayData) {
      byId('gatewayReadiness').textContent = 'Gateway ' + (gatewayData.gateway?.status || 'status unavailable') +
        ' · Webhook ' + (gatewayData.webhook?.status || 'unknown') + ' · API ' +
        (gatewayData.api?.connected ? 'connected' : gatewayData.api?.configured ? 'not connected' : 'not configured');
    } else {
      byId('gatewayReadiness').textContent = 'Protected gateway readiness is temporarily unavailable.';
    }
    (config.channels || []).forEach(channel => {
      const line = document.createElement('div');
      line.className = 'd-flex justify-content-between align-items-center border rounded p-2 gap-2';
      const label = document.createElement('span');
      label.textContent = channel.name;
      const value = document.createElement('span');
      value.className = 'badge ' + (channel.available_by_config ? 'text-bg-success' : 'text-bg-secondary');
      value.textContent = !channel.policy_allowed ? 'Unavailable under current policy' : channel.configured ? 'Configured' : 'Disabled';
      line.append(label, value);
      host.append(line);
    });
  }

  function render(data) {
    renderKpis(data);
    renderTrend(data);
    renderStatuses(data);
    renderChannels(data);
    renderRecent(data);
    byId('recentScope').textContent = (data.scope?.timezone || '') + ' · ' + (data.scope?.start_at || '') +
      ' ≤ timestamp < ' + (data.scope?.end_exclusive || '');
    const hasErrors = Object.values(data.section_status || {}).includes('error');
    byId('dashboardNotice').className = 'alert ' + (hasErrors ? 'alert-warning' : 'alert-success');
    byId('dashboardNotice').textContent = hasErrors ? 'Some dashboard information is temporarily unavailable.' :
      'Online payment data loaded · ' + data.scope.timezone + ' · ' + data.scope.start_at + ' ≤ timestamp < ' + data.scope.end_exclusive;
    byId('adminLastUpdated').textContent = 'Last updated: ' + new Date(data.generated_at).toLocaleString('en-PH');
  }

  async function load() {
    const notice = byId('dashboardNotice');
    notice.className = 'alert alert-info';
    notice.textContent = 'Loading dashboard data...';
    try {
      const url = new URL(window.PAYMENT_ADMIN_DASHBOARD_API, location.origin);
      url.searchParams.set('period', byId('dashboardPeriod').value);
      const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.message || 'Dashboard data could not be loaded. Please try again.');
      render(data);
      try {
        const gatewayResponse = await fetch(window.PAYMENT_ADMIN_GATEWAY_STATUS_API, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        const gatewayData = await gatewayResponse.json();
        if (!gatewayResponse.ok) throw new Error('Gateway status unavailable');
        renderGateway(data, gatewayData);
      } catch {
        renderGateway(data);
      }
    } catch (error) {
      notice.className = 'alert alert-danger';
      notice.textContent = error.message || 'Dashboard data could not be loaded. Please try again.';
      byId('adminLastUpdated').textContent = '';
      byId('adminKpis').innerHTML = '<div class="col-12"><div class="card border-0 shadow-sm"><div class="card-body text-danger">Dashboard data unavailable.</div></div></div>';
      ['paymentTrendChart', 'onlineStatusChart', 'channelChart'].forEach(id => {
        if (charts[id]) { charts[id].destroy(); delete charts[id]; }
        byId(id).classList.add('d-none');
      });
      byId('trendState').textContent = 'Trend data unavailable.';
      byId('statusState').textContent = 'Status data unavailable.';
      byId('channelState').textContent = 'Channel data unavailable.';
      byId('recentActivityRows').innerHTML = '<tr><td colspan="7" class="text-center text-danger py-4">Online transactions unavailable.</td></tr>';
      byId('gatewayMode').textContent = 'Gateway readiness unavailable.';
      byId('gatewayReadiness').textContent = 'Unable to load protected readiness information.';
      byId('receivingChannels').replaceChildren();
    }
  }

  byId('dashboardPeriod').addEventListener('change', load);
  byId('refreshDashboard').addEventListener('click', load);
  load();
})();
