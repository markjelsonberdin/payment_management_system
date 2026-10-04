<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
requireAuth();
requirePaymentPermission('payment.mis_overview');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Use GET.']);
    exit;
}
require_once __DIR__ . '/../../includes/MisOverviewService.php';
try {
    $core = db();
    if (!$core) throw new RuntimeException('Core database unavailable');
    require_once __DIR__ . '/../../config/env_loader.php';
    payment_load_env(__DIR__ . '/../../.env');
    $technical = null;
    try {
        // Optional connection: legacy db_connect.php dies on failure, so it
        // cannot be used for a dashboard with partial availability.
        $host = getenv('PAYMENT_DB_HOST') ?: (getenv('DB_HOST') ?: '127.0.0.1');
        $port = getenv('PAYMENT_DB_PORT') ?: (getenv('DB_PORT') ?: '3307');
        $database = getenv('PAYMENT_DB_DATABASE') ?: (getenv('DB_DATABASE') ?: 'payment_db');
        $charset = getenv('PAYMENT_DB_CHARSET') ?: 'utf8mb4';
        $technical = new PDO("mysql:host=$host;port=$port;dbname=$database;charset=$charset",
            getenv('PAYMENT_DB_USER') ?: (getenv('DB_USERNAME') ?: 'root'),
            getenv('PAYMENT_DB_PASS') ?: (getenv('DB_PASSWORD') ?: ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]);
    } catch (Throwable $e) {
        error_log('MIS overview technical configuration connection unavailable');
    }
    // Only presence flags enter the service; credential values never enter JSON.
    $credentials = [];
    foreach (['test', 'live'] as $mode) {
        $credentials[$mode] = ['api' => (bool) getenv('PAYMONGO_SK_' . strtoupper($mode)),
            'webhook' => (bool) getenv('PAYMONGO_WHSEC_' . strtoupper($mode))];
    }
    echo json_encode((new MisOverviewService($core, $technical, $credentials))->load(), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('MIS overview unavailable');
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'MIS Overview is temporarily unavailable.']);
}