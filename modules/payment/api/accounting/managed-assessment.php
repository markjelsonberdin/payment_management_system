<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
requireAuth(); requirePaymentPermission('billing.individual.process');
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../database/db_connect.php';
require_once __DIR__ . '/../../includes/BillingStudentContextProvider.php';
require_once __DIR__ . '/../../includes/ManagedStandardAssessmentService.php';
require_once __DIR__ . '/../../includes/ManagedStandardAssessmentWriter.php';
function managedRespond(array $body,int $status=200): never { http_response_code($status); echo json_encode($body,JSON_INVALID_UTF8_SUBSTITUTE); exit; }
try {
 if(strtoupper((string)$_SERVER['REQUEST_METHOD'])!=='POST') managedRespond(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED','message'=>'Use POST.'],405);
 $raw=(string)file_get_contents('php://input'); $input=$raw!==''?json_decode($raw,true,512,JSON_THROW_ON_ERROR):$_POST; if(!is_array($input)) throw new InvalidArgumentException('Request must be an object.');
 $csrf=(string)($_SERVER['HTTP_X_CSRF_TOKEN']??$input['csrf_token']??''); if(!verifyCsrfToken($csrf)) managedRespond(['ok'=>false,'error'=>'CSRF_INVALID','message'=>'Your security token is invalid or expired.'],403);
 $action=(string)($input['action']??''); if(!in_array($action,['preview','commit'],true)) managedRespond(['ok'=>false,'error'=>'UNKNOWN_ACTION','message'=>'Unknown managed assessment action.'],404);
 $provider=BillingStudentContextProviderFactory::current(); $context=$provider->resolve(is_array($input['context']??null)?$input['context']:[]); $selection=is_array($input['selected_reviewable_fee_version_ids']??null)?$input['selected_reviewable_fee_version_ids']:[];
 $service=new ManagedStandardAssessmentService($pdo);
 $selection=$service->validatedReviewableSelection($context,$selection);
 if($action==='preview') $data=$service->preview($context,$selection)->toArray();
 else { $data=(new ManagedStandardAssessmentWriter($pdo,$service))->commitStudentAssessment($context,$selection,(int)getCurrentUserId()); logActivity('managed_assessment_commit','Managed assessment '.$data['outcome'].' for student #'.$context->studentId,'payment'); }
 managedRespond(['ok'=>true,'data'=>$data,'development_context_enabled'=>BillingStudentContextProviderFactory::developmentEnabled(),'csrf_token'=>generateCsrfToken()]);
} catch (AcademicContextUnavailableException $e) { managedRespond(['ok'=>false,'error'=>'ACADEMIC_CONTEXT_UNAVAILABLE','message'=>'Managed Billing requires an authoritative academic context.'],409); }
catch (ManagedAssessmentCommitException $e) { managedRespond(['ok'=>false,'error'=>$e->errorCode,'message'=>$e->getMessage()],$e->httpStatus); }
catch (InvalidArgumentException|JsonException $e) { managedRespond(['ok'=>false,'error'=>'VALIDATION_FAILED','message'=>'Invalid managed assessment request.'],422); }
catch (Throwable $e) { error_log('Managed assessment API failure: '.$e->getMessage()); managedRespond(['ok'=>false,'error'=>'MANAGED_ASSESSMENT_UNAVAILABLE','message'=>'Managed assessment is temporarily unavailable.'],500); }
