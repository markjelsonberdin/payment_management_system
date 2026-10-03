<?php
/**
 * SMS 2 - API Endpoint: PayMongo Channels Status
 * Fetches the active capabilities from PayMongo and combines them with Admin toggles.
 */
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../includes/authentication.php';

header('Content-Type: application/json');

// Security Check
if (!isAuthenticated()) {
    echo json_encode(['error' => 'Unauthorized']);
    http_response_code(401);
    exit;
}
if (!paymentRoleAllowsPermission(getCurrentUserRoleKey(), 'integration.paymongo.manage')
    || !userCanAccessModule('integration.paymongo.manage')) {
    http_response_code(403);
    echo json_encode(['error' => 'FORBIDDEN']);
    exit;
}

require_once __DIR__ . '/../../database/db_connect.php';
require_once __DIR__ . '/../../config/paymongo.php';
require_once __DIR__ . '/../../includes/paymongo/PayMongoService.php';
require_once __DIR__ . '/../../includes/PaymentChannelService.php';

try {
    // Mode to check (defaults to active gateway_mode, but UI can pass ?mode=test to preview)
    $mode = $_GET['mode'] ?? null;
    
    // Load config (returns PayMongoConfig array with pk, sk, wh, mode)
    // Wait, paymongo.php loads the ACTIVE mode. If the UI wants to preview the other mode, 
    // it needs a way to fetch the secret key for that mode directly.
    // Let's use env_loader directly if a mode is explicitly requested.
    require_once __DIR__ . '/../../config/env_loader.php';
    payment_load_env(__DIR__ . '/../../.env');
    
    global $pdo;
    $channelService = new PaymentChannelService($pdo);
    
    $activeMode = $channelService->getActiveEnvironment();
    $targetMode = in_array($mode, ['test', 'live']) ? $mode : $activeMode;

    $sk = '';
    if ($targetMode === 'live') {
        $sk = getenv('PAYMONGO_SK_LIVE');
    } else {
        $sk = getenv('PAYMONGO_SK_TEST');
    }

    if (empty($sk)) {
        echo json_encode(['error' => "Secret key for $targetMode mode is missing in .env"]);
        exit;
    }

    $paymongo = new PayMongoService($sk);
    $statuses = $channelService->getChannelStatuses($paymongo, $targetMode);
    
    echo json_encode([
        'mode' => $targetMode,
        'channels' => $statuses
    ]);

} catch (Throwable $e) {
    error_log('PayMongo channel status lookup failed.');
    http_response_code(502);
    echo json_encode(['error' => 'CHANNEL_STATUS_UNAVAILABLE']);
}

