<?php

declare(strict_types=1);

if (!defined('ROOT_PATH')) define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/modules/payment/includes/SchoolSalesCatalogService.php';

final class TestCanonicalApplicabilityProvider implements SchoolSalesApplicabilityScopeProvider
{
    private array $scopes = [['BSIT','1'], ['BSIT','2'], ['BSCS','2'], ['BSCS','3']];
    public function isCanonicalProgram(string $programCode): bool { foreach ($this->scopes as $s) if ($s[0] === $programCode) return true; return false; }
    public function isCanonicalYearLevel(string $yearLevel): bool { foreach ($this->scopes as $s) if ($s[1] === $yearLevel) return true; return false; }
    public function isCanonicalProgramYear(string $programCode, string $yearLevel): bool { return in_array([$programCode, $yearLevel], $this->scopes, true); }
}

$checks = 0;
function applicabilityCheck(bool $ok, string $message): void { global $checks; $checks++; if (!$ok) throw new RuntimeException($message); }
function expectApplicabilityError(callable $operation, string $expected): void
{
    $actual = null;
    try { $operation(); } catch (SchoolSalesCatalogValidationException|SchoolSalesCatalogAuthorizationException|CatalogCorrelationConflictException $e) { $actual = $e->getMessage(); }
    applicabilityCheck($actual === $expected, "Expected {$expected}, received " . ($actual ?? 'no error'));
}

$payment = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$core = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$payment->exec("CREATE TABLE school_sale_categories (sale_category_id INTEGER PRIMARY KEY, category_code TEXT, category_name TEXT, sort_order INTEGER, status TEXT, created_by INTEGER, updated_by INTEGER, created_at TEXT, updated_at TEXT)");
$payment->exec("CREATE TABLE school_sale_item_types (sale_item_type_id INTEGER PRIMARY KEY, type_code TEXT, type_name TEXT, metadata_profile TEXT, status TEXT, sort_order INTEGER, created_by INTEGER, updated_by INTEGER, created_at TEXT, updated_at TEXT)");
$payment->exec("CREATE TABLE school_sale_items (sale_item_id INTEGER PRIMARY KEY, item_code TEXT, sale_category_id INTEGER, sale_item_type_id INTEGER, item_name TEXT, description TEXT, applicability_mode TEXT, status TEXT, created_by INTEGER, updated_by INTEGER, created_at TEXT, updated_at TEXT)");
$payment->exec("CREATE TABLE school_sale_item_variants (sale_variant_id INTEGER PRIMARY KEY, sale_item_id INTEGER, variant_code TEXT, variant_name TEXT, sku TEXT, size_label TEXT, variant_metadata TEXT, sort_order INTEGER, status TEXT, created_by INTEGER, updated_by INTEGER, created_at TEXT, updated_at TEXT)");
$payment->exec("CREATE TABLE school_sale_variant_prices (sale_variant_price_id INTEGER PRIMARY KEY, sale_variant_id INTEGER, amount NUMERIC, currency TEXT, effective_from TEXT, effective_to TEXT, status TEXT, created_by INTEGER, updated_by INTEGER, activated_by INTEGER, retired_by INTEGER, created_at TEXT, updated_at TEXT, activated_at TEXT, retired_at TEXT)");
$payment->exec("CREATE TABLE school_sale_item_applicability (
 sale_item_applicability_id INTEGER PRIMARY KEY AUTOINCREMENT, sale_item_id INTEGER NOT NULL,
 program_code TEXT, year_level TEXT, status TEXT NOT NULL, created_by INTEGER, updated_by INTEGER,
 deactivated_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT DEFAULT CURRENT_TIMESTAMP, deactivated_at TEXT)");
$payment->exec("CREATE UNIQUE INDEX uq_test_active_scope ON school_sale_item_applicability
 (sale_item_id, COALESCE(program_code,'__ANY__'), COALESCE(year_level,'__ANY__')) WHERE status='Active'");
$payment->exec("CREATE TABLE payment_audit_outbox (
 audit_outbox_id INTEGER PRIMARY KEY AUTOINCREMENT, correlation_id TEXT NOT NULL UNIQUE,
 request_fingerprint TEXT NOT NULL, action TEXT NOT NULL, module_key TEXT NOT NULL,
 entity_type TEXT NOT NULL, entity_id INTEGER, detail TEXT NOT NULL, before_state TEXT,
 after_state TEXT, committed_result TEXT NOT NULL, actor_user_id INTEGER, actor_user_name TEXT,
 actor_role_key TEXT, actor_ip_address TEXT, actor_user_agent TEXT,
 delivery_status TEXT NOT NULL DEFAULT 'Pending', attempt_count INTEGER NOT NULL DEFAULT 0,
 next_attempt_at TEXT, last_attempt_at TEXT, delivered_at TEXT, core_activity_log_id INTEGER,
 last_error TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$core->exec("CREATE TABLE activity_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, user_name TEXT, role_key TEXT, action TEXT, module_key TEXT, entity_type TEXT, entity_id INTEGER, detail TEXT, before_state TEXT, after_state TEXT, correlation_id TEXT UNIQUE, ip_address TEXT, user_agent TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$payment->exec("INSERT INTO school_sale_categories VALUES (1,'MISC','Misc',10,'Active',NULL,NULL,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
$payment->exec("INSERT INTO school_sale_item_types VALUES (1,'OTHER_MERCHANDISE','Other Merchandise','NONE','Active',10,NULL,NULL,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
$payment->exec("INSERT INTO school_sale_items VALUES
 (1,'ITEM-1',1,1,'Item One',NULL,'ALL','Draft',1,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP),
 (2,'ITEM-2',1,1,'Item Two',NULL,'ALL','Draft',1,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");

$service = new SchoolSalesCatalogService(
    $payment,
    new SchoolSalesCatalogMutationInfrastructure($payment, new PaymentAuditOutboxService($payment, new StructuredActivityAuditWriter($core))),
    new TestCanonicalApplicabilityProvider()
);
$actor = ['user_id' => 30, 'user_name' => 'Catalog Manager', 'role_key' => 'accounting_admin', 'permissions' => ['school_sales.catalog.manage']];

applicabilityCheck($service->itemApplicabilityHistory(1) === [], 'ALL item must begin with zero Active assignments.');
expectApplicabilityError(fn() => $service->addItemApplicabilityAssignment(1, CatalogCorrelationId::generate(), ['program_code' => 'BSIT'], $actor), 'RESTRICTED_MODE_REQUIRED');
$restricted = $service->changeItemApplicabilityMode(1, 'restricted', CatalogCorrelationId::generate(), $actor);
applicabilityCheck($restricted['result']['item']['applicability_mode'] === 'RESTRICTED', 'Draft item may enter RESTRICTED mode before assignments are completed.');

$programOnly = $service->addItemApplicabilityAssignment(1, CatalogCorrelationId::generate(), ['program_code' => ' bsit ', 'year_level' => null], $actor);
applicabilityCheck($programOnly['result']['program_code'] === 'BSIT' && $programOnly['result']['year_level'] === null, 'Program-only scope must normalize canonical program code.');
$yearOnly = $service->addItemApplicabilityAssignment(1, CatalogCorrelationId::generate(), ['program_code' => null, 'year_level' => '2'], $actor);
applicabilityCheck($yearOnly['result']['program_code'] === null && $yearOnly['result']['year_level'] === '2', 'Approved model must support canonical year-only scope.');
$combined = $service->addItemApplicabilityAssignment(1, CatalogCorrelationId::generate(), ['program_code' => 'BSCS', 'year_level' => '3'], $actor);
applicabilityCheck($combined['result']['program_code'] === 'BSCS' && $combined['result']['year_level'] === '3', 'Program and year scope must match an authoritative Registrar cohort.');
expectApplicabilityError(fn() => $service->addItemApplicabilityAssignment(1, CatalogCorrelationId::generate(), ['program_code' => 'BSIT'], $actor), 'DUPLICATE_ACTIVE_APPLICABILITY_SCOPE');
expectApplicabilityError(fn() => $service->addItemApplicabilityAssignment(1, CatalogCorrelationId::generate(), ['program_code' => 'BSBA'], $actor), 'UNKNOWN_CANONICAL_PROGRAM_CODE');
expectApplicabilityError(fn() => $service->addItemApplicabilityAssignment(1, CatalogCorrelationId::generate(), ['year_level' => '5'], $actor), 'INVALID_YEAR_LEVEL');
expectApplicabilityError(fn() => $service->addItemApplicabilityAssignment(1, CatalogCorrelationId::generate(), ['program_code' => 'BSIT', 'year_level' => '3'], $actor), 'UNKNOWN_CANONICAL_PROGRAM_YEAR');

$deactivated = $service->deactivateItemApplicabilityAssignment((int) $programOnly['result']['sale_item_applicability_id'], CatalogCorrelationId::generate(), $actor);
applicabilityCheck($deactivated['result']['status'] === 'Inactive' && $deactivated['result']['deactivated_at'] !== null, 'Deactivation must preserve row and populate lifecycle history.');
applicabilityCheck(count($service->itemApplicabilityHistory(1)) === 3, 'Inactive applicability history must remain readable.');

$replaced = $service->replaceItemApplicabilityAssignments(1, CatalogCorrelationId::generate(), [
    ['program_code' => 'BSIT', 'year_level' => '1'],
    ['program_code' => 'BSCS', 'year_level' => '2'],
], $actor);
$activeAfterReplace = array_values(array_filter($replaced['result']['applicability'], static fn(array $row): bool => $row['status'] === 'Active'));
$inactiveAfterReplace = array_values(array_filter($replaced['result']['applicability'], static fn(array $row): bool => $row['status'] === 'Inactive'));
applicabilityCheck(count($activeAfterReplace) === 2 && count($inactiveAfterReplace) === 3, 'Replacement must preserve old rows as Inactive and install exact new Active set.');

$all = $service->changeItemApplicabilityMode(1, 'ALL', CatalogCorrelationId::generate(), $actor);
$activeAfterAll = array_filter($all['result']['applicability'], static fn(array $row): bool => $row['status'] === 'Active');
applicabilityCheck($all['result']['item']['applicability_mode'] === 'ALL' && count($activeAfterAll) === 0, 'RESTRICTED to ALL must atomically deactivate every Active assignment.');
applicabilityCheck(count($all['result']['applicability']) === 5, 'RESTRICTED to ALL must never hard-delete applicability history.');

$replayId = CatalogCorrelationId::generate();
$set = [['program_code' => 'BSIT', 'year_level' => '2']];
$first = $service->replaceItemApplicabilityAssignments(2, $replayId, $set, $actor);
$replay = $service->replaceItemApplicabilityAssignments(2, $replayId, $set, $actor);
applicabilityCheck(!$first['idempotent_replay'] && $replay['idempotent_replay'] && $first['result'] === $replay['result'], 'Same correlation and assignment set must safely replay.');
expectApplicabilityError(fn() => $service->replaceItemApplicabilityAssignments(2, $replayId, [['program_code' => 'BSCS', 'year_level' => '2']], $actor), 'CORRELATION_ID_CONFLICT');
applicabilityCheck((int) $payment->query("SELECT COUNT(*) FROM school_sale_item_applicability WHERE sale_item_id=2 AND status='Active'")->fetchColumn() === 1, 'Replay/conflict must not duplicate assignment rows.');

applicabilityCheck((int) $payment->query("SELECT COUNT(*) FROM payment_audit_outbox WHERE action LIKE 'school_sale_applicability_%'")->fetchColumn() === 8, 'Every successful applicability mutation must insert one atomic outbox event.');
applicabilityCheck((int) $core->query("SELECT COUNT(*) FROM activity_logs WHERE action LIKE 'school_sale_applicability_%'")->fetchColumn() === 8, 'Every successful applicability mutation must deliver structured Core audit.');
$tables = $payment->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
applicabilityCheck(!array_intersect($tables, ['billing','billing_items','fees','payment_allocations','cash_sales']), 'Applicability management must not create or require billing, fee, allocation, or Cash Sale state.');
$page = (string) file_get_contents(ROOT_PATH . '/modules/payment/pages/accounting_admin/school-sales-catalog.php');
$source = (string) file_get_contents(ROOT_PATH . '/modules/payment/includes/SchoolSalesCatalogService.php');
applicabilityCheck(str_contains($page, 'CATALOG_MUTATIONS_PENDING') && !str_contains($page, 'addItemApplicabilityAssignment'), 'Production catalog POST/UI must keep applicability operations unexposed.');
applicabilityCheck(!str_contains($page, 'activateItem'), 'Item activation must remain internal and unexposed.');

echo "PASS: {$checks} School Sales applicability checks.\n";
