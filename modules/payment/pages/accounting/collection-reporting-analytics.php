<?php
/**
 * SMS 2 - Collection Reporting & Analytics
 * Module: Payment Management
 */
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../includes/authentication.php';
require_once __DIR__ . '/../../../../includes/audit.php';
require_once __DIR__ . '/../../database/db_connect.php';
require_once __DIR__ . '/../../includes/PaymentReportingScope.php';

// I-enforce ang login at module access
requireAuth();
requirePaymentPermission('payment.analytics');
// ==========================================
// 1. KUNIN ANG MGA ANALYTICS & REPORTS DATA
// ==========================================
try {
    $officialPayment = PaymentReportingScope::officialCondition();
    $officialPaymentP = PaymentReportingScope::officialCondition('p');
    // Official collections: LIVE online plus verified non-online payments.
    $totalCollections = $pdo->query("SELECT SUM(amount) FROM payments WHERE {$officialPayment}")->fetchColumn() ?: 0;

    // Total Outstanding Receivables (Balancing)
    $totalReceivables = $pdo->query("SELECT SUM(remaining_balance) FROM billing WHERE billing_status != 'Paid'")->fetchColumn() ?: 0;

    // Total Fully Paid Billings count
    $fullyPaidCount = $pdo->query("SELECT COUNT(*) FROM billing WHERE billing_status = 'Paid'")->fetchColumn() ?: 0;

    // Breakdown by Payment Channel (Cash, GCash, Bank, etc.)
    $stmtChannel = $pdo->query("
        SELECT payment_channel, SUM(amount) as total_amount, COUNT(*) as transaction_count 
        FROM payments 
        WHERE {$officialPayment}
        GROUP BY payment_channel
    ");
    $channelBreakdown = $stmtChannel->fetchAll(PDO::FETCH_ASSOC);

    // Recent Verified Collections for Report Table
   $stmtRecent = $pdo->query("
        SELECT p.*, s.student_number, s.full_name
        FROM payments p
        JOIN students s ON p.student_id = s.student_id
        WHERE {$officialPaymentP}
        ORDER BY p.created_at DESC 
        LIMIT 10
    ");
    $recentCollections = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);
    // Academic collection efficiency intentionally excludes direct school-sales revenue.
    $totalAcademicReceivables = $pdo->query("SELECT COALESCE(SUM(total_amount - COALESCE(discount_amount, 0)), 0) FROM billing WHERE billing_status <> 'Cancelled'")->fetchColumn() ?: 0;
    $collectionEfficiency = $totalAcademicReceivables > 0 ? ((float) $totalCollections / (float) $totalAcademicReceivables) * 100 : 0;

    $trendCurrentStmt = $pdo->query("SELECT DATE(p.payment_date) AS payment_day, COALESCE(SUM(p.amount), 0) AS total FROM payments p WHERE {$officialPaymentP} AND p.payment_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND CURDATE() GROUP BY DATE(p.payment_date)");
    $trendPriorStmt = $pdo->query("SELECT DATE(p.payment_date) AS payment_day, COALESCE(SUM(p.amount), 0) AS total FROM payments p WHERE {$officialPaymentP} AND p.payment_date BETWEEN DATE_SUB(DATE_SUB(CURDATE(), INTERVAL 6 DAY), INTERVAL 1 YEAR) AND DATE_SUB(CURDATE(), INTERVAL 1 YEAR) GROUP BY DATE(p.payment_date)");
    $currentTrendMap = []; foreach ($trendCurrentStmt->fetchAll(PDO::FETCH_ASSOC) as $row) { $currentTrendMap[$row['payment_day']] = (float) $row['total']; }
    $priorTrendMap = []; foreach ($trendPriorStmt->fetchAll(PDO::FETCH_ASSOC) as $row) { $priorTrendMap[$row['payment_day']] = (float) $row['total']; }
    $trendLabels = []; $trendCurrent = []; $trendPrior = [];
    for ($day = 6; $day >= 0; $day--) { $date = date('Y-m-d', strtotime("-$day days")); $priorDate = date('Y-m-d', strtotime("$date -1 year")); $trendLabels[] = date('M j', strtotime($date)); $trendCurrent[] = $currentTrendMap[$date] ?? 0; $trendPrior[] = $priorTrendMap[$priorDate] ?? 0; }

    $categoryStmt = $pdo->query("SELECT fc.category_name, COALESCE(SUM(pa.allocated_amount), 0) AS total_amount FROM payment_allocations pa JOIN payments p ON p.payment_id = pa.payment_id JOIN billing_items bi ON bi.billing_item_id = pa.billing_item_id JOIN fees f ON f.fee_id = bi.fee_id JOIN fee_categories fc ON fc.category_id = f.category_id WHERE {$officialPaymentP} GROUP BY fc.category_id, fc.category_name ORDER BY total_amount DESC");
    $categoryBreakdown = $categoryStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $totalCollections = 0;
    $totalReceivables = 0;
    $fullyPaidCount = 0;
    $channelBreakdown = [];
    $recentCollections = [];
    $totalAcademicReceivables = 0; $collectionEfficiency = 0; $trendLabels = []; $trendCurrent = []; $trendPrior = []; $categoryBreakdown = [];
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
            <h2 class="mb-1 fw-bolder"><i class="ti ti-chart-pie text-primary me-2"></i>Collection Analytics</h2>
            <p class="text-muted mb-0 fs-6">Real-time financial collection summaries, channel metrics, and reporting insights.</p>
        </div>
        <div class="col-md-6 text-md-end mt-3 mt-md-0">
            <button onclick="window.print()" class="btn btn-primary shadow-sm fw-bold px-4">
                <i class="ti ti-printer me-1"></i> Print / Export Report
            </button>
        </div>
    </div>

    <?php if (isset($dbError)): ?>
        <div class="alert alert-danger shadow-sm"><i class="ti ti-alert-triangle me-2"></i> Database Error: <?= htmlspecialchars($dbError) ?></div>
    <?php endif; ?>

    <!-- High-Level Overview Metrics Cards -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 border-start border-success border-4">
                <div class="card-body">
                    <p class="text-muted fw-bold mb-1 text-uppercase" style="font-size: 0.8rem;">Official Collections (Amount Applied)</p>
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

        <div class="col-md-3">
            <div class="card border-0 shadow-sm rounded-4 border-start border-info border-4"><div class="card-body"><p class="text-muted fw-bold mb-1 text-uppercase" style="font-size:.8rem;">Collection Efficiency</p><h3 class="fw-bolder mb-0 text-info"><?= number_format($collectionEfficiency, 1) ?>%</h3><small class="text-muted">Academic collections ÷ academic receivables</small></div></div>
        </div>
    </div>

    <div class="row g-4 mb-4"><div class="col-lg-7"><div class="card border-0 shadow-sm rounded-4 h-100"><div class="card-header bg-white border-0 pt-4 px-4"><h5 class="fw-bold mb-0">Current vs. Matching Prior Period</h5></div><div class="card-body"><canvas id="collectionTrendChart" height="120"></canvas></div></div></div><div class="col-lg-5"><div class="card border-0 shadow-sm rounded-4 h-100"><div class="card-header bg-white border-0 pt-4 px-4"><h5 class="fw-bold mb-0">Collections by Fee Category</h5></div><div class="card-body"><canvas id="feeCategoryChart" height="120"></canvas></div></div></div></div>
    <!-- Breakdown By Channels & Summary -->
    <div class="row mb-4">
        <div class="col-lg-5 mb-4 mb-lg-0">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-white border-0 pt-4 px-4">
                    <h5 class="fw-bold mb-0 text-dark"><i class="ti ti-wallet text-primary me-2"></i>Collections by Channel</h5>
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
                    <h5 class="fw-bold mb-0 text-dark"><i class="fas fa-file-alt text-primary me-2"></i>Recent Official Collections</h5>
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

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(() => {
 const money = value => '₱' + Number(value || 0).toLocaleString('en-PH', {minimumFractionDigits:2});
 const labels = <?= json_encode($trendLabels) ?>, current = <?= json_encode($trendCurrent) ?>, prior = <?= json_encode($trendPrior) ?>;
 new Chart(document.getElementById('collectionTrendChart'), {type:'line',data:{labels,datasets:[{label:'Current period',data:current,borderColor:'#2563eb',backgroundColor:'#2563eb22',fill:true,tension:.3},{label:'Matching prior period',data:prior,borderColor:'#94a3b8',backgroundColor:'#94a3b822',fill:true,tension:.3}]},options:{responsive:true,scales:{y:{beginAtZero:true,ticks:{callback:money}}}}});
 const categories = <?= json_encode($categoryBreakdown) ?>;
 new Chart(document.getElementById('feeCategoryChart'), {type:'bar',data:{labels:categories.map(row=>row.category_name),datasets:[{label:'Academic collections',data:categories.map(row=>row.total_amount),backgroundColor:'#059669'}]},options:{indexAxis:'y',responsive:true,plugins:{legend:{display:false}},scales:{x:{beginAtZero:true,ticks:{callback:money}}}}});
})();
</script><?php require_once __DIR__ . '/../../../../includes/layout-end.php'; ?>
