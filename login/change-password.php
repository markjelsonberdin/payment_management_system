<?php
/**
 * SMS 2 – Forced / voluntary change password (authenticated)
 */
require_once __DIR__ . '/../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once ROOT_PATH . '/includes/security-ui.php';
require_once ROOT_PATH . '/includes/security-workflow.php';
require_once ROOT_PATH . '/includes/module-controls.php';
requireAuth();

// Password changes are disabled while the whole system is in maintenance
if (smsIsSystemInMaintenance() && getCurrentUserRoleKey() !== 'admin') {
    header('Location: ' . BASE_URL . '/account/maintenance.php');
    exit;
}

// Module staff cannot self-change passwords — only Super Admin (or forced first-login change)
$error = '';
$forced = !empty($_SESSION['must_change_password']);
if (!$forced && getCurrentUserRoleKey() !== 'admin') {
    header('Location: ' . BASE_URL . '/dashboard/index.php');
    exit;
}

$policy = smsPasswordPolicy();
$minLen = $policy['min'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfVerify()) {
        $error = 'Security check failed. Please refresh and try again.';
    } else {
        $current = (string) ($_POST['current_password'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $confirm  = (string) ($_POST['password_confirm'] ?? '');
        $userId = getCurrentUserId();

        $pdo = db();
        $row = null;
        if ($pdo && $userId) {
            $stmt = $pdo->prepare('SELECT password_hash, username, email FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
        }

        if (!$row || !password_verify($current, (string) $row['password_hash'])) {
            $error = 'Current password is incorrect.';
        } elseif ($password !== $confirm) {
            $error = 'New passwords do not match.';
        } elseif (!(smsValidatePasswordForAccount($password, (string)($row['username'] ?? ''), (string)($row['email'] ?? ''))['ok'])) {
            $error = smsValidatePasswordForAccount($password, (string)($row['username'] ?? ''), (string)($row['email'] ?? ''))['message'];
        } elseif (smsSetUserPassword((int) $userId, $password, false)) {
            $_SESSION['must_change_password'] = 0;
            logActivity('password_change', 'Password changed by user', 'System');
            if (getCurrentUserRoleKey() === 'student') {
                header('Location: ' . BASE_URL . '/modules/student-portal/pages/my-profile.php');
            } else {
                header('Location: ' . BASE_URL . '/dashboard/index.php');
            }
            exit;
        } else {
            $error = 'Could not update password. Please try again.';
        }
    }
}

$pageTitle = 'Change Password';
$bodyClass = 'login-page';
$forceTheme = 'light';
require_once ROOT_PATH . '/includes/header.php';
?>
<link href="<?= BASE_URL ?>/assets/css/auth-pages.css?v=2" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/password-strength.css" rel="stylesheet">
<style>
.password-change-card { width: min(480px, 100%); }
.password-change-card .auth-icon {
    width: 46px; height: 46px; display: grid; place-items: center;
    margin-bottom: 1rem; border-radius: 13px; background: #eaf1ff;
    color: #2555d9; font-size: 1.15rem;
}
.password-change-card .sms-pw-group .form-control {
    min-height: 48px; padding: 11px 48px 11px 14px !important;
    border: 1px solid #cbd7e8 !important; border-radius: 10px !important;
    background: #fff !important; color: #111827 !important;
    -webkit-text-fill-color: #111827 !important;
}
.password-change-card .sms-pw-group .form-control:focus {
    border-color: #4775e8 !important;
    box-shadow: 0 0 0 3px rgba(37,85,217,.13) !important;
}
.password-change-card .sms-pw-toggle { color: #64748b !important; }
.password-change-card .password-note {
    margin: -.25rem 0 1.25rem; color: #64748b; font-size: .78rem;
}
.password-change-card { box-shadow: 0 24px 70px rgba(8, 25, 65, .18); }
.password-change-card .auth-lead { color: #4b6384; }
.password-change-card .policy-callout { background:#f4f8ff; border:1px solid #dbe8ff; border-radius:12px; padding:12px 14px; color:#385170; font-size:.82rem; }
</style>

<main class="auth-stage">
<section class="auth-card password-change-card" aria-labelledby="changePasswordTitle">
    <div class="auth-icon"><i class="fas fa-key" aria-hidden="true"></i></div>
    <h1 id="changePasswordTitle">Change password</h1>
    <p class="auth-lead"><?= $forced ? 'Your account was issued a temporary password. Create a private password before continuing.' : 'Update your account password.' ?></p>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" id="changePasswordForm" novalidate>
        <?= csrfField() ?>
        <div class="mb-3">
            <label class="form-label fw-semibold" for="current_password">Current password</label>
            <?= smsPasswordInput(['id' => 'current_password', 'name' => 'current_password', 'required' => true, 'autocomplete' => 'current-password']) ?>
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold" for="password">New password</label>
            <?= smsPasswordInput(['id' => 'password', 'name' => 'password', 'required' => true, 'minlength' => $minLen, 'autocomplete' => 'new-password']) ?>
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold" for="password_confirm">Confirm new password</label>
            <?= smsPasswordInput(['id' => 'password_confirm', 'name' => 'password_confirm', 'required' => true, 'minlength' => $minLen, 'autocomplete' => 'new-password']) ?>
        </div>
        <?= smsPasswordStrengthMarkup('password') ?>
        <div class="policy-callout mt-3"><i class="fas fa-user-shield me-1" aria-hidden="true"></i>Use <?= (int)$policy['min'] ?>–<?= (int)$policy['max'] ?> characters. Do not use your username, email, or a default password.</div>
        <div id="passwordMatchMessage" class="small mt-2" aria-live="polite"></div>
        <button type="submit" class="btn btn-auth-primary w-100 mt-3">Save secure password</button>
        <?php if (!$forced): ?>
            <div class="text-center mt-3">
                <a href="<?= BASE_URL ?>/dashboard/index.php">Cancel</a>
            </div>
        <?php endif; ?>
    </form>
</section>
</main>
<?php require_once ROOT_PATH . '/includes/scripts.php'; ?>
<script src="<?= BASE_URL ?>/assets/js/password-strength.js"></script>
<script>
document.addEventListener('DOMContentLoaded',()=>{const p=document.getElementById('password'),c=document.getElementById('password_confirm'),m=document.getElementById('passwordMatchMessage');if(!p||!c)return;const update=()=>{if(!c.value){m.textContent='';return;}const ok=p.value===c.value;m.className='small mt-2 '+(ok?'text-success':'text-danger');m.textContent=ok?'✓ Passwords match':'Passwords do not match';};p.addEventListener('input',update);c.addEventListener('input',update);});
</script>
