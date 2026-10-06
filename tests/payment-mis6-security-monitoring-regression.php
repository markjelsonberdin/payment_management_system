<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') throw new RuntimeException('CLI only');

require_once __DIR__ . '/../modules/payment/includes/PaymentSecurityMonitoringService.php';

$checks = 0;
function mis6Check(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) throw new RuntimeException($message);
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE users (
    id INTEGER PRIMARY KEY, full_name TEXT, username TEXT, email TEXT, role_key TEXT, status TEXT,
    failed_login_attempts INTEGER, locked_until TEXT, password_hash TEXT, totp_secret TEXT, reset_token TEXT
)');
$pdo->exec('CREATE TABLE activity_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, user_name TEXT, role_key TEXT, action TEXT,
    module_key TEXT, entity_type TEXT, entity_id INTEGER, detail TEXT, before_state TEXT, after_state TEXT,
    correlation_id TEXT, ip_address TEXT, user_agent TEXT, created_at TEXT
)');
$insertUser = $pdo->prepare('INSERT INTO users VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$now = new DateTimeImmutable('2026-10-05 12:00:00', new DateTimeZone('Asia/Manila'));
$users = [
    [1, 'Admin Active', 'admin.active', 'a@example.test', 'accounting_admin', 'active', 0, null],
    [2, 'Officer Locked', 'officer.locked', 'b@example.test', 'accounting_officer', 'active', 3, '2026-10-05 13:00:00'],
    [3, 'Cashier Disabled', 'cashier.disabled', 'c@example.test', 'cashier', 'inactive', 1, null],
    [4, 'Admin Disabled Locked', 'admin.disabled.locked', 'd@example.test', 'accounting_admin', 'inactive', 5, '2026-10-05 13:30:00'],
    [5, 'Officer Expired', 'officer.expired', 'e@example.test', 'accounting_officer', 'locked', 2, '2026-10-05 11:00:00'],
    [6, 'MIS Hidden', 'mis.hidden', 'mis@example.test', 'mis_admin', 'active', 99, '2026-10-06 12:00:00'],
    [7, 'Student Hidden', 'student.hidden', 'student@example.test', 'student', 'active', 99, '2026-10-06 12:00:00'],
];
foreach ($users as $user) $insertUser->execute(array_merge($user, ['HASH-MUST-NOT-LEAK', 'TOTP-MUST-NOT-LEAK', 'TOKEN-MUST-NOT-LEAK']));

$log = $pdo->prepare('INSERT INTO activity_logs (user_id,user_name,role_key,action,module_key,entity_type,entity_id,detail,before_state,after_state,correlation_id,ip_address,user_agent,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
$uuid = '11111111-1111-4111-8111-111111111111';
$log->execute([2, 'Officer Locked', 'accounting_officer', 'login_failed', 'System', null, null, 'Invalid password', null, null, null, '127.0.0.1', 'safe-agent', '2026-10-05 10:00:00']);
$log->execute([2, 'Officer Locked', 'accounting_officer', 'lockout', 'System', null, null, 'Locked', null, null, null, '127.0.0.1', 'safe-agent', '2026-10-05 10:01:00']);
$log->execute([99, 'MIS Actor', 'mis_admin', 'PAYMENT_USER_ROLE_CHANGED', 'payment', 'payment_user', 1, 'safe', '{}', '{"sessions_revoked":true,"token":"SECRET"}', $uuid, '127.0.0.1', 'safe-agent', '2026-10-04 10:00:00']);
$log->execute([99, 'MIS Actor', 'mis_admin', 'accounting_user_password_reset', 'payment', 'payment_user', 3, 'legacy', null, null, null, null, null, '2026-10-03 10:00:00']);
$log->execute([7, 'Student Hidden', 'student', 'login_failed', 'System', null, null, 'outside scope', null, null, null, '127.0.0.1', null, '2026-10-05 09:00:00']);
$log->execute([99, 'MIS Actor', 'mis_admin', 'PAYMENT_USER_ROLE_CHANGED', 'payment', 'payment_user', 999, 'unresolved target', null, '{"sessions_revoked":true}', '22222222-2222-4222-8222-222222222222', null, null, '2026-10-05 08:00:00']);
$log->execute([99, 'MIS Actor', 'mis_admin', 'payment_verify', 'payment', 'payment', 1, 'financial event', null, null, null, null, null, '2026-10-05 07:00:00']);

$service = new PaymentSecurityMonitoringService($pdo, $now);
$data = $service->load([]);
mis6Check($data['summary']['locked_accounts'] === 2, 'Only current managed locks counted');
mis6Check($data['summary']['accounts_with_failed_attempts'] === 4, 'Current failed-attempt accounts counted');
mis6Check($data['summary']['disabled_accounts'] === 2, 'Disabled accounts counted independently');
mis6Check($data['summary']['recent_session_revocations'] === 1, 'Recorded session revocations counted');
mis6Check($data['meta']['active_session_count_available'] === false, 'No active-session count invented');
mis6Check($data['personnel']['total'] === 5, 'Only managed Payment roles listed');
mis6Check(!in_array('MIS Hidden', array_column($data['personnel']['items'], 'full_name'), true), 'MIS Admin excluded from targets');
mis6Check(!in_array('Student Hidden', array_column($data['personnel']['items'], 'full_name'), true), 'Student excluded from targets');
$byName = array_column($data['personnel']['items'], null, 'full_name');
mis6Check($byName['Admin Active']['administrative_state'] === 'ACTIVE' && $byName['Admin Active']['security_state'] === 'UNLOCKED', 'Active unlocked state');
mis6Check($byName['Officer Locked']['administrative_state'] === 'ACTIVE' && $byName['Officer Locked']['security_state'] === 'LOCKED', 'Active locked state');
mis6Check($byName['Cashier Disabled']['administrative_state'] === 'INACTIVE' && $byName['Cashier Disabled']['security_state'] === 'UNLOCKED', 'Inactive unlocked state');
mis6Check($byName['Admin Disabled Locked']['administrative_state'] === 'INACTIVE' && $byName['Admin Disabled Locked']['security_state'] === 'LOCKED', 'Inactive locked state');
mis6Check($byName['Officer Expired']['administrative_state'] === 'ACTIVE' && $byName['Officer Expired']['security_state'] === 'LOCK_EXPIRED', 'Expired lock interpreted read-only');
mis6Check($byName['Officer Locked']['current_failed_attempts'] === 3, 'Counter clearly current');
mis6Check(count($byName['Admin Disabled Locked']['alerts']) === 3, 'Deterministic alerts derived');

$encoded = json_encode($data, JSON_THROW_ON_ERROR);
foreach (['HASH-MUST-NOT-LEAK','TOTP-MUST-NOT-LEAK','TOKEN-MUST-NOT-LEAK','SECRET','safe-agent'] as $secret) {
    mis6Check(!str_contains($encoded, $secret), "Sensitive field leaked: {$secret}");
}
mis6Check(count($data['events']['items']) === 4, 'Only resolved managed-user security events visible');
$types = array_column($data['events']['items'], 'event_type');
mis6Check(in_array('LOGIN_FAILED', $types, true), 'Login failures visible');
mis6Check(in_array('ACCOUNT_LOCKED', $types, true), 'Lockouts visible');
mis6Check(in_array('PAYMENT_USER_ROLE_CHANGED', $types, true), 'Canonical events visible');
mis6Check(in_array('PAYMENT_USER_PASSWORD_RESET', $types, true), 'Approved legacy alias normalized');
mis6Check(!str_contains($encoded, 'unresolved target'), 'Unresolved target omitted');
mis6Check(!str_contains($encoded, 'financial event'), 'Unrelated global event excluded');
$revocations = array_filter($data['events']['items'], static fn(array $event): bool => $event['sessions_revoked']);
mis6Check(count($revocations) === 1 && str_contains(reset($revocations)['safe_context'], 'revoked'), 'Session revocation evidence visible safely');

mis6Check($service->load(['role' => 'cashier'])['personnel']['total'] === 1, 'Role filter');
mis6Check($service->load(['administrative_status' => 'inactive'])['personnel']['total'] === 2, 'Administrative filter');
mis6Check($service->load(['lock_state' => 'locked'])['personnel']['total'] === 2, 'Lock-state filter');
mis6Check($service->load(['lock_state' => 'lock_expired'])['personnel']['total'] === 1, 'Expired-lock filter');
mis6Check($service->load(['failed_attempts' => 'present'])['personnel']['total'] === 4, 'Failed-attempt filter');
mis6Check($service->load(['event_type' => 'LOGIN_FAILED'])['events']['total'] === 1, 'Event-type filter');
mis6Check($service->load(['target_user' => 2])['events']['total'] === 2, 'Target filter');
mis6Check($service->load(['result' => 'success'])['events']['total'] === 2, 'Result filter');

foreach ([['role'=>'student'], ['page_size'=>101], ['page_size'=>1], ['lock_state'=>'compromised'], ['arbitrary'=>'x'], ['target_user'=>-1], ['date_from'=>'2026-99-99'], ['date_from'=>'2026-10-06','date_to'=>'2026-10-05']] as $bad) {
    try { $service->load($bad); mis6Check(false, 'Malformed filter accepted'); }
    catch (InvalidArgumentException) { mis6Check(true, 'Malformed filter rejected'); }
}

$auth = file_get_contents(__DIR__ . '/../includes/authentication.php');
$config = file_get_contents(__DIR__ . '/../config/config.php');
$page = file_get_contents(__DIR__ . '/../modules/payment/pages/mis_admin/security-monitoring.php');
$api = file_get_contents(__DIR__ . '/../modules/payment/api/mis_admin/security-monitoring.php');
$js = file_get_contents(__DIR__ . '/../modules/payment/assets/js/payment-security-monitoring.js');
mis6Check(str_contains($auth, "'payment.mis_overview', 'payment.security.view'"), 'MIS permission granted');
foreach (['accounting_admin','accounting_officer','cashier'] as $role) {
    $start = strpos($auth, "'{$role}' => ["); $end = strpos($auth, '],', $start);
    mis6Check(!str_contains(substr($auth, $start, $end - $start), 'payment.security.view'), "{$role} denied monitoring permission");
}
mis6Check(str_contains($config, "'mis_admin/security-monitoring'") && str_contains($config, "'permission' => 'payment.security.view'"), 'Permission-gated navigation');
mis6Check(str_contains($page, "requirePaymentPermission('payment.security.view')"), 'Page layer permission');
mis6Check(str_contains($api, "requirePaymentPermission('payment.security.view')"), 'API layer permission');
mis6Check(str_contains($api, "'AUTHENTICATION_REQUIRED'") && str_contains($api, "'NOT_AUTHORIZED'"), 'API returns safe 401/403 responses');
mis6Check(str_contains($api, "actorRow['status'] !== 'active'") && str_contains($api, "actorRow['role_key'] !== 'mis_admin'"), 'API revalidates active current MIS actor');
mis6Check(str_contains($js, "action: 'unlock'") && str_contains($js, 'app.dataset.personnelApi'), 'Canonical unlock endpoint reused');
mis6Check(!str_contains($js, 'summary-filter') && !str_contains($js, 'View accounts'), 'Overview cards remain informational without shortcut controls');
foreach (['Locked Accounts', 'Failed Sign-ins', 'Disabled Accounts', 'Revoked Sessions'] as $simpleLabel) {
    mis6Check(str_contains($js, $simpleLabel), "Simple summary label retained: {$simpleLabel}");
}
mis6Check(!str_contains($js, 'Security monitoring updated.') && !str_contains($page, 'Loading security information'), 'Passive refresh and loading banners stay hidden');
mis6Check(str_contains($page, 'id="securityNotice" class="d-none"') && str_contains($js, "alert alert-danger"), 'Only actionable failures use the compact notice component');
mis6Check(!str_contains($api, 'UPDATE ') && !str_contains($api, 'DELETE ') && !str_contains($api, 'INSERT '), 'Monitoring API is read-only');
mis6Check(!str_contains($page, 'active sessions') && str_contains($page, 'Active-session counts are unavailable'), 'No active-session inventory claim');

echo "PASS: {$checks} MIS-6 security monitoring checks.\n";
