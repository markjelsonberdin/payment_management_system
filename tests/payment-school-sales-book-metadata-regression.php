<?php

declare(strict_types=1);

if (!defined('ROOT_PATH')) define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/modules/payment/includes/SchoolSalesCatalogService.php';

$checks = 0;
function bookCheck(bool $ok, string $message): void { global $checks; $checks++; if (!$ok) throw new RuntimeException($message); }
function bookFailure(array $readiness, string $code): bool { return in_array($code, array_column($readiness['failures'], 'code'), true); }
function expectBookError(callable $operation, string $expected): void
{
    $actual = null;
    try { $operation(); } catch (SchoolSalesCatalogValidationException|SchoolSalesCatalogAuthorizationException|CatalogCorrelationConflictException $e) { $actual = $e->getMessage(); }
    bookCheck($actual === $expected || (is_string($actual) && str_starts_with($actual, $expected)), "Expected {$expected}; received " . ($actual ?? 'no error'));
}

$payment = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$core = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$payment->exec("CREATE TABLE school_sale_categories (sale_category_id INTEGER PRIMARY KEY, category_code TEXT, category_name TEXT, sort_order INTEGER, status TEXT, created_by INTEGER, updated_by INTEGER, created_at TEXT, updated_at TEXT)");
$payment->exec("CREATE TABLE school_sale_item_types (sale_item_type_id INTEGER PRIMARY KEY, type_code TEXT, type_name TEXT, metadata_profile TEXT, status TEXT, sort_order INTEGER, created_by INTEGER, updated_by INTEGER, created_at TEXT, updated_at TEXT)");
$payment->exec("CREATE TABLE school_sale_items (sale_item_id INTEGER PRIMARY KEY, item_code TEXT, sale_category_id INTEGER, sale_item_type_id INTEGER, item_name TEXT, description TEXT, applicability_mode TEXT, status TEXT, created_by INTEGER, updated_by INTEGER, created_at TEXT, updated_at TEXT)");
$payment->exec("CREATE TABLE school_sale_item_variants (sale_variant_id INTEGER PRIMARY KEY, sale_item_id INTEGER, variant_code TEXT, variant_name TEXT, sku TEXT, size_label TEXT, variant_metadata TEXT, sort_order INTEGER, status TEXT, created_by INTEGER, updated_by INTEGER, created_at TEXT, updated_at TEXT)");
$payment->exec("CREATE TABLE school_sale_variant_prices (sale_variant_price_id INTEGER PRIMARY KEY, sale_variant_id INTEGER, amount NUMERIC, currency TEXT, effective_from TEXT, effective_to TEXT, status TEXT, created_by INTEGER, updated_by INTEGER, activated_by INTEGER, retired_by INTEGER, created_at TEXT, updated_at TEXT, activated_at TEXT, retired_at TEXT)");
$payment->exec("CREATE TABLE school_sale_item_applicability (sale_item_applicability_id INTEGER PRIMARY KEY, sale_item_id INTEGER, program_code TEXT, year_level TEXT, status TEXT, created_by INTEGER, updated_by INTEGER, deactivated_by INTEGER, created_at TEXT, updated_at TEXT, deactivated_at TEXT)");
$payment->exec("CREATE TABLE school_sale_book_details (sale_book_detail_id INTEGER PRIMARY KEY AUTOINCREMENT, sale_item_id INTEGER NOT NULL UNIQUE, book_title TEXT NOT NULL, author TEXT, publisher TEXT, edition TEXT, isbn TEXT, notes TEXT, created_by INTEGER, updated_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$payment->exec("CREATE TABLE payment_audit_outbox (audit_outbox_id INTEGER PRIMARY KEY AUTOINCREMENT, correlation_id TEXT UNIQUE, request_fingerprint TEXT, action TEXT, module_key TEXT, entity_type TEXT, entity_id INTEGER, detail TEXT, before_state TEXT, after_state TEXT, committed_result TEXT, actor_user_id INTEGER, actor_user_name TEXT, actor_role_key TEXT, actor_ip_address TEXT, actor_user_agent TEXT, delivery_status TEXT DEFAULT 'Pending', attempt_count INTEGER DEFAULT 0, next_attempt_at TEXT, last_attempt_at TEXT, delivered_at TEXT, core_activity_log_id INTEGER, last_error TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$core->exec("CREATE TABLE activity_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, user_name TEXT, role_key TEXT, action TEXT, module_key TEXT, entity_type TEXT, entity_id INTEGER, detail TEXT, before_state TEXT, after_state TEXT, correlation_id TEXT UNIQUE, ip_address TEXT, user_agent TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");

$payment->exec("INSERT INTO school_sale_categories VALUES (1,'BOOKS','Books',10,'Active',NULL,NULL,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
$payment->exec("INSERT INTO school_sale_item_types VALUES (1,'BOOK','Book','BOOK_REQUIRED','Active',10,NULL,NULL,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP),(2,'UNIFORM','Uniform','NONE','Active',20,NULL,NULL,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
$items = [[1,'BOOK-DRAFT',1,'Draft'],[2,'NONBOOK',2,'Draft'],[3,'BOOK-INACTIVE',1,'Inactive'],[4,'BOOK-ARCHIVED',1,'Archived'],[5,'BOOK-ACTIVE',1,'Active'],[6,'BOOK-INVALID',1,'Draft'],[7,'BOOK-RESTRICTED',1,'Draft'],[8,'UNIFORM-READY',2,'Draft']];
$insertItem = $payment->prepare("INSERT INTO school_sale_items VALUES (?,?,1,?,?,'', 'ALL',?,1,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
foreach ($items as [$id,$code,$type,$status]) $insertItem->execute([$id,$code,$type,$code,$status]);
foreach ([1,3,4,5,6,7,8] as $id) {
    $payment->prepare("INSERT INTO school_sale_item_variants VALUES (?,?, 'STANDARD','Standard',NULL,NULL,NULL,10,'Active',1,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)")->execute([$id,$id]);
    $payment->prepare("INSERT INTO school_sale_variant_prices VALUES (?,?,100,'PHP','2020-01-01 00:00:00',NULL,'Active',1,1,1,NULL,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,NULL)")->execute([$id,$id]);
}

$service = new SchoolSalesCatalogService($payment, new SchoolSalesCatalogMutationInfrastructure($payment, new PaymentAuditOutboxService($payment, new StructuredActivityAuditWriter($core))));
$manage = ['user_id'=>71,'user_name'=>'Catalog Manager','role_key'=>'accounting_admin','permissions'=>['school_sales.catalog.manage']];
$activate = ['user_id'=>72,'user_name'=>'Activator','role_key'=>'accounting_admin','permissions'=>['school_sales.catalog.activate']];
$at = new DateTimeImmutable('2026-10-02 00:00:00', new DateTimeZone('UTC'));

bookCheck($service->bookDetails(1) === null, 'BOOK read without a row must return null.');
expectBookError(fn() => $service->bookDetails(2), 'BOOK_ITEM_REQUIRED');
expectBookError(fn() => $service->createBookDetails(1, CatalogCorrelationId::generate(), ['book_title'=>''], $manage), 'INVALID_BOOK_TITLE');
expectBookError(fn() => $service->createBookDetails(1, CatalogCorrelationId::generate(), ['book_title'=>'Valid','isbn'=>'bad'], $manage), 'INVALID_BOOK_ISBN');

$payload = ['book_title'=>'Database Systems','author'=>'A. Author','publisher'=>'BCP Press','edition'=>'2nd','isbn'=>'978-1-234567-89-7','notes'=>'Catalog metadata only'];
$correlation = CatalogCorrelationId::generate();
$created = $service->createBookDetails(1, $correlation, $payload, $manage);
bookCheck($created['result']['book_title'] === 'Database Systems' && $created['result']['sale_item_id'] === 1, 'Valid BOOK metadata must be created.');
bookCheck($created['audit_status'] === 'delivered', 'Create must deliver its structured audit event.');
$replay = $service->createBookDetails(1, $correlation, $payload, $manage);
bookCheck($replay['idempotent_replay'] && $replay['result'] === $created['result'], 'Same correlation and request must replay the committed result.');
expectBookError(fn() => $service->createBookDetails(1, $correlation, array_replace($payload, ['book_title'=>'Different']), $manage), 'CORRELATION_ID_CONFLICT');
expectBookError(fn() => $service->createBookDetails(1, CatalogCorrelationId::generate(), $payload, $manage), 'BOOK_DETAILS_ALREADY_EXIST');
expectBookError(fn() => $service->createBookDetails(2, CatalogCorrelationId::generate(), $payload, $manage), 'BOOK_ITEM_REQUIRED');
expectBookError(fn() => $service->updateDraftItem(1, CatalogCorrelationId::generate(), [
    'item_code'=>'BOOK-DRAFT', 'item_name'=>'BOOK-DRAFT', 'sale_category_id'=>1,
    'sale_item_type_id'=>2, 'description'=>null, 'applicability_mode'=>'ALL',
], $manage), 'BOOK_DETAILS_REQUIRE_BOOK_ITEM_TYPE');

$payment->exec("INSERT INTO school_sale_book_details (sale_item_id,book_title) VALUES (3,'Old Title'),(4,'Archived Title'),(5,'Active Title')");
$updated = $service->updateBookDetails(3, CatalogCorrelationId::generate(), array_replace($payload, ['book_title'=>'Updated Title']), $manage);
bookCheck($updated['result']['book_title'] === 'Updated Title', 'Inactive BOOK metadata may be updated.');
expectBookError(fn() => $service->updateBookDetails(4, CatalogCorrelationId::generate(), $payload, $manage), 'ARCHIVED_ITEM_IMMUTABLE');
expectBookError(fn() => $service->updateBookDetails(5, CatalogCorrelationId::generate(), $payload, $manage), 'ACTIVE_BOOK_DETAILS_IMMUTABLE');
expectBookError(fn() => $service->updateBookDetails(7, CatalogCorrelationId::generate(), $payload, $manage), 'BOOK_DETAILS_NOT_FOUND');

bookCheck(bookFailure($service->evaluateItemActivationReadiness(7, $at), 'BOOK_DETAILS_MISSING'), 'Missing BOOK metadata must block readiness explicitly.');
$payment->exec("INSERT INTO school_sale_book_details (sale_item_id,book_title,isbn) VALUES (6,'Valid title','bad')");
bookCheck(bookFailure($service->evaluateItemActivationReadiness(6, $at), 'BOOK_ISBN_INVALID'), 'Invalid persisted BOOK metadata must block readiness explicitly.');
bookCheck($service->evaluateItemActivationReadiness(1, $at)['ready'] === true, 'Valid metadata must remove the former unconditional BOOK block.');
bookCheck($service->evaluateItemActivationReadiness(8, $at)['ready'] === true, 'Existing non-BOOK readiness must remain unchanged.');

$activated = $service->activateItem(1, CatalogCorrelationId::generate(), $activate);
bookCheck($activated['result']['item']['status'] === 'Active', 'A fully valid BOOK must activate.');
expectBookError(fn() => $service->updateBookDetails(1, CatalogCorrelationId::generate(), $payload, $manage), 'ACTIVE_BOOK_DETAILS_IMMUTABLE');
bookCheck((int) $payment->query("SELECT COUNT(*) FROM payment_audit_outbox WHERE action IN ('school_sale_book_details_created','school_sale_book_details_updated')")->fetchColumn() === 2, 'Successful Book mutations must create atomic audit outbox rows.');
bookCheck((int) $core->query("SELECT COUNT(*) FROM activity_logs WHERE action IN ('school_sale_book_details_created','school_sale_book_details_updated')")->fetchColumn() === 2, 'Book audit events must reach Core audit.');
bookCheck(!array_intersect($payment->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN), ['billing','fees','payment_allocations','cash_sales']), 'Book support must not create protected financial domains.');

echo "PASS: {$checks} School Sales Book metadata checks.\n";
