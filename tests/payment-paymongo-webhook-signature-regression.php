<?php
declare(strict_types=1);
$root = dirname(__DIR__);
define('ROOT_PATH', $root);
require_once $root . '/modules/payment/includes/paymongo/PayMongoWebhookSecurityService.php';

$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE payment_gateway_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)');
$testSecret = 'unit-test-webhook-secret';
$liveSecret = 'unit-live-webhook-secret';
$oldTest = getenv('PAYMONGO_WHSEC_TEST');
$oldLive = getenv('PAYMONGO_WHSEC_LIVE');
putenv('PAYMONGO_WHSEC_TEST=' . $testSecret);
putenv('PAYMONGO_WHSEC_LIVE=' . $liveSecret);
$payload = '{"data":{"id":"evt_unit"}}';
$timestamp = (string) time();
$testHmac = hash_hmac('sha256', $timestamp . '.' . $payload, $testSecret);
$liveHmac = hash_hmac('sha256', $timestamp . '.' . $payload, $liveSecret);
$count = 0;
$check = static function (bool $condition, string $label) use (&$count): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    $count++;
};
$testVerifier = new PayMongoWebhookSecurityService($pdo, 'test');
$check($testVerifier->verifySignature("t={$timestamp},te={$testHmac}", $payload) === true, 'Test environment accepts a valid test signature.');
try {
    $testVerifier->verifySignature("t={$timestamp},li={$testHmac}", $payload);
    $check(false, 'Test environment rejects the live signature slot.');
} catch (Exception) {
    $check(true, 'Test environment rejects the live signature slot.');
}
$liveVerifier = new PayMongoWebhookSecurityService($pdo, 'live');
$check($liveVerifier->verifySignature("t={$timestamp},li={$liveHmac}", $payload) === true, 'Live environment accepts a valid live signature.');
try {
    $liveVerifier->verifySignature("t={$timestamp},te={$liveHmac}", $payload);
    $check(false, 'Live environment rejects the test signature slot.');
} catch (Exception) {
    $check(true, 'Live environment rejects the test signature slot.');
}
try {
    new PayMongoWebhookSecurityService($pdo, 'staging');
    $check(false, 'Unknown environment is rejected.');
} catch (InvalidArgumentException) {
    $check(true, 'Unknown environment is rejected.');
}
putenv($oldTest === false ? 'PAYMONGO_WHSEC_TEST' : 'PAYMONGO_WHSEC_TEST=' . $oldTest);
putenv($oldLive === false ? 'PAYMONGO_WHSEC_LIVE' : 'PAYMONGO_WHSEC_LIVE=' . $oldLive);
echo "PayMongo webhook signature regression: {$count} assertions passed.\n";
