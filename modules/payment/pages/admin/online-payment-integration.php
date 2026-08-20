<?php
/**
 * SMS 2 - Payment Management Module (Admin Integration Setup)
 * PURPOSE: Manage Payment Gateway Configuration (Reads keys from .env)
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
    
    $gateway_mode = trim($_POST['gateway_mode']);
    
    // Toggles (Kung naka-check, '1', kung hindi ay '0')
    $channel_gcash = isset($_POST['channel_gcash']) ? '1' : '0';
    $channel_maya  = isset($_POST['channel_maya']) ? '1' : '0';
    $channel_card  = isset($_POST['channel_card']) ? '1' : '0';
    $fee_policy    = trim($_POST['fee_policy']);

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO payment_db.payment_gateway_settings (setting_key, setting_value) 
            VALUES (:key, :val) 
            ON DUPLICATE KEY UPDATE setting_value = :val
        ");

        // I-update lang ang Application Rules (Keys are now in .env)
        $settingsToUpdate = [
            'gateway_mode'  => $gateway_mode,
            'channel_gcash' => $channel_gcash,
            'channel_maya'  => $channel_maya,
            'channel_card'  => $channel_card,
            'fee_policy'    => $fee_policy
        ];

        foreach ($settingsToUpdate as $key => $val) {
            $stmt->execute([':val' => $val, ':key' => $key]);
        }

        $pdo->commit();
        logActivity('update_gateway_settings', 'Updated PayMongo gateway mode and active channels.', 'payment');

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

// ==========================================
// LOAD SECURE CONFIGURATION
// ==========================================
$paymongoConfig = require __DIR__ . '/../../config/paymongo.php';

// Prepare keys for JS injection (Safe, will not expose .env path)
// We still need to pass both test and live keys for the JS dropdown toggle.
// Since paymongo.php only gives the active one, we will temporarily use sms2_env (Wait, we have getenv() available now via env_loader.php)
$pk_test = getenv('PAYMONGO_PK_TEST') ?: '';
$sk_test = !empty(getenv('PAYMONGO_SK_TEST')) ? 'sk_test_********' : '';
$wh_test = getenv('PAYMONGO_WHSEC_TEST') ?: '';

$pk_live = getenv('PAYMONGO_PK_LIVE') ?: '';
$sk_live = !empty(getenv('PAYMONGO_SK_LIVE')) ? 'sk_live_********' : '';
$wh_live = getenv('PAYMONGO_WHSEC_LIVE') ?: '';

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

    <!-- Dashboard Status Cards Header -->
    <div class="d-flex justify-content-between align-items-end mb-3">
        <h5 class="fw-bold mb-0 text-dark">Integration Health</h5>
        <button type="button" class="btn btn-sm btn-outline-primary fw-bold shadow-sm" id="btnRefreshStatus">
            <i class="fas fa-sync-alt me-1" id="iconRefreshStatus"></i> Refresh Status
        </button>
    </div>

    <!-- Dashboard Status Cards -->
    <div class="row g-3 mb-4">
        <!-- Gateway Status -->
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-3 h-100 border-start border-secondary border-4" id="gatewayStatusCard">
                <div class="card-body d-flex flex-column justify-content-center py-4 ps-4">
                    <span class="text-muted text-uppercase fw-bold mb-1" style="font-size: 0.70rem; letter-spacing: 0.5px;">Online Payment Gateway</span>
                    <h4 class="fw-bolder text-secondary mb-0" id="gatewayStatusText">
                        <i class="fas fa-spinner fa-spin me-2" id="gatewayStatusIcon"></i>Checking...
                    </h4>
                    <small class="text-muted mt-2 d-block" id="gatewayStatusSubtext">Fetching readiness state...</small>
                </div>
            </div>
        </div>

      <!-- Connection Status -->
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-3 h-100 border-start border-secondary border-4" id="apiConnectionCard">
                <div class="card-body d-flex flex-column justify-content-center py-4 ps-4">
                    <span class="text-muted text-uppercase fw-bold mb-1" style="font-size: 0.70rem; letter-spacing: 0.5px;">PayMongo API Connection</span>
                    <h4 class="fw-bolder text-secondary mb-0" id="apiConnectionText">
                        <i class="fas fa-spinner fa-spin me-2" id="apiConnectionIcon"></i>Checking...
                    </h4>
                    <small class="text-muted mt-2 d-block" id="apiConnectionSubtext">Verifying credentials...</small>
                </div>
            </div>
        </div>

        <!-- Webhook Status -->
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-3 h-100 border-start border-secondary border-4" id="webhookStatusCard">
                <div class="card-body d-flex flex-column justify-content-center py-4 ps-4">
                    <span class="text-muted text-uppercase fw-bold mb-1" style="font-size: 0.70rem; letter-spacing: 0.5px;">Webhook Status</span>
                    <h4 class="fw-bolder text-secondary mb-0" id="webhookStatusText">
                        <i class="fas fa-spinner fa-spin me-2" id="webhookStatusIcon"></i>Checking...
                    </h4>
                    <small class="text-muted mt-2 d-block" id="webhookStatusSubtext">Inspecting remote registration...</small>
                </div>
            </div>
        </div>
    </div>

    <form action="" method="POST">
        <?= csrfField(); ?>
        <div class="row">
            <!-- Left Column: API Credentials & Mode -->
            <div class="col-lg-7 mb-4">
                <div class="card border-0 shadow-sm rounded-4 h-100">
                    <div class="card-header bg-white border-bottom py-3">
                        <h5 class="fw-bold mb-0 text-primary"><i class="fas fa-key me-2"></i>PayMongo API Configuration</h5>
                    </div>
                    <div class="card-body p-4">
                        
                        <div class="mb-4 pb-3 border-bottom">
                            <label class="form-label fw-bold text-dark">Active Environment Mode</label>
                            <select class="form-select shadow-sm border-primary" id="gatewayModeSelect" name="gateway_mode" style="border-width: 2px;">
                                <option value="test" <?= (isset($settings['gateway_mode']) && $settings['gateway_mode'] === 'test') ? 'selected' : '' ?>>Test Mode (Sandbox / Mock Transactions)</option>
                                <option value="live" <?= (isset($settings['gateway_mode']) && $settings['gateway_mode'] === 'live') ? 'selected' : '' ?>>Live Mode (Production / Real Payments)</option>
                            </select>
                            <small class="text-muted d-block mt-1">This dropdown dictates which set of keys below will be used by the system during checkout.</small>
                        </div>

                        <!-- Dynamic Key Display -->
                        <div class="p-3 rounded-3 border" id="keyDisplayBox">
                            <div class="mb-3">
                                <label class="form-label fw-bold text-dark" id="lblPublicKey">Public Key</label>
                                <input type="text" class="form-control shadow-sm font-monospace bg-white" id="displayPublicKey" readonly>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold text-dark" id="lblSecretKey">Secret Key</label>
                                <input type="text" class="form-control shadow-sm font-monospace bg-white text-muted" id="displaySecretKey" readonly>
                            </div>
                            <div class="mb-0">
                                <label class="form-label fw-bold text-dark" id="lblWebhookKey">Webhook Secret</label>
                                <input type="text" class="form-control shadow-sm font-monospace bg-white" id="displayWebhookKey" readonly>
                            </div>
                            <small class="d-block mt-3" id="keyHintText"><i class="fas fa-info-circle me-1"></i>Keys are securely loaded from the <code>.env</code> file. To update, modify the environment file directly.</small>
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
                            <input class="form-check-input" type="checkbox" role="switch" id="gcashSwitch" name="channel_gcash" value="1" <?= (!isset($settings['channel_gcash']) || $settings['channel_gcash'] == '1') ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold text-dark ms-2" for="gcashSwitch">GCash E-Wallet</label>
                        </div>

                        <div class="form-check form-switch fs-6 mb-3">
                            <input class="form-check-input" type="checkbox" role="switch" id="mayaSwitch" name="channel_maya" value="1" <?= (!isset($settings['channel_maya']) || $settings['channel_maya'] == '1') ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold text-dark ms-2" for="mayaSwitch">Maya E-Wallet</label>
                        </div>

                        <div class="form-check form-switch fs-6">
                            <input class="form-check-input" type="checkbox" role="switch" id="cardSwitch" name="channel_card" value="1" <?= (!isset($settings['channel_card']) || $settings['channel_card'] == '1') ? 'checked' : '' ?>>
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
document.addEventListener("DOMContentLoaded", function () {
    const gatewaySelect = document.getElementById('gatewayModeSelect');
    const displayPK = document.getElementById('displayPublicKey');
    const displaySK = document.getElementById('displaySecretKey');
    const displayWH = document.getElementById('displayWebhookKey');
    
    const lblPK = document.getElementById('lblPublicKey');
    const lblSK = document.getElementById('lblSecretKey');
    const lblWH = document.getElementById('lblWebhookKey');
    
    const keyBox = document.getElementById('keyDisplayBox');
    const keyHint = document.getElementById('keyHintText');

    // Polling UI Elements
    const btnRefresh = document.getElementById('btnRefreshStatus');
    const iconRefresh = document.getElementById('iconRefreshStatus');

    const ui = {
        api: {
            card: document.getElementById('apiConnectionCard'),
            text: document.getElementById('apiConnectionText'),
            icon: document.getElementById('apiConnectionIcon'),
            sub: document.getElementById('apiConnectionSubtext')
        },
        webhook: {
            card: document.getElementById('webhookStatusCard'),
            text: document.getElementById('webhookStatusText'),
            icon: document.getElementById('webhookStatusIcon'),
            sub: document.getElementById('webhookStatusSubtext')
        },
        gateway: {
            card: document.getElementById('gatewayStatusCard'),
            text: document.getElementById('gatewayStatusText'),
            icon: document.getElementById('gatewayStatusIcon'),
            sub: document.getElementById('gatewayStatusSubtext')
        }
    };

    // PHP Variables injected into JS
    const keys = {
        test: {
            pk: "<?= htmlspecialchars($pk_test) ?>",
            sk: "<?= htmlspecialchars($sk_test) ?>",
            wh: "<?= htmlspecialchars($wh_test) ?>"
        },
        live: {
            pk: "<?= htmlspecialchars($pk_live) ?>",
            sk: "<?= htmlspecialchars($sk_live) ?>",
            wh: "<?= htmlspecialchars($wh_live) ?>"
        }
    };

    function updateFields() {
        const mode = gatewaySelect.value;
        
        displayPK.value = keys[mode].pk;
        displaySK.value = keys[mode].sk;
        displayWH.value = keys[mode].wh;

        if (mode === 'live') {
            lblPK.textContent = 'Live Public Key';
            lblSK.textContent = 'Live Secret Key';
            lblWH.textContent = 'Live Webhook Secret (Host Forge)';
            
            keyBox.className = 'p-3 bg-danger bg-opacity-10 rounded-3 border border-danger border-opacity-25';
            displayPK.classList.add('border-danger');
            displaySK.classList.add('border-danger');
            displayWH.classList.add('border-danger');
            
            keyHint.innerHTML = '<i class="fas fa-shield-alt me-1"></i>Live keys are locked in the <code>.env</code> file for maximum security.';
            keyHint.className = 'd-block mt-3 text-danger';
        } else {
            lblPK.textContent = 'Test Public Key';
            lblSK.textContent = 'Test Secret Key';
            lblWH.textContent = 'Test Webhook Secret (Ngrok)';
            
            keyBox.className = 'p-3 bg-light rounded-3 border';
            displayPK.classList.remove('border-danger');
            displaySK.classList.remove('border-danger');
            displayWH.classList.remove('border-danger');
            
            keyHint.innerHTML = '<i class="fas fa-info-circle me-1"></i>Keys are securely loaded from the <code>.env</code> file. To update, modify the environment file directly.';
            keyHint.className = 'd-block mt-3 text-muted';
        }
    }

    // Polling Logic
    let pollTimer;
    
    function setCardState(cardObj, statusStr, color, iconClass, subMessage) {
        cardObj.card.className = `card border-0 shadow-sm rounded-3 h-100 border-start border-${color} border-4`;
        cardObj.text.className = `fw-bolder text-${color} mb-0`;
        cardObj.text.innerHTML = `<i class="${iconClass} me-2"></i>${statusStr}`;
        cardObj.sub.textContent = subMessage;
    }

    function fetchStatus(force = false) {
        if (force) {
            iconRefresh.classList.add('fa-spin');
            btnRefresh.disabled = true;
        }

        const url = '../../api/paymongo/status.php' + (force ? '?force=1' : '');

        fetch(url)
            .then(res => res.json())
            .then(data => {
                if (data.error) throw new Error(data.error);

                // API CARD
                if (data.api.connected) {
                    setCardState(ui.api, 'Connected', 'success', 'fas fa-wifi', data.api.message);
                } else if (data.api.status === 'auth_failed') {
                    setCardState(ui.api, 'Auth Failed', 'danger', 'fas fa-times-circle', data.api.message);
                } else {
                    setCardState(ui.api, 'Unavailable', 'secondary', 'fas fa-plug', data.api.message);
                }

                // WEBHOOK CARD
                if (data.webhook.status === 'ready') {
                    setCardState(ui.webhook, 'Ready', 'success', 'fas fa-satellite-dish', data.webhook.message);
                } else if (data.webhook.status === 'configured_but_invalid') {
                    setCardState(ui.webhook, 'Invalid Config', 'warning', 'fas fa-exclamation-triangle', data.webhook.message);
                } else {
                    setCardState(ui.webhook, 'Not Ready', 'secondary', 'fas fa-unlink', data.webhook.message);
                }

                // GATEWAY CARD
                if (data.gateway.status === 'TEST ACTIVE') {
                    setCardState(ui.gateway, 'TEST ACTIVE', 'success', 'fas fa-play-circle', data.gateway.message);
                } else if (data.gateway.status === 'LIVE READY') {
                    setCardState(ui.gateway, 'LIVE READY', 'primary', 'fas fa-rocket', data.gateway.message);
                } else if (data.gateway.status === 'LIVE ACTIVE') {
                    setCardState(ui.gateway, 'LIVE ACTIVE', 'success', 'fas fa-check-double', data.gateway.message);
                } else if (data.gateway.status === 'LIVE NOT READY') {
                    setCardState(ui.gateway, 'LIVE NOT READY', 'danger', 'fas fa-ban', data.gateway.message);
                } else {
                    setCardState(ui.gateway, 'NOT READY', 'secondary', 'fas fa-stop-circle', data.gateway.message);
                }

            })
            .catch(err => {
                setCardState(ui.api, 'Error', 'danger', 'fas fa-exclamation-triangle', 'Failed to fetch status.');
                setCardState(ui.webhook, 'Error', 'danger', 'fas fa-exclamation-triangle', 'Failed to fetch status.');
                setCardState(ui.gateway, 'Error', 'danger', 'fas fa-exclamation-triangle', 'Failed to fetch status.');
            })
            .finally(() => {
                if (force) {
                    iconRefresh.classList.remove('fa-spin');
                    btnRefresh.disabled = false;
                }
            });
    }

    gatewaySelect.addEventListener('change', function() {
        updateFields();
    });

    btnRefresh.addEventListener('click', () => fetchStatus(true));
    
    // Initialize
    updateFields();
    fetchStatus(false);
    
    // Poll every 30 seconds
    pollTimer = setInterval(() => fetchStatus(false), 30000);
});
</script>

<?php require_once __DIR__ . '/../../../../includes/layout-end.php'; ?>