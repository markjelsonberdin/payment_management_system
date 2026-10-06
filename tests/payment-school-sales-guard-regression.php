<?php
declare(strict_types=1);

putenv('PAYMENT_SCHOOL_SALES_SELLING_ENABLED');
require_once __DIR__ . '/../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';

$checks = 0;
function schoolSalesCheck(bool $ok, string $message): void
{
    global $checks;
    $checks++;
    if (!$ok) throw new RuntimeException($message);
}
function sourcePosition(string $source, string $needle): int
{
    $position = strpos($source, $needle);
    if ($position === false) throw new RuntimeException("Missing expected source marker: {$needle}");
    return $position;
}

schoolSalesCheck(!paymentSchoolSalesSellingEnabled(), 'Missing feature flag must disable Cashier School Sales.');
foreach (['', 'false', '0', '1', 'yes', 'on', 'enabled', 'malformed'] as $value) {
    putenv('PAYMENT_SCHOOL_SALES_SELLING_ENABLED=' . $value);
    schoolSalesCheck(!paymentSchoolSalesSellingEnabled(), "Feature flag value {$value} must remain disabled.");
}
foreach (['true', ' TRUE ', "\tTrUe\n"] as $value) {
    putenv('PAYMENT_SCHOOL_SALES_SELLING_ENABLED=' . $value);
    schoolSalesCheck(paymentSchoolSalesSellingEnabled(), 'Normalized exact true must enable the helper.');
}
putenv('PAYMENT_SCHOOL_SALES_SELLING_ENABLED');

$cashierGroup = $MODULES['payment']['groups']['CASHIER PORTAL'] ?? [];
$paymentSlugs = array_column($MODULES['payment']['pages'] ?? [], 'slug');
schoolSalesCheck(!in_array('cashier/school-sales', $cashierGroup, true), 'Disabled navigation must omit Cashier School Sales.');
schoolSalesCheck(!in_array('cashier/school-sales', $paymentSlugs, true), 'Disabled overview must omit Cashier School Sales.');
schoolSalesCheck(in_array('cashier/payment-collection-portal', $cashierGroup, true), 'Payment Collection must remain available.');
schoolSalesCheck(in_array('cashier/walk-in-transaction-history', $cashierGroup, true), 'Cashier transaction history must remain available.');

$catalog = (string) file_get_contents(ROOT_PATH . '/modules/payment/pages/accounting_admin/school-sales-catalog.php');
schoolSalesCheck(str_contains($catalog, "requirePaymentPermission('school_sales.catalog.view')"), 'Catalog GET must require the view permission.');
schoolSalesCheck(str_contains($catalog, 'CATALOG_MUTATIONS_PENDING'), 'Catalog POST must fail closed pending the service layer.');
schoolSalesCheck(sourcePosition($catalog, 'CATALOG_MUTATIONS_PENDING') < sourcePosition($catalog, '$catalogBootstrap'), 'Catalog POST guard must run before UI/API bootstrap work.');
schoolSalesCheck(!str_contains($catalog, 'unit_price'), 'Catalog page must not use the obsolete unit_price field.');
schoolSalesCheck(!preg_match('/\b(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+school_sale_/i', $catalog), 'Catalog page must not contain authoritative mutation SQL.');
schoolSalesCheck(!preg_match('/\bSELECT\b.+\bFROM\s+school_sale_/is', $catalog), 'Catalog page must not own authoritative catalog SELECT SQL.');
schoolSalesCheck(str_contains($catalog, '/modules/payment/api/accounting/school-sales-catalog.php'), 'Catalog GET UI must use the authoritative JSON API.');

$catalogService = (string) file_get_contents(ROOT_PATH . '/modules/payment/includes/SchoolSalesCatalogService.php');
schoolSalesCheck(str_contains($catalogService, 'JOIN school_sale_item_types'), 'Catalog read service must use the current item-type relationship.');
schoolSalesCheck(!str_contains($catalogService, 'unit_price'), 'Catalog read service must not use the obsolete unit_price field.');

$salePage = (string) file_get_contents(ROOT_PATH . '/modules/payment/pages/cashier/school-sales.php');
if (str_contains($salePage, "query['view'] = 'school-items'")) {
    schoolSalesCheck(str_contains($salePage, "header('Location: payment-collection-portal.php?"), 'Legacy School Sales route must redirect to the unified Cashier portal.');
    schoolSalesCheck(!str_contains($salePage, 'database/db_connect.php') && !str_contains($salePage, 'includes/CashSaleService.php'), 'Legacy redirect must not load Payment mutation infrastructure.');
} else {
    $pageGate = sourcePosition($salePage, 'paymentSchoolSalesSellingEnabled()');
    schoolSalesCheck($pageGate < sourcePosition($salePage, 'database/db_connect.php'), 'Cashier page gate must precede Payment DB loading.');
    schoolSalesCheck($pageGate < sourcePosition($salePage, 'includes/CashSaleService.php'), 'Cashier page gate must precede CashSaleService loading.');
}

$lookupApi = (string) file_get_contents(ROOT_PATH . '/modules/payment/api/fetch_cash_sale_student.php');
$apiGate = sourcePosition($lookupApi, 'paymentSchoolSalesSellingEnabled()');
schoolSalesCheck(str_contains($lookupApi, "'error' => 'FEATURE_DISABLED'"), 'Lookup API must return FEATURE_DISABLED.');
schoolSalesCheck($apiGate < sourcePosition($lookupApi, 'database/db_connect.php'), 'Lookup API gate must precede Payment DB loading.');
schoolSalesCheck($apiGate < sourcePosition($lookupApi, 'RegistrarStudentClient.php'), 'Lookup API gate must precede Registrar synchronization code.');

$dashboard = (string) file_get_contents(ROOT_PATH . '/modules/payment/pages/cashier/dashboard.php');
schoolSalesCheck(str_contains($dashboard, 'if (paymentSchoolSalesSellingEnabled())'), 'Dashboard action must use the centralized helper.');
schoolSalesCheck(str_contains($dashboard, 'if(saleAction)saleAction.href='), 'Dashboard JavaScript must tolerate a hidden action.');

$config = (string) file_get_contents(ROOT_PATH . '/config/config.php');
$authentication = (string) file_get_contents(ROOT_PATH . '/includes/authentication.php');
schoolSalesCheck(substr_count($config, 'paymentSchoolSalesSellingEnabled()') >= 2, 'Navigation and overview must use the centralized helper.');
schoolSalesCheck(!str_contains($authentication, "'payment.school_sales_catalog' => 'fee.manage'"), 'Legacy Fee Setup alias must be removed.');
schoolSalesCheck(str_contains($config, "'permission' => 'school_sales.catalog.view'"), 'Catalog navigation must require view permission.');

echo "PASS: {$checks} School Sales RBAC and fail-closed checks.\n";
