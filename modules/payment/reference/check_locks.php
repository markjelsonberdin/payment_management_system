<?php
$pdo = new PDO("mysql:host=127.0.0.1;port=3307;dbname=information_schema", "root", "");
$stmt = $pdo->query("SELECT * FROM INNODB_TRX");
$locks = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Active transactions:\n";
print_r($locks);

$stmt2 = $pdo->query("SHOW FULL PROCESSLIST");
$procs = $stmt2->fetchAll(PDO::FETCH_ASSOC);
echo "\nProcesslist:\n";
print_r($procs);
