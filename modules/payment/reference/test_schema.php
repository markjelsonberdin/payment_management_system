<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/modules/payment/database/db_connect.php';
global $pdo;
$stmt1 = $pdo->query("DESCRIBE payment_db.payment_concerns");
$stmt2 = $pdo->query("DESCRIBE payment_db.payments");
echo "=== payment_concerns ===\n";
print_r($stmt1->fetchAll(PDO::FETCH_ASSOC));
echo "\n=== payments ===\n";
print_r($stmt2->fetchAll(PDO::FETCH_ASSOC));
