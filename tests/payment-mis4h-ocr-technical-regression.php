<?php
declare(strict_types=1);

$root = dirname(__DIR__);
define('ROOT_PATH', $root);
require_once $root . '/modules/payment/includes/OcrTechnicalStatusService.php';
require_once $root . '/modules/payment/includes/OcrConfigurationService.php';
require_once $root . '/modules/payment/includes/ocr/GoogleVisionOcrService.php';

$passed = 0;
$assert = static function (bool $condition, string $message) use (&$passed): void {
    if (!$condition) throw new RuntimeException($message);
    $passed++;
};

$endpoint = file_get_contents($root . '/modules/payment/api/mis_admin/ocr-test-connection.php');
$statusApi = file_get_contents($root . '/modules/payment/api/mis_admin/ocr-status.php');
$page = file_get_contents($root . '/modules/payment/pages/mis_admin/google-ocr-integration.php');
$legacy = file_get_contents($root . '/modules/payment/includes/ocr/GoogleOCRService.php');

$assert(str_contains($endpoint, "REQUEST_METHOD'] ?? 'GET') !== 'POST'"), 'Diagnostic must require POST.');
$assert(str_contains($endpoint, "verifyCsrfToken"), 'Diagnostic must enforce CSRF.');
$assert(str_contains($endpoint, "integration.ocr.manage"), 'Diagnostic must enforce canonical OCR permission.');
$assert(str_contains($endpoint, 'requireActiveMisActor'), 'Diagnostic must revalidate active MIS authority.');
$assert(str_contains($endpoint, 'nonBillableAuthenticationStatus'), 'Diagnostic must use non-billable authentication.');
$assert(!str_contains($endpoint, 'extractDocumentText('), 'Diagnostic must not call OCR extraction.');
$assert(!str_contains($endpoint, 'documentTextDetection('), 'Diagnostic must not call Vision document detection.');
$assert(str_contains($endpoint, "'provider_capability' => 'UNVERIFIED'"), 'Provider capability must remain unverified.');
$assert(str_contains($endpoint, "'actual_ocr_processing' => 'UNVERIFIED'"), 'Actual OCR processing must remain unverified.');
$assert(!str_contains($endpoint, 'access_token'), 'Endpoint source must not expose access tokens.');
$assert(str_contains($statusApi, 'OcrTechnicalStatusService'), 'Status API must use the technical monitoring service.');
$assert(str_contains($page, 'Run Authentication Diagnostic'), 'MIS page must expose the non-billable authentication diagnostic.');
$assert(str_contains($page, 'Non-billable Diagnostics'), 'MIS page must display non-billable diagnostics.');
$assert(str_contains($page, 'Usage &amp; Quota Monitoring'), 'MIS page must display usage and quota monitoring.');
$assert(str_contains($page, 'Integration Readiness') && str_contains($page, 'ocrReadinessSteps'), 'MIS page must render the shared readiness checklist.');
$assert(str_contains($statusApi, 'project_matches') && str_contains($statusApi, 'configuration_fingerprint'), 'Status API must compare project identity and current diagnostic configuration.');
$assert(str_contains($legacy, 'LEGACY_OCR_SERVICE_RETIRED'), 'Legacy OCR must fail closed by default.');

$oldCredentials = getenv('GOOGLE_APPLICATION_CREDENTIALS');
$oldProject = getenv('GOOGLE_CLOUD_PROJECT');
$credential = json_encode(['type'=>'service_account','project_id'=>'test-project','private_key'=>'not-a-real-key','client_email'=>'test@example.invalid']);
putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $credential);
putenv('GOOGLE_CLOUD_PROJECT=test-project');
$configuration = (new GoogleVisionOcrService())->configurationStatus();
$assert($configuration['status'] === 'CONFIGURED', 'Valid inline credential shape should be recognized without network access.');
$assert($configuration['protected_location'] === true, 'Inline credential should be treated as protected transport.');
$fingerprint = (new GoogleVisionOcrService())->configurationFingerprint('test-project', 'DOCUMENT_TEXT_DETECTION', 900);
$assert(is_string($fingerprint) && preg_match('/^[a-f0-9]{64}$/', $fingerprint) === 1, 'Configuration fingerprint is non-reversible and contains no credential payload.');
$assert($fingerprint === (new GoogleVisionOcrService())->configurationFingerprint('test-project', 'DOCUMENT_TEXT_DETECTION', 1, true), 'Fingerprint excludes quota and enabled switch, which do not change provider capability.');
$rotatedCredential = json_encode(['type'=>'service_account','project_id'=>'test-project','private_key'=>'not-a-real-key','client_email'=>'rotated@example.invalid','private_key_id'=>'rotated-key-id']);
putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $rotatedCredential);
$rotatedFingerprint = (new GoogleVisionOcrService())->configurationFingerprint('test-project', 'DOCUMENT_TEXT_DETECTION', 900);
$assert(is_string($rotatedFingerprint) && $rotatedFingerprint !== $fingerprint, 'Credential identity rotation invalidates prior OCR evidence.');
$otherProjectFingerprint = (new GoogleVisionOcrService())->configurationFingerprint('other-project', 'DOCUMENT_TEXT_DETECTION', 900);
$assert($otherProjectFingerprint !== $rotatedFingerprint, 'Project changes invalidate prior OCR evidence.');
$otherModeFingerprint = (new GoogleVisionOcrService())->configurationFingerprint('test-project', 'TEXT_DETECTION', 900);
$assert($otherModeFingerprint !== $rotatedFingerprint, 'Processing-mode changes invalidate prior OCR evidence.');
$oldCredentials === false ? putenv('GOOGLE_APPLICATION_CREDENTIALS') : putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $oldCredentials);
$oldProject === false ? putenv('GOOGLE_CLOUD_PROJECT') : putenv('GOOGLE_CLOUD_PROJECT=' . $oldProject);

if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE ocr_usage_months (billing_month TEXT PRIMARY KEY, configured_limit INTEGER, reserved_units INTEGER, consumed_units INTEGER, successful_units INTEGER, failed_units INTEGER)');
    $pdo->exec('CREATE TABLE ocr_usage_ledger (billing_month TEXT, lifecycle_state TEXT, provider_called INTEGER, failure_category TEXT, reserved_at TEXT, provider_called_at TEXT, completed_at TEXT)');
    $month = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-01');
    $stmt = $pdo->prepare('INSERT INTO ocr_usage_months VALUES (?,900,2,5,4,1)');
    $stmt->execute([$month]);
    $old = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->modify('-30 minutes')->format('Y-m-d H:i:s');
    $stmt = $pdo->prepare('INSERT INTO ocr_usage_ledger VALUES (?,?,?,?,?,?,?)');
    $stmt->execute([$month,'CONSUMED',1,null,$old,$old,$old]);
    $stmt->execute([$month,'CACHE_HIT',0,null,$old,null,$old]);
    $stmt->execute([$month,'FAILED',1,'TIMEOUT',$old,$old,$old]);
    $stmt->execute([$month,'RESERVED',0,null,$old,null,null]);
    $data = (new OcrTechnicalStatusService($pdo))->load(['monthly_limit'=>900,'mode'=>'DOCUMENT_TEXT_DETECTION'], ['status'=>'CONFIGURED','protected_location'=>true]);
    $assert($data['usage']['consumed'] === 5, 'Consumed units must come from the monthly record.');
    $assert($data['usage']['reserved'] === 2, 'Reserved units must come from the monthly record.');
    $assert($data['usage']['remaining'] === 893, 'Remaining capacity must subtract consumed and reserved units.');
    $assert($data['usage']['provider_attempts'] === 2, 'Provider attempts must use provider_called state.');
    $assert($data['usage']['cache_hits'] === 1, 'Cache hits must be counted separately.');
    $assert($data['stale_incomplete'] === 1, 'Stale reservations must be visible.');
    $readinessFingerprint = str_repeat('a', 64);
    $authenticated = (new OcrTechnicalStatusService($pdo))->load([
        'enabled'=>true,'project_id'=>'test-project','mode'=>'DOCUMENT_TEXT_DETECTION','monthly_limit'=>900,
        'last_test_authentication'=>'VERIFIED','last_diagnostic_config_fingerprint'=>$readinessFingerprint,
    ], ['status'=>'CONFIGURED','readable'=>true,'protected_location'=>true,'project_matches'=>true,'configuration_fingerprint'=>$readinessFingerprint]);
    $assert($authenticated['diagnostic']['authentication_state'] === 'VERIFIED', 'OAuth result is current only when its configuration fingerprint matches.');
    $assert($authenticated['diagnostic']['actual_ocr_processing'] === 'UNVERIFIED', 'OAuth success never claims actual OCR processing.');
    $assert($authenticated['readiness']['steps'][3]['state'] === 'pending', 'Vision OCR processing remains pending until an actual OCR test succeeds.');
    $assert($authenticated['readiness']['state'] !== 'READY', 'Authenticated configuration is not Ready without a live OCR test and all prerequisites.');

    $tables=['payment_concerns','ocr_results','ocr_scan_attempts','ocr_image_cache'];
    foreach($tables as $table)$pdo->exec('CREATE TABLE '.$table.' (placeholder TEXT)');
    $receiptRoot=sys_get_temp_dir().DIRECTORY_SEPARATOR.'sms2-ocr-readiness-'.bin2hex(random_bytes(6));
    mkdir($receiptRoot,0750,true);
    $oldReceiptRoot=getenv('PAYMENT_PRIVATE_RECEIPT_ROOT'); putenv('PAYMENT_PRIVATE_RECEIPT_ROOT='.$receiptRoot);
    $pdo->exec('CREATE TABLE payment_gateway_settings (setting_key TEXT PRIMARY KEY,setting_value TEXT,description TEXT)');
    $readyConfig=['enabled'=>true,'project_id'=>'test-project','mode'=>'DOCUMENT_TEXT_DETECTION','monthly_limit'=>900,
        'last_test_authentication'=>'VERIFIED','last_diagnostic_config_fingerprint'=>$readinessFingerprint];
    $readyCredential=['status'=>'CONFIGURED','readable'=>true,'protected_location'=>true,'project_matches'=>true,'configuration_fingerprint'=>$readinessFingerprint];
    $readyService=new OcrTechnicalStatusService($pdo);
    $missingEvidence=$readyService->load($readyConfig,$readyCredential);
    $assert($missingEvidence['readiness']['steps'][3]['state']==='pending' && $missingEvidence['readiness']['state']!=='READY','Missing live OCR evidence cannot report Ready.');
    $failedEvidence=$readyService->load($readyConfig+['last_failed_test'=>'2026-10-10T00:00:00Z'],$readyCredential);
    $assert($failedEvidence['readiness']['steps'][3]['state']==='pending' && $failedEvidence['readiness']['state']!=='READY','Failed provider attempt without success evidence cannot report Ready.');
    $staleConfig=array_merge($readyConfig,['last_ocr_successful_test'=>'2026-10-10T00:00:00Z','last_ocr_test_config_fingerprint'=>str_repeat('b',64)]);
    $staleEvidence=$readyService->load($staleConfig,$readyCredential);
    $assert($staleEvidence['readiness']['steps'][3]['state']==='pending' && $staleEvidence['readiness']['state']==='DEGRADED','Stale success evidence is visibly degraded.');
    $successConfig=array_merge($readyConfig,['last_ocr_successful_test'=>'2026-10-10T00:00:00Z','last_ocr_test_config_fingerprint'=>$readinessFingerprint]);
    $successEvidence=$readyService->load($successConfig,$readyCredential);
    $assert($successEvidence['readiness']['steps'][3]['state']==='verified' && $successEvidence['readiness']['state']==='READY','Matching successful provider evidence plus enabled scanning and all prerequisites reports Ready.');
    $rotatedEvidence=$readyService->load($successConfig,['status'=>'CONFIGURED','readable'=>true,'protected_location'=>true,'project_matches'=>true,'configuration_fingerprint'=>$rotatedFingerprint]);
    $assert($rotatedEvidence['readiness']['steps'][3]['state']==='pending' && $rotatedEvidence['readiness']['state']==='DEGRADED','Credential identity rotation makes the previously successful OCR evidence stale.');
    $disabledEvidence=$readyService->load(array_merge($successConfig,['enabled'=>false]),$readyCredential);
    $assert($disabledEvidence['readiness']['state']==='AUTHENTICATED','Disabling scans does not stale provider evidence, but prevents Ready until scanning is enabled.');

    $pdo->exec('CREATE TABLE payment_audit_outbox (audit_outbox_id INTEGER PRIMARY KEY AUTOINCREMENT,correlation_id TEXT UNIQUE,request_fingerprint TEXT,action TEXT,module_key TEXT,entity_type TEXT,entity_id INTEGER,detail TEXT,before_state TEXT,after_state TEXT,committed_result TEXT,actor_user_id INTEGER,actor_user_name TEXT,actor_role_key TEXT,actor_ip_address TEXT,actor_user_agent TEXT,delivery_status TEXT,attempt_count INTEGER,next_attempt_at TEXT,last_attempt_at TEXT,delivered_at TEXT,core_activity_log_id INTEGER,last_error TEXT,created_at TEXT,updated_at TEXT)');
    $settings=$pdo->prepare('INSERT INTO payment_gateway_settings (setting_key,setting_value,description) VALUES (?,?,?)');
    foreach([['ocr_enabled','1','test'],['ocr_project_id','test-project','test'],['ocr_mode','DOCUMENT_TEXT_DETECTION','test'],['ocr_monthly_limit','900','test']] as $setting)$settings->execute($setting);
    $pdo->exec('DROP TABLE ocr_usage_ledger');
    $pdo->exec('CREATE TABLE ocr_usage_ledger (request_id TEXT,provider TEXT,feature TEXT,provider_called INTEGER,lifecycle_state TEXT,completed_at TEXT)');
    $pdo->exec('DROP TABLE ocr_scan_attempts');
    $pdo->exec('CREATE TABLE ocr_scan_attempts (request_id TEXT,extraction_status TEXT,completed_at TEXT)');
    $mutationInfra=new SchoolSalesCatalogMutationInfrastructure($pdo,new PaymentAuditOutboxService($pdo));
    $configurationService=new OcrConfigurationService($pdo,$mutationInfra);
    $actor=['id'=>12,'name'=>'Test Accounting','role'=>'accounting_officer'];
    $originalCredential=getenv('GOOGLE_APPLICATION_CREDENTIALS');$originalProject=getenv('GOOGLE_CLOUD_PROJECT');
    putenv('GOOGLE_APPLICATION_CREDENTIALS='.json_encode(['type'=>'service_account','project_id'=>'test-project','private_key'=>'not-a-real-key','client_email'=>'test@example.invalid']));
    putenv('GOOGLE_CLOUD_PROJECT=test-project');
    $providerRequestId=CatalogCorrelationId::generate();
    try{$configurationService->recordSuccessfulOcrEvidence(CatalogCorrelationId::generate(),$actor,$providerRequestId,$fingerprint);$assert(false,'success evidence without a completed provider ledger row must be rejected');}
    catch(DomainException $e){$assert($e->getMessage()==='OCR_SUCCESS_PROVIDER_EVIDENCE_REQUIRED','No marker can be recorded without completed successful provider evidence.');}
    $pdo->prepare('INSERT INTO ocr_usage_ledger VALUES (?,?,?,?,?,?)')->execute([$providerRequestId,'google_cloud_vision','DOCUMENT_TEXT_DETECTION',1,'SUCCEEDED',gmdate('Y-m-d H:i:s')]);
    $pdo->prepare('INSERT INTO ocr_scan_attempts VALUES (?,?,?)')->execute([$providerRequestId,'SUCCEEDED',gmdate('Y-m-d H:i:s')]);
    $recorded=$configurationService->recordSuccessfulOcrEvidence(CatalogCorrelationId::generate(),$actor,$providerRequestId,$fingerprint);
    $stored=$configurationService->get();
    $assert($stored['last_ocr_successful_test']!==null && $stored['last_ocr_test_config_fingerprint']===$fingerprint,'Successful actual-processing evidence persists timestamp and non-secret fingerprint.');
    $assert(($recorded['audit_status']??'')==='pending' && (int)$pdo->query("SELECT COUNT(*) FROM payment_audit_outbox WHERE action='OCR_PROVIDER_PROCESSING_VERIFIED'")->fetchColumn()===1,'Successful-processing evidence is persisted through the audited mutation outbox.');
    $auditState=(string)$pdo->query("SELECT after_state FROM payment_audit_outbox WHERE action='OCR_PROVIDER_PROCESSING_VERIFIED'")->fetchColumn();
    $assert(!str_contains($auditState,'private_key') && !str_contains($auditState,'client_email'),'Audit metadata contains no credential identity or secret values.');
    try{$configurationService->recordSuccessfulOcrEvidence(CatalogCorrelationId::generate(),$actor,$providerRequestId,str_repeat('b',64));$assert(false,'stale observed configuration must be rejected');}
    catch(DomainException $e){$assert($e->getMessage()==='OCR_SUCCESS_CONFIGURATION_CHANGED','A stale observed configuration cannot be recorded as successful.');}
    $pdo->prepare("UPDATE payment_gateway_settings SET setting_value='0' WHERE setting_key='ocr_enabled'")->execute();
    try{$configurationService->recordSuccessfulOcrEvidence(CatalogCorrelationId::generate(),$actor,$providerRequestId,$fingerprint);$assert(false,'disabled scanning must not record a success marker');}
    catch(DomainException $e){$assert($e->getMessage()==='OCR_SUCCESS_CONFIGURATION_CHANGED','Scanning-disabled state cannot receive a success marker.');}
    $originalCredential===false?putenv('GOOGLE_APPLICATION_CREDENTIALS'):putenv('GOOGLE_APPLICATION_CREDENTIALS='.$originalCredential);
    $originalProject===false?putenv('GOOGLE_CLOUD_PROJECT'):putenv('GOOGLE_CLOUD_PROJECT='.$originalProject);
    $oldReceiptRoot===false?putenv('PAYMENT_PRIVATE_RECEIPT_ROOT'):putenv('PAYMENT_PRIVATE_RECEIPT_ROOT='.$oldReceiptRoot);
    @rmdir($receiptRoot);
}

echo "Batch 4H OCR technical regression: {$passed} assertions passed.\n";
