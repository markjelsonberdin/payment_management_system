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
$qrSupportedApps = ['GCash', 'Maya', 'BPI', 'BDO', 'GoTyme', 'MariBank', 'Visa'];

require_once ROOT_PATH . '/includes/layout-start.php';
?>

<div class="container-fluid py-4 d-flex align-items-center justify-content-center" style="min-height: 70vh;">
    <div class="card border-0 shadow-lg rounded-4 p-5 text-center" style="max-width: 450px; width: 100%;">
        <h4 class="fw-bolder text-primary mb-4"><i class="ti ti-qrcode me-2"></i>Scan to Pay</h4>

        <div class="mb-3">
            <div class="small fw-bold text-muted text-uppercase mb-2">Supported QRPh apps</div>
            <div class="d-flex flex-wrap justify-content-center gap-2">
                <?php foreach ($qrSupportedApps as $app): ?>
                    <span class="badge rounded-pill text-bg-light border px-2 py-1"><?= htmlspecialchars($app) ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        
        <div class="bg-white p-3 border rounded-4 shadow-sm d-inline-block mb-4 mx-auto">
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
        
        <div class="alert alert-warning py-3 mb-4 shadow-sm rounded-3 d-flex align-items-center justify-content-center" id="qrStatusAlert">
            <i class="ti ti-loader fa-lg me-3" id="qrStatusSpinner"></i> 
            <span id="qrStatusText" class="fw-bold fs-6">Waiting for payment confirmation...</span>
        </div>

        <div class="text-muted small mb-3">QR expires in <strong id="qrCountdown">10:00</strong></div>
        
        <a href="payment-history.php" class="btn btn-outline-secondary w-100 py-2 fw-bold shadow-sm rounded-3">
            <i class="ti ti-arrow-left me-2"></i>Back to History
        </a>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const paymentIntentId = "<?= addslashes($paymentIntentId) ?>";
        const expiresAt = new Date("<?= addslashes(date('c', strtotime($expiresAt))) ?>").getTime();
        const countdown = document.getElementById('qrCountdown');
        const countdownInterval = setInterval(() => {
            const remaining = Math.max(0, expiresAt - Date.now());
            const totalSeconds = Math.floor(remaining / 1000);
            const minutes = String(Math.floor(totalSeconds / 60)).padStart(2, '0');
            const seconds = String(totalSeconds % 60).padStart(2, '0');
            countdown.textContent = minutes + ':' + seconds;
            if (remaining <= 0) {
                clearInterval(countdownInterval);
                clearInterval(pollingInterval);
                document.getElementById('qrStatusText').textContent = 'QR expired. Please start a new payment.';
                document.getElementById('qrStatusAlert').classList.replace('alert-warning', 'alert-danger');
            }
        }, 1000);
        const pollingInterval = setInterval(() => {
            fetch("<?= BASE_URL ?>/modules/student-portal/api/check-payment-status.php?payment_intent_id=" + paymentIntentId)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        if (data.status === 'Verified') {
                            clearInterval(pollingInterval);
                            document.getElementById('qrStatusText').innerHTML = "Payment Successful!";
                            const alertBox = document.getElementById('qrStatusAlert');
                            alertBox.classList.remove('alert-warning');
                            alertBox.classList.add('alert-success');
                            document.getElementById('qrStatusSpinner').className = "ti ti-circle-check fa-lg me-3";
                            setTimeout(() => {
                                window.location.href = 'payment-history.php';
                            }, 2000);
                        } else if (data.status === 'Failed' || data.status === 'Rejected' || data.status === 'Expired') {
                            clearInterval(pollingInterval);
                            clearInterval(countdownInterval);
                            document.getElementById('qrStatusText').innerHTML = "Payment Failed or Expired.";
                            const alertBox = document.getElementById('qrStatusAlert');
                            alertBox.classList.remove('alert-warning');
                            alertBox.classList.add('alert-danger');
                            document.getElementById('qrStatusSpinner').className = "ti ti-circle-x fa-lg me-3";
                        }
                    }
                })
                .catch(err => console.error(err));
        }, 4000);
    });
</script>

<?php require_once ROOT_PATH . '/includes/layout-end.php'; ?>
