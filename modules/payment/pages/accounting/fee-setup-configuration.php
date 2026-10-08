<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once ROOT_PATH . '/includes/breadcrumbs.php';
requireAuth();
requirePaymentPermission('fee.view');

require_once ROOT_PATH . '/modules/payment/database/db_connect.php';
require_once ROOT_PATH . '/modules/payment/includes/FeeSetupService.php';

$canManageFees = paymentEffectivePermission(getCurrentUserRoleKey(), 'fee.manage');
$canActivateFees = paymentEffectivePermission(getCurrentUserRoleKey(), 'fee.activate');
$feeSetupBootstrap = ['taxonomy' => [], 'catalog' => [], 'legacy' => [], 'permissions' => ['manage' => $canManageFees, 'activate' => $canActivateFees]];
$feeSetupBootstrapError = null;
try {
    $feeSetupService = new FeeSetupService($pdo);
    $feeSetupBootstrap = [
        'taxonomy' => $feeSetupService->taxonomy(),
        'catalog' => $feeSetupService->catalog(),
        'legacy' => $feeSetupService->legacyFees(),
        'csrf_token' => generateCsrfToken(),
        'permissions' => ['manage' => $canManageFees, 'activate' => $canActivateFees],
    ];
} catch (Throwable $e) {
    error_log('Fee Setup page bootstrap failed: ' . $e->getMessage());
    $feeSetupBootstrapError = 'Fee Setup data is temporarily unavailable.';
}

$pageTitle = 'Fee Setup & Configuration';
$activeModule = 'payment';
$activePage = 'accounting/fee-setup-configuration';
$breadcrumbs = [
    ['label' => 'Payment Management', 'url' => BASE_URL . '/modules/payment/index.php'],
    ['label' => 'Fee Setup & Configuration', 'url' => null],
];
require_once ROOT_PATH . '/modules/payment/includes/payment-ui-assets.php';
paymentUiUseSharedCss(['css/fee-setup.css?v=3']);
require_once ROOT_PATH . '/includes/layout-start.php';
?>
<?php renderBreadcrumbs($breadcrumbs); ?>

<div class="container-fluid py-4 fee-setup payment-page" id="feeSetupApp"
     data-api="<?= htmlspecialchars(BASE_URL . '/modules/payment/api/accounting/fee-setup.php', ENT_QUOTES, 'UTF-8') ?>">
    <div class="payment-page-header">
        <div class="payment-page-header-text">
            <h2 class="payment-page-title"><i class="ti ti-receipt me-2" aria-hidden="true"></i>Fee Configuration</h2>
            <p class="payment-page-lede">Manage the fee catalog, effective versions, and applicability.</p>
        </div>
        <div class="payment-page-actions">
            <div class="input-group w-auto shadow-sm fee-search-box">
                <span class="input-group-text bg-white border-end-0"><i class="ti ti-search text-muted"></i></span>
                <input class="form-control border-start-0 ps-0" id="feeSearch" placeholder="Search fee name or code...">
            </div>
            <button class="btn btn-light border shadow-sm fw-bold px-4" id="legacyFeesButton" type="button" <?= $canManageFees ? '' : 'disabled' ?>>
                <i class="ti ti-tags me-1"></i> Legacy Classification
            </button>
            <button class="btn btn-light border shadow-sm fw-bold px-4" id="archivesButton" type="button">
                <i class="ti ti-archive me-1"></i> Archives
            </button>
            <button class="btn btn-primary shadow-sm fw-bold px-4" id="addFeeButton" type="button" <?= $canManageFees ? '' : 'disabled' ?>>
                <i class="ti ti-plus me-1"></i> Add Fee
            </button>
        </div>
    </div>

    <div class="alert d-none alert-dismissible fade show border-0 shadow-sm rounded-3" id="feeAlert" role="alert" aria-live="polite">
        <span id="feeAlertText"></span><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php if ($feeSetupBootstrapError !== null): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($feeSetupBootstrapError) ?></div>
    <?php endif; ?>

    <div class="accordion mb-4" id="feesAccordion"></div>
    <div class="text-center text-muted py-5 d-none" id="feesEmpty">
        <i class="ti ti-receipt-off fs-1 d-block mb-2"></i>No managed fees found.
    </div>
</div>

<!-- Add/Edit Identity: follows the original centered modal convention. -->
<div class="modal fade payment-modal" id="identityModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><form class="modal-content shadow-lg" id="identityForm">
        <div class="modal-header bg-primary text-white border-0 pb-3" id="identityHeader">
            <h5 class="modal-title fw-bold"><i class="ti ti-plus-circle me-2"></i><span id="identityTitle">Add New Fee</span></h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body bg-light p-4"><input type="hidden" id="identityId">
            <p class="small text-muted mb-3">Fields marked <span class="text-danger fw-bold">*</span> are required.</p>
            <div class="mb-3"><label class="form-label fw-semibold" for="identityCode">Fee Code <span class="text-danger">*</span></label><input class="form-control text-uppercase" id="identityCode" maxlength="60" minlength="3" pattern="[A-Z0-9][A-Z0-9_-]{2,59}" placeholder="e.g., LAB-COMP" required aria-required="true" aria-describedby="identityCodeHelp identityCodeError"><div class="form-text" id="identityCodeHelp">Use 3–60 uppercase letters, numbers, hyphens, or underscores. This cannot be changed after creation.</div><div class="invalid-feedback" id="identityCodeError"></div></div>
            <div class="mb-3"><label class="form-label fw-semibold" for="identityName">Fee Name <span class="text-danger">*</span></label><input class="form-control" id="identityName" maxlength="100" placeholder="e.g., Computer Laboratory Fee" required aria-required="true" aria-describedby="identityNameError"><div class="invalid-feedback" id="identityNameError"></div></div>
            <div class="row g-3"><div class="col-sm-6"><label class="form-label fw-semibold" for="identityGroup">Fee Group <span class="text-danger">*</span></label><select class="form-select" id="identityGroup" required aria-required="true" aria-describedby="identityGroupError"></select><div class="invalid-feedback" id="identityGroupError"></div></div><div class="col-sm-6"><label class="form-label fw-semibold" for="identityType">Fee Type <span class="text-danger">*</span></label><select class="form-select" id="identityType" required aria-required="true" aria-describedby="identityTypeHelp identityTypeError"></select><div class="form-text" id="identityTypeHelp">Choose the classification that best describes this fee.</div><div class="invalid-feedback" id="identityTypeError"></div><div class="form-text d-none" id="typeLockedHelp">Locked because versions exist.</div></div></div>
            <div class="mt-3" id="identityDescriptionWrap"><label class="form-label fw-semibold" for="identityDescription">Description <span class="text-muted fw-normal">(Optional)</span></label><textarea class="form-control" id="identityDescription" rows="3" maxlength="2000" placeholder="Optional internal description of this fee"></textarea><div class="form-text">For Accounting reference only; this does not set the billed amount.</div></div>
        </div>
        <div class="modal-footer border-0"><button class="btn btn-light shadow-sm" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary shadow-sm px-4" id="identitySave">Save Fee</button></div>
    </form></div>
</div>

<!-- Version history is the minimum extension required by the approved lifecycle. -->
<div class="modal fade payment-modal" id="versionsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><div class="modal-content shadow-lg">
        <div class="modal-header bg-white border-bottom pb-3"><div><h5 class="modal-title fw-bold text-primary"><i class="ti ti-versions me-2"></i><span id="versionsTitle">Fee Versions</span></h5><small class="text-muted" id="versionsCode"></small></div><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body bg-light p-4"><div class="text-end mb-3" id="newVersionActions"><button class="btn btn-light border shadow-sm" id="newBlankVersion" type="button">New Blank</button> <button class="btn btn-primary shadow-sm" id="copyLatestVersion" type="button">Copy Latest</button></div><div id="versionsList"></div></div>
        <div class="modal-footer border-0"><button class="btn btn-light shadow-sm" data-bs-dismiss="modal">Close</button></div>
    </div></div>
</div>

<div class="modal fade payment-modal" id="versionEditorModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><form class="modal-content shadow-lg" id="versionForm">
        <div class="modal-header bg-white border-bottom pb-3"><div><h5 class="modal-title fw-bold text-primary"><i class="ti ti-edit me-2"></i><span id="versionTitle">Create Draft Version</span></h5><small class="text-muted" id="versionFeeLabel"></small></div><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body bg-light p-4"><input type="hidden" id="versionFeeId"><input type="hidden" id="versionId">
            <div class="row g-3"><div class="col-sm-4"><label class="form-label fw-semibold">Academic Year</label><input class="form-control" id="versionYear" required placeholder="2027-2028"></div><div class="col-sm-4"><label class="form-label fw-semibold">Semester</label><select class="form-select" id="versionTerm"><option value="1st">1st Semester</option><option value="2nd">2nd Semester</option><option value="Summer">Summer</option></select></div><div class="col-sm-4"><label class="form-label fw-semibold">Amount</label><div class="input-group"><span class="input-group-text">₱</span><input class="form-control text-end" id="versionAmount" type="number" min="0" step=".01" required></div></div><div class="col-sm-4"><label class="form-label fw-semibold">Behavior</label><select class="form-select" id="versionBehavior"><option>Standard</option><option>One-Time</option><option>Optional</option><option>Manual</option></select></div><div class="col-sm-4"><label class="form-label fw-semibold">Required?</label><select class="form-select" id="versionRequired"><option value="1">Yes</option><option value="0">No</option></select></div><div class="col-sm-4"><label class="form-label fw-semibold">Description</label><input class="form-control" id="versionDescription"></div></div>
            <hr><div class="d-flex justify-content-between align-items-start"><div><h6 class="payment-modal-section-title mb-1">Applicability</h6><small class="text-muted">Select All explicitly; blank values never mean all.</small></div><button class="btn btn-sm btn-light border shadow-sm" id="addScope" type="button"><i class="ti ti-plus me-1"></i>Add</button></div><div id="versionScopes" class="mt-3"></div>
        </div>
        <div class="modal-footer border-0"><button class="btn btn-light shadow-sm" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary shadow-sm px-4" id="versionSave">Save Draft</button></div>
    </form></div>
</div>

<div class="modal fade payment-modal" id="legacyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><div class="modal-content shadow-lg">
        <div class="modal-header bg-white border-bottom pb-3"><div><h5 class="modal-title fw-bold text-secondary"><i class="ti ti-tags me-2"></i>Legacy Fee Classification</h5><small class="text-muted">Existing fees awaiting classification into the managed catalog.</small></div><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body bg-light p-4"><div id="legacyLoading" class="text-center text-muted py-4 d-none"><span class="spinner-border spinner-border-sm me-2"></span>Loading legacy fees...</div><div id="legacyError" class="alert alert-danger d-none"><span id="legacyErrorText"></span><button class="btn btn-sm btn-outline-danger ms-2" id="legacyRetry" type="button">Retry</button></div><div id="legacyResults"><div class="input-group shadow-sm mb-3"><span class="input-group-text bg-white border-end-0"><i class="ti ti-search text-muted"></i></span><input class="form-control border-start-0 ps-0" id="legacySearch" placeholder="Search legacy fee name or category..."></div><div class="payment-operational-table border-0 shadow-sm"><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Fee</th><th>Category</th><th class="text-end">Amount</th><th>Status</th><th class="text-end pe-4">Action</th></tr></thead><tbody id="legacyRows"></tbody></table></div></div><div class="text-center text-muted py-4 d-none" id="legacyEmpty">No legacy fees awaiting classification.</div></div></div>
        <div class="modal-footer border-0 bg-white"><button class="btn btn-light shadow-sm" data-bs-dismiss="modal">Close</button></div>
    </div></div>
</div>

<div class="modal fade payment-modal" id="archivesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable"><div class="modal-content shadow-lg">
        <div class="modal-header"><div><h5 class="modal-title fw-bold"><i class="fas fa-box-archive me-2"></i>Archives</h5><small class="text-muted">Read-only managed fee history.</small></div><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body bg-light p-4"><div id="archivesLoading" class="text-center text-muted py-4"><span class="spinner-border spinner-border-sm me-2"></span>Loading archives...</div><div id="archivesError" class="alert alert-danger d-none"><span id="archivesErrorText"></span><button class="btn btn-sm btn-outline-danger ms-2" id="archivesRetry" type="button">Retry</button></div><div id="archivesContent" class="d-none"><h6 class="payment-modal-section-title">Archived Fee Identities</h6><div class="payment-operational-table border-0 shadow-sm mb-4"><div class="table-responsive"><table class="table mb-0"><thead><tr><th class="ps-4">Fee</th><th>Classification</th><th>Versions</th><th>Archived</th></tr></thead><tbody id="archivedIdentityRows"></tbody></table></div></div><h6 class="payment-modal-section-title">Archived Fee Versions</h6><div class="payment-operational-table border-0 shadow-sm"><div class="table-responsive"><table class="table mb-0"><thead><tr><th class="ps-4">Fee</th><th>Version / Term</th><th>Configuration</th><th>Archived</th></tr></thead><tbody id="archivedVersionRows"></tbody></table></div></div><div id="archivesEmpty" class="text-center text-muted py-4 d-none">No archived managed fees or versions.</div></div></div>
        <div class="modal-footer"><button class="btn btn-light" data-bs-dismiss="modal">Close</button></div>
    </div></div>
</div>

<div class="modal fade payment-modal" id="classificationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><form class="modal-content shadow-lg" id="classificationForm">
        <div class="modal-header bg-white border-bottom pb-3"><div><h5 class="modal-title fw-bold text-primary"><i class="ti ti-tags me-2"></i>Classify Existing Fee</h5><small class="text-muted">Reference only — legacy values are not copied or approved automatically.</small></div><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body bg-light p-4"><input type="hidden" id="legacyFeeId"><div class="card border-0 shadow-sm mb-3"><div class="card-body"><h6 class="payment-modal-section-title">Existing Legacy Information</h6><div class="row g-2" id="legacyReference"></div></div></div>
            <div id="classificationEditor"><p class="small text-muted mb-3">Fields marked <span class="text-danger fw-bold">*</span> are required.</p><div class="row g-3"><div class="col-sm-4"><label class="form-label fw-semibold" for="legacyCode">Fee Code <span class="text-danger">*</span></label><input class="form-control text-uppercase" id="legacyCode" maxlength="60" minlength="3" pattern="[A-Z0-9][A-Z0-9_-]{2,59}" placeholder="e.g., LAB-COMP" required aria-required="true" aria-describedby="legacyCodeHelp legacyCodeError"><div class="form-text" id="legacyCodeHelp">3–60 uppercase letters, numbers, hyphens, or underscores.</div><div class="invalid-feedback" id="legacyCodeError"></div></div><div class="col-sm-4"><label class="form-label fw-semibold" for="legacyGroup">Fee Group <span class="text-danger">*</span></label><select class="form-select" id="legacyGroup" required aria-required="true" aria-describedby="legacyGroupError"></select><div class="invalid-feedback" id="legacyGroupError"></div></div><div class="col-sm-4"><label class="form-label fw-semibold" for="legacyType">Fee Type <span class="text-danger">*</span></label><select class="form-select" id="legacyType" required aria-required="true" aria-describedby="legacyTypeError"></select><div class="invalid-feedback" id="legacyTypeError"></div></div><div class="col-sm-4"><label class="form-label fw-semibold" for="legacyYear">Academic Year <span class="text-danger">*</span></label><input class="form-control" id="legacyYear" required pattern="[0-9]{4}-[0-9]{4}" inputmode="numeric" placeholder="YYYY-YYYY (e.g., 2027-2028)" aria-required="true" aria-describedby="legacyYearHelp legacyYearError"><div class="form-text" id="legacyYearHelp">Use consecutive academic years.</div><div class="invalid-feedback" id="legacyYearError"></div></div><div class="col-sm-4"><label class="form-label fw-semibold" for="legacyTerm">Semester <span class="text-danger">*</span></label><select class="form-select" id="legacyTerm" required aria-required="true" aria-describedby="legacyTermError"><option value="">Select semester</option><option value="1st">1st Semester</option><option value="2nd">2nd Semester</option><option value="Summer">Summer</option></select><div class="invalid-feedback" id="legacyTermError"></div></div><div class="col-sm-4"><label class="form-label fw-semibold" for="legacyAmount">Version Amount <span class="text-danger">*</span></label><div class="input-group"><span class="input-group-text">₱</span><input class="form-control text-end" id="legacyAmount" type="number" min="0" max="99999999.99" step=".01" inputmode="decimal" placeholder="0.00" required aria-required="true" aria-describedby="legacyAmountHelp legacyAmountError"></div><div class="form-text" id="legacyAmountHelp">Approved amount for this academic year and semester.</div><div class="invalid-feedback d-block" id="legacyAmountError"></div></div><div class="col-sm-4"><label class="form-label fw-semibold" for="legacyBehavior">Behavior <span class="text-danger">*</span></label><select class="form-select" id="legacyBehavior" required aria-required="true" aria-describedby="legacyBehaviorError"><option value="">Select fee behavior</option><option>Standard</option><option>One-Time</option><option>Optional</option><option>Manual</option></select><div class="invalid-feedback" id="legacyBehaviorError"></div></div><div class="col-sm-4"><label class="form-label fw-semibold" for="legacyRequired">Required? <span class="text-danger">*</span></label><select class="form-select" id="legacyRequired" required aria-required="true" aria-describedby="legacyRequiredError"><option value="">Select requirement</option><option value="1">Yes</option><option value="0">No</option></select><div class="invalid-feedback" id="legacyRequiredError"></div></div><div class="col-sm-4"><label class="form-label fw-semibold" for="legacyDescription">Description <span class="text-muted fw-normal">(Optional)</span></label><input class="form-control" id="legacyDescription" maxlength="2000" placeholder="Optional note for this academic-year configuration"></div></div><hr><div class="d-flex justify-content-between"><div><h6 class="payment-modal-section-title mb-1">Applicability <span class="text-danger">*</span></h6><small class="text-muted">Select All explicitly; blank values never mean all.</small></div><button class="btn btn-sm btn-light border shadow-sm" id="addLegacyScope" type="button"><i class="ti ti-plus me-1"></i>Add</button></div><div id="legacyScopes" class="mt-3"></div><div class="small text-danger d-none mt-2" id="legacyScopesError"></div></div>
            <div class="d-none" id="classificationPreview"><h6 class="payment-modal-section-title">Classification Review</h6><div id="classificationPreviewContent"></div></div>
        </div>
        <div class="modal-footer border-0"><button class="btn btn-light shadow-sm" data-bs-dismiss="modal">Cancel</button><button class="btn btn-light border shadow-sm d-none" id="previewBack" type="button">Back</button><button class="btn btn-primary shadow-sm" id="previewClassification">Preview Classification</button><button class="btn btn-success shadow-sm d-none" id="commitClassification" type="button">Confirm Classification</button></div>
    </form></div>
</div>

<div class="modal fade payment-modal" id="confirmModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-sm modal-dialog-centered"><div class="modal-content shadow-lg"><div class="modal-body text-center p-4"><div class="fs-1 mb-2" id="confirmIcon"><i class="ti ti-alert-triangle text-warning"></i></div><h5 class="fw-bold" id="confirmTitle">Confirm action?</h5><div class="text-muted" id="confirmText"></div><div class="d-flex gap-2 mt-4"><button class="btn btn-light border shadow-sm w-50" data-bs-dismiss="modal">Cancel</button><button class="btn btn-warning text-dark fw-bold shadow-sm w-50" id="confirmAction">Confirm</button></div></div></div></div></div>

<script>
window.FEE_SETUP_BOOTSTRAP = <?= json_encode($feeSetupBootstrap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?>;
</script>
<script src="<?= BASE_URL ?>/modules/payment/assets/js/fee-setup.js?v=4"></script>
<?php require_once ROOT_PATH . '/includes/layout-end.php'; ?>
