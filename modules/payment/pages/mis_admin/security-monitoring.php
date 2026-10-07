<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
requireAuth();
requirePaymentPermission('payment.security.view');
$pageTitle = 'Security Monitoring';
$activeModule = 'payment';
$activePage = 'mis_admin/security-monitoring';
$breadcrumbs = [
    ['label' => 'MIS Admin', 'url' => BASE_URL . '/modules/payment/pages/mis_admin/overview.php'],
    ['label' => 'Security Monitoring', 'url' => null],
];
require_once ROOT_PATH . '/includes/breadcrumbs.php';
require_once ROOT_PATH . '/includes/layout-start.php';
renderBreadcrumbs($breadcrumbs);
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/modules/payment/assets/css/payment-mis-admin.css?v=1">
<main class="container-fluid payment-page py-4" id="paymentSecurityApp"
 data-api="<?= htmlspecialchars(BASE_URL . '/modules/payment/api/mis_admin/security-monitoring.php', ENT_QUOTES, 'UTF-8') ?>"
 data-personnel-api="<?= htmlspecialchars(BASE_URL . '/modules/payment/api/mis_admin/payment-users.php', ENT_QUOTES, 'UTF-8') ?>"
 data-csrf="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>"
 data-can-unlock="<?= paymentRoleAllowsPermission(getCurrentUserRoleKey(), 'payment_users.unlock') ? '1' : '0' ?>">
  <div class="mis-page-header">
    <div><h1 class="h3">Security Monitoring</h1>
      <p>Monitor Payment personnel authentication and security conditions.</p></div>
    <button class="btn btn-outline-primary" id="securityRefresh" type="button">Refresh</button>
  </div>
  <div id="securityNotice" class="d-none" role="alert" aria-live="assertive"></div>
  <section class="mb-4" aria-labelledby="securityOverviewHeading">
    <h2 class="h5" id="securityOverviewHeading">Security Overview</h2>
    <div class="row g-3" id="securitySummaryCards"></div>
    <p class="small text-muted mt-2 mb-0">Failed attempts are current account counters. Session revocations are persisted events from the last 30 days. Active-session counts are unavailable by design.</p>
  </section>
  <section class="card mis-card mb-4"><div class="card-body">
    <div class="d-flex flex-wrap justify-content-between gap-2 mb-3"><div><h2 class="h5 mb-1">Accounts Requiring Attention</h2><p class="small text-muted mb-0">Administrative and authentication lock states are shown separately.</p></div></div>
    <form id="personnelFilters" class="row g-2 align-items-end mb-3 mis-filter-bar">
      <div class="col-md-3"><label class="form-label" for="personnelSearch">Search</label><input class="form-control" id="personnelSearch" name="search" maxlength="100" placeholder="Name, username, or email"></div>
      <div class="col-md-2"><label class="form-label" for="personnelRole">Payment role</label><select class="form-select" id="personnelRole" name="role"><option value="">All roles</option><option value="accounting_admin">Accounting Admin</option><option value="accounting_officer">Accounting Officer</option><option value="cashier">Cashier</option></select></div>
      <div class="col-md-2"><label class="form-label" for="adminState">Administrative</label><select class="form-select" id="adminState" name="administrative_status"><option value="">All states</option><option value="active">Active</option><option value="inactive">Inactive / Disabled</option></select></div>
      <div class="col-md-2"><label class="form-label" for="lockState">Security</label><select class="form-select" id="lockState" name="lock_state"><option value="">All states</option><option value="locked">Locked</option><option value="unlocked">Unlocked</option><option value="lock_expired">Lock expired</option></select></div>
      <div class="col-md-2"><label class="form-label" for="failedAttempts">Failed attempts</label><select class="form-select" id="failedAttempts" name="failed_attempts"><option value="">All</option><option value="present">Current attempts &gt; 0</option></select></div>
      <div class="col-md-2 mis-filter-actions"><button class="btn btn-primary" type="submit">Apply</button><button class="btn btn-outline-secondary" id="personnelReset" type="button">Reset</button></div>
    </form>
    <div class="table-responsive mis-table-card"><table class="table table-hover align-middle mb-0"><thead><tr><th scope="col">Name</th><th scope="col">Payment Role</th><th scope="col">Administrative</th><th scope="col">Security</th><th scope="col">Current Failed Attempts</th><th scope="col">Locked Until</th><th scope="col">Last Security Activity</th><th scope="col">Action</th></tr></thead><tbody id="personnelSecurityRows"></tbody></table></div>
    <div class="mis-pagination"><small id="personnelCount" class="text-muted"></small><nav class="payment-pagination" aria-label="Payment account pages"><ul class="pagination pagination-sm mb-0" id="personnelPagination"></ul></nav></div>
  </div></section>
  <section class="card mis-card"><div class="card-body">
    <div class="mb-3"><h2 class="h5 mb-1">Recent Security Events</h2><p class="small text-muted mb-0">Payment-scoped persisted events only. Historical events without a reliable managed-user target are omitted.</p></div>
    <form id="eventFilters" class="row g-2 align-items-end mb-3 mis-filter-bar">
      <div class="col-md-2"><label class="form-label" for="eventFrom">From</label><input class="form-control" type="date" id="eventFrom" name="date_from"></div>
      <div class="col-md-2"><label class="form-label" for="eventTo">To</label><input class="form-control" type="date" id="eventTo" name="date_to"></div>
      <div class="col-md-3"><label class="form-label" for="eventType">Event</label><select class="form-select" id="eventType" name="event_type"><option value="">All events</option><option value="LOGIN_FAILED">Login failed</option><option value="ACCOUNT_LOCKED">Account locked</option><option value="PAYMENT_USER_UNLOCKED">Account unlocked</option><option value="PAYMENT_USER_PASSWORD_RESET">Password reset</option><option value="PAYMENT_USER_DEACTIVATED">Account disabled</option><option value="PAYMENT_USER_ROLE_CHANGED">Role changed</option></select></div>
      <div class="col-md-2"><label class="form-label" for="eventResult">Result</label><select class="form-select" id="eventResult" name="result"><option value="">All results</option><option value="success">Success</option><option value="denied">Denied</option><option value="locked">Locked</option></select></div>
      <div class="col-md-2"><label class="form-label" for="pageSize">Rows</label><select class="form-select" id="pageSize" name="page_size"><option>25</option><option>50</option><option>100</option></select></div>
      <div class="col-md-2 mis-filter-actions"><button class="btn btn-primary" type="submit">Apply</button><button class="btn btn-outline-secondary" id="eventReset" type="button">Reset</button></div>
    </form>
    <div class="table-responsive mis-table-card"><table class="table table-hover align-middle mb-0"><thead><tr><th scope="col">Timestamp</th><th scope="col">Event</th><th scope="col">Actor</th><th scope="col">Target</th><th scope="col">Role</th><th scope="col">Result</th><th scope="col">Safe Context</th><th scope="col">Correlation ID</th></tr></thead><tbody id="securityEventRows"></tbody></table></div>
    <div class="mis-pagination"><small id="eventCount" class="text-muted"></small><nav class="payment-pagination" aria-label="Security event pages"><ul class="pagination pagination-sm mb-0" id="eventPagination"></ul></nav></div>
  </div></section>
</main>
<script src="<?= BASE_URL ?>/modules/payment/assets/js/payment-security-monitoring.js?v=3"></script>
<?php require_once ROOT_PATH . '/includes/layout-end.php'; ?>
