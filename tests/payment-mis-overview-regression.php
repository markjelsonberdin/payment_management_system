<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') throw new RuntimeException('CLI only');
require_once __DIR__ . '/../modules/payment/includes/MisOverviewService.php';
$checks = 0;
function checkMis(bool $condition, string $message): void {
    global $checks;
    $checks++;
    if (!$condition) throw new RuntimeException($message);
}
$core = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$core->exec('CREATE TABLE users (role_key TEXT, status TEXT, failed_login_attempts INTEGER, locked_until TEXT)');
$insert = $core->prepare('INSERT INTO users VALUES (?, ?, ?, ?)');
$now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
$insert->execute(['accounting_admin', 'active', 2, null]);
$insert->execute(['accounting_officer', 'inactive', 3, $now->modify('+1 hour')->format('Y-m-d H:i:s')]);
$insert->execute(['cashier', 'active', 1, $now->modify('-1 hour')->format('Y-m-d H:i:s')]);
foreach (['mis_admin', 'superadmin', 'student', 'faculty', 'registrar', 'finance', 'payment_admin'] as $role) {
    $insert->execute([$role, 'active', 99, $now->modify('+1 day')->format('Y-m-d H:i:s')]);
}
$core->exec('CREATE TABLE activity_logs (id INTEGER PRIMARY KEY, action TEXT, created_at TEXT, role_key TEXT, module_key TEXT, detail TEXT)');
$log = $core->prepare('INSERT INTO activity_logs (action,created_at,role_key,module_key,detail) VALUES (?, ?, ?, ?, ?)');
$log->execute(['accounting_user_create', '2026-10-04 10:00:00', 'mis_admin', 'payment', 'secret must never appear']);
$log->execute(['PAYMENT_USER_UNLOCKED', '2026-10-04 10:30:00', 'mis_admin', 'payment', 'new secret must never appear']);
$log->execute(['PAYMONGO_MODE_CHANGED', '2026-10-04 10:45:00', 'mis_admin', 'payment', 'integration secret must never appear']);
$log->execute(['payment_verify', '2026-10-04 11:00:00', 'mis_admin', 'payment', 'financial data']);
$log->execute(['accounting_user_edit', '2026-10-04 12:00:00', 'superadmin', 'payment', 'outside actor']);
$log->execute(['accounting_user_status', '2026-10-04 13:00:00', 'mis_admin', 'registrar', 'outside module']);
$technical = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$technical->exec('CREATE TABLE payment_gateway_settings (setting_key TEXT, setting_value TEXT)');
$technical->exec("INSERT INTO payment_gateway_settings VALUES ('gateway_mode', 'live'), ('fee_policy', 'private-financial-policy')");
$service = new MisOverviewService($core, $technical, ['live' => ['api' => true, 'webhook' => false]]);
$data = $service->load();
checkMis($data['accounts']['total'] === 3, 'Only three managed staff roles counted');
checkMis($data['accounts']['active'] === 2 && $data['accounts']['inactive'] === 1, 'Account status counts');
checkMis($data['accounts']['locked'] === 1, 'Only unexpired managed-account locks counted');
checkMis($data['accounts']['failed_attempts'] === 6, 'Only managed failed attempt counters summed');
checkMis($data['accounts']['by_role'] === ['accounting_admin' => 1, 'accounting_officer' => 1, 'cashier' => 1], 'Fixed role counts');
checkMis(count($data['activity']) === 3 && $data['activity'][0]['event'] === 'PayMongo mode changed'
    && $data['activity'][1]['event'] === 'Payment user unlocked'
    && $data['activity'][2]['event'] === 'Payment user created', 'Canonical integration and legacy MIS events exposed');
checkMis(!str_contains(json_encode($data), 'secret must never appear'), 'Raw audit details excluded');
checkMis(!str_contains(json_encode($data), 'new secret must never appear'), 'Canonical audit details excluded');
checkMis(!str_contains(json_encode($data), 'integration secret must never appear'), 'Integration audit details excluded');
checkMis(!str_contains(json_encode($data), 'private-financial-policy'), 'Financial settings excluded');
checkMis($data['paymongo']['environment'] === 'live' && $data['paymongo']['api_credentials'] === true && $data['paymongo']['webhook_secret'] === false, 'Only selected-environment presence flags');
checkMis(!array_key_exists('connected', $data['paymongo']), 'Configuration does not claim connectivity');
checkMis($data['integrations']['ocr'] === 'Configuration incomplete' && !array_key_exists('aub', $data['integrations']), 'OCR status remains honest and AUB remains deferred');
$technical->exec("INSERT INTO payment_gateway_settings VALUES ('ocr_enabled', '0'), ('ocr_project_id', 'bcp-payment-management-system'), ('ocr_mode', 'DOCUMENT_TEXT_DETECTION'), ('ocr_monthly_limit', '900')");
checkMis($service->load()['integrations']['ocr'] === 'Technical configuration saved', 'OCR overview reports saved non-secret configuration');
$technical->exec("UPDATE payment_gateway_settings SET setting_value='invalid' WHERE setting_key='gateway_mode'");
checkMis($service->load()['paymongo']['environment'] === null, 'Invalid mode cannot silently become test or live');
$partial = (new MisOverviewService($core))->load();
checkMis($partial['section_status']['paymongo'] === 'error' && $partial['accounts']['total'] === 3, 'Technical DB failure retains account information');
$core->exec('DROP TABLE activity_logs');
$partial = $service->load();
checkMis($partial['activity'] === null && $partial['section_status']['activity'] === 'error', 'Missing audit table is unavailable, not empty');
$core->exec('DELETE FROM users');
$empty = $service->load();
checkMis($empty['accounts']['total'] === 0 && $empty['accounts']['locked'] === 0, 'Empty accounts have real zero counts');
$core->exec('DROP TABLE users');
$broken = $service->load();
checkMis($broken['accounts'] === null && $broken['section_status']['accounts'] === 'error', 'Missing users table is unavailable, not zero');
echo "PASS: $checks MIS Overview data isolation and failure-state checks.\n";
