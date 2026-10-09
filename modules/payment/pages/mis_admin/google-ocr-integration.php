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
$pageTitle = 'Google OCR Configuration'; $activeModule = 'payment'; $activePage = 'mis_admin/google-ocr-integration';
$breadcrumbs = [
    ['label' => 'MIS Admin', 'url' => BASE_URL . '/modules/payment/pages/mis_admin/overview.php'],
    ['label' => 'Google OCR Configuration', 'url' => null],
];
require_once ROOT_PATH . '/includes/breadcrumbs.php';
require_once ROOT_PATH . '/includes/layout-start.php';
renderBreadcrumbs($breadcrumbs);
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/modules/payment/assets/css/payment-mis-admin.css?v=2">
<link rel="stylesheet" href="<?= BASE_URL ?>/modules/payment/assets/css/mis/google-ocr-config.css?v=2">
<link rel="stylesheet" href="<?= BASE_URL ?>/modules/payment/assets/css/mis/google-ocr-colors.css?v=2">
<main id="ocrAdminApp" class="container-fluid payment-page ocr-admin py-4"
      data-status-url="<?= BASE_URL ?>/modules/payment/api/mis_admin/ocr-status.php"
      data-diagnostic-url="<?= BASE_URL ?>/modules/payment/api/mis_admin/ocr-test-connection.php">
  <header class="ocr-header"><div><span class="ocr-eyebrow">Receipt processing</span><h1>Google OCR Configuration</h1><p>Manage receipt OCR integration, authentication, and usage limits.</p></div><div class="ocr-header-actions"><button class="btn btn-outline-primary" id="ocrRunDiagnostic" type="button"><i class="ti ti-shield-check"></i> Run Authentication Diagnostic</button></div></header>
  <?php if (($_GET['result'] ?? '') === 'updated'): ?><div class="alert alert-success">Google OCR configuration updated.</div><?php endif; ?>
  <?php if ($errorCode !== null): ?><div class="alert alert-danger"><?= e($messages[$errorCode] ?? $messages['OCR_CONFIG_INVALID']) ?></div><?php endif; ?>

  <section class="ocr-summary-grid">
    <?php foreach ([['ocrStatusCard','ti-scan','OCR Status','ocrSummaryStatus','ocrSummaryStatusNote'],['ocrAuthCard','ti-shield-check','Authentication Status','ocrSummaryAuth','ocrSummaryAuthNote'],['ocrUsageCard','ti-chart-bar','Monthly OCR Usage','ocrSummaryUsage','ocrSummaryMonth'],['ocrQuotaCard','ti-gauge','Remaining Quota','ocrSummaryRemaining','ocrSummaryQuotaNote']] as [$card,$icon,$label,$value,$note]): ?>
    <article class="ocr-summary" id="<?= e($card) ?>"><span class="ocr-summary-icon"><i class="ti <?= e($icon) ?>"></i></span><div><small><?= e($label) ?></small><strong id="<?= e($value) ?>">Loading…</strong><p id="<?= e($note) ?>">Loading persisted status…</p></div></article>
    <?php endforeach; ?>
  </section>

  <div class="ocr-main-grid">
  <section class="ocr-panel"><div class="ocr-panel-title"><div><span class="ocr-title-icon"><i class="ti ti-adjustments"></i></span><div><h2>OCR Configuration</h2><p>Supported processing controls stored by the payment service.</p></div></div><span class="ocr-pill" id="ocrConfigurationBadge">Checking</span></div>
    <form method="post" id="ocrConfigurationForm" data-original-enabled="<?= $configuration['enabled'] ? '1' : '0' ?>" data-original-limit="<?= e((string) $configuration['monthly_limit']) ?>">
      <?= csrfField(); ?><input type="hidden" name="correlation_id" value="<?= e($correlationId) ?>">
      <div class="ocr-fields"><div class="ocr-readonly"><span>OCR Provider</span><strong><i class="ti ti-lock"></i> Google Cloud Vision</strong></div><div><label for="ocrMode">Detection Mode</label><select class="form-select" id="ocrMode" name="mode"><option value="DOCUMENT_TEXT_DETECTION" selected>DOCUMENT_TEXT_DETECTION</option></select><small>Optimized for receipt and deposit-slip documents.</small></div><div class="ocr-wide"><label for="projectId">Google Cloud Project ID</label><input class="form-control" id="projectId" name="project_id" maxlength="30" value="<?= e((string) $configuration['project_id']) ?>" autocomplete="off"><small>Non-secret identifier. Credential values and tokens remain hidden.</small></div><div><label for="monthlyLimit">Application Monthly Quota</label><div class="input-group"><input class="form-control" id="monthlyLimit" name="monthly_limit" type="number" min="1" max="900" value="<?= e((string) $configuration['monthly_limit']) ?>"><span class="input-group-text">units / mo</span></div><small>Allowed range: 1–900 units.</small></div><div class="ocr-switch"><div><strong>OCR Processing Switch</strong><span>Allow authorized receipt scans to invoke the OCR parser.</span></div><div class="form-check form-switch"><input class="form-check-input" id="ocrEnabled" name="enabled" type="checkbox" value="1" <?= $configuration['enabled'] ? 'checked' : '' ?>></div></div></div>
      <div class="ocr-config-footer"><div><span>Configuration status</span><strong><?= $configuration['project_id'] !== '' ? 'Saved' : 'Incomplete' ?></strong></div><div><span>Last updated</span><strong><?= e((string) ($configuration['last_configuration_update'] ?: 'Not recorded')) ?></strong></div></div>
      <button class="btn btn-primary ocr-save" type="submit" name="save_ocr_settings"><i class="ti ti-device-floppy"></i> Save OCR Configuration</button>
    </form>
  </section>

  <section class="ocr-panel"><div class="ocr-panel-title"><div><span class="ocr-title-icon"><i class="ti ti-shield-lock"></i></span><div><h2>Authentication &amp; Credentials</h2><p>Read-only readiness parameters.</p></div></div><span class="ocr-pill" id="ocrCredentialBadge"><?= e($credentials['status']) ?></span></div><div class="ocr-credential-list"><?php foreach ([['Credential Configured',($credentials['path_configured'] ?? false)],['Credential Readable',($credentials['readable'] ?? false)],['Environment Project ID',($credentials['project_configured'] ?? false)],['Protected Location Confirmed',($credentials['protected_location'] ?? false)]] as [$label,$ready]): ?><div><span><?= e($label) ?></span><strong class="<?= $ready ? 'is-good' : 'is-warning' ?>"><?= $ready ? 'Yes' : 'No' ?></strong></div><?php endforeach; ?><div><span>Last Authentication Diagnostic</span><strong id="ocrCredentialDiagnostic">Not Checked</strong></div></div><div class="ocr-safe-note"><i class="ti ti-lock"></i><span><strong>Strict secret masking:</strong> private keys, OAuth tokens, raw JSON, and filesystem paths are not displayed by this portal.</span></div><div class="ocr-method"><span>Authentication method</span><strong>Application Default Credentials</strong></div></section>
  </div>

  <section class="ocr-panel ocr-usage"><div class="ocr-panel-title"><div><span class="ocr-title-icon"><i class="ti ti-chart-bar"></i></span><div><h2>Usage &amp; Quota Monitoring</h2><p id="ocrUsagePeriod">Current Asia/Manila billing month.</p></div></div><span class="ocr-pill" id="ocrUsageAvailability">Checking</span></div><div class="ocr-progress-copy"><strong id="ocrQuotaFraction">Unavailable</strong><span id="ocrQuotaCeiling">Ceiling unavailable</span></div><div class="progress"><div class="progress-bar" id="ocrQuotaProgress"></div></div><div class="ocr-legend"><span>Consumed <b id="ocrConsumedLegend">—</b></span><span>Reserved / in-flight <b id="ocrReservedLegend">—</b></span><span>Available <b id="ocrAvailableLegend">—</b></span></div><div class="ocr-metrics"><?php foreach ([['ocrConsumed','Consumed'],['ocrReserved','In Flight'],['ocrRemaining','Available'],['ocrProviderAttempts','Attempts'],['ocrSuccessful','Successful'],['ocrFailed','Provider Fail'],['ocrCacheHits','Cache Hits'],['ocrStale','Stale States']] as [$id,$label]): ?><div><span><?= e($label) ?></span><strong id="<?= e($id) ?>">Unavailable</strong></div><?php endforeach; ?></div><p class="ocr-usage-note"><i class="ti ti-info-circle"></i> This represents the SMS2 application limit and does not represent Google Cloud billing allowance or free-tier credits.</p></section>

  <div class="ocr-health-grid"><section class="ocr-panel"><div class="ocr-panel-title"><div><span class="ocr-title-icon"><i class="ti ti-stethoscope"></i></span><div><h2>Non-billable Diagnostics</h2><p>Configuration and OAuth readiness without a Vision OCR request.</p></div></div></div><div class="ocr-diagnostic-grid"><div><span>Last result</span><strong id="ocrDiagnosticStatus">Not Checked</strong></div><div><span>Last checked</span><strong id="ocrDiagnosticTime">Not recorded</strong></div><div><span>Authentication</span><strong id="ocrAuthentication">Not Checked</strong></div><div><span>Safe error category</span><strong id="ocrErrorCategory">None recorded</strong></div></div><div class="ocr-safe-note" id="ocrStatusMessage"><i class="ti ti-info-circle"></i><span>Authentication does not verify actual receipt processing.</span></div><form id="ocrDiagnosticForm"><?= csrfField(); ?><input type="hidden" name="correlation_id" value="<?= e(CatalogCorrelationId::generate()) ?>"></form></section><section class="ocr-panel"><div class="ocr-panel-title"><div><span class="ocr-title-icon"><i class="ti ti-activity"></i></span><div><h2>Processing Status</h2><p>Aggregated records without receipt contents.</p></div></div></div><div class="ocr-status-tables"><div><h3>Lifecycle Outcomes</h3><table><tbody id="ocrLifecycleRows"><tr><td>Loading…</td></tr></tbody></table></div><div><h3>Failure Categories</h3><table><tbody id="ocrFailureRows"><tr><td>Loading…</td></tr></tbody></table></div></div></section></div>
</main>

<div class="modal fade" id="ocrConfirmModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content ocr-confirm"><div class="modal-header"><div><h2 class="modal-title">Confirm Configuration Changes</h2><p>Review availability and quota changes before saving.</p></div><button class="btn-close" type="button" data-bs-dismiss="modal"></button></div><div class="modal-body"><p>This update applies to authorized receipt OCR requests.</p><div class="ocr-change-table"><div><span>Setting</span><span>Current</span><span>New</span></div><div><strong>OCR Processing</strong><span id="ocrPreviousEnabled">—</span><span id="ocrNewEnabled">—</span></div><div><strong>Monthly Quota</strong><span id="ocrPreviousLimit">—</span><span id="ocrNewLimit">—</span></div></div><div class="ocr-safe-note"><i class="ti ti-shield-check"></i><span>The change will be attributed to the active administrator through the existing structured audit flow.</span></div></div><div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" id="ocrConfirmSave" type="button">Confirm &amp; Apply</button></div></div></div></div>
<script src="<?= BASE_URL ?>/modules/payment/assets/js/payment-ocr-administration.js?v=3" defer></script>
<?php require_once ROOT_PATH . '/includes/layout-end.php'; ?>
