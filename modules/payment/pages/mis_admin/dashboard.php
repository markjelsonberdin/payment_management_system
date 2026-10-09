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
<link rel="stylesheet" href="<?= BASE_URL ?>/modules/payment/assets/css/payment-mis-admin.css?v=4">
<link rel="stylesheet" href="<?= BASE_URL ?>/modules/payment/assets/css/mis/mis-admin-dashboard.css?v=3">
<main class="container-fluid payment-page mis-admin-dashboard py-4" id="misOverview">
  <div class="mis-dashboard-header">
    <div class="d-flex align-items-center gap-3">
      <span class="mis-dashboard-heading-icon"><i class="ti ti-layout-dashboard" aria-hidden="true"></i></span>
      <div><h1>Dashboard</h1><p>Monitor Payment personnel, integrations, and security.</p></div>
    </div>
    <div class="mis-dashboard-refresh-wrap">
      <small id="adminLastUpdated">Not yet updated</small>
      <button id="refreshDashboard" class="btn btn-outline-primary" type="button"><i class="ti ti-refresh me-1" aria-hidden="true"></i>Refresh</button>
    </div>
  </div>


  <div id="dashboardNotice" class="d-none" role="status" aria-live="polite"></div>
  <section class="mis-dashboard-kpis" id="adminKpis" aria-label="MIS Admin summary" aria-live="polite"></section>

  <div class="mis-dashboard-main-grid">
    <section class="mis-dashboard-panel" aria-labelledby="integrationHealthTitle">
      <div class="mis-dashboard-panel-header">
        <div class="d-flex align-items-center gap-2"><i class="ti ti-affiliate" aria-hidden="true"></i><h2 id="integrationHealthTitle">Integration Health</h2></div>
        <p>Saved configuration is not connectivity. A step is verified only when supported by current system data.</p>
      </div>
      <div class="mis-integration-grid" id="integrationHealth" aria-live="polite"></div>
    </section>

    <section class="mis-dashboard-panel" aria-labelledby="recentSecurityTitle">
      <div class="mis-dashboard-panel-header mis-security-panel-heading">
        <div>
          <div class="d-flex align-items-center gap-2"><i class="ti ti-shield" aria-hidden="true"></i><h2 id="recentSecurityTitle">Recent Security Events</h2></div>
          <p>Latest Payment personnel security activity from Core logs.</p>
        </div>
        <?php if (paymentEffectivePermission(getCurrentUserRoleKey(), 'payment.security.view')): ?>
          <a href="<?= BASE_URL ?>/modules/payment/pages/mis_admin/security-monitoring.php">View Security Monitoring</a>
        <?php endif; ?>
      </div>
      <div id="recentSecurityEvents" class="mis-security-events" aria-live="polite"></div>
      <div class="mis-dashboard-source-note"><i class="ti ti-lock" aria-hidden="true"></i>Payment scoped and RBAC filtered.</div>
    </section>
  </div>
  <section class="mis-dashboard-panel mt-3" aria-labelledby="accountSecurityTitle">
    <div class="mis-dashboard-panel-header">
      <div class="d-flex align-items-center gap-2"><i class="ti ti-users" aria-hidden="true"></i><h2 id="accountSecurityTitle">Payment Roles &amp; Account Security</h2></div>
    </div>
    <div id="roleCounts" class="mis-dashboard-role-counts" aria-live="polite">Loading role counts…</div>
    <p id="securitySummary" class="small text-muted mt-3 mb-0" aria-live="polite">Loading account security…</p>
    <p class="small text-muted mt-2 mb-0">Active status and temporary locks are counted separately. Failed attempts are current account counters, not a time-based login history.</p>
    <?php if (paymentEffectivePermission(getCurrentUserRoleKey(), 'payment.security.view')): ?>
      <a href="<?= BASE_URL ?>/modules/payment/pages/mis_admin/security-monitoring.php" class="small">Open Security Monitoring</a>
    <?php endif; ?>
  </section>
</main>
<script>
window.MIS_OVERVIEW_API = <?= json_encode(BASE_URL . '/modules/payment/api/mis_admin/overview.php') ?>;
window.MIS_SECURITY_API = <?= json_encode(BASE_URL . '/modules/payment/api/mis_admin/security-monitoring.php') ?>;
window.MIS_OVERVIEW_LINKS = <?= json_encode(['users' => BASE_URL . '/modules/payment/pages/mis_admin/payment-user-management.php', 'security' => BASE_URL . '/modules/payment/pages/mis_admin/security-monitoring.php', 'paymongo' => BASE_URL . '/modules/payment/pages/mis_admin/online-payment-integration.php', 'ocr' => BASE_URL . '/modules/payment/pages/mis_admin/google-ocr-integration.php']) ?>;
</script>
<script src="<?= BASE_URL ?>/modules/payment/assets/js/payment-admin-dashboard.js?v=8"></script>
<?php require_once ROOT_PATH . '/includes/layout-end.php'; ?>
