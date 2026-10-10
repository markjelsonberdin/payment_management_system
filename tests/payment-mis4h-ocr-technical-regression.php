<?php
declare(strict_types=1);

$root = dirname(__DIR__);
define('ROOT_PATH', $root);
require_once $root . '/modules/payment/includes/OcrTechnicalStatusService.php';
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
    $fingerprint = str_repeat('a', 64);
    $authenticated = (new OcrTechnicalStatusService($pdo))->load([
        'enabled'=>true,'project_id'=>'test-project','mode'=>'DOCUMENT_TEXT_DETECTION','monthly_limit'=>900,
        'last_test_authentication'=>'VERIFIED','last_diagnostic_config_fingerprint'=>$fingerprint,
    ], ['status'=>'CONFIGURED','readable'=>true,'protected_location'=>true,'project_matches'=>true,'configuration_fingerprint'=>$fingerprint]);
    $assert($authenticated['diagnostic']['authentication_state'] === 'VERIFIED', 'OAuth result is current only when its configuration fingerprint matches.');
    $assert($authenticated['diagnostic']['actual_ocr_processing'] === 'UNVERIFIED', 'OAuth success never claims actual OCR processing.');
    $assert($authenticated['readiness']['steps'][3]['state'] === 'pending', 'Vision OCR processing remains pending until an actual OCR test succeeds.');
    $assert($authenticated['readiness']['state'] !== 'READY', 'Authenticated configuration is not Ready without a live OCR test and all prerequisites.');
}

echo "Batch 4H OCR technical regression: {$passed} assertions passed.\n";
