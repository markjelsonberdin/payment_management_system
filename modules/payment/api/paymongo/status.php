<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once ROOT_PATH . '/modules/payment/includes/PayMongoIntegrationSecurity.php';
require_once ROOT_PATH . '/modules/payment/includes/PayMongoConfigurationService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function payMongoStatusRespond(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (!isAuthenticated()) payMongoStatusRespond(['error' => 'AUTHENTICATION_REQUIRED'], 401);
requireAuth();
try {
    $corePdo = db();
    if (!$corePdo) throw new RuntimeException('Core database unavailable.');
    PayMongoIntegrationSecurity::requireActiveMisActor($corePdo);
} catch (DomainException $e) {
    payMongoStatusRespond(['error' => 'ACTOR_SESSION_STALE'], 403);
} catch (Throwable $e) {
    error_log('PayMongo status authorization failed: ' . get_class($e));
    payMongoStatusRespond(['error' => 'STATUS_UNAVAILABLE'], 503);
}

require_once ROOT_PATH . '/modules/payment/database/db_connect.php';
require_once ROOT_PATH . '/modules/payment/includes/paymongo/PayMongoService.php';
require_once ROOT_PATH . '/modules/payment/includes/PaymentChannelService.php';
global $pdo;

$forceRefresh = ($_GET['force'] ?? '') === '1';
if (!$forceRefresh && isset($_SESSION['paymongo_status_cache'])) {
    $cache = $_SESSION['paymongo_status_cache'];
    if (is_array($cache) && time() - (int) ($cache['timestamp'] ?? 0) < 30) {
        payMongoStatusRespond((array) ($cache['data'] ?? []));
    }
}

$config = require ROOT_PATH . '/modules/payment/config/paymongo.php';
$mode = in_array(($config['env'] ?? ''), ['test', 'live'], true) ? $config['env'] : null;
if ($mode === null) payMongoStatusRespond(['error' => 'PAYMONGO_CONFIG_INVALID'], 503);
$isLive = $mode === 'live';
$expectedWebhookUrl = rtrim((string) BASE_URL, '/') . '/modules/payment/api/paymongo/webhook.php';
$secretConfigured = (string) ($config['secret_key'] ?? '') !== '';
$webhookSecretConfigured = (string) ($config['webhook_secret'] ?? '') !== '';
$response = [
    'environment' => $mode,
    'credentials' => [
        'public_key' => (string) ($config['public_key'] ?? '') !== '' ? 'CONFIGURED' : 'NOT_CONFIGURED',
        'secret_key' => $secretConfigured ? 'CONFIGURED' : 'NOT_CONFIGURED',
        'webhook_secret' => $webhookSecretConfigured ? 'CONFIGURED' : 'NOT_CONFIGURED',
    ],
    'api' => ['configured' => $secretConfigured, 'connected' => false, 'status' => 'CONFIGURATION_ERROR', 'message' => 'API credentials are not configured.'],
    'webhook' => [
        'configured' => $webhookSecretConfigured, 'registered' => false, 'enabled' => false,
        'correct_environment' => false, 'correct_event' => false, 'url_status' => 'UNVERIFIED',
        'expected_url' => $expectedWebhookUrl, 'status' => 'not_configured', 'message' => 'Webhook secret is not configured.',
    ],
    'gateway' => ['active' => false, 'status' => 'INACTIVE', 'message' => 'Payment gateway is inactive.'],
    'channels' => ['status' => 'not_checked', 'items' => []],
    'last_test' => ['status' => null, 'successful_at' => null, 'failed_at' => null],
    'last_configuration_update' => null,
    'checked_at' => gmdate('c'),
];

try {
    $metaStmt = $pdo->query("SELECT setting_key, setting_value FROM payment_gateway_settings WHERE setting_key IN ('paymongo_last_test_status','paymongo_last_successful_test','paymongo_last_failed_test','paymongo_last_config_update')");
    $meta = $metaStmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $response['last_test'] = ['status' => $meta['paymongo_last_test_status'] ?? null, 'successful_at' => $meta['paymongo_last_successful_test'] ?? null, 'failed_at' => $meta['paymongo_last_failed_test'] ?? null];
    $response['last_configuration_update'] = $meta['paymongo_last_config_update'] ?? null;
} catch (Throwable $e) {
    error_log('PayMongo status metadata unavailable: ' . get_class($e));
}

if ($secretConfigured) {
    try {
        $service = new PayMongoService();
        $apiData = $service->testConnection();
        $response['api'] = ['configured' => true, 'connected' => true, 'status' => 'CONNECTED', 'message' => 'Authenticated provider request succeeded.'];
        $webhooks = isset($apiData['data']) && is_array($apiData['data']) ? $apiData['data'] : [];
        $response['webhook']['registered'] = count($webhooks) > 0;
        $candidateFound = false;
        foreach ($webhooks as $webhook) {
            $attributes = is_array($webhook['attributes'] ?? null) ? $webhook['attributes'] : [];
            $environmentMatches = (bool) ($attributes['livemode'] ?? false) === $isLive;
            $enabled = ($attributes['status'] ?? '') === 'enabled';
            $events = is_array($attributes['events'] ?? null) ? $attributes['events'] : [];
            $eventsMatch = empty(array_diff(['payment.paid'], $events));
            if (!$environmentMatches || !$enabled || !$eventsMatch) continue;
            $candidateFound = true;
            $response['webhook']['correct_environment'] = true;
            $response['webhook']['correct_event'] = true;
            $response['webhook']['enabled'] = true;
            $providerUrl = PayMongoConfigurationService::normalizeWebhookUrl((string) ($attributes['url'] ?? ''));
            $expectedUrl = PayMongoConfigurationService::normalizeWebhookUrl($expectedWebhookUrl);
            $response['webhook']['url_status'] = $providerUrl === '' ? 'NOT_CONFIGURED' : ($providerUrl === $expectedUrl ? 'MATCH' : 'MISMATCH');
            if ($response['webhook']['url_status'] === 'MATCH') break;
        }
        if (!$response['webhook']['registered']) {
            $response['webhook']['status'] = 'not_registered';
            $response['webhook']['url_status'] = 'NOT_CONFIGURED';
            $response['webhook']['message'] = 'No PayMongo webhook is registered.';
        } elseif (!$candidateFound) {
            $response['webhook']['status'] = 'configured_but_invalid';
            $response['webhook']['message'] = 'The webhook environment, status, or required events do not match.';
        } elseif ($response['webhook']['url_status'] !== 'MATCH') {
            $response['webhook']['status'] = 'url_mismatch';
            $response['webhook']['message'] = 'The registered webhook URL does not match the expected PMS endpoint.';
        } else {
            $response['webhook']['status'] = 'ready';
            $response['webhook']['message'] = 'The webhook is enabled with the expected URL and events.';
        }
    } catch (PayMongoProviderException $e) {
        $response['api']['status'] = $e->category;
        $response['api']['message'] = payMongoSafeStatusMessage($e->category);
    } catch (Throwable $e) {
        error_log('PayMongo provider status failed: ' . get_class($e));
        $response['api']['status'] = 'PROVIDER_ERROR';
        $response['api']['message'] = payMongoSafeStatusMessage('PROVIDER_ERROR');
    }
}

$apiOk = $response['api']['connected'];
$webhookReady = $response['webhook']['status'] === 'ready';
$httpsReady = str_starts_with($expectedWebhookUrl, 'https://');
if ($mode === 'test') {
    $response['gateway'] = $apiOk && $webhookReady
        ? ['active' => true, 'status' => 'TEST ACTIVE', 'message' => 'Ready for test transactions.']
        : ['active' => false, 'status' => 'NOT READY', 'message' => 'Test integration configuration is incomplete.'];
} else {
    $response['gateway'] = $apiOk && $webhookReady && $httpsReady
        ? ['active' => false, 'status' => 'LIVE READY', 'message' => 'Technical checks passed; activation remains a controlled manual decision.']
        : ['active' => false, 'status' => 'LIVE NOT READY', 'message' => 'Live integration configuration is incomplete.'];
}

try {
    $channelService = new PaymentChannelService($pdo);
    $adminSettings = $channelService->getAdminSettings($mode);
    $providerStatuses = null;
    if ($mode === 'test' && $apiOk) {
        $providerStatuses = array_fill_keys(['qrph'], ['provider_active' => true]);
    } elseif ($apiOk && isset($service)) {
        $capabilities = $service->getMerchantCapabilities();
        if ($capabilities !== []) {
            $providerStatuses = [];
            foreach (['qrph'] as $code) $providerStatuses[$code] = ['provider_active' => ($capabilities[$code] ?? null) === 'active'];
        }
    }
    if ($providerStatuses !== null) {
        foreach (['qrph' => 'QR Ph'] as $code => $label) {
            $policyAllowed = $mode !== 'live' || $code === 'qrph';
            $providerActive = !empty($providerStatuses[$code]['provider_active']);
            $configured = !empty($adminSettings[$code]);
            $response['channels']['items'][] = ['code' => $code, 'name' => $label, 'configured' => $configured, 'provider_active' => $providerActive, 'policy_allowed' => $policyAllowed, 'available_to_students' => $configured && $providerActive && $policyAllowed];
        }
        $response['channels']['status'] = 'ok';
    }
} catch (Throwable $e) {
    error_log('PayMongo channel readiness failed: ' . get_class($e));
    $response['channels'] = ['status' => 'error', 'items' => []];
}

$_SESSION['paymongo_status_cache'] = ['timestamp' => time(), 'data' => $response];
payMongoStatusRespond($response);

function payMongoSafeStatusMessage(string $category): string
{
    return match ($category) {
        'AUTHENTICATION_FAILED' => 'PayMongo rejected the configured credentials.',
        'CONFIGURATION_ERROR' => 'Required PayMongo configuration is missing or invalid.',
        'NETWORK_ERROR' => 'The server could not reach PayMongo.',
        'TIMEOUT' => 'The PayMongo request timed out.',
        default => 'PayMongo could not complete the technical status check.',
    };
}
