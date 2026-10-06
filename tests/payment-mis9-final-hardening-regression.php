<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') throw new RuntimeException('CLI only');

$checks = 0;
function mis9check(bool $ok, string $message): void {
    global $checks; $checks++;
    if (!$ok) throw new RuntimeException($message);
}
$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);

require_once $root . '/config/config.php';
require_once $root . '/includes/authentication.php';

$auth = $read('includes/authentication.php') . $read('includes/module-controls.php');
$session = $read('config/session.php');
$config = $read('config/config.php');
$sidebar = $read('includes/sidebar.php');
$pages = [
    'dashboard.php' => 'payment.mis_overview',
    'payment-user-management.php' => 'payment_users.view',
    'online-payment-integration.php' => 'integration.paymongo.manage',
    'google-ocr-integration.php' => 'integration.ocr.manage',
    'security-monitoring.php' => 'payment.security.view',
    'administrative-audit.php' => 'payment.audit.view',
];
$misUi = '';
foreach ($pages as $file => $permission) {
    $source = $read('modules/payment/pages/mis_admin/' . $file);
    $misUi .= "\n" . $source;
    mis9check(str_contains($source, "requirePaymentPermission('{$permission}')"), "$file exact permission");
    mis9check(str_contains($source, '<h1'), "$file keeps H1");
}

$apiMap = [
    'modules/payment/api/mis_admin/overview.php' => 'payment.mis_overview',
    'modules/payment/api/mis_admin/payment-users.php' => 'payment_users.view',
    'modules/payment/api/mis_admin/security-monitoring.php' => 'payment.security.view',
    'modules/payment/api/mis_admin/administrative-audit.php' => 'payment.audit.view',
    'modules/payment/api/paymongo/status.php' => 'requireActiveMisActor',
    'modules/payment/api/paymongo/channels.php' => 'requireActiveMisActor',
    'modules/payment/api/paymongo/test-connection.php' => 'requireActiveMisActor',
];
foreach ($apiMap as $file => $permission) {
    mis9check(str_contains($read($file), $permission), "$file exact permission");
}

$overview = $read('modules/payment/api/mis_admin/overview.php');
foreach (['AUTHENTICATION_REQUIRED', 'NOT_AUTHORIZED', 'ACTOR_SESSION_STALE'] as $error) {
    mis9check(str_contains($overview, $error), "Overview $error handling");
}
mis9check(str_contains($overview, "actorRow['status'] !== 'active'"), 'Overview rejects inactive actor');
mis9check(str_contains($overview, "actorRow['role_key'] !== getCurrentUserRoleKey()"), 'Overview rejects stale role');
mis9check(str_contains($overview, "!== 'GET'") && str_contains($overview, '405'), 'Overview method allowlist');

$deniedRoles = ['accounting_admin', 'accounting_officer', 'cashier', 'student', 'finance', 'payment_admin', 'superadmin', 'unknown_role'];
$misPermissions = ['payment.mis_overview', 'payment_users.view', 'integration.paymongo.manage',
    'integration.ocr.manage', 'payment.security.view', 'payment.audit.view'];
foreach ($deniedRoles as $role) foreach ($misPermissions as $permission) {
    mis9check(!paymentRoleAllowsPermission($role, $permission), "$role denied $permission");
}
$financial = ['fee.manage', 'billing.individual.process', 'billing.bulk.approve', 'payment.verify',
    'payment.concern.review', 'ledger.view', 'ar.manage', 'report.view', 'payment.collection',
    'payment.walkin_history', 'payment.school_sales', 'school_sales.catalog.manage'];
foreach ($financial as $permission) {
    mis9check(!paymentRoleAllowsPermission('mis_admin', $permission), "MIS denied $permission");
}
$misBundle = ['payment.mis_overview', 'payment_users.view', 'payment_users.create', 'payment_users.update',
    'payment_users.role.assign', 'payment_users.activate', 'payment_users.deactivate', 'payment_users.unlock',
    'payment_users.password.reset', 'integration.paymongo.manage', 'integration.ocr.manage',
    'payment.security.view', 'payment.audit.view'];
foreach ($misBundle as $permission) mis9check(paymentRoleAllowsPermission('mis_admin', $permission), "MIS owns $permission");

$personnelApi = $read('modules/payment/api/mis_admin/payment-users.php');
$personnel = $read('modules/payment/includes/PaymentPersonnelService.php');
foreach (['verifyCsrfToken', 'METHOD_NOT_ALLOWED', 'ACTOR_SESSION_STALE', 'smsBumpUserKickEpoch'] as $control) {
    mis9check(str_contains($personnelApi . $personnel, $control), "Personnel control $control");
}
foreach (['accounting_admin', 'accounting_officer', 'cashier'] as $role) {
    mis9check(str_contains($personnel, "'$role'"), "Managed role $role");
}
mis9check(!preg_match('/managedRoles[^;]*(?:mis_admin|student)/s', $personnel), 'MIS and Student excluded from managed roles');

foreach (['session.use_strict_mode', 'session.use_only_cookies', 'httponly', 'samesite', 'smsEnforceSessionTimeout'] as $control) {
    mis9check(str_contains($session, $control), "Session control $control");
}
foreach (['session_regenerate_id(true)', 'user_kick_epoch', 'smsUserSessionGenerationRevoked', 'smsBumpUserKickEpoch'] as $control) {
    mis9check(str_contains($auth, $control), "Authentication control $control");
}

$paymongoPage = $read('modules/payment/pages/mis_admin/online-payment-integration.php');
$paymongoTest = $read('modules/payment/api/paymongo/test-connection.php');
mis9check(str_contains($paymongoPage, 'verifyCsrfToken'), 'PayMongo configuration CSRF');
mis9check(str_contains($paymongoTest, 'verifyCsrfToken'), 'PayMongo test CSRF');
mis9check(!preg_match('/name=["\']fee_policy["\']/', $paymongoPage), 'No MIS fee policy control');
mis9check(!preg_match('/(?:sk_live_|sk_test_|whsec_)[A-Za-z0-9]{8,}/', $paymongoPage . $paymongoTest), 'No PayMongo secret literal');

$ocrPage = $read('modules/payment/pages/mis_admin/google-ocr-integration.php');
$vision = $read('modules/payment/includes/ocr/GoogleVisionOcrService.php');
$usage = $read('modules/payment/includes/ocr/OcrUsageGuardService.php');
$storage = $read('modules/payment/includes/ocr/PrivateReceiptStorageService.php');
$processor = $read('modules/payment/includes/ocr/ReceiptOcrProcessor.php');
mis9check(str_contains($vision, 'GOOGLE_APPLICATION_CREDENTIALS'), 'OCR uses ADC');
mis9check(!str_contains($ocrPage, 'GOOGLE_APPLICATION_CREDENTIALS'), 'OCR page hides credential path');
mis9check(str_contains($usage, '900') && str_contains($usage, 'Asia/Manila'), 'OCR ceiling and timezone');
mis9check(str_contains($storage, '5 * 1024 * 1024'), 'Receipt maximum is 5 MB');
foreach (['image/jpeg', 'image/png', 'image/webp'] as $mime) mis9check(str_contains($storage, $mime), "Receipt allows $mime");
mis9check(!str_contains($storage, 'application/pdf'), 'PDF excluded');
mis9check(str_contains($processor, "guard->begin") && str_contains($processor, "['cache_hit']")
    && str_contains($processor, 'markProviderCalled'), 'OCR cache and quota reservation');
mis9check(!preg_match('/(?:approve|reject).*(?:payment|concern)/i', $processor), 'OCR makes no financial decision');

$auditApi = $read('modules/payment/api/mis_admin/administrative-audit.php');
$audit = $read('modules/payment/includes/PaymentAdministrativeAuditService.php');
mis9check(!preg_match('/\b(?:INSERT|UPDATE|DELETE|TRUNCATE)\b/i', $auditApi), 'Audit API is read only');
foreach (['password', 'secret', 'token', 'private_key', 'access_token', 'refresh_token', 'session_id', 'receipt_path'] as $key) {
    mis9check(str_contains($audit, $key), "Audit redacts $key");
}
mis9check(str_contains($audit, 'OCR_SAFE_KEYS') && !preg_match('/OCR_SAFE_KEYS[^;]*(?:ocr_text|amount|reference|receipt_path)/s', $audit),
    'OCR audit allowlist excludes private financial evidence');
mis9check(str_contains($audit, 'activity_logs'), 'Core activity logs are canonical');
mis9check(!str_contains($audit, 'payment_audit_outbox'), 'Outbox is not public audit source');

foreach (['AUB Bank Reconciliation', 'Fee Setup', 'Student Billing', 'Payment Approval', 'Payment Ledger',
    'Accounts Receivable', 'Collection Reporting', 'School Sales', 'Refund', 'Void'] as $forbidden) {
    mis9check(!str_contains($misUi, $forbidden), "MIS UI excludes $forbidden");
}
foreach (['Payment Administration', 'Integrations', 'Security', 'Audit'] as $group) {
    mis9check(str_contains($config, "'$group'"), "Navigation group $group");
}
mis9check(str_contains($auth, "overview_group_label'] = 'Dashboard'"), 'Dashboard hierarchy');
mis9check(str_contains($sidebar, "module['overview_group_label']"), 'Optional navigation metadata');
mis9check(!str_contains($config, "'MIS ADMIN PORTAL'"), 'Legacy flat MIS group absent');

$frontend = $read('modules/payment/assets/js/payment-admin-dashboard.js')
    . $read('modules/payment/assets/js/accounting-user-management.js')
    . $read('modules/payment/assets/js/payment-security-monitoring.js')
    . $read('modules/payment/assets/js/payment-administrative-audit.js');
mis9check(substr_count($frontend, 'const esc') >= 4, 'Frontend output escaping retained');
mis9check(!preg_match('/(?:private_key|BEGIN PRIVATE KEY|client_secret|access_token|refresh_token)/i', $frontend), 'No frontend credential material');
$releaseSource = $auth . $config . $sidebar . $misUi . $frontend . $overview . $personnelApi . $auditApi;
mis9check(!str_contains($releaseSource, 'BEGIN PRIVATE KEY'), 'No embedded private key');
mis9check(!preg_match('/(?:sk_live_|sk_test_|whsec_)[A-Za-z0-9]{8,}/', $releaseSource), 'No embedded PayMongo credential');
mis9check(!preg_match('/var_dump\s*\(|print_r\s*\(|TODO\s*(?:bypass|disable auth)/i', $releaseSource), 'No debug or auth bypass marker');

echo "PASS: {$checks} MIS-9 final hardening checks.\n";
