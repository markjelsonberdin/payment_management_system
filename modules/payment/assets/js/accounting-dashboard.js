document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('dashboardFilters');
  const state = document.getElementById('dashState');
  const content = document.getElementById('dashboardContent');
  const charts = {};
  const money = value => value == null || !Number.isFinite(Number(value))
    ? 'Unavailable'
    : '₱' + Number(value).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  }[char]));
  const sectionState = (data, key) => data.section_status?.[key] || 'error';
  const businessDateTime = value => {
    if (!value) return '—';
    const parsed = new Date(String(value).replace(' ', 'T') + '+08:00');
    return Number.isNaN(parsed.getTime()) ? String(value) : parsed.toLocaleString('en-PH', { timeZone: 'Asia/Manila' });
  };

  function drawChart(id, type, labels, values, options = {}) {
    const canvas = document.getElementById(id);
    const message = document.getElementById(options.stateId);
    if (charts[id]) {
      charts[id].destroy();
      delete charts[id];
    }
    if (!canvas) return;
    const section = sectionState(options.data, options.section);
    if (section === 'error') {
      canvas.classList.add('d-none');
      if (message) message.textContent = options.errorMessage || 'This information is temporarily unavailable.';
      return;
    }
    const hasPositiveValue = values.some(value => Number(value) > 0);
    if (!labels.length || !hasPositiveValue) {
      canvas.classList.add('d-none');
      if (message) message.textContent = options.emptyMessage || 'No collections for this period.';
      return;
    }
    canvas.classList.remove('d-none');
    if (message) message.textContent = '';
    if (typeof Chart === 'undefined') {
      if (message) message.textContent = 'Chart display is unavailable.';
      canvas.classList.add('d-none');
      return;
    }
    const datasets = [{
      label: options.label || 'Collections', data: values,
      borderColor: '#2563eb', backgroundColor: type === 'line' ? 'rgba(37, 99, 235, .12)' : '#2563eb',
      borderWidth: type === 'line' ? 2 : 0, fill: type === 'line', tension: .3,
      borderRadius: type === 'bar' ? 5 : 0
    }];
    if (options.prior?.some(value => Number(value) > 0)) {
      datasets.push({ label: 'Previous Period', data: options.prior, borderColor: '#94a3b8',
        backgroundColor: 'rgba(148, 163, 184, .08)', borderDash: [5, 4], borderWidth: 2,
        fill: false, tension: .3 });
    }
    if (type === 'doughnut') {
      datasets[0].backgroundColor = options.colors;
      datasets[0].borderWidth = 0;
    }
    charts[id] = new Chart(canvas, {
      type,
      data: { labels, datasets },
      options: {
        responsive: true, maintainAspectRatio: false,
        indexAxis: options.horizontal ? 'y' : 'x',
        interaction: { mode: 'index', intersect: false },
        plugins: { legend: { display: type === 'doughnut' || datasets.length > 1, position: 'bottom' } },
        scales: type === 'doughnut' ? {} : options.horizontal
          ? { x: { beginAtZero: true, ticks: { callback: value => money(value) } } }
          : { y: { beginAtZero: true, ticks: { callback: value => money(value) } } }
      }
    });
  }

  function renderKpis(data) {
    const kpis = data.kpis || {};
    const unavailable = sectionState(data, 'kpis') === 'error';
    const cards = [
      ['ti-cash', 'Total Collected', kpis.official_academic_collections, 'Verified academic allocations · selected period', 'primary'],
      ['ti-wallet', 'Cash Payments', kpis.cash_payments, 'Walk-in cash allocations', 'success'],
      ['ti-world-dollar', 'Online Payments', kpis.live_online_collections, 'Verified Live allocations', 'info'],
      ['ti-building-bank', 'Bank Transfers', kpis.bank_transfers, 'Verified bank allocations', 'primary'],
      ['ti-report-money', 'Remaining Balance', kpis.outstanding_balance, 'AY/Semester snapshot', 'danger']
    ];
    document.getElementById('dashKpis').innerHTML = cards.map(([icon, label, amount, note, color]) =>
      '<div class="col-sm-6 col-xl"><div class="card border-0 shadow-sm h-100"><div class="card-body">' +
      '<div class="d-flex justify-content-between align-items-start gap-2"><div class="small text-muted text-uppercase fw-semibold">' + label +
      '</div><span class="rounded bg-' + color + '-subtle text-' + color + ' p-2"><i class="ti ' + icon + '" aria-hidden="true"></i></span></div>' +
      '<div class="h4 fw-bold mt-2 mb-1">' + (unavailable ? 'Unavailable' : money(amount)) + '</div><small class="text-muted">' + esc(note) + '</small></div></div></div>'
    ).join('');
  }

  function renderOnlineSummary(data) {
    const host = document.getElementById('onlineSummary');
    if (sectionState(data, 'online_summary') === 'error') {
      host.innerHTML = '<p class="text-danger mb-0">Online payment status is temporarily unavailable.</p>';
      return;
    }
    const summary = data.online_summary || {};
    const entries = [['Verified', summary.verified, 'success'], ['Pending', summary.pending, 'warning'],
      ['Failed', summary.failed, 'danger'], ['Expired', summary.expired, 'danger'],
      ['Cancelled', summary.cancelled, 'secondary'], ['Rejected', summary.rejected, 'secondary'], ['Unknown', summary.unknown, 'secondary']];
    const hasCounts = entries.some(([, count]) => Number(count) > 0);
    host.innerHTML = hasCounts ? entries.filter(([, count]) => Number(count) > 0).map(([label, count, color]) =>
      '<div class="d-flex justify-content-between align-items-center border-bottom py-2"><span><span class="badge text-bg-' + color + ' me-2">' +
      label + '</span></span><strong>' + Number(count).toLocaleString('en-PH') + '</strong></div>').join('') :
      '<div class="text-muted py-3">No online attempts for this period.</div>';
  }

  function renderRecent(data) {
    const body = document.getElementById('recentRows');
    if (sectionState(data, 'recent_collections') === 'error') {
      body.innerHTML = '<tr><td colspan="6" class="text-center text-danger py-4">Recent payments are temporarily unavailable.</td></tr>';
      return;
    }
    const rows = data.recent_collections || [];
    body.innerHTML = rows.length ? rows.slice(0, 20).map(row =>
      '<tr><td class="text-nowrap">' + esc(businessDateTime(row.verified_at)) + '</td><td>' + esc(row.full_name || row.student_number) +
      '<small class="d-block text-muted">' + esc(row.student_number || '') + '</small></td><td>' +
      esc(row.receipt_number || row.reference_number || '—') + '</td><td>' + esc(row.payment_method || row.payment_channel) +
      '</td><td class="text-end fw-semibold">' + money(row.total_applied) + '</td><td><span class="badge text-bg-success">Verified</span></td></tr>'
    ).join('') : '<tr><td colspan="6" class="text-center text-muted py-4">No official collections in this period.</td></tr>';
  }

  async function load(event) {
    event?.preventDefault();
    state.className = 'alert alert-info';
    state.textContent = 'Loading report...';
    content.classList.add('d-none');
    try {
      const response = await fetch(window.ACCOUNTING_DASHBOARD_API + '?' + new URLSearchParams(new FormData(form)), {
        credentials: 'same-origin', headers: { Accept: 'application/json' }
      });
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.error || 'Dashboard data could not be loaded. Please try again.');
      window.PaymentReportingPeriodControls?.setAvailableYears(data.filter_options?.report_years || []);
      content.classList.remove('d-none');
      document.getElementById('lastUpdated').textContent = 'Last updated: ' + new Date(data.generated_at).toLocaleString('en-PH');
      renderKpis(data);
      const trend = data.trend || {};
      const labels = trend.labels || [];
      const prior = (trend.prior || []).slice(0, labels.length);
      while (prior.length < labels.length) prior.push(0);
      drawChart('collectionTrendChart', 'line', labels, trend.current || [], { data, section: 'trend', stateId: 'trendState',
        prior: trend.comparison_label ? prior : [], label: 'Current Period', errorMessage: 'Collection trend is temporarily unavailable.',
        emptyMessage: trend.comparison_label ? 'No collections in the selected periods.' : 'No collections for this period.' });
      if (sectionState(data, 'trend') !== 'error' && labels.length && (trend.current || []).some(value => Number(value) > 0)) {
        document.getElementById('trendState').textContent = trend.comparison_label || 'No prior-period comparison for this range.';
      }
      const methods = (data.payment_methods || []).filter(item => Number(item.total) > 0);
      drawChart('paymentMethodsChart', 'doughnut', methods.map(item => item.label), methods.map(item => Number(item.total)), {
        data, section: 'payment_methods', stateId: 'methodsState', colors: ['#2563eb', '#16a34a', '#0f766e', '#64748b'],
        errorMessage: 'Payment method totals are temporarily unavailable.', emptyMessage: 'No official collections for this period.'
      });
      const categories = (data.fee_categories || []).filter(item => Number(item.total) > 0);
      drawChart('feeCategoryChart', 'bar', categories.map(item => item.label), categories.map(item => Number(item.total)), {
        data, section: 'fee_categories', stateId: 'categoryState', horizontal: true,
        errorMessage: 'Fee category totals are temporarily unavailable.', emptyMessage: 'No fee-category collections for this period.'
      });
      renderOnlineSummary(data);
      renderRecent(data);
      const hasPartial = Object.values(data.section_status || {}).includes('error');
      state.className = 'alert ' + (hasPartial ? 'alert-warning' : (data.recent_collections || []).length ? 'alert-success' : 'alert-light');
      state.textContent = hasPartial ? 'Some report sections are currently unavailable.' :
        (data.recent_collections || []).length
          ? (data.scope?.academic_year || '') + ' · ' + (data.scope?.semester || '') + ' · ' + (data.scope?.period_label || 'Report loaded')
          : 'No transactions found for this period.';
      document.getElementById('integrityAlerts').innerHTML = (data.integrity_warnings || []).map(warning =>
        '<div class="alert alert-warning"><i class="ti ti-alert-triangle me-2" aria-hidden="true"></i>' + esc(warning) + '</div>').join('');
    } catch (error) {
      state.className = 'alert alert-danger';
      state.textContent = 'Unable to load report data.';
      document.getElementById('lastUpdated').textContent = '';
      content.classList.add('d-none');
    }
  }

  form.addEventListener('submit', load);
  load();
});
