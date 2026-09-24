<?php
require_once __DIR__ . '/../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
requireAuth();

$role = smsNormalizeRoleKey(getCurrentUserRoleKey());
$dashboardRoutes = [
    'accounting_officer' => '/modules/payment/pages/accounting/dashboard.php',
    'payment_admin' => '/modules/payment/pages/payment_admin/dashboard.php',
    'finance' => '/modules/payment/pages/payment_admin/dashboard.php',
    'cashier' => '/modules/payment/pages/cashier/dashboard.php',
];
if (isset($dashboardRoutes[$role])) {
    header('Location: ' . BASE_URL . $dashboardRoutes[$role]);
    exit;
}
header('Location: ' . BASE_URL . '/dashboard/index.php');
exit;
?>
