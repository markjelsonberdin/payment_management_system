<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/authentication.php';

$allowed = getAllowedModuleKeys();
print_r($allowed);
