<?php
/**
 * SMS 2 - Payment Management Module (Admin Integration Setup)
 * PURPOSE: Manage Payment Gateway Credentials, Mode, and Channel Toggles.
 */
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../includes/authentication.php';
require_once __DIR__ . '/../../../../includes/audit.php';
require_once __DIR__ . '/../../database/db_connect.php';

requireAuth();
requirePaymentPermission('payment.online_payment_config');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

global $pdo;

// ==========================================
// SAVE SETTINGS LOGIC (POST)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_gateway_settings'])) {
    $gateway_mode            = trim($_POST['gateway_mode']);
    $paymongo_public_key     = trim($_POST['paymongo_public_key']);
    $paymongo_secret_key     = trim($_POST['paymongo_secret_key']);
    $paymongo_webhook_secret = trim($_POST['paymongo_webhook_secret']);
    
    // Toggles (Kung naka-check, '1', kung hindi ay '0')
    $channel_gcash           = isset($_POST['channel_gcash']) ? '1' : '0';
    $channel_maya            = isset($_POST['channel_maya']) ? '1' : '0';
    $channel_card            = isset($_POST['channel_card']) ? '1' : '0';
    $fee_policy              = trim($_POST['fee_policy']);

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("UPDATE payment_db.payment_gateway_settings SET setting_value = :val WHERE setting_key = :key");

        // I-update ang bawat configuration key sa database
        $settingsToUpdate = [
            'gateway_mode'            => $gateway_mode,
            'paymongo_public_key'     => $paymongo_public_key,
            'paymongo_webhook_secret' => $paymongo_webhook_secret,
            'channel_gcash'           => $channel_gcash,
            'channel_maya'            => $channel_maya,
            'channel_card'            => $channel_card,
            'fee_policy'              => $fee_policy
        ];

        // Huwag i-update ang secret key kung blangko (para hindi mabura yung lumang naka-save na mask/key)
        if (!empty($paymongo_secret_key) && strpos($paymongo_secret_key, '********') === false) {
            $settingsToUpdate['paymongo_secret_key'] = $paymongo_secret_key;
        }

        foreach ($settingsToUpdate as $key => $val) {
            $stmt->execute([':val' => $val, ':key' => $key]);
        }

        $pdo->commit();
        logActivity('update_gateway_settings', 'Updated PayMongo gateway configurations and channels.', 'payment');

        header("Location: online-payment-integration.php?success=1");
        exit();

    } catch (Exception $e) {
        $pdo->rollBack();
        header("Location: online-payment-integration.php?error=" . urlencode($e->getMessage()));
        exit();
    }
}

// ==========================================
// FETCH SETTINGS FROM DATABASE
// ==========================================
$settings = [];
try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM payment_db.payment_gateway_settings");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (PDOException $e) {
    $dbError = $e->getMessage();
}

$pageTitle    = 'Online Payment Integration';
$activeModule = 'payment';
$activePage   = 'online-payment-integration';
$breadcrumbs  = [
    ['label' => 'Payment Management', 'url' => BASE_URL . '/modules/payment/index.php'],
    ['label' => 'Online Payment Integration', 'url' => null],
];

require_once __DIR__ . '/../../../../includes/breadcrumbs.php';
require_once __DIR__ . '/../../../../includes/layout-start.php';
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">

<?php renderBreadcrumbs($breadcrumbs); ?>

<div class="container-fluid py-4">
    
    <!-- Header -->
    <div class="row mb-4 align-items-center">
        <div class="col-md-6">
            <h2 class="mb-1 fw-bolder"><i class="fas fa-globe text-primary me-2"></i>Online Payment Integration</h2>
            <p class="text-muted mb-0 fs-6">Configure payment gateway credentials, active digital channels, and processing fee rules.</p>
        </div>
    </div>

    <!-- Alerts -->
    <?php if (isset($_GET['success']) && $_GET['success'] == 1): ?>
        <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm rounded-3" role="alert">
            <i class="fas fa-check-circle me-2"></i> <strong>Success!</strong> Payment gateway settings updated successfully.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm rounded-3" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i> <strong>Error!</strong> <?= htmlspecialchars($_GET['error']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <form action="" method="POST">
        <div class="row">
            <!-- Left Column: API Credentials & Mode -->
            <div class="col-lg-7 mb-4">
                <div class="card border-0 shadow-sm rounded-4 h-100">
                    <div class="card-header bg-white border-bottom py-3">
                        <h5 class="fw-bold mb-0 text-primary"><i class="fas fa-key me-2"></i>PayMongo API Configuration</h5>
                    </div>
                    <div class="card-body p-4">
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold text-dark">Gateway Environment Mode</label>
                            <select class="form-select shadow-sm" name="gateway_mode">
                                <option value="test" <?= (isset($settings['gateway_mode']) && $settings['gateway_mode'] === 'test') ? 'selected' : '' ?>>Test Mode (Sandbox / Mock Transactions)</option>
                                <option value="live" <?= (isset($settings['gateway_mode']) && $settings['gateway_mode'] === 'live') ? 'selected' : '' ?>>Live Mode (Production / Real Payments)</option>
                            </select>
                            <small class="text-muted">Use Test Mode for system debugging and verification.</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold text-dark">Public Key</label>
                            <input type="text" class="form-control shadow-sm font-monospace" name="paymongo_public_key" value="<?= htmlspecialchars($settings['paymongo_public_key'] ?? '') ?>" placeholder="pk_test_..." required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold text-dark">Secret Key</label>
                            <div class="input-group shadow-sm">
                                <input type="password" class="form-control font-monospace" name="paymongo_secret_key" value="<?= htmlspecialchars($settings['paymongo_secret_key'] ?? '') ?>" placeholder="sk_test_...">
                                <span class="input-group-text bg-white text-muted" style="cursor: pointer;" onclick="toggleSecretVisibility(this)">
                                    <i class="fas fa-eye"></i>
                                </span>
                            </div>
                            <small class="text-muted">Leave blank if you do not wish to change the existing secret key.</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold text-dark">Webhook Signing Secret</label>
                            <input type="text" class="form-control shadow-sm font-monospace" name="paymongo_webhook_secret" value="<?= htmlspecialchars($settings['paymongo_webhook_secret'] ?? '') ?>" placeholder="whsec_...">
                            <small class="text-muted">Required for validating asynchronous server-to-server payment notifications.</small>
                        </div>

                    </div>
                </div>
            </div>

            <!-- Right Column: Channel Toggles & Fee Policy -->
            <div class="col-lg-5 mb-4">
                
                <!-- Payment Channels Card -->
                <div class="card border-0 shadow-sm rounded-4 mb-4">
                    <div class="card-header bg-white border-bottom py-3">
                        <h5 class="fw-bold mb-0 text-primary"><i class="fas fa-toggle-on me-2"></i>Active Payment Channels</h5>
                    </div>
                    <div class="card-body p-4">
                        <p class="text-muted small mb-3">Enable or disable channels available to students in their portal payment gateway interface.</p>
                        
                        <div class="form-check form-switch fs-6 mb-3">
                            <input class="form-check-input" type="checkbox" role="switch" id="gcashSwitch" name="channel_gcash" value="1" <?= (isset($settings['channel_gcash']) && $settings['channel_gcash'] == '1') ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold text-dark ms-2" for="gcashSwitch">GCash E-Wallet</label>
                        </div>

                        <div class="form-check form-switch fs-6 mb-3">
                            <input class="form-check-input" type="checkbox" role="switch" id="mayaSwitch" name="channel_maya" value="1" <?= (isset($settings['channel_maya']) && $settings['channel_maya'] == '1') ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold text-dark ms-2" for="mayaSwitch">Maya E-Wallet</label>
                        </div>

                        <div class="form-check form-switch fs-6">
                            <input class="form-check-input" type="checkbox" role="switch" id="cardSwitch" name="channel_card" value="1" <?= (isset($settings['channel_card']) && $settings['channel_card'] == '1') ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold text-dark ms-2" for="cardSwitch">Credit / Debit Card (Visa/Mastercard)</label>
                        </div>
                    </div>
                </div>

                <!-- Convenience Fee Policy Card -->
                <div class="card border-0 shadow-sm rounded-4">
                    <div class="card-header bg-white border-bottom py-3">
                        <h5 class="fw-bold mb-0 text-primary"><i class="fas fa-hand-holding-usd me-2"></i>Convenience Fee Policy</h5>
                    </div>
                    <div class="card-body p-4">
                        <div class="mb-3">
                            <select class="form-select shadow-sm" name="fee_policy">
                                <option value="pass_to_student" <?= (isset($settings['fee_policy']) && $settings['fee_policy'] === 'pass_to_student') ? 'selected' : '' ?>>Pass Processing Fee to Student</option>
                                <option value="absorb_by_school" <?= (isset($settings['fee_policy']) && $settings['fee_policy'] === 'absorb_by_school') ? 'selected' : '' ?>>Absorb Processing Fee by School</option>
                            </select>
                        </div>
                        <button type="submit" name="save_gateway_settings" class="btn btn-primary w-100 py-2 shadow-sm fw-bold">
                            <i class="fas fa-save me-1"></i> Save Gateway Configuration
                        </button>
                    </div>
                </div>

            </div>
        </div>
    </form>
</div>

<script>
function toggleSecretVisibility(element) {
    const input = element.previousElementSibling;
    const icon = element.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
}
</script>

<?php require_once __DIR__ . '/../../../../includes/layout-end.php'; ?>