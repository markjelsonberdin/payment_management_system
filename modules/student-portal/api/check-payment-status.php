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

    $expiresAt = $payment['expires_at'] ?? null;
    if (!$expiresAt) {
        throw new RuntimeException('Payment record is missing its authoritative expiry timestamp.');
    }

    // Expiration is a backend state transition. The conditional update makes
    // this safe when payment.paid races with the deadline.
    $statusColumn = $pdo->query("SHOW COLUMNS FROM payments LIKE 'payment_status'")->fetch(PDO::FETCH_ASSOC);
    $supportsExpiredState = stripos((string) ($statusColumn['Type'] ?? ''), "'Expired'") !== false;
    if ($supportsExpiredState) {
        $stmtExpire = $pdo->prepare(
            "UPDATE payments
             SET payment_status = 'Expired'
             WHERE payment_id = :payment_id
               AND payment_status = 'Pending'
               AND expires_at <= NOW()"
        );
        $stmtExpire->execute([':payment_id' => $payment['payment_id']]);
    }

    $hasEnvironmentColumn = (bool) $pdo->query("SHOW COLUMNS FROM payments LIKE 'gateway_environment'")->fetch(PDO::FETCH_ASSOC);
    $environmentSelect = $hasEnvironmentColumn ? 'gateway_environment' : 'NULL AS gateway_environment';
    $stmtRefresh = $pdo->prepare(
        "SELECT payment_status, payment_channel, {$environmentSelect}, expires_at,
                UNIX_TIMESTAMP(expires_at) * 1000 AS expires_at_ms,
                UNIX_TIMESTAMP() * 1000 AS server_now_ms
         FROM payments WHERE payment_id = :payment_id"
    );
    $stmtRefresh->execute([':payment_id' => $payment['payment_id']]);
    $payment = array_merge($payment, $stmtRefresh->fetch(PDO::FETCH_ASSOC) ?: []);

    // A real wallet rejects a sandbox QR and PayMongo may emit payment.failed.
    // Keep that QR visibly pending in Test Mode until the configured expiry;
    // Live Mode continues to surface genuine terminal failures.
    $gatewayMode = strtolower((string) ($payment['gateway_environment'] ?? 'test'));
    $reportedStatus = $payment['payment_status'];
    if (!$supportsExpiredState
        && $reportedStatus === 'Pending'
        && (int) $payment['expires_at_ms'] <= (int) $payment['server_now_ms']
    ) {
        $reportedStatus = 'Expired';
    }

    if ($gatewayMode === 'test'
        && strcasecmp((string) ($payment['payment_channel'] ?? ''), 'QRPh') === 0
        && in_array($reportedStatus, ['Failed', 'Rejected'], true)
    ) {
        $reportedStatus = ((int) $payment['expires_at_ms'] <= (int) $payment['server_now_ms'])
            ? 'Expired'
            : 'Pending';
    }

    echo json_encode([
        'success' => true,
        'status' => $reportedStatus,
        'expires_at' => $expiresAt,
        'expires_at_ms' => (int) $payment['expires_at_ms'],
        'server_now_ms' => (int) $payment['server_now_ms'],
    ]);

} catch (Exception $e) {
    error_log("Check Status Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
