<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once __DIR__ . '/../database/db_connect.php';
header('Content-Type: application/json; charset=utf-8');
requireAuth();
requirePaymentPermission('payment.cashier_dashboard');
$query=trim((string)($_GET['q'] ?? ''));
if (mb_strlen($query)<2 || mb_strlen($query)>100) { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Enter at least two characters.']); exit; }
try {
    if (preg_match('/^S\d{9}$/i', $query)===1) {
        $stmt=$pdo->prepare('SELECT student_number,full_name,course,year_level,status FROM students WHERE LOWER(student_number)=LOWER(?) LIMIT 1');
        $stmt->execute([$query]);
    } else {
        $needle='%'.$query.'%';
        $stmt=$pdo->prepare('SELECT student_number,full_name,course,year_level,status FROM students WHERE full_name LIKE ? OR student_number LIKE ? ORDER BY full_name,student_number LIMIT 10');
        $stmt->execute([$needle,$needle]);
    }
    echo json_encode(['success'=>true,'students'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
} catch (Throwable $e) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'Student search is temporarily unavailable.']); }