<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
requireAuth();
requirePaymentPermission('payment.accounting_users_view');
$pageTitle = 'Accounting User Management';
$activeModule = 'payment';
$activePage = 'accounting_admin/user-management';
$breadcrumbs = [['label' => 'Payment Management', 'url' => BASE_URL . '/modules/payment/index.php'], ['label' => 'Accounting Admin', 'url' => null], ['label' => 'User Management', 'url' => null]];
require_once ROOT_PATH . '/includes/breadcrumbs.php';
require_once ROOT_PATH . '/includes/layout-start.php';
renderBreadcrumbs($breadcrumbs);
?>
<main class="container-fluid py-4" id="accountingUsersApp" data-api="<?= htmlspecialchars(BASE_URL . '/modules/payment/api/accounting/accounting-users.php', ENT_QUOTES, 'UTF-8') ?>" data-csrf="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
    <div class="d-flex justify-content-between align-items-start gap-3 mb-4">
        <div><h1 class="h3 mb-1">Accounting User Management</h1><p class="text-muted mb-0">Manage Accounting Officer and Cashier accounts only. Other system accounts are outside this portal.</p></div>
        <button class="btn btn-primary" id="newOfficer">Add Accounting User</button>
    </div>
    <div id="accountingUsersMessage" aria-live="polite"></div>
    <section class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive">
        <table class="table table-hover align-middle mb-0"><thead><tr><th class="ps-4">Name</th><th>Role</th><th>Username</th><th>Email</th><th>Status</th><th>Force change</th><th class="text-end pe-4">Actions</th></tr></thead><tbody id="accountingUsersRows"><tr><td colspan="7" class="text-center py-4 text-muted">Loading…</td></tr></tbody></table>
    </div></div></section>
    <div class="modal fade" id="officerModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form id="officerForm">
        <div class="modal-header"><h2 class="modal-title h5" id="officerModalTitle">Add Accounting User</h2><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><input type="hidden" name="user_id"><div class="mb-3"><label class="form-label">Role</label><select class="form-select" name="role_key" required><option value="accounting_officer">Accounting Officer</option><option value="cashier">Cashier</option></select></div><div class="mb-3"><label class="form-label">Full name</label><input class="form-control" name="full_name" required></div><div class="mb-3"><label class="form-label">Username</label><input class="form-control" name="username" required></div><div class="mb-3"><label class="form-label">Email</label><input class="form-control" name="email" type="email" required></div><div class="mb-3"><label class="form-label">Temporary password</label><input class="form-control" name="password" type="password"><div class="form-text">Required for new accounts. The user must change it at next login.</div></div></div>
        <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save User</button></div>
    </form></div></div></div>
</main>
<script src="<?= BASE_URL ?>/modules/payment/assets/js/accounting-user-management.js"></script>
<?php require_once ROOT_PATH . '/includes/layout-end.php'; ?>
