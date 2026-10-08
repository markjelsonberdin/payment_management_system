<?php
declare(strict_types=1);
require_once __DIR__.'/../../../../config/config.php';require_once ROOT_PATH.'/includes/authentication.php';require_once ROOT_PATH.'/modules/payment/includes/PaymentPermissionMatrixService.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
function permissionMatrixRespond(array$b,int$s=200):never{http_response_code($s);echo json_encode($b,JSON_INVALID_UTF8_SUBSTITUTE);exit;}
if(!isAuthenticated())permissionMatrixRespond(['ok'=>false,'error'=>'AUTHENTICATION_REQUIRED','message'=>'Authentication is required.'],401);
if(!paymentRoleAllowsPermission(getCurrentUserRoleKey(),'payment.permissions.manage')||!userCanAccessModule('payment.permissions.manage'))permissionMatrixRespond(['ok'=>false,'error'=>'NOT_AUTHORIZED','message'=>'You are not authorized to view Payment permissions.'],403);
requireAuth();requirePaymentPermission('payment.permissions.manage');
try{if(strtoupper((string)($_SERVER['REQUEST_METHOD']??''))!=='GET'){header('Allow: GET');permissionMatrixRespond(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED','message'=>'Use GET.'],405);}$core=db();if(!$core)throw new RuntimeException('Core unavailable');$q=$core->prepare('SELECT role_key,status FROM users WHERE id=? LIMIT 1');$q->execute([getCurrentUserId()]);$actor=$q->fetch(PDO::FETCH_ASSOC);if(!$actor||$actor['status']!=='active'||$actor['role_key']!=='mis_admin'||$actor['role_key']!==getCurrentUserRoleKey())permissionMatrixRespond(['ok'=>false,'error'=>'ACTOR_SESSION_STALE','message'=>'Your account authority changed. Sign in again.'],403);permissionMatrixRespond(['ok'=>true,'data'=>(new PaymentPermissionMatrixService($core))->load()]);}
catch(Throwable$e){error_log('Permission matrix unavailable: '.get_class($e));permissionMatrixRespond(['ok'=>false,'error'=>'PERMISSION_MATRIX_UNAVAILABLE','message'=>'Permission information is temporarily unavailable.'],500);}
