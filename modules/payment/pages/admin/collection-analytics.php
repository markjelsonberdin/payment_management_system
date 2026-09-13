<?php
/**
 * SMS 2 - Admin Reporting: Collection Analytics
 * PURPOSE: Admin-level overview of collections and payment gateway health.
 */
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../includes/authentication.php';
require_once __DIR__ . '/../../../../includes/audit.php';
require_once __DIR__ . '/../../database/db_connect.php';
require_once __DIR__ . '/../../includes/PaymentReportingScope.php';

requireAuth();
requirePaymentPermission('payment.collection_analytics_view');

$allowedRanges = ['today', 'week', 'month', 'semester', 'all', 'custom'];
$selectedRange = in_array($_GET['range'] ?? '', $allowedRanges, true) ? $_GET['range'] : 'month';
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$validDate = static function (string $value): bool {
    $date = DateTime::createFromFormat('Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
};

$periodConditions = [
    'today' => 'DATE(p.payment_date) = CURDATE()',
    'week' => 'p.payment_date >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY) AND p.payment_date <= CURDATE()',
    'month' => 'YEAR(p.payment_date) = YEAR(CURDATE()) AND MONTH(p.payment_date) = MONTH(CURDATE())',
    'semester' => 'p.payment_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)',
    'all' => '1=1',
];
$periodParams = [];
if ($selectedRange === 'custom' && $validDate($dateFrom) && $validDate($dateTo) && $dateFrom <= $dateTo) {
    $periodCondition = 'DATE(p.payment_date) BETWEEN :date_from AND :date_to';
    $periodParams = [':date_from' => $dateFrom, ':date_to' => $dateTo];
} else {
    if ($selectedRange === 'custom') {
        $selectedRange = 'month';
    }
    $periodCondition = $periodConditions[$selectedRange];
}

try {
    $officialPayment = PaymentReportingScope::officialCondition('p');
    // 1. FINANCIAL OVERVIEW
    $totalReceivables = $pdo->query("SELECT SUM(remaining_balance) FROM billing WHERE billing_status != 'Paid'")->fetchColumn() ?: 0;
    $stmtOverview = $pdo->prepare("
        SELECT
            COALESCE(SUM(p.amount), 0) AS total_collections,
            COALESCE(SUM(CASE WHEN p.transaction_type = 'Online' THEN p.amount ELSE 0 END), 0) AS online_collections,
            COALESCE(SUM(CASE WHEN p.transaction_type <> 'Online' THEN p.amount ELSE 0 END), 0) AS walkin_collections,
            COALESCE(SUM(p.processing_fee), 0) AS processing_fees,
            COALESCE(SUM(COALESCE(p.checkout_total, p.amount)), 0) AS checkout_total,
            COUNT(*) AS transaction_count
        FROM payments p
        WHERE {$officialPayment} AND {$periodCondition}
    ");
    $stmtOverview->execute($periodParams);
    $overview = $stmtOverview->fetch(PDO::FETCH_ASSOC) ?: [];
    $totalCollections = (float) ($overview['total_collections'] ?? 0);
    $onlineCollections = (float) ($overview['online_collections'] ?? 0);
    $walkinCollections = (float) ($overview['walkin_collections'] ?? 0);
    $processingFees = (float) ($overview['processing_fees'] ?? 0);
    $checkoutTotal = (float) ($overview['checkout_total'] ?? 0);
    $officialTransactionCount = (int) ($overview['transaction_count'] ?? 0);

    $stmtChannels = $pdo->prepare("
        SELECT COALESCE(NULLIF(p.payment_channel, ''), 'Unspecified') AS payment_channel,
               SUM(p.amount) AS total_amount,
               COUNT(*) AS transaction_count
        FROM payments p
        WHERE {$officialPayment} AND {$periodCondition}
        GROUP BY COALESCE(NULLIF(p.payment_channel, ''), 'Unspecified')
        ORDER BY total_amount DESC
    ");
    $stmtChannels->execute($periodParams);
    $channelBreakdown = $stmtChannels->fetchAll(PDO::FETCH_ASSOC);

    $stmtRecent = $pdo->prepare("
        SELECT p.payment_id, p.payment_date, p.created_at, p.reference_number,
               p.payment_channel, p.amount, p.processing_fee, p.checkout_total,
               s.student_number, s.full_name
        FROM payments p
        JOIN students s ON s.student_id = p.student_id
        WHERE {$officialPayment} AND {$periodCondition}
        ORDER BY COALESCE(p.verified_at, p.created_at) DESC, p.payment_id DESC
        LIMIT 8
    ");
    $stmtRecent->execute($periodParams);
    $recentCollections = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);

    $maxChannelTotal = 0.0;
    foreach ($channelBreakdown as $channelRow) {
        $maxChannelTotal = max($maxChannelTotal, (float) $channelRow['total_amount']);
    }

    // 2. GATEWAY HEALTH
    $pendingOnline = $pdo->query("SELECT COUNT(*) FROM payments WHERE payment_status = 'Pending' AND transaction_type = 'Online'")->fetchColumn() ?: 0;
    $failedOnline = $pdo->query("SELECT COUNT(*) FROM payments WHERE payment_status IN ('Failed', 'Rejected', 'Expired', 'Cancelled') AND transaction_type = 'Online'")->fetchColumn() ?: 0;
    
    // Get gateway environment
    $stmtEnv = $pdo->query("SELECT setting_value FROM payment_gateway_settings WHERE setting_key = 'gateway_mode'");
    $env = $stmtEnv->fetchColumn() ?: 'test';

} catch (PDOException $e) {
    $dbError = 'Collection analytics are temporarily unavailable.';
    $totalReceivables = $totalCollections = $onlineCollections = $walkinCollections = 0;
    $processingFees = $checkoutTotal = 0;
    $officialTransactionCount = $pendingOnline = $failedOnline = 0;
    $channelBreakdown = $recentCollections = [];
    $maxChannelTotal = 0;
    $env = 'unknown';
}

$pageTitle    = 'Collection Analytics (Admin)';
$activeModule = 'payment';
$activePage   = 'admin/collection-analytics';
$breadcrumbs  = [
    ['label' => 'Payment Management', 'url' => BASE_URL . '/modules/payment/index.php'],
    ['label' => 'Collection Analytics', 'url' => null],
];

require_once __DIR__ . '/../../../../includes/breadcrumbs.php';
require_once __DIR__ . '/../../../../includes/layout-start.php';
?>

<?php renderBreadcrumbs($breadcrumbs); ?>

<div class="container-fluid py-4">
    
    <div class="row mb-4 align-items-center g-3">
        <div class="col-lg-5">
            <h2 class="mb-1 fw-bolder"><i class="fas fa-chart-line text-primary me-2"></i>Collection & Analytics</h2>
            <p class="text-muted mb-0 fs-6">Official production collections, receivables, and payment operations.</p>
        </div>
        <div class="col-lg-7">
            <form method="get" class="card border-0 shadow-sm rounded-4 p-3">
                <div class="row g-2 align-items-end">
                    <div class="col-sm-4">
                        <label class="form-label small fw-semibold text-muted mb-1" for="range">Reporting period</label>
                        <select class="form-select" id="range" name="range">
                            <?php foreach (['today' => 'Today', 'week' => 'This week', 'month' => 'This month', 'semester' => 'Last 6 months', 'all' => 'All time', 'custom' => 'Custom'] as $value => $label): ?>
                                <option value="<?= $value ?>" <?= $selectedRange === $value ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-3 custom-date-field">
                        <label class="form-label small fw-semibold text-muted mb-1" for="date_from">From</label>
                        <input type="date" class="form-control" id="date_from" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
                    </div>
                    <div class="col-sm-3 custom-date-field">
                        <label class="form-label small fw-semibold text-muted mb-1" for="date_to">To</label>
                        <input type="date" class="form-control" id="date_to" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
                    </div>
                    <div class="col-sm-2 d-grid">
                        <button class="btn btn-primary" type="submit"><i class="ti ti-filter me-1"></i>Apply</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <?php if (isset($dbError)): ?>
        <div class="alert alert-danger shadow-sm"><i class="ti ti-alert-triangle me-2"></i> Database Error: <?= htmlspecialchars($dbError) ?></div>
    <?php endif; ?>

    <!-- FINANCIAL OVERVIEW -->
    <h5 class="fw-bold mb-3 text-dark">Financial Overview</h5>
    <div class="row mb-5">
        <div class="col-md-3 mb-3 mb-md-0">
            <div class="card border-0 shadow-sm rounded-4 border-start border-danger border-4 h-100">
                <div class="card-body">
                    <p class="text-muted fw-bold mb-1 text-uppercase" style="font-size: 0.8rem;">Total Receivables</p>
                    <h3 class="fw-bolder mb-0 text-danger">₱ <?= number_format((float)$totalReceivables, 2) ?></h3>
                    <small class="text-muted">Current outstanding balance</small>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3 mb-md-0">
            <div class="card border-0 shadow-sm rounded-4 border-start border-success border-4 h-100">
                <div class="card-body">
                    <p class="text-muted fw-bold mb-1 text-uppercase" style="font-size: 0.8rem;">Official Collections (Amount Applied)</p>
                    <h3 class="fw-bolder mb-0 text-success">₱ <?= number_format((float)$totalCollections, 2) ?></h3>
                    <small class="text-muted"><?= number_format($officialTransactionCount) ?> official transactions</small>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3 mb-md-0">
            <div class="card border-0 shadow-sm rounded-4 border-start border-primary border-4 h-100">
                <div class="card-body">
                    <p class="text-muted fw-bold mb-1 text-uppercase" style="font-size: 0.8rem;">Online Collections</p>
                    <h3 class="fw-bolder mb-0 text-primary">₱ <?= number_format((float)$onlineCollections, 2) ?></h3>
                    <small class="text-muted">Verified LIVE gateway payments</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm rounded-4 border-start border-info border-4 h-100">
                <div class="card-body">
                    <p class="text-muted fw-bold mb-1 text-uppercase" style="font-size: 0.8rem;">Walk-in Collections</p>
                    <h3 class="fw-bolder mb-0 text-info">₱ <?= number_format((float)$walkinCollections, 2) ?></h3>
                    <small class="text-muted">Verified non-online payments</small>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-5">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <p class="text-muted fw-bold text-uppercase small mb-1">Processing Fees</p>
                        <h4 class="fw-bolder mb-1">₱ <?= number_format($processingFees, 2) ?></h4>
                        <small class="text-muted">Gateway fees in the selected official scope</small>
                    </div>
                    <span class="analytics-icon bg-warning-subtle text-warning"><i class="ti ti-receipt-tax"></i></span>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <p class="text-muted fw-bold text-uppercase small mb-1">Checkout Total Charged</p>
                        <h4 class="fw-bolder mb-1">₱ <?= number_format($checkoutTotal, 2) ?></h4>
                        <small class="text-muted">Amount applied plus applicable processing fees</small>
                    </div>
                    <span class="analytics-icon bg-primary-subtle text-primary"><i class="ti ti-credit-card"></i></span>
                </div>
            </div>
        </div>
    </div>

    <!-- GATEWAY HEALTH -->
    <h5 class="fw-bold mb-3 text-dark">Payment Gateway Health (PayMongo)</h5>
    <div class="row mb-4">
        <div class="col-md-4 mb-3 mb-md-0">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="flex-shrink-0">
                        <div class="bg-light rounded-circle p-3 text-center" style="width: 60px; height: 60px;">
                            <i class="fas fa-server text-primary fs-4"></i>
                        </div>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <p class="text-muted fw-bold mb-0 text-uppercase" style="font-size: 0.75rem;">Current Environment</p>
                        <h5 class="fw-bolder mb-0 text-dark text-capitalize"><?= htmlspecialchars($env) ?> Mode</h5>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-4 mb-3 mb-md-0">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="flex-shrink-0">
                        <div class="bg-light rounded-circle p-3 text-center" style="width: 60px; height: 60px;">
                            <i class="fas fa-hourglass-half text-warning fs-4"></i>
                        </div>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <p class="text-muted fw-bold mb-0 text-uppercase" style="font-size: 0.75rem;">Pending Online Transactions</p>
                        <h5 class="fw-bolder mb-0 text-dark"><?= number_format((float)($pendingOnline ?? 0)) ?></h5>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="flex-shrink-0">
                        <div class="bg-light rounded-circle p-3 text-center" style="width: 60px; height: 60px;">
                            <i class="ti ti-circle-x text-danger fs-4"></i>
                        </div>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <p class="text-muted fw-bold mb-0 text-uppercase" style="font-size: 0.75rem;">Failed / Rejected Online</p>
                        <h5 class="fw-bolder mb-0 text-dark"><?= number_format((float)($failedOnline ?? 0)) ?></h5>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mt-1">
        <div class="col-xl-5">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-transparent border-0 px-4 pt-4 pb-2">
                    <h5 class="fw-bold mb-1"><i class="ti ti-chart-bar text-primary me-2"></i>Collections by Channel</h5>
                    <p class="text-muted small mb-0">Official amount applied for the selected period</p>
                </div>
                <div class="card-body px-4">
                    <?php if (!$channelBreakdown): ?>
                        <div class="analytics-empty"><i class="ti ti-chart-bar-off"></i><span>No official collections in this period.</span></div>
                    <?php else: ?>
                        <?php foreach ($channelBreakdown as $channel):
                            $channelAmount = (float) $channel['total_amount'];
                            $barWidth = $maxChannelTotal > 0 ? max(4, ($channelAmount / $maxChannelTotal) * 100) : 0;
                        ?>
                            <div class="mb-4">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <div><strong><?= htmlspecialchars($channel['payment_channel']) ?></strong><span class="text-muted small ms-2"><?= number_format((int) $channel['transaction_count']) ?> txn</span></div>
                                    <strong>₱ <?= number_format($channelAmount, 2) ?></strong>
                                </div>
                                <div class="progress analytics-progress"><div class="progress-bar" style="width: <?= number_format($barWidth, 2, '.', '') ?>%"></div></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-xl-7">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-transparent border-0 px-4 pt-4 pb-2 d-flex justify-content-between align-items-start">
                    <div>
                        <h5 class="fw-bold mb-1"><i class="ti ti-list-details text-primary me-2"></i>Recent Official Collections</h5>
                        <p class="text-muted small mb-0">Latest verified LIVE and approved non-online payments</p>
                    </div>
                    <a class="btn btn-sm btn-outline-primary" href="<?= BASE_URL ?>/modules/payment/pages/admin/transaction-history.php">View history</a>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0 analytics-table">
                        <thead><tr><th>Student</th><th>Channel</th><th>Date</th><th class="text-end">Amount</th></tr></thead>
                        <tbody>
                        <?php if (!$recentCollections): ?>
                            <tr><td colspan="4"><div class="analytics-empty"><i class="ti ti-receipt-off"></i><span>No official collections found.</span></div></td></tr>
                        <?php else: ?>
                            <?php foreach ($recentCollections as $payment): ?>
                                <tr>
                                    <td><div class="fw-semibold"><?= htmlspecialchars($payment['full_name']) ?></div><small class="text-muted"><?= htmlspecialchars($payment['student_number']) ?></small></td>
                                    <td><span class="badge rounded-pill text-bg-light border"><?= htmlspecialchars($payment['payment_channel'] ?: 'Unspecified') ?></span></td>
                                    <td><div><?= htmlspecialchars(date('M d, Y', strtotime($payment['payment_date']))) ?></div><small class="text-muted"><?= htmlspecialchars($payment['reference_number']) ?></small></td>
                                    <td class="text-end fw-bold text-success">₱ <?= number_format((float) $payment['amount'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

</div>

<style>
.analytics-icon{width:52px;height:52px;border-radius:16px;display:grid;place-items:center;font-size:1.45rem;flex:0 0 auto}.analytics-progress{height:8px;background:var(--bs-tertiary-bg);border-radius:99px}.analytics-progress .progress-bar{border-radius:99px;background:linear-gradient(90deg,#2563eb,#06b6d4)}.analytics-table thead th{padding:.85rem 1.25rem;border-top:0;color:var(--bs-secondary-color);font-size:.72rem;text-transform:uppercase;letter-spacing:.04em}.analytics-table tbody td{padding:.9rem 1.25rem}.analytics-empty{min-height:150px;display:flex;flex-direction:column;align-items:center;justify-content:center;color:var(--bs-secondary-color);gap:.6rem;text-align:center}.analytics-empty i{font-size:2rem}@media(max-width:575.98px){.analytics-table{min-width:620px}}
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const range = document.getElementById('range');
    const fields = document.querySelectorAll('.custom-date-field');
    const syncCustomDates = () => fields.forEach(field => field.classList.toggle('d-none', range.value !== 'custom'));
    range.addEventListener('change', syncCustomDates);
    syncCustomDates();
});
</script>

<?php require_once __DIR__ . '/../../../../includes/layout-end.php'; ?>
