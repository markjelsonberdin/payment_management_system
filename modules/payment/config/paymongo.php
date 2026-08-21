<?php
/**
 * Centralized PayMongo Configuration
 * Loads environment variables and provides them securely.
 */

// Load Environment securely
require_once __DIR__ . '/env_loader.php';
payment_load_env(__DIR__ . '/../.env');

// Fetch Gateway Mode from Database securely
$gatewayMode = 'test'; // Default

try {
    // Use central database connection to ensure correct port (e.g. 3307) and credentials
    require_once __DIR__ . '/../database/db_connect.php';
    global $pdo;
    
    $stmt = $pdo->query("SELECT setting_value FROM payment_gateway_settings WHERE setting_key = 'gateway_mode'");
    if ($row = $stmt->fetch()) {
        $gatewayMode = $row['setting_value'] === 'live' ? 'live' : 'test';
    }
} catch (Exception $e) {
    // Failsafe: Default to test mode if DB is unreachable here
}

$isLive = ($gatewayMode === 'live');

return [
    'env'            => $gatewayMode,
    'public_key'     => $isLive ? getenv('PAYMONGO_PK_LIVE') : getenv('PAYMONGO_PK_TEST'),
    'secret_key'     => $isLive ? getenv('PAYMONGO_SK_LIVE') : getenv('PAYMONGO_SK_TEST'),
    'webhook_secret' => $isLive ? getenv('PAYMONGO_WHSEC_LIVE') : getenv('PAYMONGO_WHSEC_TEST'),
];
