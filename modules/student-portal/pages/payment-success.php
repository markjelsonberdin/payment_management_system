<?php
/**
 * SMS 2 - Student Portal: Payment Success Redirect
 * This is the page where PayMongo redirects the user after a successful checkout.
 */

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once ROOT_PATH . '/includes/breadcrumbs.php';

$pageTitle = 'Payment Processing';
$activeModule = 'student_portal';
$activePage = 'account-balance';
$breadcrumbs = [
    ['label' => 'Student Portal', 'url' => BASE_URL . '/modules/student-portal/pages/my-profile.php'],
    ['label' => 'Account Balance', 'url' => BASE_URL . '/modules/student-portal/pages/account-balance.php'],
    ['label' => 'Payment Status', 'url' => null],
];

// 1. Secure Payment Lookup
$studentId = $_SESSION['student_id'] ?? null;
$referenceNumber = $_GET['ref'] ?? null;
$paymentData = null;
$accessDenied = false;

if ($studentId && $referenceNumber) {
    try {
        $pdo = studentPortalDb();
        if ($pdo) {
            // Secure query: Only fetch if it belongs to the logged-in student
            $stmt = $pdo->prepare("
                SELECT p.*, b.remaining_balance 
                FROM payment_db.payments p
                JOIN payment_db.students s ON p.student_id = s.student_id
                LEFT JOIN payment_db.billing b ON p.billing_id = b.billing_id
                WHERE p.reference_number = :ref AND s.student_number = :sid
            ");
            $stmt->execute([
                ':ref' => $referenceNumber,
                ':sid' => $studentId
            ]);
            
            $paymentData = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$paymentData) {
                // Payment not found or does not belong to student
                $accessDenied = true;
            }
        }
    } catch (Exception $e) {
        $accessDenied = true;
    }
} else {
    $accessDenied = true;
}

require_once ROOT_PATH . '/includes/layout-start.php';
?>

<!-- Render Breadcrumbs -->
<?php renderBreadcrumbs($breadcrumbs); ?>

<div class="student-portal">
    <div class="card border-0 shadow-sm rounded-4 mt-4">
        <div class="card-body p-5 text-center">
            
            <?php if ($accessDenied): ?>
                <div class="mb-4">
                    <div class="d-inline-flex align-items-center justify-content-center bg-danger text-white rounded-circle" style="width: 80px; height: 80px;">
                        <i class="fas fa-times fs-1"></i>
                    </div>
                </div>
                <h2 class="fw-bolder text-dark mb-3">Access Denied</h2>
                <p class="text-muted mb-4 lead mx-auto" style="max-width: 600px;">
                    We could not verify the payment reference or you do not have permission to view it.
                </p>
                <div>
                    <a href="account-balance.php" class="btn btn-primary px-5 py-2 rounded-3 shadow-sm fw-bold">
                        <i class="fas fa-arrow-left me-2"></i>Return to Account Balance
                    </a>
                </div>
            <?php else: ?>
                <div class="mb-4">
                    <div class="d-inline-flex align-items-center justify-content-center bg-success text-white rounded-circle" style="width: 80px; height: 80px;">
                        <i class="fas fa-check fs-1"></i>
                    </div>
                </div>
                
                <h2 class="fw-bolder text-dark mb-3">Payment Authorized</h2>
                
                <p class="text-muted mb-4 lead mx-auto" style="max-width: 600px;">
                    Your payment has been successfully authorized. If the status is still Pending, please wait a few moments for the gateway to confirm.
                </p>

                <div class="mx-auto text-start" style="max-width: 450px;">
                    <div class="card bg-light border-0 rounded-3 p-4 mb-4 shadow-sm">
                        
                        <div class="d-flex justify-content-between mb-3 border-bottom pb-2">
                            <span class="text-muted fw-bold">Status</span>
                            <?php 
                                $statusBadge = 'bg-warning text-dark';
                                if ($paymentData['payment_status'] === 'Verified') $statusBadge = 'bg-success text-white';
                                elseif ($paymentData['payment_status'] === 'Failed') $statusBadge = 'bg-danger text-white';
                            ?>
                            <span class="badge <?= $statusBadge ?> fs-6"><?= htmlspecialchars($paymentData['payment_status']) ?></span>
                        </div>

                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Reference Number</span>
                            <span class="fw-bolder text-dark"><?= htmlspecialchars($paymentData['reference_number']) ?></span>
                        </div>
                        
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Payment Channel</span>
                            <span class="fw-bolder text-dark"><?= htmlspecialchars($paymentData['payment_channel']) ?></span>
                        </div>

                        <div class="d-flex justify-content-between mb-2 mt-3 pt-2 border-top">
                            <span class="text-muted">Amount Applied to Balance</span>
                            <span class="fw-bold text-dark">₱ <?= number_format($paymentData['amount'], 2) ?></span>
                        </div>
                        
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Processing Fee</span>
                            <span class="fw-bold text-dark">₱ <?= number_format((float)$paymentData['processing_fee'], 2) ?></span>
                        </div>

                        <div class="d-flex justify-content-between mb-2 mt-2 pt-2 border-top bg-white p-2 rounded">
                            <span class="text-dark fw-bolder">Checkout Total</span>
                            <span class="fw-bolder text-primary fs-5">₱ <?= number_format((float)$paymentData['checkout_total'], 2) ?></span>
                        </div>
                        
                        <?php if ($paymentData['payment_status'] === 'Verified'): ?>
                        <div class="d-flex justify-content-between mt-3 mb-1">
                            <span class="text-muted small">Updated Remaining Balance</span>
                            <span class="fw-bold text-success small">₱ <?= number_format((float)$paymentData['remaining_balance'], 2) ?></span>
                        </div>
                        <?php endif; ?>

                    </div>
                </div>

                <div>
                    <a href="payment-history.php" class="btn btn-outline-primary px-4 py-2 rounded-3 shadow-sm fw-bold me-2">
                        <i class="fas fa-list-alt me-2"></i>View History
                    </a>
                    <a href="account-balance.php" class="btn btn-primary px-4 py-2 rounded-3 shadow-sm fw-bold">
                        <i class="fas fa-arrow-left me-2"></i>Return to Balance
                    </a>
                </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<?php require_once ROOT_PATH . '/includes/layout-end.php'; ?>
