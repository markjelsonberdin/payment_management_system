<?php
/**
 * API Endpoint: PayMongo Configuration Real-Time Status
 * Returns JSON detailing API connectivity, Webhook readiness, and overall Gateway status.
 */
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../includes/authentication.php';
require_once __DIR__ . '/../../database/db_connect.php';

header('Content-Type: application/json');

// 1. Security Check
if (!isAuthenticated()) {
    echo json_encode(['error' => 'Unauthorized']);
    http_response_code(401);
    exit;
}
requirePaymentPermission('payment.online_payment_config');

// 2. Cache Check (if not manually forced)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$forceRefresh = isset($_GET['force']) && $_GET['force'] == '1';
if (!$forceRefresh && isset($_SESSION['paymongo_status_cache'])) {
    $cache = $_SESSION['paymongo_status_cache'];
    if (time() - $cache['timestamp'] < 30) {
        // Return cached result if less than 30 seconds old
        echo json_encode($cache['data']);
        exit;
    }
}

// 3. Load Configurations securely via the new env loader inside paymongo.php
$paymongoConfig = require __DIR__ . '/../../config/paymongo.php';
require_once __DIR__ . '/../../includes/PayMongoService.php';

$mode = $paymongoConfig['env']; // 'test' or 'live'
$isLive = ($mode === 'live');
$secretKey = $paymongoConfig['secret_key'];
$webhookSecret = $paymongoConfig['webhook_secret'];
$expectedWebhookUrl = BASE_URL . '/modules/payment/api/paymongo/webhook.php';

$response = [
    'environment' => $mode,
    'api' => [
        'configured' => !empty($secretKey),
        'connected' => false,
        'status' => 'not_configured',
        'message' => 'API credentials missing.'
    ],
    'webhook' => [
        'configured' => !empty($webhookSecret),
        'registered' => false,
        'enabled' => false,
        'correct_environment' => false,
        'correct_event' => false,
        'status' => 'not_configured',
        'message' => 'Webhook secret missing.'
    ],
    'gateway' => [
        'active' => false,
        'status' => 'INACTIVE',
        'message' => 'Payment gateway is inactive.'
    ],
    'checked_at' => date('Y-m-d h:i:s A')
];

if (!empty($secretKey)) {
    try {
        $service = new PayMongoService();
        $apiData = $service->testConnection(); // GET /v1/webhooks
        
        $response['api']['connected'] = true;
        $response['api']['status'] = 'connected';
        $response['api']['message'] = 'Authenticated API request succeeded.';
        
        // Check Webhooks
        if (isset($apiData['data']) && is_array($apiData['data'])) {
            $response['webhook']['registered'] = count($apiData['data']) > 0;
            
            if ($response['webhook']['registered']) {
                $validWebhookFound = false;
                
                foreach ($apiData['data'] as $wh) {
                    $attr = $wh['attributes'] ?? [];
                    $whLivemode = $attr['livemode'] ?? false;
                    $whStatus = $attr['status'] ?? '';
                    $whUrl = $attr['url'] ?? '';
                    $whEvents = $attr['events'] ?? [];
                    
                    $isCorrectEnv = ($whLivemode === $isLive);
                    $isEnabled = ($whStatus === 'enabled');
                    $hasRequiredEvent = in_array('checkout_session.payment.paid', $whEvents);
                    
                    // Note: We check if the configured URL exactly matches OR if it's ngrok for local dev testing
                    // But to be strict as requested, we'll check correct environment and required event first.
                    
                    if ($isCorrectEnv && $isEnabled && $hasRequiredEvent) {
                        $response['webhook']['enabled'] = true;
                        $response['webhook']['correct_environment'] = true;
                        $response['webhook']['correct_event'] = true;
                        $validWebhookFound = true;
                        
                        $response['webhook']['status'] = 'ready';
                        $response['webhook']['message'] = 'Webhook is active and listening for checkout payments.';
                        break;
                    }
                }
                
                if (!$validWebhookFound) {
                    $response['webhook']['status'] = 'configured_but_invalid';
                    $response['webhook']['message'] = 'Webhook exists but lacks correct environment, is disabled, or missing required events.';
                }
            } else {
                $response['webhook']['status'] = 'not_registered';
                $response['webhook']['message'] = 'No webhooks registered in PayMongo.';
            }
        }
    } catch (Exception $e) {
        $msg = $e->getMessage();
        if (strpos($msg, '401') !== false) {
            $response['api']['status'] = 'auth_failed';
            $response['api']['message'] = 'Authentication Failed: Invalid Secret Key.';
        } else {
            $response['api']['status'] = 'unavailable';
            $response['api']['message'] = 'API Error: ' . $msg;
        }
    }
}

// HTTPS Production Readiness Check
$isSecure = false;
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    $isSecure = true;
} elseif (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    $isSecure = true;
}

$isExpectedUrlSecure = (strpos($expectedWebhookUrl, 'https://') === 0);
$productionHttpsReady = ($isSecure && $isExpectedUrlSecure);

// Gateway Readiness Logic
$apiOk = $response['api']['connected'];
$whReady = ($response['webhook']['status'] === 'ready');

if ($mode === 'test') {
    if ($apiOk && $whReady) {
        $response['gateway']['status'] = 'TEST ACTIVE';
        $response['gateway']['active'] = true;
        $response['gateway']['message'] = 'Ready for Test Mode transactions.';
    } else {
        $response['gateway']['status'] = 'NOT READY';
        $response['gateway']['message'] = 'Test environment is missing required API or Webhook configuration.';
    }
} else {
    // Live Mode
    if ($apiOk && $whReady && $productionHttpsReady) {
        $response['gateway']['status'] = 'LIVE READY';
        $response['gateway']['active'] = false; // "LIVE ACTIVE" requires a separate manual kill switch to be true
        $response['gateway']['message'] = 'System is ready for Live Activation.';
    } else {
        $response['gateway']['status'] = 'LIVE NOT READY';
        if (!$productionHttpsReady) {
            $response['gateway']['message'] = 'Production HTTPS requirements not met.';
        } else {
            $response['gateway']['message'] = 'Live environment is missing required configuration.';
        }
    }
}

// Save to Cache
$_SESSION['paymongo_status_cache'] = [
    'timestamp' => time(),
    'data' => $response
];

echo json_encode($response);
