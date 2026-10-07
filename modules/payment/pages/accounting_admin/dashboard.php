<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once __DIR__ . '/../../database/db_connect.php';
require_once __DIR__ . '/../../includes/AccountingReportingPageService.php';

requireAuth();
requirePaymentPermission('billing.bulk.approve');
requirePaymentPermission('report.view');

$pageTitle = 'Accounting Admin Dashboard';
$activeModule = 'payment';
$activePage = 'accounting_admin/dashboard';
$breadcrumbs = [
    ['label' => 'Payment Management', 'url' => BASE_URL . '/modules/payment/index.php'],
    ['label' => 'Accounting Admin Dashboard', 'url' => null],
];

$report = null;
$dashboardError = null;
try {
    $report = (new AccountingReportingPageService($pdo))->loadForWeb($_GET);
} catch (InvalidArgumentException $e) {
    $dashboardError = $e->getMessage();
} catch (Throwable $e) {
    error_log('Accounting Admin dashboard: ' . $e->getMessage());
    $dashboardError = 'Dashboard data is temporarily unavailable.';
}

require_once ROOT_PATH . '/includes/breadcrumbs.php';
require_once ROOT_PATH . '/includes/layout-start.php';
renderBreadcrumbs($breadcrumbs);

$scope = $report['scope'] ?? [];
$kpis = $report['kpis'] ?? [];
$recent = $report['recent_collections'] ?? [];
$money = static fn (mixed $value): string => '₱' . number_format((float) $value, 2);
?>
<main class="container-fluid payment-page py-4" id="accountingAdminDashboard">
    <header class="payment-page-header">
        <div class="payment-page-header-text">
            <h1 class="h3 payment-page-title"><i class="ti ti-chart-pie me-2" aria-hidden="true"></i>Accounting Admin Dashboard</h1>
            <p>Verified collections, receivables, and billing oversight.</p>
            <?php if ($scope): ?>
                <p class="small mt-1"><?= htmlspecialchars(($scope['academic_year'] ?? '') . ' · ' . ($scope['semester'] ?? '') . ' semester · ' . ($scope['period_label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
        </div>
        <div class="payment-page-actions">
            <a class="btn btn-outline-primary" href="<?= htmlspecialchars(BASE_URL . '/modules/payment/pages/accounting/collection-reporting-integrated.php', ENT_QUOTES, 'UTF-8') ?>">
                <i class="ti ti-chart-bar me-1" aria-hidden="true"></i>Collection &amp; Analytics
            </a>
            <a class="btn btn-primary" href="<?= htmlspecialchars(BASE_URL . '/modules/payment/pages/accounting_admin/managed-bulk-approval.php', ENT_QUOTES, 'UTF-8') ?>">
                <i class="ti ti-checkbox me-1" aria-hidden="true"></i>Review Billing Approvals
            </a>
        </div>
    </header>

    <?php if ($dashboardError !== null): ?>
        <div class="alert alert-warning" role="alert">
            <i class="ti ti-alert-circle me-1" aria-hidden="true"></i><?= htmlspecialchars($dashboardError, ENT_QUOTES, 'UTF-8') ?>
            <a class="alert-link ms-1" href="<?= htmlspecialchars(BASE_URL . '/modules/payment/pages/accounting/collection-reporting-integrated.php', ENT_QUOTES, 'UTF-8') ?>">Open Collection &amp; Analytics</a>
        </div>
    <?php else: ?>
        <section class="payment-stat-grid" aria-label="Accounting financial summary">
            <?php foreach ([
                ['Official collections', 'official_academic_collections', 'ti-receipt', 'Verified payments in the selected period', 'is-success'],
                ['Term-to-date collections', 'term_to_date_collections', 'ti-wallet', 'Verified collections for this term', 'is-info'],
                ['Outstanding receivables', 'outstanding_balance', 'ti-report-money', 'Open student balances for this term', 'is-warning'],
                ['Net assessed', 'net_assessed_amount', 'ti-file-invoice', 'Assessment less recorded discounts', ''],
            ] as [$label, $key, $icon, $description, $tone]): ?>
                <article class="card payment-stat-card <?= $tone ?>">
                    <div class="card-body d-flex align-items-start gap-3">
                        <span class="payment-metric-icon" aria-hidden="true"><i class="ti <?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?>"></i></span>
                        <div class="min-w-0">
                            <p class="payment-stat-card-label"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></p>
                            <p class="payment-stat-card-value fs-5"><?= htmlspecialchars($money($kpis[$key] ?? 0), ENT_QUOTES, 'UTF-8') ?></p>
                            <p class="small text-muted mb-0"><?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>

        <section class="card payment-card payment-operational-table" aria-labelledby="adminRecentCollectionsTitle">
            <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h2 class="h5 mb-1" id="adminRecentCollectionsTitle">Recent verified collections</h2>
                    <p class="small text-muted mb-0">Payment allocations in the selected report period.</p>
                </div>
                <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars(BASE_URL . '/modules/payment/pages/accounting/collection-reporting-integrated.php', ENT_QUOTES, 'UTF-8') ?>">View report</a>
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th scope="col">Verified</th><th scope="col">Student</th><th scope="col">Receipt / Reference</th><th scope="col">Channel</th><th scope="col" class="text-end">Amount applied</th></tr></thead>
                    <tbody>
                    <?php if (!$recent): ?>
                        <tr><td class="payment-table-empty" colspan="5">No verified collections in this period.</td></tr>
                    <?php else: foreach ($recent as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) ($row['verified_at'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><span class="payment-table-primary"><?= htmlspecialchars((string) ($row['full_name'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></span><small class="payment-table-secondary"><?= htmlspecialchars((string) ($row['student_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?></small></td>
                            <td><?= htmlspecialchars((string) (($row['receipt_number'] ?? '') ?: ($row['reference_number'] ?? '—')), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><span class="payment-status is-info"><?= htmlspecialchars((string) ($row['payment_channel'] ?? 'Unspecified'), ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td class="payment-table-money"><?= htmlspecialchars($money($row['total_applied'] ?? 0), ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</main>
<?php require_once ROOT_PATH . '/includes/layout-end.php'; ?>
