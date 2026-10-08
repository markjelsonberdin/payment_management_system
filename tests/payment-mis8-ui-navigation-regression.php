<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') throw new RuntimeException('CLI only');
$checks = 0;
function mis8check(bool $condition, string $message): void {
    global $checks;
    $checks++;
    if (!$condition) throw new RuntimeException($message);
}
$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$config = $read('config/config.php');
$auth = $read('includes/authentication.php');
$index = $read('modules/payment/index.php');
$sidebar = $read('includes/sidebar.php');
$pages = [
    'dashboard' => $read('modules/payment/pages/mis_admin/dashboard.php'),
    'overview' => $read('modules/payment/pages/mis_admin/overview.php'),
    'users' => $read('modules/payment/pages/mis_admin/payment-user-management.php'),
    'paymongo' => $read('modules/payment/pages/mis_admin/online-payment-integration.php'),
    'ocr' => $read('modules/payment/pages/mis_admin/google-ocr-integration.php'),
    'security' => $read('modules/payment/pages/mis_admin/security-monitoring.php'),
];
$expectedGroups = [
    'Payment Administration' => 'mis_admin/payment-user-management',
    'Integrations' => 'mis_admin/online-payment-integration',
    'Security' => 'mis_admin/security-monitoring',
];
foreach ($expectedGroups as $group => $slug) {
    mis8check(str_contains($config, "'{$group}'") && str_contains($config, "'{$slug}'"), "{$group} navigation group");
}
mis8check(str_contains($auth, "\$visible['payment']['label'] = 'MIS Admin'"), 'MIS-only module label');
mis8check(!str_contains($auth, "\$visible['payment']['overview_group_label'] = 'Dashboard'") && str_contains($sidebar, "Dashboard Module") && str_contains($sidebar, "if (!\$isMisOverview || \$paymentRoleKey === 'mis_admin')"), 'Dashboard and MIS Admin Overview use separate configured navigation sections');
mis8check(str_contains($sidebar, "/dashboard/index.php") && str_contains($index, "'mis_admin/dashboard'") && str_contains($config, "'slug' => 'mis_admin/dashboard'"), 'MIS Admin Dashboard route resolves independently');
mis8check(str_contains($sidebar, "/modules/payment/pages/mis_admin/overview.php") && str_contains($config, "'label' => 'Payment Management'"), 'MIS Admin Overview remains its own module destination');
mis8check(str_contains($index, "'mis_admin' => ['Payment Administration', 'Integrations', 'Security']"), 'Payment index supports MIS group list');
mis8check(str_contains($sidebar, 'foreach ($module[\'groups\'] as $groupLabel => $groupSlugs)'), 'Existing generic sidebar grouping preserved');
$mappings = [
    'mis_admin/dashboard' => 'payment.mis_overview',
    'mis_admin/payment-user-management' => 'payment_users.view',
    'mis_admin/online-payment-integration' => 'integration.paymongo.manage',
    'mis_admin/google-ocr-integration' => 'integration.ocr.manage',
    'mis_admin/security-monitoring' => 'payment.security.view',
];
foreach ($mappings as $slug => $permission) {
    mis8check((bool) preg_match("/'slug' => '" . preg_quote($slug, '/') . "'.*'permission' => '" . preg_quote($permission, '/') . "'/", $config), "{$slug} permission mapping");
}
$labels = ['Dashboard', 'Payment Users', 'PayMongo Integration', 'Google OCR Integration', 'Security Monitoring'];
foreach ($labels as $label) mis8check(str_contains($config, "'title' => '{$label}'"), "{$label} canonical label");
foreach ($pages as $name => $page) {
    mis8check((bool) preg_match('/<h1\b[^>]*>.*?<\/h1>/s', $page), "{$name} uses H1");
    if ($name === 'overview') {
        mis8check(str_contains($page, "ROOT_PATH.'/includes/layout-start.php'") && str_contains($page, 'payment-page'), "{$name} uses the existing shared layout");
    } else {
        mis8check(str_contains($page, 'payment-mis-admin.css'), "{$name} loads shared MIS CSS");
    }
    if ($name !== 'overview') {
        mis8check(str_contains($page, "['label' => 'MIS Admin'") || str_contains($page, "['label'=>'MIS Admin'"), "{$name} MIS breadcrumb");
    }
}
foreach (['users', 'security'] as $name) {
    mis8check(str_contains($pages[$name], 'scope="col"'), "{$name} table header semantics");
    mis8check(str_contains($pages[$name], 'table-responsive'), "{$name} responsive table");
}
mis8check(str_contains($pages['security'], 'personnelReset') && str_contains($pages['security'], 'eventReset'), 'Security reset controls');
$securityJs = $read('modules/payment/assets/js/payment-security-monitoring.js');
mis8check(str_contains($securityJs, "byId('personnelReset')") && str_contains($securityJs, "byId('eventReset')"), 'Security reset behavior');
mis8check(substr_count($pages['users'], 'aria-label="Close dialog"') >= 2, 'Payment user modal closes accessible');
mis8check(str_contains($pages['dashboard'], 'MIS_OVERVIEW_API') && str_contains($pages['dashboard'], "\$pageTitle = 'Dashboard'"), 'Dashboard retains its live status cards and dashboard route');
mis8check(str_contains($pages['overview'], "\$pageTitle='Overview'"), 'Overview has its own route and page identity');
mis8check(str_contains($pages['dashboard'], "requirePaymentPermission('payment.mis_overview')") && str_contains($pages['overview'], "requirePaymentPermission('payment.mis_overview')"), 'Both destinations enforce the MIS Overview permission server-side');
mis8check(str_contains($sidebar, "isPaymentDashboardPage) ? 'active'") && str_contains($sidebar, "\$activePage === 'mis_admin/overview'"), 'Dashboard and Overview have distinct active navigation states');
mis8check(str_contains($pages['overview'], 'module-button-grid'), 'Overview retains its separate module shortcut grid');
foreach (['payment-user-management.php', 'security-monitoring.php', 'online-payment-integration.php', 'google-ocr-integration.php'] as $route) {
    mis8check(str_contains($pages['overview'], $route), "Overview link {$route}");
}
$legacyUi = implode("\n", $pages);
foreach (['MIS Admin Overview', 'Payment Personnel Management', 'Online Payment Integration', 'Online Payment Configuration', 'Google OCR Configuration', 'Manage Payment Users'] as $legacy) {
    mis8check(!str_contains($legacyUi, $legacy), "Legacy UI label removed: {$legacy}");
}
foreach (['Fee Setup', 'Student Billing', 'Payment Approval', 'Payment Ledger', 'Accounts Receivable', 'Collection Reporting', 'School Sales', 'Refund', 'Void'] as $financial) {
    mis8check(!str_contains(implode("\n", array_intersect_key($pages, array_flip(['overview','users','security']))), $financial), "No financial MIS navigation: {$financial}");
}
mis8check(is_file($root . '/modules/payment/assets/css/payment-mis-admin.css'), 'Shared MIS CSS exists');
echo "PASS: {$checks} MIS-8 UI and navigation checks.\n";
