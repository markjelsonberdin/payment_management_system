<?php
/**
 * SMS2 - User Management API Endpoint
 * Path: SMS2_system/modules/user-management/api/students.php
 * Usage: GET /SMS2_system/modules/user-management/api/students.php?student_number=S230000001
 */

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET");

// 1. Connect through the authoritative SMS2 Core database helper.
try {
    require_once __DIR__ . '/../../../config/database.php';
    $pdo_sms2 = getDatabaseConnection();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Database connection failed."]);
    exit;
}

// 2. Kunin ang student_number parameter
$student_number = isset($_GET['student_number']) ? trim($_GET['student_number']) : null;

if (!$student_number) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Student number is required."]);
    exit;
}

try {
    // 3. I-query ang estudyante sa sms2_db.users table
    // Ginagamit natin ang role_key = 'student' para iwasang makuha ang mga admin
    $stmt = $pdo_sms2->prepare("
        SELECT 
            id as student_id, 
            id as user_id,
            student_id as student_number, 
            full_name as first_name, 
            '' as last_name, 
            'Unknown' as course_id, 
            '1' as year_level, 
            status 
        FROM users 
        WHERE role_key = 'student' AND student_id = :student_number 
        LIMIT 1
    ");
    
    $stmt->execute([':student_number' => $student_number]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    // 4. I-format ang JSON
    if ($student) {
        http_response_code(200);
        echo json_encode([
            "success" => true,
            "data" => $student
        ]);
    } else {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Student not found in User Management records."
        ]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Server Error: " . $e->getMessage()
    ]);
}
?>
