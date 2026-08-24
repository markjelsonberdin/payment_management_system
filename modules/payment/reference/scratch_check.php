<?php
require 'c:/xampp/htdocs/SMS2_system/config/database.php';
$pdo = db();
$stmt = $pdo->query("SHOW COLUMNS FROM payment_gateway_settings");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
