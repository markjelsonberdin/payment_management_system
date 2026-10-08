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
    ['label' => 'MIS Admin', 'url' => BASE_URL . '/modules/payment/pages/mis_admin/overview.php'],
    ['label' => 'PayMongo Integration', 'url' => null],
];

require_once __DIR__ . '/../../../../includes/breadcrumbs.php';

require_once __DIR__ . '/../../../../includes/layout-start.php';
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/modules/payment/assets/css/payment-mis-admin.css?v=1">
<link rel="stylesheet" href="<?= BASE_URL ?>/modules/payment/assets/css/mis/paymongo-config.css?v=4">

<?php renderBreadcrumbs($breadcrumbs); ?>

<div class="container-fluid payment-page paymongo-config py-4" id="paymongoAdminApp" data-credential-url="<?= BASE_URL ?>/modules/payment/api/mis_admin/paymongo-credential.php">
    <div class="paymongo-page-header">
        <div>
            <div class="paymongo-eyebrow">Payment infrastructure</div>
            <h1>Online Payment Configuration</h1>
            <p>Manage payment gateway credentials, payment channels, and integration health.</p>
        </div>
        <div class="paymongo-header-actions">
            <button type="button" class="btn btn-outline-primary" id="btnTestConnection">
                <i class="ti ti-bolt me-1" aria-hidden="true"></i>Test Connection
            </button>
            <button type="button" class="btn btn-primary" id="btnRefreshStatus">
                <i class="ti ti-refresh me-1" id="iconRefreshStatus" aria-hidden="true"></i>Refresh Status
            </button>
        </div>
    </div>

    <?php if (isset($_GET['error_code'])): ?>
        <div class="alert alert-danger alert-dismissible fade show paymongo-alert" role="alert">
            <i class="ti ti-alert-triangle me-2" aria-hidden="true"></i>
            <strong>Configuration not saved.</strong>
            <?= e($errorMessages[$_GET['error_code']] ?? 'The PayMongo request could not be completed.') ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close alert"></button>
        </div>
    <?php endif; ?>
    <?php if (isset($dbError)): ?>
        <div class="alert alert-warning paymongo-alert" role="alert"><?= e($dbError) ?></div>
    <?php endif; ?>

    <section class="paymongo-readiness-card" id="gatewayStatusCard" aria-labelledby="gatewayStatusText">
        <div class="paymongo-readiness-icon"><i class="ti ti-rocket" id="gatewayStatusIcon" aria-hidden="true"></i></div>
        <div class="paymongo-readiness-copy">
            <span>Online Payment Gateway</span>
            <h2 id="gatewayStatusText">Checking...</h2>
            <p id="gatewayStatusSubtext">Fetching readiness state...</p>
        </div>
        <div class="paymongo-live-indicator"><span></span>Live status</div>
    </section>

    <div class="paymongo-health-grid">
        <article class="paymongo-health-card" id="apiConnectionCard">
            <div class="paymongo-health-icon"><i class="ti ti-wifi" id="apiConnectionIcon" aria-hidden="true"></i></div>
            <div>
                <span>PayMongo API Connection</span>
                <h3 id="apiConnectionText">Checking...</h3>
                <p id="apiConnectionSubtext">Verifying credentials...</p>
            </div>
        </article>
        <article class="paymongo-health-card" id="webhookStatusCard">
            <div class="paymongo-health-icon"><i class="ti ti-antenna" id="webhookStatusIcon" aria-hidden="true"></i></div>
            <div>
                <span>Webhook Status</span>
                <h3 id="webhookStatusText">Checking...</h3>
                <p id="webhookStatusSubtext">Inspecting remote registration...</p>
            </div>
        </article>
    </div>

    <section class="paymongo-panel paymongo-webhook-panel">
        <div class="paymongo-panel-heading">
            <div>
                <span class="paymongo-section-icon"><i class="ti ti-webhook" aria-hidden="true"></i></span>
                <div><h2>Webhook Configuration Details</h2><p>Endpoint registration and latest connection test.</p></div>
            </div>
            <span class="paymongo-security-pill"><i class="ti ti-lock" aria-hidden="true"></i>Protected endpoint</span>
        </div>
        <div class="paymongo-webhook-grid">
            <div class="paymongo-detail paymongo-detail-wide">
                <span>Expected Webhook URL</span>
                <div class="paymongo-webhook-url-row">
                    <strong id="expectedWebhookUrl">Checking...</strong>
                    <button type="button" class="paymongo-copy-url" id="copyWebhookUrl" title="Copy webhook URL" aria-label="Copy expected webhook URL"><i class="ti ti-copy" aria-hidden="true"></i><span>Copy</span></button>
                </div>
            </div>
            <div class="paymongo-detail">
                <span>URL Status</span>
                <strong id="webhookUrlStatus">UNVERIFIED</strong>
            </div>
            <div class="paymongo-detail">
                <span>Last Test Event</span>
                <strong id="lastTestEvent">Never Tested</strong>
            </div>
        </div>
    </section>

    <form action="" method="POST" id="paymongoConfigurationForm">
        <?= csrfField(); ?>
        <input type="hidden" name="correlation_id" value="<?= e($formCorrelationId) ?>">

        <div class="paymongo-config-grid">
            <div class="paymongo-config-main">
                <section class="paymongo-panel">
                    <div class="paymongo-panel-heading">
                        <div>
                            <span class="paymongo-section-icon"><i class="ti ti-adjustments" aria-hidden="true"></i></span>
                            <div><h2>Payment Environment Mode</h2><p>Choose which protected credential set is used for student checkout.</p></div>
                        </div>
                    </div>
                    <div class="paymongo-field-block">
                        <label class="form-label" for="gatewayModeSelect">Active environment</label>
                        <select class="form-select" id="gatewayModeSelect" name="gateway_mode">
                            <option value="test" <?= (isset($settings['gateway_mode']) && $settings['gateway_mode'] === 'test') ? 'selected' : '' ?>>Test Mode (Sandbox / Staging Simulation)</option>
                            <option value="live" <?= (isset($settings['gateway_mode']) && $settings['gateway_mode'] === 'live') ? 'selected' : '' ?>>Live Mode (Production / Real Payments)</option>
                        </select>
                        <div class="paymongo-inline-note"><i class="ti ti-info-circle" aria-hidden="true"></i>Switching modes directs new student checkouts to the selected PayMongo environment after you save.</div>
                    </div>
                </section>

                <section class="paymongo-panel">
                    <div class="paymongo-panel-heading">
                        <div>
                            <span class="paymongo-section-icon"><i class="ti ti-key" aria-hidden="true"></i></span>
                            <div><h2>PayMongo API Configuration</h2><p>Keys stay masked until an authorized MIS Admin views or copies one.</p></div>
                        </div>
                    </div>
                    <div class="paymongo-credential-grid">
                        <?php foreach (['test' => ['Test Credentials', 'Sandbox', 'ti-flask'], 'live' => ['Live Credentials', 'Production', 'ti-shield-lock']] as $modeKey => $credentialMeta): ?>
                            <div class="paymongo-credential-group <?= $modeKey === 'live' ? 'is-live' : 'is-test' ?>">
                                <div class="paymongo-credential-header">
                                    <span class="paymongo-credential-icon"><i class="ti <?= e($credentialMeta[2]) ?>" aria-hidden="true"></i></span>
                                    <div><h3><?= e($credentialMeta[0]) ?></h3><span><?= e($credentialMeta[1]) ?></span></div>
                                </div>
                                <?php foreach (['public' => 'Public Key', 'secret' => 'Secret Key', 'webhook' => 'Webhook Signing Secret'] as $credentialKey => $credentialLabel): ?>
                                    <div class="paymongo-credential-row" data-mode="<?= e($modeKey) ?>" data-credential="<?= e($credentialKey) ?>" data-configured="<?= $credentialStatus[$modeKey][$credentialKey] ? '1' : '0' ?>">
                                        <div class="paymongo-credential-meta">
                                            <span><?= e($credentialLabel) ?></span>
                                            <strong><?= $credentialStatus[$modeKey][$credentialKey] ? 'Configured' : 'Not configured' ?></strong>
                                        </div>
                                        <div class="paymongo-credential-value-wrap">
                                            <code class="paymongo-mask" aria-live="polite" aria-label="<?= e($credentialLabel . ' is ' . ($credentialStatus[$modeKey][$credentialKey] ? 'configured' : 'not configured')) ?>"><?= $credentialStatus[$modeKey][$credentialKey] ? '••••••••••••••••' : '—' ?></code>
                                            <button class="paymongo-key-action paymongo-key-view" type="button" <?= $credentialStatus[$modeKey][$credentialKey] ? '' : 'disabled' ?> aria-label="Show <?= e($credentialLabel) ?>" title="Show key"><i class="ti ti-eye" aria-hidden="true"></i></button>
                                            <button class="paymongo-key-action paymongo-key-copy" type="button" <?= $credentialStatus[$modeKey][$credentialKey] ? '' : 'disabled' ?> aria-label="Copy <?= e($credentialLabel) ?>" title="Copy key"><i class="ti ti-copy" aria-hidden="true"></i></button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                <div class="paymongo-credential-note"><i class="ti ti-lock" aria-hidden="true"></i><?= $modeKey === 'test' ? 'Sandbox keys are used for simulation and QA.' : 'Production keys are managed through protected server environment variables.' ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            </div>

            <aside class="paymongo-config-side">
                <section class="paymongo-panel">
                    <div class="paymongo-panel-heading">
                        <div>
                            <span class="paymongo-section-icon"><i class="ti ti-wallet" aria-hidden="true"></i></span>
                            <div><h2>Active Payment Channels</h2><p>Channels available to students for the selected environment.</p></div>
                        </div>
                    </div>
                    <div class="paymongo-channel-row" id="container_qrph">
                        <div class="paymongo-channel-brand">
                            <span class="paymongo-channel-icon"><i class="ti ti-qrcode" aria-hidden="true"></i></span>
                            <div><h3>QR Ph</h3><span>National standard</span></div>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input channel-toggle" type="checkbox" role="switch" id="qrphSwitch" name="channel_qrph" value="1" aria-label="Enable QR Ph">
                        </div>
                        <div id="status_qrph" class="paymongo-channel-status" aria-live="polite"></div>
                    </div>
                    <div class="paymongo-inline-note mt-3"><i class="ti ti-info-circle" aria-hidden="true"></i>QR Ph is the authorized online payment channel for test and live environments.</div>
                    <div id="hidden_channels_msg" class="small text-muted mt-2" style="display:none">Unavailable channels are hidden because they are not active for this PayMongo account.</div>
                </section>

                <section class="paymongo-panel">
                    <div class="paymongo-panel-heading">
                        <div>
                            <span class="paymongo-section-icon"><i class="ti ti-percentage" aria-hidden="true"></i></span>
                            <div><h2>Processing Fee Settings</h2><p>Set who is responsible for the PayMongo processing fee.</p></div>
                        </div>
                        <span class="paymongo-rule-pill">Financial rule</span>
                    </div>
                    <label class="form-label" for="feePolicySelect">Fee responsibility</label>
                    <select class="form-select" id="feePolicySelect" name="fee_policy" required>
                        <option value="pass_to_student" <?= ($settings['fee_policy'] ?? 'pass_to_student') === 'pass_to_student' ? 'selected' : '' ?>>Pass Processing Fee to Student</option>
                        <option value="absorb_by_school" <?= ($settings['fee_policy'] ?? '') === 'absorb_by_school' ? 'selected' : '' ?>>Absorb Processing Fee by Institution</option>
                    </select>
                    <div class="paymongo-fee-summary">
                        <i class="ti ti-receipt" aria-hidden="true"></i>
                        <p><strong id="feePolicySummary">Pass Processing Fee to Student</strong><span>The selected policy is validated and recorded in the payment activity log.</span></p>
                    </div>
                </section>

                <button type="submit" name="save_gateway_settings" class="btn btn-primary paymongo-save-button">
                    <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>Save Gateway Configuration
                </button>
            </aside>
        </div>
    </form>
</div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const paymongoApp = document.getElementById('paymongoAdminApp');
    const gatewaySelect = document.getElementById('gatewayModeSelect');

    // Polling UI Elements
    const btnRefresh = document.getElementById('btnRefreshStatus');
    const iconRefresh = document.getElementById('iconRefreshStatus');
    const btnTestConnection = document.getElementById('btnTestConnection');
    const csrfToken = document.querySelector('input[name="csrf_token"]').value;

    async function requestCredential(row, purpose) {
        const body = new URLSearchParams({
            csrf_token: csrfToken,
            mode: row.dataset.mode,
            credential: row.dataset.credential,
            purpose: purpose
        });
        const response = await fetch(paymongoApp.dataset.credentialUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
            },
            body: body.toString()
        });
        const payload = await response.json();
        if (!response.ok || !payload.ok || typeof payload.value !== 'string') {
            throw new Error('The credential could not be accessed.');
        }
        return payload.value;
    }

    document.querySelectorAll('.paymongo-credential-row[data-configured="1"]').forEach((row) => {
        const output = row.querySelector('.paymongo-mask');
        const viewButton = row.querySelector('.paymongo-key-view');
        const copyButton = row.querySelector('.paymongo-key-copy');
        const maskedValue = '••••••••••••••••';

        viewButton.addEventListener('click', async () => {
            if (row.dataset.visible === '1') {
                output.textContent = maskedValue;
                row.dataset.visible = '0';
                viewButton.innerHTML = '<i class="ti ti-eye" aria-hidden="true"></i>';
                viewButton.setAttribute('aria-label', 'Show key');
                viewButton.title = 'Show key';
                return;
            }
            viewButton.disabled = true;
            try {
                const credentialValue = await requestCredential(row, 'view');
                output.textContent = credentialValue;
                row.dataset.visible = '1';
                viewButton.innerHTML = '<i class="ti ti-eye-off" aria-hidden="true"></i>';
                viewButton.setAttribute('aria-label', 'Hide key');
                viewButton.title = 'Hide key';
            } catch (error) {
                output.textContent = 'Access unavailable';
                window.setTimeout(() => { output.textContent = maskedValue; }, 1800);
            } finally {
                viewButton.disabled = false;
            }
        });

        copyButton.addEventListener('click', async () => {
            copyButton.disabled = true;
            try {
                const credentialValue = await requestCredential(row, 'copy');
                await navigator.clipboard.writeText(credentialValue);
                copyButton.innerHTML = '<i class="ti ti-check" aria-hidden="true"></i>';
                copyButton.title = 'Copied';
            } catch (error) {
                copyButton.innerHTML = '<i class="ti ti-alert-triangle" aria-hidden="true"></i>';
                copyButton.title = 'Copy failed';
            } finally {
                window.setTimeout(() => {
                    copyButton.innerHTML = '<i class="ti ti-copy" aria-hidden="true"></i>';
                    copyButton.title = 'Copy key';
                    copyButton.disabled = false;
                }, 1400);
            }
        });
    });

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
            qrph: <?= isset($settings['test_channel_qrph']) && $settings['test_channel_qrph'] === '0' ? 'false' : 'true' ?>
        },
        live: {
            qrph: <?= isset($settings['live_channel_qrph']) && $settings['live_channel_qrph'] === '0' ? 'false' : 'true' ?>
        }
    };

    const switches = { qrph: document.getElementById('qrphSwitch') };

    function updateFields() {
        const mode = gatewaySelect.value;
        switches.qrph.checked = channels[mode].qrph;
    }

    // Polling Logic
    let pollTimer;
    
    function setCardState(cardObj, statusStr, color, iconClass, subMessage) {
        cardObj.card.dataset.state = color;
        cardObj.text.textContent = statusStr;
        cardObj.icon.className = iconClass;
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
                const lastTestStatus = data.last_test?.status;
                document.getElementById('lastTestEvent').textContent = lastTestStatus
                    ? lastTestStatus.replaceAll('_', ' ')
                    : 'Never Tested';

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
        ['qrph'].forEach(c => {
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
                ['qrph'].forEach(c => {
                    const div = document.getElementById('status_' + c);
                    if (div) div.innerHTML = `<span class="text-danger"><i class="ti ti-alert-triangle me-1"></i>Error checking capability</span>`;
                });
            });
    }

    gatewaySelect.addEventListener('change', function() {
        updateFields();
        fetchChannelsStatus(); // Re-fetch capabilities for the new mode
    });

    const feePolicySelect = document.getElementById('feePolicySelect');
    const feePolicySummary = document.getElementById('feePolicySummary');
    function updateFeePolicySummary() {
        feePolicySummary.textContent = feePolicySelect.options[feePolicySelect.selectedIndex].text;
    }
    feePolicySelect.addEventListener('change', updateFeePolicySummary);
    updateFeePolicySummary();

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

    document.getElementById('copyWebhookUrl')?.addEventListener('click', async function () {
        const webhookUrl = document.getElementById('expectedWebhookUrl')?.textContent?.trim() || '';
        if (webhookUrl === '' || webhookUrl === 'Checking...' || webhookUrl === 'Unavailable') return;
        const original = this.innerHTML;
        try {
            await navigator.clipboard.writeText(webhookUrl);
            this.innerHTML = '<i class="ti ti-check" aria-hidden="true"></i><span>Copied</span>';
        } catch (error) {
            this.innerHTML = '<i class="ti ti-alert-triangle" aria-hidden="true"></i><span>Failed</span>';
        } finally {
            window.setTimeout(() => { this.innerHTML = original; }, 1400);
        }
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
