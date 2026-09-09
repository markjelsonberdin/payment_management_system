<?php
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/database.php';

$pdo = db();
$stmt = $pdo->query("SELECT * FROM users WHERE full_name LIKE '%Lebron%'");
$user = $stmt->fetch(PDO::FETCH_ASSOC);
echo "USER:\n";
print_r($user);

// Now check if he exists in students table
$stmt = $pdo->query("SELECT * FROM students WHERE student_number = '" . $user['student_id'] . "'");
$student = $stmt->fetch(PDO::FETCH_ASSOC);
echo "\nSTUDENT in main DB:\n";
print_r($student);

// Try payment db
require_once __DIR__ . '/modules/student-portal/config/config.php';
$payDb = studentPortalDb();
$stmt = $payDb->query("SELECT * FROM students WHERE student_number = '" . $user['student_id'] . "'");
$payStudent = $stmt->fetch(PDO::FETCH_ASSOC);
echo "\nSTUDENT in Payment DB:\n";
print_r($payStudent);
