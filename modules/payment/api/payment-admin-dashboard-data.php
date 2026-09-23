<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
requireAuth();
requirePaymentPermission('payment.online_payment_config');
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../database/db_connect.php';
require_once __DIR__ . '/../includes/PaymentAdminReportingService.php';
try {
    $service = new PaymentAdminReportingService($pdo);
    $result = $service->load(['period' => $_GET['period'] ?? 'today']);
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'invalid_period','message'=>'The selected reporting period is invalid.']);
} catch (Throwable $e) {
    error_log('Payment Admin dashboard API: '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'dashboard_unavailable','message'=>'Payment operations data is temporarily unavailable.']);
}
