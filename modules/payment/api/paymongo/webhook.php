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

    $stmtMode = $pdo->query("SELECT setting_value FROM payment_gateway_settings WHERE setting_key = 'gateway_mode'");
    $activeMode = $stmtMode->fetchColumn() ?: 'test';

    $securityService = new PayMongoWebhookSecurityService($pdo, $activeMode);
    $securityService->verifySignature($signatureHeader, $rawPayload);

    $payload = json_decode($rawPayload, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON payload");
    }

    // PayMongo normally wraps the resource in an event envelope. Some
    // delivery views/retries can provide the payment resource directly.
    $eventType = $payload['data']['attributes']['type'] ?? '';
    $eventData = $payload['data']['attributes']['data'] ?? [];
    $webhookEventId = (string) ($payload['data']['id'] ?? '');
    if ($eventType === '' && ($payload['type'] ?? '') === 'payment') {
        $eventType = (($payload['attributes']['status'] ?? '') === 'paid')
            ? 'payment.paid'
            : 'payment.' . (string) ($payload['attributes']['status'] ?? 'unknown');
        $eventData = $payload;
    }
    
    // Environment validation
    $payloadEnv = !empty(
        $payload['data']['attributes']['livemode']
        ?? $eventData['attributes']['livemode']
    ) ? 'live' : 'test';
    if ($payloadEnv !== $activeMode) {
        throw new Exception("Environment mismatch: Webhook is $payloadEnv but system is $activeMode");
    }

    // Handle different event types
    if ($eventType === 'checkout_session.payment.paid') {
        $checkoutSessionId = $eventData['id'] ?? '';
        $paymongoAmount = $eventData['attributes']['line_items'][0]['amount'] ?? 0;
        $paymongoAmountDec = $paymongoAmount / 100;
        $paymongoCurrency = $eventData['attributes']['line_items'][0]['currency'] ?? '';
        
        if (empty($checkoutSessionId)) throw new Exception("Missing checkout_session_id");

        $stmt = $pdo->prepare("SELECT * FROM payments WHERE checkout_session_id = :session_id");
        $stmt->execute([':session_id' => $checkoutSessionId]);
        $internalPayment = $stmt->fetch(PDO::FETCH_ASSOC);

    } elseif ($eventType === 'payment.paid') {
        $paymentIntentId = $eventData['attributes']['payment_intent_id'] ?? '';
        $paymongoAmount = $eventData['attributes']['amount'] ?? 0;
        $paymongoAmountDec = $paymongoAmount / 100;
        $paymongoCurrency = $eventData['attributes']['currency'] ?? '';

        if (empty($paymentIntentId)) {
            // Fallback: check metadata if payment_intent_id is not directly exposed
            $paymentIntentId = $eventData['attributes']['metadata']['payment_intent_id'] ?? '';
        }
        
        if (empty($paymentIntentId)) throw new Exception("Missing payment_intent_id in payment.paid event");

        $stmt = $pdo->prepare("SELECT * FROM payments WHERE payment_intent_id = :pi_id");
        $stmt->execute([':pi_id' => $paymentIntentId]);
        $internalPayment = $stmt->fetch(PDO::FETCH_ASSOC);

    } elseif ($eventType === 'payment.failed') {
        $paymentIntentId = $eventData['attributes']['payment_intent_id'] ?? '';
        if ($paymentIntentId) {
            // QR Ph sandbox can emit payment.failed as soon as a real wallet
            // rejects its test-only QR. That event must not stop an otherwise
            // valid QR before the configured expiry. QR expiry is enforced by
            // the status endpoint; a verified payment still uses payment.paid.
            // Other payment channels continue to treat payment.failed as final.
            $stmt = $pdo->prepare(
                "SELECT payment_id, payment_channel
                 FROM payments
                 WHERE payment_intent_id = :pi_id
                 LIMIT 1"
            );
            $stmt->execute([':pi_id' => $paymentIntentId]);
            $failedPayment = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($activeMode === 'test'
                && $failedPayment
                && strcasecmp((string) $failedPayment['payment_channel'], 'QRPh') === 0
            ) {
                $stmt = $pdo->prepare(
                    "UPDATE payments
                     SET remarks = CASE
                         WHEN COALESCE(remarks, '') LIKE '%[PayMongo QR failure received]%' THEN remarks
                         ELSE CONCAT(COALESCE(remarks, ''), ' [PayMongo QR failure received]')
                     END
                     WHERE payment_id = :payment_id
                       AND payment_status = 'Pending'"
                );
                $stmt->execute([':payment_id' => $failedPayment['payment_id']]);
                echo json_encode(['success' => true, 'message' => 'QR failure noted; keeping it pending until expiry']);
                exit;
            }

            $stmt = $pdo->prepare("UPDATE payments SET payment_status = 'Failed' WHERE payment_intent_id = :pi_id AND payment_status = 'Pending'");
            $stmt->execute([':pi_id' => $paymentIntentId]);
        }
        echo json_encode(['success' => true, 'message' => 'Payment marked failed']);
        exit;

    } elseif (in_array($eventType, ['qrph.expired', 'qr.expired'], true)) {
        // Just log it or optionally update remarks. 
        // We keep it 'Pending' so the student can resume it (regenerate QR).
        $paymentIntentId = $eventData['attributes']['payment_intent_id'] ?? $eventData['id'] ?? '';
        if ($paymentIntentId) {
            $stmt = $pdo->prepare("UPDATE payments SET remarks = CONCAT(IFNULL(remarks,''), ' [QR Expired]') WHERE payment_intent_id = :pi_id");
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

    if (strtoupper((string) $paymongoCurrency) !== 'PHP') {
        throw new Exception("Unsupported or missing payment currency");
    }

    // Payment verification, allocation, and webhook idempotency are one Payment DB transaction.
    $pdo->beginTransaction();

    // Re-read the record under a row lock. This prevents concurrent PayMongo
    // retries from observing the same Pending payment and allocating it twice.
    $stmtLockedPayment = $pdo->prepare('SELECT * FROM payments WHERE payment_id = :payment_id FOR UPDATE');
    $stmtLockedPayment->execute([':payment_id' => $internalPayment['payment_id']]);
    $internalPayment = $stmtLockedPayment->fetch(PDO::FETCH_ASSOC);

    if (!$internalPayment) {
        throw new Exception('Payment record disappeared before verification');
    }

    if ($internalPayment['payment_status'] === 'Verified') {
        $pdo->rollBack();
        echo json_encode(['success' => true, 'message' => 'Already verified']);
        exit;
    }
    if ($internalPayment['payment_status'] !== 'Pending') {
        throw new Exception('Payment record is in an unexpected state: ' . $internalPayment['payment_status']);
    }

    if (empty($internalPayment['student_id']) || empty($internalPayment['billing_id'])) {
        throw new Exception('Payment record lacks required context (student_id/billing_id)');
    }

    $expectedTotal = (float) $internalPayment['checkout_total'];
    if (abs($expectedTotal - $paymongoAmountDec) > 0.01) {
        throw new Exception("Amount mismatch. Expected: $expectedTotal, Actual: $paymongoAmountDec");
    }

    // PayMongo event IDs are unique in Payment DB. The row lock remains the
    // fallback for resource-shaped deliveries which do not carry an event ID.
    if ($webhookEventId !== '') {
        $stmtEvent = $pdo->prepare(
            "INSERT IGNORE INTO paymongo_transactions
                (payment_id, checkout_session_id, payment_intent_id, webhook_event_id, event_type,
                 amount, convenience_fee, total_charged, signature_verified, processing_status)
             VALUES
                (:payment_id, :checkout_session_id, :payment_intent_id, :webhook_event_id, :event_type,
                 :amount, :convenience_fee, :total_charged, 1, 'Processing')"
        );
        $stmtEvent->execute([
            ':payment_id' => $internalPayment['payment_id'],
            ':checkout_session_id' => $internalPayment['checkout_session_id'] ?? null,
            ':payment_intent_id' => $internalPayment['payment_intent_id'] ?? null,
            ':webhook_event_id' => $webhookEventId,
            ':event_type' => $eventType,
            ':amount' => $internalPayment['amount'],
            ':convenience_fee' => $internalPayment['processing_fee'] ?? 0,
            ':total_charged' => $internalPayment['checkout_total'],
        ]);

        if ($stmtEvent->rowCount() !== 1) {
            $pdo->rollBack();
            echo json_encode(['success' => true, 'message' => 'Webhook event already processed']);
            exit;
        }
    }

    // Handle expiry/late-payment policy after authentication and validation.
    $paymentExpiresAt = !empty($internalPayment['expires_at'])
        ? $internalPayment['expires_at']
        : (!empty($internalPayment['created_at']) ? date('Y-m-d H:i:s', strtotime($internalPayment['created_at']) + 600) : null);
    if ($paymentExpiresAt !== null && time() > strtotime($paymentExpiresAt)) {
        error_log("[" . date('Y-m-d H:i:s') . "] Late Webhook Reconciliation: Payment ID {$internalPayment['payment_id']} confirmed by PayMongo after expiry time ({$paymentExpiresAt}). Reconciling as Paid.\n", 3, __DIR__ . '/webhook_error.log');
    }

    $stmtUpdate = $pdo->prepare("
        UPDATE payments 
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

    if ($webhookEventId !== '') {
        $stmtProcessed = $pdo->prepare(
            "UPDATE paymongo_transactions
             SET processing_status = 'Processed', processed_at = CURRENT_TIMESTAMP
             WHERE webhook_event_id = :webhook_event_id"
        );
        $stmtProcessed->execute([':webhook_event_id' => $webhookEventId]);
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Payment successfully verified and allocated']);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("[" . date('Y-m-d H:i:s') . "] Webhook Error: " . $e->getMessage() . "\n", 3, __DIR__ . '/webhook_error.log');
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Webhook processing failed']);
}
