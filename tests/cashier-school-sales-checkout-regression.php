<?php

declare(strict_types=1);

if (!defined('ROOT_PATH')) define('ROOT_PATH', dirname(__DIR__));

$checks = 0;
function checkoutCheck(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) throw new RuntimeException($message);
}

$service = (string) file_get_contents(ROOT_PATH . '/modules/payment/includes/CashSaleService.php');
$page = (string) file_get_contents(ROOT_PATH . '/modules/payment/pages/cashier/school-sales.php');
$collection = (string) file_get_contents(ROOT_PATH . '/modules/payment/pages/cashier/payment-collection-portal.php');

checkoutCheck(str_contains($service, 'sale_variant_id'), 'Checkout must use authoritative catalog variants.');
checkoutCheck(str_contains($service, 'school_sale_variant_prices'), 'Checkout must resolve the current server-side price.');
checkoutCheck(str_contains($service, 'cashier_transactions'), 'Checkout must create the C4 cashier transaction header.');
checkoutCheck(str_contains($service, 'idempotency_key'), 'Checkout must be idempotent per Cashier request.');
checkoutCheck(str_contains($service, 'FOR UPDATE'), 'Checkout must lock authoritative records while validating a sale.');
checkoutCheck(str_contains($service, "'SCHOOL_SALE'"), 'Checkout must classify the transaction as a school sale.');
checkoutCheck(str_contains($page, 'cashier-school-sales-catalog.php'), 'Cashier UI must load the normalized catalog API.');
checkoutCheck(str_contains($page, 'sale_variant_id'), 'Cashier UI must submit variant IDs rather than legacy item prices.');
checkoutCheck(str_contains($page, 'idempotency_key'), 'Cashier UI must submit a server-generated idempotency key.');
checkoutCheck(str_contains($collection, 'School Items'), 'Academic collection must expose the School Items switcher.');

echo "PASS: {$checks} Cashier School Sales checkout integration checks.\n";
