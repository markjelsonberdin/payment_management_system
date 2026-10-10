<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('CLI only');
}

$root = dirname(__DIR__);
$page = file_get_contents($root . '/modules/user-management/pages/user-accounts.php');
$saveUser = file_get_contents($root . '/modules/user-management/includes/save-user.php');
$auth = file_get_contents($root . '/includes/authentication.php');
$legacySecurity = file_get_contents($root . '/account/module-security.php');
$moduleSecurity = file_get_contents($root . '/modules/user-management/pages/module-security.php');
$webhook = file_get_contents($root . '/modules/payment/api/paymongo/webhook.php');
$registrarClient = file_get_contents($root . '/modules/payment/includes/RegistrarStudentClient.php');
$dockerIgnore = file_get_contents($root . '/.dockerignore');
$apacheRules = file_get_contents($root . '/.htaccess');
$seedFiles = [
    file_get_contents($root . '/database/seed_accounts.php'),
    file_get_contents($root . '/database/seed_panel_members.php'),
];
$checks = 0;
function bootstrapCheck(bool $ok, string $message): void
{
    global $checks;
    $checks++;
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

bootstrapCheck(is_string($page) && is_string($saveUser), 'Required account-management source is missing');
bootstrapCheck(!preg_match('/\b(?:INSERT|UPDATE|DELETE)\s+(?:IGNORE\s+)?(?:INTO\s+)?(?:users|roles|role_permissions)\b/i', $page), 'User Accounts page contains account or permission mutations');
bootstrapCheck(!str_contains($page, 'password_hash('), 'User Accounts page contains a password bootstrap');
bootstrapCheck(!preg_match('/password\s*[\]\)]?\s*=>\s*[\'\"]/i', $page), 'User Accounts page contains a literal password value');
bootstrapCheck(str_contains($saveUser, 'isAuthenticated()') && str_contains($saveUser, 'userCanAccessModule(\'user-management\')'), 'Provisioning API lacks its authenticated permission gate');
bootstrapCheck(str_contains($saveUser, 'requireCsrf('), 'Provisioning API lacks CSRF validation');
bootstrapCheck(str_contains($saveUser, 'smsValidatePasswordForAccount('), 'Provisioning API bypasses the system password policy');
bootstrapCheck(str_contains($saveUser, 'smsBumpUserKickEpoch($pdo, $id)'), 'Admin password update does not revoke existing sessions');
$passwordSetter = is_string($auth) ? strstr($auth, 'function smsSetUserPassword(') : false;
$passwordSetter = is_string($passwordSetter) ? substr($passwordSetter, 0, (int) strpos($passwordSetter, "\nfunction logout")) : '';
bootstrapCheck(str_contains($passwordSetter, 'smsValidatePasswordForAccount('), 'Shared password setter bypasses account-aware policy');
bootstrapCheck(str_contains($passwordSetter, 'smsBumpUserKickEpoch($pdo, $userId)'), 'Forced reset does not revoke existing sessions');
bootstrapCheck(!str_contains($passwordSetter, 'failed_login_attempts') && !str_contains($passwordSetter, 'locked_until'), 'Password reset clears login lock state');
bootstrapCheck(is_string($legacySecurity) && !str_contains($legacySecurity, "'Temp@'") && !str_contains($legacySecurity, 'Temporary password:'), 'Legacy security screen generates or displays a temporary password');
bootstrapCheck(is_string($moduleSecurity) && str_contains($moduleSecurity, 'smsSetUserPassword($targetId, $newPassword, true)'), 'Module password reset does not force rotation and session revocation');
bootstrapCheck(is_string($moduleSecurity) && !str_contains($moduleSecurity, '$forceChange ='), 'Module password reset can disable forced rotation');
bootstrapCheck(is_string($moduleSecurity) && str_contains($moduleSecurity, "smsPasswordPolicy()['min']"), 'Password form does not use the system policy minimum');
bootstrapCheck(is_string($webhook) && str_contains($webhook, "error_log('PayMongo webhook processing failed:") && !str_contains($webhook, 'webhook_error.log'), 'Webhook logging still depends on a tracked application-directory log');
bootstrapCheck(is_string($registrarClient) && str_contains($registrarClient, "error_log('Payment student synchronization failed:") && !str_contains($registrarClient, 'sync_error.log'), 'Registrar synchronization logging still depends on a tracked application-directory log');
bootstrapCheck(is_string($dockerIgnore) && str_contains($dockerIgnore, '/scratch/'), 'Scratch artifacts are not excluded from Docker context');
bootstrapCheck(is_string($dockerIgnore) && !str_contains($dockerIgnore, '**/*.sql'), 'Docker rules broadly exclude SQL migrations');
bootstrapCheck(is_string($dockerIgnore) && str_contains($dockerIgnore, '/modules/payment/database/payment_db-*.sql'), 'Known payment database dumps are not excluded from Docker context');
bootstrapCheck(is_string($apacheRules) && str_contains($apacheRules, 'FilesMatch') && str_contains($apacheRules, 'RewriteRule ^(scratch|tests|docs|credentials|private)'), 'Apache defense-in-depth access rules are missing');
foreach ($seedFiles as $seed) {
    bootstrapCheck(is_string($seed), 'Deprecated seeder is missing');
    bootstrapCheck(!preg_match('/\b(?:INSERT|UPDATE|DELETE|REPLACE)\s+(?:IGNORE\s+)?(?:INTO\s+)?(?:users|roles|role_permissions)\b/i', $seed), 'Deprecated seeder can mutate account data');
    bootstrapCheck(!str_contains($seed, 'password_hash('), 'Deprecated seeder contains a password hash operation');
    bootstrapCheck(!preg_match('/password\s*[\]\)]?\s*=>\s*[\'\"]/i', $seed), 'Deprecated seeder contains a literal password value');
}

echo "PASS: {$checks} account-bootstrap security checks.\n";
