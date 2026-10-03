<?php
declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__));
}

require_once __DIR__ . '/../modules/payment/includes/SchoolSalesCatalogService.php';

$checks = 0;
function catalogReadCheck(bool $ok, string $message): void
{
    global $checks;
    $checks++;
    if (!$ok) throw new RuntimeException($message);
}

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$pdo->exec('CREATE TABLE school_sale_categories (
    sale_category_id INTEGER PRIMARY KEY, category_code TEXT NOT NULL, category_name TEXT NOT NULL,
    sort_order INTEGER NOT NULL, status TEXT NOT NULL, created_by INTEGER NULL, updated_by INTEGER NULL,
    created_at TEXT NOT NULL, updated_at TEXT NOT NULL
)');
$pdo->exec('CREATE TABLE school_sale_item_types (
    sale_item_type_id INTEGER PRIMARY KEY, type_code TEXT NOT NULL, type_name TEXT NOT NULL,
    metadata_profile TEXT NOT NULL, status TEXT NOT NULL, sort_order INTEGER NOT NULL,
    created_by INTEGER NULL, updated_by INTEGER NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL
)');
$pdo->exec('CREATE TABLE school_sale_items (
    sale_item_id INTEGER PRIMARY KEY, item_code TEXT NOT NULL, sale_category_id INTEGER NOT NULL,
    sale_item_type_id INTEGER NOT NULL, item_name TEXT NOT NULL, description TEXT NULL,
    applicability_mode TEXT NOT NULL, status TEXT NOT NULL, created_by INTEGER NULL,
    updated_by INTEGER NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL
)');
$pdo->exec('CREATE TABLE school_sale_item_variants (
    sale_variant_id INTEGER PRIMARY KEY, sale_item_id INTEGER NOT NULL, variant_code TEXT NOT NULL,
    variant_name TEXT NOT NULL, sku TEXT NULL, size_label TEXT NULL, variant_metadata TEXT NULL,
    sort_order INTEGER NOT NULL, status TEXT NOT NULL, created_by INTEGER NULL, updated_by INTEGER NULL,
    created_at TEXT NOT NULL, updated_at TEXT NOT NULL
)');
$pdo->exec('CREATE TABLE school_sale_variant_prices (
    sale_variant_price_id INTEGER PRIMARY KEY, sale_variant_id INTEGER NOT NULL, amount TEXT NOT NULL,
    currency TEXT NOT NULL, effective_from TEXT NOT NULL, effective_to TEXT NULL, status TEXT NOT NULL,
    created_by INTEGER NULL, updated_by INTEGER NULL, activated_by INTEGER NULL, retired_by INTEGER NULL,
    created_at TEXT NOT NULL, updated_at TEXT NOT NULL, activated_at TEXT NULL, retired_at TEXT NULL
)');
$pdo->exec('CREATE TABLE school_sale_item_applicability (
    sale_item_applicability_id INTEGER PRIMARY KEY, sale_item_id INTEGER NOT NULL,
    program_code TEXT NULL, year_level TEXT NULL, status TEXT NOT NULL, created_by INTEGER NULL,
    updated_by INTEGER NULL, deactivated_by INTEGER NULL, created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL, deactivated_at TEXT NULL
)');

$service = new SchoolSalesCatalogService($pdo);
catalogReadCheck($service->categories() === [], 'Empty category state must return an empty collection.');
catalogReadCheck($service->itemTypes() === [], 'Empty item-type state must return an empty collection.');
catalogReadCheck($service->catalogItems() === [], 'Empty item state must return an empty collection.');
catalogReadCheck($service->itemVariants(99) === [], 'Empty variant state must return an empty collection.');
catalogReadCheck($service->variantPriceHistory(99) === [], 'Empty price state must return an empty collection.');
catalogReadCheck($service->itemApplicabilityHistory(99) === [], 'Empty applicability state must return an empty collection.');

$pdo->exec("INSERT INTO school_sale_categories VALUES
 (1,'UNIFORM','Uniform',10,'Active',1,2,'2026-01-01 00:00:00','2026-01-02 00:00:00'),
 (2,'ARCHIVE','Archived Category',20,'Archived',1,2,'2026-01-01 00:00:00','2026-01-02 00:00:00')");
$pdo->exec("INSERT INTO school_sale_item_types VALUES
 (1,'UNIFORM','Uniform','UNIFORM_VARIANT','Active',10,1,2,'2026-01-01 00:00:00','2026-01-02 00:00:00'),
 (2,'OLD_TYPE','Old Type','STANDARD_PRODUCT','Inactive',20,1,2,'2026-01-01 00:00:00','2026-01-02 00:00:00')");
$pdo->exec("INSERT INTO school_sale_items VALUES
 (1,'UNIFORM-001',1,1,'PE Shirt','School PE shirt','RESTRICTED','Draft',1,2,'2026-01-01 00:00:00','2026-01-02 00:00:00'),
 (2,'ARCHIVED-001',2,2,'Old Item',NULL,'ALL','Archived',1,2,'2026-01-01 00:00:00','2026-01-02 00:00:00')");
$pdo->exec("INSERT INTO school_sale_item_variants VALUES
 (1,1,'SMALL','Small','SKU-S', 'S','{\"size\":\"S\"}',10,'Active',1,2,'2026-01-01 00:00:00','2026-01-02 00:00:00'),
 (2,1,'OLD','Old',NULL,NULL,NULL,20,'Inactive',1,2,'2026-01-01 00:00:00','2026-01-02 00:00:00')");
$pdo->exec("INSERT INTO school_sale_variant_prices VALUES
 (1,1,'450.00','PHP','2025-01-01 00:00:00','2025-12-31 23:59:59','Retired',1,2,1,2,'2025-01-01 00:00:00','2025-12-31 23:59:59','2025-01-01 00:00:00','2025-12-31 23:59:59'),
 (2,1,'500.00','PHP','2026-01-01 00:00:00',NULL,'Active',1,2,1,NULL,'2026-01-01 00:00:00','2026-01-01 00:00:00','2026-01-01 00:00:00',NULL)");
$pdo->exec("INSERT INTO school_sale_item_applicability VALUES
 (1,1,'BSIT','1','Active',1,2,NULL,'2026-01-01 00:00:00','2026-01-02 00:00:00',NULL),
 (2,1,'BSCS','2','Inactive',1,2,2,'2025-01-01 00:00:00','2026-01-02 00:00:00','2026-01-02 00:00:00')");

$categories = $service->categories();
$types = $service->itemTypes();
$items = $service->catalogItems();
catalogReadCheck(count($categories) === 2 && $categories[1]['status'] === 'Archived', 'Archived categories must remain distinguishable.');
catalogReadCheck(count($types) === 2 && $types[1]['status'] === 'Inactive', 'Inactive item types must remain distinguishable.');
catalogReadCheck(count($items) === 2 && $items[1]['status'] === 'Archived', 'Archived items must remain distinguishable.');
catalogReadCheck($items[0]['sale_item_id'] === 1 && $items[0]['sale_category_id'] === 1, 'Canonical item IDs must be integers.');

$countsBefore = [];
foreach (['school_sale_categories','school_sale_item_types','school_sale_items','school_sale_item_variants','school_sale_variant_prices','school_sale_item_applicability'] as $table) {
    $countsBefore[$table] = (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
}
$at = new DateTimeImmutable('2026-06-01 12:00:00', new DateTimeZone('UTC'));
$detail = $service->itemDetails(1, $at);
catalogReadCheck($detail['item']['item_code'] === 'UNIFORM-001', 'Item detail must return the canonical item.');
catalogReadCheck(count($detail['variants']) === 2, 'Item detail must include all variant lifecycle states.');
catalogReadCheck($detail['variants'][0]['variant_metadata'] === ['size' => 'S'], 'Variant metadata must be decoded canonically.');
catalogReadCheck($detail['variants'][0]['current_price_count'] === 1, 'Exactly one effective Active price must be visible.');
catalogReadCheck($detail['variants'][0]['current_price']['amount'] === '500.00', 'Current price must retain decimal precision.');
catalogReadCheck($detail['variants'][0]['prices'][1]['status'] === 'Retired', 'Price history must retain retired rows.');
catalogReadCheck(count($detail['applicability']) === 2, 'Applicability history must retain all rows.');
catalogReadCheck($detail['applicability'][0]['status'] === 'Active' && $detail['applicability'][1]['status'] === 'Inactive', 'Applicability lifecycle states must remain distinguishable.');

foreach ($countsBefore as $table => $count) {
    catalogReadCheck((int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn() === $count, "Read operations must not mutate {$table}.");
}

$serviceSource = (string) file_get_contents(ROOT_PATH . '/modules/payment/includes/SchoolSalesCatalogService.php');
$pageSource = (string) file_get_contents(ROOT_PATH . '/modules/payment/pages/accounting_admin/school-sales-catalog.php');
catalogReadCheck(!str_contains($serviceSource, 'unit_price') && !str_contains($pageSource, 'unit_price'), 'Catalog reads must not use obsolete unit_price.');
catalogReadCheck(str_contains($serviceSource, 'SchoolSalesCatalogMutationInfrastructure'), 'Catalog writes must use the approved mutation infrastructure.');
catalogReadCheck(!preg_match('/\bSELECT\b.+\bFROM\s+school_sale_/is', $pageSource), 'Accounting Admin page must not own catalog SELECT SQL.');
catalogReadCheck(str_contains($pageSource, '/modules/payment/api/accounting/school-sales-catalog.php'), 'Accounting Admin page must consume the authoritative catalog API.');
catalogReadCheck(str_contains($pageSource, 'CATALOG_MUTATIONS_PENDING'), 'Catalog POST must remain fail-closed.');

echo "PASS: {$checks} School Sales catalog read checks.\n";
