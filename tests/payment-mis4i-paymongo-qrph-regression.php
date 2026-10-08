<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn(string $path): string => file_get_contents($root . '/' . $path);
$configuration = $read('modules/payment/includes/PayMongoConfigurationService.php');
$channels = $read('modules/payment/includes/PaymentChannelService.php');
$validation = $read('modules/payment/includes/PaymentValidationService.php');
$provider = $read('modules/payment/includes/paymongo/PayMongoService.php');
$webhook = $read('modules/payment/api/paymongo/webhook.php');
$status = $read('modules/payment/api/paymongo/status.php');
$connection = $read('modules/payment/api/paymongo/test-connection.php');
$checkout = $read('modules/payment/api/paymongo/create-checkout.php');
$qr = $read('modules/payment/api/paymongo/create-qr-payment.php');
$page = $read('modules/payment/pages/mis_admin/online-payment-integration.php');
$student = $read('modules/student-portal/pages/account-balance.php');
$resume = $read('modules/student-portal/api/resume-payment.php');
$security = $read('modules/payment/includes/paymongo/PayMongoWebhookSecurityService.php');

$count = 0;
$check = static function (bool $condition, string $label) use (&$count): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    $count++;
};

$check(str_contains($configuration, "private const CHANNELS = ['qrph'];"), 'Only QR Ph configuration is mutable.');
$check(!preg_match('/name=["\']channel_(?:gcash|maya|card)["\']/', $page), 'Unsupported channel controls are absent.');
$check(str_contains($channels, "channelCode !== 'qrph'"), 'Channel policy rejects every non-QR method.');
$check(str_contains($validation, "channel !== 'qrph'"), 'Payment validation rejects every non-QR method.');
$check(str_contains($provider, "'payment_method_allowed' => ['qrph']"), 'Payment Intent permits QR Ph only.');
$check(str_contains($provider, "'type' => 'qrph'"), 'Payment Method is QR Ph.');
$check(str_contains($checkout, '410') && str_contains($checkout, 'PAYMENT_METHOD_RETIRED'), 'Legacy Checkout Session creation is retired.');
$check(!str_contains($webhook, "checkout_session.payment.paid"), 'Webhook allowlist uses the Payment Intent lifecycle only.');
$check(str_contains($webhook, "eventType === 'payment.paid'"), 'Webhook accepts the active paid event.');
$check(str_contains($webhook, "payment.failed"), 'Webhook handles active payment failure.');
$check(str_contains($webhook, 'verifySignature'), 'Webhook verifies its signature.');
$check(str_contains($security, 'hash_equals'), 'Webhook signature comparison is timing safe.');
$check(str_contains($webhook, 'gateway_environment') && str_contains($webhook, 'payloadEnv'), 'Webhook checks payment environment.');
$check(str_contains($webhook, "strtoupper((string)") && str_contains($webhook, "paymongoCurrency"), 'Webhook rejects wrong currency.');
$check(str_contains($webhook, 'checkout_total') && str_contains($webhook, 'Amount mismatch'), 'Webhook verifies authorized total.');
$check(str_contains($webhook, 'FOR UPDATE'), 'Webhook locks the payment row.');
$check(str_contains($webhook, 'webhook_event_id') && str_contains($webhook, 'INSERT IGNORE'), 'Webhook event idempotency is retained.');
$check(str_contains($webhook, '$internalPayment[\'payment_status\'] === \'Verified\''), 'Payment-level settlement idempotency is retained.');
$check(str_contains($webhook, 'PAYMONGO_LIVE_PAYMENT_SETTLED'), 'Live settlement creates durable audit intent.');
$allocation = strpos($webhook, 'allocatePayment(');
$outbox = strpos($webhook, 'insertPending(');
$commit = strpos($webhook, '$pdo->commit();');
$check($allocation !== false && $outbox !== false && $commit !== false && $allocation < $outbox && $outbox < $commit, 'Allocation and outbox insertion precede the same commit.');
$check(str_contains($webhook, 'PaymentAuditOutboxService($pdo)') && !str_contains($webhook, '->deliver('), 'Webhook leaves Core audit delivery recoverable and separate from allocation.');
$check(str_contains($webhook, '$shouldAllocate = ($internalPayment[\'gateway_environment\'] ?? null) === \'live\''), 'Only live attempts allocate.');
$check(str_contains($status, "array_diff(['payment.paid']"), 'Webhook diagnostics require the active paid event.');
$check(str_contains($status, "foreach (['qrph' => 'QR Ph']"), 'Readiness reports QR Ph only.');
$check(str_contains($connection, "!== 'POST'") && str_contains($connection, 'verifyCsrfToken') && str_contains($connection, 'requireActiveMisActor'), 'Diagnostics require POST, CSRF, and active MIS authority.');
$check(str_contains($student, "if (channel !== 'qrph')") && !str_contains($student, 'create-checkout.php'), 'Student initiation uses QR Ph only.');
$check(str_contains($resume, 'Historical non-QR online attempts cannot be resumed'), 'Historical unsupported attempts cannot initiate new Checkout Sessions.');
$check(str_contains($qr, 'getCurrentUserId') && str_contains($qr, 'student_id') && str_contains($qr, "channel !== 'qrph'"), 'QR creation derives student identity and rejects non-QR input.');
$check(str_contains($qr, 'Idempotency') || str_contains($provider, 'Idempotency-Key'), 'Provider initialization uses an idempotency key.');
$check(!preg_match('/(?:sk_live_|sk_test_|whsec_)[A-Za-z0-9_-]{8,}/', $page . $status . $connection . $webhook), 'Runtime and MIS sources contain no credential literals.');

echo "Batch 4I PayMongo QR Ph technical regression: {$count} assertions passed.\n";
