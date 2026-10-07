<?php
/**
 * SMS 2 - Collection Reporting & Analytics
 * Module: Payment Management
 */
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../includes/authentication.php';
require_once __DIR__ . '/../../../../includes/audit.php';
require_once __DIR__ . '/../../database/db_connect.php';
require_once __DIR__ . '/../../includes/PaymentConcernService.php';
require_once __DIR__ . '/../../includes/PaymentConcernVerificationService.php';

requireAuth();
requirePaymentPermission('payment.concern.review');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$reviewer_id = getCurrentUserId();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_concern'])) {
    requireCsrf();
    $concern_id = (int)($_POST['concern_id'] ?? 0);
    $billing_id = filter_var($_POST['billing_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
    $action     = $_POST['action_concern'];
    $remarks    = trim($_POST['remarks'] ?? '');

    try {
        if ($concern_id < 1 || !in_array($action, ['Verify', 'Hold', 'Reject'], true)) {
            throw new Exception('Invalid concern decision.');
        }
        $concernService = new PaymentConcernService($pdo);
        $verifiedData = [
            'amount' => $_POST['verified_amount'] ?? null,
            'reference_number' => $_POST['verified_reference'] ?? '',
            'transaction_date' => $_POST['verified_date'] ?? '',
            'payment_channel' => $_POST['verified_channel'] ?? '',
            'review_confirmed' => isset($_POST['review_confirmed']),
        ];
        $concernService->verifyConcern($concern_id, $action, $reviewer_id, $remarks, $billing_id, $verifiedData);
        $activity = ['Verify' => 'verify_payment_concern', 'Hold' => 'hold_payment_concern', 'Reject' => 'reject_payment_concern'][$action];
        logActivity($activity, "$action payment concern ID #{$concern_id}" . ($remarks !== '' ? "; Reason: " . substr($remarks, 0, 500) : ''), 'payment', (int)$reviewer_id);

        header("Location: payment-concern-portal.php?success=1");
        exit();

    } catch (Exception $e) {
        header("Location: payment-concern-portal.php?error=" . urlencode($e->getMessage()));
        exit();
    }
}


try {
    $concernService = new PaymentConcernService($pdo);
    $concernsList = $concernService->getQueue();
    
    // Evaluate rules for pending concerns
    $ruleEngine = new PaymentConcernVerificationService($pdo);

    foreach ($concernsList as &$concern) {
        if ($concern['ocr_status'] === 'Completed') {
            $eval = $ruleEngine->evaluateConcern($concern['concern_id']);
            $concern['rule_status'] = $eval['status'];
            $concern['rule_remarks'] = $eval['remarks'];

        }
    }

    $billingChoices = [];
    $billingRows = $pdo->query("SELECT billing_id, student_id, academic_year, semester, remaining_balance FROM billing ORDER BY billing_id DESC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($billingRows as $billingRow) {
        $billingChoices[(int)$billingRow['student_id']][] = $billingRow;
    }

} catch (Exception $e) {
    $concernsList = [];
    $billingChoices = [];
    $dbError = $e->getMessage();
    error_log('Payment matching review load failed: ' . $dbError);
}

$pageTitle    = 'Payment Concern Portal';
$activeModule = 'payment';
$activePage   = 'accounting/payment-concerns';
$breadcrumbs  = [
    ['label' => 'Payment Management', 'url' => BASE_URL . '/modules/payment/index.php'],
    ['label' => 'Payment Concern Portal', 'url' => null],
];

require_once __DIR__ . '/../../../../includes/breadcrumbs.php';
require_once __DIR__ . '/../../../../includes/layout-start.php';
?>

<?php renderBreadcrumbs($breadcrumbs); ?>
<link rel="stylesheet" href="<?= BASE_URL ?>/modules/payment/assets/css/payment-operational-tables.css">

<div class="container-fluid payment-page py-4">
    <!-- Header -->
    <div class="row mb-4 align-items-center">
        <div class="col-md-8">
            <h2 class="mb-1 fw-bolder"><i class="fas fa-headset text-primary me-2"></i>Payment Concern Portal</h2>
            <p class="text-muted mb-0 fs-6">Review student-submitted receipts and use Google OCR as supporting evidence.</p>
        </div>
        <div class="col-md-4 text-md-end mt-3 mt-md-0 d-flex align-items-center justify-content-md-end gap-2">
            <a href="payment-concern-portal.php" class="btn btn-outline-secondary shadow-sm" title="Refresh review queue">Refresh</a>
            <a href="bank-reconciliation.php" class="btn btn-outline-primary shadow-sm" title="Bank Reconciliation">
                <i class="fas fa-university"></i>
            </a>
            <div class="input-group w-auto shadow-sm">
                <span class="input-group-text bg-white border-end-0"><i class="ti ti-search text-muted"></i></span>
                <input type="text" class="form-control border-start-0 ps-0 table-live-search-input" data-table-target="#concernsTable" placeholder="Search...">
            </div>
        </div>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible shadow-sm"><i class="ti ti-circle-check me-2"></i> Payment concern successfully updated! <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger alert-dismissible shadow-sm"><i class="ti ti-alert-circle me-2"></i> <?= htmlspecialchars($_GET['error']) ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if (isset($dbError)): ?>
        <div class="alert alert-danger">The payment concern review queue is temporarily unavailable.</div>
    <?php endif; ?>

    <!-- Table of Concerns -->
    <div class="card shadow-sm border-0 rounded-4 overflow-hidden payment-operational-table">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 text-nowrap" id="concernsTable">
                    <thead class="bg-light text-uppercase text-secondary" style="font-size: 0.75rem; letter-spacing: 0.5px;">
                        <tr>
                            <th class="ps-4 py-3">ID #</th>
                            <th class="py-3">Student Details</th>
                            <th class="py-3">Payment Info</th>
                            <th class="py-3">Google OCR Extracted Data</th>
                            <th class="py-3 text-center">OCR Status</th>
                            <th class="py-3 text-center">Verification</th>
                            <th class="text-end pe-4 py-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="border-top-0">
                        <?php if (count($concernsList) > 0): ?>
                            <?php foreach ($concernsList as $row): ?>
                                <tr>
                                    <td class="ps-4 fw-bold text-primary">#<?= $row['concern_id'] ?></td>
                                    <td>
                                        <div class="payment-table-primary"><?= htmlspecialchars($row['full_name']) ?></div>
                                        <small class="payment-table-secondary"><?= htmlspecialchars($row['student_number']) ?></small>
                                    </td>
                                    <td>
                                        <div class="payment-table-money text-start"><?= $row['payment_amount'] !== null ? '₱ ' . number_format((float)$row['payment_amount'], 2) : 'Not linked' ?></div>
                                        <small class="payment-table-secondary"><?= htmlspecialchars((string)($row['payment_channel'] ?? 'N/A')) ?></small>
                                    </td>
                                    <td>
                                        <div class="payment-table-primary small"><strong>Bank:</strong> <?= htmlspecialchars($row['bank_name'] ?? 'N/A') ?></div>
                                        <div class="payment-table-secondary"><strong>OCR Ref:</strong> <?= htmlspecialchars($row['ocr_ref'] ?? 'N/A') ?></div>
                                        <div class="payment-table-secondary">Confidence: <?= $row['confidence_score'] ? $row['confidence_score'] . '%' : 'N/A' ?></div>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle px-2 py-1 mb-1"><?= htmlspecialchars($row['ocr_status']) ?></span>
                                        <?php if (isset($row['rule_status'])): ?>
                                            <div class="small fw-bold <?= $row['rule_status'] === 'READY_FOR_REVIEW' ? 'text-success' : 'text-danger' ?>">
                                                <i class="fas <?= $row['rule_status'] === 'READY_FOR_REVIEW' ? 'fa-check-circle' : 'fa-exclamation-triangle' ?>"></i>
                                                <?= htmlspecialchars($row['rule_status']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php 
                                        $vStatus = match($row['verification_status']) {
                                            'Verified' => 'bg-success',
                                            'Rejected' => 'bg-danger',
                                            'On Hold' => 'bg-info text-dark',
                                            default => 'bg-warning text-dark'
                                        };
                                        ?>
                                        <span class="badge rounded-pill <?= $vStatus ?> px-3 py-1">
                                            <?= htmlspecialchars($row['verification_status']) ?>
                                        </span>
                                    </td>
                                    <td class="text-end pe-4">
                                        <button type="button" class="btn btn-sm btn-light text-primary shadow-sm" title="Review payment concern" aria-label="Review payment concern #<?= (int)$row['concern_id'] ?>" data-bs-toggle="modal" data-bs-target="#reviewModal<?= $row['concern_id'] ?>">
                                            <i class="ti ti-report-money me-1"></i> Review & Verify
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="payment-table-empty">
                                    <i class="ti ti-circle-check fs-3 mb-2 text-success opacity-50 d-block"></i>
                                    No pending payment concerns to review.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Render Modals Outside Table -->
<?php if (count($concernsList) > 0): ?>
    <?php foreach ($concernsList as $row): ?>
        <div class="modal fade" id="reviewModal<?= $row['concern_id'] ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-xl">
                <div class="modal-content border-0 shadow-lg">
                    <div class="modal-header bg-primary text-white border-0">
                        <h5 class="modal-title fw-bold"><i class="ti ti-receipt me-2"></i>Review Payment Concern #<?= $row['concern_id'] ?></h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    
                    <div class="modal-body bg-light text-start p-0">
                        <div class="row g-0">
                            <!-- LEFT COLUMN: Image & OCR Scan -->
                            <div class="col-lg-4 border-end p-4 bg-white d-flex flex-column">
                                <h6 class="fw-bold text-muted mb-3"><i class="fas fa-image me-2"></i>Receipt Image</h6>
                                <div class="text-center bg-light rounded border flex-grow-1 d-flex align-items-center justify-content-center overflow-hidden position-relative" style="min-height: 400px; max-height: 600px;">
                                    <?php if (!empty($row['receipt_path'])): ?>
                                        <img src="<?= BASE_URL ?>/modules/payment/api/accounting/view-receipt.php?concern_id=<?= (int)$row['concern_id'] ?>" alt="Receipt" class="img-fluid" style="object-fit: contain; max-height: 600px;">
                                    <?php else: ?>
                                        <span class="text-muted"><i class="ti ti-ban fs-3 d-block mb-2"></i>No image attached</span>
                                    <?php endif; ?>
                                </div>
                                <div class="small text-muted mt-3">
                                    <div><strong>Student:</strong> <?= htmlspecialchars($row['full_name']) ?> (<?= htmlspecialchars($row['student_number']) ?>)</div>
                                    <div><strong>Concern submitted:</strong> <?= htmlspecialchars($row['submitted_at']) ?></div>
                                    <div><strong>Linked payment:</strong> <?= $row['payment_id'] ? '#' . (int)$row['payment_id'] . ' · PHP ' . number_format((float)$row['payment_amount'], 2) : 'None yet' ?></div>
                                </div>
                                <div class="mt-3">
                                    <button class="btn btn-outline-primary w-100 fw-bold" onclick="scanConcernOCR(<?= $row['concern_id'] ?>, this, <?= $row['ocr_status'] === 'Failed' ? 'true' : 'false' ?>)" <?= in_array($row['verification_status'], ['Pending', 'On Hold'], true) ? '' : 'disabled' ?>>
                                        <i class="fas fa-robot me-2"></i><?= $row['ocr_status'] === 'Failed' ? 'Retry Google Vision OCR' : 'Run Google Vision OCR Scan' ?>
                                    </button>
                                </div>
                            </div>
                            
                            <!-- RIGHT COLUMN: Data & Verification -->
                            <div class="col-lg-8 p-4 d-flex flex-column">
                                <?php $canDecide = in_array($row['verification_status'], ['Pending', 'On Hold'], true); ?>
                                <form action="" method="POST" class="d-flex flex-column h-100">
                                    <?= csrfField(); ?>
                                    <input type="hidden" name="concern_id" value="<?= $row['concern_id'] ?>">
                                    <?php if ($row['payment_id']): ?><input type="hidden" name="billing_id" value="<?= (int)$row['billing_id'] ?>"><?php endif; ?>

                                    <h6 class="fw-bold text-muted mb-3"><i class="fas fa-clipboard-check me-2"></i>Extracted Data & Verification</h6>
                                    
                                    <div class="bg-white p-3 rounded shadow-sm border mb-4">
                                        <div class="row mb-2">
                                            <div class="col-6 text-muted small fw-bold">Student</div>
                                            <div class="col-6 text-dark fw-bold"><?= htmlspecialchars($row['full_name']) ?> <small class="text-muted fw-normal">(<?= htmlspecialchars($row['student_number']) ?>)</small></div>
                                        </div>
                                        <div class="row mb-2">
                                            <div class="col-6 text-muted small fw-bold">Linked Payment Amount</div>
                                            <div class="col-6 text-primary fw-bold"><?= $row['payment_amount'] !== null ? 'PHP ' . number_format((float)$row['payment_amount'], 2) : 'No linked payment' ?></div>
                                        </div>
                                        <hr class="my-2">
                                        
                                        <!-- OCR Result placeholders -->
                                        <div class="row mb-2">
                                            <div class="col-6 text-muted small fw-bold">OCR Status</div>
                                            <div class="col-6"><span class="badge bg-secondary" id="ocr_status_badge_<?= $row['concern_id'] ?>"><?= htmlspecialchars($row['ocr_status']) ?></span></div>
                                        </div>
                                        <div class="row mb-2">
                                            <div class="col-6 text-muted small fw-bold">OCR Extracted Amount</div>
                                            <div class="col-6 text-success fw-bold" id="ocr_amount_<?= $row['concern_id'] ?>"><?= $row['extracted_amount'] !== null ? 'PHP ' . number_format((float)$row['extracted_amount'], 2) : 'Not extracted' ?></div>
                                        </div>
                                        <div class="row mb-3">
                                            <div class="col-6 text-muted small fw-bold">OCR Reference No.</div>
                                            <div class="col-6 text-dark fw-bold" id="ocr_ref_<?= $row['concern_id'] ?>"><?= htmlspecialchars($row['ocr_ref'] ?? 'Pending Scan') ?></div>
                                        </div>
                                        <div class="row mb-0"><div class="col-6 text-muted small fw-bold">Automated assessment</div><div class="col-6"><span class="badge bg-light text-dark border"><?= htmlspecialchars($row['rule_status'] ?? 'OCR pending') ?></span></div></div>
                                    </div>

                                    <div class="row g-3 mb-3">
                                        <div class="col-md-6">
                                            <div class="bg-white border rounded-3 p-3 h-100">
                                                <h6 class="fw-bold">Google OCR evidence</h6>
                                                <div class="small"><strong>Status:</strong> <?= htmlspecialchars($row['extraction_status'] ?? $row['ocr_status']) ?></div>
                                                <div class="small"><strong>Reference:</strong> <?= htmlspecialchars($row['ocr_ref'] ?? 'Not extracted') ?></div>
                                                <div class="small"><strong>Amount:</strong> <?= $row['extracted_amount'] !== null ? 'PHP ' . number_format((float)$row['extracted_amount'], 2) : 'Not extracted' ?></div>
                                                <div class="small"><strong>Date:</strong> <?= htmlspecialchars($row['transaction_date'] ?? 'Not extracted') ?></div>
                                                <div class="small"><strong>Receipt channel:</strong> <?= htmlspecialchars($row['bank_name'] ?? 'Not extracted') ?></div>
                                                <div class="small mt-2 text-muted">OCR values are evidence only. Compare them with the original receipt before deciding.</div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="bg-white border rounded-3 p-3 h-100">
                                                <h6 class="fw-bold">Review indicators</h6>
                                                <div class="small"><strong>Assessment:</strong> <?= htmlspecialchars($row['rule_status'] ?? 'Pending OCR') ?></div>
                                                <div class="small text-muted mt-2"><?= htmlspecialchars($row['rule_remarks'] ?? 'Run OCR or review the receipt manually.') ?></div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php if ($row['verification_status'] === 'On Hold'): ?>
                                        <div class="alert alert-info py-2"><strong>On hold:</strong> <?= htmlspecialchars($row['hold_reason'] ?? '') ?></div>
                                    <?php endif; ?>

                                    <hr class="my-3">
                                    <h6 class="fw-bold text-primary mb-2"><i class="ti ti-edit me-2"></i>Accounting verified values</h6>
                                    <p class="small text-muted">Confirm these values against the receipt image. OCR does not approve or reject a payment.</p>
                                    <div class="row g-2 mb-3">
                                        <div class="col-md-6">
                                            <label class="form-label small fw-bold text-muted">Verified Amount</label>
                                            <div class="input-group input-group-sm">
                                                <span class="input-group-text">₱</span>
                                                <input type="number" step="0.01" min="0.01" name="verified_amount" class="form-control" value="<?= htmlspecialchars((string)($row['extracted_amount'] ?? $row['payment_amount'] ?? '')) ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small fw-bold text-muted">Reference No.</label>
                                            <input type="text" name="verified_reference" class="form-control form-control-sm" value="<?= htmlspecialchars((string)($row['ocr_ref'] ?? '')) ?>" maxlength="100">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small fw-bold text-muted">Payment Channel</label>
                                            <select name="verified_channel" class="form-select form-select-sm"><option value="">Select channel</option><?php foreach(['GCash','Maya','PayMongo','Bank Transfer','Other'] as $channel): ?><option value="<?= $channel ?>" <?= strcasecmp((string)($row['bank_name']??''),$channel)===0?'selected':'' ?>><?= $channel ?></option><?php endforeach; ?></select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small fw-bold text-muted">Transaction Date</label>
                                            <input type="date" name="verified_date" class="form-control form-control-sm" value="<?= htmlspecialchars((string)($row['transaction_date'] ?? '')) ?>">
                                        </div>
                                    </div>

                                    <?php if (!$row['payment_id'] && $canDecide): ?>
                                        <div class="mb-3">
                                            <label class="form-label fw-bold small">Billing to credit after approval</label>
                                            <select name="billing_id" class="form-select">
                                                <option value="">Select the student's billing</option>
                                                <?php foreach ($billingChoices[(int)$row['student_id']] ?? [] as $bill): ?>
                                                    <option value="<?= (int)$bill['billing_id'] ?>">#<?= (int)$bill['billing_id'] ?> · <?= htmlspecialchars($bill['academic_year']) ?> <?= htmlspecialchars($bill['semester']) ?> · Balance PHP <?= number_format((float)$bill['remaining_balance'], 2) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    <?php endif; ?>

                                    <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="review_confirmed" value="1" id="reviewConfirmed<?= (int)$row['concern_id'] ?>"><label class="form-check-label fw-bold" for="reviewConfirmed<?= (int)$row['concern_id'] ?>">I reviewed the original receipt, entered the verified values, and considered any available OCR evidence.</label></div>

                                    <div class="mb-3">
                                        <label class="form-label fw-bold small text-muted">Review reason / notes (required for Hold or Reject)</label>
                                        <textarea class="form-control" name="remarks" rows="3" placeholder="Describe your investigation or rejection reason"></textarea>
                                    </div>

                                    <div class="mb-4">
                                        <label class="form-label fw-bold small text-muted">Decision <span class="text-danger">*</span></label>
                                        <select class="form-select fw-bold" name="action_concern" required <?= $canDecide ? '' : 'disabled' ?>>
                                            <option value="">Select decision</option>
                                            <option value="Verify">Approve &amp; Verify (Update Ledger)</option>
                                            <option value="Hold">Hold for Investigation</option>
                                            <option value="Reject">Reject Concern</option>
                                        </select>
                                    </div>

                                    <div class="mt-auto pt-3 border-top d-flex gap-2 justify-content-end">
                                        <button type="button" class="btn btn-light border shadow-sm" data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" class="btn btn-primary px-4 fw-bold shadow-sm" <?= $canDecide ? '' : 'disabled' ?>>Submit Decision</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<script>
const CSRF_TOKEN = '<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>';

function scanConcernOCR(concernId, btnElement, retry = false) {
    const originalText = btnElement.innerHTML;
    btnElement.innerHTML = '<i class="ti ti-loader me-2"></i> Scanning...';
    btnElement.disabled = true;

    fetch("<?= BASE_URL ?>/modules/payment/api/accounting/ocr-scan-concern.php", {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ concern_id: concernId, csrf_token: CSRF_TOKEN, retry: retry === true })
    })
    .then(async res => {
        const payload = await res.json();
        if (!res.ok) {
            throw new Error(payload.message || payload.error || 'OCR request failed.');
        }
        return payload;
    })
    .then(data => {
        if (data.success) {
            const badge = document.getElementById('ocr_status_badge_' + concernId);
            if (badge) {
                badge.textContent = 'Completed';
                badge.className = 'badge bg-success';
            }
            btnElement.innerHTML = '<i class="ti ti-circle-check me-2"></i> OCR Scan Complete';
            const hint = document.createElement('div');
            hint.className = 'small text-success mt-2';
            hint.textContent = 'OCR completed. Reopen this concern later to view refreshed extracted details.';
            btnElement.parentElement.append(hint);
        } else {
            alert("OCR Failed: " + (data.message || data.error || "Unknown error"));
            btnElement.innerHTML = originalText;
            btnElement.disabled = false;
        }
    })
    .catch(err => {
        console.error(err);
        alert(err.message || "Network error during OCR scan.");
        btnElement.innerHTML = originalText;
        btnElement.disabled = false;
    });
}
</script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Initialization scripts if needed
    });
</script>

<script src="<?= BASE_URL ?>/modules/payment/assets/js/payment-search.js"></script>
<?php require_once __DIR__ . '/../../../../includes/layout-end.php'; ?>
