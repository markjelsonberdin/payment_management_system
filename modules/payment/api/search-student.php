<?php
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../database/db_connect.php';

header('Content-Type: application/json');
$student_number = $_GET['student_number'] ?? '';

// Wag mag-search kung masyadong maikli yung tinype
if(strlen($student_number) < 3) {
    echo json_encode(['success' => false]);
    exit;
}

try {
    // Connect to sms2_db directly since we don't have a registrar API yet
    $sms2_pdo = new PDO("mysql:host=127.0.0.1;port=3307;dbname=sms2_db", "root", "");
    $sms2_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Search in sms2_db.users
    $stmt = $sms2_pdo->prepare("
        SELECT 
            id as student_id, 
            student_id as student_number, 
            full_name
        FROM users 
        WHERE role_key = 'student' AND student_id = :student_number 
        LIMIT 1
    ");
    $stmt->execute([':student_number' => $student_number]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($student) {
        // Sync to payment_db.students so foreign keys work
        $syncStmt = $pdo->prepare("
            INSERT INTO students (student_id, user_id, student_number, full_name, course, year_level, status)
            VALUES (:id, :uid, :sn, :name, 'Unknown', '1', 'Enrolled')
            ON DUPLICATE KEY UPDATE 
                full_name = VALUES(full_name),
                last_sync_at = CURRENT_TIMESTAMP
        ");
        $syncStmt->execute([
            ':id' => $student['student_id'],
            ':uid' => $student['student_id'],
            ':sn' => $student['student_number'],
            ':name' => $student['full_name']
        ]);

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