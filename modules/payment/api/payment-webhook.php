<?php
/**
 * SMS 2 - Payment Gateway Webhook REST API
 * Handles asynchronous payment confirmations from gateways (e.g., PayMongo)
 *
 * Deprecated: the supported, signed webhook is api/paymongo/webhook.php.
 * This file is retained temporarily to prevent stale external URLs from
 * silently posting financial records through an unsafe legacy path.
 */
http_response_code(410);
header('Content-Type: application/json');
echo json_encode([
    'success' => false,
    'error' => 'WEBHOOK_ENDPOINT_DEPRECATED',
    'message' => 'Use /modules/payment/api/paymongo/webhook.php.'
]);
exit;
