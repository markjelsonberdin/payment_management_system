<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once ROOT_PATH . '/modules/payment/database/db_connect.php';
require_once ROOT_PATH . '/modules/payment/includes/PayMongoIntegrationSecurity.php';
require_once ROOT_PATH . '/modules/payment/includes/OcrConfigurationService.php';
require_once ROOT_PATH . '/modules/payment/includes/ocr/GoogleVisionOcrService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function ocrDiagnosticRespond(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    ocrDiagnosticRespond(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED'], 405);
}
if (!isAuthenticated()) {
    ocrDiagnosticRespond(['ok' => false, 'error' => 'AUTHENTICATION_REQUIRED'], 401);
}
requireAuth();
requirePaymentPermission('integration.ocr.manage');
if (!verifyCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
    ocrDiagnosticRespond(['ok' => false, 'error' => 'CSRF_INVALID'], 403);
}

try {
    $core = db();
    if (!$core) throw new RuntimeException('Core database unavailable');
    $actor = PayMongoIntegrationSecurity::requireActiveMisActor($core, 'integration.ocr.manage');
    global $pdo;
    $outbox = new PaymentAuditOutboxService($pdo, new StructuredActivityAuditWriter($core));
    $service = new OcrConfigurationService($pdo, new SchoolSalesCatalogMutationInfrastructure($pdo, $outbox));
    $configuration = $service->get();
    $provider = new GoogleVisionOcrService();
    $providerStatus = $provider->configurationStatus();

    $configuredProject = trim((string) ($configuration['project_id'] ?? ''));
    $projectMatches = $provider->projectIdentityMatches($configuredProject);
    $configurationFingerprint = $provider->configurationFingerprint(
        $configuredProject,
        (string) ($configuration['mode'] ?? ''),
        (int) ($configuration['monthly_limit'] ?? 900),
        (bool) ($configuration['enabled'] ?? false)
    );
    $configurationValid = ($providerStatus['status'] ?? '') === 'CONFIGURED'
        && ($providerStatus['protected_location'] ?? false) === true
        && strtoupper((string) ($configuration['mode'] ?? '')) === GoogleVisionOcrService::FEATURE
        && $projectMatches;

    $diagnostic = [
        'status' => 'FAILED',
        'authentication' => 'UNVERIFIED',
        'error_category' => 'CONFIGURATION_INVALID',
        'provider_capability' => 'UNVERIFIED',
        'actual_ocr_processing' => 'UNVERIFIED',
    ];
    if ($configurationValid) {
        // OAuth token acquisition only. No image is uploaded and Vision OCR is never invoked here.
        $authentication = $provider->nonBillableAuthenticationStatus();
        $verified = ($authentication['authenticated'] ?? false) === true;
        $diagnostic['status'] = $verified ? 'AUTHENTICATION_VERIFIED' : 'FAILED';
        $diagnostic['authentication'] = $verified ? 'VERIFIED' : 'FAILED';
        $diagnostic['error_category'] = $verified ? 'NONE'
            : (string) ($authentication['error_category'] ?? 'AUTHENTICATION_FAILED');
    }

    $correlationId = trim((string) ($_POST['correlation_id'] ?? ''));
    $service->recordDiagnostic($correlationId, $actor, $diagnostic + [
        'configuration_fingerprint' => $configurationFingerprint ?? '',
    ]);
    ocrDiagnosticRespond([
        'ok' => true,
        'diagnostic' => $diagnostic,
        'message' => $diagnostic['status'] === 'AUTHENTICATION_VERIFIED'
            ? 'Authentication verified. Provider capability and actual OCR processing remain unverified.'
            : 'The controlled diagnostic did not verify authentication.',
    ]);
} catch (DomainException $exception) {
    ocrDiagnosticRespond(['ok' => false, 'error' => 'ACTOR_SESSION_STALE'], 403);
} catch (InvalidArgumentException $exception) {
    ocrDiagnosticRespond(['ok' => false, 'error' => 'DIAGNOSTIC_REQUEST_INVALID'], 422);
} catch (Throwable $exception) {
    error_log('OCR diagnostic failed: ' . get_class($exception));
    ocrDiagnosticRespond([
        'ok' => false,
        'error' => 'OCR_DIAGNOSTIC_UNAVAILABLE',
        'message' => 'The controlled OCR diagnostic is temporarily unavailable.',
    ], 500);
}
