<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/authentication.php';
echo "Keys for cashier: ";
print_r(smsRolePermissionLookupKeys('cashier'));
