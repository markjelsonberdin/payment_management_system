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
require_once ROOT_PATH . '/modules/payment/includes/ocr/GoogleOCRService.php';
require_once ROOT_PATH . '/modules/payment/includes/bank_recon/BankReconciliationService.php';
require_once ROOT_PATH . '/modules/payment/includes/PaymentSecurityService.php';

header('Content-Type: application/json');

requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'METHOD_NOT_ALLOWED']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$csrfToken = $input['csrf_token'] ?? '';
if (!verifyCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF_FAILED', 'message' => 'Invalid security token.']);
    exit;
}

if (empty($input['concern_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'MISSING_ID']);
    exit;
}

$concernId = (int)$input['concern_id'];
$scannedBy = getCurrentUserId();
$role = getCurrentUserRoleKey();

try {
    global $pdo;

    $securityService = new PaymentSecurityService($pdo);
    $securityService->ensurePaymentAccess($scannedBy, $role, 'payment.ocr.scan', $concernId, 'concern');

    // Rate Limiting (User + Concern + Time window)
    $rateLimitKey = "ocr_limit_{$scannedBy}_{$concernId}";
    $currentTime = time();
    $minuteWindow = 60;
    
    if (!isset($_SESSION[$rateLimitKey])) {
        $_SESSION[$rateLimitKey] = [];
    }
    
    // Filter out old attempts
    $_SESSION[$rateLimitKey] = array_filter($_SESSION[$rateLimitKey], function($timestamp) use ($currentTime, $minuteWindow) {
        return ($currentTime - $timestamp) < $minuteWindow;
    });
    
    if (count($_SESSION[$rateLimitKey]) >= 5) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'RATE_LIMIT_EXCEEDED', 'message' => 'Too many OCR scan attempts for this concern. Please wait a minute.']);
        exit;
    }
    
    // Record this attempt
    $_SESSION[$rateLimitKey][] = $currentTime;

    $ocrService = new GoogleOCRService($pdo);
    
    $stmt = $pdo->prepare("SELECT receipt_path FROM payment_concerns WHERE concern_id = ?");
    $stmt->execute([$concernId]);
    $concern = $stmt->fetch();

    if (!$concern || empty($concern['receipt_path'])) {
        throw new Exception("No receipt found for this concern.");
    }

    $result = $ocrService->processReceipt($concernId, $concern['receipt_path'], $scannedBy);

    if ($result['success'] && !empty($result['ocr_result_id'])) {
        $reconService = new BankReconciliationService($pdo);
        $reconResult = $reconService->reconcileConcern($result['ocr_result_id']);
        $result['bank_match'] = $reconResult;
    }

    // Audit Logging
    $auditDesc = "OCR Scan for Concern #{$concernId}. Status: " . ($result['success'] ? 'SUCCESS' : 'FAILED');
    if (isset($result['bank_match']['status'])) {
        $auditDesc .= ". Match Status: " . $result['bank_match']['status'];
    }
    
    $stmtLog = $pdo->prepare("
        INSERT INTO sms2_db.activity_logs (user_id, action, module_key, detail, ip_address, user_agent)
        VALUES (:uid, 'Trigger OCR Scan', 'Payment Management', :desc, :ip, :ua)
    ");
    $stmtLog->execute([
        ':uid' => $scannedBy,
        ':desc' => $auditDesc,
        ':ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
    ]);

    echo json_encode($result);

} catch (Exception $e) {
    http_response_code(500);
    error_log("OCR Processing Failed for Concern ID {$concernId}: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'OCR_PROCESSING_FAILED', 'message' => $e->getMessage()]);
}

