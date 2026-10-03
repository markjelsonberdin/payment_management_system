<?php

declare(strict_types=1);

if (!defined('ROOT_PATH')) define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/modules/payment/includes/SchoolSalesCatalogService.php';

$checks = 0;
function priceCheck(bool $ok, string $message): void { global $checks; $checks++; if (!$ok) throw new RuntimeException($message); }
function expectPriceError(callable $operation, string $expected): void
{
    $actual = null;
    try { $operation(); } catch (SchoolSalesCatalogValidationException|SchoolSalesCatalogAuthorizationException|CatalogCorrelationConflictException $e) { $actual = $e->getMessage(); }
    priceCheck($actual === $expected, "Expected {$expected}, received " . ($actual ?? 'no error'));
}

$payment = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$core = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$payment->exec("CREATE TABLE school_sale_categories (sale_category_id INTEGER PRIMARY KEY, category_code TEXT, category_name TEXT, sort_order INTEGER, status TEXT, created_by INTEGER, updated_by INTEGER, created_at TEXT, updated_at TEXT)");
$payment->exec("CREATE TABLE school_sale_item_types (sale_item_type_id INTEGER PRIMARY KEY, type_code TEXT, type_name TEXT, metadata_profile TEXT, status TEXT, sort_order INTEGER, created_by INTEGER, updated_by INTEGER, created_at TEXT, updated_at TEXT)");
$payment->exec("CREATE TABLE school_sale_items (sale_item_id INTEGER PRIMARY KEY, item_code TEXT UNIQUE, sale_category_id INTEGER, sale_item_type_id INTEGER, item_name TEXT, description TEXT, applicability_mode TEXT, status TEXT, created_by INTEGER, updated_by INTEGER, created_at TEXT, updated_at TEXT)");
$payment->exec("CREATE TABLE school_sale_item_variants (sale_variant_id INTEGER PRIMARY KEY, sale_item_id INTEGER, variant_code TEXT, variant_name TEXT, sku TEXT UNIQUE, size_label TEXT, variant_metadata TEXT, sort_order INTEGER, status TEXT, created_by INTEGER, updated_by INTEGER, created_at TEXT, updated_at TEXT, UNIQUE(sale_item_id,variant_code))");
$payment->exec("CREATE TABLE school_sale_variant_prices (
 sale_variant_price_id INTEGER PRIMARY KEY AUTOINCREMENT, sale_variant_id INTEGER NOT NULL,
 amount NUMERIC NOT NULL, currency TEXT NOT NULL, effective_from TEXT NOT NULL, effective_to TEXT,
 status TEXT NOT NULL, created_by INTEGER, updated_by INTEGER, activated_by INTEGER, retired_by INTEGER,
 created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
 activated_at TEXT, retired_at TEXT, UNIQUE(sale_variant_id,effective_from))");
$payment->exec("CREATE TABLE school_sale_item_applicability (sale_item_applicability_id INTEGER PRIMARY KEY, sale_item_id INTEGER, program_code TEXT, year_level TEXT, status TEXT, created_by INTEGER, updated_by INTEGER, deactivated_by INTEGER, created_at TEXT, updated_at TEXT, deactivated_at TEXT)");
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
$payment->exec("INSERT INTO school_sale_categories VALUES (1,'UNIFORM','Uniform',10,'Active',NULL,NULL,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
$payment->exec("INSERT INTO school_sale_item_types VALUES (1,'UNIFORM','Uniform','NONE','Active',10,NULL,NULL,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
$payment->exec("INSERT INTO school_sale_items VALUES (1,'UNI-001',1,1,'Uniform',NULL,'ALL','Draft',1,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
$payment->exec("INSERT INTO school_sale_item_variants VALUES
 (1,1,'STANDARD','Standard',NULL,NULL,NULL,10,'Draft',1,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP),
 (2,1,'NO-PRICE','No Price',NULL,NULL,NULL,20,'Draft',1,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP),
 (3,1,'CONFLICT','Conflict',NULL,NULL,NULL,30,'Draft',1,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");

$service = new SchoolSalesCatalogService($payment, new SchoolSalesCatalogMutationInfrastructure(
    $payment, new PaymentAuditOutboxService($payment, new StructuredActivityAuditWriter($core))
));
$manage = ['user_id' => 20, 'user_name' => 'Manager', 'role_key' => 'accounting_admin', 'permissions' => ['school_sales.catalog.manage']];
$activate = ['user_id' => 21, 'user_name' => 'Activator', 'role_key' => 'accounting_admin', 'permissions' => ['school_sales.catalog.activate']];

$draftInput = ['amount' => '500', 'currency' => 'PHP', 'effective_from' => '2020-01-01 00:00:00', 'effective_to' => null];
$created = $service->createDraftVariantPrice(1, CatalogCorrelationId::generate(), $draftInput, $manage);
$priceId = (int) $created['result']['sale_variant_price_id'];
priceCheck($created['result']['status'] === 'Draft' && $created['result']['amount'] === '500.00', 'Valid Draft price must use canonical amount and Draft status.');
priceCheck($created['result']['currency'] === 'PHP', 'Currency must remain server-authoritative PHP.');
expectPriceError(fn() => $service->createDraftVariantPrice(1, CatalogCorrelationId::generate(), array_replace($draftInput, ['amount' => '0', 'effective_from' => '2021-01-01 00:00:00']), $manage), 'INVALID_PRICE_AMOUNT');
expectPriceError(fn() => $service->createDraftVariantPrice(1, CatalogCorrelationId::generate(), array_replace($draftInput, ['amount' => '-1', 'effective_from' => '2021-01-01 00:00:00']), $manage), 'INVALID_PRICE_AMOUNT');
expectPriceError(fn() => $service->createDraftVariantPrice(1, CatalogCorrelationId::generate(), array_replace($draftInput, ['effective_from' => '2022-01-02 00:00:00', 'effective_to' => '2022-01-01 00:00:00']), $manage), 'INVALID_EFFECTIVE_INTERVAL');
expectPriceError(fn() => $service->createDraftVariantPrice(1, CatalogCorrelationId::generate(), array_replace($draftInput, ['currency' => 'USD', 'effective_from' => '2022-01-01 00:00:00']), $manage), 'UNSUPPORTED_PRICE_CURRENCY');
expectPriceError(fn() => $service->createDraftVariantPrice(1, CatalogCorrelationId::generate(), $draftInput, $manage), 'DUPLICATE_PRICE_EFFECTIVE_START');

$updated = $service->updateDraftVariantPrice($priceId, CatalogCorrelationId::generate(), array_replace($draftInput, ['amount' => '550.50']), $manage);
priceCheck($updated['result']['amount'] === '550.50' && $updated['result']['status'] === 'Draft', 'Draft price monetary fields must be editable before activation.');
expectPriceError(fn() => $service->activateVariantPrice($priceId, CatalogCorrelationId::generate(), $manage), 'SCHOOL_SALES_CATALOG_ACTIVATE_REQUIRED');
$activated = $service->activateVariantPrice($priceId, CatalogCorrelationId::generate(), $activate);
priceCheck($activated['result']['status'] === 'Active' && $activated['result']['is_current'] === true, 'Price activation must create an available current price.');
expectPriceError(fn() => $service->updateDraftVariantPrice($priceId, CatalogCorrelationId::generate(), array_replace($draftInput, ['amount' => '600']), $manage), 'ACTIVATED_PRICE_IMMUTABLE');

$overlap = $service->createDraftVariantPrice(1, CatalogCorrelationId::generate(), ['amount' => '600', 'effective_from' => '2021-01-01 00:00:00', 'effective_to' => null], $manage);
expectPriceError(fn() => $service->activateVariantPrice((int) $overlap['result']['sale_variant_price_id'], CatalogCorrelationId::generate(), $activate), 'ACTIVE_PRICE_WINDOW_OVERLAP');

$payment->exec("UPDATE school_sale_item_variants SET status='Active' WHERE sale_variant_id=1");
$replacement = $service->replaceActiveVariantPrice(1, CatalogCorrelationId::generate(), ['amount' => '650', 'effective_from' => '2022-01-01 00:00:00', 'effective_to' => null], $activate);
priceCheck($replacement['result']['successor']['status'] === 'Active' && $replacement['result']['successor']['amount'] === '650.00', 'Repricing must create an Active successor with new monetary value.');
priceCheck($replacement['result']['retired_predecessor']['status'] === 'Retired' && $replacement['result']['retired_predecessor']['effective_to'] === '2022-01-01 00:00:00', 'Immediate successor must close and retire its predecessor.');
priceCheck((int) $payment->query("SELECT COUNT(*) FROM school_sale_variant_prices WHERE sale_variant_id=1 AND status='Active'")->fetchColumn() === 1, 'Repricing must leave one current Active price.');
expectPriceError(fn() => $service->retireActiveVariantPrice((int) $replacement['result']['successor']['sale_variant_price_id'], '2027-01-01 00:00:00', CatalogCorrelationId::generate(), $activate), 'ACTIVE_VARIANT_REQUIRES_REPLACEMENT_PRICE');

$futureReplacement = $service->replaceActiveVariantPrice(1, CatalogCorrelationId::generate(), ['amount' => '700', 'effective_from' => '2030-01-01 00:00:00', 'effective_to' => null], $activate);
$futureId = (int) $futureReplacement['result']['successor']['sale_variant_price_id'];
priceCheck($futureReplacement['result']['retired_predecessor']['status'] === 'Active', 'Future repricing must close but keep the current predecessor Active until its end.');
expectPriceError(fn() => $service->cancelVariantPrice($futureId, CatalogCorrelationId::generate(), $manage), 'SCHOOL_SALES_CATALOG_ACTIVATE_REQUIRED');
$cancelledFuture = $service->cancelVariantPrice($futureId, CatalogCorrelationId::generate(), $activate);
priceCheck($cancelledFuture['result']['status'] === 'Cancelled', 'Activator may cancel a future Active price.');

$cancelDraft = $service->createDraftVariantPrice(2, CatalogCorrelationId::generate(), ['amount' => '100', 'effective_from' => '2031-01-01 00:00:00'], $manage);
$cancelledDraft = $service->cancelVariantPrice((int) $cancelDraft['result']['sale_variant_price_id'], CatalogCorrelationId::generate(), $manage);
priceCheck($cancelledDraft['result']['status'] === 'Cancelled', 'Manager may cancel a Draft price.');

$retireDraftVariantPrice = $service->createDraftVariantPrice(2, CatalogCorrelationId::generate(), ['amount' => '90', 'effective_from' => '2018-01-01 00:00:00'], $manage);
$retireActivePrice = $service->activateVariantPrice((int) $retireDraftVariantPrice['result']['sale_variant_price_id'], CatalogCorrelationId::generate(), $activate);
$retired = $service->retireActiveVariantPrice((int) $retireActivePrice['result']['sale_variant_price_id'], '2020-01-01 00:00:00', CatalogCorrelationId::generate(), $activate);
priceCheck($retired['result']['status'] === 'Retired' && $retired['result']['amount'] === '90.00', 'Retirement must close lifecycle without changing historical amount.');
priceCheck($payment->query("SELECT COUNT(*) FROM payment_audit_outbox WHERE action='school_sale_price_retired'")->fetchColumn() === 1, 'Successful retirement must emit its structured audit action.');

$replayCorrelation = CatalogCorrelationId::generate();
$replayInput = ['amount' => '125', 'effective_from' => '2032-01-01 00:00:00'];
$first = $service->createDraftVariantPrice(2, $replayCorrelation, $replayInput, $manage);
$replay = $service->createDraftVariantPrice(2, $replayCorrelation, $replayInput, $manage);
priceCheck(!$first['idempotent_replay'] && $replay['idempotent_replay'] && $first['result'] === $replay['result'], 'Same correlation/request must safely replay committed price result.');
expectPriceError(fn() => $service->createDraftVariantPrice(2, $replayCorrelation, array_replace($replayInput, ['amount' => '126']), $manage), 'CORRELATION_ID_CONFLICT');

$payment->exec("INSERT INTO school_sale_variant_prices (sale_variant_id,amount,currency,effective_from,effective_to,status) VALUES
 (3,100,'PHP','2020-01-01 00:00:00',NULL,'Active'),
 (3,110,'PHP','2021-01-01 00:00:00',NULL,'Active')");
$states = [];
foreach ($service->itemVariants(1, new DateTimeImmutable('2026-10-02 00:00:00', new DateTimeZone('UTC'))) as $row) $states[$row['variant_code']] = $row['current_price_state'];
priceCheck($states['STANDARD'] === 'Available', 'Current-price reader must report Available for exactly one current Active price.');
priceCheck($states['NO-PRICE'] === 'Missing', 'Current-price reader must report Missing when no current Active price exists.');
priceCheck($states['CONFLICT'] === 'Conflict', 'Current-price reader must expose conflicting current prices without mutation.');
$resolved = $service->resolveCurrentVariantPrice(1, new DateTimeImmutable('2026-10-02 00:00:00', new DateTimeZone('UTC')));
priceCheck($resolved['current_price_state'] === 'Available' && $resolved['current_price']['amount'] === '650.00', 'Direct current-price resolution must return the authoritative effective price read-only.');

priceCheck((int) $payment->query("SELECT COUNT(*) FROM payment_audit_outbox WHERE action LIKE 'school_sale_price_%'")->fetchColumn() >= 8, 'Every successful price mutation must write a structured outbox event.');
priceCheck((int) $payment->query('SELECT COUNT(*) FROM school_sale_item_applicability')->fetchColumn() === 0, 'Price management must not create applicability rows.');
$page = (string) file_get_contents(ROOT_PATH . '/modules/payment/pages/accounting_admin/school-sales-catalog.php');
$source = (string) file_get_contents(ROOT_PATH . '/modules/payment/includes/SchoolSalesCatalogService.php');
priceCheck(str_contains($page, 'CATALOG_MUTATIONS_PENDING') && !str_contains($page, 'createDraftVariantPrice'), 'Production POST/UI must not expose price mutations.');
priceCheck(!str_contains($page, 'addItemApplicabilityAssignment') && !str_contains($page, 'activateItem'), 'Applicability and item activation operations must remain internal and unexposed.');

echo "PASS: {$checks} School Sales effective price checks.\n";
