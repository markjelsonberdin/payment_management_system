<?php
/**
 * Deprecated insecure panel-member seeder.
 *
 * Account creation and password resets must use an authenticated,
 * permission-checked User Management workflow. This file intentionally does
 * not connect to a database or mutate roles, permissions, or accounts.
 */
declare(strict_types=1);

http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
echo "Panel account seeding is disabled. Use the authorized User Management workflow.\n";
exit(1);
