<?php
/**
 * SMS 2 - API Endpoint: Check Payment Status
 * Fast polling endpoint to check the internal status of a pending payment.
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/authentication.php';
require_once __DIR__ . '/../../payment/database/db_connect.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    http_response_code(401);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    http_response_code(405);
    exit;
}

$paymentIntentId = $_GET['payment_intent_id'] ?? '';
$referenceNumber = $_GET['reference_number'] ?? '';

if (!$paymentIntentId && !$referenceNumber) {
    echo json_encode(['success' => false, 'error' => 'Missing identifier']);
    http_response_code(400);
    exit;
}

try {
    // Only search payments belonging to the currently authenticated user
    $studentId = $_SESSION['user_id']; 

    if ($paymentIntentId) {
        $stmt = $pdo->prepare("SELECT payment_status FROM payment_db.payments WHERE payment_intent_id = :id AND student_id = :student_id LIMIT 1");
        $stmt->execute([':id' => $paymentIntentId, ':student_id' => $studentId]);
    } else {
        $stmt = $pdo->prepare("SELECT payment_status FROM payment_db.payments WHERE reference_number = :ref AND student_id = :student_id LIMIT 1");
        $stmt->execute([':ref' => $referenceNumber, ':student_id' => $studentId]);
    }

    $payment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$payment) {
        echo json_encode(['success' => false, 'error' => 'Payment not found']);
        http_response_code(404);
        exit;
    }

    if ($payment['payment_status'] === 'Pending' && !empty($payment['checkout_session_id'])) {
        // Fallback: Check PayMongo API directly (Useful for localhost testing without webhooks)
        require_once ROOT_PATH . '/modules/payment/includes/paymongo/paymongo/PayMongoService.php';
        try {
            $paymongo = new PayMongoService();
            $session = $paymongo->getCheckoutSession($payment['checkout_session_id']);
            
            $pmPayments = $session['data']['attributes']['payments'] ?? [];
            $isPaid = false;
            foreach ($pmPayments as $pmPayment) {
                if (($pmPayment['attributes']['status'] ?? '') === 'paid') {
                    $isPaid = true;
                    break;
                }
            }
            
            if ($isPaid) {
                $pdo->beginTransaction();
                $stmtUpdate = $pdo->prepare("UPDATE payment_db.payments SET payment_status = 'Verified', verified_at = CURRENT_TIMESTAMP WHERE payment_id = :pid AND payment_status = 'Pending'");
                $stmtUpdate->execute([':pid' => $payment['payment_id']]);
                
                if ($stmtUpdate->rowCount() > 0) {
                    require_once ROOT_PATH . '/modules/payment/includes/PaymentAllocationService.php';
                    $allocationService = new PaymentAllocationService($pdo);
                    $allocationService->allocatePayment(
                        $payment['payment_id'],
                        $payment['student_id'],
                        $payment['billing_id'],
                        (float) $payment['amount'],
                        $payment['allocation_context'],
                        $payment['billing_item_id']
                    );
                    $payment['payment_status'] = 'Verified';
                }
                $pdo->commit();
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("PayMongo Fallback Check Error: " . $e->getMessage());
        }
    }

    echo json_encode([
        'success' => true,
        'status' => $payment['payment_status'] // 'Pending', 'Verified', 'Failed', 'Rejected', etc.
    ]);

} catch (Exception $e) {
    error_log("Check Status Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}

