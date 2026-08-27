<?php
require_once __DIR__ . '/config/config.php';
require_once ROOT_PATH . '/modules/payment/database/db_connect.php';

try {
    $pdo->beginTransaction();

    // 1. Alter Enum
    $pdo->exec("ALTER TABLE payment_db.payments MODIFY COLUMN payment_channel ENUM('Cash','GCash','Maya','Visa','Mastercard','Bank','PayMongo','QRPh') NOT NULL");

    // 2. Add payment_intent_id column
    // Check if column exists first
    $stmt = $pdo->query("SHOW COLUMNS FROM payment_db.payments LIKE 'payment_intent_id'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE payment_db.payments ADD COLUMN payment_intent_id VARCHAR(255) NULL AFTER checkout_session_id");
        $pdo->exec("ALTER TABLE payment_db.payments ADD UNIQUE INDEX (payment_intent_id)");
    }

    $pdo->commit();
    echo "Database schema updated successfully.\n";
} catch (Exception $e) {
    $pdo->rollBack();
    echo "Error updating schema: " . $e->getMessage() . "\n";
}
