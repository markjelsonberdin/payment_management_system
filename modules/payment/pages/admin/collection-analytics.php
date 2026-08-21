<?php
/**
 * SMS 2 - Admin Reporting: Collection Analytics
 * PURPOSE: Admin-level overview of collections and payment gateway health.
 */
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../includes/authentication.php';
require_once __DIR__ . '/../../../../includes/audit.php';
require_once __DIR__ . '/../../database/db_connect.php';

requireAuth();
requirePaymentPermission('payment.collection_analytics_view');

try {
    // 1. FINANCIAL OVERVIEW
    $totalReceivables = $pdo->query("SELECT SUM(remaining_balance) FROM billing WHERE billing_status != 'Paid'")->fetchColumn() ?: 0;
    $totalCollections = $pdo->query("SELECT SUM(amount) FROM payments WHERE payment_status = 'Verified'")->fetchColumn() ?: 0;
    
    // Online vs Walk-in Collections
    $stmtOnlineWalkin = $pdo->query("
        SELECT 
            SUM(CASE WHEN payment_channel IN ('gcash','maya','card','qrph','paymongo','visa') THEN amount ELSE 0 END) as online_collections,
            SUM(CASE WHEN payment_channel NOT IN ('gcash','maya','card','qrph','paymongo','visa') THEN amount ELSE 0 END) as walkin_collections
        FROM payments WHERE payment_status = 'Verified'
    ");
    $collectionSplit = $stmtOnlineWalkin->fetch(PDO::FETCH_ASSOC);
    $onlineCollections = $collectionSplit['online_collections'] ?: 0;
    $walkinCollections = $collectionSplit['walkin_collections'] ?: 0;

    // 2. GATEWAY HEALTH
    $pendingOnline = $pdo->query("SELECT COUNT(*) FROM payments WHERE payment_status = 'Pending' AND payment_method = 'PayMongo'")->fetchColumn() ?: 0;
    $failedOnline = $pdo->query("SELECT COUNT(*) FROM payments WHERE payment_status IN ('Failed', 'Rejected') AND payment_method = 'PayMongo'")->fetchColumn() ?: 0;
    
    // Get gateway environment
    $stmtEnv = $pdo->query("SELECT setting_value FROM payment_db.payment_gateway_settings WHERE setting_key = 'gateway_mode'");
    $env = $stmtEnv->fetchColumn() ?: 'test';

} catch (PDOException $e) {
    $dbError = $e->getMessage();
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
    
    <div class="row mb-4 align-items-center">
        <div class="col-md-6">
            <h2 class="mb-1 fw-bolder"><i class="fas fa-chart-line text-primary me-2"></i>Collection & Analytics</h2>
            <p class="text-muted mb-0 fs-6">System-wide financial oversight and payment gateway health monitoring.</p>
        </div>
    </div>

    <?php if (isset($dbError)): ?>
        <div class="alert alert-danger shadow-sm"><i class="fas fa-exclamation-triangle me-2"></i> Database Error: <?= htmlspecialchars($dbError) ?></div>
    <?php endif; ?>

    <!-- FINANCIAL OVERVIEW -->
    <h5 class="fw-bold mb-3 text-dark">Financial Overview</h5>
    <div class="row mb-5">
        <div class="col-md-3 mb-3 mb-md-0">
            <div class="card border-0 shadow-sm rounded-4 border-start border-danger border-4 h-100">
                <div class="card-body">
                    <p class="text-muted fw-bold mb-1 text-uppercase" style="font-size: 0.8rem;">Total Receivables</p>
                    <h3 class="fw-bolder mb-0 text-danger">₱ <?= number_format((float)$totalReceivables, 2) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3 mb-md-0">
            <div class="card border-0 shadow-sm rounded-4 border-start border-success border-4 h-100">
                <div class="card-body">
                    <p class="text-muted fw-bold mb-1 text-uppercase" style="font-size: 0.8rem;">Total Collections (Net)</p>
                    <h3 class="fw-bolder mb-0 text-success">₱ <?= number_format((float)$totalCollections, 2) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3 mb-md-0">
            <div class="card border-0 shadow-sm rounded-4 border-start border-primary border-4 h-100">
                <div class="card-body">
                    <p class="text-muted fw-bold mb-1 text-uppercase" style="font-size: 0.8rem;">Online Collections</p>
                    <h3 class="fw-bolder mb-0 text-primary">₱ <?= number_format((float)$onlineCollections, 2) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm rounded-4 border-start border-info border-4 h-100">
                <div class="card-body">
                    <p class="text-muted fw-bold mb-1 text-uppercase" style="font-size: 0.8rem;">Walk-in Collections</p>
                    <h3 class="fw-bolder mb-0 text-info">₱ <?= number_format((float)$walkinCollections, 2) ?></h3>
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
                            <i class="fas fa-times-circle text-danger fs-4"></i>
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

</div>

<?php require_once __DIR__ . '/../../../../includes/layout-end.php'; ?>
