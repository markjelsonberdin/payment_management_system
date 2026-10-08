<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
requireAuth();
requirePaymentPermission('payment_users.view');
$pageTitle = 'Payment Users';
$activeModule = 'payment';
$activePage = 'mis_admin/payment-user-management';
$breadcrumbs = [['label' => 'MIS Admin', 'url' => BASE_URL . '/modules/payment/pages/mis_admin/overview.php'], ['label' => 'Payment Users', 'url' => null]];
require_once ROOT_PATH . '/includes/breadcrumbs.php';
require_once ROOT_PATH . '/includes/layout-start.php';
renderBreadcrumbs($breadcrumbs);
?>
<link rel="stylesheet" href="<?= BASE_URL ?>/modules/payment/assets/css/payment-mis-admin.css?v=6">
<main class="container-fluid payment-page py-4" id="accountingUsersApp"
 data-api="<?= htmlspecialchars(BASE_URL . '/modules/payment/api/mis_admin/payment-users.php', ENT_QUOTES, 'UTF-8') ?>"
 data-csrf="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>"
 data-can-create="<?= paymentEffectivePermission(getCurrentUserRoleKey(), 'payment_users.create') ? '1' : '0' ?>"
 data-can-update="<?= paymentEffectivePermission(getCurrentUserRoleKey(), 'payment_users.update') ? '1' : '0' ?>"
 data-can-assign-role="<?= paymentEffectivePermission(getCurrentUserRoleKey(), 'payment_users.role.assign') ? '1' : '0' ?>"
 data-can-activate="<?= paymentEffectivePermission(getCurrentUserRoleKey(), 'payment_users.activate') ? '1' : '0' ?>"
 data-can-deactivate="<?= paymentEffectivePermission(getCurrentUserRoleKey(), 'payment_users.deactivate') ? '1' : '0' ?>"
 data-can-unlock="<?= paymentEffectivePermission(getCurrentUserRoleKey(), 'payment_users.unlock') ? '1' : '0' ?>"
 data-can-reset-password="<?= paymentEffectivePermission(getCurrentUserRoleKey(), 'payment_users.password.reset') ? '1' : '0' ?>">
 <div class="mis-page-header">
  <div>
   <h1 class="h3">Payment Users</h1>
   <p>Manage authorized Payment personnel accounts and access states.</p>
  </div>
  <?php if (paymentEffectivePermission(getCurrentUserRoleKey(), 'payment_users.create')): ?>
   <button class="btn btn-primary" id="newOfficer"><i class="fas fa-plus me-1"></i>Add Payment User</button>
  <?php endif; ?>
 </div>

 <div id="accountingUsersMessage" class="mis-async-state" role="status" aria-live="polite"></div>

 <!-- Stat cards -->
 <div class="row g-3 mb-4">
  <div class="col-6 col-xl-3">
   <div class="mis-stat-card">
    <div class="mis-stat-icon mis-stat-icon-primary"><i class="fas fa-id-card"></i></div>
    <div class="mis-stat-label">Total Personnel</div>
    <div class="mis-stat-value" id="statTotal">0</div>
    <div class="mis-stat-sub"><span class="mis-stat-dot bg-primary"></span>Authorized</div>
   </div>
  </div>
  <div class="col-6 col-xl-3">
   <div class="mis-stat-card">
    <div class="mis-stat-icon mis-stat-icon-success"><i class="fas fa-check-circle"></i></div>
    <div class="mis-stat-label">Active Accounts</div>
    <div class="mis-stat-value" id="statActive">0</div>
    <div class="mis-stat-sub" id="statActivePct">Authenticated &amp; operational</div>
   </div>
  </div>
  <div class="col-6 col-xl-3">
   <div class="mis-stat-card">
    <div class="mis-stat-icon mis-stat-icon-danger"><i class="fas fa-lock"></i></div>
    <div class="mis-stat-label">Locked Accounts</div>
    <div class="mis-stat-value" id="statLocked">0</div>
    <div class="mis-stat-sub text-danger" id="statLockedSub">No locked accounts</div>
   </div>
  </div>
  <div class="col-6 col-xl-3">
   <div class="mis-stat-card">
    <div class="mis-stat-icon mis-stat-icon-info"><i class="fas fa-sync-alt"></i></div>
    <div class="mis-stat-label">Force Password Change</div>
    <div class="mis-stat-value" id="statForce">0</div>
    <div class="mis-stat-sub">Flagged for credential cycle</div>
   </div>
  </div>
 </div>

 <!-- Table card -->
 <section class="card mis-table-card">
  <div class="card-header mis-table-card-header">
   <div>
    <h2 class="h6 mb-0">Payment Personnel</h2>
    <small class="text-muted">Authorized accounts and their current access states</small>
   </div>
   <button type="button" class="btn btn-outline-secondary btn-sm" id="refreshUsers"><i class="fas fa-rotate me-1"></i>Refresh</button>
  </div>

  <div class="mis-filter-bar mx-3 mt-3 mb-0">
   <div class="row g-2 align-items-center">
    <div class="col-md-6">
     <div class="input-group">
      <span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span>
      <input type="search" class="form-control" id="userSearch" placeholder="Search by name, username, or email…">
     </div>
    </div>
    <div class="col-6 col-md-3">
     <select class="form-select" id="roleFilter">
      <option value="">All Roles</option>
      <option value="accounting_admin">Accounting Admin</option>
      <option value="accounting_officer">Accounting Officer</option>
      <option value="cashier">Cashier</option>
     </select>
    </div>
    <div class="col-6 col-md-3">
     <select class="form-select" id="statusFilter">
      <option value="">All Status</option>
      <option value="active">Active</option>
      <option value="inactive">Inactive</option>
     </select>
    </div>
   </div>
  </div>

  <div class="card-body p-0">
   <div class="table-responsive">
    <table class="table table-hover align-middle mb-0 mis-users-table">
     <thead>
      <tr>
       <th scope="col" class="ps-4">User / Identity</th>
       <th scope="col">Role</th>
       <th scope="col">Email</th>
       <th scope="col">Status</th>
       <th scope="col">Security</th>
       <th scope="col">Force change</th>
       <th scope="col">Last login</th>
       <th scope="col" class="text-end pe-4">Actions</th>
      </tr>
     </thead>
     <tbody id="accountingUsersRows">
      <tr><td colspan="8" class="text-center py-4 text-muted">Loading Payment users…</td></tr>
     </tbody>
    </table>
   </div>
  </div>

  <div class="mis-pagination px-3 pb-3">
   <small class="text-muted" id="paginationSummary">Showing 0 of 0 payment accounts</small>
   <div class="btn-group" id="paginationControls" role="group" aria-label="Pagination"></div>
  </div>
 </section>

 <div class="modal fade" id="officerModal" tabindex="-1" aria-labelledby="officerModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered payment-user-modal-dialog">
   <div class="modal-content payment-user-modal">
    <form id="officerForm" novalidate>
     <div class="modal-header payment-user-modal-header">
      <div class="d-flex align-items-center gap-3">
       <span class="payment-user-modal-icon"><i class="ti ti-user-plus" aria-hidden="true"></i></span>
       <div>
        <h2 class="modal-title" id="officerModalTitle">Add Payment User</h2>
        <p class="mb-0">Assign PMS credentials and an authorized system role.</p>
       </div>
      </div>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close dialog"></button>
     </div>
     <div class="modal-body payment-user-modal-body">
      <div class="modal-form-message" role="alert" aria-live="assertive"></div>
      <input type="hidden" name="user_id">

      <div class="payment-user-field">
       <label class="form-label" for="paymentUserRole">Role</label>
       <div class="payment-user-input-wrap">
        <i class="ti ti-user-shield payment-user-field-icon" id="paymentUserRoleIcon" aria-hidden="true"></i>
        <select id="paymentUserRole" class="form-select payment-user-control has-leading-icon" name="role_key" required>
         <option value="accounting_admin">Accounting Admin</option>
         <option value="accounting_officer">Accounting Officer</option>
         <option value="cashier">Cashier</option>
        </select>
        <div class="invalid-feedback">Select a valid Payment role.</div>
       </div>
       <div class="payment-user-field-note"><i class="ti ti-info-circle" aria-hidden="true"></i>Account access is limited by the selected PMS role.</div>
      </div>

      <div class="payment-user-field">
       <label class="form-label" for="paymentUserName">Full name</label>
       <div class="payment-user-input-wrap">
        <i class="ti ti-id-badge-2 payment-user-field-icon" aria-hidden="true"></i>
        <input id="paymentUserName" class="form-control payment-user-control has-leading-icon" name="full_name" minlength="2" maxlength="120" placeholder="e.g. Juan P. Dela Cruz" required>
        <div class="invalid-feedback">Enter the user's complete name.</div>
       </div>
      </div>

      <div class="row g-3">
       <div class="col-sm-6">
        <div class="payment-user-field">
         <label class="form-label" for="paymentUsername">Username</label>
         <div class="payment-user-input-wrap">
          <i class="ti ti-at payment-user-field-icon" aria-hidden="true"></i>
          <input id="paymentUsername" class="form-control payment-user-control has-leading-icon" name="username" minlength="3" maxlength="64" pattern="[A-Za-z0-9._-]+" placeholder="e.g. jdelacruz" required autocomplete="off">
          <div class="invalid-feedback">Use 3–64 letters, numbers, dots, underscores, or hyphens.</div>
         </div>
        </div>
       </div>
       <div class="col-sm-6">
        <div class="payment-user-field">
         <label class="form-label" for="paymentUserEmail">Email</label>
         <div class="payment-user-input-wrap">
          <i class="ti ti-mail payment-user-field-icon" aria-hidden="true"></i>
          <input id="paymentUserEmail" class="form-control payment-user-control has-leading-icon" name="email" type="email" maxlength="190" placeholder="e.g. jdelacruz@bestlink.edu.ph" required autocomplete="off">
          <div class="invalid-feedback">Enter a valid email address.</div>
         </div>
        </div>
       </div>
      </div>

      <div class="password-fields">
       <div class="payment-user-field">
        <label class="form-label" for="paymentTempPassword">Temporary password</label>
        <div class="payment-user-input-wrap">
         <input id="paymentTempPassword" class="form-control payment-user-control" name="password" type="password" minlength="12" maxlength="128" placeholder="Enter initial temporary password" autocomplete="new-password">
        </div>
        <div class="form-text">The user must replace this at first login.</div>
       </div>

       <div class="payment-user-field">
        <label class="form-label" for="paymentTempPasswordConfirm">Confirm temporary password</label>
        <div class="payment-user-input-wrap">
         <input id="paymentTempPasswordConfirm" class="form-control payment-user-control" name="password_confirm" type="password" minlength="12" maxlength="128" placeholder="Re-enter password" autocomplete="new-password">
        </div>
       </div>

       <div class="payment-password-policy">
        <div class="password-feedback" aria-live="polite">Start typing to check your password:</div>
        <ul class="password-rules">
         <li data-rule="length">12–128 characters</li>
         <li data-rule="upper">Uppercase letter</li>
         <li data-rule="lower">Lowercase letter</li>
         <li data-rule="number">Number</li>
         <li data-rule="special">Special character</li>
         <li data-rule="identifier">Does not contain username/email</li>
         <li data-rule="match">Passwords match</li>
        </ul>
       </div>

       <div class="payment-user-policy-lock">
        <i class="ti ti-lock-check" aria-hidden="true"></i>
        <div><strong>Immediate password change required</strong><span>This security policy is automatically enforced on the user's first sign-in.</span></div>
       </div>
      </div>
     </div>
     <div class="modal-footer payment-user-modal-footer">
      <div class="payment-user-governance"><i class="ti ti-lock" aria-hidden="true"></i>Protected by PMS Security Governance</div>
      <div class="payment-user-modal-actions">
       <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
       <button type="submit" class="btn btn-primary"><i class="ti ti-user-plus me-1" aria-hidden="true"></i>Save User</button>
      </div>
     </div>
    </form>
   </div>
  </div>
 </div>
 <div class="modal fade" id="resetPasswordModal" tabindex="-1" aria-labelledby="resetPasswordTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered reset-password-dialog"><div class="modal-content reset-password-modal"><form id="resetPasswordForm" novalidate>
   <div class="modal-header reset-password-header"><div><h2 class="modal-title" id="resetPasswordTitle">Reset password</h2><small id="resetPasswordTarget"></small></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close dialog"></button></div>
   <div class="modal-body reset-password-body"><div class="modal-form-message" role="alert" aria-live="assertive"></div><input type="hidden" name="user_id">
    <div class="reset-password-notice"><i class="ti ti-shield-lock" aria-hidden="true"></i><span>This creates a temporary password. The user must change it on their next login.</span></div>
    <div class="reset-password-toolbar"><button type="button" class="btn btn-outline-primary btn-sm" id="generateResetPassword"><i class="ti ti-refresh" aria-hidden="true"></i><span>Auto-generate</span></button><button type="button" class="btn btn-light btn-sm" id="viewResetPassword" aria-pressed="false"><i class="ti ti-eye" aria-hidden="true"></i><span>View</span></button><button type="button" class="btn btn-light btn-sm" id="copyResetPassword"><i class="ti ti-copy" aria-hidden="true"></i><span>Copy</span></button></div>
    <div class="payment-user-field"><label class="form-label" for="resetTempPassword">New temporary password</label><input id="resetTempPassword" class="form-control payment-user-control" name="password" type="password" minlength="12" maxlength="128" placeholder="Enter or generate a strong password" autocomplete="new-password" required><div class="invalid-feedback">Enter a temporary password that meets every requirement.</div></div>
    <div class="payment-user-field"><label class="form-label" for="resetTempPasswordConfirm">Confirm password</label><input id="resetTempPasswordConfirm" class="form-control payment-user-control" name="password_confirm" type="password" minlength="12" maxlength="128" placeholder="Re-enter password" autocomplete="new-password" required><div class="invalid-feedback">The password confirmation must match.</div></div>
    <div class="payment-password-policy"><div class="password-feedback" aria-live="polite">Start typing to check your password.</div><ul class="password-rules"><li data-rule="length">12–128 characters</li><li data-rule="upper">Uppercase letter</li><li data-rule="lower">Lowercase letter</li><li data-rule="number">Number</li><li data-rule="special">Special character</li><li data-rule="identifier">Does not contain username/email</li><li data-rule="match">Passwords match</li></ul></div>
    <section class="reset-account-control"><div><strong>Account status</strong><span id="resetAccountStatus">Managed Payment personnel account</span><small>Deactivation revokes current sessions while preserving audit and payment records.</small></div><button type="button" class="btn btn-outline-danger btn-sm" id="deactivateFromReset"><i class="ti ti-user-off" aria-hidden="true"></i>Deactivate account</button></section>
   </div>
   <div class="modal-footer reset-password-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-warning" type="submit"><i class="ti ti-key" aria-hidden="true"></i>Reset password</button></div>
  </form></div></div>
 </div>
</main>
<script src="<?= BASE_URL ?>/modules/payment/assets/js/accounting-user-management.js?v=7"></script>
<?php require_once ROOT_PATH . '/includes/layout-end.php'; ?>
