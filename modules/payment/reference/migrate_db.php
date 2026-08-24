<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/modules/payment/database/db_connect.php';

try {
    echo "Starting migration...\n";
    
    // Add columns with a default first
    $pdo->exec("ALTER TABLE payment_db.billing_items ADD COLUMN source_context VARCHAR(50) DEFAULT 'Enrollment Assessment' AFTER fee_name");
    echo "Added source_context column.\n";
    
    $pdo->exec("ALTER TABLE payment_db.billing_items ADD COLUMN added_by INT UNSIGNED NULL AFTER source_context");
    echo "Added added_by column.\n";
    
    $pdo->exec("ALTER TABLE payment_db.billing_items ADD COLUMN added_at DATETIME DEFAULT CURRENT_TIMESTAMP AFTER added_by");
    echo "Added added_at column.\n";
    
    // Make source_context NOT NULL and remove the default so future inserts must provide it
    $pdo->exec("ALTER TABLE payment_db.billing_items MODIFY COLUMN source_context VARCHAR(50) NOT NULL");
    echo "Modified source_context to NOT NULL.\n";

    echo "Migration completed successfully.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
