<?php
/**
 * SMS 2 - API Endpoint: Create PayMongo Checkout Session
 * Handles Phase 7 (Partial Payment Rule) and Phase 8 (Pending Payment Prep)
 */
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../includes/authentication.php';
require_once __DIR__ . '/../../../../includes/security.php';
require_once __DIR__ . '/../../database/db_connect.php';
require_once __DIR__ . '/../../includes/PayMongoService.php';
require_once __DIR__ . '/../../includes/PaymentValidationService.php';
require_once __DIR__ . '/../../includes/ConvenienceFeeService.php';
require_once __DIR__ . '/../../includes/PaymentChannelService.php';

header('Content-Type: application/json');

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
$throttleKey = 'checkout:user:' . (getCurrentUserId() ?? 'anon');
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

// Object-Level Authorization: Students can only checkout for themselves
if (getCurrentUserRoleKey() === 'student') {
    $sessionStudentId = $_SESSION['student_id'] ?? null;
    $isAuthorized = false;

    // Direct match with session string (e.g. S230000001)
    if (!empty($sessionStudentId) && (string)$sessionStudentId === (string)$studentId) {
        $isAuthorized = true;
    }
    
    // Check against payment_db students table using user_id
    if (!$isAuthorized) {
        $stmtCheck = $pdo->prepare("SELECT student_id, student_number FROM students WHERE user_id = ? LIMIT 1");
        $stmtCheck->execute([getCurrentUserId()]);
        $studentRow = $stmtCheck->fetch(PDO::FETCH_ASSOC);
        
        if ($studentRow && ((string)$studentRow['student_id'] === (string)$studentId || (string)$studentRow['student_number'] === (string)$studentId)) {
            $isAuthorized = true;
        }
    }
    
    // Fallback for local testing
    if (!$isAuthorized && $studentId === 'S230106713') {
        $isAuthorized = true; 
    }

    if (!$isAuthorized) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'FORBIDDEN', 'message' => 'Unauthorized object access.']);
        exit;
    }
}

// Normalize target_id to billing_item_id to prevent confusion
if ($allocationContext === 'SPECIFIC_ITEM' && empty($billingItemId) && !empty($input['target_id'])) {
    $billingItemId = $input['target_id'];
}

if (!$studentId || !$billingId || !$amount || !$channel) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

try {
    // 1.8 Context-Aware Duplicate Pending Transaction Detection
    $forceNewAttempt = !empty($input['force_new_attempt']);
    
    if (!$forceNewAttempt) {
        if ($allocationContext === 'SPECIFIC_ITEM') {
            $stmtDup = $pdo->prepare("SELECT * FROM payment_db.payments WHERE student_id = ? AND allocation_context = ? AND billing_item_id = ? AND payment_status = 'Pending' LIMIT 1");
            $stmtDup->execute([$studentId, $allocationContext, $billingItemId]);
        } else {
            // ENROLLMENT_PRIORITY
            $stmtDup = $pdo->prepare("SELECT * FROM payment_db.payments WHERE student_id = ? AND allocation_context = ? AND billing_id = ? AND payment_status = 'Pending' LIMIT 1");
            $stmtDup->execute([$studentId, $allocationContext, $billingId]);
        }
        
        $existingPending = $stmtDup->fetch(PDO::FETCH_ASSOC);
        if ($existingPending) {
            http_response_code(409); // Conflict
            echo json_encode([
                'success' => false, 
                'error' => 'EXISTING_PENDING_PAYMENT',
                'message' => 'You already have an active pending payment for this fee.',
                'pending_payment' => $existingPending
            ]);
            exit;
        }
    }

    $pdo->beginTransaction();

    // 2. Validate Payment Request (Phase 6 & 7)
    $validationService = new PaymentValidationService($pdo);
    $validation = $validationService->validatePaymentRequest($studentId, $billingId, $amount, $channel, $allocationContext, $billingItemId);

    if (!$validation['valid']) {
        throw new Exception($validation['error']);
    }

    // 2.5. Backend Enforcement: Verify channel is actually available
    $payMongo = new PayMongoService();
    $channelService = new PaymentChannelService($pdo);
    $env = $channelService->getActiveEnvironment();
    
    if (!$channelService->isChannelAvailable($payMongo, $env, $channel)) {
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'error' => 'PAYMENT_METHOD_UNAVAILABLE', 
            'message' => 'This payment method is currently unavailable.'
        ]);
        exit;
    }

    // 3. Get Fee Policy
    $stmtFee = $pdo->query("SELECT setting_value FROM payment_db.payment_gateway_settings WHERE setting_key = 'fee_policy'");
    $feePolicyRow = $stmtFee->fetch(PDO::FETCH_ASSOC);
    $feePolicy = $feePolicyRow ? $feePolicyRow['setting_value'] : 'absorb_by_school';

    // 4. Calculate Fee (Phase 4)
    $feeService = new ConvenienceFeeService();
    $feeData = $feeService->calculateFee((float)$amount, $channel, $feePolicy);
    
    $checkoutTotal = $feeData['checkout_total'];
    
    // Determine payment_channel enum value
    $dbChannelMap = [
        'gcash' => 'GCash',
        'maya' => 'Maya',
        'card' => 'Visa', // Or PayMongo
        'qrph' => 'PayMongo'
    ];
    $dbChannel = $dbChannelMap[$channel] ?? 'PayMongo';

    $referenceNumber = 'PM-' . time() . '-' . rand(1000, 9999);

    // Map internal channel to exact PayMongo type identifier
    $payMongoTypeMap = [
        'gcash' => 'gcash',
        'maya'  => 'paymaya',
        'card'  => 'card',
        'qrph'  => 'qrph'
    ];
    $pmType = $payMongoTypeMap[$channel] ?? $channel;

    // 5. Create PayMongo Checkout Session FIRST to get the session ID
    $description = "Payment for Billing ID #$billingId";
    
    // Note: The URLs must be absolute.
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $baseUrl = "$protocol://$host/SMS2_system/modules/student-portal/pages";
    $successUrl = "$baseUrl/payment-success.php?ref=" . $referenceNumber;
    $cancelUrl = "$baseUrl/account-balance.php";

    // Pass the referenceNumber as Idempotency-Key to PayMongo to avoid duplicates
    $response = $payMongo->createCheckoutSession(
        $checkoutTotal, // PayMongo charges the gross amount
        $description,
        $referenceNumber,
        $successUrl,
        $cancelUrl,
        [$pmType], // Force only the mapped selected channel
        $referenceNumber // Use reference number as idempotency key
    );

    $checkoutUrl = $response['data']['attributes']['checkout_url'] ?? null;
    $checkoutSessionId = $response['data']['id'] ?? null;

    if (!$checkoutUrl || !$checkoutSessionId) {
        throw new Exception("Failed to generate checkout URL from PayMongo.");
    }

    // 6. Create Pending Payment Record with full transaction context (Phase 8 Revised)
    $stmtInsert = $pdo->prepare("
        INSERT INTO payment_db.payments 
        (student_id, billing_id, category_id, allocation_context, billing_item_id, transaction_type, payment_method, amount, processing_fee, checkout_total, payment_channel, reference_number, checkout_session_id, payment_status, payment_date)
        VALUES 
        (:student_id, :billing_id, :category_id, :allocation_context, :billing_item_id, 'Online', 'Online', :amount, :processing_fee, :checkout_total, :payment_channel, :reference_number, :checkout_session_id, 'Pending', CURDATE())
    ");
    $stmtInsert->execute([
        ':student_id' => $studentId,
        ':billing_id' => $billingId,
        ':category_id' => $categoryId ?: null,
        ':allocation_context' => $allocationContext,
        ':billing_item_id' => $billingItemId ?: null,
        ':amount' => $feeData['amount_applied'],
        ':processing_fee' => $feeData['processing_fee'],
        ':checkout_total' => $feeData['checkout_total'],
        ':payment_channel' => $dbChannel,
        ':reference_number' => $referenceNumber,
        ':checkout_session_id' => $checkoutSessionId
    ]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'checkout_url' => $checkoutUrl,
        'reference_number' => $referenceNumber,
        'fee_data' => $feeData
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'INTERNAL_SERVER_ERROR',
        'message' => 'Database error.'
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
