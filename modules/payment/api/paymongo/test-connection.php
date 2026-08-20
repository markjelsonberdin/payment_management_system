<?php
/**
 * API Endpoint: PayMongo Connection Tester
 * Pings the PayMongo API using the active secret key.
 */
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../database/db_connect.php';

header('Content-Type: application/json');

try {
    global $pdo;
    
    // 1. Alamin kung Test o Live mode ang active
    $stmt = $pdo->query("SELECT setting_value FROM payment_db.payment_gateway_settings WHERE setting_key = 'gateway_mode'");
    $mode = $stmt->fetchColumn() ?: 'test';

    // 2. Basahin ang .env file (Same path depth as admin pages)
    $envPath = __DIR__ . '/../../.env';
    $envVars = file_exists($envPath) ? parse_ini_file($envPath) : [];

    // 3. Piliin ang gagamiting Secret Key
    $sk = ($mode === 'live') ? ($envVars['PAYMONGO_SK_LIVE'] ?? '') : ($envVars['PAYMONGO_SK_TEST'] ?? '');

    if (empty($sk)) {
        echo json_encode(['success' => false, 'message' => 'No Secret Key found in .env']);
        exit;
    }

    // 4. Kumatok sa PayMongo gamit ang cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.paymongo.com/v1/webhooks"); 
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "accept: application/json",
        "authorization: Basic " . base64_encode($sk . ":")
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Kung 200 OK, meaning valid ang key at nakakonekta tayo!
    if ($httpCode >= 200 && $httpCode < 300) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'code' => $httpCode, 'response' => json_decode($response)]);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>