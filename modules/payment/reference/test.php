<?php
require 'modules/payment/database/db_connect.php';
$stmt = $pdo->query("SHOW CREATE TABLE payment_concerns");
$schema = $stmt->fetch(PDO::FETCH_ASSOC);
echo $schema['Create Table'] . "\n\n";
