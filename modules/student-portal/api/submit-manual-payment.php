<?php
/**
 * SMS 2 - Submit Manual Payment Concern
 * 
 * Creates a payment concern for manual verification by Accounting.
 * Also stores the OCR extraction results.
 */
require_once __DIR__ . '/../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once ROOT_PATH . '/includes/security.php';

header('Content-Type: application/json');

// 1. Authentication
requireAuth();
$studentId = getCurrentUserId();
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

// 2. CSRF (Handling form data)
$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF_FAILED', 'message' => 'Invalid security token.']);
    exit;
}

// 3. Validation
$amount = $_POST['amount'] ?? null;
$referenceNumber = $_POST['reference_number'] ?? null;
$channel = $_POST['channel'] ?? 'Manual';
$ocrRawText = $_POST['ocr_raw_text'] ?? null;
$ocrAmount = $_POST['ocr_amount'] ?? null;
$ocrReference = $_POST['ocr_reference'] ?? null;
$billingId = $_POST['billing_id'] ?? null;
$allocationContext = $_POST['allocation_context'] ?? null;

if (!$amount || !$referenceNumber) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'MISSING_FIELDS', 'message' => 'Amount and Reference Number are required.']);
    exit;
}

// 4. File Upload (Simple secure upload)
if (!isset($_FILES['receipt']) || $_FILES['receipt']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'UPLOAD_ERROR', 'message' => 'Valid receipt image is required.']);
    exit;
}

$file = $_FILES['receipt'];
$allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

if (!in_array($ext, $allowedExts)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'INVALID_TYPE', 'message' => 'Invalid image format.']);
    exit;
}

// Save to uploads/receipts
$uploadDir = ROOT_PATH . '/uploads/receipts/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
    file_put_contents($uploadDir . '.htaccess', 'Require all denied'); // Protect direct access if applicable
}

$newFileName = uniqid('rcpt_') . '_' . time() . '.' . $ext;
$destPath = $uploadDir . $newFileName;
$dbPath = 'uploads/receipts/' . $newFileName;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'UPLOAD_FAILED', 'message' => 'Failed to save receipt image.']);
    exit;
}

try {
    require_once __DIR__ . '/../../payment/database/db_connect.php';
    global $pdo;

    // We need the internal student_id from students table based on user_id
    $stmt = $pdo->prepare("SELECT student_id FROM payment_db.students WHERE user_id = ?");
    $stmt->execute([$studentId]);
    $studentInternal = $stmt->fetchColumn();

    if (!$studentInternal) {
        throw new Exception("Student record not found in payment system.");
    }

    $pdo->beginTransaction();

    // 5. Insert Payment Concern (Student's submitted values)
    $stmtConcern = $pdo->prepare("
        INSERT INTO payment_db.payment_concerns 
        (student_id, receipt_path, verification_status, ocr_status, remarks, submitted_at) 
        VALUES (?, ?, 'Pending', 'Completed', ?, NOW())
    ");
    $remarks = json_encode([
        'submitted_amount' => $amount,
        'submitted_reference' => $referenceNumber,
        'channel' => $channel,
        'billing_id' => $billingId,
        'allocation_context' => $allocationContext
    ]);
    
    $stmtConcern->execute([$studentInternal, $dbPath, $remarks]);
    $concernId = $pdo->lastInsertId();

    // 6. Insert OCR Results (Raw/AI extracted values)
    $stmtOcr = $pdo->prepare("
        INSERT INTO payment_db.ocr_results 
        (concern_id, extracted_amount, reference_number, raw_json) 
        VALUES (?, ?, ?, ?)
    ");
    $stmtOcr->execute([
        $concernId, 
        $ocrAmount ? (float)$ocrAmount : null, 
        $ocrReference, 
        $ocrRawText
    ]);

    $pdo->commit();

    echo json_encode(['success' => true, 'message' => 'Receipt submitted successfully and is now under review by Accounting.']);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'DB_ERROR', 'message' => 'Failed to record submission: ' . $e->getMessage()]);
}
