<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/authentication.php';
echo "cashier: ";
print_r(smsAllowedModuleKeysForRole('cashier'));
echo "\nfinance: ";
print_r(smsAllowedModuleKeysForRole('finance'));
