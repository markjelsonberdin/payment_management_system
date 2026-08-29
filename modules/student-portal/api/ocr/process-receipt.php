<?php
/**
 * SMS 2 - Process Receipt OCR (Google Cloud Vision) - Student Portal
 */
require_once __DIR__ . '/../../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once ROOT_PATH . '/includes/security.php';
require_once ROOT_PATH . '/includes/uploads.php';
require_once ROOT_PATH . '/vendor/autoload.php';
require_once ROOT_PATH . '/modules/payment/includes/GoogleOCRService.php';

header('Content-Type: application/json');

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

// Ensure the uploaded receipt is available
if (!isset($_FILES['receipt']) || $_FILES['receipt']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'UPLOAD_ERROR', 'message' => 'No valid receipt image provided.']);
    exit;
}

$file = $_FILES['receipt'];
$allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
if (!in_array($file['type'], $allowedTypes)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'INVALID_FILE_TYPE', 'message' => 'Only JPG, PNG, and WebP images are allowed.']);
    exit;
}

if ($file['size'] > 5 * 1024 * 1024) { // 5MB limit
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'FILE_TOO_LARGE', 'message' => 'Image size must be under 5MB.']);
    exit;
}

try {
    $ocrService = new GoogleOCRService();
    $imageContent = file_get_contents($file['tmp_name']);
    
    // Perform extraction but do NOT save to DB
    $result = $ocrService->extractFromImage($imageContent);
    
    if (!$result['success']) {
        throw new Exception("Extraction failed.");
    }
    
    echo json_encode([
        'success' => true,
        'raw_text' => $result['raw_text'],
        'reference_number' => $result['data']['reference'] ?? null,
        'amount' => $result['data']['amount'] ? (float)$result['data']['amount'] : null,
        'payment_date' => $result['data']['date'] ?? null,
        'extraction_status' => $result['extraction_status']
    ]);

} catch (Exception $e) {
    http_response_code(500);
    error_log("Student OCR Process Failed: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'OCR_FAILED', 'message' => 'Failed to process receipt. OCR currently unavailable.']);
}
