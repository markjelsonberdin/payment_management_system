<?php
declare(strict_types=1);
require_once __DIR__.'/../../../../config/config.php';require_once ROOT_PATH.'/includes/authentication.php';require_once ROOT_PATH.'/modules/payment/database/db_connect.php';require_once ROOT_PATH.'/modules/payment/includes/AccountingOfficerDashboardService.php';
header('Content-Type: application/json; charset=utf-8');requireAuth();requirePaymentPermission('billing.individual.process');
try { echo json_encode((new AccountingOfficerDashboardService($pdo))->load($_GET),JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE); }
catch(InvalidArgumentException $e){http_response_code(422);echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);}
catch(Throwable $e){error_log('Officer dashboard API: '.$e->getMessage());http_response_code(500);echo json_encode(['ok'=>false,'error'=>'Operational dashboard is temporarily unavailable.']);}
