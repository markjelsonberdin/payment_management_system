<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once __DIR__ . '/../database/db_connect.php';
requireAuth();
requirePaymentPermission('payment.online_payment_config');
header('Content-Type: application/json; charset=utf-8');
try {
    $settings = [];
    foreach ($pdo->query("SELECT setting_key, setting_value FROM payment_gateway_settings")->fetchAll(PDO::FETCH_ASSOC) as $row) { $settings[$row['setting_key']] = $row['setting_value']; }
    $mode = ($settings['gateway_mode'] ?? 'test') === 'live' ? 'live' : 'test';
    $channels = [];
    foreach (['gcash' => 'GCash', 'maya' => 'Maya', 'qrph' => 'QR Ph', 'card' => 'Card'] as $key => $label) { $channels[] = ['name' => $label, 'enabled' => ($settings[$mode . '_channel_' . $key] ?? '0') === '1']; }
    $pending = (int)$pdo->query("SELECT COUNT(*) FROM payments WHERE transaction_type = 'Online' AND payment_status = 'Pending'")->fetchColumn();
    $verifiedMonth = (int)$pdo->query("SELECT COUNT(*) FROM payments WHERE transaction_type = 'Online' AND payment_status = 'Verified' AND gateway_environment = 'live' AND YEAR(payment_date) = YEAR(CURDATE()) AND MONTH(payment_date) = MONTH(CURDATE())")->fetchColumn();
    $saleCategories = (int)$pdo->query("SELECT COUNT(*) FROM school_sale_categories WHERE status = 'Active'")->fetchColumn();
    $saleItems = (int)$pdo->query("SELECT COUNT(*) FROM school_sale_items WHERE status = 'Active'")->fetchColumn();
    echo json_encode(['ok'=>true,'gateway_mode'=>$mode,'channels'=>$channels,'pending_online'=>$pending,'verified_month'=>$verifiedMonth,'active_sale_categories'=>$saleCategories,'active_sale_items'=>$saleItems]);
} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode(['ok'=>false,'message'=>'Dashboard data is temporarily unavailable. Check the payment configuration and cashier sales migrations.']);
}