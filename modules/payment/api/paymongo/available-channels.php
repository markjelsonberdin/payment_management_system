<?php
/**
 * SMS 2 - API Endpoint: Get Available Payment Channels
 * Purpose: Returns the dynamically available payment channels for the student,
 * based on Admin Settings, Environment (Test/Live), and PayMongo capabilities.
 */
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../includes/authentication.php';
require_once __DIR__ . '/../../database/db_connect.php';
require_once __DIR__ . '/../../includes/PayMongoService.php';
require_once __DIR__ . '/../../includes/PaymentChannelService.php';

header('Content-Type: application/json');

// 1. Security Check
if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    http_response_code(401);
    exit;
}

try {
    $paymongo = new PayMongoService();
    $channelService = new PaymentChannelService($pdo);
    
    $env = $channelService->getActiveEnvironment();
    $statuses = $channelService->getChannelStatuses($paymongo, $env);
    
    $availableChannels = [];
    foreach ($statuses as $code => $data) {
        if ($data['status'] === 'AVAILABLE') {
            $availableChannels[] = [
                'code' => $code,
                'available' => true
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'environment' => $env,
        'channels' => $availableChannels
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Failed to retrieve available channels.'
    ]);
    http_response_code(500);
}
