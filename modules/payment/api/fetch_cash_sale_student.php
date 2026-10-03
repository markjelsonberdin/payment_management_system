<?php
require_once __DIR__ . '/../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';

header('Content-Type: application/json; charset=utf-8');
if (!paymentSchoolSalesSellingEnabled()) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'FEATURE_DISABLED', 'message' => 'School Sales is unavailable.']);
    exit;
}

requireAuth();
requirePaymentPermission('payment.school_sales');
require_once __DIR__ . '/../database/db_connect.php';
require_once __DIR__ . '/../includes/RegistrarStudentClient.php';

$studentNumber = trim((string) ($_GET['student_number'] ?? ''));
if (!preg_match('/^S\d{9}$/i', $studentNumber)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Enter a valid student number.']);
    exit;
}
try {
    $student = (new RegistrarStudentClient($pdo))->getAndSyncStudent($studentNumber);
    if (!$student) throw new RuntimeException('Student not found in Registrar records.');
    echo json_encode(['success' => true, 'student_id' => (int) $student['student_id'], 'student_number' => $student['student_number'], 'name' => $student['full_name'], 'course_year' => ($student['course_id'] ?? 'N/A') . ' - ' . ($student['year_level'] ?? 'N/A') . ' Year']);
} catch (Throwable $e) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
