<?php
require_once __DIR__ . '/config/config.php';
require_once ROOT_PATH . '/modules/payment/database/db_connect.php';
$stmt = $pdo->query("DESCRIBE payments");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
