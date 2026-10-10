<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once ROOT_PATH . '/modules/payment/database/db_connect.php';
require_once ROOT_PATH . '/modules/payment/includes/PayMongoIntegrationSecurity.php';
require_once ROOT_PATH . '/modules/payment/includes/OcrConfigurationService.php';
require_once ROOT_PATH . '/modules/payment/includes/OcrTechnicalStatusService.php';
require_once ROOT_PATH . '/modules/payment/includes/ocr/GoogleVisionOcrService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED']);
    exit;
}
function ocrStatusRespond(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
if (!isAuthenticated()) ocrStatusRespond(['ok' => false, 'error' => 'AUTHENTICATION_REQUIRED'], 401);
requireAuth();
requirePaymentPermission('integration.ocr.manage');

try {
    $core = db();
    if (!$core) throw new RuntimeException('Core unavailable');
    PayMongoIntegrationSecurity::requireActiveMisActor($core, 'integration.ocr.manage');
    global $pdo;
    $outbox = new PaymentAuditOutboxService($pdo, new StructuredActivityAuditWriter($core));
    $configuration = (new OcrConfigurationService($pdo, new SchoolSalesCatalogMutationInfrastructure($pdo, $outbox)))->get();
    $provider = new GoogleVisionOcrService();
    $credential = $provider->configurationStatus();
    $credential['project_matches'] = $provider->projectIdentityMatches((string) ($configuration['project_id'] ?? ''));
    $credential['configuration_fingerprint'] = $provider->configurationFingerprint(
        (string) ($configuration['project_id'] ?? ''),
        (string) ($configuration['mode'] ?? ''),
        (int) ($configuration['monthly_limit'] ?? 900),
        (bool) ($configuration['enabled'] ?? false)
    );
    ocrStatusRespond(['ok' => true, 'data' => (new OcrTechnicalStatusService($pdo))->load($configuration, $credential)]);
} catch (DomainException $exception) {
    ocrStatusRespond(['ok' => false, 'error' => 'ACTOR_SESSION_STALE'], 403);
} catch (Throwable $exception) {
    error_log('OCR status failed: ' . get_class($exception));
    ocrStatusRespond(['ok' => false, 'error' => 'OCR_STATUS_UNAVAILABLE',
        'message' => 'OCR technical status is temporarily unavailable.'], 500);
}
