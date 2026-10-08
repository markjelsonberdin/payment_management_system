<?php
/**
 * SMS 2 - Secure Receipt Viewer
 * Serves uploaded receipts securely by checking permissions based on concern_id.
 */
require_once __DIR__ . '/../../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once ROOT_PATH . '/modules/payment/database/db_connect.php';
require_once ROOT_PATH . '/modules/payment/includes/PaymentSecurityService.php';
require_once ROOT_PATH . '/modules/payment/includes/ocr/PrivateReceiptStorageService.php';

// Authentication
requireAuth();
$userId = getCurrentUserId();
$role = getCurrentUserRoleKey();

if (!isset($_GET['concern_id'])) {
    http_response_code(400);
    echo "Missing concern_id.";
    exit;
}

$concernId = (int)$_GET['concern_id'];

try {
    global $pdo;
    $securityService = new PaymentSecurityService($pdo);
    
    // If not admin/accounting, they must own the concern
    // ensurePaymentAccess will throw an exception if they are not allowed
    $securityService->ensurePaymentAccess($userId, $role, 'payment.concern.evidence.review', $concernId, 'concern');
    
    // Fetch concern to get the receipt path
    $stmt = $pdo->prepare("SELECT receipt_path FROM payment_concerns WHERE concern_id = ?");
    $stmt->execute([$concernId]);
    $concern = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$concern || empty($concern['receipt_path'])) {
        http_response_code(404);
        echo "Receipt not found.";
        exit;
    }
    
    $resolved = (new PrivateReceiptStorageService())->resolve((string) $concern['receipt_path']);
    $absolutePath = $resolved['path'];
    $mimeType = $resolved['mime'];
    
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . filesize($absolutePath));
    // Provide a random or generic filename to hide actual DB path just in case
    header('Content-Disposition: inline; filename="receipt_' . $concernId . '.' . $resolved['extension'] . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
    
    readfile($absolutePath);
    exit;

} catch (ReceiptStorageException $e) {
    http_response_code($e->getMessage() === 'RECEIPT_NOT_FOUND' ? 404 : 503);
    echo 'Receipt is unavailable.';
    exit;
} catch (Throwable $e) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}
