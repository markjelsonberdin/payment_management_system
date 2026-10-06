<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') throw new RuntimeException('CLI only');
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/modules/payment/includes/ocr/GoogleVisionOcrService.php';

$checks = 0;
function checkOcr(bool $condition, string $message): void {
    global $checks;
    $checks++;
    if (!$condition) throw new RuntimeException($message);
}

$service = new GoogleVisionOcrService();
putenv('GOOGLE_APPLICATION_CREDENTIALS'); putenv('GOOGLE_CLOUD_PROJECT');
$status = $service->configurationStatus();
checkOcr($status['status'] === 'NOT_CONFIGURED', 'Missing credentials fail closed');
checkOcr(!array_key_exists('path', $status), 'Credential path is never exposed');

$inside = ROOT_PATH . '/modules/payment/google-credentials.json';
putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $inside); putenv('GOOGLE_CLOUD_PROJECT=bcp-payment-management-system');
$status = $service->configurationStatus();
checkOcr($status['status'] === 'NOT_CONFIGURED' && $status['readable'] === false, 'Credential paths inside the public application are rejected');

$outside = tempnam(sys_get_temp_dir(), 'sms2-ocr-');
if ($outside === false) throw new RuntimeException('Temporary file unavailable');
try {
    putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $outside);
    $status = $service->configurationStatus();
    checkOcr($status['status'] === 'CONFIGURED' && $status['readable'], 'Protected external credential path is accepted');
} finally { @unlink($outside); }

$inline = json_encode([
    'type' => 'service_account',
    'project_id' => 'bcp-payment-management-system',
    'private_key' => "-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----\n",
    'client_email' => 'sms2-google-ocr@bcp-payment-management-system.iam.gserviceaccount.com',
], JSON_THROW_ON_ERROR);
putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $inline);
$status = $service->configurationStatus();
checkOcr($status['status'] === 'CONFIGURED' && $status['readable'], 'Masked raw JSON secret is accepted without exposing its contents');
putenv('GOOGLE_APPLICATION_CREDENTIALS={invalid-json');
$status = $service->configurationStatus();
checkOcr($status['status'] === 'NOT_CONFIGURED', 'Malformed inline credentials fail closed');

$page = file_get_contents(ROOT_PATH . '/modules/payment/pages/mis_admin/google-ocr-integration.php');
$env = file_get_contents(ROOT_PATH . '/modules/payment/.env.example');
checkOcr(str_contains($page, "requirePaymentPermission('integration.ocr.manage')"), 'OCR page requires canonical MIS permission');
checkOcr(str_contains($page, 'verifyCsrfToken'), 'OCR configuration mutation enforces CSRF');
checkOcr(!str_contains($page, 'documentTextDetection('), 'Configuration page cannot make a billable OCR call');
checkOcr(!str_contains($env, 'GOOGLE_APPLICATION_CREDENTIALS_BASE64'), 'Base64 credential environment pattern removed');
checkOcr(str_contains($env, '/private/bcp-pms-ocr-service-account.json'), 'Protected HostForge credential path documented');
echo "PASS: $checks MIS Google OCR configuration security checks.\n";
