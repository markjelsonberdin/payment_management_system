<?php
require_once __DIR__ . '/../../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
requireAuth();
requirePaymentPermission('payment.mis_overview');
$pageTitle='Overview'; $activeModule='payment'; $activePage='mis_admin/overview';
$breadcrumbs=[['label'=>'Overview','url'=>null]];
$shortcuts=[
 ['title'=>'MIS Admin Staffs','description'=>'Manage authorized MIS Admin personnel accounts and access.','icon'=>'fa-users-cog','permission'=>'payment_users.view','href'=>'payment-user-management.php'],
 ['title'=>'Roles & Permissions','description'=>'Review role-based access for Payment Management users.','icon'=>'fa-user-shield','permission'=>'payment.mis_overview','href'=>'roles-permissions.php'],
 ['title'=>'PayMongo Integration','description'=>'Configure online payment provider settings.','icon'=>'fa-credit-card','permission'=>'integration.paymongo.manage','href'=>'online-payment-integration.php'],
 ['title'=>'Google OCR Integration','description'=>'Configure receipt OCR processing.','icon'=>'fa-file-image','permission'=>'integration.ocr.manage','href'=>'google-ocr-integration.php'],
 ['title'=>'Security Monitoring','description'=>'Review account security events and status.','icon'=>'fa-fingerprint','permission'=>'payment.security.view','href'=>'security-monitoring.php'],
];
require_once ROOT_PATH.'/includes/breadcrumbs.php'; require_once ROOT_PATH.'/includes/layout-start.php'; renderBreadcrumbs($breadcrumbs);
?>
<main class="container-fluid py-4"><div class="mis-page-header mb-4"><div><h1 class="h3">Overview</h1><p>Open a dashboard module to manage personnel, integrations, and security.</p></div></div><div class="row g-3 module-button-grid"><?php foreach($shortcuts as $shortcut): ?><?php if(paymentRoleAllowsPermission(getCurrentUserRoleKey(),$shortcut['permission'])): ?><div class="col-md-6 col-xl-4"><a class="text-decoration-none d-block h-100" href="<?= BASE_URL ?>/modules/payment/pages/mis_admin/<?= htmlspecialchars($shortcut['href'],ENT_QUOTES,'UTF-8') ?>"><article class="card module-card hover-card h-100"><div class="card-body d-flex align-items-center gap-3"><div class="card-icon"><i class="fas <?= htmlspecialchars($shortcut['icon'],ENT_QUOTES,'UTF-8') ?>" aria-hidden="true"></i></div><div><h2 class="h6 mb-1 fw-semibold"><?= htmlspecialchars($shortcut['title'],ENT_QUOTES,'UTF-8') ?></h2><p class="small text-muted mb-0"><?= htmlspecialchars($shortcut['description'],ENT_QUOTES,'UTF-8') ?></p></div></div></article></a></div><?php endif; ?><?php endforeach; ?></div></main>
<?php require_once ROOT_PATH.'/includes/layout-end.php'; ?>
