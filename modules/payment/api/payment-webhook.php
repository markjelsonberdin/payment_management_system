<?php
/**
 * SMS 2 - Payment Gateway Webhook REST API
 * Handles asynchronous payment confirmations from gateways (e.g., PayMongo)
 *
 * Deprecated: the supported, signed webhook is api/paymongo/webhook.php.
 * This file is retained temporarily to prevent stale external URLs from
 * silently posting financial records through an unsafe legacy path.
 */
http_response_code(410);
header('Content-Type: application/json');
echo json_encode([
    'success' => false,
    'error' => 'WEBHOOK_ENDPOINT_DEPRECATED',
    'message' => 'Use /modules/payment/api/paymongo/webhook.php.'
]);
exit;

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../database/db_connect.php';

// Siguraduhing POST request ang tumawag
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit();
}

// Kunin ang incoming JSON payload mula sa payment gateway
$payload = file_get_contents('php_input');
$event = json_decode($payload, true);

if (!$event || !isset($event['data'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid Payload']);
    exit();
}

$eventType = $event['data']['attributes']['type'] ?? '';

// Halimbawa: Kapag successful ang payment
if ($eventType === 'payment.paid') {
    $paymentData = $event['data']['attributes']['data'];
    $referenceId = $paymentData['attributes']['reference_number'] ?? ''; // Ito ang billing_id o transaction id natin
    $amountPaid  = ($paymentData['attributes']['amount'] ?? 0) / 100; // Convert cents to peso

    try {
        $pdo = studentPortalDb();
        $pdo->beginTransaction();

        // 1. I-update ang billing status at remaining balance
        $stmtUpdateBilling = $pdo->prepare("
            UPDATE billing 
            SET remaining_balance = GREATEST(0, remaining_balance - :paid),
                billing_status = CASE WHEN (remaining_balance - :paid) <= 0 THEN 'Paid' ELSE 'Partial' END
            WHERE billing_id = :billing_id
        ");
        $stmtUpdateBilling->execute([
            ':paid'       => $amountPaid,
            ':billing_id' => $referenceId
        ]);

        // 2. Mag-insert sa collections/payment history table
        $stmtInsertCollection = $pdo->prepare("
            INSERT INTO collections (billing_id, amount_paid, payment_method, reference_no, status)
            VALUES (:billing_id, :amount, 'Online Gateway', :ref, 'Completed')
        ");
        $stmtInsertCollection->execute([
            ':billing_id' => $referenceId,
            ':amount'     => $amountPaid,
            ':ref'        => $paymentData['id']
        ]);

        $pdo->commit();
        http_response_code(200);
        echo json_encode(['status' => 'success', 'message' => 'Payment recorded successfully']);

    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
} else {
    http_response_code(200);
    echo json_encode(['status' => 'ignored', 'message' => 'Event type not handled']);
}
