<?php require 'modules/payment/database/db_connect.php'; $stmt = $pdo->query('DESCRIBE sms2_db.activity_logs'); print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
