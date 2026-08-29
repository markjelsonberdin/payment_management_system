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
requirePaymentPermission('payment.concern_review');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$reviewer_id = $_SESSION['user_id'] ?? 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_concern'])) {
    $concern_id = $_POST['concern_id'];
    $payment_id = $_POST['payment_id'] ?? null;
    $billing_id = $_POST['billing_id'] ?? null;
    $action     = $_POST['action_concern']; // 'Verify' or 'Reject'
    $remarks    = trim($_POST['remarks'] ?? '');

    $verifiedData = [
        'amount' => $_POST['verified_amount'] ?? null,
        'reference' => $_POST['verified_reference'] ?? null,
        'channel' => $_POST['verified_channel'] ?? null,
        'date' => $_POST['verified_date'] ?? null,
    ];

    try {
        $concernService = new PaymentConcernService($pdo);
        
        if ($action === 'Verify') {
            $concernService->verifyConcern($concern_id, 'Verify', $reviewer_id, $remarks, $billing_id, $verifiedData);
            
            $stmtLog = $pdo->prepare("
                INSERT INTO audit_logs (user_id, action, module, description, ip_address)
                VALUES (:uid, 'Verify Online Payment Concern', 'Payment Management', :desc, :ip)
            ");
            $stmtLog->execute([
                ':uid' => $reviewer_id,
                ':desc' => "Verified payment concern ID #{$concern_id}",
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
            ]);
        } else {
            $concernService->verifyConcern($concern_id, 'Reject', $reviewer_id, $remarks);
        }

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
        if ($concern['verification_status'] === 'Pending' && $concern['ocr_status'] === 'Completed') {
            $eval = $ruleEngine->evaluateConcern($concern['concern_id']);
            $concern['rule_status'] = $eval['status'];
            $concern['rule_remarks'] = $eval['remarks'];
        }
    }

} catch (Exception $e) {
    $concernsList = [];
    $dbError = $e->getMessage();
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

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="row mb-4 align-items-center">
        <div class="col-md-8">
            <h2 class="mb-1 fw-bolder"><i class="fas fa-headset text-primary me-2"></i>Payment Concern Portal</h2>
            <p class="text-muted mb-0 fs-6">Review student-submitted payment receipts, analyze Google OCR extractions, and verify bank transfers.</p>
        </div>
        <div class="col-md-4 text-md-end mt-3 mt-md-0">
            <div class="input-group w-auto shadow-sm">
                <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                <input type="text" class="form-control border-start-0 ps-0 table-live-search-input" data-table-target="#concernsTable" placeholder="Search concern or student...">
            </div>
        </div>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible shadow-sm"><i class="fas fa-check-circle me-2"></i> Payment concern successfully updated! <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger alert-dismissible shadow-sm"><i class="fas fa-exclamation-circle me-2"></i> <?= htmlspecialchars($_GET['error']) ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <!-- Table of Concerns -->
    <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
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
                                        <div class="fw-bold text-dark"><?= htmlspecialchars($row['full_name']) ?>
                                        <small class="text-muted"><?= htmlspecialchars($row['student_number']) ?></small>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-dark">₱ <?= number_format($row['payment_amount'], 2) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($row['payment_channel']) ?></small>
                                    </td>
                                    <td>
                                        <div class="text-dark small"><strong>Bank:</strong> <?= htmlspecialchars($row['bank_name'] ?? 'N/A') ?></div>
                                        <div class="text-dark small"><strong>Ref:</strong> <?= htmlspecialchars($row['ocr_ref'] ?? 'N/A') ?></div>
                                        <div class="text-muted" style="font-size: 0.75rem;">Confidence: <?= $row['confidence_score'] ? $row['confidence_score'] . '%' : 'N/A' ?></div>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-info text-dark px-2 py-1 mb-1"><?= htmlspecialchars($row['ocr_status']) ?></span>
                                        <?php if (isset($row['rule_status'])): ?>
                                            <div class="small fw-bold <?= $row['rule_status'] === 'Valid for Review' ? 'text-success' : 'text-danger' ?>">
                                                <i class="fas <?= $row['rule_status'] === 'Valid for Review' ? 'fa-check-circle' : 'fa-exclamation-triangle' ?>"></i> 
                                                <?= htmlspecialchars($row['rule_status']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php 
                                        $vStatus = match($row['verification_status']) {
                                            'Verified' => 'bg-success',
                                            'Rejected' => 'bg-danger',
                                            default => 'bg-warning text-dark'
                                        };
                                        ?>
                                        <span class="badge rounded-pill <?= $vStatus ?> px-3 py-1">
                                            <?= htmlspecialchars($row['verification_status']) ?>
                                        </span>
                                    </td>
                                    <td class="text-end pe-4">
                                        <button type="button" class="btn btn-sm btn-light text-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#reviewModal<?= $row['concern_id'] ?>">
                                            <i class="fas fa-search-dollar me-1"></i> Review & Verify
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="py-5 text-center text-muted">
                                    <i class="fas fa-check-circle fs-3 mb-2 text-success opacity-50 d-block"></i>
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
            <div class="modal-dialog modal-dialog-centered modal-xl">
                <div class="modal-content border-0 shadow-lg">
                    <div class="modal-header bg-primary text-white border-0">
                        <h5 class="modal-title fw-bold"><i class="fas fa-receipt me-2"></i>Review Payment Concern #<?= $row['concern_id'] ?></h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    
                    <div class="modal-body bg-light text-start p-0">
                        <div class="row g-0">
                            <!-- LEFT COLUMN: Image & OCR Scan -->
                            <div class="col-lg-6 border-end p-4 bg-white d-flex flex-column">
                                <h6 class="fw-bold text-muted mb-3"><i class="fas fa-image me-2"></i>Receipt Image</h6>
                                <div class="text-center bg-light rounded border flex-grow-1 d-flex align-items-center justify-content-center overflow-hidden position-relative" style="min-height: 400px; max-height: 600px;">
                                    <?php if (!empty($row['receipt_path'])): ?>
                                        <img src="<?= BASE_URL . '/' . htmlspecialchars($row['receipt_path']) ?>" alt="Receipt" class="img-fluid" style="object-fit: contain; max-height: 600px;">
                                    <?php else: ?>
                                        <span class="text-muted"><i class="fas fa-ban fs-3 d-block mb-2"></i>No image attached</span>
                                    <?php endif; ?>
                                </div>
                                <div class="mt-3">
                                    <button class="btn btn-outline-primary w-100 fw-bold" onclick="scanConcernOCR(<?= $row['concern_id'] ?>, this)">
                                        <i class="fas fa-robot me-2"></i>Run Google Vision OCR Scan
                                    </button>
                                </div>
                            </div>
                            
                            <!-- RIGHT COLUMN: Data & Verification -->
                            <div class="col-lg-6 p-4 d-flex flex-column">
                                <form action="" method="POST" class="d-flex flex-column h-100">
                                    <?= csrfField(); ?>
                                    <input type="hidden" name="concern_id" value="<?= $row['concern_id'] ?>">
                                    <input type="hidden" name="payment_id" value="<?= $row['payment_id'] ?>">
                                    <input type="hidden" name="billing_id" value="<?= $row['billing_id'] ?>">

                                    <h6 class="fw-bold text-muted mb-3"><i class="fas fa-clipboard-check me-2"></i>Extracted Data & Verification</h6>
                                    
                                    <div class="bg-white p-3 rounded shadow-sm border mb-4">
                                        <div class="row mb-2">
                                            <div class="col-6 text-muted small fw-bold">Student</div>
                                            <div class="col-6 text-dark fw-bold"><?= htmlspecialchars($row['full_name']) ?> <small class="text-muted fw-normal">(<?= htmlspecialchars($row['student_number']) ?>)</small></div>
                                        </div>
                                        <div class="row mb-2">
                                            <div class="col-6 text-muted small fw-bold">Claimed Amount</div>
                                            <div class="col-6 text-primary fw-bold">₱ <?= number_format($row['payment_amount'], 2) ?></div>
                                        </div>
                                        <hr class="my-2">
                                        
                                        <!-- OCR Result placeholders -->
                                        <div class="row mb-2">
                                            <div class="col-6 text-muted small fw-bold">OCR Status</div>
                                            <div class="col-6"><span class="badge bg-secondary" id="ocr_status_badge_<?= $row['concern_id'] ?>"><?= htmlspecialchars($row['ocr_status']) ?></span></div>
                                        </div>
                                        <div class="row mb-2">
                                            <div class="col-6 text-muted small fw-bold">OCR Extracted Amount</div>
                                            <div class="col-6 text-success fw-bold" id="ocr_amount_<?= $row['concern_id'] ?>">₱ <?= number_format((float)($row['ocr_amount'] ?? 0), 2) ?></div>
                                        </div>
                                        <div class="row mb-0">
                                            <div class="col-6 text-muted small fw-bold">OCR Reference No.</div>
                                            <div class="col-6 text-dark fw-bold" id="ocr_ref_<?= $row['concern_id'] ?>"><?= htmlspecialchars($row['ocr_ref'] ?? 'Pending Scan') ?></div>
                                        </div>
                                    </div>

                                    <hr class="my-3">
                                    <h6 class="fw-bold text-primary mb-3"><i class="fas fa-edit me-2"></i>Accounting Verified Data</h6>
                                    <div class="row g-2 mb-3">
                                        <div class="col-md-6">
                                            <label class="form-label small fw-bold text-muted">Verified Amount</label>
                                            <div class="input-group input-group-sm">
                                                <span class="input-group-text">₱</span>
                                                <input type="number" step="0.01" class="form-control" name="verified_amount" value="<?= htmlspecialchars($row['payment_amount'] ?? $row['ocr_amount'] ?? '') ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small fw-bold text-muted">Reference No.</label>
                                            <input type="text" class="form-control form-control-sm" name="verified_reference" value="<?= htmlspecialchars($row['ocr_ref'] ?? '') ?>">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small fw-bold text-muted">Payment Channel / Bank</label>
                                            <input type="text" class="form-control form-control-sm" name="verified_channel" value="<?= htmlspecialchars($row['payment_channel'] ?? $row['bank_name'] ?? '') ?>">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small fw-bold text-muted">Transaction Date</label>
                                            <input type="date" class="form-control form-control-sm" name="verified_date" value="<?= htmlspecialchars($row['transaction_date'] ?? date('Y-m-d')) ?>">
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label fw-bold small text-muted">Accounting Remarks / Notes</label>
                                        <textarea class="form-control" name="remarks" rows="3" placeholder="Optional notes for verification..."><?= htmlspecialchars($row['remarks'] ?? '') ?></textarea>
                                    </div>

                                    <div class="mb-4">
                                        <label class="form-label fw-bold small text-muted">Decision <span class="text-danger">*</span></label>
                                        <select class="form-select fw-bold" name="action_concern" required>
                                            <option value="Verify" selected>Approve & Verify (Update Ledger)</option>
                                            <option value="Reject">Reject Concern</option>
                                        </select>
                                    </div>

                                    <div class="mt-auto pt-3 border-top d-flex gap-2 justify-content-end">
                                        <button type="button" class="btn btn-light border shadow-sm" data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" class="btn btn-primary px-4 fw-bold shadow-sm">Submit Decision</button>
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
function scanConcernOCR(concernId, btnElement) {
    const originalText = btnElement.innerHTML;
    btnElement.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Scanning...';
    btnElement.disabled = true;

    fetch("<?= BASE_URL ?>/modules/payment/api/accounting/ocr-scan-concern.php", {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ concern_id: concernId })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            // Update UI with extracted data
            document.getElementById('ocr_status_badge_' + concernId).className = 'badge bg-success';
            document.getElementById('ocr_status_badge_' + concernId).innerText = 'Completed';
            
            const amt = data.data.amount ? '₱ ' + parseFloat(data.data.amount).toLocaleString('en-US', {minimumFractionDigits: 2}) : 'Not Found';
            document.getElementById('ocr_amount_' + concernId).innerText = amt;
            document.getElementById('ocr_ref_' + concernId).innerText = data.data.reference || 'Not Found';
            
            // Auto-fill verified fields inside this specific modal
            const modal = document.getElementById('reviewModal' + concernId);
            if (modal) {
                if (data.data.amount) {
                    const amtInput = modal.querySelector(`input[name="verified_amount"]`);
                    if(amtInput) amtInput.value = data.data.amount;
                }
                if (data.data.reference) {
                    const refInput = modal.querySelector(`input[name="verified_reference"]`);
                    if(refInput) refInput.value = data.data.reference;
                }
                if (data.data.bank) {
                    const bankInput = modal.querySelector(`input[name="verified_channel"]`);
                    if(bankInput) bankInput.value = data.data.bank;
                }
            }
            
            btnElement.innerHTML = '<i class="fas fa-check text-success me-2"></i> Scan Complete';
            setTimeout(() => {
                btnElement.innerHTML = originalText;
                btnElement.disabled = false;
            }, 3000);
        } else {
            alert("OCR Failed: " + (data.error || "Unknown error"));
            btnElement.innerHTML = originalText;
            btnElement.disabled = false;
        }
    })
    .catch(err => {
        console.error(err);
        alert("Network error during OCR scan.");
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

<script src="<?= BASE_URL ?>/assets/js/payment-search.js"></script>

<?php require_once __DIR__ . '/../../../../includes/layout-end.php'; ?>