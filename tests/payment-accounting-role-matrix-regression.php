<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') throw new RuntimeException('This regression requires CLI execution.');
if (session_status() !== PHP_SESSION_NONE) throw new RuntimeException('Regression requires no pre-existing session.');
$originalSessionPath = session_save_path();
$sessionSuffix = '.sms2-payment-session-' . bin2hex(random_bytes(16));
$testSessionDirectory = __DIR__ . '/' . $sessionSuffix;
if (!@mkdir($testSessionDirectory, 0700)) {
    $testSessionDirectory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $sessionSuffix;
    if (!mkdir($testSessionDirectory, 0700)) throw new RuntimeException('Unable to create isolated test session directory.');
}
try {
    session_save_path($testSessionDirectory);
    if (session_save_path() !== $testSessionDirectory || !is_writable($testSessionDirectory)) {
        throw new RuntimeException('Isolated session path is not effective/writable.');
    }
require_once __DIR__ . '/../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
if (session_status() !== PHP_SESSION_ACTIVE) throw new RuntimeException('Isolated test session did not start.');
if (($argv[1] ?? '') === '--test-session-cleanup-failure') {
    $_SESSION['cleanup_probe'] = 'test-only';
    throw new RuntimeException('Injected test-only session cleanup failure');
}
$checks = 0;
function roleCheck(bool $ok, string $message): void { global $checks; $checks++; if (!$ok) throw new RuntimeException($message); }
function allowRole(string $role, array $permissions): void { foreach ($permissions as $p) roleCheck(paymentRoleAllowsPermission($role, $p), "$role missing $p"); }
function denyRole(string $role, array $permissions): void { foreach ($permissions as $p) roleCheck(!paymentRoleAllowsPermission($role, $p), "$role unexpectedly has $p"); }
roleCheck(smsNormalizeRoleKey(' Accounting Admin ') === 'accounting_admin', 'Accounting Admin labels must normalize to the canonical role key');
roleCheck(smsNormalizeRoleKey('ACCOUNTING_OFFICER') === 'accounting_officer', 'Accounting Officer role keys must normalize case-insensitively');
allowRole('accounting_officer', ['billing.individual.process','billing.individual.review','billing.bulk.preview','billing.bulk.create','billing.bulk.process','billing.bulk.retry','billing.bulk.resume','billing.bulk.view','ar.view','payment.discount','ledger.view','payment.concern.view','payment.reconciliation.view','payment.reconciliation.process','payment.reconciliation.import','report.view']);
denyRole('accounting_officer', ['payment.verify','payment.reconciliation.exception.approve','ar.manage']);
denyRole('accounting_officer', ['fee.activate','billing.bulk.approve','report.export','payment_users.view','integration.paymongo.manage','school_sales.catalog.view','school_sales.catalog.manage','school_sales.catalog.activate']);
allowRole('accounting_admin', ['fee.view','fee.manage','fee.activate','billing.individual.review','billing.bulk.view','billing.bulk.approve','payment.verify','ledger.view','ar.view','ar.manage','payment.reconciliation.view','payment.reconciliation.exception.approve','report.view','report.export','school_sales.catalog.view','school_sales.catalog.manage','school_sales.catalog.activate']);
denyRole('accounting_admin', ['payment.concern.view','payment.discount']);
denyRole('accounting_admin', ['billing.individual.process','billing.bulk.process','billing.bulk.retry','billing.bulk.resume','payment.reconciliation.process','payment.reconciliation.import','payment_users.view','integration.paymongo.manage']);
allowRole('cashier', ['payment.collection','payment.walkin_history','payment.cashier_dashboard','billing.individual.review']);
denyRole('cashier', ['fee.manage','billing.bulk.view','billing.bulk.approve','payment_users.view','school_sales.catalog.view','school_sales.catalog.manage','school_sales.catalog.activate']);
allowRole('mis_admin', ['payment.security.view','payment_users.view','payment_users.create','payment_users.update','payment_users.role.assign','payment_users.activate','payment_users.deactivate','payment_users.unlock','payment_users.password.reset','payment_users.reset_password','integration.paymongo.manage','integration.ocr.manage']);
denyRole('mis_admin', ['integration.aub.manage']);
denyRole('mis_admin', ['fee.manage','billing.individual.process','ledger.view','billing.bulk.approve','school_sales.catalog.view','school_sales.catalog.manage','school_sales.catalog.activate']);
denyRole('superadmin', ['billing.individual.process','billing.bulk.process','billing.bulk.approve','fee.manage','payment_users.view','school_sales.catalog.view','school_sales.catalog.manage','school_sales.catalog.activate']);
denyRole('finance', ['integration.paymongo.manage','billing.bulk.approve','school_sales.catalog.view','school_sales.catalog.manage','school_sales.catalog.activate']);
denyRole('payment_admin', ['integration.paymongo.manage','billing.bulk.approve','school_sales.catalog.view','school_sales.catalog.manage','school_sales.catalog.activate']);
roleCheck(paymentPermissionDecision('accounting_officer', 'billing.bulk.process', true), 'Correct role with an explicit grant must be allowed');
roleCheck(!paymentPermissionDecision('accounting_officer', 'billing.bulk.process', false), 'An explicit operation revocation must block the correct role');
roleCheck(!paymentPermissionDecision('cashier', 'billing.bulk.process', true), 'A database grant must not exceed the hard-coded role ceiling');
roleCheck(paymentPermissionDecision('accounting_officer', 'billing.bulk.process', null), 'Missing granular rows must retain the role ceiling during migration');
roleCheck(!paymentPermissionDecision('accounting_officer', 'payment.reconciliation.import', null), 'Missing high-risk reconciliation rows must fail closed');
roleCheck(!paymentPermissionDecision('accounting_officer', 'payment.reconciliation.import', false), 'Explicit reconciliation revocation must deny the correct role');
roleCheck(!paymentPermissionDecision('cashier', 'payment.reconciliation.import', true), 'A reconciliation grant must not exceed the hard-coded role ceiling');
roleCheck(!paymentPermissionDecision('accounting_admin', 'payment.reconciliation.exception.approve', true), 'Unavailable exception approval capability must fail closed even within the Accounting Admin ceiling');
roleCheck(!paymentPermissionDecision('accounting_officer', 'payment.reconciliation.exception.approve', true), 'Accounting Officer must not receive exception approval authority');
allowRole('mis_admin', ['payment.mis_overview']);
foreach (['accounting_admin', 'accounting_officer', 'cashier', 'student', 'superadmin', 'finance', 'payment_admin', 'unknown'] as $role) {
    denyRole($role, ['payment.mis_overview', 'payment.security.view']);
}
denyRole('mis_admin', ['report.view', 'report.export', 'payment.unknown_permission']);
$overviewEndpoint = (string) file_get_contents(ROOT_PATH . '/modules/payment/api/mis_admin/overview.php');
roleCheck(str_contains($overviewEndpoint, "requirePaymentPermission('payment.mis_overview')"), 'MIS Overview API must require its independent capability');
$compat = (string) file_get_contents(ROOT_PATH . '/modules/payment/api/payment-admin-dashboard-data.php');
roleCheck(str_contains($compat, "'/mis_admin/overview.php'") && !str_contains($compat, 'PaymentAdminReportingService'), 'Legacy data route must retire financial reporting');
$landing = (string) file_get_contents(ROOT_PATH . '/dashboard/index.php');
roleCheck(str_contains($landing, "'mis_admin' => '/modules/payment/pages/mis_admin/dashboard.php'"), 'MIS must land on technical Overview');
roleCheck(str_contains($landing, "'accounting_admin' => '/modules/payment/pages/accounting_admin/dashboard.php'"), 'Accounting Admin must land on its canonical dashboard');
roleCheck(str_contains($landing, "'accounting_officer' => '/modules/payment/pages/accounting/dashboard-integrated.php'"), 'Accounting Officer must land on its operational dashboard');
roleCheck(str_contains($landing, 'smsNormalizeRoleKey(getCurrentUserRoleKey())'), 'Dashboard routing must normalize the authenticated role key');
$paymentConfig = (string) file_get_contents(ROOT_PATH . '/config/config.php');
roleCheck(str_contains($paymentConfig, "'accounting/collection-reporting-analytics',"), 'Accounting Officer navigation must include its report.view collection analytics module');
$officerReport = (string) file_get_contents(ROOT_PATH . '/modules/payment/pages/accounting/collection-reporting-integrated.php');
roleCheck(str_contains($officerReport, "requirePaymentPermission('report.view')"), 'Accounting Officer analytics must keep server-side report permission enforcement');
$officerModules = [
    'accounting/student-billing-invoicing' => ['billing.individual.process', 'accounting/student-billing-invoicing.php'],
    'accounting/discount-scholarship-application' => ['payment.discount', 'accounting/discount-scholarship-application.php'],
    'accounting/payment-history-ledger-system' => ['ledger.view', 'accounting/payment-history-ledger-system.php'],
    'accounting/payment-concern-portal' => ['payment.concern.view', 'accounting/payment-concern-portal.php'],
    'accounting/bank-reconciliation' => ['payment.reconciliation.view', 'accounting/bank-reconciliation.php'],
    'accounting/collection-reporting-analytics' => ['report.view', 'accounting/collection-reporting-integrated.php'],
];
foreach ($officerModules as $slug => [$permission, $route]) {
    roleCheck(str_contains($paymentConfig, "'slug' => '$slug'") && str_contains($paymentConfig, "'permission' => '$permission'"), "{$slug} navigation must use its canonical permission");
    roleCheck(is_file(ROOT_PATH . '/modules/payment/pages/' . $route), "{$slug} route must exist");
    $routeSource = (string) file_get_contents(ROOT_PATH . '/modules/payment/pages/' . $route);
    roleCheck(str_contains($routeSource, 'requirePaymentPermission') && str_contains($routeSource, "'$permission'"), "{$slug} must enforce its permission server-side");
}
$officerGroupStart = strpos($paymentConfig, "'ACCOUNTING PORTAL' => [");
$officerGroupEnd = strpos($paymentConfig, "'ACCOUNTING ADMIN PORTAL' => [", $officerGroupStart);
$officerGroup = substr($paymentConfig, $officerGroupStart, $officerGroupEnd - $officerGroupStart);
roleCheck(!str_contains($officerGroup, "'accounting_admin/managed-bulk-approval'") && !str_contains($officerGroup, "'accounting_admin/school-sales-catalog'"), 'Accounting Officer navigation must not expose Accounting Admin-only modules');
$adminGroupStart = strpos($paymentConfig, "'ACCOUNTING ADMIN PORTAL' => [");
$adminGroupEnd = strpos($paymentConfig, "'CASHIER PORTAL' => [", $adminGroupStart);
$adminGroup = substr($paymentConfig, $adminGroupStart, $adminGroupEnd - $adminGroupStart);
roleCheck(!str_contains($adminGroup, "'accounting/discount-scholarship-application'") && !str_contains($adminGroup, "'accounting/payment-concern-portal'"), 'Accounting Admin navigation must not expose Officer operational modules');
$reconciliationPage = (string) file_get_contents(ROOT_PATH . '/modules/payment/pages/accounting/bank-reconciliation.php');
$reconciliationImport = (string) file_get_contents(ROOT_PATH . '/modules/payment/api/accounting/import-bank-statement.php');
$authenticationSource = (string) file_get_contents(ROOT_PATH . '/includes/authentication.php');
$reconciliationMigration = (string) file_get_contents(ROOT_PATH . '/database/migrations/payment_reconciliation_permissions.sql');
$reconciliationRollback = (string) file_get_contents(ROOT_PATH . '/database/migrations/payment_reconciliation_permissions_rollback.sql');
roleCheck(str_contains($reconciliationPage, "requireAuth();") && str_contains($reconciliationPage, "requirePaymentPermission('payment.reconciliation.view')"), 'Direct reconciliation page access must require authentication and exact view permission');
roleCheck(str_contains($reconciliationPage, "paymentEffectivePermission(") && str_contains($reconciliationPage, "'payment.reconciliation.import'") && str_contains($reconciliationPage, 'if ($canImportStatements)'), 'Reconciliation portal must hide import controls from read-only viewers');
roleCheck(str_contains($reconciliationImport, "requireAuth();") && str_contains($reconciliationImport, "requirePaymentPermission('payment.reconciliation.import')"), 'Direct reconciliation import API access must require authentication and exact import permission');
roleCheck(str_contains($reconciliationImport, "ensurePaymentAccess(\$userId, \$role, 'payment.reconciliation.import'") && str_contains($reconciliationImport, "verifyCsrfToken("), 'Reconciliation import API must preserve scoped authorization and CSRF enforcement');
roleCheck(str_contains($reconciliationImport, "logActivity('import_aub_statement'") && str_contains($reconciliationImport, '(int)$uploaderId'), 'Successful imports must retain authenticated actor attribution in the audit log');
roleCheck((bool)preg_match('/function paymentEffectivePermission.*?if \(!\$pdo\)\s*\{\s*return false;/s', $authenticationSource) && str_contains($authenticationSource, 'catch (Throwable $e)'), 'Effective Payment authorization must fail closed when Core RBAC lookup is unavailable');
roleCheck(substr_count($reconciliationMigration, "'accounting_officer', 'payment.reconciliation.") === 3 && substr_count($reconciliationMigration, "'accounting_admin', 'payment.reconciliation.") === 2, 'Core migration must map exactly the approved Officer and Admin reconciliation grants');
roleCheck(str_contains($reconciliationMigration, 'ON DUPLICATE KEY UPDATE') && str_contains($reconciliationRollback, 'DELETE FROM role_permissions'), 'Reconciliation permission migration must be idempotent and include rollback SQL');
$managedBulkApi = (string) file_get_contents(ROOT_PATH . '/modules/payment/api/accounting/managed-billing-runs.php');
roleCheck(substr_count($managedBulkApi, 'paymentEffectivePermission(') >= 8, 'Managed bulk API must enforce effective operation permissions');
roleCheck(str_contains($managedBulkApi, "requireAuth();") && str_contains($managedBulkApi, "'billing.bulk.approve'") && str_contains($managedBulkApi, "'billing.bulk.process'"), 'Managed bulk API must preserve authentication and distinct approval/execution boundaries');
$receiptPage = (string) file_get_contents(ROOT_PATH . '/modules/payment/pages/cashier/print-receipt.php');
roleCheck(str_contains($receiptPage, "paymentEffectivePermission(\$role, 'ledger.view')") && !str_contains($receiptPage, "\$role === 'superadmin'"), 'Receipt reporting access must require scoped Payment authorization without a Super Admin bypass');
roleCheck(str_contains($receiptPage, "requireAuth();") && str_contains($receiptPage, "\$receipt['student_user_id'] !== \$viewerId") && str_contains($receiptPage, "\$receipt['cashier_id'] !== \$viewerId"), 'Receipt identifiers must remain constrained by authenticated student and cashier ownership');
$billingPage = (string) file_get_contents(ROOT_PATH . '/modules/payment/pages/accounting/student-billing-invoicing.php');
roleCheck(str_contains($billingPage, 'class="payment-pagination"') && str_contains($billingPage, "['billing_page'=>\$number]"), 'Billing pagination must render numbered server-side links');
roleCheck(str_contains($billingPage, 'Showing <strong>') && str_contains($billingPage, "'billing_page_size'"), 'Billing pagination must retain the actual record range and page size');
$historyPage = (string) file_get_contents(ROOT_PATH . '/modules/payment/pages/accounting/payment-history-ledger-system.php');
roleCheck(str_contains($historyPage, 'class="payment-pagination"') && str_contains($historyPage, 'aria-current="page"') && str_contains($historyPage, 'aria-disabled="true"'), 'Payment history pagination must use shared accessible states');
$cashierHistory = (string) file_get_contents(ROOT_PATH . '/modules/payment/pages/cashier/walk-in-transaction-history.php');
$bulkApprovalPager = (string) file_get_contents(ROOT_PATH . '/modules/payment/assets/js/managed-bulk-approval.js');
$billingPager = (string) file_get_contents(ROOT_PATH . '/modules/payment/assets/js/billing-invoicing.js');
$securityPager = (string) file_get_contents(ROOT_PATH . '/modules/payment/assets/js/payment-security-monitoring.js');
roleCheck(str_contains($cashierHistory, 'class="payment-pagination"') && str_contains($cashierHistory, '‹ Previous') && str_contains($cashierHistory, 'Next ›'), 'Cashier pagination must expose the shared previous/page/next pattern');
roleCheck(str_contains($bulkApprovalPager, 'class="payment-pagination mt-3"') && str_contains($bulkApprovalPager, 'class="page-link"') && str_contains($bulkApprovalPager, 'aria-current="page"'), 'Managed approval pagination must use shared accessible pagination markup');
roleCheck(str_contains($billingPager, 'class="payment-pagination"') && str_contains($billingPager, 'registrarCohortPageSize') && str_contains($billingPager, 'cohort_page:page'), 'Billing pagination must be shared and expose its existing server-side cohort pages');
roleCheck(str_contains($securityPager, "'‹ Previous'") && str_contains($securityPager, "'Next ›'"), 'MIS security pagination must use the cashier previous/next labels');
$studentHistory = (string) file_get_contents(ROOT_PATH . '/modules/student-portal/pages/payment-history.php');
roleCheck(str_contains($studentHistory, 'payment-components.css') && str_contains($studentHistory, 'class="payment-status <?= $statusTone ?>"') && str_contains($studentHistory, "'is-success'") && str_contains($studentHistory, "'is-danger'"), 'Student Payment History must use shared semantic status badges');
roleCheck(str_contains($studentHistory, 'aria-label="Print student copy"') && str_contains($studentHistory, 'aria-label="Receipt not yet available"'), 'Student Payment History icon actions must have accessible labels');
$paymentComponents = (string) file_get_contents(ROOT_PATH . '/modules/payment/assets/css/payment-components.css');
roleCheck(str_contains($paymentComponents, '.payment-page .badge.bg-success') && str_contains($paymentComponents, '.payment-page .badge.bg-warning') && str_contains($paymentComponents, '.payment-page .badge.bg-danger') && str_contains($paymentComponents, '.payment-page .badge.bg-info'), 'Legacy Payment badges must inherit the shared semantic status palette');
$paymentHeader = (string) file_get_contents(ROOT_PATH . '/includes/header.php');
roleCheck(str_contains($paymentHeader, 'payment-components.css?v=4'), 'Payment shared component cache version must advance when shared styles change');
$cashierDashboard = (string) file_get_contents(ROOT_PATH . '/modules/payment/pages/cashier/dashboard.php');
$cashierHistory = (string) file_get_contents(ROOT_PATH . '/modules/payment/pages/cashier/walk-in-transaction-history.php');
$accountingReport = (string) file_get_contents(ROOT_PATH . '/modules/payment/pages/accounting/collection-reporting-integrated.php');
$studentBalance = (string) file_get_contents(ROOT_PATH . '/modules/student-portal/pages/account-balance.php');
roleCheck(str_contains($cashierDashboard, "money=v=>'₱'") && str_contains($cashierHistory, 'class="payment-table-money">₱'), 'Cashier monetary displays must use the canonical peso symbol');
roleCheck(str_contains($accountingReport, '₱<?= number_format') && str_contains($studentBalance, '₱<?= number_format'), 'Accounting and student Payment amounts must use the canonical peso symbol');
$adminDashboard = (string) file_get_contents(ROOT_PATH . '/modules/payment/pages/accounting_admin/dashboard.php');
roleCheck(str_contains($adminDashboard, "requirePaymentPermission('billing.bulk.approve')"), 'Accounting Admin dashboard must remain outside Officer report access');
roleCheck(str_contains($adminDashboard, "requirePaymentPermission('report.view')") && str_contains($adminDashboard, 'AccountingReportingPageService'), 'Accounting Admin dashboard must use its authorized canonical reporting service');
roleCheck(str_contains($adminDashboard, 'official_academic_collections') && str_contains($adminDashboard, 'outstanding_balance') && !str_contains($adminDashboard, 'No verified data yet'), 'Accounting Admin dashboard must render live report-backed financial summaries');
roleCheck(smsPostLoginRedirectUrl() === BASE_URL . '/dashboard/index.php', 'Post-login helper must use canonical landing dispatcher');
$loginSource = (string) file_get_contents(ROOT_PATH . '/login/login.php');
$loginAttemptOffset = strpos($loginSource, '$result = smsLoginAttempt');
$loginRenderOffset = strpos($loginSource, "\$pageTitle = 'Login';", $loginAttemptOffset ?: 0);
$loginFailureFlow = $loginAttemptOffset !== false && $loginRenderOffset !== false ? substr($loginSource, $loginAttemptOffset, $loginRenderOffset - $loginAttemptOffset) : '';
roleCheck($loginFailureFlow !== '' && str_contains($loginFailureFlow, "header('Location: ' . \$loginSelfUrl)") && str_contains($loginFailureFlow, 'exit;'), 'Failed login must return to the login page and stop protected-route processing');
roleCheck(str_contains($loginFailureFlow, "\$_SESSION['flash_login_error']") && str_contains($loginFailureFlow, 'smsLoginGateSet') && str_contains($loginSource, 'smsCaptchaVerifyRequest()'), 'Failed-login handling must preserve safe feedback, lockout, and CAPTCHA controls');
$api = (string) file_get_contents(ROOT_PATH . '/modules/payment/api/mis_admin/payment-users.php');
$service = (string) file_get_contents(ROOT_PATH . '/modules/payment/includes/PaymentPersonnelService.php');
roleCheck(str_contains($service, "['accounting_admin', 'accounting_officer', 'cashier']"), 'MIS personnel service must whitelist only permitted Payment roles');
roleCheck(substr_count($service, 'role_key IN ') >= 5, 'Personnel mutations must retain SQL-level target scoping');
roleCheck(!str_contains($service, 'DELETE FROM users'), 'Personnel management must not hard-delete users');
roleCheck(str_contains($service, 'PAYMENT_USER_SCOPE_VIOLATION'), 'Horizontal scope attacks require safe rejection');
roleCheck(str_contains($service, 'must_change_password=1'), 'Password reset must force a password change');
foreach (['payment_users.create','payment_users.update','payment_users.role.assign','payment_users.activate','payment_users.deactivate','payment_users.unlock','payment_users.password.reset'] as $permission) {
    roleCheck(str_contains($api, "requirePaymentPermission('$permission')"), "Personnel API missing exact permission $permission");
}
roleCheck(str_contains($api, "actorRow['status'] !== 'active'"), 'Personnel API must revalidate active actor state');
roleCheck(str_contains($api, "actorRow['role_key'] !== 'mis_admin'"), 'Personnel API must revalidate MIS actor role');
$superSave = (string) file_get_contents(ROOT_PATH . '/modules/user-management/includes/save-user.php');
$validRolesLine = ''; foreach (preg_split('/\R/', $superSave) as $line) if (str_contains($line, '$validRoles =')) $validRolesLine = $line;
roleCheck(str_contains($validRolesLine, "'mis_admin'"), 'Global Super Admin must provision Payment MIS Admin');
$rbacResult = "PASS: $checks canonical PMS RBAC checks.\n";

} finally {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    // Only this run's private directory is enumerated; production sessions are never touched.
    foreach (scandir($testSessionDirectory) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $path = $testSessionDirectory . DIRECTORY_SEPARATOR . $entry;
        if (!is_file($path) || !unlink($path)) throw new RuntimeException('Test session artifact cleanup failed.');
    }
    if (!rmdir($testSessionDirectory)) throw new RuntimeException('Test session directory cleanup failed.');
    session_save_path($originalSessionPath);
    echo "SESSION CLEANUP: PASS\n";
}
echo $rbacResult;
