<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
requireAuth();
requirePaymentPermission('school_sales.catalog.view');
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    exit('CATALOG_MUTATIONS_PENDING: School Sales catalog changes must use the approved JSON API.');
}
$role = getCurrentUserRoleKey();
$canManage = paymentRoleAllowsPermission($role, 'school_sales.catalog.manage') && userCanAccessModule('school_sales.catalog.manage');
$canActivate = paymentRoleAllowsPermission($role, 'school_sales.catalog.activate') && userCanAccessModule('school_sales.catalog.activate');
$catalogBootstrap = ['api' => BASE_URL . '/modules/payment/api/accounting/school-sales-catalog.php', 'csrf_token' => generateCsrfToken(), 'permissions' => ['view' => true, 'manage' => $canManage, 'activate' => $canActivate]];
$pageTitle = 'School Sales Catalog'; $activeModule = 'payment'; $activePage = 'accounting_admin/school-sales-catalog';
$breadcrumbs = [['label' => 'Payment Management', 'url' => BASE_URL . '/modules/payment/index.php'], ['label' => 'School Sales Catalog', 'url' => null]];
require_once ROOT_PATH . '/includes/breadcrumbs.php'; require_once ROOT_PATH . '/includes/layout-start.php'; renderBreadcrumbs($breadcrumbs);
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/modules/payment/assets/css/school-sales-catalog.css?v=1">
<main class="container-fluid py-4 school-sales-catalog" id="schoolSalesCatalogApp">
 <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4"><div><h1 class="h3 fw-bolder mb-1">School Sales Catalog</h1><p class="text-muted mb-0">Manage catalog configuration and lifecycle. Cashier selling remains disabled.</p></div><?php if ($canManage): ?><button class="btn btn-primary" type="button" data-command="create-item"><i class="ti ti-plus me-1"></i>New Draft Item</button><?php endif; ?></div>
 <div id="catalogAlert" class="alert d-none" role="status"><span id="catalogAlertText"></span><button id="catalogRetry" class="btn btn-sm btn-outline-dark ms-2 d-none" type="button">Retry same request</button></div>
 <div class="row g-4">
  <aside class="col-12 col-xl-4"><section class="card border-0 shadow-sm catalog-list-card"><div class="card-body border-bottom"><div class="input-group mb-3"><span class="input-group-text bg-body"><i class="ti ti-search"></i></span><input id="catalogSearch" class="form-control" type="search" placeholder="Search code or item" aria-label="Search catalog"></div><div class="row g-2"><div class="col-6"><label class="form-label small" for="catalogStatus">Status</label><select id="catalogStatus" class="form-select form-select-sm"><option value="">All statuses</option><option>Draft</option><option>Active</option><option>Inactive</option><option>Archived</option></select></div><div class="col-6"><label class="form-label small" for="catalogType">Type</label><select id="catalogType" class="form-select form-select-sm"><option value="">All types</option></select></div></div></div><div id="catalogListLoading" class="catalog-state"><span class="spinner-border spinner-border-sm me-2"></span>Loading catalog…</div><div id="catalogListEmpty" class="catalog-state d-none">No matching catalog items.</div><div id="catalogList" class="list-group list-group-flush catalog-list" aria-label="Catalog items"></div></section></aside>
  <section class="col-12 col-xl-8">
   <div id="catalogWelcome" class="card border-0 shadow-sm"><div class="card-body catalog-welcome text-center"><i class="ti ti-package"></i><h2 class="h5 mt-3">Select a catalog item</h2><p class="text-muted mb-0">Review variants, pricing, applicability, Book metadata, and activation readiness.</p></div></div>
   <div id="catalogDetailLoading" class="card border-0 shadow-sm d-none"><div class="catalog-state"><span class="spinner-border spinner-border-sm me-2"></span>Loading item details…</div></div>
   <div id="catalogDetail" class="d-none">
    <section class="card border-0 shadow-sm mb-4"><div class="card-body"><div class="d-flex flex-wrap justify-content-between gap-3"><div><div class="d-flex align-items-center gap-2"><h2 id="detailName" class="h4 mb-0"></h2><span id="detailStatus" class="badge"></span></div><div id="detailCode" class="text-muted font-monospace small mt-1"></div><p id="detailDescription" class="mt-3 mb-0 text-muted"></p></div><div id="itemActions" class="catalog-actions"></div></div><div id="detailMeta" class="row g-3 mt-2"></div></div></section>
    <section class="card border-0 shadow-sm mb-4"><div class="card-header bg-body d-flex justify-content-between align-items-center"><div><h3 class="h6 mb-1">Activation readiness</h3><small class="text-muted">Server-evaluated prerequisites</small></div><span id="readinessBadge" class="badge"></span></div><div id="readinessBody" class="card-body"></div></section>
    <section class="card border-0 shadow-sm mb-4"><div class="card-header bg-body d-flex justify-content-between align-items-center"><div><h3 class="h6 mb-1">Variants and effective prices</h3><small class="text-muted">Current official price and complete history</small></div><div id="variantHeaderActions" class="catalog-actions"></div></div><div id="variantList" class="card-body p-0"></div></section>
    <section class="card border-0 shadow-sm mb-4"><div class="card-header bg-body d-flex justify-content-between align-items-center"><div><h3 class="h6 mb-1">Applicability</h3><small class="text-muted">Eligibility filtering only; never billing or debt.</small></div><div id="applicabilityActions" class="catalog-actions"></div></div><div id="applicabilityBody" class="card-body"></div></section>
    <section id="bookSection" class="card border-0 shadow-sm mb-4 d-none"><div class="card-header bg-body d-flex justify-content-between align-items-center"><div><h3 class="h6 mb-1">Book metadata</h3><small class="text-muted">Descriptive metadata only</small></div><div id="bookActions" class="catalog-actions"></div></div><div id="bookBody" class="card-body"></div></section>
   </div>
  </section>
 </div>
</main>
<div class="modal fade" id="catalogEditorModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h2 id="catalogEditorTitle" class="modal-title fs-5"></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div id="catalogEditorBody" class="modal-body"></div><div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button id="catalogEditorSave" type="button" class="btn btn-primary">Save</button></div></div></div></div>
<div class="modal fade" id="catalogConfirmModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h2 id="catalogConfirmTitle" class="modal-title fs-5">Confirm action</h2><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><p id="catalogConfirmText" class="mb-0"></p></div><div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button id="catalogConfirmRun" type="button" class="btn btn-danger">Continue</button></div></div></div></div>
<script>window.SCHOOL_SALES_CATALOG_BOOTSTRAP = <?= json_encode($catalogBootstrap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?>;</script>
<script src="<?= BASE_URL ?>/modules/payment/assets/js/school-sales-catalog.js?v=1"></script>
<?php require_once ROOT_PATH . '/includes/layout-end.php'; ?>
