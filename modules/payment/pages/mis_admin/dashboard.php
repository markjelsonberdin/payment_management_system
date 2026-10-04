<?php
require_once __DIR__ . '/../../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
requireAuth();
requirePaymentPermission('payment.mis_overview');
$pageTitle = 'MIS Admin Overview';
$activeModule = 'payment';
$activePage = 'mis_admin/dashboard';
$breadcrumbs = [
    ['label' => 'Payment Management', 'url' => BASE_URL . '/modules/payment/index.php'],
    ['label' => 'MIS Admin Overview', 'url' => null],
];
require_once ROOT_PATH . '/includes/breadcrumbs.php';
require_once ROOT_PATH . '/includes/layout-start.php';
renderBreadcrumbs($breadcrumbs);
?>
<main class="container-fluid py-4" id="misOverview">
  <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
    <div><h1 class="h3 mb-1">MIS Admin Overview</h1>
      <p class="text-muted mb-0">Payment staff access, account security, and technical configuration.</p></div>
    <button id="refreshDashboard" class="btn btn-outline-primary" type="button">Refresh</button>
  </div>
  <div class="d-flex flex-wrap gap-2 mb-3">
    <?php if (paymentRoleAllowsPermission(getCurrentUserRoleKey(), 'payment_users.view')): ?>
    <a class="btn btn-primary" href="<?= BASE_URL ?>/modules/payment/pages/mis_admin/payment-user-management.php">Manage Payment Users</a>
    <?php endif; ?>
    <?php if (paymentRoleAllowsPermission(getCurrentUserRoleKey(), 'integration.paymongo.manage')): ?>
    <a class="btn btn-outline-primary" href="<?= BASE_URL ?>/modules/payment/pages/mis_admin/online-payment-integration.php">PayMongo Configuration</a>
    <?php endif; ?>
  </div>
  <div id="dashboardNotice" class="small text-muted mb-3" role="status" aria-live="polite">Loading technical overview…</div>
  <small id="adminLastUpdated" class="text-muted d-block mb-3"></small>
  <div class="row g-3 mb-4" id="adminKpis" aria-live="polite"></div>
  <div class="row g-3 mb-4">
    <div class="col-lg-6"><section class="card h-100"><div class="card-body">
      <h2 class="h5">Payment Roles &amp; Account Security</h2>
      <div id="roleCounts">Loading account counts…</div>
      <p id="securitySummary" class="small text-muted mt-3 mb-0"></p>
      <p class="small text-muted mt-2 mb-0">Active status and temporary locks are counted separately. Failed attempts are current account counters, not a time-based login history. Dedicated security monitoring is pending.</p>
    </div></section></div>
    <div class="col-lg-6"><section class="card h-100"><div class="card-body">
      <h2 class="h5">Integration Configuration</h2>
      <div id="integrationSummary">Loading configuration status…</div>
      <p class="small text-muted mt-3 mb-0">Configuration presence does not confirm provider connectivity, webhook registration, or successful delivery.</p>
    </div></section></div>
  </div>
  <section class="card"><div class="card-body">
    <h2 class="h5">Recent Administrative Activity</h2>
    <p class="small text-muted">Recorded MIS personnel changes. A dedicated security and audit viewer is pending.</p>
    <div class="table-responsive"><table class="table align-middle mb-0">
      <thead><tr><th scope="col">Date &amp; Time</th><th scope="col">Administrative Event</th></tr></thead>
      <tbody id="recentActivityRows"><tr><td colspan="2">Loading administrative activity…</td></tr></tbody>
    </table></div>
  </div></section>
</main>
<script>window.MIS_OVERVIEW_API = <?= json_encode(BASE_URL . '/modules/payment/api/mis_admin/overview.php') ?>;</script>
<script src="<?= BASE_URL ?>/modules/payment/assets/js/payment-admin-dashboard.js?v=5"></script>
<?php require_once ROOT_PATH . '/includes/layout-end.php'; ?>