<?php
/**
 * SMS 2 - PayMongo Webhook Endpoint
 * 
 * Handles incoming webhooks from PayMongo and processes 
 * the automated payment verification and allocation securely.
 */

require_once __DIR__ . '/../../../../config/config.php';
require_once ROOT_PATH . '/modules/payment/database/db_connect.php';
require_once ROOT_PATH . '/modules/payment/includes/paymongo/PayMongoWebhookSecurityService.php';
require_once ROOT_PATH . '/modules/payment/includes/PaymentAllocationService.php';

ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Invalid request method");
    }

    $rawPayload = file_get_contents('php://input');
    $signatureHeader = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '';

    $stmtMode = $pdo->query("SELECT setting_value FROM payment_db.payment_gateway_settings WHERE setting_key = 'gateway_mode'");
    $activeMode = $stmtMode->fetchColumn() ?: 'test';

    $securityService = new PayMongoWebhookSecurityService($pdo, $activeMode);
    $securityService->verifySignature($signatureHeader, $rawPayload);

    $payload = json_decode($rawPayload, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON payload");
    }

    $eventType = $payload['data']['attributes']['type'] ?? '';
    $eventData = $payload['data']['attributes']['data'] ?? [];
    
    // Environment validation
    $payloadEnv = $eventData['attributes']['livemode'] ? 'live' : 'test';
    if ($payloadEnv !== $activeMode) {
        throw new Exception("Environment mismatch: Webhook is $payloadEnv but system is $activeMode");
    }

    // Handle different event types
    if ($eventType === 'checkout_session.payment.paid') {
        $checkoutSessionId = $eventData['id'] ?? '';
        $paymongoAmount = $eventData['attributes']['line_items'][0]['amount'] ?? 0;
        $paymongoAmountDec = $paymongoAmount / 100;
        
        if (empty($checkoutSessionId)) throw new Exception("Missing checkout_session_id");

        $stmt = $pdo->prepare("SELECT * FROM payment_db.payments WHERE checkout_session_id = :session_id");
        $stmt->execute([':session_id' => $checkoutSessionId]);
        $internalPayment = $stmt->fetch(PDO::FETCH_ASSOC);

    } elseif ($eventType === 'payment.paid') {
        $paymentIntentId = $eventData['attributes']['payment_intent_id'] ?? '';
        $paymongoAmount = $eventData['attributes']['amount'] ?? 0;
        $paymongoAmountDec = $paymongoAmount / 100;

        if (empty($paymentIntentId)) {
            // Fallback: check metadata if payment_intent_id is not directly exposed
            $paymentIntentId = $eventData['attributes']['metadata']['payment_intent_id'] ?? '';
        }
        
        if (empty($paymentIntentId)) throw new Exception("Missing payment_intent_id in payment.paid event");

        $stmt = $pdo->prepare("SELECT * FROM payment_db.payments WHERE payment_intent_id = :pi_id");
        $stmt->execute([':pi_id' => $paymentIntentId]);
        $internalPayment = $stmt->fetch(PDO::FETCH_ASSOC);

    } elseif ($eventType === 'payment.failed') {
        $paymentIntentId = $eventData['attributes']['payment_intent_id'] ?? '';
        if ($paymentIntentId) {
            $stmt = $pdo->prepare("UPDATE payment_db.payments SET payment_status = 'Failed' WHERE payment_intent_id = :pi_id AND payment_status = 'Pending'");
            $stmt->execute([':pi_id' => $paymentIntentId]);
        }
        echo json_encode(['success' => true, 'message' => 'Payment marked failed']);
        exit;

    } elseif ($eventType === 'qrph.expired') {
        // Just log it or optionally update remarks. 
        // We keep it 'Pending' so the student can resume it (regenerate QR).
        $paymentIntentId = $eventData['attributes']['payment_intent_id'] ?? $eventData['id'] ?? '';
        if ($paymentIntentId) {
            $stmt = $pdo->prepare("UPDATE payment_db.payments SET remarks = CONCAT(IFNULL(remarks,''), ' [QR Expired]') WHERE payment_intent_id = :pi_id");
            $stmt->execute([':pi_id' => $paymentIntentId]);
        }
        echo json_encode(['success' => true, 'message' => 'QR Ph expired noted']);
        exit;

    } else {
        echo json_encode(['success' => true, 'message' => 'Event ignored']);
        exit;
    }

    if (!$internalPayment) {
        throw new Exception("No internal payment record found");
    }

    // Idempotency check
    if ($internalPayment['payment_status'] === 'Verified') {
        echo json_encode(['success' => true, 'message' => 'Already verified']);
        exit;
    }
    if ($internalPayment['payment_status'] !== 'Pending') {
        throw new Exception("Payment record is in an unexpected state: " . $internalPayment['payment_status']);
    }

    // Validate Context matches
    if (empty($internalPayment['student_id']) || empty($internalPayment['billing_id'])) {
        throw new Exception("Payment record lacks required context (student_id/billing_id)");
    }

    // Amount validation
    $expectedTotal = (float) $internalPayment['checkout_total'];
    if (abs($expectedTotal - $paymongoAmountDec) > 0.01) {
        throw new Exception("Amount mismatch. Expected: $expectedTotal, Actual: $paymongoAmountDec");
    }

    // Handle expiry/late-payment policy
    if (!empty($internalPayment['expires_at'])) {
        $expiresAt = strtotime($internalPayment['expires_at']);
        if (time() > $expiresAt) {
            // Late Webhook Policy: If a QR is expired but a payment is later officially confirmed by PayMongo 
            // and passes all validation (signature, idempotency, intent ownership, amount, state), RECONCILE AS PAID.
            error_log("[" . date('Y-m-d H:i:s') . "] Late Webhook Reconciliation: Payment ID {$internalPayment['payment_id']} confirmed by PayMongo after expiry time ({$internalPayment['expires_at']}). Reconciling as Paid.\n", 3, __DIR__ . '/webhook_error.log');
        }
    }

    // Allocation Logic
    $pdo->beginTransaction();

    $stmtUpdate = $pdo->prepare("
        UPDATE payment_db.payments 
        SET payment_status = 'Verified', verified_at = CURRENT_TIMESTAMP 
        WHERE payment_id = :pid
    ");
    $stmtUpdate->execute([':pid' => $internalPayment['payment_id']]);

    $allocationService = new PaymentAllocationService($pdo);
    $allocationService->allocatePayment(
        $internalPayment['payment_id'],
        $internalPayment['student_id'],
        $internalPayment['billing_id'],
        (float) $internalPayment['amount'],
        $internalPayment['allocation_context'],
        $internalPayment['billing_item_id']
    );

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Payment successfully verified and allocated']);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("[" . date('Y-m-d H:i:s') . "] Webhook Error: " . $e->getMessage() . "\n", 3, __DIR__ . '/webhook_error.log');
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
