<?php
error_reporting(0);
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../database/db_connect.php';
require_once __DIR__ . '/../includes/RegistrarStudentClient.php';

header('Content-Type: application/json');
$student_number = $_GET['student_number'] ?? '';

if(empty($student_number)) {
    echo json_encode(['success' => false, 'message' => 'Empty student number.']);
    exit;
}

try {
    // 1. Fetch Student from Registrar API (and sync locally)
    $client = new RegistrarStudentClient($pdo);
    $student = $client->getAndSyncStudent($student_number);
    
    if (!$student) {
        echo json_encode(['success' => false, 'message' => 'Student not found in Registrar records.']);
        exit;
    }
    
    $student_id = $student['student_id'];

    // 2. Query Payment Database for active billing using the student_id
    $stmt = $pdo->prepare("
        SELECT 
            billing_id, 
            billing_type,
            total_amount,
            remaining_balance
        FROM billing
        WHERE student_id = :sid
        AND billing_status IN ('Unpaid', 'Partial')
        ORDER BY created_at DESC LIMIT 1
    ");
    $stmt->execute([':sid' => $student_id]);
    $billing = $stmt->fetch(PDO::FETCH_ASSOC);

    if($billing) {
        $course_str = ($student['course_id'] ?? 'N/A') . ' - ' . ($student['year_level'] ?? 'N/A') . ' Year';

        echo json_encode([
            'success' => true,
            'billing_id' => $billing['billing_id'],
            'student_id' => $student_id,
            'name' => trim($student['full_name'] ?? 'Unknown Student'),
            'student_number' => $student['student_number'],
            'course_year' => $course_str,
            'billing_type' => $billing['billing_type'],
            'total_amount' => (float) $billing['total_amount'],
            'balance' => (float) $billing['remaining_balance']
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'No active billing found for this student. Please create one in Billing & Invoicing.']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
?>
