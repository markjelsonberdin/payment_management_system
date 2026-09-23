<?php
require_once __DIR__ . '/../../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
requireAuth();
requirePaymentPermission('payment.online_payment_config');
$pageTitle = 'Payment Admin Dashboard';
$activeModule = 'payment';
$activePage = 'payment_admin/dashboard';
$breadcrumbs = [
    ['label' => 'Payment Management', 'url' => BASE_URL . '/modules/payment/index.php'],
    ['label' => 'Payment Admin Dashboard', 'url' => null],
];
require_once ROOT_PATH . '/includes/breadcrumbs.php';
require_once ROOT_PATH . '/includes/layout-start.php';
renderBreadcrumbs($breadcrumbs);
?>
<div class="container-fluid py-4" id="paymentAdminDashboard">
  <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
    <div>
      <h1 class="h3 mb-1"><i class="ti ti-device-mobile-check text-primary me-2" aria-hidden="true"></i>Payment Admin Dashboard</h1>
      <p class="text-muted mb-0">Online payment monitoring and status</p>
    </div>
    <div class="d-flex flex-wrap gap-2 align-items-center">
      <label for="dashboardPeriod" class="small text-muted">Period</label>
      <select id="dashboardPeriod" class="form-select" aria-label="Online payment reporting period" style="width:auto">
        <option value="today">Today</option><option value="week">Week</option><option value="month">Month</option><option value="year">Year</option>
      </select>
      <button id="refreshDashboard" class="btn btn-outline-primary" type="button"><i class="ti ti-refresh me-1" aria-hidden="true"></i>Refresh</button>
    </div>
  </div>
  <div id="dashboardNotice" class="alert alert-info" role="status" aria-live="polite">Loading dashboard data...</div>
  <small id="adminLastUpdated" class="text-muted d-block mb-3"></small>
  <div class="row g-3 mb-4" id="adminKpis" aria-live="polite"><div class="col-12"><div class="card border-0 shadow-sm"><div class="card-body text-muted">Loading online payment data...</div></div></div></div>
  <div class="row g-3 mb-4">
    <div class="col-xl-8"><div class="card border-0 shadow-sm h-100"><div class="card-body">
      <h2 class="h5"><i class="ti ti-chart-line text-primary me-2" aria-hidden="true"></i>Online Payment Trend</h2>
      <p class="small text-muted">Verified Live allocation amounts only.</p>
      <div style="height:300px"><canvas id="paymentTrendChart" aria-label="Verified Live online allocation trend"></canvas></div>
      <div id="trendState" class="small text-muted" role="status"></div>
    </div></div></div>
    <div class="col-xl-4"><div class="card border-0 shadow-sm h-100"><div class="card-body">
      <h2 class="h5"><i class="ti ti-chart-donut text-primary me-2" aria-hidden="true"></i>Online Payment Status</h2>
      <p class="small text-muted">Attempt counts by actual status, across environments. Counts are not collection amounts.</p>
      <div style="height:270px"><canvas id="onlineStatusChart" aria-label="Online payment attempt counts by status"></canvas></div>
      <div id="statusState" class="small text-muted" role="status"></div>
    </div></div></div>
  </div>
  <div class="row g-3 mb-4">
    <div class="col-xl-7"><div class="card border-0 shadow-sm h-100"><div class="card-body">
      <h2 class="h5"><i class="ti ti-credit-card text-primary me-2" aria-hidden="true"></i>Online Payment Channels</h2>
      <p class="small text-muted">Verified Live allocations grouped by recorded payment channel.</p>
      <div style="height:300px"><canvas id="channelChart" aria-label="Verified Live collections by recorded channel"></canvas></div>
      <div id="channelState" class="small text-muted" role="status"></div>
    </div></div></div>
    <div class="col-xl-5"><div class="card border-0 shadow-sm h-100"><div class="card-body">
      <h2 class="h5"><i class="ti ti-adjustments-check text-primary me-2" aria-hidden="true"></i>Gateway Readiness</h2>
      <div id="gatewayMode" class="fw-semibold">Loading...</div>
      <div id="gatewayReadiness" class="small text-muted" role="status">Checking protected readiness status...</div>
      <div id="receivingChannels" class="vstack gap-2 mt-3"></div>
      <a class="btn btn-sm btn-outline-primary mt-3" href="<?= BASE_URL ?>/modules/payment/pages/payment_admin/online-payment-integration.php"><i class="ti ti-settings me-1" aria-hidden="true"></i>Online Payment Settings</a>
    </div></div></div>
  </div>
  <div class="card border-0 shadow-sm"><div class="card-body">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
      <div><h2 class="h5 mb-1"><i class="ti ti-list-details text-primary me-2" aria-hidden="true"></i>Recent Online Transactions</h2>
        <p class="small text-muted mb-0">Verified amounts use applied allocations; other statuses show recorded attempt amount.</p></div>
      <small id="recentScope" class="text-muted"></small>
    </div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
      <thead><tr><th>Date &amp; Time</th><th>Student</th><th>Reference No.</th><th>Channel</th><th class="text-end">Amount</th><th>Status</th><th>Environment</th></tr></thead>
      <tbody id="recentActivityRows"><tr><td colspan="7" class="text-center text-muted py-4">Loading online transactions...</td></tr></tbody>
    </table></div>
  </div></div>
</div>
<script>
window.PAYMENT_ADMIN_DASHBOARD_API = <?= json_encode(BASE_URL . '/modules/payment/api/payment-admin-dashboard-data.php') ?>;
window.PAYMENT_ADMIN_GATEWAY_STATUS_API = <?= json_encode(BASE_URL . '/modules/payment/api/paymongo/status.php') ?>;
</script>
<script src="<?= BASE_URL ?>/modules/payment/assets/js/payment-admin-dashboard.js?v=3"></script>
<?php require_once ROOT_PATH . '/includes/layout-end.php'; ?>
