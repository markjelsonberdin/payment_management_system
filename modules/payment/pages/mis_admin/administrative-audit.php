<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
requireAuth();requirePaymentPermission('payment.audit.view');
$pageTitle='Administrative Audit';$activeModule='payment';$activePage='mis_admin/administrative-audit';
$breadcrumbs=[['label'=>'MIS Admin','url'=>BASE_URL.'/modules/payment/pages/mis_admin/overview.php'],['label'=>'Administrative Audit','url'=>null]];
require_once ROOT_PATH.'/includes/breadcrumbs.php';require_once ROOT_PATH.'/includes/layout-start.php';renderBreadcrumbs($breadcrumbs);
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/modules/payment/assets/css/payment-mis-admin.css?v=1">
<main class="container-fluid payment-page py-4" id="administrativeAuditApp" data-api="<?= htmlspecialchars(BASE_URL.'/modules/payment/api/mis_admin/administrative-audit.php',ENT_QUOTES,'UTF-8') ?>">
 <div class="mis-page-header"><div><h1 class="h3">Administrative Audit</h1><p>Review historical Payment administrative activity and configuration changes.</p></div><button class="btn btn-outline-primary" id="auditRefresh" type="button">Refresh</button></div>
 <div id="auditNotice" class="mis-async-state small text-muted mb-3" role="status" aria-live="polite">Loading audit records…</div>
 <section class="mb-4" aria-labelledby="auditSummaryHeading"><h2 class="h5" id="auditSummaryHeading">Audit Summary</h2><div class="row g-3" id="auditSummaryCards"></div><p class="small text-muted mt-2 mb-0">Summary uses the current 30-day audit window. Administrative records are read-only.</p></section>
 <section class="card mis-card"><div class="card-body">
  <h2 class="h5 mb-3">Audit Events</h2>
  <form id="auditFilters" class="row g-2 align-items-end mb-3 mis-filter-bar">
   <div class="col-lg-2 col-md-4"><label class="form-label" for="auditFrom">From</label><input class="form-control" type="date" id="auditFrom" name="date_from" required></div>
   <div class="col-lg-2 col-md-4"><label class="form-label" for="auditTo">To</label><input class="form-control" type="date" id="auditTo" name="date_to" required></div>
   <div class="col-lg-2 col-md-4"><label class="form-label" for="auditCategory">Category</label><select class="form-select" id="auditCategory" name="category"><option value="">All categories</option><option value="USER_ADMINISTRATION">User Administration</option><option value="SECURITY_ADMINISTRATION">Security Administration</option><option value="INTEGRATION_PAYMONGO">Integration — PayMongo</option><option value="INTEGRATION_OCR">Integration — OCR</option></select></div>
   <div class="col-lg-2 col-md-4"><label class="form-label" for="auditResult">Result</label><select class="form-select" id="auditResult" name="result"><option value="">All results</option><option>SUCCESS</option><option>FAILED</option><option>DENIED</option><option>PARTIAL</option><option>RECORDED</option></select></div>
   <div class="col-lg-2 col-md-4"><label class="form-label" for="auditCorrelation">Correlation ID</label><input class="form-control" id="auditCorrelation" name="correlation_id" maxlength="36" placeholder="UUID v4"></div>
   <div class="col-lg-1 col-md-2"><label class="form-label" for="auditPageSize">Rows</label><select class="form-select" id="auditPageSize" name="page_size"><option>25</option><option>50</option><option>100</option></select></div>
   <div class="col-lg-2 col-md-4 mis-filter-actions"><button class="btn btn-primary" type="submit">Apply</button><button class="btn btn-outline-secondary" id="auditReset" type="button">Reset</button></div>
   <div class="col-lg-2 col-md-4"><label class="form-label" for="auditEvent">Event</label><input class="form-control" id="auditEvent" name="event" maxlength="60" placeholder="Canonical event"></div>
   <div class="col-lg-2 col-md-4"><label class="form-label" for="auditActor">Actor ID</label><input class="form-control" id="auditActor" name="actor" type="number" min="1"></div>
   <div class="col-lg-2 col-md-4"><label class="form-label" for="auditTarget">Target ID</label><input class="form-control" id="auditTarget" name="target" type="number" min="1"></div>
  </form>
  <div class="table-responsive mis-table-card"><table class="table table-hover align-middle mb-0"><thead><tr><th scope="col">Timestamp</th><th scope="col">Event</th><th scope="col">Category</th><th scope="col">Actor</th><th scope="col">Target</th><th scope="col">Result</th><th scope="col">Correlation ID</th><th scope="col">Details</th></tr></thead><tbody id="auditRows"><tr><td colspan="8" class="text-center py-4 text-muted">Loading…</td></tr></tbody></table></div>
  <div class="mis-pagination"><small id="auditCount" class="text-muted"></small><nav class="payment-pagination" aria-label="Administrative audit pages"><ul class="pagination pagination-sm mb-0" id="auditPagination"></ul></nav></div>
 </div></section>
 <div class="modal fade" id="auditDetailModal" tabindex="-1" aria-labelledby="auditDetailTitle" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h2 class="modal-title h5" id="auditDetailTitle">Audit Event Detail</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close audit event detail"></button></div><div class="modal-body" id="auditDetailBody"></div><div class="modal-footer"><button class="btn btn-outline-primary" id="viewRelatedEvents" type="button">View Related Events</button><button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Close</button></div></div></div></div>
</main>
<script src="<?= BASE_URL ?>/modules/payment/assets/js/payment-administrative-audit.js?v=2"></script>
<?php require_once ROOT_PATH.'/includes/layout-end.php'; ?>
