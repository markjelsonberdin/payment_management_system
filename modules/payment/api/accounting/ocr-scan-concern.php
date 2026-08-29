<?php
/**
 * SMS 2 - OCR Scan Concern (Accounting API)
 * Triggered by accounting to scan an existing uploaded receipt.
 */
require_once __DIR__ . '/../../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once ROOT_PATH . '/includes/security.php';
require_once ROOT_PATH . '/vendor/autoload.php';
require_once ROOT_PATH . '/modules/payment/database/db_connect.php';
require_once ROOT_PATH . '/modules/payment/includes/GoogleOCRService.php';

header('Content-Type: application/json');

requireAuth();

// Ensure accounting or admin role
$role = getCurrentUserRoleKey();
if (!in_array($role, ['accounting', 'cashier', 'admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'FORBIDDEN']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'METHOD_NOT_ALLOWED']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (empty($input['concern_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'MISSING_ID']);
    exit;
}

$concernId = (int)$input['concern_id'];

try {
    global $pdo;

    $ocrService = new GoogleOCRService($pdo);
    
    $stmt = $pdo->prepare("SELECT receipt_path FROM payment_concerns WHERE concern_id = ?");
    $stmt->execute([$concernId]);
    $concern = $stmt->fetch();

    if (!$concern || empty($concern['receipt_path'])) {
        throw new Exception("No receipt found for this concern.");
    }

    $result = $ocrService->processReceipt($concernId, $concern['receipt_path']);

    echo json_encode($result);

} catch (Exception $e) {
    // Return safe JSON error without exposing raw exception
    http_response_code(500);
    error_log("OCR Processing Failed for Concern ID {$concernId}: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'OCR_PROCESSING_FAILED', 'message' => 'An error occurred during OCR extraction. Check logs for details.']);
}
