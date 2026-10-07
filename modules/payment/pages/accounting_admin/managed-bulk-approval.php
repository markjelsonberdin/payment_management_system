<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
requireAuth();
requirePaymentPermission('billing.bulk.approve');
$pageTitle = 'Managed Bulk Run Approval';
$activeModule = 'payment';
$activePage = 'accounting_admin/managed-bulk-approval';
$breadcrumbs = [['label'=>'Payment Management','url'=>BASE_URL.'/modules/payment/index.php'],['label'=>'Accounting Admin','url'=>null],['label'=>'Managed Bulk Run Approval','url'=>null]];
require_once ROOT_PATH . '/includes/breadcrumbs.php';
require_once ROOT_PATH . '/includes/layout-start.php';
renderBreadcrumbs($breadcrumbs);
?>
<style>
/* Keep assignment filters on their own usable row instead of compressing them beside the heading. */
#bulkApprovalResult article > .d-flex.flex-wrap.justify-content-between.gap-2.mt-4 > .d-flex.gap-2 {
    flex: 1 0 100%;
    display: grid !important;
    grid-template-columns: minmax(220px, 2fr) minmax(150px, 1fr) minmax(130px, .8fr) auto;
    align-items: center;
    margin-top: .25rem;
}
#bulkApprovalResult article > .d-flex.flex-wrap.justify-content-between.gap-2.mt-4 > .d-flex.gap-2 .form-control,
#bulkApprovalResult article > .d-flex.flex-wrap.justify-content-between.gap-2.mt-4 > .d-flex.gap-2 .form-select { width: 100%; }
#bulkApprovalResult article > .d-flex.flex-wrap.justify-content-between.gap-2.mt-4 > .d-flex.gap-2 .btn { min-width: 88px; }
@media (max-width: 767.98px) {
    #bulkApprovalResult article > .d-flex.flex-wrap.justify-content-between.gap-2.mt-4 > .d-flex.gap-2 { grid-template-columns: 1fr; }
    #bulkApprovalResult article > .d-flex.flex-wrap.justify-content-between.gap-2.mt-4 > .d-flex.gap-2 .btn { width: 100%; }
}
</style>
<main class="container-fluid payment-page py-4" id="bulkApprovalApp" data-api="<?= htmlspecialchars(BASE_URL.'/modules/payment/api/accounting/managed-billing-runs.php', ENT_QUOTES, 'UTF-8') ?>" data-csrf="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
 <header class="payment-page-header"><div class="payment-page-header-text"><h1 class="h3 payment-page-title">Managed Bulk Run Approval</h1><p>Review staff-created Draft runs. Billing cannot be created or processed here.</p></div></header>
 <section class="card border-0 shadow-sm mb-4"><div class="card-body">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><h2 class="h5 mb-1">Pending Bulk Run Approvals</h2><p class="text-muted small mb-0">All Draft runs awaiting Accounting Admin review.</p></div><button class="btn btn-outline-primary btn-sm" id="refreshPendingApprovals" type="button">Refresh</button></div>
  <div id="pendingApprovalList" aria-live="polite"><div class="text-muted small">Loading pending approvals…</div></div>
 </div></section>
 <section class="card border-0 shadow-sm"><div class="card-body"><div class="mb-3"><h2 class="h5 mb-1">Load a Specific Run</h2><p class="text-muted small mb-0">Use the Run ID when you need to reopen a completed, approved, or previously reviewed run.</p></div><form id="bulkApprovalLoad" class="row g-2 align-items-end"><div class="col-sm-4"><label class="form-label" for="bulkApprovalRunId">Run ID</label><input class="form-control" id="bulkApprovalRunId" name="run_id" type="number" min="1" required></div><div class="col-auto"><button class="btn btn-primary">Load Run</button></div></form><div id="bulkApprovalResult" class="mt-4" aria-live="polite"></div></div></section>
</main>
<div class="modal fade" id="approveRunModal" tabindex="-1" aria-labelledby="approveRunModalTitle" aria-hidden="true"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h2 class="modal-title fs-5" id="approveRunModalTitle">Approve Managed Bulk Run</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body" id="approveRunModalBody"></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-success" id="confirmApproveRun">Approve Run</button></div></div></div></div>
<script src="<?= BASE_URL ?>/modules/payment/assets/js/managed-bulk-approval.js"></script>
<?php require_once ROOT_PATH . '/includes/layout-end.php'; ?>
