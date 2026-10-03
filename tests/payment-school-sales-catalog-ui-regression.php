<?php

declare(strict_types=1);

if (!defined('ROOT_PATH')) define('ROOT_PATH', dirname(__DIR__));
$checks = 0;
function catalogUiCheck(bool $ok, string $message): void { global $checks; $checks++; if (!$ok) throw new RuntimeException($message); }

$page = (string) file_get_contents(ROOT_PATH . '/modules/payment/pages/accounting_admin/school-sales-catalog.php');
$js = (string) file_get_contents(ROOT_PATH . '/modules/payment/assets/js/school-sales-catalog.js');
$css = (string) file_get_contents(ROOT_PATH . '/modules/payment/assets/css/school-sales-catalog.css');
$config = (string) file_get_contents(ROOT_PATH . '/config/config.php');

catalogUiCheck(!preg_match('/\b(?:INSERT|UPDATE|DELETE)\s+(?:INTO\s+|FROM\s+)?school_sale_/i', $page), 'Page must not contain direct catalog mutation SQL.');
catalogUiCheck(!str_contains(strtolower($page), '<form'), 'Catalog UI must not expose an HTML form mutation path.');
catalogUiCheck(str_contains($page, '/modules/payment/api/accounting/school-sales-catalog.php'), 'Production JSON API must be the page API boundary.');
catalogUiCheck(substr_count($js, 'fetch(') >= 2, 'Catalog reads and mutations must use fetch through the API wrapper.');
catalogUiCheck(str_contains($page, "'manage' => \$canManage") && str_contains($page, "'activate' => \$canActivate"), 'Page must bootstrap permission-aware UI flags.');
catalogUiCheck(str_contains($js, 'permissions.manage') && str_contains($js, 'permissions.activate'), 'Controls must be permission-aware.');
catalogUiCheck(str_contains($js, 'crypto.randomUUID') && str_contains($js, 'e.retry=attempt'), 'Mutations must generate UUID-v4 IDs and retain the same attempt for uncertain retries.');
catalogUiCheck(str_contains($js, "read('activation_readiness'") && str_contains($js, 'r.failures.map'), 'Activation readiness must render structured API failures.');
catalogUiCheck(str_contains($js, "item.type_code==='BOOK'") && str_contains($js, "toggle('d-none',!isBook)"), 'Book metadata section must be limited to BOOK items.');
catalogUiCheck(str_contains($js, 'current_price_state') && str_contains($js, 'price-history'), 'Current price state and price history must be rendered.');
catalogUiCheck(str_contains($js, "applicability_mode==='ALL'") && str_contains($js, 'replace_applicability'), 'ALL/RESTRICTED applicability management must be wired.');
catalogUiCheck(str_contains($js, "audit_status==='pending'") && str_contains($js, 'already committed'), 'Pending audit delivery must render as committed success without resubmission advice.');
catalogUiCheck(str_contains($js, 'const errors =') && str_contains($js, 'CORRELATION_ID_CONFLICT'), 'Stable API error codes must map to friendly UI messages.');
catalogUiCheck(str_contains($page, 'http_response_code(503)') && str_contains($page, 'CATALOG_MUTATIONS_PENDING'), 'Existing page POST must remain blocked at 503.');
catalogUiCheck(str_contains($css, '@media (max-width:') && str_contains($css, '.catalog-list-card'), 'Catalog workspace must include responsive styling.');
catalogUiCheck(str_contains($config, "=== 'true'"), 'Cashier School Sales must remain strict and disabled by default.');

echo "PASS: {$checks} School Sales Catalog UI checks.\n";
