<?php
error_reporting(0);
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../database/db_connect.php';
require_once __DIR__ . '/../includes/RegistrarStudentClient.php';

header('Content-Type: application/json');
$student_number = $_GET['student_number'] ?? '';

if(empty($student_number)) {
    echo json_encode(['success' => false]);
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
        SELECT billing_id, remaining_balance
        FROM billing
        WHERE student_id = :sid
        AND billing_status IN ('Unpaid', 'Partial')
        ORDER BY created_at DESC LIMIT 1
    ");
    $stmt->execute([':sid' => $student_id]);
    $billing = $stmt->fetch(PDO::FETCH_ASSOC);

    if($billing) {
        echo json_encode([
            'success' => true,
            'billing_id' => $billing['billing_id'],
            'name' => trim($student['first_name'] . ' ' . $student['last_name']),
            'student_number' => $student['student_number'],
            'balance' => (float) $billing['remaining_balance']
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'No unpaid billing found.']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false]);
}
?>