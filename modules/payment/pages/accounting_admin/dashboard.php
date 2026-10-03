<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';

requireAuth();
requirePaymentPermission('report.view');

$pageTitle = 'Accounting Admin Dashboard';
$activeModule = 'payment';
$activePage = 'accounting_admin/dashboard';
$breadcrumbs = [
    ['label' => 'Payment Management', 'url' => BASE_URL . '/modules/payment/index.php'],
    ['label' => 'Accounting Admin Dashboard', 'url' => null],
];

require_once ROOT_PATH . '/includes/breadcrumbs.php';
require_once ROOT_PATH . '/includes/layout-start.php';
renderBreadcrumbs($breadcrumbs);
?>
<main class="container-fluid py-4" id="accountingAdminDashboard">
    <header class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1"><i class="ti ti-chart-pie text-primary me-2" aria-hidden="true"></i>Accounting Admin Dashboard</h1>
            <p class="text-muted mb-0">Financial oversight workspace. Metrics will appear here when their approved transaction sources are available.</p>
        </div>
        <a class="btn btn-outline-primary" href="<?= htmlspecialchars(BASE_URL . '/modules/payment/pages/accounting_admin/managed-bulk-approval.php', ENT_QUOTES, 'UTF-8') ?>">
            <i class="ti ti-checkbox me-1" aria-hidden="true"></i>Review Billing Approvals
        </a>
    </header>

    <div class="alert alert-light border mb-4" role="status">
        <i class="ti ti-info-circle text-primary me-2" aria-hidden="true"></i>
        This dashboard intentionally shows no estimated or placeholder financial values. It will be populated only from verified billing, collection, receivable, and school-sales records.
    </div>

    <section class="row g-3 mb-4" aria-label="Financial summary">
        <?php foreach (['Financial Summary', 'Collection Summary', 'Outstanding AR Summary', 'Billing / Review Summary', 'School Sales Summary'] as $title): ?>
            <div class="col-12 col-sm-6 col-xl">
                <article class="card h-100 border-0 shadow-sm">
                    <div class="card-body">
                        <h2 class="h6 mb-2"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h2>
                        <p class="text-muted small mb-0">No verified dashboard data is available yet.</p>
                    </div>
                </article>
            </div>
        <?php endforeach; ?>
    </section>

    <section class="row g-3">
        <div class="col-lg-7">
            <article class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h5 mb-2">Recent Financial Activity</h2>
                    <p class="text-muted mb-0">Recent verified activity will be listed here once the financial dashboard data source is finalized.</p>
                </div>
            </article>
        </div>
        <div class="col-lg-5">
            <article class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h5 mb-2">Collection Trend</h2>
                    <p class="text-muted mb-0">Trend reporting is deferred until it can be calculated from actual posted transactions.</p>
                </div>
            </article>
        </div>
    </section>
</main>
<?php require_once ROOT_PATH . '/includes/layout-end.php'; ?>
