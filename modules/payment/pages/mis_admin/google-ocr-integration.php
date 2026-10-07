<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once ROOT_PATH . '/modules/payment/database/db_connect.php';
require_once ROOT_PATH . '/modules/payment/includes/PayMongoIntegrationSecurity.php';
require_once ROOT_PATH . '/modules/payment/includes/OcrConfigurationService.php';
require_once ROOT_PATH . '/modules/payment/includes/ocr/GoogleVisionOcrService.php';

requireAuth();
requirePaymentPermission('integration.ocr.manage');
global $pdo;
$corePdo = db();
if (!$corePdo) { http_response_code(503); exit('Google OCR administration is temporarily unavailable.'); }
try {
    $actor = PayMongoIntegrationSecurity::requireActiveMisActor($corePdo, 'integration.ocr.manage');
} catch (DomainException $e) {
    http_response_code(403); exit('Your account authority changed. Sign in again.');
}

$outbox = new PaymentAuditOutboxService($pdo, new StructuredActivityAuditWriter($corePdo));
$service = new OcrConfigurationService($pdo, new SchoolSalesCatalogMutationInfrastructure($pdo, $outbox));
$errorCode = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_ocr_settings'])) {
    if (!verifyCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
        http_response_code(403); exit('The request could not be verified. Refresh the page and try again.');
    }
    try {
        $service->update($_POST, $actor);
        header('Location: google-ocr-integration.php?result=updated'); exit;
    } catch (DomainException|InvalidArgumentException $e) {
        $allowed = ['OCR_PROJECT_ID_INVALID', 'OCR_MODE_INVALID', 'OCR_LIMIT_INVALID', 'OCR_PROJECT_REQUIRED', 'CORRELATION_ID_INVALID'];
        $errorCode = in_array($e->getMessage(), $allowed, true) ? $e->getMessage() : 'OCR_CONFIG_INVALID';
    } catch (Throwable $e) {
        error_log('OCR configuration update failed: ' . get_class($e));
        $errorCode = 'OCR_CONFIG_SAVE_FAILED';
    }
}
try { $configuration = $service->get(); }
catch (Throwable $e) { error_log('OCR configuration lookup failed: ' . get_class($e)); http_response_code(503); exit('Google OCR configuration is temporarily unavailable.'); }
$credentials = (new GoogleVisionOcrService())->configurationStatus();
$correlationId = CatalogCorrelationId::generate();
$messages = [
    'OCR_PROJECT_ID_INVALID' => 'Enter a valid Google Cloud project ID.',
    'OCR_MODE_INVALID' => 'Only Document Text Detection is supported.',
    'OCR_LIMIT_INVALID' => 'Set a monthly OCR limit from 1 through 900.',
    'OCR_PROJECT_REQUIRED' => 'A Google Cloud project ID is required before enabling OCR.',
    'CORRELATION_ID_INVALID' => 'The request identifier is invalid. Refresh the page and try again.',
    'OCR_CONFIG_INVALID' => 'The OCR configuration request is invalid.',
    'OCR_CONFIG_SAVE_FAILED' => 'The OCR configuration could not be saved.',
];
$pageTitle = 'Google OCR Integration'; $activeModule = 'payment'; $activePage = 'mis_admin/google-ocr-integration';
$breadcrumbs = [
    ['label' => 'MIS Admin', 'url' => BASE_URL . '/modules/payment/pages/mis_admin/overview.php'],
    ['label' => 'Google OCR Integration', 'url' => null],
];
require_once ROOT_PATH . '/includes/breadcrumbs.php';
require_once ROOT_PATH . '/includes/layout-start.php';
renderBreadcrumbs($breadcrumbs);
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/modules/payment/assets/css/payment-mis-admin.css?v=1">
<main class="container-fluid payment-page py-4">
  <div class="mis-page-header"><div><h1 class="h3">Google OCR Integration</h1><p>Manage and monitor Google OCR configuration, readiness, and usage.</p></div></div>
  <?php if (($_GET['result'] ?? '') === 'updated'): ?><div class="alert alert-success">Google OCR configuration updated.</div><?php endif; ?>
  <?php if ($errorCode !== null): ?><div class="alert alert-danger"><?= e($messages[$errorCode] ?? $messages['OCR_CONFIG_INVALID']) ?></div><?php endif; ?>
  <div class="row g-3 mb-4">
    <div class="col-md-4"><section class="card mis-card"><div class="card-body"><div class="small text-muted mis-card-label">Credentials</div><div class="mis-card-value"><?= e($credentials['status']) ?></div><p class="small text-muted mb-0">Protected file: <?= $credentials['readable'] ? 'Ready' : 'Not Ready' ?></p></div></section></div>
    <div class="col-md-4"><section class="card mis-card"><div class="card-body"><div class="small text-muted mis-card-label">Project environment</div><div class="mis-card-value"><?= $credentials['project_configured'] ? 'Configured' : 'Not Ready' ?></div><p class="small text-muted mb-0">The environment value is never displayed here.</p></div></section></div>
    <div class="col-md-4"><section class="card mis-card"><div class="card-body"><div class="small text-muted mis-card-label">Connection test</div><div class="mis-card-value">Unavailable</div><p class="small text-muted mb-0">Connection testing remains disabled until controlled production validation.</p></div></section></div>
  </div>
  <form method="post" class="card mis-card"><div class="card-body">
    <?= csrfField(); ?><input type="hidden" name="correlation_id" value="<?= e($correlationId) ?>">
    <div class="row g-3">
      <div class="col-lg-6"><label class="form-label fw-bold" for="projectId">Google Cloud project ID</label><input class="form-control" id="projectId" name="project_id" maxlength="30" value="<?= e((string) $configuration['project_id']) ?>" autocomplete="off"><div class="form-text">Non-secret identifier only. Service-account JSON is installed manually on the server.</div></div>
      <div class="col-lg-3"><label class="form-label fw-bold" for="ocrMode">OCR feature</label><select class="form-select" id="ocrMode" name="mode"><option value="DOCUMENT_TEXT_DETECTION" selected>Document Text Detection</option></select></div>
      <div class="col-lg-3"><label class="form-label fw-bold" for="monthlyLimit">Monthly unit ceiling</label><input class="form-control" id="monthlyLimit" name="monthly_limit" type="number" min="1" max="900" value="<?= e((string) $configuration['monthly_limit']) ?>"></div>
      <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="ocrEnabled" name="enabled" value="1" <?= $configuration['enabled'] ? 'checked' : '' ?>><label class="form-check-label fw-bold" for="ocrEnabled">Enable OCR after controlled validation</label></div><div class="form-text">Keep disabled until protected credentials, usage guard, cache, audit, and fail-safe handling pass validation.</div></div>
    </div>
  </div><div class="card-footer bg-white d-flex justify-content-end"><button class="btn btn-primary" type="submit" name="save_ocr_settings">Save OCR Configuration</button></div></form>
</main>
<?php require_once ROOT_PATH . '/includes/layout-end.php'; ?>
