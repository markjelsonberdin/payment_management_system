<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once ROOT_PATH . '/includes/StructuredActivityAuditWriter.php';
require_once ROOT_PATH . '/modules/payment/includes/PayMongoIntegrationSecurity.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

function payMongoCredentialRespond(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function payMongoCredentialCorrelationId(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

if (!isAuthenticated()) payMongoCredentialRespond(['ok' => false, 'error' => 'AUTHENTICATION_REQUIRED'], 401);
requireAuth();
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
    header('Allow: POST');
    payMongoCredentialRespond(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED'], 405);
}
if (!verifyCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
    payMongoCredentialRespond(['ok' => false, 'error' => 'CSRF_INVALID'], 403);
}

$mode = strtolower(trim((string) ($_POST['mode'] ?? '')));
$credential = strtolower(trim((string) ($_POST['credential'] ?? '')));
$purpose = strtolower(trim((string) ($_POST['purpose'] ?? 'view')));
$environmentKeys = [
    'test' => ['public' => 'PAYMONGO_PK_TEST', 'secret' => 'PAYMONGO_SK_TEST', 'webhook' => 'PAYMONGO_WHSEC_TEST'],
    'live' => ['public' => 'PAYMONGO_PK_LIVE', 'secret' => 'PAYMONGO_SK_LIVE', 'webhook' => 'PAYMONGO_WHSEC_LIVE'],
];
if (!isset($environmentKeys[$mode][$credential]) || !in_array($purpose, ['view', 'copy'], true)) {
    payMongoCredentialRespond(['ok' => false, 'error' => 'REQUEST_INVALID'], 422);
}

try {
    $corePdo = db();
    if (!$corePdo) throw new RuntimeException('Core database unavailable.');
    $actor = PayMongoIntegrationSecurity::requireActiveMisActor($corePdo);
    require_once ROOT_PATH . '/modules/payment/config/env_loader.php';
    payment_load_env(ROOT_PATH . '/modules/payment/.env');
    $credentialValue = (string) getenv($environmentKeys[$mode][$credential]);
    if ($credentialValue === '') payMongoCredentialRespond(['ok' => false, 'error' => 'CREDENTIAL_NOT_CONFIGURED'], 404);

    (new StructuredActivityAuditWriter($corePdo))->write([
        'user_id' => $actor['id'], 'user_name' => $actor['name'], 'role_key' => $actor['role'],
        'action' => 'PAYMONGO_CREDENTIAL_ACCESSED', 'module_key' => 'payment',
        'entity_type' => 'paymongo_credential', 'entity_id' => null,
        'detail' => sprintf('MIS Admin requested %s access to a configured %s PayMongo %s credential.', $purpose, $mode, $credential),
        'before_state' => null,
        'after_state' => json_encode(['environment' => $mode, 'credential' => $credential, 'purpose' => $purpose], JSON_THROW_ON_ERROR),
        'correlation_id' => payMongoCredentialCorrelationId(),
        'ip_address' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
        'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null,
    ]);
    payMongoCredentialRespond(['ok' => true, 'value' => $credentialValue]);
} catch (DomainException $e) {
    payMongoCredentialRespond(['ok' => false, 'error' => 'ACTOR_SESSION_STALE'], 403);
} catch (Throwable $e) {
    error_log('PayMongo credential access failed: ' . get_class($e));
    payMongoCredentialRespond(['ok' => false, 'error' => 'CREDENTIAL_ACCESS_UNAVAILABLE'], 500);
}
