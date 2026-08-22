<?php
/**
 * SMS 2 - Resume Payment API
 * 
 * Handles the "Resume Payment" button from the student payment history.
 * - Validates student ownership of the payment.
 * - Checks PayMongo session status.
 * - Redirects to active session or creates a new one if expired.
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../payment/config/paymongo.php';
require_once __DIR__ . '/../../payment/includes/PayMongoService.php';
require_once ROOT_PATH . '/includes/authentication.php';

// Force student login
requireAuth();
$role = getCurrentUserRoleKey();
if ($role !== 'student') {
    die("Unauthorized access.");
}

$studentUserId = $_SESSION['user_id'];
$paymentId = $_GET['id'] ?? null;

if (!$paymentId) {
    die("Invalid request. Missing payment ID.");
}

try {
    // 1. Establish connection to payment_db
    require_once __DIR__ . '/../../payment/database/db_connect.php';
    global $pdo;

    // 2. Fetch the payment and validate ownership in one query
    $stmt = $pdo->prepare("
        SELECT p.*, s.user_id 
        FROM payments p
        JOIN students s ON p.student_id = s.student_id
        WHERE p.payment_id = :payment_id
    ");
    $stmt->execute([':payment_id' => $paymentId]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$payment) {
        die("Payment record not found.");
    }

    // 3. Security: Validate ownership
    if ($payment['user_id'] != $studentUserId) {
        die("Unauthorized access. This payment does not belong to your account.");
    }

    // 4. Validate internal payment status
    if ($payment['payment_status'] !== 'Pending') {
        echo "<script>alert('This payment is no longer pending (Current Status: " . htmlspecialchars($payment['payment_status']) . ").'); window.location.href='../pages/payment-history.php';</script>";
        exit;
    }

    $checkoutSessionId = $payment['checkout_session_id'];
    if (!$checkoutSessionId) {
        die("Invalid payment record: Missing checkout session reference.");
    }

    // 5. Check PayMongo Session Status
    $payMongo = new PayMongoService();
    $sessionData = $payMongo->getCheckoutSession($checkoutSessionId);
    
    $payMongoStatus = $sessionData['data']['attributes']['status'] ?? 'unknown';
    $checkoutUrl = $sessionData['data']['attributes']['checkout_url'] ?? null;

    if ($payMongoStatus === 'active' && $checkoutUrl) {
        // Session is still active. Safely redirect back.
        header("Location: " . $checkoutUrl);
        exit;
    } else {
        // Session has expired or is no longer active. We need to create a new checkout attempt.
        // We will reuse the internal `payment_id` but update its `checkout_session_id`.
        
        $amount = (float)$payment['amount'];
        $description = "Resume Payment for Ref: " . $payment['reference_number'];
        $referenceNumber = $payment['reference_number'] . '_' . time(); // Append time to prevent idempotency collision on PayMongo side for the same reference if it strictly checks it.
        
        $successUrl = BASE_URL . "/modules/payment/pages/student/online-payment-success.php?ref=" . urlencode($referenceNumber);
        $cancelUrl = BASE_URL . "/modules/student-portal/pages/payment-history.php?cancel=1";
        
        // Reverse-map DB channel to internal code
        $reverseDbChannelMap = [
            'GCash' => 'gcash',
            'Maya' => 'maya',
            'Visa' => 'card',
            'PayMongo' => 'qrph' // Defaulting PayMongo to qrph
        ];
        $internalCode = $reverseDbChannelMap[$payment['payment_channel']] ?? 'gcash';

        // Enforce Server-Side Availability
        require_once __DIR__ . '/../../payment/includes/PaymentChannelService.php';
        $channelService = new PaymentChannelService($pdo);
        $env = $channelService->getActiveEnvironment();
        
        if (!$channelService->isChannelAvailable($payMongo, $env, $internalCode)) {
            echo "<script>alert('Sorry, the payment method you originally chose is currently disabled. Please start a new payment.'); window.location.href='../pages/payment-history.php';</script>";
            exit;
        }

        // Map to exact PayMongo identifier
        $payMongoTypeMap = [
            'gcash' => 'gcash',
            'maya'  => 'paymaya',
            'card'  => 'card',
            'qrph'  => 'qrph'
        ];
        $pmType = $payMongoTypeMap[$internalCode] ?? $internalCode;

        $newSession = $payMongo->createCheckoutSession(
            $amount, 
            $description, 
            $referenceNumber, 
            $successUrl, 
            $cancelUrl, 
            [$pmType]
        );

        $newCheckoutSessionId = $newSession['data']['id'];
        $newCheckoutUrl = $newSession['data']['attributes']['checkout_url'];

        // Update the internal payment record with the new session ID and reference
        $updateStmt = $pdo->prepare("
            UPDATE payments 
            SET checkout_session_id = :cs_id, reference_number = :ref
            WHERE payment_id = :id
        ");
        $updateStmt->execute([
            ':cs_id' => $newCheckoutSessionId,
            ':ref' => $referenceNumber,
            ':id' => $paymentId
        ]);

        // Redirect to the newly created checkout session
        header("Location: " . $newCheckoutUrl);
        exit;
    }

} catch (Exception $e) {
    die("Error processing resume payment: " . htmlspecialchars($e->getMessage()));
}
