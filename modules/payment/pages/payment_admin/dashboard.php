<?php
require_once __DIR__ . '/../../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
requireAuth();
requirePaymentPermission('payment.online_payment_config');
$pageTitle = 'Payment Admin Dashboard';
$activeModule = 'payment';
$activePage = 'payment_admin/dashboard';
$breadcrumbs = [['label'=>'Payment Management','url'=>BASE_URL . '/modules/payment/index.php'],['label'=>'Payment Admin Dashboard','url'=>null]];
$canManageCatalog = paymentRoleAllowsPermission(getCurrentUserRoleKey(), 'payment.school_sales_catalog') && userCanAccessModule('payment.school_sales_catalog');
require_once ROOT_PATH . '/includes/breadcrumbs.php';
require_once ROOT_PATH . '/includes/layout-start.php';
?>
<?php renderBreadcrumbs($breadcrumbs); ?>
<div class="container-fluid py-4" id="paymentAdminDashboard">
  <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div><h2 class="fw-bolder mb-1">Payment Operations</h2><p class="text-muted mb-0">System-wide payment activity, receiving setup, and integration readiness. Financial totals use verified allocations.</p></div>
    <div class="d-flex flex-wrap align-items-center gap-2">
      <label for="dashboardPeriod" class="form-label mb-0 small fw-semibold">Period</label>
      <select id="dashboardPeriod" class="form-select" aria-label="Dashboard reporting period" style="width:auto"><option value="today">Today</option><option value="week">This week</option><option value="month">This month</option><option value="year">This year</option></select>
      <button id="refreshDashboard" type="button" class="btn btn-outline-secondary"><i class="fas fa-rotate me-1"></i>Refresh</button>
    </div>
  </div>
  <div id="dashboardNotice" class="alert alert-info" role="status">Loading payment operations data…</div>
  <div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3"><div class="card h-100 border-0 shadow-sm"><div class="card-body"><div class="small text-muted text-uppercase fw-semibold">Total Successful Payments</div><div id="totalSuccessfulAmount" class="fs-4 fw-bold mt-2">—</div><small id="totalSuccessfulCount" class="text-muted">Loading…</small></div></div></div>
    <div class="col-sm-6 col-xl-3"><div class="card h-100 border-0 shadow-sm"><div class="card-body"><div class="small text-muted text-uppercase fw-semibold">Cash Collections</div><div id="cashAmount" class="fs-4 fw-bold mt-2">—</div><small id="cashCount" class="text-muted">Loading…</small></div></div></div>
    <div class="col-sm-6 col-xl-3"><div class="card h-100 border-0 shadow-sm"><div class="card-body"><div class="small text-muted text-uppercase fw-semibold">Live Online Collections</div><div id="liveOnlineAmount" class="fs-4 fw-bold mt-2">—</div><small id="liveOnlineCount" class="text-muted">Loading…</small></div></div></div>
    <div class="col-sm-6 col-xl-3"><div class="card h-100 border-0 shadow-sm"><div class="card-body"><div class="small text-muted text-uppercase fw-semibold">Pending Live Online Payments</div><div id="pendingLiveCount" class="fs-4 fw-bold mt-2">—</div><small class="text-muted">Current unresolved queue · count only</small></div></div></div>
  </div>
  <div class="row g-3 mb-4">
    <div class="col-md-4"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="small text-muted text-uppercase fw-semibold">Online Status Monitoring</div><div id="failedExpiredSummary" class="mt-2">Loading…</div><small class="text-muted">Period statuses by attempt creation time; statuses are not collected revenue.</small></div></div></div>
    <div class="col-md-4"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="small text-muted text-uppercase fw-semibold">Gateway Environment & Readiness</div><div id="gatewayEnvironment" class="fs-5 fw-bold mt-2">Loading…</div><div id="gatewayReadiness" class="small text-muted">Checking protected integration status…</div></div></div></div>
    <div class="col-md-4"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="small text-muted text-uppercase fw-semibold">School Sales Catalog</div><div class="d-flex justify-content-between mt-2"><span>Active categories</span><strong id="activeSaleCategories">—</strong></div><div class="d-flex justify-content-between"><span>Active items</span><strong id="activeSaleItems">—</strong></div><small class="text-muted d-block mt-2">Catalog only; separate from academic collections.</small><?php if($canManageCatalog): ?><a class="btn btn-sm btn-outline-primary mt-3" href="<?= BASE_URL ?>/modules/payment/pages/payment_admin/school-sales-catalog.php">Manage Catalog</a><?php endif; ?></div></div></div>
  </div>
  <div class="row g-4 mb-4">
    <div class="col-lg-8"><div class="card border-0 shadow-sm h-100"><div class="card-body"><h5 class="mb-1">Successful Payment Activity</h5><p id="trendCaption" class="small text-muted">Official allocated collections · Manila time</p><div style="height:300px"><canvas id="paymentTrendChart" aria-label="Successful payment activity trend"></canvas></div><div id="trendState" class="small text-muted mt-2"></div></div></div></div>
    <div class="col-lg-4"><div class="card border-0 shadow-sm h-100"><div class="card-body"><h5 class="mb-1">Collection Mix</h5><p class="small text-muted">Official allocated amounts; Payment Concern / Bank stays separate.</p><div style="height:260px"><canvas id="collectionMixChart" aria-label="Collection mix chart"></canvas></div><div id="concernAmountSummary" class="small text-muted mt-2"></div></div></div></div>
  </div>
  <div class="row g-4 mb-4">
    <div class="col-lg-7"><div class="card border-0 shadow-sm h-100"><div class="card-body"><h5 class="mb-1">Collections by Payment Channel</h5><p class="small text-muted">Based on recorded payment channel and official allocations.</p><div style="height:300px"><canvas id="channelChart" aria-label="Collections by payment channel"></canvas></div><div id="channelState" class="small text-muted mt-2"></div></div></div></div>
    <div class="col-lg-5"><div class="card border-0 shadow-sm h-100"><div class="card-body"><h5 class="mb-1">Receiving Channel Status</h5><p class="small text-muted">Configured options are distinct from observed payment channels. Live checkout is QRPh-only.</p><div id="activeReceivingCount" class="small fw-semibold mb-2">Checking configured options…</div><div id="receivingChannels" class="vstack gap-2">Loading…</div><a class="small d-inline-block mt-3" href="<?= BASE_URL ?>/modules/payment/pages/payment_admin/online-payment-integration.php">Open online payment settings</a></div></div></div>
  </div>
  <div class="row g-4 mb-4">
    <div class="col-lg-5"><div class="card border-0 shadow-sm h-100"><div class="card-body"><h5 class="mb-1">Online Payment Status</h5><p class="small text-muted">Current LIVE queue is separate; period status counts include test and unknown environment labels.</p><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Environment</th><th>Status</th><th class="text-end">Count</th></tr></thead><tbody id="onlineStatusRows"><tr><td colspan="3" class="text-muted">Loading…</td></tr></tbody></table></div></div></div></div>
    <div class="col-lg-7"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="d-flex justify-content-between align-items-center"><div><h5 class="mb-1">Needs Attention</h5><p class="small text-muted mb-2">Supported operational or integrity findings only.</p></div><span id="attentionBadge" class="badge text-bg-success">Clear</span></div><div id="attentionItems" class="vstack gap-2"><span class="text-muted">Checking…</span></div></div></div></div>
  </div>
  <div class="card border-0 shadow-sm mb-4"><div class="card-body"><div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3"><div><h5 class="mb-1">Recent Payment Activity</h5><p class="small text-muted mb-0">One row per payment. Applied academic allocation and attempted header amount are shown separately.</p></div><span id="recentScope" class="small text-muted"></span></div><div class="table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Timestamp</th><th>Reference</th><th>Student</th><th>Type / Channel</th><th>Environment</th><th>Status</th><th class="text-end">Applied</th><th class="text-end">Attempted</th></tr></thead><tbody id="recentActivityRows"><tr><td colspan="8" class="text-center text-muted">Loading…</td></tr></tbody></table></div></div></div>
  <div class="d-flex flex-wrap gap-2"><?php if($canManageCatalog): ?><a class="btn btn-outline-primary" href="<?= BASE_URL ?>/modules/payment/pages/payment_admin/school-sales-catalog.php"><i class="fas fa-tags me-1"></i>School Sales Catalog</a><?php endif; ?><a class="btn btn-primary" href="<?= BASE_URL ?>/modules/payment/pages/payment_admin/online-payment-integration.php"><i class="fas fa-sliders-h me-1"></i>Configure Online Payments</a></div>
</div>
<script>window.PAYMENT_ADMIN_DASHBOARD_API = <?= json_encode(BASE_URL . '/modules/payment/api/payment-admin-dashboard-data.php') ?>; window.PAYMENT_ADMIN_GATEWAY_STATUS_API = <?= json_encode(BASE_URL . '/modules/payment/api/paymongo/status.php') ?>;</script>
<script src="<?= BASE_URL ?>/modules/payment/assets/js/payment-admin-dashboard.js?v=1"></script>
<?php require_once ROOT_PATH . '/includes/layout-end.php'; ?>
