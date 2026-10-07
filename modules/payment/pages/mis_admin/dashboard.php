<?php
require_once __DIR__ . '/../../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
requireAuth();
requirePaymentPermission('payment.mis_overview');
$pageTitle = 'Dashboard';
$activeModule = 'payment';
$activePage = 'mis_admin/dashboard';
$breadcrumbs = [
    ['label' => 'MIS Admin', 'url' => BASE_URL . '/modules/payment/pages/mis_admin/overview.php'],
    ['label' => 'Dashboard', 'url' => null],
];
require_once ROOT_PATH . '/includes/breadcrumbs.php';
require_once ROOT_PATH . '/includes/layout-start.php';
renderBreadcrumbs($breadcrumbs);
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/modules/payment/assets/css/payment-mis-admin.css?v=1">
<main class="container-fluid payment-page py-4" id="misOverview">
  <div class="mis-page-header">
    <div><h1 class="h3">Dashboard</h1>
      <p>Monitor Payment personnel, integrations, security, and recent administrative activity.</p></div>
    <button id="refreshDashboard" class="btn btn-outline-primary" type="button">Refresh</button>
  </div>
  <div id="dashboardNotice" class="mis-async-state small text-muted mb-3" role="status" aria-live="polite">Loading technical overview…</div>
  <small id="adminLastUpdated" class="text-muted d-block mb-3"></small>
  <div class="row g-3 mb-4" id="adminKpis" aria-live="polite"></div>
  <div class="row g-3 mb-4">
    <div class="col-lg-6"><section class="card mis-card"><div class="card-body">
      <h2 class="h5">Payment Roles &amp; Account Security</h2>
      <div id="roleCounts">Loading account counts…</div>
      <p id="securitySummary" class="small text-muted mt-3 mb-0"></p>
      <p class="small text-muted mt-2 mb-0">Active status and temporary locks are counted separately. Failed attempts are current account counters, not a time-based login history.</p>
      <?php if (paymentRoleAllowsPermission(getCurrentUserRoleKey(), 'payment.security.view')): ?>
      <a href="<?= BASE_URL ?>/modules/payment/pages/mis_admin/security-monitoring.php" class="small">Open Security Monitoring</a>
      <?php endif; ?>

    </div></section></div>
    <div class="col-lg-6"><section class="card mis-card"><div class="card-body">
      <h2 class="h5">Integration Configuration</h2>
      <div id="integrationSummary">Loading configuration status…</div>
      <p class="small text-muted mt-3 mb-0">Configuration presence does not confirm provider connectivity, webhook registration, or successful delivery.</p>
    </div></section></div>
  </div>
  <section class="card mis-card"><div class="card-body">
    <h2 class="h5">Recent Administrative Activity</h2>
    <p class="small text-muted">Recorded MIS personnel changes. Use Security Monitoring for the scoped account-security event feed.</p>
    <div class="table-responsive"><table class="table align-middle mb-0">
      <thead><tr><th scope="col">Date &amp; Time</th><th scope="col">Administrative Event</th></tr></thead>
      <tbody id="recentActivityRows"><tr><td colspan="2">Loading administrative activity…</td></tr></tbody>
    </table></div>
    <?php if (paymentRoleAllowsPermission(getCurrentUserRoleKey(), 'payment.audit.view')): ?>
    <a class="small d-inline-block mt-3" href="<?= BASE_URL ?>/modules/payment/pages/mis_admin/administrative-audit.php">View Administrative Audit</a>
    <?php endif; ?>
  </div></section>
</main>
<script>window.MIS_OVERVIEW_API = <?= json_encode(BASE_URL . '/modules/payment/api/mis_admin/overview.php') ?>; window.MIS_OVERVIEW_LINKS = <?= json_encode(['users' => BASE_URL . '/modules/payment/pages/mis_admin/payment-user-management.php', 'security' => BASE_URL . '/modules/payment/pages/mis_admin/security-monitoring.php', 'paymongo' => BASE_URL . '/modules/payment/pages/mis_admin/online-payment-integration.php', 'ocr' => BASE_URL . '/modules/payment/pages/mis_admin/google-ocr-integration.php', 'audit' => BASE_URL . '/modules/payment/pages/mis_admin/administrative-audit.php']) ?>;</script>
<script src="<?= BASE_URL ?>/modules/payment/assets/js/payment-admin-dashboard.js?v=5"></script>
<?php require_once ROOT_PATH . '/includes/layout-end.php'; ?>
