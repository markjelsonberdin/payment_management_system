<?php
declare(strict_types=1);
// Compatibility route kept for existing links; detailed reporting is canonical here.
require_once __DIR__ . '/../../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
requireAuth();
requirePaymentPermission('payment.analytics');
require __DIR__ . '/collection-reporting-integrated.php';
