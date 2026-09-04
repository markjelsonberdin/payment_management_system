<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/modules/payment/database/db_connect.php';
$paymongoConfig = require __DIR__ . '/modules/payment/config/paymongo.php';

$url = 'https://api.paymongo.com/v1/webhooks/hook_fBda74w2mgBHkNMXApGeJhpM/enable';
$secretKey = $paymongoConfig['secret_key'];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/json',
    'Content-Type: application/json',
    'Authorization: Basic ' . base64_encode($secretKey . ':')
]);

$response = curl_exec($ch);
curl_close($ch);

echo $response;
