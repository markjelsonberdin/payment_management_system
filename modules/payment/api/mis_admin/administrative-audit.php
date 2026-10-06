<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once ROOT_PATH . '/modules/payment/includes/PaymentAdministrativeAuditService.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function administrativeAuditRespond(array $body,int $status=200):never
{ http_response_code($status);echo json_encode($body,JSON_INVALID_UTF8_SUBSTITUTE);exit; }

if(!isAuthenticated())administrativeAuditRespond(['ok'=>false,'error'=>'AUTHENTICATION_REQUIRED','message'=>'Authentication is required.'],401);
if(!paymentRoleAllowsPermission(getCurrentUserRoleKey(),'payment.audit.view')||!userCanAccessModule('payment.audit.view')){
    administrativeAuditRespond(['ok'=>false,'error'=>'NOT_AUTHORIZED','message'=>'You are not authorized to view Payment administrative audit records.'],403);
}
requireAuth();
requirePaymentPermission('payment.audit.view');

try{
    if(strtoupper((string)($_SERVER['REQUEST_METHOD']??''))!=='GET'){
        header('Allow: GET');administrativeAuditRespond(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED','message'=>'Use GET.'],405);
    }
    $pdo=db();if(!$pdo)throw new RuntimeException('Core database unavailable.');
    $actor=$pdo->prepare('SELECT role_key,status FROM users WHERE id=? LIMIT 1');$actor->execute([getCurrentUserId()]);$actorRow=$actor->fetch(PDO::FETCH_ASSOC);
    if(!$actorRow||$actorRow['status']!=='active'||$actorRow['role_key']!=='mis_admin'||$actorRow['role_key']!==getCurrentUserRoleKey()){
        administrativeAuditRespond(['ok'=>false,'error'=>'ACTOR_SESSION_STALE','message'=>'Your account authority changed. Sign in again.'],403);
    }
    $service=new PaymentAdministrativeAuditService($pdo);$action=(string)($_GET['action']??'events');$input=$_GET;unset($input['action']);
    if($action==='summary'&&$input!==[])throw new InvalidArgumentException('Summary does not accept filters.');
    if($action==='event_detail'&&array_diff(array_keys($input),['id'])!==[])throw new InvalidArgumentException('Unsupported event-detail input.');
    if($action==='correlation'&&array_diff(array_keys($input),['correlation_id'])!==[])throw new InvalidArgumentException('Unsupported correlation input.');
    $data=match($action){
        'summary'=>$service->summary(),
        'events'=>$service->events($input),
        'event_detail'=>$service->detail(filter_var($input['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0),
        'correlation'=>$service->correlation(strtolower(trim((string)($input['correlation_id']??'')))),
        default=>throw new InvalidArgumentException('Invalid audit action.'),
    };
    administrativeAuditRespond(['ok'=>true,'data'=>$data]);
}catch(DomainException $e){administrativeAuditRespond(['ok'=>false,'error'=>'AUDIT_EVENT_NOT_FOUND','message'=>'The audit event is unavailable.'],404);
}catch(InvalidArgumentException $e){administrativeAuditRespond(['ok'=>false,'error'=>'INVALID_AUDIT_QUERY','message'=>$e->getMessage()],422);
}catch(Throwable $e){error_log('Payment administrative audit failed: '.get_class($e));administrativeAuditRespond(['ok'=>false,'error'=>'AUDIT_UNAVAILABLE','message'=>'Administrative audit is temporarily unavailable.'],500);}
