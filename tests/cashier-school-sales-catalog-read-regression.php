<?php

declare(strict_types=1);

if (!defined('ROOT_PATH')) define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/modules/payment/includes/CashierSchoolSalesCatalogService.php';
require_once ROOT_PATH . '/modules/payment/includes/CashierSchoolSalesCatalogApiController.php';

$checks = 0;
function c5check(bool $ok, string $message): void { global $checks; $checks++; if (!$ok) throw new RuntimeException($message); }

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->sqliteCreateFunction('regexp', static fn(string $pattern, ?string $value): int => preg_match('/' . str_replace('/', '\\/', $pattern) . '/', (string) $value));
foreach ([
    'CREATE TABLE students (student_id INTEGER PRIMARY KEY, course TEXT, year_level TEXT)',
    'CREATE TABLE school_sale_categories (sale_category_id INTEGER PRIMARY KEY, category_code TEXT, category_name TEXT, sort_order INTEGER, status TEXT)',
    'CREATE TABLE school_sale_item_types (sale_item_type_id INTEGER PRIMARY KEY, type_code TEXT, type_name TEXT, status TEXT)',
    'CREATE TABLE school_sale_items (sale_item_id INTEGER PRIMARY KEY, item_code TEXT, sale_category_id INTEGER, sale_item_type_id INTEGER, item_name TEXT, description TEXT, applicability_mode TEXT, status TEXT)',
    'CREATE TABLE school_sale_item_variants (sale_variant_id INTEGER PRIMARY KEY, sale_item_id INTEGER, variant_code TEXT, variant_name TEXT, sku TEXT, size_label TEXT, sort_order INTEGER, status TEXT)',
    'CREATE TABLE school_sale_variant_prices (sale_variant_price_id INTEGER PRIMARY KEY, sale_variant_id INTEGER, amount NUMERIC, currency TEXT, effective_from TEXT, effective_to TEXT, status TEXT)',
    'CREATE TABLE school_sale_item_applicability (sale_item_applicability_id INTEGER PRIMARY KEY, sale_item_id INTEGER, program_code TEXT, year_level TEXT, status TEXT)',
    'CREATE TABLE school_sale_book_details (sale_book_detail_id INTEGER PRIMARY KEY, sale_item_id INTEGER, book_title TEXT, author TEXT, publisher TEXT, edition TEXT, isbn TEXT, notes TEXT)'
] as $sql) $pdo->exec($sql);
$pdo->exec("INSERT INTO students VALUES (1, 'BSIT', '2'), (2, NULL, NULL)");
$pdo->exec("INSERT INTO school_sale_categories VALUES (1,'SUP','Supplies',10,'Active'),(2,'OFF','Off',20,'Inactive')");
$pdo->exec("INSERT INTO school_sale_item_types VALUES (1,'ACCESSORY','Accessory','Active'),(2,'BOOK','Book','Active')");
$pdo->exec("INSERT INTO school_sale_items VALUES
 (1,'PEN',1,1,'Blue Pen','', 'ALL','Active'),
 (2,'SHIRT',1,1,'BSIT Shirt','', 'RESTRICTED','Active'),
 (3,'OLD',2,1,'Old Item','', 'ALL','Active'),
 (4,'NOVEL',1,2,'Novel','', 'ALL','Active')");
$pdo->exec("INSERT INTO school_sale_item_variants VALUES
 (1,1,'STANDARD','Standard',NULL,NULL,0,'Active'),
 (2,2,'STANDARD','Standard',NULL,NULL,0,'Active'),
 (3,3,'STANDARD','Standard',NULL,NULL,0,'Active'),
 (4,4,'STANDARD','Standard',NULL,NULL,0,'Active')");
$pdo->exec("INSERT INTO school_sale_variant_prices VALUES
 (1,1,25.00,'PHP','2025-01-01 00:00:00',NULL,'Active'),
 (2,2,100.00,'PHP','2025-01-01 00:00:00',NULL,'Active'),
 (3,3,99.00,'PHP','2025-01-01 00:00:00',NULL,'Active'),
 (4,4,200.00,'PHP','2025-01-01 00:00:00',NULL,'Active')");
$pdo->exec("INSERT INTO school_sale_item_applicability VALUES (1,2,'BSIT','2','Active')");

$service = new CashierSchoolSalesCatalogService($pdo, new DateTimeImmutable('2026-10-03 12:00:00', new DateTimeZone('UTC')));
$list = $service->listSellableItems(1);
c5check($list['total'] === 2, 'Only active, priced, applicable non-Book records must be sellable.');
$pen = array_values(array_filter($list['items'], static fn(array $item): bool => $item['item_code'] === 'PEN'))[0] ?? null;
c5check($pen !== null && $pen['variants'][0]['current_price']['price_id'] === 1, 'The exact authoritative price ID must be returned.');
c5check($service->categoriesForStudent(1)[0]['category_code'] === 'SUP', 'Categories must derive only from sellable items.');
c5check($service->listSellableItems(2)['total'] === 1, 'Unknown student context must fail closed for RESTRICTED items while retaining ALL items.');
$pdo->exec("INSERT INTO school_sale_book_details VALUES (1,4,'Validated Book','Author','Publisher','1st','978-1234567890',NULL)");
c5check($service->listSellableItems(1)['total'] === 3, 'A BOOK item with complete approved metadata must become sellable.');
$pdo->exec("INSERT INTO school_sale_variant_prices VALUES (5,1,30.00,'PHP','2026-01-01 00:00:00',NULL,'Active')");
c5check($service->listSellableItems(1)['total'] === 2, 'Multiple current prices must fail closed for the full catalog item.');
try { $service->sellableItemDetails(1, 3); throw new RuntimeException('Inactive-parent item was returned.'); }
catch (CashierSchoolSalesCatalogException $e) { c5check($e->errorCode === 'ITEM_NOT_SELLABLE', 'Ineligible detail lookups must fail closed.'); }
$api = new CashierSchoolSalesCatalogApiController($service);
c5check($api->handle('GET', ['student_id'=>1], true, false)['status'] === 403, 'Cashier read API must enforce server-side permission.');
c5check($api->handle('POST', ['student_id'=>1], true, true)['status'] === 405, 'Cashier read API must reject mutations.');
c5check($api->handle('GET', ['student_id'=>1,'action'=>'items'], true, true)['status'] === 200, 'Authorized Cashier read must succeed.');

echo "PASS: {$checks} C5 Cashier catalog read checks.\n";
