<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/modules/payment/database/db_connect.php';
require_once __DIR__ . '/modules/payment/includes/paymongo/paymongo/PayMongoService.php';

$service = new PayMongoService();
$apiData = $service->testConnection();
echo json_encode($apiData['data'], JSON_PRETTY_PRINT);

