<?php
/**
 * Developer Tool: Simulate QR Ph Payment Success
 * Usage: Access this via browser e.g. http://localhost/SMS2_system/simulate-qr.php?pi=pi_xxxxx
 */
require_once __DIR__ . '/config/config.php';
require_once ROOT_PATH . '/modules/payment/database/db_connect.php';

$paymentIntentId = $_GET['pi'] ?? null;

if (!$paymentIntentId) {
    echo "<h2>Please provide a payment_intent_id.</h2>";
    echo "Usage: <code>?pi=pi_your_payment_intent_id</code><br><br>";
    echo "<h3>Recent Pending QR Ph Payments:</h3><ul>";
    
    global $pdo;
    $stmt = $pdo->query("SELECT payment_intent_id, amount FROM payment_db.payments WHERE payment_channel = 'QRPh' AND payment_status = 'Pending' ORDER BY payment_id DESC LIMIT 5");
    while ($row = $stmt->fetch()) {
        $pi = $row['payment_intent_id'];
        echo "<li><a href='simulate-qr.php?pi={$pi}'>{$pi}</a> - PHP {$row['amount']}</li>";
    }
    echo "</ul>";
    exit;
}

global $pdo;
$stmt = $pdo->prepare("SELECT amount FROM payment_db.payments WHERE payment_intent_id = :pi");
$stmt->execute([':pi' => $paymentIntentId]);
$amount = $stmt->fetchColumn();

if (!$amount) {
    die("Payment Intent not found in the database.");
}

// Prepare webhook payload
$webhookPayload = [
    "data" => [
        "attributes" => [
            "type" => "payment.paid",
            "data" => [
                "id" => "pay_mock_" . time(),
                "attributes" => [
                    "amount" => floatval($amount) * 100, // PayMongo expects cents
                    "payment_intent_id" => $paymentIntentId,
                    "livemode" => false
                ]
            ]
        ]
    ]
];

$jsonPayload = json_encode($webhookPayload);

// We need to generate a valid signature for the test environment.
$config = require __DIR__ . '/modules/payment/config/paymongo.php';
$secret = $config['webhook_secret'];
$timestamp = time();
$teSignature = hash_hmac('sha256', $timestamp . '.' . $jsonPayload, $secret);
$signatureHeader = "t=$timestamp,te=$teSignature,li=";

// Send to webhook
$webhookUrl = BASE_URL . '/modules/payment/api/paymongo/webhook.php';

$ch = curl_init($webhookUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Paymongo-Signature: ' . $signatureHeader
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "<h2>Simulation Complete</h2>";
echo "<b>Webhook Response Code:</b> $httpCode<br>";
echo "<b>Webhook Response:</b> " . htmlspecialchars($response) . "<br>";
echo "<br><a href='simulate-qr.php'>Back</a>";
