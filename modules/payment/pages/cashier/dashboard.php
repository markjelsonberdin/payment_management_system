<?php
require_once __DIR__ . '/../../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
requireAuth();
requirePaymentPermission('payment.cashier_dashboard');

$pageTitle = 'Cashier Dashboard';
$activeModule = 'payment';
$activePage = 'cashier/dashboard';
$breadcrumbs = [
    ['label' => 'Payment Management', 'url' => BASE_URL . '/modules/payment/index.php'],
    ['label' => 'Cashier Dashboard', 'url' => null],
];
require_once ROOT_PATH . '/includes/breadcrumbs.php';
require_once ROOT_PATH . '/includes/layout-start.php';
?>
<?php renderBreadcrumbs($breadcrumbs); ?>
<div class="container-fluid py-4 cashier-dashboard">
  <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
      <h2 class="fw-bolder mb-1"><i class="ti ti-cash-register text-primary me-2"></i>Cashier Dashboard</h2>
      <p class="text-muted mb-0">Cash collections and school sales processed by <strong><?= htmlspecialchars(getCurrentUserName()) ?></strong>.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
      <a href="<?= BASE_URL ?>/modules/payment/pages/cashier/payment-collection-portal.php" class="btn btn-primary"><i class="ti ti-user-search me-1"></i> Find Student / Collect</a>
      <a href="<?= BASE_URL ?>/modules/payment/pages/cashier/school-sales.php" class="btn btn-outline-primary"><i class="ti ti-shopping-cart me-1"></i> New School Sale</a>
      <a href="<?= BASE_URL ?>/modules/payment/pages/cashier/walk-in-transaction-history.php" class="btn btn-outline-secondary"><i class="ti ti-history me-1"></i> Transaction History</a>
    </div>
  </div>

  <div class="card border-0 shadow-sm mb-4">
    <div class="card-body d-flex flex-wrap align-items-center gap-3">
      <span class="fw-semibold"><i class="ti ti-calendar-time me-1 text-primary"></i> Collection Period</span>
      <div class="btn-group" role="group" aria-label="Collection period">
        <button class="btn btn-outline-primary period-btn active" data-period="today">Today</button>
        <button class="btn btn-outline-primary period-btn" data-period="week">Weekly</button>
        <button class="btn btn-outline-primary period-btn" data-period="month">Monthly</button>
        <button class="btn btn-outline-primary period-btn" data-period="year">Yearly</button>
      </div>
      <small id="periodLabel" class="text-muted ms-md-auto"></small>
    </div>
  </div>

  <div id="dashboardAlert" class="alert alert-danger d-none"></div>
  <div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl"><div class="card h-100 border-0 shadow-sm border-start border-4 border-primary"><div class="card-body"><small class="text-muted text-uppercase fw-bold">Academic Cash Collection</small><h3 id="academicKpi" class="mt-2 mb-0">PHP 0.00</h3><small class="text-muted">Verified walk-in academic payments</small></div></div></div>
    <div class="col-sm-6 col-xl"><div class="card h-100 border-0 shadow-sm border-start border-4" style="border-color:#0891b2!important"><div class="card-body"><small class="text-muted text-uppercase fw-bold">School Sales</small><h3 id="salesKpi" class="mt-2 mb-0">PHP 0.00</h3><small class="text-muted">Direct school-goods cash sales</small></div></div></div>
    <div class="col-sm-6 col-xl"><div class="card h-100 border-0 shadow-sm border-start border-4 border-success"><div class="card-body"><small class="text-muted text-uppercase fw-bold">Total Cash Received</small><h3 id="totalKpi" class="mt-2 mb-0">PHP 0.00</h3><small id="comparisonKpi" class="text-muted">Compared with prior period</small></div></div></div>
    <div class="col-sm-6 col-xl"><div class="card h-100 border-0 shadow-sm border-start border-4 border-dark"><div class="card-body"><small class="text-muted text-uppercase fw-bold">Transactions</small><h3 id="transactionKpi" class="mt-2 mb-0">0</h3><small class="text-muted">Successful cash transactions</small></div></div></div>
    <div class="col-sm-6 col-xl"><div class="card h-100 border-0 shadow-sm border-start border-4 border-warning"><div class="card-body"><small class="text-muted text-uppercase fw-bold">Receipts Issued</small><h3 id="receiptKpi" class="mt-2 mb-0">0</h3><small class="text-muted">One receipt per completed transaction</small></div></div></div>
  </div>

  <div class="row g-4 mb-4">
    <div class="col-xl-7"><div class="card border-0 shadow-sm h-100"><div class="card-body"><h5 class="mb-1">Cash Collection Trend</h5><p class="small text-muted">Current selected period versus the immediately preceding comparable period.</p><div style="height:300px"><canvas id="trendChart"></canvas></div></div></div></div>
    <div class="col-xl-5"><div class="card border-0 shadow-sm h-100"><div class="card-body"><h5 class="mb-1">Academic Collections by Fee Category</h5><p class="small text-muted">Verified cash allocations only; school sales are excluded.</p><div style="height:300px"><canvas id="academicCategoryChart"></canvas></div></div></div></div>
  </div>

  <div class="row g-4 mb-4">
    <div class="col-xl-5"><div class="card border-0 shadow-sm h-100"><div class="card-body"><h5 class="mb-1">School Sales Summary</h5><p class="small text-muted">Separate from academic balances and academic collection efficiency.</p><div style="height:260px"><canvas id="salesCategoryChart"></canvas></div></div></div></div>
    <div class="col-xl-7"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="d-flex justify-content-between align-items-center"><div><h5 class="mb-1">Inventory Status</h5><p class="small text-muted mb-0">Stock monitoring is not configured yet.</p></div><span class="badge bg-secondary">Catalog only</span></div><div class="alert alert-light border mt-3 mb-0 small"><i class="ti ti-info-circle me-1"></i> Current catalog supports approved items and prices. Quantity, variants, and low-stock alerts will appear only after an approved inventory design is added.</div></div></div></div>
  </div>

  <div class="card border-0 shadow-sm">
    <div class="card-body">
      <div class="d-flex flex-wrap justify-content-between gap-2 align-items-center mb-3"><div><h5 class="mb-1">Recent Walk-in Transactions</h5><p class="small text-muted mb-0">Only transactions processed by your account.</p></div><a href="<?= BASE_URL ?>/modules/payment/pages/cashier/walk-in-transaction-history.php" class="btn btn-sm btn-outline-primary">View All</a></div>
      <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Date / Time</th><th>OR / Reference</th><th>Student</th><th>Type</th><th>Category / Items</th><th class="text-end">Amount</th><th class="text-center">Receipt</th></tr></thead><tbody id="recentTransactions"><tr><td colspan="7" class="text-center text-muted py-4">Loading transactions…</td></tr></tbody></table></div>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(() => {
  const base = '<?= BASE_URL ?>';
  const money = value => 'PHP ' + Number(value || 0).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;', "'":'&#039;'}[char]));
  let period = 'today', trendChart, academicChart, salesChart;

  const showChart = (chart, id, config) => { if (chart) chart.destroy(); return new Chart(document.getElementById(id), config); };
  const emptyChart = (items, label) => items.length ? items : [{label, total: 0}];

  async function loadDashboard() {
    const alert = document.getElementById('dashboardAlert');
    alert.classList.add('d-none');
    try {
      const response = await fetch(base + '/modules/payment/api/cashier-dashboard-data.php?period=' + encodeURIComponent(period), {credentials:'same-origin'});
      const data = await response.json();
      if (!response.ok || data.error) throw new Error(data.error || 'Unable to load dashboard data.');

      document.getElementById('periodLabel').textContent = data.range.start + ' to ' + data.range.end;
      document.getElementById('academicKpi').textContent = money(data.kpis.academic);
      document.getElementById('salesKpi').textContent = money(data.kpis.sales);
      document.getElementById('totalKpi').textContent = money(data.kpis.total_cash);
      document.getElementById('transactionKpi').textContent = data.kpis.transactions.toLocaleString();
      document.getElementById('receiptKpi').textContent = data.kpis.receipts.toLocaleString();
      const prior = Number(data.comparison.prior_total || 0), current = Number(data.comparison.current_total || 0);
      document.getElementById('comparisonKpi').textContent = prior > 0 ? ((current >= prior ? '↑ ' : '↓ ') + Math.abs((current-prior)/prior*100).toFixed(1) + '% vs prior period') : 'No prior-period cash data';

      trendChart = showChart(trendChart, 'trendChart', {type:'line', data:{labels:data.trend.current.labels, datasets:[
        {label:'Current period', data:data.trend.current.values, borderColor:'#2563EB', backgroundColor:'rgba(37,99,235,.12)', fill:true, tension:.32, borderWidth:2},
        {label:'Previous period', data:data.trend.prior.values, borderColor:'#94A3B8', borderDash:[6,5], tension:.32, borderWidth:2, fill:false}
      ]}, options:{responsive:true, maintainAspectRatio:false, interaction:{mode:'index',intersect:false}, scales:{y:{beginAtZero:true,ticks:{callback:v=>'PHP '+Number(v).toLocaleString()}}}}});

      const academic = emptyChart(data.academic_categories, 'No academic collections');
      academicChart = showChart(academicChart, 'academicCategoryChart', {type:'bar', data:{labels:academic.map(row=>row.label), datasets:[{label:'Academic cash collection', data:academic.map(row=>row.total), backgroundColor:'#2563EB', borderRadius:5}]}, options:{indexAxis:'y', responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}}, scales:{x:{beginAtZero:true,ticks:{callback:v=>'PHP '+Number(v).toLocaleString()}}}}});

      const sales = emptyChart(data.sale_categories, 'No school sales');
      salesChart = showChart(salesChart, 'salesCategoryChart', {type:'bar', data:{labels:sales.map(row=>row.label), datasets:[{label:'School sales', data:sales.map(row=>row.total), backgroundColor:'#0891B2', borderRadius:5}]}, options:{indexAxis:'y', responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}}, scales:{x:{beginAtZero:true,ticks:{callback:v=>'PHP '+Number(v).toLocaleString()}}}}});

      const recent = document.getElementById('recentTransactions');
      if (!data.recent_transactions.length) {
        recent.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">No successful cashier transactions for this period.</td></tr>';
      } else {
        recent.innerHTML = data.recent_transactions.map(row => {
          const receiptUrl = row.transaction_type === 'School Sale'
            ? base + '/modules/payment/pages/cashier/print-receipt.php?cash_sale_id=' + encodeURIComponent(row.record_id)
            : base + '/modules/payment/pages/cashier/print-receipt.php?payment_id=' + encodeURIComponent(row.record_id);
          return '<tr><td>' + escape(row.happened_at) + '</td><td class="fw-semibold">' + escape(row.receipt) + '</td><td>' + escape(row.full_name) + '<small class="d-block text-muted">' + escape(row.student_number) + '</small></td><td><span class="badge ' + (row.transaction_type === 'School Sale' ? 'bg-info' : 'bg-primary') + '">' + escape(row.transaction_type) + '</span></td><td class="small">' + escape(row.details || '—') + '</td><td class="text-end fw-semibold">' + money(row.total) + '</td><td class="text-center"><a class="btn btn-sm btn-outline-primary" target="_blank" href="' + receiptUrl + '"><i class="ti ti-printer"></i></a></td></tr>';
        }).join('');
      }
    } catch (error) {
      alert.textContent = error.message;
      alert.classList.remove('d-none');
    }
  }

  document.querySelectorAll('.period-btn').forEach(button => button.addEventListener('click', () => {
    period = button.dataset.period;
    document.querySelectorAll('.period-btn').forEach(item => item.classList.toggle('active', item === button));
    loadDashboard();
  }));
  loadDashboard();
})();
</script>