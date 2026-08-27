<?php
/**
 * SMS 2 - Resume QR Payment Page
 * Displays the QR Ph code for resumption
 */
require_once __DIR__ . '/../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once __DIR__ . '/../../payment/config/paymongo.php';
require_once __DIR__ . '/../../payment/includes/PayMongoService.php';
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

require_once ROOT_PATH . '/includes/layout-start.php';
?>

<div class="container-fluid py-4 d-flex align-items-center justify-content-center" style="min-height: 70vh;">
    <div class="card border-0 shadow-lg rounded-4 p-5 text-center" style="max-width: 450px; width: 100%;">
        <h4 class="fw-bolder text-primary mb-4"><i class="fas fa-qrcode me-2"></i>Scan to Pay</h4>
        
        <div class="bg-white p-3 border rounded-4 shadow-sm d-inline-block mb-4 mx-auto">
            <img src="<?= htmlspecialchars($qrImage) ?>" alt="QR Code" style="width: 250px; height: 250px; object-fit: contain;">
        </div>
        
        <p class="text-muted small mb-3">Scan this QR code using GCash, Maya, or any supported QR Ph banking application.</p>
        
        <div class="fw-bold text-dark fs-3 mb-4">
            <small class="text-muted fs-6 align-top">PHP</small> 
            <?= number_format((float)$payment['amount'] + (float)$payment['processing_fee'], 2) ?>
        </div>
        
        <div class="alert alert-warning py-3 mb-4 shadow-sm rounded-3 d-flex align-items-center justify-content-center" id="qrStatusAlert">
            <i class="fas fa-spinner fa-spin fa-lg me-3" id="qrStatusSpinner"></i> 
            <span id="qrStatusText" class="fw-bold fs-6">Waiting for payment confirmation...</span>
        </div>
        
        <a href="payment-history.php" class="btn btn-outline-secondary w-100 py-2 fw-bold shadow-sm rounded-3">
            <i class="fas fa-arrow-left me-2"></i>Back to History
        </a>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const paymentIntentId = "<?= addslashes($paymentIntentId) ?>";
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
                            document.getElementById('qrStatusSpinner').className = "fas fa-check-circle fa-lg me-3";
                            setTimeout(() => {
                                window.location.href = 'payment-history.php';
                            }, 2000);
                        } else if (data.status === 'Failed' || data.status === 'Rejected') {
                            clearInterval(pollingInterval);
                            document.getElementById('qrStatusText').innerHTML = "Payment Failed or Expired.";
                            const alertBox = document.getElementById('qrStatusAlert');
                            alertBox.classList.remove('alert-warning');
                            alertBox.classList.add('alert-danger');
                            document.getElementById('qrStatusSpinner').className = "fas fa-times-circle fa-lg me-3";
                        }
                    }
                })
                .catch(err => console.error(err));
        }, 4000);
    });
</script>

<?php require_once ROOT_PATH . '/includes/layout-end.php'; ?>
