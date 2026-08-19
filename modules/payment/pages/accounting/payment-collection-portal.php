<?php
/**
 * SMS 2 - Collection Reporting & Analytics
 * Module: Payment Management
 */
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../includes/authentication.php';
require_once __DIR__ . '/../../../../includes/audit.php';
require_once __DIR__ . '/../../database/db_connect.php';
require_once __DIR__ . '/../../includes/PaymentAllocationService.php';

requireAuth();
requirePaymentPermission('payment.collection');

// Tinanggal ko ang session_start() dito dahil karaniwang nasa authentication.php o config.php na ito.
// Kung mag-throw ng error na walang session, ibalik mo lang sa baba ng requireModuleAccess.
$cashier_id = $_SESSION['user_id'] ?? 1; // Fallback user ID kung sakaling walang active session

// ==========================================
// BACKEND: PROCESS WALK-IN PAYMENT
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_payment'])) {
    
    $billing_id       = $_POST['billing_id'] ?? '';
    $student_id       = $_POST['student_id'] ?? '';
    
    $amount_paid      = (float) $_POST['amount_paid'];
    $cash_received    = (float) ($_POST['cash_received'] ?? $amount_paid);
    $payment_context  = $_POST['payment_context'] ?? 'Enrollment';
    $category_id      = isset($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $payment_channel  = $_POST['payment_channel']; // Cash, GCash, etc.
    $reference_number = trim($_POST['reference_number']) ?: 'OR-' . date('Ymd') . '-' . rand(1000, 9999);
    $remarks          = trim($_POST['remarks']);

    try {
        // Idagdag itong checker na ito
        if (empty($billing_id)) {
            throw new Exception("Cache Error: Walang naipasang Billing ID ang form! Paki-Hard Refresh (CTRL + F5) ang iyong browser.");
        }

        // Tinanggal na ang $pdo->beginTransaction(); dito dahil hawak na ito ng Allocation Service

        // 1. Validate Billing (Service will handle FOR UPDATE locking on items)
        $stmtBill = $pdo->prepare("SELECT remaining_balance, billing_type FROM billing WHERE billing_id = :id");
        $stmtBill->execute([':id' => $billing_id]);
        $bill = $stmtBill->fetch();

        if (!$bill) {
            throw new Exception("Billing record not found.");
        }

        // Validate backend Context
        if ($payment_context === 'Enrollment' && $bill['billing_type'] !== 'Enrollment') {
            throw new Exception("Invalid Context: Cannot apply Enrollment Priority Mode to a non-enrollment billing.");
        }

        if ($amount_paid <= 0) {
            throw new Exception("Please enter a valid payment amount.");
        }

        if ($cash_received < $amount_paid) {
            throw new Exception("Cash received (₱".number_format($cash_received, 2).") cannot be less than the amount applied to balance (₱".number_format($amount_paid, 2).").");
        }

        $change_amount = $cash_received - $amount_paid;

        // Start transaction for atomic payment record + allocation
        $pdo->beginTransaction();

        // 2. Insert main payment record
        $stmtPayment = $pdo->prepare("
            INSERT INTO payments (student_id, billing_id, verified_by, transaction_type, payment_method, amount, cash_received, change_amount, payment_channel, reference_number, payment_status, payment_date, receipt_number, remarks, verified_at)
            VALUES (:student_id, :billing_id, :verified_by, 'Walk-in', 'Walk-in', :amount, :cash_received, :change_amount, :channel, :ref, 'Verified', CURDATE(), :receipt, :remarks, CURRENT_TIMESTAMP)
        ");
        $stmtPayment->execute([
            ':student_id' => $student_id,
            ':billing_id' => $billing_id,
            ':verified_by' => $cashier_id,
            ':amount' => $amount_paid,
            ':cash_received' => $cash_received,
            ':change_amount' => $change_amount,
            ':channel' => $payment_channel,
            ':ref' => $reference_number,
            ':receipt' => $reference_number,
            ':remarks' => $remarks
        ]);

        $payment_id = $pdo->lastInsertId();

        // 3. Call the Payment Allocation Engine
        $allocationService = new PaymentAllocationService($pdo);
        // Signature: allocatePayment($paymentId, $studentId, $billingId, $amountPaid, $context, $categoryId)
        $allocationService->allocatePayment($payment_id, $student_id, $billing_id, $amount_paid, $payment_context, $category_id);

        // 4. Immutable Audit Log (SMS2 centralized activity_logs)
        $stmtLog = $pdo->prepare("
            INSERT INTO activity_logs (user_id, action, module, description, ip_address)
            VALUES (:uid, 'Process Walk-in Payment', 'Payment Management', :desc, :ip)
        ");
        $stmtLog->execute([
            ':uid' => $cashier_id,
            ':desc' => "Processed walk-in payment of ₱" . number_format($amount_paid, 2) . " (Context: {$payment_context}) for Billing ID #{$billing_id} with OR No: {$reference_number}",
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
        ]);

        $pdo->commit();

        header("Location: payment-collection-portal.php?success=1&or=" . urlencode($reference_number));
        exit();

    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        header("Location: payment-collection-portal.php?error=" . urlencode($e->getMessage()));
        exit();
    }
}

$pageTitle    = 'Payment Collection Portal';
$activeModule = 'payment';
$activePage   = 'accounting/payment-collection-portal';
$breadcrumbs  = [
    ['label' => 'Payment Management', 'url' => BASE_URL . '/modules/payment/index.php'],
    ['label' => 'Payment Collection Portal', 'url' => null],
];

require_once __DIR__ . '/../../../../includes/breadcrumbs.php';
require_once __DIR__ . '/../../../../includes/layout-start.php';
?>

<?php renderBreadcrumbs($breadcrumbs); ?>

<div class="container-fluid py-4">
    <!-- Page Header -->
    <div class="row mb-4 align-items-center">
        <div class="col-md-8">
            <h2 class="mb-1 fw-bolder"><p class="fas fa-cash-register text-primary me-2"></p>Walk-In Payment Collection</h2>
            <p class="text-muted mb-0 fs-6">Receive physical cash or check payments, compute balances, and issue Official Receipts (OR).</p>
        </div>
    </div>

    <!-- Alerts -->
    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible shadow-sm">
            <i class="fas fa-check-circle me-2"></i> Payment successfully processed! Official Receipt <strong>#<?= htmlspecialchars($_GET['or'] ?? '') ?></strong> generated.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger alert-dismissible shadow-sm">
            <i class="fas fa-exclamation-circle me-2"></i> <?= htmlspecialchars($_GET['error']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- LEFT COLUMN: Search Student & Billing Information -->
        <div class="col-lg-5 mb-4">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-3"><i class="fas fa-search text-primary me-2"></i>1. Search Student Record</h5>
                    
                    <div class="input-group mb-3">
                        <span class="input-group-text bg-light"><i class="fas fa-user-graduate"></i></span>
                        <input type="text" class="form-control" id="searchStudentNumber" placeholder="Enter Student Number (e.g. S230106713)" autocomplete="off">
                        <button class="btn btn-primary px-4 fw-bold" type="button" id="btnSearchStudent">Find</button>
                    </div>

                    <!-- Billing Details Box (Dynamic) -->
                    <div id="studentBillingInfo" class="d-none mt-4">
                        <hr class="text-muted opacity-25">
                        <div class="bg-light p-3 rounded-3 mb-3">
                            <h6 class="fw-bold text-dark mb-1" id="lblStudentName">---</h6>
                            <p class="text-muted small mb-1">Student No: <span class="fw-bold text-dark" id="lblStudentNo">---</span></p>
                            <p class="text-muted small mb-0">Course / Year: <span class="fw-bold text-dark" id="lblCourseYear">---</span></p>
                        </div>

                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Billing Reference:</span>
                            <span class="fw-bold text-primary" id="lblBillingId">---</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Billing Type & Term:</span>
                            <span class="fw-bold text-dark" id="lblBillingTerm">---</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Total Assessment:</span>
                            <span class="fw-bold text-dark" id="lblTotalAmount">₱ 0.00</span>
                        </div>
                        <div class="d-flex justify-content-between mb-3 border-bottom pb-3">
                            <span class="text-muted">Current Balance Due:</span>
                            <span class="fw-bold text-danger fs-5" id="lblRemainingBalance">₱ 0.00</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- RIGHT COLUMN: Payment Encoding & OR Issuance -->
        <div class="col-lg-7 mb-4">
            <div class="card border-0 shadow-sm rounded-4 h-100 opacity-50" id="paymentPanel" style="pointer-events: none;">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-3"><p class="fas fa-money-bill-wave text-success me-2"></p>2. Receive Payment & Issue OR</h5>
                    
                    <form action="" method="POST" id="paymentForm">
                        <input type="hidden" name="billing_id" id="inputBillingId">
                        <input type="hidden" name="student_id" id="inputStudentId">

                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label class="form-label fw-bold small text-muted">Payment Context <span class="text-danger">*</span></label>
                                <select class="form-select" name="payment_context" id="inputPaymentContext" required onchange="document.getElementById('categorySelectionWrapper').classList.toggle('d-none', this.value !== 'DesignatedCategory')">
                                    <option value="Enrollment" selected>Enrollment Priority (RFID &rarr; Misc &rarr; Lab)</option>
                                    <option value="DesignatedCategory">Designated Category (Specific Fee Only)</option>
                                </select>
                            </div>

                            <div class="col-md-12 mb-3 d-none" id="categorySelectionWrapper">
                                <label class="form-label fw-bold small text-muted">Select Specific Category <span class="text-danger">*</span></label>
                                <select class="form-select" name="category_id" id="inputCategoryId">
                                    <option value="2">Miscellaneous</option>
                                    <option value="3">Laboratory & Computer</option>
                                    <option value="4">Student Council & Organization</option>
                                    <option value="5">Supplementary Fees</option>
                                    <option value="6">Other</option>
                                </select>
                                <small class="text-muted d-block mt-1">Payment will strictly be allocated only to items under this category.</small>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold small text-muted">Amount Applied to Balance (₱) <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-white text-success fw-bold">₱</span>
                                    <input type="number" step="0.01" class="form-control fw-bold fs-5 text-success" name="amount_paid" id="inputAmountPaid" placeholder="0.00" required>
                                </div>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold small text-muted">Cash Received (₱)</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-white fw-bold">₱</span>
                                    <input type="number" step="0.01" class="form-control fw-bold fs-5" name="cash_received" id="inputCashReceived" placeholder="Amount tendered">
                                </div>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold small text-muted">Payment Channel <span class="text-danger">*</span></label>
                                <select class="form-select" name="payment_channel" required>
                                    <option value="Cash" selected>Cash (Walk-in)</option>
                                    <option value="Bank">Bank Deposit / OTC</option>
                                    <option value="GCash">GCash Counter</option>
                                </select>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold small text-muted">Official Receipt (OR) / Ref No.</label>
                                <input type="text" class="form-control" name="reference_number" placeholder="e.g. OR-2026-98765">
                                <small class="text-muted" style="font-size: 0.75rem;">Auto-generated if left blank.</small>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold small text-muted">Change / Excess Calculation</label>
                                <div class="p-2 bg-light rounded border text-end">
                                    <span class="fw-bolder fs-5 text-dark" id="lblChangeAmount">₱ 0.00</span>
                                </div>
                            </div>

                            <div class="col-12 mb-4">
                                <label class="form-label fw-bold small text-muted">Cashier Remarks / Notes</label>
                                <textarea class="form-control" name="remarks" rows="2" placeholder="Optional payment notes..."></textarea>
                            </div>
                        </div>

                        <div class="text-end">
                            <button type="submit" name="process_payment" class="btn btn-success fw-bold px-5 py-2 shadow-sm" id="btnProcessPayment" disabled>
                                <i class="fas fa-print me-1"></i> Complete Payment & Print OR
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Dinagdagan ng ?v=time() para laging fresh ang basahin ng browser na JavaScript file -->
<script src="../../assets/js/payment-collection.js?v=<?= time() ?>"></script>
<script src="<?= BASE_URL ?>/assets/js/payment-search.js"></script>
<?php require_once __DIR__ . '/../../../../includes/layout-end.php'; ?>