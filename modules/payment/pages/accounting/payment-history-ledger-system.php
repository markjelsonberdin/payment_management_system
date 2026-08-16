<?php
/**
 * SMS 2 - Collection Reporting & Analytics
 * Module: Payment Management
 */
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../includes/authentication.php';
require_once __DIR__ . '/../../../../includes/audit.php';
require_once __DIR__ . '/../../database/db_connect.php';
require_once __DIR__ . '/../../includes/PaymentHistoryService.php';

// I-enforce ang login at module access
requireAuth();
requireModuleAccess('payment');

try {
    $search = trim($_GET['search'] ?? '');
    
    $historyService = new PaymentHistoryService($pdo);
    
    // Kunin ang summary for dashboard cards
    $summary = $historyService->getPaymentSummary();
    $totalCollections = $summary['total_collections'] ?? 0;
    $totalTransactions = $summary['total_transactions'] ?? 0;

    // Kunin ang listahan ng payments
    $paymentList = $historyService->getAllPayments($search);

} catch (Exception $e) {
    $paymentList = [];
    $totalCollections = 0;
    $totalTransactions = 0;
    $dbError = $e->getMessage();
}

$pageTitle    = 'Payment History & Ledger System';
$activeModule = 'payment';
$activePage   = 'accounting/payment-history-ledger-system';
$breadcrumbs  = [
    ['label' => 'Payment Management', 'url' => BASE_URL . '/modules/payment/index.php'],
    ['label' => 'Payment History & Ledger System', 'url' => null],
];

require_once __DIR__ . '/../../../../includes/breadcrumbs.php';
require_once __DIR__ . '/../../../../includes/layout-start.php';
?>

<?php renderBreadcrumbs($breadcrumbs); ?>

<div class="container-fluid py-4">
    
    <!-- Page Header & Search Form -->
    <div class="row mb-4 align-items-center">
        <div class="col-md-6">
            <h2 class="mb-1 fw-bolder"><i class="fas fa-history text-primary me-2"></i>Payment History & Ledger</h2>
            <p class="text-muted mb-0 fs-6">Track all historical payment transactions, official receipts, and student ledger balances.</p>
        </div>
        <div class="col-md-6 text-md-end mt-3 mt-md-0">
            <div class="d-inline-block w-auto">
                <div class="input-group shadow-sm">
                    <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                    <input type="text" class="form-control border-start-0 ps-0 table-live-search-input" data-table-target="#historyTable" placeholder="Search student or OR no...">
                </div>
            </div>
        </div>
    </div>

    <?php if (isset($dbError)): ?>
        <div class="alert alert-danger shadow-sm"><i class="fas fa-exclamation-triangle me-2"></i> Database Error: <?= htmlspecialchars($dbError) ?></div>
    <?php endif; ?>

    <!-- Overview Cards -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm rounded-4 border-start border-success border-4">
                <div class="card-body">
                    <p class="text-muted fw-bold mb-1 text-uppercase" style="font-size: 0.8rem;">Total Verified Collections</p>
                    <h3 class="fw-bolder mb-0 text-success">₱ <?= number_format($totalCollections, 2) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm rounded-4 border-start border-primary border-4">
                <div class="card-body">
                    <p class="text-muted fw-bold mb-1 text-uppercase" style="font-size: 0.8rem;">Total Transactions Recorded</p>
                    <h3 class="fw-bolder mb-0 text-dark"><?= number_format($totalTransactions) ?></h3>
                </div>
            </div>
        </div>
    </div>

    <!-- Ledger & Payment History Table -->
    <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 text-nowrap" id="historyTable">
                    <thead class="bg-light text-uppercase text-secondary" style="font-size: 0.75rem; letter-spacing: 0.5px;">
                        <tr>
                            <th class="ps-4 py-3">OR / Ref No.</th>
                            <th class="py-3">Student Details</th>
                            <th class="py-3">Payment Channel</th>
                            <th class="py-3">Amount Paid</th>
                            <th class="py-3">Remaining Balance</th>
                            <th class="py-3 text-center">Status</th>
                            <th class="py-3">Date & Time</th>
                            <th class="text-end pe-4 py-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="border-top-0">
                        <?php if (count($paymentList) > 0): ?>
                            <?php foreach ($paymentList as $pay): ?>
                                <tr>
                                    <td class="ps-4 fw-bold text-primary">#<?= htmlspecialchars($pay['reference_number'] ?? 'N/A') ?></td>
                                    <td>
                                        <div class="fw-bold text-dark"><?= htmlspecialchars($pay['full_name']) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($pay['student_number']) ?> (<?= htmlspecialchars($pay['course']) ?>)</small>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border px-2 py-1"><?= htmlspecialchars($pay['payment_channel']) ?></span>
                                    </td>
                                    <td class="fw-bold text-success">₱ <?= number_format($pay['amount'], 2) ?></td>
                                    <td class="fw-bold text-danger">₱ <?= number_format($pay['remaining_balance'], 2) ?></td>
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
                                    <td>
                                        <div class="text-dark"><?= date('M d, Y', strtotime($pay['payment_date'])) ?></div>
                                        <small class="text-muted"><?= date('h:i A', strtotime($pay['created_at'])) ?></small>
                                    </td>
                                    <td class="text-end pe-4">
                                        <button type="button" class="btn btn-sm btn-light text-primary shadow-sm" onclick="alert('Viewing Ledger & OR Details for Reference: <?= htmlspecialchars(addslashes($pay['reference_number'] ?? 'N/A')) ?>')">
                                            <i class="fas fa-eye me-1"></i> View Ledger
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="text-center py-5 text-muted">
                                    <i class="fas fa-receipt fs-1 mb-3 d-block text-light"></i>
                                    <h5 class="fw-bold text-secondary">No payment history found.</h5>
                                    <p class="mb-0">Processed walk-in or online transactions will appear here automatically.</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="<?= BASE_URL ?>/assets/js/payment-search.js"></script>

<?php require_once __DIR__ . '/../../../../includes/layout-end.php'; ?>