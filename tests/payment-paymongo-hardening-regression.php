<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/modules/payment/includes/PayMongoConfigurationService.php';

$checks = 0;
function checkPayMongo(bool $condition, string $label): void
{
    global $checks;
    $checks++;
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    echo "PASS: {$label}\n";
}

$pdo = new PDO('sqlite::memory:');
$outbox = new PaymentAuditOutboxService($pdo);
$service = new PayMongoConfigurationService($pdo, new SchoolSalesCatalogMutationInfrastructure($pdo, $outbox));
$actor = ['id' => 7, 'name' => 'MIS Admin', 'role' => 'mis_admin'];

try {
    $service->update(['gateway_mode' => 'production2', 'correlation_id' => CatalogCorrelationId::generate()], $actor);
    checkPayMongo(false, 'arbitrary gateway mode rejected');
} catch (InvalidArgumentException $e) {
    checkPayMongo($e->getMessage() === 'GATEWAY_MODE_INVALID', 'arbitrary gateway mode rejected');
}

try {
    $service->update(['gateway_mode' => 'test', 'fee_policy' => 'arbitrary_fee_policy', 'correlation_id' => CatalogCorrelationId::generate()], $actor);
    checkPayMongo(false, 'arbitrary fee policy rejected');
} catch (InvalidArgumentException $e) {
    checkPayMongo($e->getMessage() === 'FEE_POLICY_INVALID', 'arbitrary fee policy rejected');
}

checkPayMongo(
    PayMongoConfigurationService::normalizeWebhookUrl('HTTPS://Example.COM/paymongo/webhook.php/')
        === 'https://example.com/paymongo/webhook.php',
    'webhook URL comparison normalizes scheme, host, and trailing slash'
);
checkPayMongo(PayMongoConfigurationService::normalizeWebhookUrl('not-a-url') === '', 'invalid webhook URL fails closed');

$page = file_get_contents($root . '/modules/payment/pages/mis_admin/online-payment-integration.php');
$status = file_get_contents($root . '/modules/payment/api/paymongo/status.php');
$test = file_get_contents($root . '/modules/payment/api/paymongo/test-connection.php');
$channels = file_get_contents($root . '/modules/payment/api/paymongo/channels.php');
$legacy = file_get_contents($root . '/modules/payment/api/payment-webhook.php');

checkPayMongo(str_contains($page, 'verifyCsrfToken') && str_contains($page, 'PayMongoIntegrationSecurity::requireActiveMisActor'), 'configuration save enforces CSRF and active MIS actor');
checkPayMongo((bool) preg_match('/name=["\']fee_policy["\']/', $page), 'validated processing-fee choices are available');
checkPayMongo(str_contains($page, 'pass_to_student') && str_contains($page, 'absorb_by_school'), 'processing-fee choices are allowlisted');
checkPayMongo(substr_count($page, 'type="password"') >= 1 && str_contains($page, 'readonly') && str_contains($page, 'Keys stay masked'), 'credential fields are visible but masked and read-only');
checkPayMongo(str_contains($page, 'ti ti-eye-off') && str_contains($page, 'Credential hidden'), 'masked credential fields display a clear hidden-state icon');
checkPayMongo(!str_contains($page, 'Payment gateway settings updated successfully.'), 'successful save has no passive green update banner');
checkPayMongo(!str_contains($page, 'sk_test_') && !str_contains($page, 'sk_live_') && !str_contains($page, 'whsec_'), 'HTML source contains no PayMongo key fragments');
checkPayMongo(str_contains($test, 'REQUEST_METHOD') && str_contains($test, "!== 'POST'") && str_contains($test, 'verifyCsrfToken'), 'connection test requires POST and CSRF');
checkPayMongo(str_contains($status, 'PayMongoIntegrationSecurity::requireActiveMisActor') && str_contains($channels, 'PayMongoIntegrationSecurity::requireActiveMisActor'), 'MIS status endpoints enforce current active actor');
checkPayMongo(str_contains($status, "'url_status' => 'UNVERIFIED'") && str_contains($status, "'MISMATCH'"), 'status API reports safe webhook URL states');
checkPayMongo(str_contains($test, 'PAYMONGO_CONNECTION_TESTED') || str_contains(file_get_contents($root . '/modules/payment/includes/PayMongoConfigurationService.php'), 'PAYMONGO_CONNECTION_TESTED'), 'connection test uses structured audit event');
checkPayMongo(str_contains($legacy, '410'), 'legacy webhook remains retired with HTTP 410');

$sensitivePatterns = ['PAYMONGO_SK_TEST', 'PAYMONGO_SK_LIVE', 'PAYMONGO_WHSEC_TEST', 'PAYMONGO_WHSEC_LIVE'];
foreach ([$status, $test, $channels] as $source) {
    foreach ($sensitivePatterns as $pattern) {
        checkPayMongo(!preg_match('/json_encode\([^;]*' . preg_quote($pattern, '/') . '/s', $source), "{$pattern} is not serialized by MIS APIs");
    }
}

echo "PayMongo MIS-5A regression checks passed: {$checks}\n";
