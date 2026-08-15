<?php
/**
 * SMS 2 - Faculty Management - Overview
 */
$pageTitle    = 'Faculty Management';
$activeModule = 'faculty';
$activePage   = '';
$breadcrumbs  = [
    ['label' => 'Faculty Management', 'url' => null],
];

require_once __DIR__ . '/../../includes/breadcrumbs.php';
require_once __DIR__ . '/../../includes/layout-start.php';
require_once __DIR__ . '/../../includes/module-index-grid.php';
require_once __DIR__ . '/../../includes/layout-end.php';