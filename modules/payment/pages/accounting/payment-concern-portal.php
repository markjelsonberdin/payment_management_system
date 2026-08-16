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
requireModuleAccess('payment');
session_start();

$reviewer_id = $_SESSION['user_id'] ?? 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_concern'])) {
    $concern_id = $_POST['concern_id'];
    $payment_id = $_POST['payment_id'];
    $billing_id = $_POST['billing_id'];
    $action     = $_POST['action_concern']; // 'Verify' or 'Reject'
    $remarks    = trim($_POST['remarks'] ?? '');

    try {
        $concernService = new PaymentConcernService($pdo);
        
        if ($action === 'Verify') {
            $concernService->verifyConcern($concern_id, 'Verify', $reviewer_id, $remarks, $billing_id);
            
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

                                <!-- REVIEW & VERIFY MODAL PER ROW -->
                                <div class="modal fade" id="reviewModal<?= $row['concern_id'] ?>" tabindex="-1" aria-hidden="true">
                                    <div class="modal-dialog modal-dialog-centered">
                                        <div class="modal-content border-0 shadow">
                                            <div class="modal-header bg-primary text-white border-0">
                                                <h5 class="modal-title fw-bold"><i class="fas fa-receipt me-2"></i>Review Payment Concern #<?= $row['concern_id'] ?></h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                            </div>
                                            <form action="" method="POST">
                                                <div class="modal-body bg-light text-start">
                                                    <input type="hidden" name="concern_id" value="<?= $row['concern_id'] ?>">
                                                    <input type="hidden" name="payment_id" value="<?= $row['payment_id'] ?>">
                                                    <input type="hidden" name="billing_id" value="<?= $row['billing_id'] ?>">

                                                    <div class="mb-3 bg-white p-3 rounded shadow-sm">
                                                        <p class="mb-1 text-muted small">Student: <strong class="text-dark"><?= htmlspecialchars($row['full_name']) ?> (<?= htmlspecialchars($row['student_number']) ?>)</strong></p>
                                                        <p class="mb-1 text-muted small">Declared Amount: <strong class="text-success">₱ <?= number_format($row['payment_amount'], 2) ?></strong></p>
                                                        <p class="mb-0 text-muted small">OCR Extracted Ref: <strong class="text-dark"><?= htmlspecialchars($row['ocr_ref'] ?? 'None') ?></strong></p>
                                                    </div>

                                                    <div class="mb-3 text-center">
                                                        <label class="form-label fw-bold small text-muted d-block">Uploaded Proof of Payment:</label>
                                                        <?php if (!empty($row['receipt_path'])): ?>
                                                            <a href="<?= BASE_URL . '/' . htmlspecialchars($row['receipt_path']) ?>" target="_blank" class="btn btn-sm btn-outline-primary mb-2">
                                                                <i class="fas fa-external-link-alt me-1"></i> View Receipt Image in New Tab
                                                            </a>
                                                        <?php else: ?>
                                                            <span class="text-muted small">No receipt image attached.</span>
                                                        <?php endif; ?>
                                                    </div>

                                                    <div class="mb-3">
                                                        <label class="form-label fw-bold small text-muted">Accounting Remarks / Notes</label>
                                                        <textarea class="form-control" name="remarks" rows="2" placeholder="Optional notes for verification..."><?= htmlspecialchars($row['remarks'] ?? '') ?></textarea>
                                                    </div>

                                                    <div class="mb-3">
                                                        <label class="form-label fw-bold small text-muted">Decision <span class="text-danger">*</span></label>
                                                        <select class="form-select fw-bold" name="action_concern" required>
                                                            <option value="Verify" selected>Approve & Verify (Update Ledger)</option>
                                                            <option value="Reject">Reject Concern</option>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="modal-footer border-0 bg-white">
                                                    <button type="button" class="btn btn-light border shadow-sm" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="submit" class="btn btn-primary px-4 fw-bold shadow-sm">Submit Decision</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">
                                    <i class="fas fa-file-invoice fs-1 mb-3 d-block text-light"></i>
                                    <h5 class="fw-bold text-secondary">No payment concerns or online receipts found.</h5>
                                    <p class="mb-0">Submitted receipts from student portals awaiting review will appear here.</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Initialization scripts if needed
    });
</script>

<script src="<?= BASE_URL ?>/assets/js/payment-search.js"></script>

<?php require_once __DIR__ . '/../../../../includes/layout-end.php'; ?>