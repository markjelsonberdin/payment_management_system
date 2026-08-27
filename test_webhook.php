<?php
require_once __DIR__ . '/config/config.php';
require_once ROOT_PATH . '/modules/payment/database/db_connect.php';

// 1. Insert dummy pending payment
$stmt = $pdo->query("SELECT student_id, billing_id FROM payment_db.billing LIMIT 1");
$billing = $stmt->fetch();

$paymentIntentId = 'pi_test_' . time();
$referenceNumber = 'PM-TEST-' . time();
$amount = 1000.00;

$stmtInsert = $pdo->prepare("
    INSERT INTO payment_db.payments 
    (student_id, billing_id, allocation_context, transaction_type, payment_method, amount, processing_fee, checkout_total, payment_channel, reference_number, payment_intent_id, payment_status, payment_date)
    VALUES 
    (:student_id, :billing_id, 'ENROLLMENT_PRIORITY', 'Online', 'Online', :amount, 0, :checkout_total, 'QRPh', :reference_number, :payment_intent_id, 'Pending', CURDATE())
");

$stmtInsert->execute([
    ':student_id' => $billing['student_id'],
    ':billing_id' => $billing['billing_id'],
    ':amount' => $amount,
    ':checkout_total' => $amount,
    ':reference_number' => $referenceNumber,
    ':payment_intent_id' => $paymentIntentId
]);

echo "Created pending payment with PI: $paymentIntentId\n";

// 2. Simulate Webhook
$webhookPayload = [
    "data" => [
        "attributes" => [
            "type" => "payment.paid",
            "data" => [
                "id" => "pay_test_" . time(),
                "attributes" => [
                    "amount" => $amount * 100,
                    "payment_intent_id" => $paymentIntentId,
                    "livemode" => false
                ]
            ]
        ]
    ]
];

$jsonPayload = json_encode($webhookPayload);

$ch = curl_init('http://localhost/SMS2_system/modules/payment/api/paymongo/webhook.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);

// We need to generate a valid signature for the test environment.
$config = require __DIR__ . '/modules/payment/config/paymongo.php';
$secret = $config['webhook_secret'];
$timestamp = time();
$teSignature = hash_hmac('sha256', $timestamp . '.' . $jsonPayload, $secret);
$signatureHeader = "t=$timestamp,te=$teSignature,li=";

curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Paymongo-Signature: ' . $signatureHeader
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Webhook Response Code: $httpCode\n";
echo "Webhook Response: $response\n";

// 3. Verify Payment Status
$stmtCheck = $pdo->prepare("SELECT payment_status FROM payment_db.payments WHERE payment_intent_id = :pi_id");
$stmtCheck->execute([':pi_id' => $paymentIntentId]);
$status = $stmtCheck->fetchColumn();

echo "Final Payment Status: $status\n";

// Clean up
$pdo->exec("DELETE FROM payment_db.payments WHERE payment_intent_id = '$paymentIntentId'");
