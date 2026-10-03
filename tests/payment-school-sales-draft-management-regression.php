<?php

declare(strict_types=1);

if (!defined('ROOT_PATH')) define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/modules/payment/includes/SchoolSalesCatalogService.php';

$checks = 0;
function draftCheck(bool $ok, string $message): void
{
    global $checks;
    $checks++;
    if (!$ok) throw new RuntimeException($message);
}
function expectCatalogError(callable $operation, string $expected): void
{
    $actual = null;
    try { $operation(); } catch (SchoolSalesCatalogValidationException|SchoolSalesCatalogAuthorizationException|CatalogCorrelationConflictException $e) { $actual = $e->getMessage(); }
    draftCheck($actual === $expected, "Expected {$expected}, received " . ($actual ?? 'no error'));
}

$payment = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$core = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$payment->exec("CREATE TABLE school_sale_categories (
 sale_category_id INTEGER PRIMARY KEY, category_code TEXT NOT NULL, category_name TEXT NOT NULL,
 sort_order INTEGER NOT NULL, status TEXT NOT NULL, created_by INTEGER, updated_by INTEGER,
 created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$payment->exec("CREATE TABLE school_sale_item_types (
 sale_item_type_id INTEGER PRIMARY KEY, type_code TEXT NOT NULL, type_name TEXT NOT NULL,
 metadata_profile TEXT NOT NULL, status TEXT NOT NULL, sort_order INTEGER NOT NULL,
 created_by INTEGER, updated_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$payment->exec("CREATE TABLE school_sale_items (
 sale_item_id INTEGER PRIMARY KEY AUTOINCREMENT, item_code TEXT NOT NULL UNIQUE,
 sale_category_id INTEGER NOT NULL, sale_item_type_id INTEGER NOT NULL, item_name TEXT NOT NULL,
 description TEXT, applicability_mode TEXT NOT NULL, status TEXT NOT NULL,
 created_by INTEGER, updated_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$payment->exec("CREATE TABLE school_sale_item_variants (
 sale_variant_id INTEGER PRIMARY KEY AUTOINCREMENT, sale_item_id INTEGER NOT NULL,
 variant_code TEXT NOT NULL, variant_name TEXT NOT NULL, sku TEXT UNIQUE, size_label TEXT,
 variant_metadata TEXT, sort_order INTEGER NOT NULL, status TEXT NOT NULL,
 created_by INTEGER, updated_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT DEFAULT CURRENT_TIMESTAMP, UNIQUE(sale_item_id, variant_code))");
$payment->exec("CREATE TABLE school_sale_variant_prices (
 sale_variant_price_id INTEGER PRIMARY KEY, sale_variant_id INTEGER, amount REAL, currency TEXT,
 effective_from TEXT, effective_to TEXT, status TEXT, created_by INTEGER, updated_by INTEGER,
 activated_by INTEGER, retired_by INTEGER, created_at TEXT, updated_at TEXT, activated_at TEXT, retired_at TEXT)");
$payment->exec("CREATE TABLE school_sale_item_applicability (
 sale_item_applicability_id INTEGER PRIMARY KEY, sale_item_id INTEGER, program_code TEXT,
 year_level TEXT, status TEXT, created_by INTEGER, updated_by INTEGER, deactivated_by INTEGER,
 created_at TEXT, updated_at TEXT, deactivated_at TEXT)");
$payment->exec("CREATE TABLE payment_audit_outbox (
 audit_outbox_id INTEGER PRIMARY KEY AUTOINCREMENT, correlation_id TEXT NOT NULL UNIQUE,
 request_fingerprint TEXT NOT NULL, action TEXT NOT NULL, module_key TEXT NOT NULL,
 entity_type TEXT NOT NULL, entity_id INTEGER, detail TEXT NOT NULL, before_state TEXT,
 after_state TEXT, committed_result TEXT NOT NULL, actor_user_id INTEGER, actor_user_name TEXT,
 actor_role_key TEXT, actor_ip_address TEXT, actor_user_agent TEXT,
 delivery_status TEXT NOT NULL DEFAULT 'Pending', attempt_count INTEGER NOT NULL DEFAULT 0,
 next_attempt_at TEXT, last_attempt_at TEXT, delivered_at TEXT, core_activity_log_id INTEGER,
 last_error TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$core->exec("CREATE TABLE activity_logs (
 id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, user_name TEXT, role_key TEXT,
 action TEXT NOT NULL, module_key TEXT, entity_type TEXT, entity_id INTEGER, detail TEXT NOT NULL,
 before_state TEXT, after_state TEXT, correlation_id TEXT UNIQUE, ip_address TEXT,
 user_agent TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$payment->exec("INSERT INTO school_sale_categories VALUES
 (1,'UNIFORM','Uniform',10,'Active',NULL,NULL,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP),
 (2,'OLD','Old',20,'Inactive',NULL,NULL,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
$payment->exec("INSERT INTO school_sale_item_types VALUES
 (1,'UNIFORM','Uniform','NONE','Active',10,NULL,NULL,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP),
 (2,'BOOK','Book','BOOK','Inactive',20,NULL,NULL,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");

$outbox = new PaymentAuditOutboxService($payment, new StructuredActivityAuditWriter($core));
$infra = new SchoolSalesCatalogMutationInfrastructure($payment, $outbox);
$service = new SchoolSalesCatalogService($payment, $infra);
$actor = ['user_id' => 12, 'user_name' => 'Accounting Admin', 'role_key' => 'accounting_admin',
    'permissions' => ['school_sales.catalog.view', 'school_sales.catalog.manage'],
    'ip_address' => '127.0.0.1', 'user_agent' => 'CLI'];
$itemInput = ['item_code' => ' uni-001 ', 'item_name' => ' PE Uniform ', 'sale_category_id' => 1,
    'sale_item_type_id' => 1, 'description' => 'Standard PE uniform', 'applicability_mode' => 'all'];

$created = $service->createDraftItem(CatalogCorrelationId::generate(), $itemInput, $actor);
$item = $created['result'];
draftCheck($created['committed'] && $created['audit_status'] === 'delivered', 'Valid item creation must commit with delivered audit.');
draftCheck($item['status'] === 'Draft' && $item['item_code'] === 'UNI-001', 'New item must be normalized and forced to Draft.');
draftCheck((int) $payment->query('SELECT COUNT(*) FROM school_sale_item_variants')->fetchColumn() === 0, 'Item creation must not silently create STANDARD variant.');
draftCheck($payment->query("SELECT action FROM payment_audit_outbox WHERE entity_type='school_sale_item'")->fetchColumn() === 'school_sale_item_created', 'Item creation must use structured audit action.');

expectCatalogError(fn() => $service->createDraftItem(CatalogCorrelationId::generate(), array_replace($itemInput, ['status' => 'Active']), $actor), 'CLIENT_CONTROLLED_ITEM_STATUS_FORBIDDEN');
expectCatalogError(fn() => $service->createDraftItem(CatalogCorrelationId::generate(), array_replace($itemInput, ['item_code' => 'bad code']), $actor), 'INVALID_ITEM_CODE');
expectCatalogError(fn() => $service->createDraftItem(CatalogCorrelationId::generate(), array_replace($itemInput, ['item_code' => 'UNI-002', 'sale_category_id' => 999]), $actor), 'ACTIVE_CATEGORY_REQUIRED');
expectCatalogError(fn() => $service->createDraftItem(CatalogCorrelationId::generate(), array_replace($itemInput, ['item_code' => 'UNI-002', 'sale_item_type_id' => 2]), $actor), 'ACTIVE_CONTROLLED_ITEM_TYPE_REQUIRED');
expectCatalogError(fn() => $service->createDraftItem(CatalogCorrelationId::generate(), $itemInput, $actor), 'DUPLICATE_ITEM_CODE');
expectCatalogError(fn() => $service->createDraftItem(CatalogCorrelationId::generate(), array_replace($itemInput, ['item_code' => 'UNI-003']), ['user_id' => 13, 'permissions' => ['school_sales.catalog.view']]), 'SCHOOL_SALES_CATALOG_MANAGE_REQUIRED');

$updated = $service->updateDraftItem((int) $item['sale_item_id'], CatalogCorrelationId::generate(), array_replace($itemInput, ['item_name' => 'Updated PE Uniform', 'applicability_mode' => 'RESTRICTED']), $actor);
draftCheck($updated['result']['item_name'] === 'Updated PE Uniform' && $updated['result']['status'] === 'Draft', 'Draft item update must preserve Draft status.');
$payment->exec("UPDATE school_sale_items SET status='Archived' WHERE sale_item_id=" . (int) $item['sale_item_id']);
expectCatalogError(fn() => $service->updateDraftItem((int) $item['sale_item_id'], CatalogCorrelationId::generate(), $itemInput, $actor), 'ARCHIVED_ITEM_IMMUTABLE');
$payment->exec("UPDATE school_sale_items SET status='Draft' WHERE sale_item_id=" . (int) $item['sale_item_id']);

$variantInput = ['variant_code' => ' small ', 'variant_name' => 'Small', 'sku' => ' uni-pe-s ',
    'size_label' => 'S', 'variant_metadata' => ['color' => 'Blue'], 'sort_order' => 10];
$variantCreated = $service->createDraftVariant((int) $item['sale_item_id'], CatalogCorrelationId::generate(), $variantInput, $actor);
$variant = $variantCreated['result'];
draftCheck($variant['status'] === 'Draft' && $variant['variant_code'] === 'SMALL' && $variant['sku'] === 'UNI-PE-S', 'Variant creation must normalize identity and force Draft.');
draftCheck($variant['variant_metadata'] === ['color' => 'Blue'], 'Variant metadata must round-trip as structured data.');
expectCatalogError(fn() => $service->createDraftVariant((int) $item['sale_item_id'], CatalogCorrelationId::generate(), $variantInput, $actor), 'DUPLICATE_VARIANT_CODE');
expectCatalogError(fn() => $service->createDraftVariant((int) $item['sale_item_id'], CatalogCorrelationId::generate(), array_replace($variantInput, ['variant_code' => 'MEDIUM']), $actor), 'DUPLICATE_VARIANT_SKU');

$standard = $service->createStandardDraftVariant((int) $item['sale_item_id'], CatalogCorrelationId::generate(), $actor);
draftCheck($standard['result']['variant_code'] === 'STANDARD' && $standard['result']['status'] === 'Draft', 'Explicit STANDARD action must create a Draft STANDARD variant.');
$variantUpdated = $service->updateVariant((int) $variant['sale_variant_id'], CatalogCorrelationId::generate(), array_replace($variantInput, ['variant_name' => 'Small / Blue', 'sku' => 'UNI-PE-S-BLUE']), $actor);
draftCheck($variantUpdated['result']['variant_name'] === 'Small / Blue', 'Draft variant update must persist safe fields.');

$deactivated = $service->deactivateVariant((int) $variant['sale_variant_id'], CatalogCorrelationId::generate(), $actor);
draftCheck($deactivated['result']['status'] === 'Inactive', 'Explicit variant deactivation must use Inactive lifecycle.');
$archived = $service->archiveVariant((int) $variant['sale_variant_id'], CatalogCorrelationId::generate(), $actor);
draftCheck($archived['result']['status'] === 'Archived', 'Explicit variant archive must preserve row as Archived.');
expectCatalogError(fn() => $service->updateVariant((int) $variant['sale_variant_id'], CatalogCorrelationId::generate(), $variantInput, $actor), 'ARCHIVED_VARIANT_IMMUTABLE');
expectCatalogError(fn() => $service->deactivateVariant((int) $variant['sale_variant_id'], CatalogCorrelationId::generate(), $actor), 'ARCHIVED_VARIANT_IMMUTABLE');
draftCheck((int) $payment->query("SELECT COUNT(*) FROM payment_audit_outbox WHERE action IN ('school_sale_variant_created','school_sale_variant_updated','school_sale_variant_deactivated','school_sale_variant_archived')")->fetchColumn() === 5, 'Every successful variant mutation must create its structured outbox event.');

$replayCorrelation = CatalogCorrelationId::generate();
$replayInput = ['variant_code' => 'LARGE', 'variant_name' => 'Large', 'sku' => 'UNI-PE-L', 'sort_order' => 30];
$first = $service->createDraftVariant((int) $item['sale_item_id'], $replayCorrelation, $replayInput, $actor);
$second = $service->createDraftVariant((int) $item['sale_item_id'], $replayCorrelation, $replayInput, $actor);
draftCheck(!$first['idempotent_replay'] && $second['idempotent_replay'], 'Same correlation and request must return safe replay.');
draftCheck($first['result'] === $second['result'], 'Replay must recover identical committed result.');
expectCatalogError(fn() => $service->createDraftVariant((int) $item['sale_item_id'], $replayCorrelation, array_replace($replayInput, ['variant_name' => 'Changed']), $actor), 'CORRELATION_ID_CONFLICT');
draftCheck((int) $payment->query("SELECT COUNT(*) FROM school_sale_item_variants WHERE variant_code='LARGE'")->fetchColumn() === 1, 'Replay and conflict must not duplicate variant mutation.');

draftCheck((int) $payment->query('SELECT COUNT(*) FROM school_sale_variant_prices')->fetchColumn() === 0, 'Draft management must not create prices.');
draftCheck((int) $payment->query('SELECT COUNT(*) FROM school_sale_item_applicability')->fetchColumn() === 0, 'Draft management must not create applicability rows.');
$serviceSource = (string) file_get_contents(ROOT_PATH . '/modules/payment/includes/SchoolSalesCatalogService.php');
$pageSource = (string) file_get_contents(ROOT_PATH . '/modules/payment/pages/accounting_admin/school-sales-catalog.php');
draftCheck(!str_contains($pageSource, 'createDraftVariantPrice') && !str_contains($pageSource, 'activateVariantPrice'), 'Effective-price operations must remain internal and unexposed.');
draftCheck(!str_contains($pageSource, 'addItemApplicabilityAssignment') && !str_contains($pageSource, 'replaceItemApplicabilityAssignments'), 'Applicability operations must remain internal and unexposed.');
draftCheck(!str_contains($pageSource, 'activateItem'), 'Item activation must remain internal and unexposed.');
draftCheck(str_contains($pageSource, 'CATALOG_MUTATIONS_PENDING') && !str_contains($pageSource, 'createDraftItem'), 'Production catalog POST must remain blocked and unexposed.');

echo "PASS: {$checks} School Sales Draft item/variant management checks.\n";
