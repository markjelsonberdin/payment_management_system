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
    'mis_admin' => ['MIS Admin Staffs', 'Integrations', 'Security', 'Audit'],
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
$overviewCards = [];
foreach ($allowedSlugs as $slug) {
    $page = $pageBySlug[$slug] ?? null;
    if ($page === null || (isset($page['permission']) && !paymentRoleAllowsPermission($role, $page['permission']))) {
        continue;
    }
    $overviewCards[] = $page;
}

// A role-visible sidebar group must always have matching overview shortcuts.
// This fallback protects the UI from incomplete legacy permission metadata;
// it does not change the page-level authorization checks.
if ($overviewCards === [] && isset($groupByRole[$role])) {
    foreach ($groupNames as $groupName) {
        foreach (($paymentMeta['groups'][$groupName] ?? []) as $slug) {
            if (isset($pageBySlug[$slug])) {
                $overviewCards[] = $pageBySlug[$slug];
            }
        }
    }
}

require_once ROOT_PATH . '/includes/breadcrumbs.php';
require_once ROOT_PATH . '/includes/nav-icons.php';
require_once ROOT_PATH . '/includes/layout-start.php';
renderBreadcrumbs($breadcrumbs);
?>
<main class="container-fluid py-4">
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
                                <small class="text-muted">Open submodule</small>
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
