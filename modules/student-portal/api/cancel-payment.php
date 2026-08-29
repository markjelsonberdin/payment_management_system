<?php
/**
 * SMS 2 - Cancel Payment API
 * 
 * Safely cancels a Pending payment transaction.
 */
require_once __DIR__ . '/../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once ROOT_PATH . '/includes/security.php';

header('Content-Type: application/json');

// Force student login
requireAuth();
if (getCurrentUserRoleKey() !== 'student') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'FORBIDDEN', 'message' => 'Unauthorized access.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'METHOD_NOT_ALLOWED']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

// CSRF Validation
requireCsrfJson($input);

$paymentId = $input['payment_id'] ?? null;
if (!$paymentId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'MISSING_REQUIRED_FIELDS', 'message' => 'Payment ID is required.']);
    exit;
}

try {
    require_once __DIR__ . '/../../payment/database/db_connect.php';
    global $pdo;

    // Fetch the payment and validate ownership
    $stmt = $pdo->prepare("
        SELECT p.*, s.user_id 
        FROM payment_db.payments p
        JOIN payment_db.students s ON p.student_id = s.student_id
        WHERE p.payment_id = :payment_id
    ");
    $stmt->execute([':payment_id' => $paymentId]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$payment) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'NOT_FOUND', 'message' => 'Payment record not found.']);
        exit;
    }

    // Security: Validate ownership
    if ($payment['user_id'] != getCurrentUserId()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'FORBIDDEN', 'message' => 'Unauthorized object access.']);
        exit;
    }

    // State Validation
    if ($payment['payment_status'] !== 'Pending') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'INVALID_STATE', 'message' => 'Only Pending payments can be cancelled.']);
        exit;
    }

    // Cancel the payment
    $stmtUpdate = $pdo->prepare("
        UPDATE payment_db.payments 
        SET payment_status = 'Cancelled', payment_date = NOW() 
        WHERE payment_id = :payment_id
    ");
    $stmtUpdate->execute([':payment_id' => $paymentId]);

    echo json_encode(['success' => true, 'message' => 'Payment cancelled successfully.']);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'SERVER_ERROR', 'message' => $e->getMessage()]);
}
