<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') throw new RuntimeException('This regression requires CLI execution.');
if (session_status() !== PHP_SESSION_NONE) throw new RuntimeException('Regression requires no pre-existing session.');
$originalSessionPath = session_save_path();
$testSessionDirectory = __DIR__ . '/.session-' . bin2hex(random_bytes(16));
if (!mkdir($testSessionDirectory, 0700)) throw new RuntimeException('Unable to create isolated test session directory.');
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
allowRole('accounting_officer', ['billing.individual.process','billing.bulk.preview','billing.bulk.create','billing.bulk.process','billing.bulk.retry','billing.bulk.resume','billing.bulk.view','payment.verify','payment.concern.review','ledger.view','ar.view','ar.manage']);
denyRole('accounting_officer', ['fee.activate','billing.bulk.approve','report.export','payment_users.view','integration.paymongo.manage','school_sales.catalog.view','school_sales.catalog.manage','school_sales.catalog.activate']);
allowRole('accounting_admin', ['fee.view','fee.manage','fee.activate','billing.individual.review','billing.bulk.view','billing.bulk.approve','report.view','report.export','ar.view','school_sales.catalog.view','school_sales.catalog.manage','school_sales.catalog.activate']);
denyRole('accounting_admin', ['billing.individual.process','billing.bulk.process','billing.bulk.retry','billing.bulk.resume','payment.concern.review','ledger.view','ar.manage','payment_users.view','integration.paymongo.manage']);
allowRole('cashier', ['payment.collection','payment.walkin_history','payment.cashier_dashboard','billing.individual.review']);
denyRole('cashier', ['fee.manage','billing.bulk.view','billing.bulk.approve','payment_users.view','school_sales.catalog.view','school_sales.catalog.manage','school_sales.catalog.activate']);
allowRole('mis_admin', ['payment_users.view','payment_users.create','payment_users.update','payment_users.activate','payment_users.reset_password','integration.paymongo.manage','integration.ocr.manage','integration.aub.manage']);
denyRole('mis_admin', ['fee.manage','billing.individual.process','ledger.view','billing.bulk.approve','school_sales.catalog.view','school_sales.catalog.manage','school_sales.catalog.activate']);
denyRole('superadmin', ['billing.individual.process','billing.bulk.process','billing.bulk.approve','fee.manage','payment_users.view','school_sales.catalog.view','school_sales.catalog.manage','school_sales.catalog.activate']);
denyRole('finance', ['integration.paymongo.manage','billing.bulk.approve','school_sales.catalog.view','school_sales.catalog.manage','school_sales.catalog.activate']);
denyRole('payment_admin', ['integration.paymongo.manage','billing.bulk.approve','school_sales.catalog.view','school_sales.catalog.manage','school_sales.catalog.activate']);
$api = (string) file_get_contents(ROOT_PATH . '/modules/payment/api/accounting/accounting-users.php');
roleCheck(str_contains($api, "['accounting_admin', 'accounting_officer', 'cashier']"), 'MIS personnel API must whitelist only permitted Payment roles');
roleCheck(substr_count($api, "role_key IN ('accounting_admin', 'accounting_officer', 'cashier')") >= 6, 'Personnel mutations must be server-side scoped');
roleCheck(!str_contains($api, 'DELETE FROM users'), 'Personnel management must not hard-delete users');
roleCheck(str_contains($api, 'ACCOUNTING_USER_SCOPE_VIOLATION'), 'Horizontal scope attacks require safe rejection');
roleCheck(str_contains($api, 'must_change_password=1'), 'Password reset must force a password change');
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
