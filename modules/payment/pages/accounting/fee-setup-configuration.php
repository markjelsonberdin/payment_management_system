<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once ROOT_PATH . '/includes/breadcrumbs.php';
requireAuth();
requirePaymentPermission('payment.fee_setup');

$pageTitle = 'Fee Setup & Configuration';
$activeModule = 'payment';
$activePage = 'accounting/fee-setup-configuration';
$breadcrumbs = [
    ['label' => 'Payment Management', 'url' => BASE_URL . '/modules/payment/index.php'],
    ['label' => 'Fee Setup & Configuration', 'url' => null],
];
require_once ROOT_PATH . '/includes/layout-start.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/modules/payment/assets/css/fee-setup.css?v=2">
<?php renderBreadcrumbs($breadcrumbs); ?>

<div class="container-fluid py-4 fee-setup" id="feeSetupApp"
     data-api="<?= htmlspecialchars(BASE_URL . '/modules/payment/api/accounting/fee-setup.php', ENT_QUOTES, 'UTF-8') ?>">
    <div class="row mb-4 align-items-center">
        <div class="col-md-6">
            <h2 class="mb-1 fw-bolder"><i class="fas fa-money-check-alt text-primary me-2"></i>Fee Configuration</h2>
            <p class="text-muted mb-0 fs-6">Manage the fee catalog, effective versions, and applicability.</p>
        </div>
        <div class="col-md-6 text-md-end mt-3 mt-md-0">
            <div class="d-flex flex-wrap justify-content-md-end gap-2">
                <div class="input-group w-auto shadow-sm fee-search-box">
                    <span class="input-group-text bg-white border-end-0"><i class="ti ti-search text-muted"></i></span>
                    <input class="form-control border-start-0 ps-0" id="feeSearch" placeholder="Search fee name or code...">
                </div>
                <button class="btn btn-light border shadow-sm fw-bold px-4" id="legacyFeesButton" type="button">
                    <i class="fas fa-box-archive me-1"></i> Legacy Classification
                    <span class="badge bg-secondary rounded-pill ms-1" id="legacyCount">0</span>
                </button>
                <button class="btn btn-primary shadow-sm fw-bold px-4" id="addFeeButton" type="button">
                    <i class="ti ti-plus me-1"></i> Add Fee
                </button>
            </div>
        </div>
    </div>

    <div class="alert d-none alert-dismissible fade show border-0 shadow-sm rounded-3" id="feeAlert" role="alert" aria-live="polite">
        <span id="feeAlertText"></span><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>

    <div class="accordion mb-4" id="feesAccordion"></div>
    <div class="text-center text-muted py-5 d-none" id="feesEmpty">
        <i class="ti ti-receipt-off fs-1 d-block mb-2"></i>No managed fees found.
    </div>
    <div class="text-center text-muted py-5" id="feesLoading">
        <span class="spinner-border spinner-border-sm me-2"></span>Loading fee configuration...
    </div>
</div>

<!-- Add/Edit Identity: follows the original centered modal convention. -->
<div class="modal fade" id="identityModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><form class="modal-content border-0 shadow" id="identityForm">
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
<div class="modal fade" id="versionsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><div class="modal-content border-0 shadow">
        <div class="modal-header bg-white border-bottom pb-3"><div><h5 class="modal-title fw-bold text-primary"><i class="ti ti-versions me-2"></i><span id="versionsTitle">Fee Versions</span></h5><small class="text-muted" id="versionsCode"></small></div><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body bg-light p-4"><div class="text-end mb-3" id="newVersionActions"><button class="btn btn-light border shadow-sm" id="newBlankVersion" type="button">New Blank</button> <button class="btn btn-primary shadow-sm" id="copyLatestVersion" type="button">Copy Latest</button></div><div id="versionsList"></div></div>
        <div class="modal-footer border-0"><button class="btn btn-light shadow-sm" data-bs-dismiss="modal">Close</button></div>
    </div></div>
</div>

<div class="modal fade" id="versionEditorModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><form class="modal-content border-0 shadow" id="versionForm">
        <div class="modal-header bg-white border-bottom pb-3"><div><h5 class="modal-title fw-bold text-primary"><i class="ti ti-edit me-2"></i><span id="versionTitle">Create Draft Version</span></h5><small class="text-muted" id="versionFeeLabel"></small></div><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body bg-light p-4"><input type="hidden" id="versionFeeId"><input type="hidden" id="versionId">
            <div class="row g-3"><div class="col-sm-4"><label class="form-label fw-semibold">Academic Year</label><input class="form-control" id="versionYear" required placeholder="2027-2028"></div><div class="col-sm-4"><label class="form-label fw-semibold">Semester</label><select class="form-select" id="versionTerm"><option value="1st">1st Semester</option><option value="2nd">2nd Semester</option><option value="Summer">Summer</option></select></div><div class="col-sm-4"><label class="form-label fw-semibold">Amount</label><div class="input-group"><span class="input-group-text">₱</span><input class="form-control text-end" id="versionAmount" type="number" min="0" step=".01" required></div></div><div class="col-sm-4"><label class="form-label fw-semibold">Behavior</label><select class="form-select" id="versionBehavior"><option>Standard</option><option>One-Time</option><option>Optional</option><option>Manual</option></select></div><div class="col-sm-4"><label class="form-label fw-semibold">Required?</label><select class="form-select" id="versionRequired"><option value="1">Yes</option><option value="0">No</option></select></div><div class="col-sm-4"><label class="form-label fw-semibold">Description</label><input class="form-control" id="versionDescription"></div></div>
            <hr><div class="d-flex justify-content-between align-items-start"><div><h6 class="fw-bold mb-1">Applicability</h6><small class="text-muted">Select All explicitly; blank values never mean all.</small></div><button class="btn btn-sm btn-light border shadow-sm" id="addScope" type="button"><i class="ti ti-plus me-1"></i>Add</button></div><div id="versionScopes" class="mt-3"></div>
        </div>
        <div class="modal-footer border-0"><button class="btn btn-light shadow-sm" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary shadow-sm px-4" id="versionSave">Save Draft</button></div>
    </form></div>
</div>

<div class="modal fade" id="legacyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><div class="modal-content border-0 shadow">
        <div class="modal-header bg-white border-bottom pb-3"><div><h5 class="modal-title fw-bold text-secondary"><i class="ti ti-tags me-2"></i>Legacy Fee Classification</h5><small class="text-muted">Existing fees awaiting classification into the managed catalog.</small></div><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body bg-light p-4"><div class="input-group shadow-sm mb-3"><span class="input-group-text bg-white border-end-0"><i class="ti ti-search text-muted"></i></span><input class="form-control border-start-0 ps-0" id="legacySearch" placeholder="Search legacy fee name or category..."></div><div class="table-responsive"><table class="table table-sm table-hover align-middle bg-white mb-0"><thead class="text-uppercase text-secondary"><tr><th>Fee</th><th>Category</th><th class="text-end">Amount</th><th>Status</th><th class="text-end">Action</th></tr></thead><tbody id="legacyRows"></tbody></table></div><div class="text-center text-muted py-4 d-none" id="legacyEmpty">No legacy fees awaiting classification.</div></div>
        <div class="modal-footer border-0 bg-white"><button class="btn btn-light shadow-sm" data-bs-dismiss="modal">Close</button></div>
    </div></div>
</div>

<div class="modal fade" id="classificationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><form class="modal-content border-0 shadow" id="classificationForm">
        <div class="modal-header bg-white border-bottom pb-3"><div><h5 class="modal-title fw-bold text-primary"><i class="ti ti-tags me-2"></i>Classify Existing Fee</h5><small class="text-muted">Reference only — legacy values are not copied or approved automatically.</small></div><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body bg-light p-4"><input type="hidden" id="legacyFeeId"><div class="card border-0 shadow-sm mb-3"><div class="card-body"><h6 class="fw-bold">Existing Legacy Information</h6><div class="row g-2" id="legacyReference"></div></div></div>
            <div id="classificationEditor"><p class="small text-muted mb-3">Fields marked <span class="text-danger fw-bold">*</span> are required.</p><div class="row g-3"><div class="col-sm-4"><label class="form-label fw-semibold" for="legacyCode">Fee Code <span class="text-danger">*</span></label><input class="form-control text-uppercase" id="legacyCode" maxlength="60" minlength="3" pattern="[A-Z0-9][A-Z0-9_-]{2,59}" placeholder="e.g., LAB-COMP" required aria-required="true" aria-describedby="legacyCodeHelp legacyCodeError"><div class="form-text" id="legacyCodeHelp">3–60 uppercase letters, numbers, hyphens, or underscores.</div><div class="invalid-feedback" id="legacyCodeError"></div></div><div class="col-sm-4"><label class="form-label fw-semibold" for="legacyGroup">Fee Group <span class="text-danger">*</span></label><select class="form-select" id="legacyGroup" required aria-required="true" aria-describedby="legacyGroupError"></select><div class="invalid-feedback" id="legacyGroupError"></div></div><div class="col-sm-4"><label class="form-label fw-semibold" for="legacyType">Fee Type <span class="text-danger">*</span></label><select class="form-select" id="legacyType" required aria-required="true" aria-describedby="legacyTypeError"></select><div class="invalid-feedback" id="legacyTypeError"></div></div><div class="col-sm-4"><label class="form-label fw-semibold" for="legacyYear">Academic Year <span class="text-danger">*</span></label><input class="form-control" id="legacyYear" required pattern="[0-9]{4}-[0-9]{4}" inputmode="numeric" placeholder="YYYY-YYYY (e.g., 2027-2028)" aria-required="true" aria-describedby="legacyYearHelp legacyYearError"><div class="form-text" id="legacyYearHelp">Use consecutive academic years.</div><div class="invalid-feedback" id="legacyYearError"></div></div><div class="col-sm-4"><label class="form-label fw-semibold" for="legacyTerm">Semester <span class="text-danger">*</span></label><select class="form-select" id="legacyTerm" required aria-required="true" aria-describedby="legacyTermError"><option value="">Select semester</option><option value="1st">1st Semester</option><option value="2nd">2nd Semester</option><option value="Summer">Summer</option></select><div class="invalid-feedback" id="legacyTermError"></div></div><div class="col-sm-4"><label class="form-label fw-semibold" for="legacyAmount">Version Amount <span class="text-danger">*</span></label><div class="input-group"><span class="input-group-text">₱</span><input class="form-control text-end" id="legacyAmount" type="number" min="0" max="99999999.99" step=".01" inputmode="decimal" placeholder="0.00" required aria-required="true" aria-describedby="legacyAmountHelp legacyAmountError"></div><div class="form-text" id="legacyAmountHelp">Approved amount for this academic year and semester.</div><div class="invalid-feedback d-block" id="legacyAmountError"></div></div><div class="col-sm-4"><label class="form-label fw-semibold" for="legacyBehavior">Behavior <span class="text-danger">*</span></label><select class="form-select" id="legacyBehavior" required aria-required="true" aria-describedby="legacyBehaviorError"><option value="">Select fee behavior</option><option>Standard</option><option>One-Time</option><option>Optional</option><option>Manual</option></select><div class="invalid-feedback" id="legacyBehaviorError"></div></div><div class="col-sm-4"><label class="form-label fw-semibold" for="legacyRequired">Required? <span class="text-danger">*</span></label><select class="form-select" id="legacyRequired" required aria-required="true" aria-describedby="legacyRequiredError"><option value="">Select requirement</option><option value="1">Yes</option><option value="0">No</option></select><div class="invalid-feedback" id="legacyRequiredError"></div></div><div class="col-sm-4"><label class="form-label fw-semibold" for="legacyDescription">Description <span class="text-muted fw-normal">(Optional)</span></label><input class="form-control" id="legacyDescription" maxlength="2000" placeholder="Optional note for this academic-year configuration"></div></div><hr><div class="d-flex justify-content-between"><div><h6 class="fw-bold mb-1">Applicability <span class="text-danger">*</span></h6><small class="text-muted">Select All explicitly; blank values never mean all.</small></div><button class="btn btn-sm btn-light border shadow-sm" id="addLegacyScope" type="button"><i class="ti ti-plus me-1"></i>Add</button></div><div id="legacyScopes" class="mt-3"></div><div class="small text-danger d-none mt-2" id="legacyScopesError"></div></div>
            <div class="d-none" id="classificationPreview"><h6 class="fw-bold">Classification Review</h6><div id="classificationPreviewContent"></div></div>
        </div>
        <div class="modal-footer border-0"><button class="btn btn-light shadow-sm" data-bs-dismiss="modal">Cancel</button><button class="btn btn-light border shadow-sm d-none" id="previewBack" type="button">Back</button><button class="btn btn-primary shadow-sm" id="previewClassification">Preview Classification</button><button class="btn btn-success shadow-sm d-none" id="commitClassification" type="button">Confirm Classification</button></div>
    </form></div>
</div>

<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-sm modal-dialog-centered"><div class="modal-content border-0 shadow"><div class="modal-body text-center p-4"><div class="fs-1 mb-2" id="confirmIcon"><i class="ti ti-alert-triangle text-warning"></i></div><h5 class="fw-bold" id="confirmTitle">Confirm action?</h5><div class="text-muted" id="confirmText"></div><div class="d-flex gap-2 mt-4"><button class="btn btn-light border shadow-sm w-50" data-bs-dismiss="modal">Cancel</button><button class="btn btn-warning text-dark fw-bold shadow-sm w-50" id="confirmAction">Confirm</button></div></div></div></div></div>

<script src="<?= BASE_URL ?>/modules/payment/assets/js/fee-setup.js?v=2"></script>
<?php require_once ROOT_PATH . '/includes/layout-end.php'; ?>
