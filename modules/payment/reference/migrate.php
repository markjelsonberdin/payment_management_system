<?php
require 'c:\xampp\htdocs\SMS2_system\modules\payment\database\db_connect.php';
try {
    $pdo->exec("ALTER TABLE ocr_results ADD COLUMN extraction_status VARCHAR(50) DEFAULT 'PROCESSING', ADD COLUMN extraction_notes TEXT NULL");
    echo "Migration Success\n";
} catch (Exception $e) {
    echo "Migration Error: " . $e->getMessage() . "\n";
}
