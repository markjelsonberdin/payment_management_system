<?php
/**
 * SMS 2 - Resume QR Payment Page
 * Displays the QR Ph code for resumption
 */
require_once __DIR__ . '/../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once __DIR__ . '/../../payment/config/paymongo.php';
require_once __DIR__ . '/../../payment/includes/paymongo/PayMongoService.php';
require_once __DIR__ . '/../../payment/database/db_connect.php';

requireAuth();
if (getCurrentUserRoleKey() !== 'student') {
    die("Unauthorized access.");
}

$studentUserId = $_SESSION['user_id'];
$paymentId = $_GET['id'] ?? null;

if (!$paymentId) {
    die("Invalid request.");
}

global $pdo;
$stmt = $pdo->prepare("
    SELECT p.*, s.user_id 
    FROM payments p
    JOIN students s ON p.student_id = s.student_id
    WHERE p.payment_id = :payment_id
");
$stmt->execute([':payment_id' => $paymentId]);
$payment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$payment || $payment['user_id'] != $studentUserId || $payment['payment_channel'] !== 'QRPh') {
    die("Invalid payment record.");
}

if ($payment['payment_status'] !== 'Pending') {
    die("This QR payment is no longer active.");
}

$expiresAt = !empty($payment['expires_at'])
    ? $payment['expires_at']
    : (!empty($payment['created_at']) ? date('Y-m-d H:i:s', strtotime($payment['created_at']) + 600) : null);

$stmtClock = $pdo->prepare('SELECT UNIX_TIMESTAMP(:expires_at) * 1000 AS expires_at_ms, UNIX_TIMESTAMP() * 1000 AS server_now_ms');
$stmtClock->execute([':expires_at' => $expiresAt]);
$clockData = $stmtClock->fetch(PDO::FETCH_ASSOC);

if ($expiresAt === null || strtotime($expiresAt) <= time()) {
    die("This QR payment has expired. Please start a new payment attempt.");
}

$paymentIntentId = $payment['payment_intent_id'];
$payMongo = new PayMongoService();
$intentData = $payMongo->getPaymentIntent($paymentIntentId);

$qrImage = $intentData['data']['attributes']['next_action']['code']['image_url'] ?? null;

if (!$qrImage) {
    die("Error: Could not retrieve the QR Code image from PayMongo.");
}

$pageTitle    = 'Resume QR Payment';
$activeModule = 'student_portal'; 
$activePage   = 'payment-history';
$breadcrumbs  = [
    ['label' => 'Payment History', 'url' => BASE_URL . '/modules/student-portal/pages/payment-history.php'],
    ['label' => 'Resume QR Payment', 'url' => null]
];
$qrSupportedApps = [
    ['name' => 'GCash', 'logo' => 'gcash.jpg'],
    ['name' => 'Maya', 'logo' => 'maya.jpg'],
    ['name' => 'BPI', 'logo' => 'bpi.jpg'],
    ['name' => 'BDO', 'logo' => 'bdo.jpg'],
    ['name' => 'GoTyme', 'logo' => 'gotyme.jpg'],
    ['name' => 'MariBank', 'logo' => 'maribank.jpg'],
];

require_once ROOT_PATH . '/includes/layout-start.php';
?>

<style>
    .resume-qr-card { max-width: 470px; width: 100%; }
    .resume-qr-card .qr-app-grid {
        display: grid;
        display: flex;
        flex-wrap: wrap;
        justify-content: center;
        gap: .55rem;
    }
    .resume-qr-card .qr-app-card {
        min-width: 0;
        width: 56px;
        height: 28px;
        padding: .15rem;
        background: transparent;
        border: 0;
    }
    .resume-qr-card .qr-app-card img {
        display: block;
        width: 100%;
        height: 24px;
        object-fit: contain;
        border-radius: .3rem;
    }
    .resume-qr-card .qr-code-frame {
        position: relative;
        padding: .75rem;
        background: #fff;
        border: 1px solid rgba(148, 163, 184, .35);
        border-radius: 1rem;
        box-shadow: 0 10px 28px rgba(15, 23, 42, .12);
    }
    .resume-qr-card .qr-countdown-pill {
        position: absolute; z-index: 2; top: -17px; left: 50%;
        transform: translateX(-50%); padding: .35rem .7rem;
        color: #fff; background: #22a95a; border: 3px solid #fff;
        border-radius: 999px; font-weight: 800;
    }
    .resume-qr-card .qr-payment-status {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: .65rem;
        min-height: 48px;
        padding: .7rem 1rem;
        border-radius: .75rem;
        font-weight: 700;
    }
    .resume-qr-card .qr-payment-status.is-waiting { color: #854d0e; background: #fef3c7; border: 1px solid #fde68a; }
    .resume-qr-card .qr-payment-status.is-success { color: #166534; background: #dcfce7; border: 1px solid #bbf7d0; }
    .resume-qr-card .qr-payment-status.is-failed { color: #991b1b; background: #fee2e2; border: 1px solid #fecaca; }
    .resume-qr-card .qr-payment-status.is-expired { color: #475569; background: #e2e8f0; border: 1px solid #cbd5e1; }
    .resume-qr-card .qr-status-spin { animation: qr-status-spin 1s linear infinite; }
    @keyframes qr-status-spin { to { transform: rotate(360deg); } }
    @media (max-width: 390px) {
        .resume-qr-card { padding: 1.5rem !important; }
        .resume-qr-card .qr-app-grid { gap: .35rem; }
        .resume-qr-card .qr-app-card { width: 48px; height: 25px; }
        .resume-qr-card .qr-app-card img { height: 21px; }
    }
</style>

<div class="container-fluid py-4 d-flex align-items-center justify-content-center" style="min-height: 70vh;">
    <div class="card resume-qr-card border-0 shadow-lg rounded-4 p-5 text-center">
        <h4 class="fw-bolder text-primary mb-4"><i class="ti ti-scan me-2"></i>Scan QR to Pay</h4>

        <div class="mb-3">
            <div class="small fw-bold text-muted text-uppercase mb-2">Scan QR Ph code to pay</div>
            <div class="qr-app-grid" aria-label="Supported QRPh payment apps">
                <?php foreach ($qrSupportedApps as $app): ?>
                    <div class="qr-app-card"
                         title="<?= htmlspecialchars($app['name'], ENT_QUOTES, 'UTF-8') ?>">
                        <img src="<?= BASE_URL ?>/modules/payment/assets/images/<?= rawurlencode($app['logo']) ?>"
                             alt="<?= htmlspecialchars($app['name'], ENT_QUOTES, 'UTF-8') ?>"
                             loading="lazy">
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <div class="qr-code-frame d-inline-block mb-4 mx-auto">
            <div class="qr-countdown-pill" id="qrCountdown">10:00</div>
            <img src="<?= htmlspecialchars($qrImage) ?>" alt="QR Code" style="width: 250px; height: 250px; object-fit: contain;">
        </div>

        <a href="<?= htmlspecialchars($qrImage) ?>" download="sms2-qr-<?= (int)$payment['payment_id'] ?>.png" class="btn btn-primary w-100 py-2 fw-bold shadow-sm rounded-3 mb-3">
            <i class="ti ti-download me-2"></i>Download QR Code
        </a>
        
        <p class="text-muted small mb-3">Scan this QR code using GCash, Maya, or any supported QR Ph banking application.</p>
        
        <div class="fw-bold text-dark fs-3 mb-4">
            <small class="text-muted fs-6 align-top">PHP</small> 
            <?= number_format((float)$payment['amount'] + (float)$payment['processing_fee'], 2) ?>
        </div>
        
        <div class="qr-payment-status is-waiting mb-3" id="qrStatusAlert" role="status" aria-live="polite">
            <i class="ti ti-loader-2 qr-status-spin" id="qrStatusSpinner" aria-hidden="true"></i>
            <span id="qrStatusText">Waiting for payment confirmation...</span>
        </div>

        <div class="text-muted small mb-4"><i class="ti ti-clock me-1"></i>This QR has a 10-minute payment window.</div>
        
        <a href="payment-history.php" class="btn btn-outline-secondary w-100 py-2 fw-bold shadow-sm rounded-3">
            <i class="ti ti-arrow-left me-2"></i>Back to History
        </a>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        function setQrStatus(state, message) {
            const alertBox = document.getElementById('qrStatusAlert');
            const icon = document.getElementById('qrStatusSpinner');
            const states = {
                waiting: { box: 'is-waiting', icon: 'ti ti-loader-2 qr-status-spin' },
                success: { box: 'is-success', icon: 'ti ti-circle-check' },
                failed: { box: 'is-failed', icon: 'ti ti-circle-x' },
                expired: { box: 'is-expired', icon: 'ti ti-clock-x' }
            };
            const selected = states[state] || states.waiting;
            alertBox.className = 'qr-payment-status ' + selected.box + ' mb-3';
            icon.className = selected.icon;
            document.getElementById('qrStatusText').textContent = message;
        }

        const paymentIntentId = "<?= addslashes($paymentIntentId) ?>";
        const expiresAt = <?= (int) ($clockData['expires_at_ms'] ?? 0) ?>;
        const serverOffsetMs = <?= (int) ($clockData['server_now_ms'] ?? 0) ?> - Date.now();
        const countdown = document.getElementById('qrCountdown');
        const countdownInterval = setInterval(() => {
            const remaining = Math.max(0, expiresAt - (Date.now() + serverOffsetMs));
            const totalSeconds = Math.floor(remaining / 1000);
            const minutes = String(Math.floor(totalSeconds / 60)).padStart(2, '0');
            const seconds = String(totalSeconds % 60).padStart(2, '0');
            countdown.textContent = minutes + ':' + seconds;
            if (remaining <= 0) {
                clearInterval(countdownInterval);
                checkStatus(true);
            }
        }, 1000);
        let pollingInterval = null;
        const checkStatus = (isFinalCheck = false) => {
            fetch("<?= BASE_URL ?>/modules/student-portal/api/check-payment-status.php?payment_intent_id=" + paymentIntentId)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        if (data.status === 'Verified') {
                            clearInterval(pollingInterval);
                            clearInterval(countdownInterval);
                            setQrStatus('success', 'Payment successful!');
                            setTimeout(() => {
                                window.location.href = 'payment-history.php';
                            }, 2000);
                        } else if (data.status === 'Failed' || data.status === 'Rejected' || data.status === 'Expired') {
                            clearInterval(pollingInterval);
                            clearInterval(countdownInterval);
                            setQrStatus(data.status === 'Expired' ? 'expired' : 'failed', data.status === 'Expired' ? 'QR code expired.' : 'Payment failed.');
                        }
                    } else if (isFinalCheck) {
                        setQrStatus('expired', 'QR code expired.');
                    }
                })
                .catch(err => console.error(err));
        };
        checkStatus();
        pollingInterval = setInterval(checkStatus, 4000);
    });
</script>

<?php require_once ROOT_PATH . '/includes/layout-end.php'; ?>
