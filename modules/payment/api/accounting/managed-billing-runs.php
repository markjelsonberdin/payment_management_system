<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
requireAuth();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../database/db_connect.php';
require_once __DIR__ . '/../../includes/ManagedBillingRunService.php';

function managedRunRespond(array $body, int $status=200): never { http_response_code($status); echo json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE); exit; }
function managedRunInput(): array { $raw=(string)file_get_contents('php://input'); if($raw==='') return $_POST; $value=json_decode($raw,true,512,JSON_THROW_ON_ERROR); if(!is_array($value)) throw new InvalidArgumentException('Request must be an object.'); return $value; }
function managedRunCanApprove(): bool { return paymentEffectivePermission(getCurrentUserRoleKey(), 'billing.bulk.approve'); }
function managedRunCanCreateOrProcess(): bool { return paymentEffectivePermission(getCurrentUserRoleKey(), 'billing.bulk.process'); }
try {
 if(strtoupper((string)$_SERVER['REQUEST_METHOD'])!=='POST') managedRunRespond(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED','message'=>'Use POST.'],405);
 $input=managedRunInput(); $csrf=(string)($_SERVER['HTTP_X_CSRF_TOKEN']??$input['csrf_token']??''); if(!verifyCsrfToken($csrf)) managedRunRespond(['ok'=>false,'error'=>'CSRF_INVALID','message'=>'Your security token is invalid or expired.'],403);
 $service=new ManagedBillingRunService($pdo); $action=(string)($input['action']??''); $actor=(int)(getCurrentUserId()??0);
 if ($action === 'approve' && !paymentEffectivePermission(getCurrentUserRoleKey(), 'billing.bulk.approve')) managedRunRespond(['ok'=>false,'error'=>'MANAGED_BULK_APPROVAL_FORBIDDEN','message'=>'Accounting Admin approval is required.'],403);
 elseif ($action === 'preview' && !paymentEffectivePermission(getCurrentUserRoleKey(), 'billing.bulk.preview')) managedRunRespond(['ok'=>false,'error'=>'MANAGED_BULK_OPERATOR_FORBIDDEN','message'=>'Accounting Officer access is required.'],403);
 elseif ($action === 'create_draft' && !paymentEffectivePermission(getCurrentUserRoleKey(), 'billing.bulk.create')) managedRunRespond(['ok'=>false,'error'=>'MANAGED_BULK_OPERATOR_FORBIDDEN','message'=>'Accounting Officer access is required.'],403);
 elseif ($action === 'process_chunk' && !paymentEffectivePermission(getCurrentUserRoleKey(), 'billing.bulk.process')) managedRunRespond(['ok'=>false,'error'=>'MANAGED_BULK_OPERATOR_FORBIDDEN','message'=>'Accounting Officer access is required.'],403);
 elseif ($action === 'retry' && !paymentEffectivePermission(getCurrentUserRoleKey(), 'billing.bulk.retry')) managedRunRespond(['ok'=>false,'error'=>'MANAGED_BULK_OPERATOR_FORBIDDEN','message'=>'Accounting Officer access is required.'],403);
 elseif (in_array($action,['detail','list_runs','cohort_bootstrap','cohort_preview'],true) && !paymentEffectivePermission(getCurrentUserRoleKey(), 'billing.bulk.view')) managedRunRespond(['ok'=>false,'error'=>'MANAGED_BULK_DETAIL_FORBIDDEN','message'=>'Managed bulk run access is required.'],403);
 $data=match($action) {
  'preview' => $service->preview($input),
  'create_draft' => $service->createDraft($input,$actor,getCurrentUserName()),
  'approve' => $service->approve((int)($input['run_id']??0),$actor,getCurrentUserName()),
  'process_chunk' => $service->processChunk((int)($input['run_id']??0),$actor,(int)($input['batch_size']??50)),
  'retry' => $service->retry((int)($input['run_id']??0)),
  'detail' => $service->detail((int)($input['run_id']??0),(int)($input['assignment_page']??1),(int)($input['assignment_page_size']??25),(string)($input['assignment_student_search']??''),(string)($input['assignment_status']??'')),
  'list_runs' => $service->listRuns($input),
  'cohort_bootstrap' => $service->cohortBootstrap(),
  'cohort_preview' => $service->cohortPreview($input),
  default => throw new ManagedBillingRunException('UNKNOWN_ACTION','Unknown managed bulk action.',404),
 };
 if(in_array($action,['create_draft','approve','process_chunk','retry'],true)) logActivity('managed_bulk_'.$action,'Managed bulk run action '.$action,'payment');
 managedRunRespond(['ok'=>true,'data'=>$data,'development_context_enabled'=>BillingStudentContextProviderFactory::developmentEnabled(),'csrf_token'=>generateCsrfToken()]);
} catch(AcademicContextUnavailableException $e) { managedRunRespond(['ok'=>false,'error'=>'ACADEMIC_CONTEXT_UNAVAILABLE','message'=>'Managed bulk billing requires an approved development/test context.'],409); }
catch(RegistrarCohortException $e) { managedRunRespond(['ok'=>false,'error'=>$e->errorCode,'message'=>$e->getMessage()],409); }
catch(ManagedBillingRunException $e) { managedRunRespond(['ok'=>false,'error'=>$e->errorCode,'message'=>$e->getMessage()],$e->httpStatus); }
catch(InvalidArgumentException|JsonException $e) { managedRunRespond(['ok'=>false,'error'=>'VALIDATION_FAILED','message'=>'Invalid managed bulk request.'],422); }
catch(Throwable $e) { error_log('Managed bulk API failure: '.$e->getMessage()); managedRunRespond(['ok'=>false,'error'=>'MANAGED_BULK_UNAVAILABLE','message'=>'Managed bulk billing is temporarily unavailable.'],500); }

