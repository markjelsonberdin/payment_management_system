<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/modules/payment/database/db_connect.php';
require_once __DIR__ . '/modules/payment/includes/paymongo/paymongo/PayMongoService.php';

global $pdo;
$stmt = $pdo->query("SELECT * FROM payment_db.payments WHERE reference_number = 'PM-1788356244-3157'");
$payment = $stmt->fetch(PDO::FETCH_ASSOC);

if ($payment && !empty($payment['checkout_session_id'])) {
    $pm = new PayMongoService();
    $session = $pm->getCheckoutSession($payment['checkout_session_id']);
    echo json_encode($session['data']['attributes'], JSON_PRETTY_PRINT);
} else {
    echo "Payment not found or no checkout session ID";
}

