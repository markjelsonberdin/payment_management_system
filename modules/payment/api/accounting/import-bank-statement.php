<?php
/**
 * SMS 2 - Import Bank Statement (Accounting API)
 * Uploads an AUB CSV and stores the rows for Bank Reconciliation.
 */
require_once __DIR__ . '/../../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once ROOT_PATH . '/includes/security.php';
require_once ROOT_PATH . '/modules/payment/database/db_connect.php';
require_once ROOT_PATH . '/modules/payment/includes/bank_recon/BankReconciliationService.php';
require_once ROOT_PATH . '/modules/payment/includes/PaymentSecurityService.php';

header('Content-Type: application/json');

requireAuth();

// Ensure proper role
$role = getCurrentUserRoleKey();
$userId = getCurrentUserId();
global $pdo;
$securityService = new PaymentSecurityService($pdo);
$securityService->ensurePaymentAccess($userId, $role, 'payment.bank_reconciliation.import', 0, 'system');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'METHOD_NOT_ALLOWED']);
    exit;
}

// CSRF check
$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF_FAILED', 'message' => 'Invalid security token.']);
    exit;
}

// File Upload Validation
if (!isset($_FILES['statement_file']) || $_FILES['statement_file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'UPLOAD_ERROR', 'message' => 'Valid CSV statement file is required.']);
    exit;
}

$file = $_FILES['statement_file'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

if ($ext !== 'csv' || $file['type'] !== 'text/csv') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'INVALID_TYPE', 'message' => 'Only CSV files are allowed.']);
    exit;
}

if ($file['size'] > 5 * 1024 * 1024) { // 5MB limit
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'FILE_TOO_LARGE', 'message' => 'File size exceeds the 5MB limit.']);
    exit;
}

try {
    global $pdo;

    $uploaderId = getCurrentUserId();
    $reconService = new BankReconciliationService($pdo);
    
    $result = $reconService->importAUBStatement($file['tmp_name'], $uploaderId, $file['name']);
    
    echo json_encode($result);

} catch (Exception $e) {
    http_response_code(400); // Bad Request for duplicate hash or parsing error
    echo json_encode(['success' => false, 'error' => 'IMPORT_FAILED', 'message' => $e->getMessage()]);
}

