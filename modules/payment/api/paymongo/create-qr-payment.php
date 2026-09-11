<?php
/**
 * SMS 2 - API Endpoint: Create PayMongo QR Ph Payment
 * Generates a transaction-specific QR Ph using Payment Intents.
 */
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../includes/authentication.php';
require_once __DIR__ . '/../../../../includes/security.php';
require_once __DIR__ . '/../../database/db_connect.php';
require_once __DIR__ . '/../../includes/paymongo/PayMongoService.php';
require_once __DIR__ . '/../../includes/PaymentValidationService.php';
require_once __DIR__ . '/../../includes/ConvenienceFeeService.php';
require_once __DIR__ . '/../../includes/PaymentChannelService.php';

header('Content-Type: application/json');

$qrExpirySeconds = 10 * 60;
$qrExpiryMinutes = 10;

$providerStage = 'bootstrap';
try {

// 1. Security Check
if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'AUTHENTICATION_REQUIRED']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

// 1.5 CSRF Validation
requireCsrfJson($input);

// 1.6 Rate Limiting (5 requests per minute)
require_once __DIR__ . '/../../includes/PaymentRateLimiter.php';
$throttleKey = 'qr_checkout:user:' . (getCurrentUserId() ?? 'anon');
if (!PaymentRateLimiter::throttle($throttleKey, 5, 60)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'TOO_MANY_REQUESTS', 'message' => 'Too many requests. Please try again later.']);
    exit;
}

$studentId = $input['student_id'] ?? null;
$billingId = $input['billing_id'] ?? null;
$categoryId = $input['category_id'] ?? null;
$amount = $input['amount'] ?? 0;
$channel = $input['channel'] ?? '';
$allocationContext = $input['allocation_context'] ?? 'ENROLLMENT_PRIORITY';
$billingItemId = $input['billing_item_id'] ?? null;

// Resolve the external student number or numeric ID to the authoritative
// payment-db students.student_id before validation and payment creation.
$submittedStudentId = $studentId;
if ($submittedStudentId !== null && $submittedStudentId !== '') {
    $stmtStudent = $pdo->prepare(
        "SELECT student_id, student_number
         FROM students
         WHERE student_id = :numeric_id
            OR LOWER(student_number) = LOWER(:student_number)
         LIMIT 1"
    );
    $stmtStudent->execute([
        ':numeric_id' => (string) $submittedStudentId,
        ':student_number' => (string) $submittedStudentId,
    ]);
    $resolvedStudent = $stmtStudent->fetch(PDO::FETCH_ASSOC);
    if ($resolvedStudent) {
        $studentId = (int) $resolvedStudent['student_id'];
    }
}

// Object-Level Authorization: Students can only checkout for themselves
if (getCurrentUserRoleKey() === 'student') {
    $sessionStudentId = $_SESSION['student_id'] ?? null;
    $isAuthorized = false;

    $resolvedStudentNumber = $resolvedStudent['student_number'] ?? null;

    // Match the canonical student number from the payment database.
    if (!empty($sessionStudentId) && (
        strcasecmp((string) $sessionStudentId, (string) $resolvedStudentNumber) === 0
        || (string) $sessionStudentId === (string) $studentId
    )) {
        $isAuthorized = true;
    }
    
    // Older payment schemas also map users through students.user_id.
    if (!$isAuthorized) {
        $hasUserIdColumn = (bool) $pdo->query("SHOW COLUMNS FROM students LIKE 'user_id'")->fetch(PDO::FETCH_ASSOC);
        if ($hasUserIdColumn) {
            $stmtCheck = $pdo->prepare("SELECT student_id, student_number FROM students WHERE user_id = ? LIMIT 1");
            $stmtCheck->execute([getCurrentUserId()]);
            $studentRow = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if ($studentRow && ((string)$studentRow['student_id'] === (string)$studentId || strcasecmp((string)$studentRow['student_number'], (string)$resolvedStudentNumber) === 0)) {
                $isAuthorized = true;
            }
        }
    }
    
    if (!$isAuthorized) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'FORBIDDEN', 'message' => 'You can only process payments for your own account.']);
        exit;
    }
}

// Normalize target_id
if ($allocationContext === 'SPECIFIC_ITEM' && empty($billingItemId) && !empty($input['target_id'])) {
    $billingItemId = $input['target_id'];
}

if (!$studentId || !$billingId || !$amount || $channel !== 'qrph') {
    echo json_encode(['success' => false, 'error' => 'Missing required fields or invalid channel']);
    http_response_code(400);
    exit;
}

try {
    $providerStage = 'validation';
    $hasExpiryColumn = (bool) $pdo->query("SHOW COLUMNS FROM payments LIKE 'expires_at'")->fetch(PDO::FETCH_ASSOC);
    $pdo->beginTransaction();
    $dbLocked = true;

    // 1. Validate Payment Request
    $validationService = new PaymentValidationService($pdo);
    $validation = $validationService->validatePaymentRequest($studentId, $billingId, $amount, $channel, $allocationContext, $billingItemId);

    if (!$validation['valid']) {
        throw new Exception($validation['error']);
    }

    // 2. DB Level Concurrency Lock (Stable parent record = students table)
    $stmtLock = $pdo->prepare("SELECT student_id FROM students WHERE student_id = ? FOR UPDATE");
    $stmtLock->execute([$studentId]);

    // 3. Check for conflicting pending attempts
    $pendingExpiryFilter = $hasExpiryColumn
        ? 'AND expires_at > NOW()'
        : "AND created_at > DATE_SUB(NOW(), INTERVAL {$qrExpiryMinutes} MINUTE)";
    $stmtPending = $pdo->prepare("SELECT payment_id FROM payments
        WHERE student_id = ? AND billing_id = ?
        AND payment_status = 'Pending'
        AND payment_channel = 'QRPh'
        {$pendingExpiryFilter}");
    $stmtPending->execute([$studentId, $billingId]);
    if ($stmtPending->fetch()) {
        throw new Exception("You already have an active pending QR payment for this billing. Please complete it or wait for it to expire.");
    }

    $providerStage = 'configuration';
    $payMongo = new PayMongoService();
    $channelService = new PaymentChannelService($pdo);
    $env = $channelService->getActiveEnvironment();
    
    if (!$channelService->isChannelAvailable($payMongo, $env, $channel)) {
        throw new Exception("This payment method is currently unavailable.");
    }

    // Fee Calculation
    $stmtFee = $pdo->query("SELECT setting_value FROM payment_gateway_settings WHERE setting_key = 'fee_policy'");
    $feePolicyRow = $stmtFee->fetch(PDO::FETCH_ASSOC);
    $feePolicy = $feePolicyRow ? $feePolicyRow['setting_value'] : 'absorb_by_school';

    $feeService = new ConvenienceFeeService();
    $feeData = $feeService->calculateFee((float)$amount, $channel, $feePolicy);
    
    $checkoutTotal = $feeData['checkout_total'];
    $referenceNumber = 'PM-' . time() . '-' . rand(1000, 9999);
    $description = "Payment for Billing ID #$billingId";

    // 4. Persist Placeholder Payment Attempt (Draft)
    $expiryColumn = $hasExpiryColumn ? ', expires_at' : '';
    $expiryValue = $hasExpiryColumn ? ", DATE_ADD(NOW(), INTERVAL {$qrExpiryMinutes} MINUTE)" : '';
    $stmtInsert = $pdo->prepare("INSERT INTO payments
        (student_id, billing_id, category_id, allocation_context, billing_item_id, transaction_type, payment_method, amount, processing_fee, checkout_total, payment_channel, reference_number, payment_status, payment_date{$expiryColumn})
        VALUES
        (:student_id, :billing_id, :category_id, :allocation_context, :billing_item_id, 'Online', 'Online', :amount, :processing_fee, :checkout_total, 'QRPh', :reference_number, 'Pending', CURDATE(){$expiryValue})");
    
    $stmtInsert->execute([
        ':student_id' => $studentId,
        ':billing_id' => $billingId,
        ':category_id' => $categoryId ?: null,
        ':allocation_context' => $allocationContext,
        ':billing_item_id' => $billingItemId ?: null,
        ':amount' => $feeData['amount_applied'],
        ':processing_fee' => $feeData['processing_fee'],
        ':checkout_total' => $feeData['checkout_total'],
        ':reference_number' => $referenceNumber
    ]);
    
    $paymentId = $pdo->lastInsertId();

    // Release unnecessary lock/transaction scope before calling PayMongo API
    $pdo->commit();
    $dbLocked = false;

    // 5. Call PayMongo API
    $providerStage = 'create_payment_intent';
    $intentRes = $payMongo->createPaymentIntent($checkoutTotal, $description, [
        'reference_number' => $referenceNumber
    ]);

    $paymentIntentId = $intentRes['data']['id'] ?? null;
    $clientKey = $intentRes['data']['attributes']['client_key'] ?? null;

    if (!$paymentIntentId || !$clientKey) {
        throw new Exception("Failed to generate Payment Intent from PayMongo.");
    }

    $providerStage = 'create_qr_payment_method';
    $methodRes = $payMongo->createQrPaymentMethod($qrExpirySeconds);
    $paymentMethodId = $methodRes['data']['id'] ?? null;

    if (!$paymentMethodId) {
        throw new Exception("Failed to create QR Ph Payment Method.");
    }

    $providerStage = 'attach_payment_method';
    $attachRes = $payMongo->attachPaymentIntent($paymentIntentId, $paymentMethodId, $clientKey);
    $qrImage = $attachRes['data']['attributes']['next_action']['code']['image_url'] ?? null;

    if (!$qrImage) {
        throw new Exception("Failed to retrieve QR image from PayMongo attachment response.");
    }

    // 6. Update Payment Attempt with PayMongo Intent ID
    $providerStage = 'save_payment_intent';
    $stmtUpdate = $pdo->prepare("UPDATE payments SET payment_intent_id = :payment_intent_id WHERE payment_id = :payment_id");
    $stmtUpdate->execute([
        ':payment_intent_id' => $paymentIntentId,
        ':payment_id' => $paymentId
    ]);

    if ($hasExpiryColumn) {
        $stmtExpiry = $pdo->prepare('SELECT expires_at FROM payments WHERE payment_id = :payment_id');
        $stmtExpiry->execute([':payment_id' => $paymentId]);
        $expiresAt = $stmtExpiry->fetchColumn();
    } else {
        $expiresAt = date('Y-m-d H:i:s', time() + $qrExpirySeconds);
    }

    echo json_encode([
        'success' => true,
        'qr_image' => $qrImage,
        'payment_intent_id' => $paymentIntentId,
        'reference_number' => $referenceNumber,
        'amount' => $checkoutTotal,
        'expires_at' => $expiresAt,
        'fee_data' => $feeData,
        'status' => 'pending'
    ]);
} catch (Throwable $e) {
    if (isset($dbLocked) && $dbLocked && $pdo->inTransaction()) {
        $pdo->rollBack();
    } elseif (isset($paymentId)) {
        // If PayMongo or subsequent steps failed, safely mark the placeholder as Failed
        try {
            $stmtFail = $pdo->prepare("UPDATE payments SET payment_status = 'Failed', remarks = :remarks WHERE payment_id = :payment_id");
            $stmtFail->execute([
                ':remarks' => 'Error: ' . substr($e->getMessage(), 0, 200),
                ':payment_id' => $paymentId
            ]);
        } catch (Throwable $innerE) {
            error_log("Secondary error while marking payment as failed: " . $innerE->getMessage());
        }
    }
    error_log("QR Creation Error [$providerStage]: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'QR_PAYMENT_CREATION_FAILED',
        'message' => 'Unable to create the QR payment right now. Please try again.',
        'stage' => $providerStage,
        'provider_message' => preg_replace('/\s+/', ' ', substr($e->getMessage(), 0, 240))
    ]);
}
} catch (Throwable $e) {
    error_log('QR Bootstrap Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'QR_PAYMENT_BOOTSTRAP_FAILED',
        'message' => 'QR payment endpoint failed before payment initialization.',
        'stage' => $providerStage,
        'provider_message' => preg_replace('/\s+/', ' ', substr($e->getMessage(), 0, 240))
    ]);
}
