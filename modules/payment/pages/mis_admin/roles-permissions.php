<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
requireAuth();
requirePaymentPermission('payment.mis_overview');
$pageTitle='Roles & Permissions'; $activeModule='payment'; $activePage='mis_admin/roles-permissions';
$breadcrumbs=[['label'=>'MIS Admin','url'=>BASE_URL.'/modules/payment/pages/mis_admin/overview.php'],['label'=>'Roles & Permissions','url'=>null]];
$roles=[
 ['name'=>'MIS Admin','icon'=>'fa-user-shield','tone'=>'primary','description'=>'Controls Payment Management personnel, integrations, and operational security.','access'=>['View MIS Dashboard','Manage MIS Admin Staffs','Assign Payment roles','Activate, deactivate, unlock, and reset Payment user accounts','Configure PayMongo and Google OCR integrations','Review security monitoring']],
 ['name'=>'Accounting Admin','icon'=>'fa-calculator','tone'=>'success','description'=>'Oversees financial setup, approvals, receivables, and reporting.','access'=>['Manage fees and receivables','Approve managed bulk billing runs','Review payment concerns and ledgers','View collection analytics and exports','Manage School Sales catalog']],
 ['name'=>'Accounting Officer','icon'=>'fa-file-invoice-dollar','tone'=>'info','description'=>'Handles billing and authorized operational payment review.','access'=>['Create individual and managed billing','Review payment history and ledgers','Review payment concerns and discounts','Reconcile bank statement records','View collection analytics']],
 ['name'=>'Cashier','icon'=>'fa-cash-register','tone'=>'warning','description'=>'Processes counter collections and school-item sales.','access'=>['Collect walk-in payments','Issue official receipts','View cashier transactions','Process approved School Items sales']],
];
require_once ROOT_PATH.'/includes/breadcrumbs.php'; require_once ROOT_PATH.'/includes/layout-start.php'; renderBreadcrumbs($breadcrumbs);
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/modules/payment/assets/css/payment-mis-admin.css?v=1">
<main class="container-fluid payment-page py-4"><div class="payment-page-header"><div class="payment-page-header-text"><h1 class="h3 payment-page-title">Roles &amp; Permissions</h1><p>Payment roles and their assigned module access.</p></div><div class="payment-page-actions"><a class="btn btn-outline-primary" href="payment-user-management.php"><i class="fas fa-users me-1" aria-hidden="true"></i>Manage Staff</a></div></div><div class="alert alert-info border-0 shadow-sm small"><i class="fas fa-circle-info me-2" aria-hidden="true"></i>Server permissions remain authoritative. Assign roles through <strong>MIS Admin Staffs</strong>.</div><div class="row g-3"><?php foreach($roles as $role): ?><div class="col-md-6"><section class="card mis-card h-100"><div class="card-body"><div class="d-flex align-items-center gap-3 mb-3"><span class="text-<?= htmlspecialchars($role['tone'],ENT_QUOTES,'UTF-8') ?> fs-3"><i class="fas <?= htmlspecialchars($role['icon'],ENT_QUOTES,'UTF-8') ?>" aria-hidden="true"></i></span><div><h2 class="h5 mb-1"><?= htmlspecialchars($role['name'],ENT_QUOTES,'UTF-8') ?></h2><p class="small text-muted mb-0"><?= htmlspecialchars($role['description'],ENT_QUOTES,'UTF-8') ?></p></div></div><h3 class="h6 text-uppercase small text-muted">Access</h3><ul class="mb-0 ps-3"><?php foreach($role['access'] as $access): ?><li class="small mb-1"><?= htmlspecialchars($access,ENT_QUOTES,'UTF-8') ?></li><?php endforeach; ?></ul></div></section></div><?php endforeach; ?></div></main>
<?php require_once ROOT_PATH.'/includes/layout-end.php'; ?>
