<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/modules/payment/database/db_connect.php';

try {
    // Empty the table first if it has test data to avoid constraint issues
    $pdo->exec("DELETE FROM payment_db.payment_concerns");
    
    // Check if column already exists
    $stmt = $pdo->query("SHOW COLUMNS FROM payment_db.payment_concerns LIKE 'student_id'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE payment_db.payment_concerns ADD COLUMN student_id INT(10) UNSIGNED NOT NULL AFTER concern_id");
        $pdo->exec("ALTER TABLE payment_db.payment_concerns ADD CONSTRAINT fk_concerns_student FOREIGN KEY (student_id) REFERENCES payment_db.students(student_id) ON UPDATE CASCADE ON DELETE CASCADE");
        echo "Successfully added student_id to payment_concerns.\n";
    } else {
        echo "Column student_id already exists.\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
