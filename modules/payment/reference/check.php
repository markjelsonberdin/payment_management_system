<?php require 'modules/payment/database/db_connect.php'; $stmt = $pdo->query('SHOW TABLES IN sms2_db'); print_r($stmt->fetchAll(PDO::FETCH_COLUMN));
