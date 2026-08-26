<?php
require_once __DIR__ . '/modules/payment/database/db_connect.php';

try {
    echo "=============================================\n";
    echo "EXECUTING DATABASE MIGRATION BACKFILL\n";
    echo "=============================================\n\n";

    // 1. Backfill all NULL contexts to ENROLLMENT_PRIORITY (since all 7 were confirmed)
    echo "1. Backfilling historical contexts...\n";
    $affected = $pdo->exec("UPDATE payment_db.payments SET allocation_context = 'ENROLLMENT_PRIORITY' WHERE allocation_context IS NULL");
    echo "   -> Updated $affected rows.\n\n";

    // 2. Validate data constraints before enforcing NOT NULL
    echo "2. Validating data constraints...\n";
    $stmtValid1 = $pdo->query("SELECT COUNT(*) FROM payment_db.payments WHERE allocation_context = 'SPECIFIC_ITEM' AND billing_item_id IS NULL");
    $invalidSpecific = $stmtValid1->fetchColumn();
    
    $stmtValid2 = $pdo->query("SELECT COUNT(*) FROM payment_db.payments WHERE allocation_context = 'ENROLLMENT_PRIORITY' AND billing_item_id IS NOT NULL");
    $invalidEnrollment = $stmtValid2->fetchColumn();

    if ($invalidSpecific > 0 || $invalidEnrollment > 0) {
        throw new Exception("Data validation failed! Found $invalidSpecific invalid SPECIFIC_ITEM and $invalidEnrollment invalid ENROLLMENT_PRIORITY records.");
    }
    echo "   -> Validation passed. No invalid context combinations.\n\n";

    // 3. Enforce NOT NULL constraint
    echo "3. Enforcing NOT NULL DEFAULT 'ENROLLMENT_PRIORITY' on allocation_context...\n";
    $pdo->exec("ALTER TABLE payment_db.payments MODIFY COLUMN allocation_context ENUM('ENROLLMENT_PRIORITY', 'SPECIFIC_ITEM') NOT NULL DEFAULT 'ENROLLMENT_PRIORITY'");
    echo "   -> Success.\n\n";

    // 4. Add Foreign Key
    echo "4. Adding FOREIGN KEY constraint to billing_item_id...\n";
    
    // Check if constraint exists first
    $stmt = $pdo->query("
        SELECT CONSTRAINT_NAME 
        FROM information_schema.KEY_COLUMN_USAGE 
        WHERE TABLE_SCHEMA = 'payment_db' 
          AND TABLE_NAME = 'payments' 
          AND CONSTRAINT_NAME = 'fk_payments_billing_item'
    ");
    
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE payment_db.payments ADD CONSTRAINT fk_payments_billing_item FOREIGN KEY (billing_item_id) REFERENCES payment_db.billing_items(billing_item_id) ON DELETE SET NULL");
        echo "   -> FOREIGN KEY added.\n\n";
    } else {
        echo "   -> FOREIGN KEY already exists.\n\n";
    }

    // 5. Post-migration sanity check
    echo "5. Post-migration summary:\n";
    $stmtSummary = $pdo->query("SELECT allocation_context, COUNT(*) as count FROM payment_db.payments GROUP BY allocation_context");
    while ($row = $stmtSummary->fetch(PDO::FETCH_ASSOC)) {
        echo "   -> " . $row['allocation_context'] . ": " . $row['count'] . "\n";
    }

    echo "\nMigration completed successfully!\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
