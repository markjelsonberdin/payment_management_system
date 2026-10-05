<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';

header('Content-Type: application/json; charset=utf-8');

if (!paymentSchoolSalesSellingEnabled()) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'FEATURE_DISABLED', 'message' => 'School Sales is unavailable.']);
    exit;
}
if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'AUTHENTICATION_REQUIRED', 'message' => 'Authentication is required.']);
    exit;
}
if (!paymentRoleAllowsPermission(getCurrentUserRoleKey(), 'payment.school_sales') || !userCanAccessModule('payment.school_sales')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'CASHIER_CATALOG_READ_FORBIDDEN', 'message' => 'You do not have permission to read the Cashier catalog.']);
    exit;
}
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED', 'message' => 'Use GET for this read-only endpoint.']);
    exit;
}

require_once __DIR__ . '/../database/db_connect.php';
require_once __DIR__ . '/../includes/CashierSchoolSalesCatalogApiController.php';

$controller = new CashierSchoolSalesCatalogApiController(new CashierSchoolSalesCatalogService($pdo));
$response = $controller->handle(
    (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
    $_GET,
    true,
    true
);
http_response_code($response['status']);
echo json_encode($response['body'], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
