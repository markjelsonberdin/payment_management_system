<?php
/**
 * SMS 2 - Payment Management Module (Admin Integration Setup)
 * PURPOSE: Manage Payment Gateway Configuration (Reads keys from .env)
 */
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../includes/authentication.php';
require_once __DIR__ . '/../../database/db_connect.php';
require_once __DIR__ . '/../../includes/PayMongoIntegrationSecurity.php';
require_once __DIR__ . '/../../includes/PayMongoConfigurationService.php';

requireAuth();
requirePaymentPermission('integration.paymongo.manage');
global $pdo;
$corePdo = db();
if (!$corePdo) {
    http_response_code(503);
    exit('PayMongo administration is temporarily unavailable.');
}
try {
    $actor = PayMongoIntegrationSecurity::requireActiveMisActor($corePdo);
} catch (DomainException $e) {
    http_response_code(403);
    exit('Your account authority changed. Sign in again.');
}
$outbox = new PaymentAuditOutboxService($pdo, new StructuredActivityAuditWriter($corePdo));
$configurationService = new PayMongoConfigurationService(
    $pdo,
    new SchoolSalesCatalogMutationInfrastructure($pdo, $outbox)
);

// Configuration mutation: technical PayMongo settings only.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_gateway_settings'])) {
    if (!verifyCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        exit('The request could not be verified. Refresh the page and try again.');
    }
    try {
        $configurationService->update($_POST, $actor);
        unset($_SESSION['paymongo_status_cache']);
        header('Location: online-payment-integration.php');
        exit();
    } catch (DomainException|InvalidArgumentException $e) {
        $safeCode = in_array($e->getMessage(), ['GATEWAY_MODE_INVALID', 'FEE_POLICY_INVALID'], true)
            ? $e->getMessage() : 'PAYMONGO_CONFIG_INVALID';
        header('Location: online-payment-integration.php?error_code=' . rawurlencode($safeCode));
        exit();
    } catch (Throwable $e) {
        error_log('PayMongo configuration update failed: ' . get_class($e));
        header('Location: online-payment-integration.php?error_code=PAYMONGO_CONFIG_SAVE_FAILED');
        exit();
    }
}

// ==========================================
// FETCH SETTINGS FROM DATABASE
// ==========================================
$settings = [];
try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM payment_gateway_settings");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (PDOException $e) {
    error_log('PayMongo settings lookup failed: ' . get_class($e));
    $dbError = 'Payment gateway settings are temporarily unavailable.';
}

// ==========================================
// LOAD SECURE CONFIGURATION
// ==========================================
require_once __DIR__ . '/../../config/env_loader.php';
payment_load_env(__DIR__ . '/../../.env');
$credentialStatus = [
    'test' => [
        'public' => (string) getenv('PAYMONGO_PK_TEST') !== '',
        'secret' => (string) getenv('PAYMONGO_SK_TEST') !== '',
        'webhook' => (string) getenv('PAYMONGO_WHSEC_TEST') !== '',
    ],
    'live' => [
        'public' => (string) getenv('PAYMONGO_PK_LIVE') !== '',
        'secret' => (string) getenv('PAYMONGO_SK_LIVE') !== '',
        'webhook' => (string) getenv('PAYMONGO_WHSEC_LIVE') !== '',
    ],
];
$formCorrelationId = CatalogCorrelationId::generate();
$errorMessages = [
    'GATEWAY_MODE_INVALID' => 'Select a supported PayMongo environment.',
    'FEE_POLICY_INVALID' => 'Select a supported processing-fee policy.',
    'PAYMONGO_CONFIG_INVALID' => 'The PayMongo configuration request is invalid.',
    'PAYMONGO_CONFIG_SAVE_FAILED' => 'The PayMongo configuration could not be saved.',
];

$pageTitle    = 'PayMongo Integration';
$activeModule = 'payment';
$activePage   = 'mis_admin/online-payment-integration';
$breadcrumbs  = [
    ['label' => 'MIS Admin', 'url' => BASE_URL . '/modules/payment/pages/mis_admin/dashboard.php'],
    ['label' => 'PayMongo Integration', 'url' => null],
];

require_once __DIR__ . '/../../../../includes/breadcrumbs.php';

require_once __DIR__ . '/../../../../includes/layout-start.php';
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/modules/payment/assets/css/payment-mis-admin.css?v=1">

<?php renderBreadcrumbs($breadcrumbs); ?>

<div class="container-fluid py-4">
    
    <!-- Header -->
    <div class="mis-page-header">
        <div><h1 class="h3"><i class="ti ti-world text-primary me-2" aria-hidden="true"></i>PayMongo Integration</h1><p>Manage and monitor the Payment Management System's PayMongo technical integration.</p></div>
    </div>

    <!-- Validation errors remain visible; successful saves return silently. -->
    <?php if (isset($_GET['error_code'])): ?>
        <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm rounded-3" role="alert">
            <i class="ti ti-alert-triangle me-2"></i> <strong>Error!</strong> <?= e($errorMessages[$_GET['error_code']] ?? 'The PayMongo request could not be completed.') ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close alert"></button>
        </div>
    <?php endif; ?>

    <!-- Dashboard Status Cards Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
        <h5 class="fw-bold mb-0 text-dark">Integration Health</h5>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-sm btn-outline-success fw-bold shadow-sm" id="btnTestConnection">
                <i class="ti ti-plug-connected me-1"></i> Test Connection
            </button>
            <button type="button" class="btn btn-sm btn-outline-primary fw-bold shadow-sm" id="btnRefreshStatus">
                <i class="ti ti-refresh me-1" id="iconRefreshStatus"></i> Refresh Status
            </button>
        </div>
    </div>

    <!-- Dashboard Status Cards -->
    <div class="row g-3 mb-4">
        <!-- Gateway Status -->
        <div class="col-md-4">
            <div class="card mis-card" id="gatewayStatusCard">
                <div class="card-body d-flex flex-column justify-content-center py-4 ps-4">
                    <span class="text-muted text-uppercase fw-bold mb-1" style="font-size: 0.70rem; letter-spacing: 0.5px;">Online Payment Gateway</span>
                    <h4 class="fw-bolder text-secondary mb-0" id="gatewayStatusText">
                        <i class="ti ti-loader me-2" id="gatewayStatusIcon"></i>Checking...
                    </h4>
                    <small class="text-muted mt-2 d-block" id="gatewayStatusSubtext">Fetching readiness state...</small>
                </div>
            </div>
        </div>

      <!-- Connection Status -->
        <div class="col-md-4">
            <div class="card mis-card" id="apiConnectionCard">
                <div class="card-body d-flex flex-column justify-content-center py-4 ps-4">
                    <span class="text-muted text-uppercase fw-bold mb-1" style="font-size: 0.70rem; letter-spacing: 0.5px;">PayMongo API Connection</span>
                    <h4 class="fw-bolder text-secondary mb-0" id="apiConnectionText">
                        <i class="ti ti-loader me-2" id="apiConnectionIcon"></i>Checking...
                    </h4>
                    <small class="text-muted mt-2 d-block" id="apiConnectionSubtext">Verifying credentials...</small>
                </div>
            </div>
        </div>

        <!-- Webhook Status -->
        <div class="col-md-4">
            <div class="card mis-card" id="webhookStatusCard">
                <div class="card-body d-flex flex-column justify-content-center py-4 ps-4">
                    <span class="text-muted text-uppercase fw-bold mb-1" style="font-size: 0.70rem; letter-spacing: 0.5px;">Webhook Status</span>
                    <h4 class="fw-bolder text-secondary mb-0" id="webhookStatusText">
                        <i class="ti ti-loader me-2" id="webhookStatusIcon"></i>Checking...
                    </h4>
                    <small class="text-muted mt-2 d-block" id="webhookStatusSubtext">Inspecting remote registration...</small>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-3 mb-4">
        <div class="card-body p-3">
            <div class="row g-3 small">
                <div class="col-lg-6"><strong>Expected webhook URL:</strong> <span class="text-break" id="expectedWebhookUrl">Checking...</span></div>
                <div class="col-lg-2"><strong>URL status:</strong> <span id="webhookUrlStatus">UNVERIFIED</span></div>
                <div class="col-lg-2"><strong>Last successful test:</strong> <span id="lastSuccessfulTest">Never</span></div>
                <div class="col-lg-2"><strong>Last configuration update:</strong> <span id="lastConfigUpdate">Unknown</span></div>
            </div>
        </div>
    </div>

    <form action="" method="POST">
        <?= csrfField(); ?>
        <input type="hidden" name="correlation_id" value="<?= e($formCorrelationId) ?>">
        <div class="row">
            <!-- Left Column: API Credentials & Mode -->
            <div class="col-lg-7 mb-4">
                <div class="card mis-card">
                    <div class="card-header bg-white border-bottom py-3">
                        <h5 class="fw-bold mb-0 text-primary"><i class="ti ti-key me-2"></i>PayMongo API Configuration</h5>
                    </div>
                    <div class="card-body p-4">
                        
                        <div class="mb-4 pb-3 border-bottom">
                            <label class="form-label fw-bold text-dark" for="gatewayModeSelect">Active Environment Mode</label>
                            <select class="form-select shadow-sm border-primary" id="gatewayModeSelect" name="gateway_mode" style="border-width: 2px;">
                                <option value="test" <?= (isset($settings['gateway_mode']) && $settings['gateway_mode'] === 'test') ? 'selected' : '' ?>>Test Mode (Sandbox / Mock Transactions)</option>
                                <option value="live" <?= (isset($settings['gateway_mode']) && $settings['gateway_mode'] === 'live') ? 'selected' : '' ?>>Live Mode (Production / Real Payments)</option>
                            </select>
                            <small class="text-muted d-block mt-1">This dropdown dictates which set of keys below will be used by the system during checkout.</small>
                        </div>

                        <!-- Masked presence fields; credential values never enter HTML or JavaScript. -->
                        <div class="p-3 rounded-3 border" id="keyDisplayBox">
                            <?php foreach (['test' => 'Test', 'live' => 'Live'] as $modeKey => $modeLabel): ?>
                                <h6 class="fw-bold <?= $modeKey === 'live' ? 'text-danger' : 'text-primary' ?><?= $modeKey === 'live' ? ' mt-4' : '' ?>"><?= e($modeLabel) ?> credentials</h6>
                                <?php foreach (['public' => 'Public Key', 'secret' => 'Secret Key', 'webhook' => 'Webhook Secret'] as $credentialKey => $credentialLabel): ?>
                                    <div class="mb-3">
                                        <label class="form-label small fw-semibold mb-1" for="<?= e($modeKey . ucfirst($credentialKey)) ?>Key"><?= e($credentialLabel) ?></label>
                                        <input
                                            type="password"
                                            class="form-control font-monospace"
                                            id="<?= e($modeKey . ucfirst($credentialKey)) ?>Key"
                                            value="<?= $credentialStatus[$modeKey][$credentialKey] ? '••••••••••••' : '' ?>"
                                            placeholder="<?= $credentialStatus[$modeKey][$credentialKey] ? '' : 'Not configured' ?>"
                                            aria-label="<?= e($modeLabel . ' ' . $credentialLabel) ?>"
                                            readonly
                                            autocomplete="off"
                                        >
                                        <div class="form-text"><?= $credentialStatus[$modeKey][$credentialKey] ? 'Configured' : 'Not configured' ?></div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                            <small class="d-block mt-2 text-muted"><i class="ti ti-lock me-1"></i>Keys stay masked and are managed through protected server environment variables.</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column: Channel Toggles -->
            <div class="col-lg-5 mb-4">
                
                <!-- Payment Channels Card -->
                <div class="card mis-card mb-4">
                    <div class="card-header bg-white border-bottom py-3">
                        <h5 class="fw-bold mb-0 text-primary"><i class="ti ti-toggle-right me-2"></i>Active Payment Channels</h5>
                    </div>
                    <div class="card-body p-4">
                        <p class="text-muted small mb-3">Enable or disable channels available to students in their portal payment gateway interface.</p>
                        
                        <div class="form-check form-switch fs-6 mb-3" id="container_qrph">
                            <input class="form-check-input channel-toggle" type="checkbox" role="switch" id="qrphSwitch" name="channel_qrph" value="1">
                            <label class="form-check-label fw-bold text-dark ms-2" for="qrphSwitch">QR Ph</label>
                            <div id="status_qrph" class="small mt-1 ms-2"></div>
                        </div>
                        
                        <div class="form-check form-switch fs-6 mb-3" id="container_gcash">
                            <input class="form-check-input channel-toggle" type="checkbox" role="switch" id="gcashSwitch" name="channel_gcash" value="1">
                            <label class="form-check-label fw-bold text-dark ms-2" for="gcashSwitch">GCash E-Wallet</label>
                            <div id="status_gcash" class="small mt-1 ms-2"></div>
                        </div>

                        <div class="form-check form-switch fs-6 mb-3" id="container_maya">
                            <input class="form-check-input channel-toggle" type="checkbox" role="switch" id="mayaSwitch" name="channel_maya" value="1">
                            <label class="form-check-label fw-bold text-dark ms-2" for="mayaSwitch">Maya E-Wallet</label>
                            <div id="status_maya" class="small mt-1 ms-2"></div>
                        </div>

                        <div class="form-check form-switch fs-6" id="container_card">
                            <input class="form-check-input channel-toggle" type="checkbox" role="switch" id="cardSwitch" name="channel_card" value="1">
                            <label class="form-check-label fw-bold text-dark ms-2" for="cardSwitch">Credit / Debit Card (Visa/Mastercard)</label>
                            <div id="status_card" class="small mt-1 ms-2"></div>
                        </div>
                        
                        <div id="hidden_channels_msg" class="text-muted small mt-3" style="display: none;">
                            <i class="ti ti-info-circle me-1"></i> Some channels are hidden because they are not active in your PayMongo account.
                        </div>
                    </div>
                </div>

                <div class="card mis-card">
                    <div class="card-header bg-white border-bottom py-3">
                        <h5 class="fw-bold mb-0 text-primary"><i class="ti ti-cash me-2"></i>Processing Fee</h5>
                    </div>
                    <div class="card-body p-4">
                        <label class="form-label fw-semibold" for="feePolicySelect">Who pays the PayMongo processing fee?</label>
                        <select class="form-select mb-3" id="feePolicySelect" name="fee_policy" required>
                            <option value="pass_to_student" <?= ($settings['fee_policy'] ?? 'pass_to_student') === 'pass_to_student' ? 'selected' : '' ?>>Pass Processing Fee to Student</option>
                            <option value="absorb_by_school" <?= ($settings['fee_policy'] ?? '') === 'absorb_by_school' ? 'selected' : '' ?>>Absorb Processing Fee by School</option>
                        </select>
                        <p class="text-muted small">This choice is validated and recorded in the administrative audit trail.</p>
                        <button type="submit" name="save_gateway_settings" class="btn btn-primary w-100 py-2 shadow-sm fw-bold">
                            <i class="ti ti-device-floppy me-1"></i> Save Gateway Configuration
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

    // Polling UI Elements
    const btnRefresh = document.getElementById('btnRefreshStatus');
    const iconRefresh = document.getElementById('iconRefreshStatus');
    const btnTestConnection = document.getElementById('btnTestConnection');
    const csrfToken = document.querySelector('input[name="csrf_token"]').value;

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

    const channels = {
        test: {
            qrph: <?= isset($settings['test_channel_qrph']) && $settings['test_channel_qrph'] === '0' ? 'false' : 'true' ?>,
            gcash: <?= isset($settings['test_channel_gcash']) && $settings['test_channel_gcash'] === '0' ? 'false' : 'true' ?>,
            maya: <?= isset($settings['test_channel_maya']) && $settings['test_channel_maya'] === '0' ? 'false' : 'true' ?>,
            card: <?= isset($settings['test_channel_card']) && $settings['test_channel_card'] === '0' ? 'false' : 'true' ?>
        },
        live: {
            qrph: <?= isset($settings['live_channel_qrph']) && $settings['live_channel_qrph'] === '0' ? 'false' : 'true' ?>,
            gcash: <?= isset($settings['live_channel_gcash']) && $settings['live_channel_gcash'] === '0' ? 'false' : 'true' ?>,
            maya: <?= isset($settings['live_channel_maya']) && $settings['live_channel_maya'] === '0' ? 'false' : 'true' ?>,
            card: <?= isset($settings['live_channel_card']) && $settings['live_channel_card'] === '0' ? 'false' : 'true' ?>
        }
    };

    const switches = {
        qrph: document.getElementById('qrphSwitch'),
        gcash: document.getElementById('gcashSwitch'),
        maya: document.getElementById('mayaSwitch'),
        card: document.getElementById('cardSwitch')
    };

    function updateFields() {
        const mode = gatewaySelect.value;
        switches.qrph.checked = channels[mode].qrph;
        switches.gcash.checked = channels[mode].gcash;
        switches.maya.checked = channels[mode].maya;
        switches.card.checked = channels[mode].card;
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
                    setCardState(ui.api, 'Connected', 'success', 'ti ti-wifi', data.api.message);
                } else if (data.api.status === 'auth_failed') {
                    setCardState(ui.api, 'Auth Failed', 'danger', 'ti ti-circle-x', data.api.message);
                } else {
                    setCardState(ui.api, 'Unavailable', 'secondary', 'ti ti-plug', data.api.message);
                }

                // WEBHOOK CARD
                if (data.webhook.status === 'ready') {
                    setCardState(ui.webhook, 'Ready', 'success', 'ti ti-antenna', data.webhook.message);
                } else if (data.webhook.status === 'url_mismatch') {
                    setCardState(ui.webhook, 'URL Mismatch', 'danger', 'ti ti-link-off', data.webhook.message);
                } else if (data.webhook.status === 'configured_but_invalid') {
                    setCardState(ui.webhook, 'Invalid Config', 'warning', 'ti ti-alert-triangle', data.webhook.message);
                } else {
                    setCardState(ui.webhook, 'Not Ready', 'secondary', 'ti ti-link-off', data.webhook.message);
                }

                // GATEWAY CARD
                if (data.gateway.status === 'TEST ACTIVE') {
                    setCardState(ui.gateway, 'TEST ACTIVE', 'success', 'ti ti-player-play', data.gateway.message);
                } else if (data.gateway.status === 'LIVE READY') {
                    setCardState(ui.gateway, 'LIVE READY', 'primary', 'ti ti-rocket', data.gateway.message);
                } else if (data.gateway.status === 'LIVE ACTIVE') {
                    setCardState(ui.gateway, 'LIVE ACTIVE', 'success', 'ti ti-checks', data.gateway.message);
                } else if (data.gateway.status === 'LIVE NOT READY') {
                    setCardState(ui.gateway, 'LIVE NOT READY', 'danger', 'ti ti-ban', data.gateway.message);
                } else {
                    setCardState(ui.gateway, 'NOT READY', 'secondary', 'ti ti-player-stop', data.gateway.message);
                }

                document.getElementById('expectedWebhookUrl').textContent = data.webhook.expected_url || 'Unavailable';
                document.getElementById('webhookUrlStatus').textContent = data.webhook.url_status || 'UNVERIFIED';
                document.getElementById('lastSuccessfulTest').textContent = data.last_test?.successful_at || 'Never';
                document.getElementById('lastConfigUpdate').textContent = data.last_configuration_update || 'Unknown';

            })
            .catch(err => {
                setCardState(ui.api, 'Error', 'danger', 'ti ti-alert-triangle', 'Failed to fetch status.');
                setCardState(ui.webhook, 'Error', 'danger', 'ti ti-alert-triangle', 'Failed to fetch status.');
                setCardState(ui.gateway, 'Error', 'danger', 'ti ti-alert-triangle', 'Failed to fetch status.');
            })
            .finally(() => {
                if (force) {
                    iconRefresh.classList.remove('fa-spin');
                    btnRefresh.disabled = false;
                }
            });
    }

    function renderChannelStatus(channelCode, statusData) {
        const div = document.getElementById('status_' + channelCode);
        const container = document.getElementById('container_' + channelCode);
        if (!div || !container) return;
        
        let html = '';
        if (statusData.status === 'AVAILABLE') {
            html = `<span class="text-success"><i class="ti ti-circle-check me-1"></i>Available in PayMongo & Enabled for students</span>`;
        } else if (statusData.status === 'DISABLED_BY_ADMIN') {
            html = `<span class="text-muted"><i class="ti ti-toggle-left me-1"></i>${statusData.message}</span>`;
        } else if (statusData.status === 'NOT_ACTIVE_IN_PAYMONGO') {
            html = `<span class="text-danger"><i class="ti ti-circle-x me-1"></i>${statusData.message}</span>`;
        } else {
            html = `<span class="text-secondary"><i class="ti ti-ban me-1"></i>${statusData.message}</span>`;
        }
        div.innerHTML = html;

        // Hide completely if not active in PayMongo
        if (!statusData.provider_active) {
            container.style.display = 'none';
            return true; // indicates it was hidden
        } else {
            container.style.display = 'block';
            return false;
        }
    }

    function fetchChannelsStatus() {
        const mode = gatewaySelect.value;
        const url = '../../api/paymongo/channels.php?mode=' + mode;
        
        // Show loading state
        ['qrph', 'gcash', 'maya', 'card'].forEach(c => {
            const div = document.getElementById('status_' + c);
            const container = document.getElementById('container_' + c);
            if (div) div.innerHTML = `<span class="text-muted"><i class="ti ti-loader me-1"></i>Checking...</span>`;
            if (container) container.style.display = 'block'; // reset visibility
        });
        
        const hiddenMsg = document.getElementById('hidden_channels_msg');
        if (hiddenMsg) hiddenMsg.style.display = 'none';

        fetch(url)
            .then(res => res.json())
            .then(data => {
                if (data.error) throw new Error(data.error);
                if (data.channels) {
                    let hiddenCount = 0;
                    Object.keys(data.channels).forEach(channelCode => {
                        if (renderChannelStatus(channelCode, data.channels[channelCode])) {
                            hiddenCount++;
                        }
                    });
                    
                    if (hiddenCount > 0 && hiddenMsg) {
                        hiddenMsg.style.display = 'block';
                    }
                }
            })
            .catch(err => {
                ['qrph', 'gcash', 'maya', 'card'].forEach(c => {
                    const div = document.getElementById('status_' + c);
                    if (div) div.innerHTML = `<span class="text-danger"><i class="ti ti-alert-triangle me-1"></i>Error checking capability</span>`;
                });
            });
    }

    gatewaySelect.addEventListener('change', function() {
        updateFields();
        fetchChannelsStatus(); // Re-fetch capabilities for the new mode
    });

    // Event listener for manual toggles to update the status text instantly 
    // (though real save happens on form submit)
    document.querySelectorAll('.channel-toggle').forEach(el => {
        el.addEventListener('change', function() {
            // Re-fetch to reflect the new state against the provider state
            // Actually, we need to save to DB to see it, OR we can mock the UI change.
            // Since it's complicated, we just let them save. But let's show a "Not saved" indicator.
            const channel = this.id.replace('Switch', '');
            const div = document.getElementById('status_' + channel);
            if (div) div.innerHTML = `<span class="text-warning"><i class="ti ti-alert-circle me-1"></i>Unsaved change. Click save to apply.</span>`;
        });
    });

    btnTestConnection.addEventListener('click', () => {
        btnTestConnection.disabled = true;
        const body = new URLSearchParams({
            csrf_token: csrfToken,
            correlation_id: crypto.randomUUID(),
        });
        fetch('../../api/paymongo/test-connection.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
            body: body.toString(),
        })
            .then(res => res.json().then(data => ({ok: res.ok, data})))
            .then(({ok, data}) => {
                const label = data.status || data.error || 'PROVIDER_ERROR';
                setCardState(ui.api, label.replaceAll('_', ' '), ok ? 'success' : 'danger', ok ? 'ti ti-wifi' : 'ti ti-alert-triangle', data.message || 'Connection test completed.');
                fetchStatus(true);
            })
            .catch(() => setCardState(ui.api, 'NETWORK ERROR', 'danger', 'ti ti-alert-triangle', 'The connection test could not be completed.'))
            .finally(() => { btnTestConnection.disabled = false; });
    });

    btnRefresh.addEventListener('click', () => {
        fetchStatus(true);
        fetchChannelsStatus();
    });
    
    // Initialize
    updateFields();
    fetchStatus(false);
    fetchChannelsStatus();
    
    // Poll every 30 seconds
    pollTimer = setInterval(() => {
        fetchStatus(false);
        fetchChannelsStatus();
    }, 30000);
});
</script>

<?php require_once __DIR__ . '/../../../../includes/layout-end.php'; ?>
