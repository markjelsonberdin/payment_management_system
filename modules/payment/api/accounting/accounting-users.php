<?php
// Historical compatibility route. The canonical MIS handler owns all
// authentication, CSRF, exact-capability, target-scope, and mutation checks.
require __DIR__ . '/../mis_admin/payment-users.php';
