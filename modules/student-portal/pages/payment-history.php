<?php
/**
 * SMS 2 - Student Portal: Payment History
 * Standalone page with direct layout includes and DB integration.
 */

// 1. Core Configurations & Authentication
// Main System Config (para sa ROOT_PATH at BASE_URL)
require_once __DIR__ . '/../../../config/config.php';

// Student Portal Module Config (para sa studentPortalDb() function)
require_once __DIR__ . '/../config/config.php';

require_once ROOT_PATH . '/includes/authentication.php';
require_once ROOT_PATH . '/includes/breadcrumbs.php';

// 2. Page Meta Setup for Header and Sidebar active states
$pageTitle = 'Payment History';
$activeModule = 'student_portal';
$activePage = 'payment-history';
$breadcrumbs = [
    ['label' => 'Student Portal', 'url' => BASE_URL . '/modules/student-portal/pages/my-profile.php'],
    ['label' => 'Payment History', 'url' => null],
];

// 3. Database Fetch Logic
$studentId = $_SESSION['student_id'] ?? 'S230106713'; // Default fallback

$paymentTransactions = [];

try {
    $pdo = studentPortalDb(); 
    
    if ($pdo) {
        // Kunin ang lahat ng payment records ng naka-login na student mula sa payment_db
        // Naka-join tayo sa billing para makuha ang description (hal. Enrollment for 1st Sem)
        $stmtPayments = $pdo->prepare("
            SELECT p.*, b.academic_year, b.semester, b.billing_type, fc.category_name
            FROM payment_db.payments p
            JOIN payment_db.students s ON p.student_id = s.student_id
            LEFT JOIN payment_db.billing b ON p.billing_id = b.billing_id
            LEFT JOIN payment_db.fee_categories fc ON p.category_id = fc.category_id
            WHERE s.student_number = :student_number
            ORDER BY p.created_at DESC
        ");
        $stmtPayments->execute([':student_number' => $studentId]);
        $paymentTransactions = $stmtPayments->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    // Silently handle errors
}

// 4. Load the UI Header (Sidebar, Topbar, CSS)
require_once ROOT_PATH . '/includes/layout-start.php';
?>

<!-- Render Breadcrumbs -->
<?php renderBreadcrumbs($breadcrumbs); ?>

<!-- Main Student Portal Content Wrapper -->
<div class="student-portal">
    
    <!-- Page Header -->
    <div class="page-header student-portal-header d-flex justify-content-between align-items-center mb-4">
        <div>
            <span class="student-kicker text-uppercase text-primary fw-bold small">Student Portal</span>
            <h2 class="fw-bolder m-0"><i class="fas fa-receipt text-sms-primary me-2"></i>Payment History</h2>
            <p class="text-muted m-0 mt-1">Review your official receipt records and past payment transactions.</p>
        </div>
    </div>

    <!-- Payment Transactions Table Card -->
    <div class="card border-0 shadow-sm rounded-3 mb-4">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h6 class="fw-bold m-0 text-dark">Official Payment Transactions</h6>
                <a href="student-concern-portal.php" class="btn btn-sm btn-outline-danger fw-semibold rounded-3 shadow-sm">
                    <i class="fas fa-exclamation-circle me-1"></i> Report an Issue
                </a>
            </div>

            <!-- Tabs -->
            <ul class="nav nav-pills nav-fill bg-light rounded-3 p-1 border shadow-sm mb-4" id="paymentTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active fw-bold text-dark rounded-3" id="tab-all" data-bs-toggle="pill" type="button" role="tab" onclick="filterTransactions('All')">All Transactions</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link fw-bold text-success rounded-3" id="tab-verified" data-bs-toggle="pill" type="button" role="tab" onclick="filterTransactions('Verified')">Successful</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link fw-bold text-warning rounded-3" id="tab-pending" data-bs-toggle="pill" type="button" role="tab" onclick="filterTransactions('Pending')">Pending</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link fw-bold text-danger rounded-3" id="tab-failed" data-bs-toggle="pill" type="button" role="tab" onclick="filterTransactions('Failed')">Failed</button>
                </li>
            </ul>
            
            <div class="table-responsive mb-2">
                <table class="table table-hover table-borderless align-middle mb-0">
                    <thead class="border-bottom text-muted" style="font-size: 0.75rem;">
                        <tr>
                            <th class="py-3 ps-2">DATE</th>
                            <th class="py-3">REFERENCE NO.</th>
                            <th class="py-3">DESCRIPTION / CHANNEL</th>
                            <th class="py-3 text-end">AMOUNT APPLIED</th>
                            <th class="py-3 text-end">PROCESSING FEE</th>
                            <th class="py-3 text-end">TOTAL</th>
                            <th class="py-3 ps-4" style="width: 120px;">STATUS</th>
                            <th class="py-3 text-end pe-2">ACTION</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($paymentTransactions) > 0): ?>
                            <?php foreach ($paymentTransactions as $txn): ?>
                                <?php
                                    // Format Date
                                    $dateFormatted = date('M d, Y', strtotime($txn['payment_date']));
                                    
                                    // Set Badge Colors Based on Status
                                    $statusBg = match($txn['payment_status']) {
                                        'Verified' => '#bbf7d0',
                                        'Pending' => '#fef08a',
                                        default => '#fecaca' // Rejected or Failed
                                    };
                                    $statusText = match($txn['payment_status']) {
                                        'Verified' => '#15803d',
                                        'Pending' => '#b45309',
                                        default => '#b91c1c'
                                    };
                                ?>
                                <tr class="border-bottom transaction-row" data-status="<?= htmlspecialchars($txn['payment_status']) ?>">
                                    <td class="py-3 ps-2 text-dark">
                                        <div class="fw-bold"><?= $dateFormatted ?></div>
                                        <small class="text-muted" style="font-size: 0.75rem;"><?= date('h:i A', strtotime($txn['created_at'])) ?></small>
                                    </td>
                                    <td class="py-3 text-dark fw-semibold">
                                        <?= htmlspecialchars($txn['reference_number'] ?? $txn['receipt_number'] ?? 'N/A') ?>
                                    </td>
                                    <td class="py-3">
                                        <div class="fw-bold text-dark"><?= htmlspecialchars($txn['category_name'] ?? $txn['billing_type'] ?? 'Payment') ?></div>
                                        <small class="text-muted" style="font-size: 0.8rem;">
                                            Via <?= htmlspecialchars($txn['payment_channel']) ?> 
                                            <?= !empty($txn['academic_year']) ? '('.htmlspecialchars($txn['semester']).' Sem, '.$txn['academic_year'].')' : '' ?>
                                        </small>
                                    </td>
                                    <?php 
                                        $isOnline = in_array(strtolower($txn['payment_channel']), ['gcash', 'maya', 'card', 'qrph', 'paymongo', 'visa']);
                                        $amtApplied = (float)$txn['amount'];
                                        $procFee = $isOnline ? (float)($txn['processing_fee'] ?? 0) : 0;
                                        $chkTotal = $isOnline ? (float)($txn['checkout_total'] ?? $amtApplied) : $amtApplied;
                                    ?>
                                    <td class="py-3 text-end fw-bold text-dark">₱ <?= number_format($amtApplied, 2) ?></td>
                                    <td class="py-3 text-end text-muted">₱ <?= number_format($procFee, 2) ?></td>
                                    <td class="py-3 text-end fw-bolder text-primary">₱ <?= number_format($chkTotal, 2) ?></td>
                                    <td class="py-3 ps-4">
                                        <span class="badge rounded-pill fw-bold" style="background-color: <?= $statusBg ?>; color: <?= $statusText ?>; padding: 0.5em 0.8em;">
                                            <?= htmlspecialchars($txn['payment_status']) ?>
                                        </span>
                                    </td>
                                    <td class="py-3 text-end pe-2">
                                        <?php if ($txn['payment_status'] === 'Verified'): ?>
                                            <button class="btn btn-sm btn-light border text-primary shadow-sm" title="Download Receipt">
                                                <i class="fas fa-download"></i>
                                            </button>
                                        <?php elseif ($txn['payment_status'] === 'Pending'): ?>
                                            <a href="<?= BASE_URL ?>/modules/student-portal/api/resume-payment.php?id=<?= $txn['payment_id'] ?>" class="btn btn-sm btn-warning text-dark fw-bold shadow-sm">
                                                <i class="fas fa-credit-card me-1"></i> Resume
                                            </a>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-light border text-muted shadow-sm" disabled title="Receipt not yet available">
                                                <i class="fas fa-download"></i>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr id="empty-state-row">
                                <td colspan="8" class="py-5 text-center text-muted">
                                    <i class="fas fa-folder-open fs-2 mb-3 text-light-gray d-block"></i>
                                    No payment history found yet.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Help Section -->
            <div class="mt-4 p-3 bg-light rounded-3 border border-light">
                <div class="d-flex align-items-center gap-3">
                    <i class="fas fa-info-circle text-primary fs-4"></i>
                    <div>
                        <h6 class="mb-0 fw-bold text-dark" style="font-size: 0.9rem;">Missing a transaction?</h6>
                        <p class="mb-0 text-muted" style="font-size: 0.8rem;">If you made a payment but it's not showing up here, or if a payment is stuck on "Pending", please report it using the "Report an Issue" button above or proceed to the Payment Concern Portal.</p>
                    </div>
                </div>
            </div>

        </div>
    </div>

</div> <!-- End of student-portal -->

<script>
function filterTransactions(status) {
    const rows = document.querySelectorAll('.transaction-row');
    let visibleCount = 0;
    
    rows.forEach(row => {
        const rowStatus = row.getAttribute('data-status');
        if (status === 'All' || rowStatus === status) {
            row.style.display = '';
            visibleCount++;
        } else if (status === 'Failed' && (rowStatus === 'Rejected' || rowStatus === 'Failed')) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });

    const emptyState = document.getElementById('empty-state-row');
    if (emptyState) {
        if (visibleCount === 0) {
            emptyState.style.display = '';
        } else {
            emptyState.style.display = 'none';
        }
    }
}
</script>

<!-- 5. Load the UI Footer (Scripts, closing tags) -->
<?php require_once ROOT_PATH . '/includes/layout-end.php'; ?>