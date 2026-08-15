<?php
/**
 * PayMongo Webhook Endpoint (Phase 6)
 * 
 * Receives asynchronous payment event notifications from PayMongo.
 */

require_once __DIR__ . '/../../includes/PayMongoWebhookSecurityService.php';
require_once __DIR__ . '/../../includes/OnlinePaymentVerificationService.php';
require_once __DIR__ . '/../../includes/PaymentAllocationService.php';

// FIX A: Gumamit ng centralized database connection
// Siguraduhing tama ang path ng iyong db_connect.php file dito.
// Assuming na ang db_connect.php mo ay nag-i-initialize ng $pdo variable.
try {
    // Kung wala ka pang db_connect.php, i-uncomment mo pansamantala yung lumang PDO mo dito
    // pero mas maganda kung i-require mo na lang 'yung config mo.
    require_once __DIR__ . '/../database/db_connect.php'; 
    
    // Kung ang db_connect.php mo ay walang error mode, siguraduhing naka-set ito:
    if (isset($pdo)) {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } else {
        throw new PDOException("Database connection not established.");
    }
} catch (PDOException $e) {
    http_response_code(500);
    exit('Database connection failed: ' . $e->getMessage());
}

$rawPayload = file_get_contents('php://input');
$signatureHeader = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '';

if (!$rawPayload) {
    http_response_code(400);
    exit('No payload');
}

try {
    $securityService = new PayMongoWebhookSecurityService($pdo);
    
    // 1. Verify Signature (Phase 7)
    $securityService->verifySignature($signatureHeader, $rawPayload);

    $event = json_decode($rawPayload, true);

    if (!isset($event['data']['type']) || $event['data']['type'] !== 'event') {
        throw new Exception("Invalid event payload type.");
    }

    $eventType = $event['data']['attributes']['type'];

    // 2. Event Type Validation
    if ($eventType === 'checkout_session.payment.paid') {
        $checkoutSessionData = $event['data']['attributes']['data'];
        $sessionId = $checkoutSessionData['id'];
        
        // Find internal payment by sessionId
        $stmt = $pdo->prepare("SELECT payment_id, student_id, billing_id, amount FROM payments WHERE reference_number = :ref");
        $stmt->execute([':ref' => $sessionId]);
        $internalPayment = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($internalPayment) {
            $paymentId = $internalPayment['payment_id'];
            
            // 3. Duplicate/Idempotency Validation (Phase 7)
            if ($securityService->isDuplicate($paymentId)) {
                error_log("Webhook Error: Duplicate or already processed payment for Session ID: " . $sessionId);
            } else {
                // 4. Verification of Amount against Internal Records (Phase 8)
                $verificationService = new OnlinePaymentVerificationService($pdo);
                
                // FIX B: Naka-apply na 'yung error throwing mo dito imbes na fallback amount. Solid!
                $paymongoAmountPaid = $checkoutSessionData['attributes']['payments'][0]['attributes']['amount'] ?? null;
                if ($paymongoAmountPaid === null) {
                    throw new Exception("Amount missing from PayMongo payload.");
                }

                $verificationResult = $verificationService->verifyPayment($sessionId, $paymongoAmountPaid);
                
                error_log("Phase 8 Verified! Internal Payment ID: " . $paymentId . " is authentic and exact amount matches.");
                
                // 5. Database Execution & Allocation (Phase 9)
                $remarksData = json_decode($verificationResult['remarks'], true);
                $categoryId = $remarksData['category_id'] ?? null;
                $studentId = $internalPayment['student_id'];
                $billingId = $internalPayment['billing_id'];
                $amountPaid = (float) $internalPayment['amount'];

                if (!$categoryId) {
                    throw new Exception("Missing category_id context in internal payment.");
                }

                // FIX C: Binalot na natin sa iisang Parent Transaction ang Status Update at Allocation!
                try {
                    $pdo->beginTransaction();

                    // Update internal payment status to Verified
                    $updateStmt = $pdo->prepare("
                        UPDATE payments 
                        SET payment_status = 'Verified', verified_at = CURRENT_TIMESTAMP 
                        WHERE payment_id = :pid
                    ");
                    $updateStmt->execute([':pid' => $paymentId]);

                    // Execute Allocation Engine
                    $allocationService = new PaymentAllocationService($pdo);
                    $allocationService->allocatePayment($paymentId, $studentId, $billingId, $amountPaid, 'DesignatedCategory', $categoryId);
                    
                    // Kapag walang pumalya sa update at allocation, i-commit natin!
                    $pdo->commit();
                    error_log("Phase 9 Success! Payment Allocation completed for Payment ID: " . $paymentId);

                } catch (Exception $e) {
                    // Kapag may pumalya (halimbawa nagka-error sa allocation logic), i-rollback pati yung 'Verified' status
                    $pdo->rollBack();
                    throw new Exception("Allocation Failed: " . $e->getMessage());
                }
            }
        } else {
            error_log("Webhook Error: No matching internal payment found for Session ID: " . $sessionId);
        }
    }

    http_response_code(200);
    echo "Webhook received and verified";

} catch (Exception $e) {
    error_log("Webhook Security Failed: " . $e->getMessage());
    http_response_code(400);
    echo "Webhook Security Failed: " . $e->getMessage();
}