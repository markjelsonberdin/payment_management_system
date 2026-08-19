<?php
/**
 * SMS 2 - Collection Reporting & Analytics
 * Module: Payment Management
 */
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../includes/authentication.php';
require_once __DIR__ . '/../../../../includes/audit.php';
require_once __DIR__ . '/../../database/db_connect.php';
require_once __DIR__ . '/../../includes/RegistrarStudentClient.php';
require_once __DIR__ . '/../../includes/BillingService.php';

// I-enforce ang login at module access
requireAuth();
requirePaymentPermission('payment.billing');

// ==========================================
// CREATE BILLING LOGIC (POST)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_billing'])) {
    requireCsrf();
    
    $student_number = trim($_POST['student_number']);
    $billing_type   = $_POST['billing_type'];
    $academic_year  = $_POST['academic_year'];
    $semester       = $_POST['semester'];
    $selected_fees  = $_POST['selected_fees'] ?? []; 
    $generated_by   = getCurrentUserId(); 

    $redirect_url = "student-billing-invoicing.php";

    if (empty($selected_fees)) {
        header("Location: $redirect_url?error=" . urlencode("Please select at least one fee."));
        exit();
    }

    try {
        // MICROSERVICE CONSUMER: Fetch/Sync Student from Registrar API
        $client = new RegistrarStudentClient($pdo);
        $student = $client->getAndSyncStudent($student_number);
        
        if (!$student) {
            throw new Exception("Student Number not found in Registrar records.");
        }
        
        $student_id = $student['student_id'];

        $stmtCheck = $pdo->prepare("
            SELECT billing_id FROM payment_db.billing 
            WHERE student_id = :student_id 
            AND billing_type = :type 
            AND academic_year = :ay 
            AND semester = :sem
        ");
        $stmtCheck->execute([
            ':student_id' => $student_id,
            ':type'       => $billing_type,
            ':ay'         => $academic_year,
            ':sem'        => $semester
        ]);

        if ($stmtCheck->fetch()) {
            throw new Exception("Duplicate Error: This student already has an existing $billing_type for $semester Semester, A.Y. $academic_year.");
        }

        // ==========================================
        // CALL BILLING SERVICE
        // ==========================================
        // Tinanggal na natin ang manual $pdo->beginTransaction() dito dahil 
        // mismong BillingService na ang naghahandle ng database locking.
        $billingService = new BillingService($pdo);
        $discountAmount = 0.00; // Will be implemented in the next phase
        
        $billing_id = $billingService->generateBilling(
            $student_id, 
            $academic_year, 
            $semester, 
            $billing_type, 
            $selected_fees, 
            $discountAmount, 
            $generated_by
        );

        logActivity('generate_billing', "Generated $billing_type SOA for Student: $student_number", 'payment');

        header("Location: $redirect_url?success=1");
        exit();

    } catch (Exception $e) {
        // Tinanggal na natin ang $pdo->rollBack() dahil hawak na ito ng BillingService
        header("Location: $redirect_url?error=" . urlencode($e->getMessage()));
        exit();
    }
}

// ==========================================
// 1. KUNIN MGA BILLING RECORDS & ACTIVE FEES
// ==========================================
$groupedActiveFees = []; // Array para sa grouping sa modal

try {
    // Kunin ang Summary
    $summaryStmt = $pdo->query("
        SELECT 
            SUM(remaining_balance) as total_receivables,
            SUM(CASE WHEN billing_status != 'Paid' THEN 1 ELSE 0 END) as unpaid_count,
            SUM(CASE WHEN billing_status = 'Paid' THEN 1 ELSE 0 END) as paid_count
        FROM payment_db.billing
    ");
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

    $totalReceivables = $summary['total_receivables'] ?? 0;
    $unpaidCount = $summary['unpaid_count'] ?? 0;
    $paidCount = $summary['paid_count'] ?? 0;

    // Kunin ang Billing List (Fast Local Query gamit ang payment_db.students reference)
    $stmt = $pdo->query("
        SELECT b.billing_id, b.billing_type, b.academic_year, b.semester, 
               b.total_amount, b.remaining_balance, b.billing_status, b.created_at,
               s.student_number, s.full_name
        FROM payment_db.billing b
        JOIN payment_db.students s ON b.student_id = s.student_id
        ORDER BY b.created_at DESC
    ");
    $billingList = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Kunin ang Active Fees kasama ang Category Details
    $stmtFees = $pdo->query("
        SELECT f.fee_id, f.fee_name, f.default_amount, f.is_required, c.category_name
        FROM payment_db.fees f
        JOIN payment_db.fee_categories c ON f.category_id = c.category_id
        WHERE f.status = 'Active' 
        ORDER BY c.priority_order ASC, f.fee_name ASC
    ");
    $activeFees = $stmtFees->fetchAll(PDO::FETCH_ASSOC);

    // Grouping logic para sa Modal
    foreach ($activeFees as $fee) {
        $cat = $fee['category_name'];
        if (!isset($groupedActiveFees[$cat])) {
            $groupedActiveFees[$cat] = [];
        }
        $groupedActiveFees[$cat][] = $fee;
    }

} catch (PDOException $e) {
    $billingList = []; $activeFees = []; $groupedActiveFees = []; $totalReceivables = 0; $unpaidCount = 0; $paidCount = 0;
    $dbError = $e->getMessage();
}

$pageTitle    = 'Student Billing & Invoicing';
$activeModule = 'payment';
$activePage   = 'student-billing-invoicing';
$breadcrumbs  = [
    ['label' => 'Payment Management', 'url' => BASE_URL . '/modules/payment/index.php'],
    ['label' => 'Student Billing & Invoicing', 'url' => null],
];

require_once __DIR__ . '/../../../../includes/breadcrumbs.php';
require_once __DIR__ . '/../../../../includes/layout-start.php';
?>

<?php renderBreadcrumbs($breadcrumbs); ?>

<div class="container-fluid py-4">
    <!-- Page Header & Actions -->
    <div class="row mb-4 align-items-center">
        <div class="col-md-6">
            <h2 class="mb-1 fw-bolder"><i class="fas fa-file-invoice-dollar text-primary me-2"></i>Billing & Invoicing</h2>
            <p class="text-muted mb-0 fs-6">Generate and manage student statement of accounts (SOA).</p>
        </div>
        <div class="col-md-6 text-md-end mt-3 mt-md-0">
            <div class="d-flex justify-content-md-end gap-2">
                <div class="input-group w-auto shadow-sm">
                    <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                    <input type="text" class="form-control border-start-0 ps-0 table-live-search-input" data-table-target="#billingTable" placeholder="Search student no...">
                </div>
                <?php if (getCurrentUserRoleKey() === 'admin' || getCurrentUserRoleKey() === 'finance'): ?>
                    <button type="button" class="btn btn-primary shadow-sm fw-bold px-4" data-bs-toggle="modal" data-bs-target="#generateBillingModal">
                        <i class="fas fa-file-invoice me-1"></i> Generate Billing
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Alerts -->
    <?php if (isset($_GET['success']) && $_GET['success'] == 1): ?>
        <div class="alert alert-success shadow-sm"><i class="fas fa-check-circle me-2"></i> Billing generated successfully!</div>
    <?php endif; ?>
    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger shadow-sm"><i class="fas fa-exclamation-triangle me-2"></i> <?= htmlspecialchars($_GET['error']) ?></div>
    <?php endif; ?>
    <?php if (isset($dbError)): ?>
        <div class="alert alert-danger shadow-sm"><i class="fas fa-exclamation-triangle me-2"></i> Database Error: <?= htmlspecialchars($dbError) ?></div>
    <?php endif; ?>

    <!-- Overview Cards -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 border-start border-primary border-4">
                <div class="card-body">
                    <p class="text-muted fw-bold mb-1 text-uppercase" style="font-size: 0.8rem;">Total Receivables</p>
                    <h3 class="fw-bolder mb-0 text-dark">₱ <?= number_format($totalReceivables, 2) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 border-start border-warning border-4">
                <div class="card-body">
                    <p class="text-muted fw-bold mb-1 text-uppercase" style="font-size: 0.8rem;">Students w/ Balance</p>
                    <h3 class="fw-bolder mb-0 text-dark"><?= number_format($unpaidCount) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 border-start border-success border-4">
                <div class="card-body">
                    <p class="text-muted fw-bold mb-1 text-uppercase" style="font-size: 0.8rem;">Fully Paid</p>
                    <h3 class="fw-bolder mb-0 text-dark"><?= number_format($paidCount) ?></h3>
                </div>
            </div>
        </div>
    </div>

    <!-- Billing Records Table -->
    <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 text-nowrap" id="billingTable">
                    <thead class="bg-light text-uppercase text-secondary" style="font-size: 0.75rem; letter-spacing: 0.5px;">
                        <tr>
                            <th class="ps-4 py-3">Billing No.</th>
                            <th class="py-3">Student Details</th>
                            <th class="py-3">Term</th>
                            <th class="py-3">Total Amount</th>
                            <th class="py-3">Balance</th>
                            <th class="py-3 text-center">Status</th>
                            <th class="text-end pe-4 py-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="border-top-0">
                        <?php if (count($billingList) > 0): ?>
                            <?php foreach ($billingList as $bill): ?>
                                <tr>
                                    <td class="ps-4 fw-bold text-primary">#<?= str_pad($bill['billing_id'], 5, '0', STR_PAD_LEFT) ?></td>
                                    <td>
                                        <div class="fw-bold text-dark"><?= htmlspecialchars($bill['full_name']) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($bill['student_number']) ?></small>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-dark"><?= htmlspecialchars($bill['semester']) ?> Semester</div>
                                        <small class="text-muted">A.Y. <?= htmlspecialchars($bill['academic_year']) ?> &bull; <span class="text-primary fw-bold"><?= htmlspecialchars($bill['billing_type']) ?></span></small>
                                    </td>
                                    <td class="fw-bold text-dark">₱ <?= number_format($bill['total_amount'], 2) ?></td>
                                    <td class="fw-bold text-danger">₱ <?= number_format($bill['remaining_balance'], 2) ?></td>
                                    <td class="text-center">
                                        <?php 
                                        $statusClass = match($bill['billing_status']) {
                                            'Paid' => 'bg-success',
                                            'Partial' => 'bg-warning text-dark',
                                            default => 'bg-danger text-white'
                                        };
                                        ?>
                                        <span class="badge rounded-pill <?= $statusClass ?> px-3 py-2">
                                            <?= htmlspecialchars($bill['billing_status']) ?>
                                        </span>
                                    </td>
                                    <td class="text-end pe-4">
                                        <a href="view-soa.php?id=<?= $bill['billing_id'] ?>" class="btn btn-sm btn-light text-primary shadow-sm" title="View SOA">
                                            <i class="fas fa-eye me-1"></i> View
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">
                                    <i class="fas fa-file-invoice fs-1 mb-3 d-block text-light"></i>
                                    <h5 class="fw-bold text-secondary">No billing records found.</h5>
                                    <p class="mb-0">Click "Generate Billing" to create a new statement of account.</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ========================================== -->
<!-- GENERATE BILLING MODAL -->
<!-- ========================================== -->
<div class="modal fade" id="generateBillingModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white border-0 pb-3">
                <h5 class="modal-title fw-bold"><i class="fas fa-file-invoice me-2"></i>Generate New Billing</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
           <form id="billingForm" action="" method="POST">
               <?= csrfField(); ?>
                <div class="modal-body bg-light">
                    
                    <div class="row mb-4">
                       <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold text-dark">Student Number <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-white"><i class="fas fa-user-graduate text-muted"></i></span>
                            <input type="text" class="form-control border-start-0" id="studentSearchInput" name="student_number" placeholder="e.g. S230106713" required autocomplete="off">
                        </div>
                        <small id="studentNameHint" class="mt-1 d-block text-muted" style="min-height: 20px;"></small>
                    </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold text-dark">Billing Type <span class="text-danger">*</span></label>
                            <select class="form-select" name="billing_type" required>
                                <option value="Enrollment">Enrollment Assessment</option>
                                <option value="Assessment">Standard Assessment</option>
                                <option value="Adjustment">Adjustment</option>
                            </select>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold text-dark">Academic Year <span class="text-danger">*</span></label>
                            <select class="form-select" name="academic_year" required>
                                <option value="2026-2027" selected>2026-2027</option>
                                <option value="2025-2026">2025-2026</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold text-dark">Semester <span class="text-danger">*</span></label>
                            <select class="form-select" name="semester" required>
                                <option value="1st">1st Semester</option>
                                <option value="2nd">2nd Semester</option>
                                <option value="Summer">Summer</option>
                            </select>
                        </div>
                    </div>

                    <!-- Applicable Fees Section -->
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white border-bottom pb-2 d-flex justify-content-between align-items-center">
                            <h6 class="fw-bold mb-0 text-primary"><i class="fas fa-list-check me-2"></i>Select Applicable Fees</h6>
                            <div class="input-group input-group-sm w-50 shadow-sm">
                                <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                                <input type="text" class="form-control border-start-0 ps-0 table-live-search-input" data-table-target="#modalFeesTable" placeholder="Search fee name...">
                            </div>
                        </div>
                        <div class="card-body p-0" style="max-height: 250px; overflow-y: auto;">
                            <?php if (count($groupedActiveFees) > 0): ?>
                                <table class="table table-sm table-borderless align-middle mb-0" id="modalFeesTable">
                                    <tbody>
                                        <?php foreach ($groupedActiveFees as $categoryName => $fees): ?>
                                            <!-- Category Header -->
                                            <tr class="bg-light border-bottom">
                                                <td colspan="3" class="fw-bold text-secondary text-uppercase py-2 ps-3" style="font-size: 0.75rem; letter-spacing: 0.5px;">
                                                    <?= htmlspecialchars($categoryName) ?>
                                                </td>
                                            </tr>
                                            <!-- Category Fees -->
                                            <?php foreach ($fees as $fee): ?>
                                                <tr class="border-bottom">
                                                    <td class="text-center" style="width: 8%;">
                                                        <div class="form-check d-flex justify-content-center m-0">
                                                            <input class="form-check-input fee-checkbox" type="checkbox" name="selected_fees[]" value="<?= $fee['fee_id'] ?>" data-amount="<?= $fee['default_amount'] ?>" id="fee_<?= $fee['fee_id'] ?>" <?= $fee['is_required'] ? 'checked' : '' ?>>
                                                        </div>
                                                    </td>
                                                    <td class="ps-0 py-2">
                                                        <label class="form-check-label fw-bold d-block text-dark w-100" for="fee_<?= $fee['fee_id'] ?>" style="cursor: pointer;">
                                                            <?= htmlspecialchars($fee['fee_name']) ?>
                                                        </label>
                                                    </td>
                                                    <td class="text-end pe-4 py-2 fw-bold text-success" style="width: 25%;">
                                                        ₱ <?= number_format($fee['default_amount'], 2) ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <div class="text-center py-4 text-muted">
                                    <i class="fas fa-exclamation-circle mb-2 fs-4"></i><br>
                                    No active fees configured. Go to Fee Setup first.
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="card-footer bg-light border-top d-flex justify-content-between align-items-center py-3">
                            <span class="fw-bold text-secondary">Total Computed Amount:</span>
                            <h4 class="fw-bolder text-dark mb-0" id="totalComputedAmount">₱ 0.00</h4>
                        </div>
                    </div>

                </div>
                <div class="modal-footer border-0 bg-white pt-3">
                    <button type="button" class="btn btn-light border shadow-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="generate_billing" class="btn btn-primary shadow-sm px-4">Generate SOA</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="../../assets/js/billing-invoicing.js"></script>
<script src="<?= BASE_URL ?>/assets/js/payment-search.js"></script>

<?php require_once __DIR__ . '/../../../../includes/layout-end.php'; ?>