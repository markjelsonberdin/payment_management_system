<?php
/**
 * SMS 2 - Secure Receipt Viewer
 * Serves uploaded receipts securely by checking permissions based on concern_id.
 */
require_once __DIR__ . '/../../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once ROOT_PATH . '/modules/payment/database/db_connect.php';
require_once ROOT_PATH . '/modules/payment/includes/PaymentSecurityService.php';

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
    $securityService->ensurePaymentAccess($userId, $role, 'payment.collection', $concernId, 'concern');
    
    // Fetch concern to get the receipt path
    $stmt = $pdo->prepare("SELECT receipt_path FROM payment_concerns WHERE concern_id = ?");
    $stmt->execute([$concernId]);
    $concern = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$concern || empty($concern['receipt_path'])) {
        http_response_code(404);
        echo "Receipt not found.";
        exit;
    }
    
    // Resolve absolute path
    // receipt_path in DB is like 'uploads/receipts/filename.jpg'
    $absolutePath = realpath(ROOT_PATH . '/' . $concern['receipt_path']);
    $receiptsDir = realpath(ROOT_PATH . '/uploads/receipts');
    
    // Ensure the file exists and is within the receipts directory (prevent directory traversal)
    if (!$absolutePath || strpos($absolutePath, $receiptsDir) !== 0 || !file_exists($absolutePath)) {
        http_response_code(404);
        echo "File not found on server.";
        exit;
    }
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $absolutePath);
    finfo_close($finfo);
    
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . filesize($absolutePath));
    // Provide a random or generic filename to hide actual DB path just in case
    header('Content-Disposition: inline; filename="receipt_' . $concernId . '"');
    
    readfile($absolutePath);
    exit;

} catch (Exception $e) {
    http_response_code(403);
    echo "Access Denied: " . htmlspecialchars($e->getMessage());
    exit;
}
