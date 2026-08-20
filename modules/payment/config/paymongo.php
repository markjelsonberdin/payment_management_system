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
    $dbHost = getenv('DB_HOST') ?: '127.0.0.1';
    $dbPort = getenv('DB_PORT') ?: '3306';
    $dbName = getenv('DB_DATABASE') ?: 'payment_db';
    $dbUser = getenv('DB_USERNAME') ?: 'root';
    $dbPass = getenv('DB_PASSWORD') ?: '';

    $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
    $pdoConfig = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    $stmt = $pdoConfig->query("SELECT setting_value FROM payment_gateway_settings WHERE setting_key = 'gateway_mode'");
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
