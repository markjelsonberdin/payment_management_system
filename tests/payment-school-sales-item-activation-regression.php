<?php

declare(strict_types=1);

if (!defined('ROOT_PATH')) define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/modules/payment/includes/SchoolSalesCatalogService.php';

$checks = 0;
function activationCheck(bool $ok, string $message): void { global $checks; $checks++; if (!$ok) throw new RuntimeException($message); }
function readinessHas(array $readiness, string $code): bool { return in_array($code, array_column($readiness['failures'], 'code'), true); }
function expectActivationError(callable $operation, string $prefix): void
{
    $actual = null;
    try { $operation(); } catch (SchoolSalesCatalogValidationException|SchoolSalesCatalogAuthorizationException|CatalogCorrelationConflictException $e) { $actual = $e->getMessage(); }
    activationCheck(is_string($actual) && str_starts_with($actual, $prefix), "Expected {$prefix}, received " . ($actual ?? 'no error'));
}
function expectPrerequisiteFailure(callable $operation, string $code): void
{
    $actual = null;
    try { $operation(); } catch (SchoolSalesCatalogValidationException $e) { $actual = $e->getMessage(); }
    activationCheck(is_string($actual) && str_starts_with($actual, 'ITEM_ACTIVATION_PREREQUISITES_FAILED:') && str_contains($actual, $code), "Activation must fail with {$code}.");
}

$payment = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$core = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$payment->exec("CREATE TABLE school_sale_categories (sale_category_id INTEGER PRIMARY KEY, category_code TEXT, category_name TEXT, sort_order INTEGER, status TEXT, created_by INTEGER, updated_by INTEGER, created_at TEXT, updated_at TEXT)");
$payment->exec("CREATE TABLE school_sale_item_types (sale_item_type_id INTEGER PRIMARY KEY, type_code TEXT, type_name TEXT, metadata_profile TEXT, status TEXT, sort_order INTEGER, created_by INTEGER, updated_by INTEGER, created_at TEXT, updated_at TEXT)");
$payment->exec("CREATE TABLE school_sale_items (sale_item_id INTEGER PRIMARY KEY, item_code TEXT, sale_category_id INTEGER, sale_item_type_id INTEGER, item_name TEXT, description TEXT, applicability_mode TEXT, status TEXT, created_by INTEGER, updated_by INTEGER, created_at TEXT, updated_at TEXT)");
$payment->exec("CREATE TABLE school_sale_item_variants (sale_variant_id INTEGER PRIMARY KEY, sale_item_id INTEGER, variant_code TEXT, variant_name TEXT, sku TEXT, size_label TEXT, variant_metadata TEXT, sort_order INTEGER, status TEXT, created_by INTEGER, updated_by INTEGER, created_at TEXT, updated_at TEXT)");
$payment->exec("CREATE TABLE school_sale_variant_prices (sale_variant_price_id INTEGER PRIMARY KEY, sale_variant_id INTEGER, amount NUMERIC, currency TEXT, effective_from TEXT, effective_to TEXT, status TEXT, created_by INTEGER, updated_by INTEGER, activated_by INTEGER, retired_by INTEGER, created_at TEXT, updated_at TEXT, activated_at TEXT, retired_at TEXT)");
$payment->exec("CREATE TABLE school_sale_item_applicability (sale_item_applicability_id INTEGER PRIMARY KEY, sale_item_id INTEGER, program_code TEXT, year_level TEXT, status TEXT, created_by INTEGER, updated_by INTEGER, deactivated_by INTEGER, created_at TEXT, updated_at TEXT, deactivated_at TEXT)");
$payment->exec("CREATE TABLE school_sale_book_details (sale_book_detail_id INTEGER PRIMARY KEY AUTOINCREMENT, sale_item_id INTEGER UNIQUE, book_title TEXT, author TEXT, publisher TEXT, edition TEXT, isbn TEXT, notes TEXT, created_by INTEGER, updated_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$payment->exec("CREATE TABLE payment_audit_outbox (audit_outbox_id INTEGER PRIMARY KEY AUTOINCREMENT, correlation_id TEXT UNIQUE, request_fingerprint TEXT, action TEXT, module_key TEXT, entity_type TEXT, entity_id INTEGER, detail TEXT, before_state TEXT, after_state TEXT, committed_result TEXT, actor_user_id INTEGER, actor_user_name TEXT, actor_role_key TEXT, actor_ip_address TEXT, actor_user_agent TEXT, delivery_status TEXT DEFAULT 'Pending', attempt_count INTEGER DEFAULT 0, next_attempt_at TEXT, last_attempt_at TEXT, delivered_at TEXT, core_activity_log_id INTEGER, last_error TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$core->exec("CREATE TABLE activity_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, user_name TEXT, role_key TEXT, action TEXT, module_key TEXT, entity_type TEXT, entity_id INTEGER, detail TEXT, before_state TEXT, after_state TEXT, correlation_id TEXT UNIQUE, ip_address TEXT, user_agent TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");

$payment->exec("INSERT INTO school_sale_categories VALUES
 (1,'ACTIVE','Active',10,'Active',NULL,NULL,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP),
 (2,'INACTIVE','Inactive',20,'Inactive',NULL,NULL,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
$payment->exec("INSERT INTO school_sale_item_types VALUES
 (1,'UNIFORM','Uniform','NONE','Active',10,NULL,NULL,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP),
 (2,'OLD','Old','NONE','Inactive',20,NULL,NULL,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP),
 (3,'BOOK','Book','BOOK','Active',30,NULL,NULL,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
$items = [
 [1,'READY',1,1,'Ready','ALL','Draft'], [2,'BAD-CAT',2,1,'Bad Cat','ALL','Draft'],
 [3,'BAD-TYPE',1,2,'Bad Type','ALL','Draft'], [4,'NO-VAR',1,1,'No Variant','ALL','Draft'],
 [5,'NO-PRICE',1,1,'No Price','ALL','Draft'], [6,'CONFLICT',1,1,'Conflict','ALL','Draft'],
 [7,'BAD-ALL',1,1,'Bad All','ALL','Draft'], [8,'EMPTY-REST',1,1,'Empty Restricted','RESTRICTED','Draft'],
 [9,'BOOK',1,3,'Book','ALL','Draft'], [10,'ARCHIVED',1,1,'Archived','ALL','Archived'],
 [11,'TWO-VAR',1,1,'Two Variants','ALL','Draft']
];
$insertItem = $payment->prepare("INSERT INTO school_sale_items VALUES (?,?,?,?,?,NULL,?,?,1,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
foreach ($items as $i) $insertItem->execute([$i[0],$i[1],$i[2],$i[3],$i[4],$i[5],$i[6]]);
$variants = [[1,1],[2,2],[3,3],[5,5],[6,6],[7,7],[8,8],[9,9],[10,10],[11,11],[12,11]];
$insertVariant = $payment->prepare("INSERT INTO school_sale_item_variants VALUES (?,?,'STANDARD','Standard',NULL,NULL,NULL,10,'Active',1,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
foreach ($variants as $v) { $insertVariant->execute($v); }
$pricedVariants = [1,2,3,7,8,9,10,11,12];
$insertPrice = $payment->prepare("INSERT INTO school_sale_variant_prices VALUES (?,?,'100.00','PHP','2020-01-01 00:00:00',NULL,'Active',1,1,1,NULL,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,NULL)");
$priceId = 1; foreach ($pricedVariants as $variantId) $insertPrice->execute([$priceId++,$variantId]);
$payment->exec("INSERT INTO school_sale_variant_prices VALUES
 (20,6,'100.00','PHP','2020-01-01 00:00:00',NULL,'Active',1,1,1,NULL,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,NULL),
 (21,6,'120.00','PHP','2021-01-01 00:00:00',NULL,'Active',1,1,1,NULL,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,NULL)");
$payment->exec("INSERT INTO school_sale_item_applicability VALUES (1,7,'BSIT',NULL,'Active',1,1,NULL,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,NULL)");

$service = new SchoolSalesCatalogService($payment, new SchoolSalesCatalogMutationInfrastructure(
    $payment, new PaymentAuditOutboxService($payment, new StructuredActivityAuditWriter($core))
));
$activate = ['user_id'=>40,'user_name'=>'Activator','role_key'=>'accounting_admin','permissions'=>['school_sales.catalog.activate']];
$manage = ['user_id'=>41,'permissions'=>['school_sales.catalog.manage']];
$at = new DateTimeImmutable('2026-10-02 00:00:00', new DateTimeZone('UTC'));

activationCheck($service->evaluateItemActivationReadiness(1, $at)['ready'] === true, 'Fully prepared item must be activation-ready.');
activationCheck(readinessHas($service->evaluateItemActivationReadiness(2, $at), 'CATEGORY_INACTIVE'), 'Inactive category must fail readiness separately.');
activationCheck(readinessHas($service->evaluateItemActivationReadiness(3, $at), 'ITEM_TYPE_INACTIVE'), 'Inactive controlled type must fail readiness separately.');
activationCheck(readinessHas($service->evaluateItemActivationReadiness(4, $at), 'NO_ACTIVE_VARIANTS'), 'Zero Active variants must fail readiness separately.');
activationCheck(readinessHas($service->evaluateItemActivationReadiness(5, $at), 'ACTIVE_VARIANT_MISSING_CURRENT_PRICE'), 'Missing current price must fail readiness separately.');
$conflict = $service->evaluateItemActivationReadiness(6, $at);
activationCheck(readinessHas($conflict, 'ACTIVE_VARIANT_MULTIPLE_CURRENT_PRICES'), 'Multiple current prices must fail readiness separately.');
activationCheck(readinessHas($conflict, 'ACTIVE_PRICE_WINDOW_OVERLAP'), 'Overlapping Active windows must fail readiness separately.');
activationCheck(readinessHas($service->evaluateItemActivationReadiness(7, $at), 'ALL_HAS_ACTIVE_APPLICABILITY'), 'ALL with Active applicability must fail readiness separately.');
activationCheck(readinessHas($service->evaluateItemActivationReadiness(8, $at), 'RESTRICTED_HAS_NO_ACTIVE_APPLICABILITY'), 'Empty RESTRICTED applicability must fail readiness separately.');
activationCheck(readinessHas($service->evaluateItemActivationReadiness(9, $at), 'BOOK_DETAILS_MISSING'), 'BOOK without metadata must remain blocked.');
activationCheck(readinessHas($service->evaluateItemActivationReadiness(10, $at), 'ITEM_ARCHIVED'), 'Archived item must fail readiness separately.');
activationCheck(readinessHas($service->evaluateItemActivationReadiness(999, $at), 'ITEM_NOT_FOUND'), 'Missing item must return canonical readiness failure.');

foreach ([2=>'CATEGORY_INACTIVE', 3=>'ITEM_TYPE_INACTIVE', 4=>'NO_ACTIVE_VARIANTS',
          5=>'ACTIVE_VARIANT_MISSING_CURRENT_PRICE', 6=>'ACTIVE_VARIANT_MULTIPLE_CURRENT_PRICES',
          7=>'ALL_HAS_ACTIVE_APPLICABILITY', 8=>'RESTRICTED_HAS_NO_ACTIVE_APPLICABILITY',
          9=>'BOOK_DETAILS_MISSING'] as $blockedItem => $failureCode) {
    expectPrerequisiteFailure(fn() => $service->activateItem($blockedItem, CatalogCorrelationId::generate(), $activate), $failureCode);
}
activationCheck((int) $payment->query("SELECT COUNT(*) FROM school_sale_items WHERE sale_item_id BETWEEN 2 AND 9 AND status='Draft'")->fetchColumn() === 8, 'Every rejected activation must roll back item state.');

expectActivationError(fn() => $service->activateItem(1, CatalogCorrelationId::generate(), $manage), 'SCHOOL_SALES_CATALOG_ACTIVATE_REQUIRED');
$correlation = CatalogCorrelationId::generate();
$activated = $service->activateItem(1, $correlation, $activate);
activationCheck($activated['result']['item']['status'] === 'Active' && $activated['result']['readiness']['ready'], 'activateItem must repeat locked readiness and activate valid item.');
$replay = $service->activateItem(1, $correlation, $activate);
activationCheck($replay['idempotent_replay'] && $replay['result'] === $activated['result'], 'Item activation must safely replay by correlation.');
expectActivationError(fn() => $service->activateItem(11, $correlation, $activate), 'CORRELATION_ID_CONFLICT');
$auditAfter = (string) $payment->query("SELECT after_state FROM payment_audit_outbox WHERE correlation_id='{$correlation}'")->fetchColumn();
activationCheck(str_contains($auditAfter, 'readiness') && str_contains($auditAfter, 'active_variants'), 'Activation audit state must include prerequisite summary.');

expectActivationError(fn() => $service->archiveItem(1, CatalogCorrelationId::generate(), $activate), 'ACTIVE_ITEM_MUST_BE_DEACTIVATED_FIRST');
$deactivated = $service->deactivateItem(1, CatalogCorrelationId::generate(), $activate);
activationCheck($deactivated['result']['status'] === 'Inactive', 'Active item must support explicit deactivation.');
$archived = $service->archiveItem(1, CatalogCorrelationId::generate(), $activate);
activationCheck($archived['result']['status'] === 'Archived', 'Inactive item must support terminal archival.');
expectActivationError(fn() => $service->activateItem(1, CatalogCorrelationId::generate(), $activate), 'ARCHIVED_ITEM_IMMUTABLE');

$activeEleven = $service->activateItem(11, CatalogCorrelationId::generate(), $activate);
activationCheck($activeEleven['result']['item']['status'] === 'Active', 'Multi-variant valid item must activate.');
$oneRemoved = $service->deactivateVariant(11, CatalogCorrelationId::generate(), $activate);
activationCheck($oneRemoved['result']['status'] === 'Inactive', 'Active-item variant change may proceed when invariants remain valid.');
expectActivationError(fn() => $service->deactivateVariant(12, CatalogCorrelationId::generate(), $activate), 'ACTIVE_ITEM_INVARIANT_VIOLATION:NO_ACTIVE_VARIANTS');
activationCheck($payment->query('SELECT status FROM school_sale_item_variants WHERE sale_variant_id=12')->fetchColumn() === 'Active', 'Rejected variant lifecycle must roll back and preserve Active-item consistency.');

activationCheck((int) $payment->query("SELECT COUNT(*) FROM payment_audit_outbox WHERE action IN ('school_sale_item_activated','school_sale_item_deactivated','school_sale_item_archived')")->fetchColumn() === 4, 'Successful item lifecycle operations must emit structured outbox events.');
activationCheck((int) $payment->query("SELECT COUNT(*) FROM payment_audit_outbox WHERE delivery_status='Delivered'")->fetchColumn() >= 5, 'Successful lifecycle and guarded variant events must reach Core audit.');
$page = (string) file_get_contents(ROOT_PATH . '/modules/payment/pages/accounting_admin/school-sales-catalog.php');
activationCheck(str_contains($page, 'CATALOG_MUTATIONS_PENDING') && !str_contains($page, 'activateItem'), 'Production catalog POST/UI must keep lifecycle operations unexposed.');
activationCheck(!array_intersect($payment->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN), ['billing','fees','payment_allocations','cash_sales']), 'Activation lifecycle must not create billing, fee, payment, or Cash Sale state.');

echo "PASS: {$checks} School Sales item activation/lifecycle checks.\n";
