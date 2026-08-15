<?php
/**
 * API Endpoint to Create a PayMongo Checkout Session
 * Expected POST JSON: { "student_id": 1, "billing_id": 1, "category_id": 2, "amount": 1500 }
 */

require_once __DIR__ . '/../../includes/PayMongoService.php';
require_once __DIR__ . '/../../includes/OnlinePaymentValidationService.php';
require_once __DIR__ . '/../../includes/InternalPendingPaymentService.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);

$studentId = $input['student_id'] ?? $_POST['student_id'] ?? null;
$billingId = $input['billing_id'] ?? $_POST['billing_id'] ?? null;
$categoryId = $input['category_id'] ?? $_POST['category_id'] ?? null;
$amount = $input['amount'] ?? $_POST['amount'] ?? null;

if (!$studentId || !$billingId || !$categoryId || !$amount) {
    echo json_encode(['status' => 'error', 'message' => 'Missing required parameters.']);
    exit;
}

// FIX A: Centralized Database Connection
try {
    require_once __DIR__ . '/../database/db_connect.php'; 
    if (isset($pdo)) {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } else {
        throw new PDOException("Database connection not established.");
    }
} catch (PDOException $e) {
    die(json_encode(['status' => 'error', 'message' => 'Database connection failed.']));
}

try {
    // Step 1: Backend Validation (Phase 3)
    $validator = new OnlinePaymentValidationService($pdo);
    $validationResult = $validator->validatePaymentRequest($studentId, $billingId, $categoryId, $amount);
    
    if (!$validationResult['is_valid']) {
        throw new Exception($validationResult['message']);
    }

    // Get Category Name for Description
    $stmt = $pdo->prepare("SELECT category_name FROM fee_categories WHERE category_id = ?");
    $stmt->execute([$categoryId]);
    $catName = $stmt->fetchColumn() ?: "School Fees"; 
    
    // Step 2: Create Internal Pending Payment (Phase 4)
    $internalService = new InternalPendingPaymentService($pdo);
    $paymentId = $internalService->createPendingPayment($studentId, $billingId, $amount, $categoryId);
    
    // Step 3: Call PayMongo (Phase 5)
    $payMongo = new PayMongoService();
    
    // Dynamically build the base URL so it works in both localhost and production
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'];
    $baseUrl = $protocol . "://" . $host . "/SMS2_system/modules/payment";
    
    $successUrl = $baseUrl . '/pages/student/payment-success.php?payment_id=' . $paymentId;
    $cancelUrl = $baseUrl . '/pages/student/payment-cancelled.php?payment_id=' . $paymentId;
    
    $description = "Payment for " . $catName;
    
    // The reference number passed to PayMongo is our internal payment_id
    $payMongoResponse = $payMongo->createCheckoutSession(
        $amount, 
        $description, 
        $paymentId, 
        $successUrl, 
        $cancelUrl
    );
    
    // Step 4: Extract Checkout Session ID and URL
    $checkoutSessionId = $payMongoResponse['data']['id'] ?? null;
    $checkoutUrl = $payMongoResponse['data']['attributes']['checkout_url'] ?? null;
    
    if (!$checkoutSessionId || !$checkoutUrl) {
        throw new Exception("PayMongo failed to return a valid Checkout Session URL.");
    }
    
    // Step 5: Update internal payment with the Session ID
    $internalService->updateReferenceNumber($paymentId, $checkoutSessionId);
    
    echo json_encode([
        'status' => 'success',
        'checkout_url' => $checkoutUrl,
        'session_id' => $checkoutSessionId,
        'internal_payment_id' => $paymentId
    ]);

} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}