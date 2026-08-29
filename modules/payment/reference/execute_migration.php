<?php
require_once __DIR__ . '/modules/payment/database/db_connect.php';

try {
    echo "=============================================\n";
    echo "DATABASE INSPECTION AND MIGRATION SCRIPT\n";
    echo "=============================================\n\n";

    // STEP 1: Add columns safely (as NULLable first)
    echo "1. Adding columns if they don't exist...\n";
    
    // Check if allocation_context exists
    $stmt = $pdo->query("SHOW COLUMNS FROM payment_db.payments LIKE 'allocation_context'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE payment_db.payments ADD COLUMN allocation_context ENUM('ENROLLMENT_PRIORITY', 'SPECIFIC_ITEM') NULL AFTER category_id");
        echo "   -> Added allocation_context (NULL)\n";
    } else {
        echo "   -> allocation_context already exists.\n";
    }

    // Check if billing_item_id exists
    $stmt = $pdo->query("SHOW COLUMNS FROM payment_db.payments LIKE 'billing_item_id'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE payment_db.payments ADD COLUMN billing_item_id INT(10) UNSIGNED DEFAULT NULL AFTER allocation_context");
        echo "   -> Added billing_item_id (NULL)\n";
    } else {
        echo "   -> billing_item_id already exists.\n";
    }

    // STEP 2: Inspect existing payments
    echo "\n2. Inspecting existing payment records...\n";
    $stmtTotal = $pdo->query("SELECT COUNT(*) FROM payment_db.payments");
    $totalPayments = $stmtTotal->fetchColumn();
    echo "   Total payments in table: $totalPayments\n\n";

    $stmtAnalyze = $pdo->query("
        SELECT p.payment_id, p.amount, p.category_id, p.transaction_type, p.allocation_context
        FROM payment_db.payments p
    ");
    $payments = $stmtAnalyze->fetchAll(PDO::FETCH_ASSOC);

    $proposedEnrollment = 0;
    $proposedSpecific = 0;
    $unknown = 0;
    $alreadyHasItem = 0;

    foreach ($payments as $p) {
        if ($p['allocation_context'] !== null) {
            // Already categorized
            if ($p['allocation_context'] === 'ENROLLMENT_PRIORITY') $proposedEnrollment++;
            if ($p['allocation_context'] === 'SPECIFIC_ITEM') $proposedSpecific++;
            continue;
        }

        // Since SPECIFIC_ITEM did not exist, all historical payments were routed through priority
        $proposedEnrollment++;
    }

    $stmtHasItem = $pdo->query("SELECT COUNT(*) FROM payment_db.payments WHERE billing_item_id IS NOT NULL");
    $alreadyHasItem = $stmtHasItem->fetchColumn();

    echo "--- PROPOSED BACKFILL SUMMARY ---\n";
    echo "Total payments: $totalPayments\n\n";
    echo "Proposed backfill:\n";
    echo "ENROLLMENT_PRIORITY: $proposedEnrollment\n";
    echo "UNKNOWN / NEED REVIEW: $unknown\n\n";
    echo "Payments with billing_item_id: $alreadyHasItem\n";
    echo "---------------------------------\n\n";

    echo "Script paused. Please review the counts above in the console output.\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
