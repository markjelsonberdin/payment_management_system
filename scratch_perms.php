<?php
require 'c:/xampp/htdocs/SMS2_system/config/database.php';
$pdo = db();

try {
    // Revoke all payment permissions from superadmin and admin
    $pdo->exec("DELETE FROM sms2_db.role_permissions WHERE role_key IN ('superadmin', 'admin') AND module_key LIKE 'payment.%'");
    
    echo "Payment permissions removed from superadmin and admin roles.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
