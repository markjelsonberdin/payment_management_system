<?php
/**
 * SMS 2 - PayMongo Webhook Endpoint
 * 
 * Handles incoming webhooks from PayMongo (e.g., checkout_session.payment.paid)
 * and processes the automated payment verification and allocation securely.
 */

require_once __DIR__ . '/../../../config/config.php';
require_once ROOT_PATH . '/modules/payment/database/db_connect.php';
require_once ROOT_PATH . '/modules/payment/includes/PayMongoWebhookSecurityService.php';
require_once ROOT_PATH . '/modules/payment/includes/PaymentAllocationService.php';

// We don't want PHP to display errors back to the caller in HTML format.
// Log them instead so PayMongo gets a clean HTTP response.
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Invalid request method");
    }

    // 1. Read raw body + headers
    $rawPayload = file_get_contents('php://input');
    $signatureHeader = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '';

    // 2. Determine environment based on our DB settings
    $stmtMode = $pdo->query("SELECT setting_value FROM payment_db.payment_gateway_settings WHERE setting_key = 'gateway_mode'");
    $activeMode = $stmtMode->fetchColumn() ?: 'test';

    // 3. Verify HMAC signature & Validate timestamp / replay window
    // The security service automatically pulls the correct secret key and verifies 'te' or 'li' based on activeMode
    $securityService = new PayMongoWebhookSecurityService($pdo, $activeMode);
    $securityService->verifySignature($signatureHeader, $rawPayload);

    // 4. Parse JSON
    $payload = json_decode($rawPayload, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON payload");
    }

    // 5. Validate event = checkout_session.payment.paid
    $eventType = $payload['data']['attributes']['type'] ?? '';
    if ($eventType !== 'checkout_session.payment.paid') {
        // Ignored event, but still return 200 OK so PayMongo knows we received it
        echo json_encode(['success' => true, 'message' => 'Event ignored']);
        exit;
    }

    $eventData = $payload['data']['attributes']['data'] ?? [];
    $checkoutSessionId = $eventData['id'] ?? '';
    $referenceNumber = $eventData['attributes']['reference_number'] ?? '';
    $paymongoCheckoutAmount = $eventData['attributes']['line_items'][0]['amount'] ?? 0;
    
    // PayMongo amount is in cents
    $paymongoCheckoutAmountDec = $paymongoCheckoutAmount / 100;

    // 6. Validate environment (Test mode should not process live payloads, and vice versa)
    $payloadEnv = $eventData['attributes']['livemode'] ? 'live' : 'test';
    if ($payloadEnv !== $activeMode) {
        // Log anomaly but don't fail, maybe just ignore
        throw new Exception("Environment mismatch: Webhook is $payloadEnv but system is $activeMode");
    }

    if (empty($checkoutSessionId)) {
        throw new Exception("Missing checkout_session_id in payload");
    }

    // 7. Find internal payment by checkout_session_id
    $stmt = $pdo->prepare("
        SELECT payment_id, student_id, billing_id, category_id, amount, processing_fee, checkout_total, payment_status 
        FROM payment_db.payments 
        WHERE checkout_session_id = :session_id
    ");
    $stmt->execute([':session_id' => $checkoutSessionId]);
    $internalPayment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$internalPayment) {
        throw new Exception("No internal payment record found for checkout_session_id: $checkoutSessionId");
    }

    // 8. Idempotency/state check
    if ($internalPayment['payment_status'] === 'Verified') {
        // Already processed, return 200 OK no-op
        echo json_encode(['success' => true, 'message' => 'Already verified']);
        exit;
    }

    if ($internalPayment['payment_status'] !== 'Pending') {
        throw new Exception("Payment record is in an unexpected state: " . $internalPayment['payment_status']);
    }

    // 9. Validate expected checkout_total against actual PayMongo amount
    $expectedTotal = (float) $internalPayment['checkout_total'];
    if (abs($expectedTotal - $paymongoCheckoutAmountDec) > 0.01) {
        throw new Exception("Amount mismatch. Expected: $expectedTotal, Actual: $paymongoCheckoutAmountDec");
    }

    // 10. BEGIN DB TRANSACTION
    $pdo->beginTransaction();

    // 11. Mark payment Verified
    $stmtUpdate = $pdo->prepare("
        UPDATE payment_db.payments 
        SET payment_status = 'Verified', updated_at = CURRENT_TIMESTAMP 
        WHERE payment_id = :pid
    ");
    $stmtUpdate->execute([':pid' => $internalPayment['payment_id']]);

    // 12. PaymentAllocationService
    $allocationService = new PaymentAllocationService($pdo);
    
    $studentId = $internalPayment['student_id'];
    $billingId = $internalPayment['billing_id'];
    $categoryId = $internalPayment['category_id'];
    $amountApplied = (float) $internalPayment['amount']; // Only allocate the tuition applied

    $context = $categoryId ? 'DesignatedCategory' : 'Enrollment';

    // 13. Update billing/billing_items (Handled inside PaymentAllocationService)
    $allocationService->allocatePayment(
        $internalPayment['payment_id'],
        $studentId,
        $billingId,
        $amountApplied,
        $context,
        $categoryId
    );

    // 14. Update ledger/history (Assuming it's handled by PaymentAllocationService or we can add it later if separate)
    // The allocation service handles billing_items and parent billing balance recalculation.
    // If a separate Ledger table exists, we would insert here.

    // 15. COMMIT
    $pdo->commit();

    // HTTP 200 OK
    echo json_encode(['success' => true, 'message' => 'Payment successfully verified and allocated']);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    // Log error to a file for debugging
    error_log("[" . date('Y-m-d H:i:s') . "] Webhook Error: " . $e->getMessage() . "\n", 3, __DIR__ . '/webhook_error.log');
    
    http_response_code(400); // Bad Request to signal PayMongo that something failed
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}