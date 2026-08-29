<?php
require 'c:\xampp\htdocs\SMS2_system\modules\payment\database\db_connect.php';
$stmt = $pdo->query('SHOW CREATE TABLE payment_concerns');
print_r($stmt->fetch(PDO::FETCH_ASSOC));
$stmt = $pdo->query('SHOW CREATE TABLE ocr_results');
print_r($stmt->fetch(PDO::FETCH_ASSOC));
