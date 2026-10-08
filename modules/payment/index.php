<?php
require_once __DIR__ . '/../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
requireAuth();

$pageTitle = 'Payment Management';
$activeModule = 'payment';
$activePage = '';
$breadcrumbs = [['label' => 'Payment Management', 'url' => null]];
$role = smsNormalizeRoleKey(getCurrentUserRoleKey());
$groupByRole = [
    'accounting_officer' => 'ACCOUNTING PORTAL',
    'accounting_admin' => 'ACCOUNTING ADMIN PORTAL',
    'cashier' => 'CASHIER PORTAL',
    'mis_admin' => ['Payment Administration', 'Integrations', 'Security'],
];
$paymentMeta = $MODULES['payment'] ?? ['groups' => [], 'pages' => []];
$configuredGroups = $groupByRole[$role] ?? array_keys($paymentMeta['groups']);
$groupNames = is_array($configuredGroups) ? $configuredGroups : [$configuredGroups];
$allowedSlugs = [];
foreach ($groupNames as $groupName) {
    foreach (($paymentMeta['groups'][$groupName] ?? []) as $slug) {
        if (!in_array($slug, $allowedSlugs, true)) {
            $allowedSlugs[] = $slug;
        }
    }
}
$pageBySlug = [];
foreach ($paymentMeta['pages'] as $page) {
    $pageBySlug[$page['slug']] = $page;
}
$routeOverrides = [
    'accounting/collection-reporting-analytics' => 'accounting/collection-reporting-integrated.php',
];
$pageDescriptions = [
    'accounting/dashboard' => 'Billing workload and operational exceptions.',
    'accounting_admin/dashboard' => 'Verified collections and financial oversight.',
    'accounting/fee-setup-configuration' => 'Manage fee definitions and effective versions.',
    'accounting/student-billing-invoicing' => 'Create individual and managed billing runs.',
    'accounting/discount-scholarship-application' => 'Review eligible discounts and scholarships.',
    'accounting/payment-history-ledger-system' => 'Review official payments and student ledgers.',
    'accounting/payment-concern-portal' => 'Review student payment concerns and evidence.',
    'accounting/bank-reconciliation' => 'Import and reconcile bank statement records.',
    'accounting/collection-reporting-analytics' => 'Analyze official collections and receivables.',
    'accounting_admin/managed-bulk-approval' => 'Review and approve managed billing runs.',
    'accounting_admin/school-sales-catalog' => 'Manage school sales items and prices.',
    'cashier/payment-collection-portal' => 'Collect authorized walk-in payments.',
    'cashier/dashboard' => 'Review collections and cashier activity.',
    'cashier/school-sales' => 'Sell configured school items.',
    'cashier/walk-in-transaction-history' => 'Find completed collections and receipts.',
    'mis_admin/dashboard' => 'Payment system health and operational status.',
    'mis_admin/payment-user-management' => 'Manage Payment staff access.',
    'mis_admin/roles-permissions' => 'Review Payment roles and module access.',
    'mis_admin/online-payment-integration' => 'Configure PayMongo integration.',
    'mis_admin/google-ocr-integration' => 'Configure receipt OCR integration.',
    'mis_admin/security-monitoring' => 'Review Payment security events and account state.',
];
$overviewCards = [];
foreach ($allowedSlugs as $slug) {
    $page = $pageBySlug[$slug] ?? null;
    if ($page === null || (isset($page['permission']) && !paymentEffectivePermission($role, $page['permission']))) {
        continue;
    }
    if ($slug === 'accounting_admin/dashboard' && !paymentEffectivePermission($role, 'report.view')) continue;
    $overviewCards[] = $page;
}

require_once ROOT_PATH . '/includes/breadcrumbs.php';
require_once ROOT_PATH . '/includes/nav-icons.php';
require_once ROOT_PATH . '/includes/layout-start.php';
renderBreadcrumbs($breadcrumbs);
?>
<main class="container-fluid payment-page py-4">
    <div class="page-header">
        <h1><i class="fas fa-credit-card text-sms-primary me-2" aria-hidden="true"></i>Payment Management</h1>
        <p>Select a payment module to get started.</p>
    </div>

    <div class="row g-3 module-button-grid">
        <?php foreach ($overviewCards as $page): ?>
            <?php
            $slug = $page['slug'];
            $path = $routeOverrides[$slug] ?? ($slug . '.php');
            $href = BASE_URL . '/modules/payment/pages/' . $path;
            ?>
            <div class="col-md-6 col-lg-4">
                <a href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>" class="text-decoration-none d-block h-100">
                    <div class="card module-card hover-card h-100">
                        <div class="card-body d-flex align-items-center gap-3">
                            <div class="card-icon"><i class="fas <?= htmlspecialchars(smsNavPageIcon($slug), ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i></div>
                            <div class="min-w-0">
                                <h6 class="mb-0 fw-semibold"><?= htmlspecialchars($page['title'], ENT_QUOTES, 'UTF-8') ?></h6>
                                <small class="text-muted"><?= htmlspecialchars($pageDescriptions[$slug] ?? 'Open this Payment module', ENT_QUOTES, 'UTF-8') ?></small>
                            </div>
                        </div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
        <?php if ($overviewCards === []): ?>
            <div class="col-12">
                <div class="alert alert-info mb-0" role="status">No Payment shortcuts are available for this role.</div>
            </div>
        <?php endif; ?>
    </div>
</main>
<?php require_once ROOT_PATH . '/includes/layout-end.php'; ?>
