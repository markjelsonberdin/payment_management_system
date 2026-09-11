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
    // Resolve the authenticated user to the payment-db student_id. These are
    // separate identifiers and must not be compared directly.
    $stmtStudent = $pdo->prepare(
        'SELECT student_id FROM students WHERE user_id = :user_id LIMIT 1'
    );
    $stmtStudent->execute([':user_id' => (int) ($_SESSION['user_id'] ?? 0)]);
    $studentId = $stmtStudent->fetchColumn();

    if (!$studentId) {
        echo json_encode(['success' => false, 'error' => 'Student profile not found']);
        http_response_code(403);
        exit;
    }

    if ($paymentIntentId) {
        $stmt = $pdo->prepare("SELECT * FROM payments WHERE payment_intent_id = :id AND student_id = :student_id LIMIT 1");
        $stmt->execute([':id' => $paymentIntentId, ':student_id' => $studentId]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM payments WHERE reference_number = :ref AND student_id = :student_id LIMIT 1");
        $stmt->execute([':ref' => $referenceNumber, ':student_id' => $studentId]);
    }

    $payment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$payment) {
        echo json_encode(['success' => false, 'error' => 'Payment not found']);
        http_response_code(404);
        exit;
    }

    $expiresAt = !empty($payment['expires_at'])
        ? $payment['expires_at']
        : (!empty($payment['created_at']) ? date('Y-m-d H:i:s', strtotime($payment['created_at']) + 600) : null);

    if ($payment['payment_status'] === 'Pending' && $expiresAt !== null && strtotime($expiresAt) <= time()) {
        echo json_encode([
            'success' => true,
            'status' => 'Expired',
            'expires_at' => $expiresAt,
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'status' => $payment['payment_status'], // 'Pending', 'Verified', 'Failed', 'Rejected', etc.
        'expires_at' => $expiresAt,
    ]);

} catch (Exception $e) {
    error_log("Check Status Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
