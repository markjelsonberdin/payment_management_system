<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/modules/payment/database/db_connect.php';
global $pdo;
$stmt = $pdo->query("SHOW TABLES IN payment_db LIKE 'bank_statements'");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
