
<?php
/**
 * SMS 2 - Collection Reporting & Analytics
 * Module: Payment Management
 */
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../includes/authentication.php';
require_once __DIR__ . '/../../../../includes/audit.php';
require_once __DIR__ . '/../../database/db_connect.php';

// I-enforce ang login at module access
requireAuth();
requirePaymentPermission('payment.analytics');
// ==========================================
// 1. KUNIN ANG MGA ANALYTICS & REPORTS DATA
// ==========================================
try {
    // Total Verified Collections
    $totalCollections = $pdo->query("SELECT SUM(amount) FROM payments WHERE payment_status = 'Verified'")->fetchColumn() ?: 0;

    // Total Outstanding Receivables (Balancing)
    $totalReceivables = $pdo->query("SELECT SUM(remaining_balance) FROM billing WHERE billing_status != 'Paid'")->fetchColumn() ?: 0;

    // Total Fully Paid Billings count
    $fullyPaidCount = $pdo->query("SELECT COUNT(*) FROM billing WHERE billing_status = 'Paid'")->fetchColumn() ?: 0;

    // Breakdown by Payment Channel (Cash, GCash, Bank, etc.)
    $stmtChannel = $pdo->query("
        SELECT payment_channel, SUM(amount) as total_amount, COUNT(*) as transaction_count 
        FROM payments 
        WHERE payment_status = 'Verified' 
        GROUP BY payment_channel
    ");
    $channelBreakdown = $stmtChannel->fetchAll(PDO::FETCH_ASSOC);

    // Recent Verified Collections for Report Table
   $stmtRecent = $pdo->query("
        SELECT p.*, s.student_number, u.full_name 
        FROM payments p
        JOIN students s ON p.student_id = s.student_id
        JOIN sms2_db.users u ON s.user_id = u.id
        WHERE p.payment_status = 'Verified'
        ORDER BY p.created_at DESC 
        LIMIT 10
    ");
    $recentCollections = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $totalCollections = 0;
    $totalReceivables = 0;
    $fullyPaidCount = 0;
    $channelBreakdown = [];
    $recentCollections = [];
    $dbError = $e->getMessage();
}

$pageTitle    = 'Collection Reporting & Analytics';
$activeModule = 'payment';
$activePage   = 'accounting/collection-reporting-analytics';
$breadcrumbs  = [
    ['label' => 'Payment Management', 'url' => BASE_URL . '/modules/payment/index.php'],
    ['label' => 'Collection Reporting & Analytics', 'url' => null],
];

require_once __DIR__ . '/../../../../includes/breadcrumbs.php';
require_once __DIR__ . '/../../../../includes/layout-start.php';
?>

<?php renderBreadcrumbs($breadcrumbs); ?>

<div class="container-fluid py-4">
    
    <!-- Page Header & Actions -->
    <div class="row mb-4 align-items-center">
        <div class="col-md-6">
            <h2 class="mb-1 fw-bolder"><i class="fas fa-chart-pie text-primary me-2"></i>Collection Analytics</h2>
            <p class="text-muted mb-0 fs-6">Real-time financial collection summaries, channel metrics, and reporting insights.</p>
        </div>
        <div class="col-md-6 text-md-end mt-3 mt-md-0">
            <button onclick="window.print()" class="btn btn-primary shadow-sm fw-bold px-4">
                <i class="fas fa-print me-1"></i> Print / Export Report
            </button>
        </div>
    </div>

    <?php if (isset($dbError)): ?>
        <div class="alert alert-danger shadow-sm"><i class="fas fa-exclamation-triangle me-2"></i> Database Error: <?= htmlspecialchars($dbError) ?></div>
    <?php endif; ?>

    <!-- High-Level Overview Metrics Cards -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 border-start border-success border-4">
                <div class="card-body">
                    <p class="text-muted fw-bold mb-1 text-uppercase" style="font-size: 0.8rem;">Total Collections (Verified)</p>
                    <h3 class="fw-bolder mb-0 text-success">₱ <?= number_format($totalCollections, 2) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 border-start border-danger border-4">
                <div class="card-body">
                    <p class="text-muted fw-bold mb-1 text-uppercase" style="font-size: 0.8rem;">Total Outstanding Receivables</p>
                    <h3 class="fw-bolder mb-0 text-danger">₱ <?= number_format($totalReceivables, 2) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 border-start border-primary border-4">
                <div class="card-body">
                    <p class="text-muted fw-bold mb-1 text-uppercase" style="font-size: 0.8rem;">Fully Settled Accounts</p>
                    <h3 class="fw-bolder mb-0 text-dark"><?= number_format($fullyPaidCount) ?> Students</h3>
                </div>
            </div>
        </div>
    </div>

    <!-- Breakdown By Channels & Summary -->
    <div class="row mb-4">
        <div class="col-lg-5 mb-4 mb-lg-0">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-white border-0 pt-4 px-4">
                    <h5 class="fw-bold mb-0 text-dark"><i class="fas fa-wallet text-primary me-2"></i>Collections by Channel</h5>
                </div>
                <div class="card-body px-4">
                    <?php if (count($channelBreakdown) > 0): ?>
                        <div class="table-responsive">
                            <table class="table align-middle mb-0">
                                <thead class="table-light text-uppercase" style="font-size: 0.75rem;">
                                    <tr>
                                        <th>Channel</th>
                                        <th class="text-center">Count</th>
                                        <th class="text-end">Total Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($channelBreakdown as $ch): ?>
                                        <tr>
                                            <td class="fw-bold text-dark"><?= htmlspecialchars($ch['payment_channel']) ?></td>
                                            <td class="text-center"><span class="badge bg-light text-secondary border px-2"><?= $ch['transaction_count'] ?></span></td>
                                            <td class="text-end fw-bold text-success">₱ <?= number_format($ch['total_amount'], 2) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4 text-muted small">No channel breakdown data available yet.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-white border-0 pt-4 px-4">
                    <h5 class="fw-bold mb-0 text-dark"><i class="fas fa-file-alt text-primary me-2"></i>Recent Verified Collections</h5>
                </div>
                <div class="card-body px-0 pb-0">
                    <div class="table-responsive" style="max-height: 280px; overflow-y: auto;">
                        <table class="table table-hover align-middle mb-0 text-nowrap">
                            <thead class="bg-light text-uppercase text-secondary" style="font-size: 0.70rem;">
                                <tr>
                                    <th class="ps-4">OR / Ref</th>
                                    <th>Student</th>
                                    <th>Channel</th>
                                    <th class="text-end pe-4">Applied</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($recentCollections) > 0): ?>
                                    <?php foreach ($recentCollections as $rc): ?>
                                        <tr>
                                            <td class="ps-4 fw-bold text-primary">#<?= htmlspecialchars($rc['reference_number'] ?? 'N/A') ?></td>
                                            <td>
                                                <div class="fw-bold text-dark"><?= htmlspecialchars($rc['full_name']) ?></div>
                                                <small class="text-muted"><?= htmlspecialchars($rc['student_number']) ?></small>
                                            </td>
                                            <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($rc['payment_channel']) ?></span></td>
                                            <td class="text-end pe-4 fw-bold text-success">₱ <?= number_format($rc['amount'], 2) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" class="text-center py-4 text-muted small">No recent collections found.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<?php require_once __DIR__ . '/../../../../includes/layout-end.php'; ?>