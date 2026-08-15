<?php
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../database/db_connect.php';
require_once __DIR__ . '/../includes/RegistrarStudentClient.php';

header('Content-Type: application/json');
$student_number = $_GET['student_number'] ?? '';

// Wag mag-search kung masyadong maikli yung tinype
if(strlen($student_number) < 3) {
    echo json_encode(['success' => false]);
    exit;
}

try {
    $client = new RegistrarStudentClient($pdo);
    $student = $client->getAndSyncStudent($student_number);
    
    if ($student) {
        echo json_encode([
            'success' => true,
            'name' => trim($student['first_name'] . ' ' . $student['last_name']),
            'student_id' => $student['student_id'] // Used by Invoicing
        ]);
        exit;
    }
    
    echo json_encode(['success' => false]);
} catch (Exception $e) {
    echo json_encode(['success' => false]);
}
?>