<?php
/**
 * SMS 2 - Admin Reporting: Transaction History
 * PURPOSE: Admin-level auditing and oversight of all financial transactions.
 */
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../includes/authentication.php';
require_once __DIR__ . '/../../../../includes/audit.php';
require_once __DIR__ . '/../../database/db_connect.php';
require_once __DIR__ . '/../../includes/PaymentHistoryService.php';

requireAuth();
requirePaymentPermission('payment.transaction_history_view');

try {
    $search = trim($_GET['search'] ?? '');
    $historyService = new PaymentHistoryService($pdo);
    $paymentList = $historyService->getAllPayments($search);
} catch (Exception $e) {
    $paymentList = [];
    $dbError = $e->getMessage();
}

$pageTitle    = 'Transaction History (Admin)';
$activeModule = 'payment';
$activePage   = 'admin/transaction-history';
$breadcrumbs  = [
    ['label' => 'Payment Management', 'url' => BASE_URL . '/modules/payment/index.php'],
    ['label' => 'Transaction History', 'url' => null],
];

require_once __DIR__ . '/../../../../includes/breadcrumbs.php';
require_once __DIR__ . '/../../../../includes/layout-start.php';
?>

<?php renderBreadcrumbs($breadcrumbs); ?>

<div class="container-fluid py-4">
    
    <div class="row mb-4 align-items-center">
        <div class="col-md-6">
            <h2 class="mb-1 fw-bolder"><i class="fas fa-list-alt text-primary me-2"></i>Transaction History</h2>
            <p class="text-muted mb-0 fs-6">System-wide auditing of all payment transactions and gateway records.</p>
        </div>
        <div class="col-md-6 text-md-end mt-3 mt-md-0">
            <div class="d-inline-block w-auto">
                <div class="input-group shadow-sm">
                    <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                    <input type="text" class="form-control border-start-0 ps-0 table-live-search-input" data-table-target="#historyTable" placeholder="Search student or Ref No...">
                </div>
            </div>
        </div>
    </div>

    <?php if (isset($dbError)): ?>
        <div class="alert alert-danger shadow-sm"><i class="fas fa-exclamation-triangle me-2"></i> Database Error: <?= htmlspecialchars($dbError) ?></div>
    <?php endif; ?>

    <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 text-nowrap" id="historyTable">
                    <thead class="bg-light text-uppercase text-secondary" style="font-size: 0.75rem; letter-spacing: 0.5px;">
                        <tr>
                            <th class="ps-4 py-3">Date & Time</th>
                            <th class="py-3">Student</th>
                            <th class="py-3">Source / Channel</th>
                            <th class="py-3 text-end">Amount Applied</th>
                            <th class="py-3 text-end">Processing Fee</th>
                            <th class="py-3 text-end">Checkout Total</th>
                            <th class="py-3 text-center">Status</th>
                            <th class="py-3">Reference No.</th>
                            <th class="text-end pe-4 py-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="border-top-0">
                        <?php if (count($paymentList) > 0): ?>
                            <?php foreach ($paymentList as $pay): ?>
                                <tr>
                                    <td class="ps-4">
                                        <div class="text-dark fw-bold"><?= date('M d, Y', strtotime($pay['payment_date'])) ?></div>
                                        <small class="text-muted"><?= date('h:i A', strtotime($pay['created_at'])) ?></small>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-dark"><?= htmlspecialchars($pay['full_name']) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($pay['student_number']) ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border px-2 py-1"><?= htmlspecialchars($pay['payment_channel'] ?? $pay['payment_method']) ?></span>
                                    </td>
                                    <?php 
                                        $isOnline = in_array(strtolower($pay['payment_channel'] ?? $pay['payment_method']), ['gcash', 'maya', 'card', 'qrph', 'paymongo', 'visa']);
                                        $amtApplied = (float)$pay['amount'];
                                        $procFee = $isOnline ? (float)($pay['processing_fee'] ?? 0) : 0;
                                        $chkTotal = $isOnline ? (float)($pay['checkout_total'] ?? $amtApplied) : $amtApplied;
                                    ?>
                                    <td class="fw-bold text-success text-end">₱ <?= number_format($amtApplied, 2) ?></td>
                                    <td class="text-muted text-end">₱ <?= number_format($procFee, 2) ?></td>
                                    <td class="fw-bold text-primary text-end">₱ <?= number_format($chkTotal, 2) ?></td>
                                    <td class="text-center">
                                        <?php 
                                        $statusClass = match($pay['payment_status']) {
                                            'Verified' => 'bg-success',
                                            'Pending' => 'bg-warning text-dark',
                                            default => 'bg-danger'
                                        };
                                        ?>
                                        <span class="badge rounded-pill <?= $statusClass ?> px-3 py-1">
                                            <?= htmlspecialchars($pay['payment_status']) ?>
                                        </span>
                                    </td>
                                    <td class="fw-bold text-secondary">
                                        <?= htmlspecialchars($pay['reference_number'] ?? 'N/A') ?>
                                    </td>
                                    <td class="text-end pe-4">
                                        <button type="button" class="btn btn-sm btn-outline-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#detailsModal<?= $pay['payment_id'] ?>">
                                            <i class="fas fa-list me-1"></i> Details
                                        </button>
                                        

                                        
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" class="text-center py-5 text-muted">
                                    <i class="fas fa-search fs-1 mb-3 d-block text-light"></i>
                                    <h5 class="fw-bold text-secondary">No transactions found.</h5>
                                    <p class="mb-0">System-wide transactions will appear here.</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ========================================== -->
<!-- MODALS PLACED OUTSIDE OF OVERFLOW CONTAINER -->
<!-- ========================================== -->
<?php if (count($paymentList) > 0): ?>
    <?php foreach ($paymentList as $pay): ?>
        <?php 
            $isOnline = in_array(strtolower($pay['payment_channel'] ?? $pay['payment_method']), ['gcash', 'maya', 'card', 'qrph', 'paymongo', 'visa']);
            $amtApplied = (float)$pay['amount'];
            $procFee = $isOnline ? (float)($pay['processing_fee'] ?? 0) : 0;
            $chkTotal = $isOnline ? (float)($pay['checkout_total'] ?? $amtApplied) : $amtApplied;
            $statusClass = match($pay['payment_status']) {
                'Verified' => 'bg-success',
                'Pending' => 'bg-warning text-dark',
                default => 'bg-danger'
            };
        ?>
        <!-- Premium Modal for Auditing Details -->
        <div class="modal fade" id="detailsModal<?= $pay['payment_id'] ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 shadow-lg overflow-hidden" style="border-radius: 1rem;">
                    <div class="modal-header bg-primary text-white border-bottom-0 p-4">
                        <h5 class="modal-title fw-bolder mb-0">
                            <i class="fas fa-receipt me-2 opacity-75"></i>Transaction Details
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    
                    <!-- Modal Header Summary -->
                    <div class="bg-light p-4 text-center border-bottom">
                        <div class="text-uppercase fw-bold text-muted mb-1" style="font-size: 0.75rem; letter-spacing: 1px;">Total Amount Paid</div>
                        <h2 class="fw-bolder text-primary mb-2">₱ <?= number_format($chkTotal, 2) ?></h2>
                        <span class="badge rounded-pill <?= $statusClass ?> px-3 py-2 fs-6 shadow-sm">
                            <i class="fas <?= $pay['payment_status'] === 'Verified' ? 'fa-check-circle' : 'fa-clock' ?> me-1"></i>
                            <?= htmlspecialchars($pay['payment_status']) ?>
                        </span>
                    </div>

                    <div class="modal-body p-4 bg-white">
                        <!-- Student Info -->
                        <h6 class="fw-bold text-secondary text-uppercase mb-3" style="font-size: 0.75rem; letter-spacing: 1px;"><i class="fas fa-user-circle me-2"></i>Student Information</h6>
                        <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">
                            <span class="text-muted"><i class="fas fa-id-card me-2 opacity-50"></i>Student No.</span>
                            <span class="fw-bold text-dark"><?= htmlspecialchars($pay['student_number']) ?></span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-4 pb-2 border-bottom">
                            <span class="text-muted"><i class="fas fa-user me-2 opacity-50"></i>Full Name</span>
                            <span class="fw-bold text-dark text-end"><?= htmlspecialchars($pay['full_name']) ?></span>
                        </div>

                        <!-- Payment Breakdown -->
                        <h6 class="fw-bold text-secondary text-uppercase mb-3" style="font-size: 0.75rem; letter-spacing: 1px;"><i class="fas fa-file-invoice-dollar me-2"></i>Payment Breakdown</h6>
                        <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">
                            <span class="text-muted">Amount Applied</span>
                            <span class="fw-bold text-dark">₱ <?= number_format($amtApplied, 2) ?></span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">
                            <span class="text-muted">Processing Fee</span>
                            <span class="fw-bold text-danger">+ ₱ <?= number_format($procFee, 2) ?></span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-4 pb-2 border-bottom">
                            <span class="text-muted fw-bold">Checkout Total</span>
                            <span class="fw-bold text-primary fs-5">₱ <?= number_format($chkTotal, 2) ?></span>
                        </div>

                        <!-- Audit Info -->
                        <h6 class="fw-bold text-secondary text-uppercase mb-3" style="font-size: 0.75rem; letter-spacing: 1px;"><i class="fas fa-shield-alt me-2"></i>Audit Information</h6>
                        <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">
                            <span class="text-muted">Reference No.</span>
                            <span class="fw-bold text-dark font-monospace"><?= htmlspecialchars($pay['reference_number'] ?? 'N/A') ?></span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">
                            <span class="text-muted">Channel / Method</span>
                            <span class="fw-bold text-dark"><?= htmlspecialchars($pay['payment_channel'] ?? $pay['payment_method']) ?></span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">
                            <span class="text-muted">Payment ID</span>
                            <span class="fw-bold text-secondary">#<?= htmlspecialchars($pay['payment_id']) ?></span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">
                            <span class="text-muted">Session ID</span>
                            <span class="fw-bold text-secondary font-monospace" style="font-size: 0.75rem; text-truncate max-width-50" title="<?= htmlspecialchars($pay['checkout_session_id'] ?? 'N/A') ?>"><?= htmlspecialchars($pay['checkout_session_id'] ?? 'N/A') ?></span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-muted">Timestamp</span>
                            <span class="fw-bold text-secondary"><?= htmlspecialchars($pay['created_at']) ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../../../../includes/layout-end.php'; ?>
