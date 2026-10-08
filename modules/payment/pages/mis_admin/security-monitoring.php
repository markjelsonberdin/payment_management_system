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
<link rel="stylesheet" href="<?= BASE_URL ?>/modules/payment/assets/css/payment-mis-admin.css?v=3">
<link rel="stylesheet" href="<?= BASE_URL ?>/modules/payment/assets/css/mis/security-monitoring.css?v=2">
<main class="container-fluid payment-page security-monitoring-page py-4" id="paymentSecurityApp"
 data-api="<?= htmlspecialchars(BASE_URL . '/modules/payment/api/mis_admin/security-monitoring.php', ENT_QUOTES, 'UTF-8') ?>"
 data-personnel-api="<?= htmlspecialchars(BASE_URL . '/modules/payment/api/mis_admin/payment-users.php', ENT_QUOTES, 'UTF-8') ?>"
 data-csrf="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>"
 data-can-unlock="<?= paymentEffectivePermission(getCurrentUserRoleKey(), 'payment_users.unlock') ? '1' : '0' ?>">

  <header class="security-page-header">
    <div>
      <span class="security-scope-badge"><i class="ti ti-shield-lock" aria-hidden="true"></i>RBAC scoped: payment.security.view · Read-only monitoring</span>
      <h1>Security Monitoring</h1>
      <p>Personnel authentication state and attributable Payment security events.</p>
    </div>
    <button class="btn btn-outline-primary" id="securityRefresh" type="button"><i class="ti ti-refresh me-1" aria-hidden="true"></i>Refresh</button>
  </header>

  <div id="securityNotice" class="d-none" role="alert" aria-live="assertive"></div>
  <section class="security-summary-grid" id="securitySummaryCards" aria-label="Security summary" aria-live="polite"></section>
  <p class="security-data-note">Failed attempts are current account counters. Session revocations are persisted events from the last 30 days. Active-session counts are unavailable by design.</p>

  <div class="security-overview-grid">
    <section class="security-panel" aria-labelledby="accountSnapshotTitle">
      <div class="security-panel-heading">
        <div><span class="security-panel-icon"><i class="ti ti-users" aria-hidden="true"></i></span><div><h2 id="accountSnapshotTitle">Account Security Status</h2><p>Current managed Payment personnel state.</p></div></div>
      </div>
      <div id="accountSecuritySnapshot" class="security-snapshot" aria-live="polite"></div>
    </section>

    <section class="security-panel" aria-labelledby="recentActivityTitle">
      <div class="security-panel-heading">
        <div><span class="security-panel-icon"><i class="ti ti-shield-check" aria-hidden="true"></i></span><div><h2 id="recentActivityTitle">Recent Security Activity</h2><p>Latest attributable Payment personnel events.</p></div></div>
      </div>
      <div id="recentSecurityActivity" class="security-recent-list" aria-live="polite"></div>
    </section>
  </div>

  <section class="security-panel security-table-panel" aria-labelledby="attentionTitle">
    <div class="security-panel-heading">
      <div><span class="security-panel-icon"><i class="ti ti-user-exclamation" aria-hidden="true"></i></span><div><h2 id="attentionTitle">Accounts Requiring Attention</h2><p>Administrative state and authentication lock state remain separate.</p></div></div>
    </div>
    <form id="personnelFilters" class="security-filter-grid">
      <div class="security-filter-search"><label class="form-label" for="personnelSearch">Search personnel</label><div class="security-input-icon"><i class="ti ti-search" aria-hidden="true"></i><input class="form-control" id="personnelSearch" name="search" maxlength="100" placeholder="Name, username, or email"></div></div>
      <div><label class="form-label" for="personnelRole">Payment role</label><select class="form-select" id="personnelRole" name="role"><option value="">All roles</option><option value="accounting_admin">Accounting Admin</option><option value="accounting_officer">Accounting Officer</option><option value="cashier">Cashier</option></select></div>
      <div><label class="form-label" for="adminState">Administrative</label><select class="form-select" id="adminState" name="administrative_status"><option value="">All states</option><option value="active">Active</option><option value="inactive">Inactive / Disabled</option></select></div>
      <div><label class="form-label" for="lockState">Security</label><select class="form-select" id="lockState" name="lock_state"><option value="">All states</option><option value="locked">Locked</option><option value="unlocked">Unlocked</option><option value="lock_expired">Lock expired</option></select></div>
      <div><label class="form-label" for="failedAttempts">Failed counters</label><select class="form-select" id="failedAttempts" name="failed_attempts"><option value="">All counters</option><option value="present">Current attempts &gt; 0</option></select></div>
      <div class="security-filter-actions"><button class="btn btn-primary" type="submit">Apply</button><button class="btn btn-light" id="personnelReset" type="button">Reset</button></div>
    </form>
    <div class="table-responsive security-table-wrap"><table class="table align-middle mb-0"><thead><tr><th scope="col">Personnel</th><th scope="col">Payment Role</th><th scope="col">Administrative</th><th scope="col">Security</th><th scope="col">Failed Counters</th><th scope="col">Locked Until</th><th scope="col">Last Activity</th><th scope="col" class="text-end">Action</th></tr></thead><tbody id="personnelSecurityRows"></tbody></table></div>
    <div class="mis-pagination security-pagination"><small id="personnelCount"></small><nav class="payment-pagination" aria-label="Payment account pages"><ul class="pagination pagination-sm mb-0" id="personnelPagination"></ul></nav></div>
  </section>

  <section class="security-panel security-table-panel" aria-labelledby="repositoryTitle">
    <div class="security-panel-heading">
      <div><span class="security-panel-icon"><i class="ti ti-database-search" aria-hidden="true"></i></span><div><h2 id="repositoryTitle">Security Events Repository</h2><p>Filtered and paginated Core activity records with reliable Payment personnel attribution.</p></div></div>
      <span class="security-live-badge"><span></span>Persisted data</span>
    </div>
    <form id="eventFilters" class="security-filter-grid security-event-filters">
      <div><label class="form-label" for="eventFrom">From</label><input class="form-control" type="date" id="eventFrom" name="date_from"></div>
      <div><label class="form-label" for="eventTo">To</label><input class="form-control" type="date" id="eventTo" name="date_to"></div>
      <div><label class="form-label" for="eventType">Event type</label><select class="form-select" id="eventType" name="event_type"><option value="">All events</option><option value="LOGIN_FAILED">Login failed</option><option value="ACCOUNT_LOCKED">Account locked</option><option value="PAYMENT_USER_UNLOCKED">Account unlocked</option><option value="PAYMENT_USER_PASSWORD_RESET">Password reset</option><option value="PAYMENT_USER_DEACTIVATED">Account disabled</option><option value="PAYMENT_USER_ACTIVATED">Account activated</option><option value="PAYMENT_USER_ROLE_CHANGED">Role changed</option><option value="PAYMENT_USER_STATUS_CHANGED">Status changed</option></select></div>
      <div><label class="form-label" for="eventResult">Outcome</label><select class="form-select" id="eventResult" name="result"><option value="">All outcomes</option><option value="success">Success</option><option value="denied">Denied</option><option value="locked">Locked</option></select></div>
      <div><label class="form-label" for="pageSize">Rows</label><select class="form-select" id="pageSize" name="page_size"><option>25</option><option>50</option><option>100</option></select></div>
      <div class="security-filter-actions"><button class="btn btn-primary" type="submit">Apply</button><button class="btn btn-light" id="eventReset" type="button">Reset</button></div>
    </form>
    <div class="table-responsive security-table-wrap"><table class="table align-middle mb-0"><thead><tr><th scope="col">Date &amp; Time (UTC+8)</th><th scope="col">Event Type</th><th scope="col">Personnel Account</th><th scope="col">Role</th><th scope="col">Severity</th><th scope="col">Outcome</th><th scope="col" class="text-end">Details</th></tr></thead><tbody id="securityEventRows"></tbody></table></div>
    <div class="mis-pagination security-pagination"><small id="eventCount"></small><nav class="payment-pagination" aria-label="Security event pages"><ul class="pagination pagination-sm mb-0" id="eventPagination"></ul></nav></div>
  </section>

  <div class="modal fade" id="securityEventModal" tabindex="-1" aria-labelledby="securityEventModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content security-event-modal">
      <div class="modal-header"><div><span class="security-modal-eyebrow">Core Activity Log · Safe attribution</span><h2 class="modal-title" id="securityEventModalTitle">Security Event</h2></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close event details"></button></div>
      <div class="modal-body" id="securityEventModalBody"></div>
      <div class="modal-footer"><span><i class="ti ti-shield-lock me-1" aria-hidden="true"></i>Sensitive credentials are never displayed.</span><button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close Record</button></div>
    </div></div>
  </div>
</main>
<script src="<?= BASE_URL ?>/modules/payment/assets/js/payment-security-monitoring.js?v=5"></script>
<?php require_once ROOT_PATH . '/includes/layout-end.php'; ?>
