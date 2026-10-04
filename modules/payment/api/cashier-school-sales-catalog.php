<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once __DIR__ . '/../database/db_connect.php';
require_once __DIR__ . '/../includes/CashierSchoolSalesCatalogApiController.php';

header('Content-Type: application/json; charset=utf-8');

$controller = new CashierSchoolSalesCatalogApiController(new CashierSchoolSalesCatalogService($pdo));
$response = $controller->handle(
    (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
    $_GET,
    isAuthenticated(),
    paymentRoleAllowsPermission(getCurrentUserRoleKey(), 'payment.school_sales') && userCanAccessModule('payment.school_sales')
);
http_response_code($response['status']);
echo json_encode($response['body'], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
