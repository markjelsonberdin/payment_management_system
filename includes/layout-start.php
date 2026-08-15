<?php
/**
 * SMS 2 - Authenticated Layout Start
 * Include after setting $pageTitle, $activeModule, optional $activePage, $breadcrumbs
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/authentication.php';
requireAuth();

// Force password change before using the app
$script = basename($_SERVER['SCRIPT_NAME'] ?? '');
if (!empty($_SESSION['must_change_password']) && $script !== 'change-password.php') {
    header('Location: ' . BASE_URL . '/login/change-password.php');
    exit;
}

$pageTitle    = $pageTitle ?? APP_NAME;
$activeModule = $activeModule ?? '';
$activePage   = $activePage ?? '';
$breadcrumbs  = $breadcrumbs ?? [];
$bodyClass    = 'sms-app';

requireModuleAccess($activeModule);

require_once ROOT_PATH . '/includes/header.php';
?>
<div class="sms-wrapper">
    <?php require_once ROOT_PATH . '/includes/navbar.php'; ?>
    <?php require_once ROOT_PATH . '/includes/sidebar.php'; ?>
    <div class="sms-content">
        <main class="sms-main">
