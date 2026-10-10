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
checkMis(!array_key_exists('activity', $data) && !array_key_exists('activity', $data['section_status']), 'MIS Overview no longer loads or exposes the removed administrative activity feed');
checkMis(!str_contains(json_encode($data), 'private-financial-policy'), 'Financial settings excluded');
checkMis($data['paymongo']['environment'] === 'live' && $data['paymongo']['api_credentials'] === true && $data['paymongo']['webhook_secret'] === false, 'Only selected-environment presence flags');
checkMis(!array_key_exists('connected', $data['paymongo']), 'Configuration does not claim connectivity');
checkMis($data['integrations']['ocr'] === 'Configuration incomplete' && !array_key_exists('aub', $data['integrations']), 'OCR status remains honest and AUB remains deferred');
checkMis(count($data['ocr']['readiness']['steps'] ?? []) === 8, 'Dashboard exposes the shared OCR readiness and enablement checklist');
checkMis(($data['ocr']['readiness']['state'] ?? '') !== 'READY', 'Dashboard never reports OCR ready without verified live processing');
$technical->exec("INSERT INTO payment_gateway_settings VALUES ('ocr_enabled', '0'), ('ocr_project_id', 'bcp-payment-management-system'), ('ocr_mode', 'DOCUMENT_TEXT_DETECTION'), ('ocr_monthly_limit', '900')");
checkMis($service->load()['integrations']['ocr'] === 'Technical configuration saved', 'OCR overview reports saved non-secret configuration');
$technical->exec("UPDATE payment_gateway_settings SET setting_value='invalid' WHERE setting_key='gateway_mode'");
checkMis($service->load()['paymongo']['environment'] === null, 'Invalid mode cannot silently become test or live');
$dashboardPage = file_get_contents(__DIR__ . '/../modules/payment/pages/mis_admin/dashboard.php');
$dashboardScript = file_get_contents(__DIR__ . '/../modules/payment/assets/js/payment-admin-dashboard.js');
checkMis(!str_contains($dashboardPage, 'refreshDashboard') && !str_contains($dashboardPage, '>Refresh</button>'), 'MIS dashboard has no manual refresh control');
checkMis(!str_contains($dashboardScript, 'refreshDashboard') && str_contains($dashboardScript, 'window.setInterval'), 'MIS dashboard refreshes data automatically without a click handler');
checkMis(str_contains($dashboardScript, '10000') && str_contains($dashboardScript, "document.visibilityState === 'visible'"), 'Dashboard polls every ten seconds only while visible');
$partial = (new MisOverviewService($core))->load();
checkMis($partial['section_status']['paymongo'] === 'error' && $partial['accounts']['total'] === 3, 'Technical DB failure retains account information');
$core->exec('DELETE FROM users');
$empty = $service->load();
checkMis($empty['accounts']['total'] === 0 && $empty['accounts']['locked'] === 0, 'Empty accounts have real zero counts');
$core->exec('DROP TABLE users');
$broken = $service->load();
checkMis($broken['accounts'] === null && $broken['section_status']['accounts'] === 'error', 'Missing users table is unavailable, not zero');
echo "PASS: $checks MIS Overview data isolation and failure-state checks.\n";
