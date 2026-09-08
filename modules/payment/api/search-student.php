<?php
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../database/db_connect.php';
require_once __DIR__ . '/../includes/RegistrarStudentClient.php';

header('Content-Type: application/json');
$student_number = $_GET['student_number'] ?? '';

// Do not run a lookup for partial input. The deployed payment records use
// the canonical S + 9 digits student-number format.
if (!preg_match('/^S\d{9}$/i', $student_number)) {
    echo json_encode(['success' => false]);
    exit;
}

try {
    $client = new RegistrarStudentClient($pdo);
    $student = $client->getAndSyncStudent($student_number);
    
    if ($student) {
        echo json_encode([
            'success' => true,
            'name' => trim($student['full_name'] ?? 'Unknown Student'),
            'student_id' => $student['student_id'] // Used by Invoicing
        ]);
        exit;
    }
    
    echo json_encode(['success' => false]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>