<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once ROOT_PATH . '/includes/security.php';
require_once ROOT_PATH . '/includes/audit.php';
require_once ROOT_PATH . '/vendor/autoload.php';
require_once ROOT_PATH . '/modules/payment/database/db_connect.php';
require_once ROOT_PATH . '/modules/payment/includes/PaymentSecurityService.php';
require_once ROOT_PATH . '/modules/payment/includes/ocr/ReceiptOcrProcessor.php';
require_once ROOT_PATH . '/modules/payment/includes/ocr/OcrStructuredAuditService.php';

header('Content-Type: application/json');
requireAuth();
requirePaymentPermission('payment.concern_review');

function ocrRespond(array $payload,int $status=200):never{http_response_code($status);echo json_encode($payload,JSON_UNESCAPED_SLASHES);exit;}

if($_SERVER['REQUEST_METHOD']!=='POST')ocrRespond(['success'=>false,'error'=>'METHOD_NOT_ALLOWED'],405);
$input=json_decode((string)file_get_contents('php://input'),true);
if(!is_array($input))ocrRespond(['success'=>false,'error'=>'INVALID_JSON'],400);
if(!verifyCsrfToken((string)($input['csrf_token']??'')))ocrRespond(['success'=>false,'error'=>'CSRF_FAILED','message'=>'Invalid security token.'],403);
$concernId=filter_var($input['concern_id']??null,FILTER_VALIDATE_INT);
if($concernId===false||$concernId<1)ocrRespond(['success'=>false,'error'=>'MISSING_ID'],400);
$controlledRetry=($input['retry']??false)===true;
$actorId=(int)getCurrentUserId();
$corePdo=db();
$auditService=new OcrStructuredAuditService(new PaymentAuditOutboxService($pdo,$corePdo?new StructuredActivityAuditWriter($corePdo):null));

try{
    global $pdo;
    (new PaymentSecurityService($pdo))->ensurePaymentAccess($actorId,getCurrentUserRoleKey(),'payment.concern_review',(int)$concernId,'concern');
    $rateKey='ocr_limit_'.$actorId.'_'.$concernId;
    $now=time();
    $_SESSION[$rateKey]=array_values(array_filter($_SESSION[$rateKey]??[],static fn($stamp)=>$now-(int)$stamp<60));
    if(count($_SESSION[$rateKey])>=5)ocrRespond(['success'=>false,'error'=>'RATE_LIMIT_EXCEEDED','message'=>'Too many OCR requests. Please wait one minute.'],429);
    $_SESSION[$rateKey][]=$now;

    $processor=new ReceiptOcrProcessor($pdo,new GoogleVisionOcrService(),new PrivateReceiptStorageService(),new OcrUsageGuardService($pdo),new ReceiptParserService(),new ReceiptEvidenceService($pdo));
    $result=$processor->scan((int)$concernId,$actorId,$controlledRetry);
    $audit=$auditService->record((int)$concernId,$actorId,(string)getCurrentUserName(),(string)getCurrentUserRoleKey(),$result);
    $result['audit_status']=strtolower($audit);
    logActivity('receipt_ocr_review_evidence',sprintf('OCR evidence request for concern #%d: %s; request=%s; indicators=%d',(int)$concernId,$result['success']?'completed':'not_completed',(string)($result['request_id']??'none'),count($result['review_indicators']??[])),'payment',$actorId);
    $failureStatus=($result['error']??'')==='OCR_MONTHLY_LIMIT_REACHED'?429:409;
    ocrRespond($result,$result['success']?200:$failureStatus);
}catch(DomainException|ReceiptStorageException $e){
    $category=$e->getMessage();
    $status=in_array($category,['OCR_RECEIPT_NOT_FOUND','RECEIPT_NOT_FOUND'],true)?404:(in_array($category,['OCR_DISABLED','OCR_PROVIDER_NOT_CONFIGURED','PRIVATE_RECEIPT_STORAGE_NOT_CONFIGURED','PRIVATE_RECEIPT_STORAGE_UNAVAILABLE'],true)?503:409);
    logActivity('receipt_ocr_review_evidence_failed','OCR evidence request failed for concern #'.(int)$concernId.'; category='.$category,'payment',$actorId);
    try{$auditService->recordFailure((int)$concernId,$actorId,(string)getCurrentUserName(),(string)getCurrentUserRoleKey(),$category);}catch(Throwable){}
    ocrRespond(['success'=>false,'error'=>$category,'message'=>'OCR evidence is unavailable. The receipt remains available for manual review.'],$status);
}catch(GoogleVisionOcrException $e){
    logActivity('receipt_ocr_provider_failed','OCR provider request failed for concern #'.(int)$concernId.'; category='.$e->category,'payment',$actorId);
    try{$auditService->recordFailure((int)$concernId,$actorId,(string)getCurrentUserName(),(string)getCurrentUserRoleKey(),$e->category);}catch(Throwable){}
    ocrRespond(['success'=>false,'error'=>$e->category,'message'=>'Google OCR could not complete. No automatic decision was made.'],502);
}catch(Throwable $e){
    error_log('Receipt OCR failed category=internal concern='.(int)$concernId);
    logActivity('receipt_ocr_review_evidence_failed','OCR evidence request failed for concern #'.(int)$concernId.'; category=INTERNAL_ERROR','payment',$actorId);
    try{$auditService->recordFailure((int)$concernId,$actorId,(string)getCurrentUserName(),(string)getCurrentUserRoleKey(),'OCR_INTERNAL_ERROR');}catch(Throwable){}
    ocrRespond(['success'=>false,'error'=>'OCR_INTERNAL_ERROR','message'=>'OCR could not complete. The receipt remains available for manual review.'],500);
}
