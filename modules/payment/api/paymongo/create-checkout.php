<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
http_response_code(410);
echo json_encode(['success'=>false,'error'=>'PAYMENT_METHOD_RETIRED','message'=>'New online payments use QR Ph only.']);
