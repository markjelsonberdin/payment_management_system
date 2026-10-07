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
require_once __DIR__ . '/../../includes/PaymentSecurityService.php';
require_once __DIR__ . '/../../includes/PaymentNotificationService.php';
require_once __DIR__ . '/../../includes/OfficialReceiptService.php';

requireAuth();
requirePaymentPermission('payment.collection');

// Tinanggal ko ang session_start() dito dahil karaniwang nasa authentication.php o config.php na ito.
// Kung mag-throw ng error na walang session, ibalik mo lang sa baba ng requireModuleAccess.
$cashier_id = (int) getCurrentUserId();
if ($cashier_id <= 0) {
    http_response_code(403);
    exit('Cashier account required.');
}
$parseCashierMoney = static function ($value, string $field): float {
    if (!is_string($value) && !is_int($value) && !is_float($value)) {
        throw new InvalidArgumentException($field . ' must be a valid amount.');
    }
    $raw = trim((string) $value);
    if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $raw)) {
        throw new InvalidArgumentException($field . ' must be a positive amount with up to two decimal places.');
    }
    return (float) $raw;
};
$schoolSalesEnabled = paymentSchoolSalesSellingEnabled();
$canSellSchoolItems = $schoolSalesEnabled
    && paymentRoleAllowsPermission(getCurrentUserRoleKey(), 'payment.school_sales')
    && userCanAccessModule('payment.school_sales');
$requestedView = $_GET['view'] ?? 'academic';
$cashierView = 'academic';
if ($requestedView === 'school-items') {
    if (!$schoolSalesEnabled) {
        http_response_code(404);
        exit('School item sales are unavailable.');
    }
    if (!$canSellSchoolItems) {
        http_response_code(403);
        exit('You do not have permission to sell school items.');
    }
    $cashierView = 'school-items';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_sale'])) {
    if (!$schoolSalesEnabled) {
        http_response_code(404);
        exit('School item sales are unavailable.');
    }
    if (!$canSellSchoolItems) {
        http_response_code(403);
        exit('You do not have permission to sell school items.');
    }
    try {
        if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) throw new RuntimeException('Invalid CSRF token.');
        $items = json_decode((string) ($_POST['items_json'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        require_once __DIR__ . '/../../includes/CashSaleService.php';
        $sale = (new CashSaleService($pdo))->create((int) ($_POST['student_id'] ?? 0), $cashier_id, $items, (float) ($_POST['cash_received'] ?? 0), trim((string) ($_POST['remarks'] ?? '')), trim((string) ($_POST['idempotency_key'] ?? '')));
        if (!($sale['duplicate'] ?? false)) {
            (new PaymentNotificationService($pdo))->notifySchoolSale((int) $_POST['student_id'], $sale['cash_sale_id'], $sale['total'], $sale['receipt_number']);
            logActivity('process_cash_school_sale', 'Processed school sale of PHP ' . number_format($sale['total'], 2) . ' with OR ' . $sale['receipt_number'], 'payment', $cashier_id);
        }
        header('Location: print-receipt.php?cash_sale_id=' . (int) $sale['cash_sale_id'] . '&autoprint=1'); exit();
    } catch (Throwable $e) {
        header('Location: payment-collection-portal.php?view=school-items&error=' . urlencode($e->getMessage())); exit();
    }
}

$recentSales = [];
$salesHistoryUnavailable = false;
if ($cashierView === 'school-items') {
    try {
        $recentSalesStmt = $pdo->prepare("SELECT cs.cash_sale_id, cs.receipt_number, cs.sold_at, cs.total_amount, s.full_name, s.student_number, COALESCE(items.item_names, '') AS items
            FROM cash_sales cs
            JOIN students s ON s.student_id = cs.student_id
            LEFT JOIN (
                SELECT cash_sale_id, GROUP_CONCAT(item_name_snapshot ORDER BY cash_sale_line_id SEPARATOR ', ') AS item_names
                FROM cash_sale_items GROUP BY cash_sale_id
            ) items ON items.cash_sale_id = cs.cash_sale_id
            WHERE cs.cashier_id = ? AND cs.sale_status = 'Completed'
            ORDER BY cs.sold_at DESC, cs.cash_sale_id DESC LIMIT 10");
        $recentSalesStmt->execute([$cashier_id]);
        $recentSales = $recentSalesStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('Cashier recent school sales: ' . $e->getMessage());
        $salesHistoryUnavailable = true;
    }
}

// ==========================================
// BACKEND: PROCESS WALK-IN PAYMENT
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_payment'])) {
    
    // CSRF Protection Check
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Invalid CSRF token.');
    }

    try {
        $billing_id = $_POST['billing_id'] ?? '';
        $student_id = $_POST['student_id'] ?? '';
        if (!is_scalar($billing_id) || !preg_match('/^\d+$/D', (string) $billing_id)) {
            throw new InvalidArgumentException('Billing record is required. Refresh and select the student again.');
        }
        if (!is_scalar($student_id) || !preg_match('/^\d+$/D', (string) $student_id)) {
            throw new InvalidArgumentException('Student record is required.');
        }

        $amount_paid = $parseCashierMoney($_POST['amount_paid'] ?? null, 'Amount applied');
        $cashReceivedInput = $_POST['cash_received'] ?? '';
        if (!is_scalar($cashReceivedInput)) {
            throw new InvalidArgumentException('Cash received must be a valid amount.');
        }
        $cashReceivedInput = trim((string) $cashReceivedInput);
        $cash_received = $cashReceivedInput === '' ? $amount_paid : $parseCashierMoney($cashReceivedInput, 'Cash received');
        $payment_context = $_POST['payment_context'] ?? 'GENERAL_PRIORITY';
        $categoryInput = $_POST['category_id'] ?? null;
        $itemInput = $_POST['item_id'] ?? null;
        $category_id = $categoryInput !== null && is_scalar($categoryInput) && preg_match('/^\d+$/D', (string) $categoryInput) ? (int) $categoryInput : null;
        $item_id = $itemInput !== null && is_scalar($itemInput) && preg_match('/^\d+$/D', (string) $itemInput) ? (int) $itemInput : null;
        $idempotencyInput = $_POST['idempotency_key'] ?? '';
        $idempotency_key = is_scalar($idempotencyInput) ? strtolower(trim((string) $idempotencyInput)) : '';
        // Cashier collection is cash-only. Never accept channel or OR data from the browser.
        $payment_channel = 'Cash';
        $remarksInput = $_POST['remarks'] ?? '';
        if (!is_scalar($remarksInput)) {
            throw new InvalidArgumentException('Remarks must be plain text.');
        }
        $remarks = trim((string) $remarksInput);

        if (empty($billing_id)) {
            throw new Exception("Cache Error: Walang naipasang Billing ID ang form! Paki-Hard Refresh (CTRL + F5) ang iyong browser.");
        }
        if ((int) $student_id <= 0) {
            throw new Exception('Student record is required.');
        }
        if (!preg_match('/^[a-f0-9]{64}$/D', $idempotency_key)) {
            throw new RuntimeException('This payment form expired. Refresh the page and try again.');
        }
        if (!is_string($payment_context) || !in_array($payment_context, ['GENERAL_PRIORITY', 'ENROLLMENT_PRIORITY', 'CATEGORY_PRIORITY', 'SPECIFIC_ITEM'], true)) {
            throw new InvalidArgumentException('Select a valid payment context.');
        }

        $requestFingerprint = hash('sha256', json_encode([
            'student_id' => (int) $student_id,
            'billing_id' => (int) $billing_id,
            'amount_paid' => number_format($amount_paid, 2, '.', ''),
            'cash_received' => number_format($cash_received, 2, '.', ''),
            'payment_context' => $payment_context,
            'category_id' => $category_id,
            'item_id' => $item_id,
            'remarks' => $remarks,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        // Start transaction for atomic payment record + allocation
        $pdo->beginTransaction();

        // Claim this exact cashier submission before touching the billing balance.
        // The database unique key serializes simultaneous retries across requests.
        $claim = $pdo->prepare("INSERT INTO cashier_payment_idempotency (cashier_user_id, idempotency_key, request_fingerprint, payment_id)
            VALUES (?, ?, ?, NULL)
            ON DUPLICATE KEY UPDATE idempotency_key = VALUES(idempotency_key)");
        $claim->execute([$cashier_id, $idempotency_key, $requestFingerprint]);
        $claimLookup = $pdo->prepare('SELECT request_fingerprint, payment_id FROM cashier_payment_idempotency WHERE cashier_user_id = ? AND idempotency_key = ? FOR UPDATE');
        $claimLookup->execute([$cashier_id, $idempotency_key]);
        $claimRow = $claimLookup->fetch(PDO::FETCH_ASSOC);
        if (!$claimRow || !hash_equals((string) $claimRow['request_fingerprint'], $requestFingerprint)) {
            throw new RuntimeException('This payment form was already used for different payment details. Refresh and review the student and amount.');
        }
        if ((int) ($claimRow['payment_id'] ?? 0) > 0) {
            $existingPayment = $pdo->prepare("SELECT payment_id FROM payments WHERE payment_id = ? AND verified_by = ? AND transaction_type = 'Walk-in' AND payment_channel = 'Cash' AND payment_status = 'Verified' LIMIT 1");
            $existingPayment->execute([(int) $claimRow['payment_id'], $cashier_id]);
            $existingPaymentId = (int) $existingPayment->fetchColumn();
            if ($existingPaymentId <= 0) {
                throw new RuntimeException('The prior payment record needs review. No additional payment was posted.');
            }
            $pdo->commit();
            header('Location: print-receipt.php?payment_id=' . $existingPaymentId . '&autoprint=1');
            exit();
        }

        // 1. Validate Billing with Row-Level Locking (FOR UPDATE)
        $stmtBill = $pdo->prepare("SELECT billing_id, remaining_balance, billing_type FROM billing WHERE billing_id = :id FOR UPDATE");
        $stmtBill->execute([':id' => $billing_id]);
        $bill = $stmtBill->fetch();

        if (!$bill) {
            throw new Exception("Billing record not found.");
        }

        // Validate backend Context
        $securityService = new PaymentSecurityService($pdo);
        $securityService->validatePaymentContext(
            $payment_context, 
            $amount_paid, 
            (float)$bill['remaining_balance'], 
            $bill, 
            $payment_context === 'SPECIFIC_ITEM' ? $item_id : null,
            $category_id
        );

        if ($amount_paid <= 0) {
            throw new Exception("Please enter a valid payment amount.");
        }

        if ($cash_received < $amount_paid) {
            throw new Exception("Cash received (₱".number_format($cash_received, 2).") cannot be less than the amount applied to balance (₱".number_format($amount_paid, 2).").");
        }

        $change_amount = round($cash_received - $amount_paid, 2);

        // Reserve an immutable OR inside the same transaction before posting payment.
        $reference_number = (new OfficialReceiptService($pdo))->reserve();

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

        $completeClaim = $pdo->prepare('UPDATE cashier_payment_idempotency SET payment_id = ? WHERE cashier_user_id = ? AND idempotency_key = ? AND payment_id IS NULL');
        $completeClaim->execute([(int) $payment_id, $cashier_id, $idempotency_key]);
        if ($completeClaim->rowCount() !== 1) {
            throw new RuntimeException('Unable to finalize the cashier payment request. The payment was not committed.');
        }

        $pdo->commit();

        // These side effects run after the payment is durable. Their failure must
        // not make the cashier believe the payment failed and submit a new charge.
        try {
            (new PaymentNotificationService($pdo))->notifyVerifiedPayment(
                (int) $student_id,
                (int) $payment_id,
                $amount_paid,
                'Cashier walk-in',
                $reference_number
            );

            // Payment is now durable in Payment DB. Audit is authoritative in SMS2 Core.
            logActivity(
                'process_walk_in_payment',
                "Processed walk-in payment of ₱" . number_format($amount_paid, 2) . " (Context: {$payment_context}) for Billing ID #{$billing_id} with OR No: {$reference_number}",
                'payment',
                (int) $cashier_id
            );
        } catch (Throwable $postCommitError) {
            error_log('Cashier payment post-commit notification/audit failed for payment ' . (int) $payment_id . ': ' . $postCommitError->getMessage());
        }

        header("Location: print-receipt.php?payment_id=" . (int) $payment_id . "&autoprint=1");
        exit();

    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        header("Location: payment-collection-portal.php?error=" . urlencode($e->getMessage()));
        exit();
    }
}

$pageTitle    = 'Payment Collection Portal';
$activeModule = 'payment';
$activePage   = 'cashier/payment-collection-portal';
$breadcrumbs  = [
    ['label' => 'Payment Management', 'url' => BASE_URL . '/modules/payment/index.php'],
    ['label' => 'Payment Collection Portal', 'url' => null],
];

require_once __DIR__ . '/../../../../includes/breadcrumbs.php';
require_once __DIR__ . '/../../../../includes/layout-start.php';
?>

<?php renderBreadcrumbs($breadcrumbs); ?>

<div class="container-fluid payment-page py-4">
    <!-- Page Header -->
    <div class="row mb-4 align-items-center">
        <div class="col-md-8">
            <h2 class="mb-1 fw-bolder"><i class="fas fa-cash-register text-primary me-2"></i>Cashier Collection Portal</h2>
            <p class="text-muted mb-0 fs-6">Collect academic fees or approved school items and issue Official Receipts (OR).</p>
        </div>
    </div>

    <div class="nav nav-pills bg-white border rounded-3 p-2 shadow-sm mb-4" role="navigation" aria-label="Cashier collection type">
        <a class="nav-link <?= $cashierView === 'academic' ? 'active' : '' ?>" <?= $cashierView === 'academic' ? 'aria-current="page"' : '' ?> href="payment-collection-portal.php"><i class="ti ti-school me-1"></i>Academic Payments</a>
        <?php if ($canSellSchoolItems): ?>
            <a class="nav-link <?= $cashierView === 'school-items' ? 'active' : '' ?>" <?= $cashierView === 'school-items' ? 'aria-current="page"' : '' ?> href="payment-collection-portal.php?view=school-items"><i class="ti ti-shopping-bag me-1"></i>School Items</a>
        <?php endif; ?>
    </div>

    <?php if ($cashierView === 'school-items'): ?>
        <?php if (isset($_GET['error'])): ?><div class="alert alert-danger alert-dismissible shadow-sm"><i class="ti ti-alert-circle me-2"></i><?= htmlspecialchars($_GET['error']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
        <?php require __DIR__ . '/school-items-panel.php'; ?>
    <?php else: ?>
    <!-- Alerts -->
    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible shadow-sm">
            <i class="ti ti-circle-check me-2"></i> Payment successfully processed! Receipt <strong>#<?= htmlspecialchars($_GET['or'] ?? '') ?></strong> generated.
            <?php if ((int) ($_GET['payment_id'] ?? 0) > 0): ?>
                <a class="btn btn-sm btn-success ms-3" target="_blank" href="print-receipt.php?payment_id=<?= (int) $_GET['payment_id'] ?>"><i class="ti ti-printer me-1"></i>Print Student Copy</a>
            <?php endif; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger alert-dismissible shadow-sm">
            <i class="ti ti-alert-circle me-2"></i> <?= htmlspecialchars($_GET['error']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- LEFT COLUMN: Search Student & Billing Information -->
        <div class="col-lg-5 mb-4">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-3"><i class="ti ti-search text-primary me-2"></i>1. Search Student Record</h5>
                    
                    <div class="input-group mb-3">
                        <span class="input-group-text bg-light"><i class="ti ti-school"></i></span>
                        <input type="text" class="form-control" id="searchStudentNumber" placeholder="Enter Student Number (e.g. S230106713)" autocomplete="off">
                        <button class="btn btn-primary px-4 fw-bold" type="button" id="btnSearchStudent">Find</button>
                    </div>
                    <div id="studentLookupError" class="alert alert-danger py-2 d-none" role="alert"></div>

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
                            <span class="text-muted">Billing Type:</span>
                            <span class="fw-bold text-dark" id="lblBillingTerm">---</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Academic Year / Semester:</span>
                            <span class="fw-bold text-dark text-end" id="lblAcademicTerm">---</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Total Assessment:</span>
                            <span class="fw-bold text-dark" id="lblTotalAmount">₱ 0.00</span>
                        </div>
                        <div class="d-flex justify-content-between mb-3 border-bottom pb-3">
                            <span class="text-muted">Current Balance Due:</span>
                            <span class="fw-bold text-danger fs-5" id="lblRemainingBalance">₱ 0.00</span>
                        </div>

                        <!-- Unpaid Fees Breakdown Container -->
                        <div id="unpaidFeesContainer" class="d-none mt-2">
                            <h6 class="text-muted fw-bolder small text-uppercase mb-3 mt-3"><i class="ti ti-list-ul me-2"></i>Unpaid Fees by Category</h6>
                            <div id="unpaidFeesList" class="small">
                                <!-- Dynamic breakdown will be injected here by JS -->
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- RIGHT COLUMN: Payment Encoding & OR Issuance -->
        <div class="col-lg-7 mb-4">
            <div class="card border-0 shadow-sm rounded-4 h-100 opacity-50" id="paymentPanel" style="pointer-events: none;">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-3"><i class="ti ti-cash text-success me-2"></i>2. Receive Payment & Issue OR</h5>
                    
                    <form action="" method="POST" id="paymentForm" data-cashier-name="<?= htmlspecialchars(getCurrentUserName(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="process_payment" value="1">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="billing_id" id="inputBillingId">
                        <input type="hidden" name="student_id" id="inputStudentId">
                        <input type="hidden" name="idempotency_key" value="<?= htmlspecialchars(bin2hex(random_bytes(32)), ENT_QUOTES, 'UTF-8') ?>">

                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label class="form-label fw-bold small text-muted">Payment Context <span class="text-danger">*</span></label>
                                <select class="form-select" name="payment_context" id="inputPaymentContext" required>
                                    <option value="GENERAL_PRIORITY" selected>General / Full Payment (Covers All Fees Including Tuition)</option>
                                    <option value="ENROLLMENT_PRIORITY">Enrollment Priority (RFID &rarr; Misc &rarr; Lab)</option>
                                    <option value="CATEGORY_PRIORITY">Designated Category (Specific Fee Only)</option>
                                </select>
                            </div>

                            <div class="col-md-12 mb-3 d-none" id="categorySelectionWrapper">
                                <label class="form-label fw-bold small text-muted">Select Specific Category <span class="text-danger">*</span></label>
                                <select class="form-select" name="category_id" id="inputCategoryId">
                                    <option value="">Search a student first</option>
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
                                <label class="form-label fw-bold small text-muted">Payment Channel</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light text-success fw-bold"><i class="ti ti-cash"></i></span>
                                    <input type="text" class="form-control bg-light fw-bold text-dark" value="Cash (Walk-in)" readonly>
                                </div>
                                <input type="hidden" name="payment_channel" value="Cash">
                                <small class="text-muted" style="font-size: 0.75rem;"><i class="fas fa-lock me-1"></i>Dedicated channel for walk-in counter payments.</small>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold small text-muted">Official Receipt (OR) / Ref No.</label>
                                <input type="text" class="form-control bg-light text-muted" placeholder="System Auto-Generated" readonly>
                                <small class="text-primary fw-bold" style="font-size: 0.75rem;"><i class="fas fa-magic me-1"></i>Automatically generated upon save.</small>
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
                                <i class="ti ti-printer me-1"></i> Complete Payment & Print OR
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="paymentReviewModal" tabindex="-1" aria-labelledby="paymentReviewTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow">
    <div class="modal-header bg-primary text-white"><h5 class="modal-title" id="paymentReviewTitle"><i class="ti ti-receipt me-2"></i>Review Cash Payment</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><p class="text-muted small">Confirm the cash payment before it is posted. The server performs the final validation and allocation.</p>
      <dl class="row mb-0"><dt class="col-5">Student</dt><dd class="col-7" id="reviewStudent">—</dd><dt class="col-5">Billing / Category</dt><dd class="col-7" id="reviewContext">—</dd><dt class="col-5">Current balance</dt><dd class="col-7" id="reviewBalance">—</dd><dt class="col-5">Amount applied</dt><dd class="col-7 fw-bold" id="reviewAmount">—</dd><dt class="col-5">Cash received</dt><dd class="col-7" id="reviewCash">—</dd><dt class="col-5">Change</dt><dd class="col-7" id="reviewChange">—</dd><dt class="col-5">Processed by</dt><dd class="col-7" id="reviewCashier">—</dd></dl>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Back</button><button type="button" class="btn btn-success" id="btnConfirmPayment"><i class="ti ti-check me-1"></i>Confirm & Process Payment</button></div>
  </div></div>
</div>
<!-- Dinagdagan ng ?v=time() para laging fresh ang basahin ng browser na JavaScript file -->
<script src="../../assets/js/payment-collection.js?v=<?= time() ?>"></script>
<script src="<?= BASE_URL ?>/modules/payment/assets/js/payment-search.js"></script>
    <?php endif; ?>
<?php require_once __DIR__ . '/../../../../includes/layout-end.php'; ?>
