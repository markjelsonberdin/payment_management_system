<?php
/**
 * SMS 2 - View Statement of Account (SOA)
 * Module: Payment Management
 */
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../includes/authentication.php'; // ETO YUNG NAWAWALA BRO!
require_once __DIR__ . '/../../../../includes/audit.php';
require_once __DIR__ . '/../../database/db_connect.php';

// I-enforce ang login at module access
requireAuth();
requirePaymentPermission('payment.billing');

require_once __DIR__ . '/../../includes/BillingService.php';

$billing_id = $_GET['id'] ?? null;

if (!$billing_id) {
    header("Location: student-billing-invoicing.php");
    exit();
}

try {
    $billingService = new BillingService($pdo);
    $billing = $billingService->getBillingData($billing_id);

    if (!$billing) {
        die("Billing record not found.");
    }
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

$pageTitle    = 'Statement of Account (SOA)';
$activeModule = 'payment';
$activePage   = 'accounting/student-billing-invoicing';
$breadcrumbs  = [
    ['label' => 'Payment Management', 'url' => BASE_URL . '/modules/payment/index.php'],
    ['label' => 'Student Billing & Invoicing', 'url' => 'accounting/student-billing-invoicing.php'],
    ['label' => 'View SOA', 'url' => null],
];

require_once __DIR__ . '/../../../../includes/breadcrumbs.php';
require_once __DIR__ . '/../../../../includes/layout-start.php';
?>

<?php renderBreadcrumbs($breadcrumbs); ?>

<div class="container-fluid py-4">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            
            <!-- Action Toolbar (Print Button) -->
            <div class="d-flex justify-content-between align-items-center mb-3">
                <a href="student-billing-invoicing.php" class="btn btn-light border shadow-sm">
                    <i class="fas fa-arrow-left me-1"></i> Back to List
                </a>
                <button onclick="window.print()" class="btn btn-primary shadow-sm fw-bold">
                    <i class="fas fa-print me-1"></i> Print SOA
                </button>
            </div>

            <!-- Printable SOA Card -->
            <div class="card border-0 shadow-sm rounded-4 p-4 p-md-5 bg-white">
                
                <!-- School Header -->
                <div class="text-center border-bottom pb-4 mb-4">
                    <h3 class="fw-bolder text-primary mb-1">BESTLINK COLLEGE OF THE PHILIPPINES</h3>
                    <p class="text-muted mb-0 small">M Excellency Road, Brgy. Kaligayahan, Novaliches, Quezon City</p>
                    <h5 class="fw-bold text-dark mt-3 text-uppercase">STATEMENT OF ACCOUNT (SOA)</h5>
                </div>

                <!-- Student & Billing Info -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <p class="mb-1 text-muted small uppercase fw-bold">Student Information:</p>
                        <h5 class="fw-bold text-dark mb-1"><?= htmlspecialchars($billing['full_name'] ?? 'N/A') ?></h5>
                        <p class="mb-1 text-muted">Student No: <span class="fw-bold text-dark"><?= htmlspecialchars($billing['student_number']) ?></span></p>
                    </div>
                    <div class="col-md-6 text-md-end mt-3 mt-md-0">
                        <p class="mb-1 text-muted small uppercase fw-bold">Billing Details:</p>
                        <p class="mb-1">Billing No: <span class="fw-bold text-primary">#<?= str_pad($billing['billing_id'], 5, '0', STR_PAD_LEFT) ?></span></p>
                        <p class="mb-1">Type: <span class="fw-bold text-dark"><?= htmlspecialchars($billing['billing_type']) ?></span></p>
                        <p class="mb-1">Term: <span class="fw-bold text-dark"><?= htmlspecialchars($billing['semester']) ?> Semester, A.Y. <?= htmlspecialchars($billing['academic_year']) ?></span></p>
                        <p class="mb-0">Date Generated: <span class="fw-bold text-dark"><?= date('M d, Y h:i A', strtotime($billing['created_at'])) ?></span></p>
                    </div>
                </div>

                <!-- Fee Breakdown Table -->
                <div class="table-responsive mb-4">
                    <table class="table align-middle border">
                        <thead class="table-light text-uppercase" style="font-size: 0.75rem;">
                            <tr>
                                <th class="py-3 ps-3">Fee Description</th>
                                <th class="py-3 text-end pe-3">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($billing['breakdown'] as $category => $items): ?>
                                <tr class="bg-light">
                                    <td colspan="2" class="ps-3 fw-bolder text-secondary text-uppercase" style="font-size: 0.8rem;">
                                        <?= htmlspecialchars($category) ?>
                                    </td>
                                </tr>
                                <?php foreach ($items as $item): ?>
                                    <tr>
                                        <td class="ps-4 fw-bold text-dark"><?= htmlspecialchars($item['fee_name']) ?></td>
                                        <td class="text-end pe-3 fw-bold text-success">₱ <?= number_format($item['amount'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <td class="text-end fw-bold py-3">Gross Assessment:</td>
                                <td class="text-end pe-3 fw-bolder fs-5 text-dark">₱ <?= number_format($billing['total_amount'], 2) ?></td>
                            </tr>
                            <?php if ($billing['discount_amount'] > 0): ?>
                            <tr>
                                <td class="text-end fw-bold py-2 text-primary">Less Discount:</td>
                                <td class="text-end pe-3 fw-bolder fs-5 text-primary">- ₱ <?= number_format($billing['discount_amount'], 2) ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <td class="text-end fw-bold py-2">Remaining Balance:</td>
                                <td class="text-end pe-3 fw-bolder fs-5 text-danger">₱ <?= number_format($billing['remaining_balance'], 2) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- Status Banner -->
                <div class="alert alert-<?= $billing['billing_status'] === 'Paid' ? 'success' : 'warning' ?> text-center fw-bold py-2 mb-0">
                    Status: <?= strtoupper($billing['billing_status']) ?>
                </div>

            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../../../../includes/layout-end.php'; ?>