<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once ROOT_PATH . '/modules/payment/includes/PayMongoIntegrationSecurity.php';
require_once ROOT_PATH . '/modules/payment/includes/PayMongoConfigurationService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function payMongoTestRespond(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (!isAuthenticated()) payMongoTestRespond(['success' => false, 'error' => 'AUTHENTICATION_REQUIRED'], 401);
requireAuth();
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
    header('Allow: POST');
    payMongoTestRespond(['success' => false, 'error' => 'METHOD_NOT_ALLOWED'], 405);
}
if (!verifyCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
    payMongoTestRespond(['success' => false, 'error' => 'CSRF_INVALID'], 403);
}

try {
    $corePdo = db();
    if (!$corePdo) throw new RuntimeException('Core database unavailable.');
    $actor = PayMongoIntegrationSecurity::requireActiveMisActor($corePdo);
    require_once ROOT_PATH . '/modules/payment/database/db_connect.php';
    require_once ROOT_PATH . '/modules/payment/includes/paymongo/PayMongoService.php';
    global $pdo;

    $config = require ROOT_PATH . '/modules/payment/config/paymongo.php';
    $mode = in_array(($config['env'] ?? ''), ['test', 'live'], true) ? $config['env'] : null;
    if ($mode === null) throw new PayMongoProviderException('CONFIGURATION_ERROR');

    $result = 'CONNECTED';
    try {
        (new PayMongoService())->testConnection();
    } catch (PayMongoProviderException $e) {
        $result = $e->category;
    } catch (Throwable $e) {
        error_log('PayMongo connection provider call failed: ' . get_class($e));
        $result = 'PROVIDER_ERROR';
    }

    $outbox = new PaymentAuditOutboxService($pdo, new StructuredActivityAuditWriter($corePdo));
    $configuration = new PayMongoConfigurationService($pdo, new SchoolSalesCatalogMutationInfrastructure($pdo, $outbox));
    $record = $configuration->recordConnectionTest((string) ($_POST['correlation_id'] ?? ''), $actor, $mode, $result);
    unset($_SESSION['paymongo_status_cache']);

    $messages = [
        'CONNECTED' => 'Authenticated PayMongo connection succeeded.',
        'AUTHENTICATION_FAILED' => 'PayMongo rejected the configured credentials.',
        'CONFIGURATION_ERROR' => 'Required PayMongo configuration is missing or invalid.',
        'NETWORK_ERROR' => 'The server could not reach PayMongo.',
        'TIMEOUT' => 'The PayMongo request timed out.',
        'PROVIDER_ERROR' => 'PayMongo could not complete the connection test.',
    ];
    $ok = $result === 'CONNECTED';
    payMongoTestRespond([
        'success' => $ok, 'status' => $result, 'message' => $messages[$result],
        'correlation_id' => $record['correlation_id'], 'audit_status' => $record['audit_status'],
    ], $ok ? 200 : ($result === 'CONFIGURATION_ERROR' ? 422 : 502));
} catch (DomainException $e) {
    payMongoTestRespond(['success' => false, 'error' => 'ACTOR_SESSION_STALE'], 403);
} catch (InvalidArgumentException $e) {
    payMongoTestRespond(['success' => false, 'error' => 'REQUEST_INVALID'], 422);
} catch (Throwable $e) {
    error_log('PayMongo connection test failed: ' . get_class($e));
    payMongoTestRespond(['success' => false, 'error' => 'PAYMONGO_TEST_AUDIT_FAILED', 'message' => 'The connection test could not be safely recorded.'], 500);
}
