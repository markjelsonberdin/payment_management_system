<?php
/**
 * SMS 2 - API Endpoint: Create PayMongo QR Ph Payment
 * Generates a transaction-specific QR Ph using Payment Intents.
 */
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../includes/authentication.php';
require_once __DIR__ . '/../../database/db_connect.php';
require_once __DIR__ . '/../../includes/PayMongoService.php';
require_once __DIR__ . '/../../includes/PaymentValidationService.php';
require_once __DIR__ . '/../../includes/ConvenienceFeeService.php';
require_once __DIR__ . '/../../includes/PaymentChannelService.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    http_response_code(401);
    exit;
}

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
$allocationContext = $input['allocation_context'] ?? 'ENROLLMENT_PRIORITY';
$billingItemId = $input['billing_item_id'] ?? null;

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
    $pdo->beginTransaction();

    // Validate Payment Request
    $validationService = new PaymentValidationService($pdo);
    $validation = $validationService->validatePaymentRequest($studentId, $billingId, $amount, $channel, $allocationContext, $billingItemId);

    if (!$validation['valid']) {
        throw new Exception($validation['error']);
    }

    $payMongo = new PayMongoService();
    $channelService = new PaymentChannelService($pdo);
    $env = $channelService->getActiveEnvironment();
    
    if (!$channelService->isChannelAvailable($payMongo, $env, $channel)) {
        throw new Exception("This payment method is currently unavailable.");
    }

    // Fee Calculation
    $stmtFee = $pdo->query("SELECT setting_value FROM payment_db.payment_gateway_settings WHERE setting_key = 'fee_policy'");
    $feePolicyRow = $stmtFee->fetch(PDO::FETCH_ASSOC);
    $feePolicy = $feePolicyRow ? $feePolicyRow['setting_value'] : 'absorb_by_school';

    $feeService = new ConvenienceFeeService();
    $feeData = $feeService->calculateFee((float)$amount, $channel, $feePolicy);
    
    $checkoutTotal = $feeData['checkout_total'];
    $referenceNumber = 'PM-' . time() . '-' . rand(1000, 9999);
    $description = "Payment for Billing ID #$billingId";

    // 1. Create Payment Intent
    $intentRes = $payMongo->createPaymentIntent($checkoutTotal, $description, [
        'reference_number' => $referenceNumber
    ]);

    $paymentIntentId = $intentRes['data']['id'] ?? null;
    $clientKey = $intentRes['data']['attributes']['client_key'] ?? null;

    if (!$paymentIntentId || !$clientKey) {
        throw new Exception("Failed to generate Payment Intent from PayMongo.");
    }

    // 2. Create QR Ph Payment Method
    $methodRes = $payMongo->createQrPaymentMethod();
    $paymentMethodId = $methodRes['data']['id'] ?? null;

    if (!$paymentMethodId) {
        throw new Exception("Failed to create QR Ph Payment Method.");
    }

    // 3. Attach Payment Method to Payment Intent
    $attachRes = $payMongo->attachPaymentIntent($paymentIntentId, $paymentMethodId, $clientKey);
    $qrImage = $attachRes['data']['attributes']['next_action']['code']['image_url'] ?? null;

    if (!$qrImage) {
        throw new Exception("Failed to retrieve QR image from PayMongo attachment response.");
    }

    // 4. Create Pending Payment Record
    $stmtInsert = $pdo->prepare("
        INSERT INTO payment_db.payments 
        (student_id, billing_id, category_id, allocation_context, billing_item_id, transaction_type, payment_method, amount, processing_fee, checkout_total, payment_channel, reference_number, payment_intent_id, payment_status, payment_date)
        VALUES 
        (:student_id, :billing_id, :category_id, :allocation_context, :billing_item_id, 'Online', 'Online', :amount, :processing_fee, :checkout_total, 'QRPh', :reference_number, :payment_intent_id, 'Pending', CURDATE())
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
        ':reference_number' => $referenceNumber,
        ':payment_intent_id' => $paymentIntentId
    ]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'qr_image' => $qrImage,
        'payment_intent_id' => $paymentIntentId,
        'reference_number' => $referenceNumber,
        'amount' => $checkoutTotal,
        'fee_data' => $feeData,
        'status' => 'pending'
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("QR Creation Error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'QR_PAYMENT_CREATION_FAILED',
        'message' => $e->getMessage()
    ]);
}
