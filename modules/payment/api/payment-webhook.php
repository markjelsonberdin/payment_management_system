<?php
/**
 * SMS 2 - Payment Gateway Webhook REST API
 * Handles asynchronous payment confirmations from gateways (e.g., PayMongo)
 */
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
            UPDATE payment_db.billing 
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
            INSERT INTO payment_db.collections (billing_id, amount_paid, payment_method, reference_no, status)
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