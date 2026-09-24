<?php
/**
 * API Endpoint: PayMongo Connection Tester
 * Pings the PayMongo API using the active secret key.
 */
require_once __DIR__ . '/../../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'AUTHENTICATION_REQUIRED']);
    exit;
}
if (!paymentRoleAllowsPermission(getCurrentUserRoleKey(), 'payment.online_payment_config')
    || !userCanAccessModule('payment.online_payment_config')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'FORBIDDEN']);
    exit;
}

require_once ROOT_PATH . '/modules/payment/database/db_connect.php';
require_once ROOT_PATH . '/modules/payment/includes/paymongo/PayMongoService.php';

try {
    (new PayMongoService())->testConnection();
    echo json_encode(['success' => true, 'status' => 'connected']);
} catch (Throwable $e) {
    error_log('PayMongo connection test failed.');
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'PAYMONGO_CONNECTION_FAILED']);
}
?>
