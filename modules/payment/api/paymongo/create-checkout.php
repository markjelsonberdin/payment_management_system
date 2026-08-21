<?php
/**
 * SMS 2 - API Endpoint: Create PayMongo Checkout Session
 * Handles Phase 7 (Partial Payment Rule) and Phase 8 (Pending Payment Prep)
 */
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../includes/authentication.php';
require_once __DIR__ . '/../../database/db_connect.php';
require_once __DIR__ . '/../../includes/PayMongoService.php';
require_once __DIR__ . '/../../includes/PaymentValidationService.php';
require_once __DIR__ . '/../../includes/ConvenienceFeeService.php';

header('Content-Type: application/json');

// 1. Security Check
if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    http_response_code(401);
    exit;
}
// Optionally check if user is a student:
// if ($_SESSION['user_role'] !== 'Student') { ... }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    http_response_code(405);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$studentId = $input['student_id'] ?? null;
$billingId = $input['billing_id'] ?? null;
$categoryId = $input['category_id'] ?? null;
$amount = $input['amount'] ?? 0;
$channel = $input['channel'] ?? '';

if (!$studentId || !$billingId || !$amount || !$channel) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    http_response_code(400);
    exit;
}

try {
    $pdo->beginTransaction();

    // 2. Validate Payment Request (Phase 6 & 7)
    $validationService = new PaymentValidationService($pdo);
    $validation = $validationService->validatePaymentRequest($studentId, $billingId, $amount, $channel);

    if (!$validation['valid']) {
        throw new Exception($validation['error']);
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

    // Generate Unique Reference Number
    $referenceNumber = 'PM-' . time() . '-' . rand(1000, 9999);

    // 5. Create PayMongo Checkout Session FIRST to get the session ID
    $payMongo = new PayMongoService();
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
        [$channel], // Force only the selected channel
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
        (student_id, billing_id, category_id, transaction_type, payment_method, amount, processing_fee, checkout_total, payment_channel, reference_number, checkout_session_id, payment_status, payment_date)
        VALUES 
        (:student_id, :billing_id, :category_id, 'Online', 'Online', :amount, :processing_fee, :checkout_total, :payment_channel, :reference_number, :checkout_session_id, 'Pending', CURDATE())
    ");
    $stmtInsert->execute([
        ':student_id' => $studentId,
        ':billing_id' => $billingId,
        ':category_id' => $categoryId ?: null,
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

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
