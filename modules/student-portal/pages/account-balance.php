<?php
/**
 * SMS 2 - Student Portal: Account Balance
 * Standalone page with direct layout includes and DB integration.
 */

// 1. Core Configurations & Authentication
// Main System Config (para sa ROOT_PATH at BASE_URL)
require_once __DIR__ . '/../../../config/config.php';

// Student Portal Module Config (para sa studentPortalDb() function)
require_once __DIR__ . '/../config/config.php';

require_once ROOT_PATH . '/includes/authentication.php';
require_once ROOT_PATH . '/includes/breadcrumbs.php';

// 2. Page Meta Setup for Header and Sidebar active states
$pageTitle = 'Account Balance';
$activeModule = 'student_portal';
$activePage = 'account-balance';
$breadcrumbs = [
    ['label' => 'Student Portal', 'url' => BASE_URL . '/modules/student-portal/pages/my-profile.php'],
    ['label' => 'Account Balance', 'url' => null],
];

// 3. Database Fetch Logic
$studentId = $_SESSION['student_id'] ?? 'S230106713'; // Default fallback based on your DB

$totalAssessment = 0.00;
$totalPaid = 0.00;
$remainingBalance = 0.00;
$assessmentBreakdown = [];
$academicYear = 'N/A';
$semester = 'N/A';

try {
    // Kunin ang PDO connection gamit ang function mula sa config.php
    $pdo = studentPortalDb(); 
    
    if ($pdo) {
        // Minsan ang nasa session ay numeric lang (e.g. 230115569) pero sa database ay 'S230115569'
        $searchSn = strtoupper(trim($studentId));
        if (!str_starts_with($searchSn, 'S') && is_numeric($searchSn)) {
            $searchSn = 'S' . str_pad($searchSn, 9, '0', STR_PAD_LEFT);
        }

        // Kunin ang internal student_id gamit ang student_number mula sa session
        $stmt = $pdo->prepare("SELECT student_id FROM payment_db.students WHERE student_number = :student_number OR student_number = :raw_number LIMIT 1");
        
        $stmt->execute([
            ':student_number' => $searchSn,
            ':raw_number' => $studentId
        ]);
        $studentRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($studentRow) {
            $dbStudentId = $studentRow['student_id'];

            // Kunin ang pinakabagong billing record ng estudyante
            $stmtBilling = $pdo->prepare("SELECT * FROM payment_db.billing WHERE student_id = :student_id ORDER BY billing_id DESC LIMIT 1");
            $stmtBilling->execute([':student_id' => $dbStudentId]);
            $billingDetails = $stmtBilling->fetch(PDO::FETCH_ASSOC);

            if ($billingDetails) {
                $totalAssessment = (float)$billingDetails['total_amount'];
                $remainingBalance = (float)$billingDetails['remaining_balance'];
                $totalPaid = $totalAssessment - $remainingBalance;
                
                $academicYear = $billingDetails['academic_year'];
                $semester = $billingDetails['semester'];

                // Kunin ang breakdown ng fees naka-join sa fees table at fee_categories
                $stmtItems = $pdo->prepare("
                    SELECT bi.*, f.fee_name, f.description, f.category_id, fc.category_name 
                    FROM payment_db.billing_items bi 
                    JOIN payment_db.fees f ON bi.fee_id = f.fee_id 
                    LEFT JOIN payment_db.fee_categories fc ON f.category_id = fc.category_id
                    WHERE bi.billing_id = :billing_id
                ");
                $stmtItems->execute([':billing_id' => $billingDetails['billing_id']]);
                $assessmentBreakdown = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

                // Phase 11: Compute Payable Categories (Excluding Tuition category_id = 1)
                $payableCategories = [];
                foreach ($assessmentBreakdown as $item) {
                    if ($item['category_id'] == 1) continue; // Skip Tuition
                    if ($item['remaining_amount'] <= 0) continue;

                    $catId = $item['category_id'];
                    if (!isset($payableCategories[$catId])) {
                        $payableCategories[$catId] = [
                            'id' => $catId,
                            'name' => $item['category_name'] ?: 'Other Fees',
                            'amount' => 0.00
                        ];
                    }
                    $payableCategories[$catId]['amount'] += (float)$item['remaining_amount'];
                }
            }
        }
    }
} catch (PDOException $e) {
    // Silently handle errors para hindi masira ang UI
}

// 4. Load the UI Header (Sidebar, Topbar, CSS)
require_once ROOT_PATH . '/includes/layout-start.php';
?>

<!-- Render Breadcrumbs -->
<?php renderBreadcrumbs($breadcrumbs); ?>

<!-- Main Student Portal Content Wrapper -->
<div class="student-portal">
    
    <!-- Page Header -->
    <div class="page-header student-portal-header d-flex justify-content-between align-items-center mb-4">
        <div>
            <span class="student-kicker text-uppercase text-primary fw-bold small">Student Portal</span>
            <h2 class="fw-bolder m-0"><i class="fas fa-wallet text-sms-primary me-2"></i>Account Balance</h2>
            <p class="text-muted m-0 mt-1">Track current charges, payments, discounts, and remaining balance.</p>
        </div>
        <div class="student-term-badge bg-light border px-3 py-2 rounded-3 text-dark fw-semibold shadow-sm">
            <i class="fas fa-calendar-check text-primary me-1"></i> SY <?= htmlspecialchars($academicYear) ?>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-3 h-100" style="border-left: 5px solid #f59e0b !important;">
                <div class="card-body d-flex flex-column justify-content-center py-4 ps-4">
                    <span class="text-muted text-uppercase fw-bold mb-1" style="font-size: 0.70rem; letter-spacing: 0.5px;">Total Assessment</span>
                    <h4 class="fw-bolder text-dark mb-0">PHP <?= number_format($totalAssessment, 2) ?></h4>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-3 h-100" style="border-left: 5px solid #10b981 !important;">
                <div class="card-body d-flex flex-column justify-content-center py-4 ps-4">
                    <span class="text-muted text-uppercase fw-bold mb-1" style="font-size: 0.70rem; letter-spacing: 0.5px;">Total Paid</span>
                    <h4 class="fw-bolder text-dark mb-0">PHP <?= number_format($totalPaid, 2) ?></h4>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-3 h-100" style="border-left: 5px solid #3b82f6 !important;">
                <div class="card-body d-flex flex-column justify-content-center py-4 ps-4">
                    <span class="text-muted text-uppercase fw-bold mb-1" style="font-size: 0.70rem; letter-spacing: 0.5px;">Balance</span>
                    <h4 class="fw-bolder text-dark mb-0">PHP <?= number_format($remainingBalance, 2) ?></h4>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Assessment Breakdown -->
    <div class="card border-0 shadow-sm rounded-3 mb-4">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h6 class="fw-bold m-0 text-dark">Assessment Breakdown</h6>
                <span class="badge bg-light text-dark border px-3 py-2">S.Y. <?= htmlspecialchars($academicYear) ?> • <?= htmlspecialchars($semester) ?> Semester</span>
            </div>
            
            <div class="table-responsive mb-4">
                <table class="table table-borderless border-bottom align-middle mb-0">
                    <thead class="border-bottom text-muted" style="font-size: 0.75rem;">
                        <tr>
                            <th class="py-3 ps-2">FEE</th>
                            <th class="py-3 text-end">AMOUNT</th>
                            <th class="py-3 ps-4" style="width: 120px;">STATUS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($assessmentBreakdown) > 0): ?>
                            <?php foreach ($assessmentBreakdown as $item): ?>
                                <?php
                                    // Set Badge Colors Based on Status
                                    $statusBg = match($item['status']) {
                                        'Paid' => '#bbf7d0',
                                        'Partial' => '#fef08a',
                                        default => '#fecaca' // Unpaid
                                    };
                                    $statusText = match($item['status']) {
                                        'Paid' => '#15803d',
                                        'Partial' => '#b45309',
                                        default => '#b91c1c'
                                    };
                                ?>
                                <tr class="border-bottom">
                                    <td class="py-3 ps-2">
                                        <div class="fw-bold text-dark"><?= htmlspecialchars($item['fee_name']) ?></div>
                                        <?php if (!empty($item['description'])): ?>
                                            <small class="text-muted" style="font-size: 0.8rem;"><?= htmlspecialchars($item['description']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 text-end text-dark">PHP <?= number_format($item['amount'], 2) ?></td>
                                    <td class="py-3 ps-4">
                                        <span class="badge rounded-pill" style="background-color: <?= $statusBg ?>; color: <?= $statusText ?>; padding: 0.5em 0.8em;">
                                            <?= htmlspecialchars($item['status']) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="3" class="py-4 text-center text-muted">No assessment records found. You currently have no active billing.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div>
                <a href="?process=soa" class="btn btn-outline-primary fw-semibold px-4 py-2 shadow-sm rounded-3">
                    <i class="fas fa-file-invoice me-2"></i>Request Statement of Account
                </a>
            </div>
        </div>
    </div>

    <!-- Pay Online via Paymongo Section -->
    <div class="card border-0 shadow-sm rounded-3" style="background-color: #f8fafc;">
        <div class="card-body p-4">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                <div>
                    <h6 class="fw-bold text-dark mb-1"><i class="fas fa-shield-alt text-success me-2"></i>Pay Online via Paymongo</h6>
                    <p class="text-muted mb-0" style="font-size: 0.85rem;">Secure checkout powered by Paymongo. Choose your preferred payment channel below.</p>
                </div>
                <div>
                    <?php if ($remainingBalance > 0): ?>
                        <button class="btn btn-primary fw-bold px-4 py-2 shadow-sm rounded-3 w-100" data-bs-toggle="modal" data-bs-target="#paymongoModal">
                            <i class="fas fa-credit-card me-2"></i>Pay Remaining Balance
                        </button>
                    <?php else: ?>
                        <button class="btn btn-success fw-bold px-4 py-2 shadow-sm rounded-3 w-100" disabled>
                            <i class="fas fa-check-circle me-2"></i>Fully Paid
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

</div> <!-- End of student-portal -->

<!-- Paymongo Channels Modal UI -->
<?php if ($remainingBalance > 0): ?>
<div class="modal fade" id="paymongoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4 overflow-hidden">
            <div class="modal-header bg-primary text-white border-0 pb-3">
                <h5 class="modal-title fw-bold"><i class="fas fa-wallet me-2"></i>Select Payment Channel</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="alert alert-info border-0 shadow-sm mb-3 small">
                    <i class="fas fa-info-circle me-1"></i> Tuition fees cannot be paid online. Please select a valid fee category below to pay.
                </div>
                
                <label class="form-label fw-bold text-dark small mb-2">Select Fee to Pay:</label>
                <select id="paymongoCategorySelect" class="form-select mb-3 shadow-sm">
                    <?php if (empty($payableCategories)): ?>
                        <option value="">No eligible fees available to pay</option>
                    <?php else: ?>
                        <?php foreach ($payableCategories as $cat): ?>
                            <option value="<?= $cat['id'] ?>" data-amount="<?= $cat['amount'] ?>">
                                <?= htmlspecialchars($cat['name']) ?> (PHP <?= number_format($cat['amount'], 2) ?>)
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>

                <label class="form-label fw-bold text-dark small mb-3">Choose Payment Method:</label>
                <div class="d-grid gap-2">
                    <!-- GCash -->
                    <div class="p-3 border rounded-3 bg-white shadow-sm d-flex justify-content-between align-items-center paymongo-btn" style="cursor: pointer;" onclick="initiatePayMongoCheckout()">
                        <div class="d-flex align-items-center gap-3">
                            <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center fw-bold" style="width: 40px; height: 40px;">G</div>
                            <div>
                                <div class="fw-bold text-dark mb-0">GCash</div>
                                <small class="text-muted">Fast & secure e-wallet payment</small>
                            </div>
                        </div>
                        <i class="fas fa-chevron-right text-muted"></i>
                    </div>
                    <!-- Maya -->
                    <div class="p-3 border rounded-3 bg-white shadow-sm d-flex justify-content-between align-items-center paymongo-btn" style="cursor: pointer;" onclick="initiatePayMongoCheckout()">
                        <div class="d-flex align-items-center gap-3">
                            <div class="bg-success text-white rounded-circle d-flex align-items-center justify-content-center fw-bold" style="width: 40px; height: 40px;">M</div>
                            <div>
                                <div class="fw-bold text-dark mb-0">Maya</div>
                                <small class="text-muted">Pay using your Maya account</small>
                            </div>
                        </div>
                        <i class="fas fa-chevron-right text-muted"></i>
                    </div>
                    <!-- Card -->
                    <div class="p-3 border rounded-3 bg-white shadow-sm d-flex justify-content-between align-items-center paymongo-btn" style="cursor: pointer;" onclick="initiatePayMongoCheckout()">
                        <div class="d-flex align-items-center gap-3">
                            <div class="bg-dark text-white rounded-circle d-flex align-items-center justify-content-center fw-bold" style="width: 40px; height: 40px;"><i class="fas fa-credit-card"></i></div>
                            <div>
                                <div class="fw-bold text-dark mb-0">Credit / Debit Card</div>
                                <small class="text-muted">Visa, Mastercard, JCB</small>
                            </div>
                        </div>
                        <i class="fas fa-chevron-right text-muted"></i>
                    </div>
                </div>
            </div>
            
            <div class="modal-footer border-0 bg-light py-3 d-flex justify-content-between align-items-center">
                <div id="checkoutLoading" class="d-none text-primary fw-bold small">
                    <i class="fas fa-spinner fa-spin me-2"></i>Generating Secure Link...
                </div>
                <button type="button" class="btn btn-light border shadow-sm px-4" data-bs-dismiss="modal">Cancel</button>
            </div>
        </div>
    </div>
</div>

<script>
function initiatePayMongoCheckout() {
    const selectEl = document.getElementById('paymongoCategorySelect');
    const categoryId = selectEl.value;
    
    if (!categoryId) {
        alert("Please select a fee category to pay.");
        return;
    }
    
    const amount = parseFloat(selectEl.options[selectEl.selectedIndex].getAttribute('data-amount'));
    const studentId = "<?= addslashes($dbStudentId ?? '') ?>";
    const billingId = "<?= addslashes($billingDetails['billing_id'] ?? '') ?>";

    if (!studentId || !billingId) {
        alert("Billing information is missing.");
        return;
    }

    // Show loading state
    document.getElementById('checkoutLoading').classList.remove('d-none');
    document.querySelectorAll('.paymongo-btn').forEach(btn => btn.style.pointerEvents = 'none');

    // Call our Phase 5 API endpoint
    fetch("<?= BASE_URL ?>/modules/payment/api/paymongo/create-checkout-session.php", {
        method: "POST",
        headers: {
            "Content-Type": "application/json"
        },
        body: JSON.stringify({
            student_id: studentId,
            billing_id: billingId,
            category_id: categoryId,
            amount: amount
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success' && data.checkout_url) {
            // Redirect the student to PayMongo secure checkout page
            window.location.href = data.checkout_url;
        } else {
            alert("Checkout Failed: " + (data.message || "Unknown error occurred."));
            resetCheckoutUI();
        }
    })
    .catch(error => {
        console.error("Error:", error);
        alert("A network error occurred while generating the checkout link.");
        resetCheckoutUI();
    });
}

function resetCheckoutUI() {
    document.getElementById('checkoutLoading').classList.add('d-none');
    document.querySelectorAll('.paymongo-btn').forEach(btn => btn.style.pointerEvents = 'auto');
}
</script>
<?php endif; ?>

<!-- 5. Load the UI Footer (Scripts, closing tags) -->
<?php require_once ROOT_PATH . '/includes/layout-end.php'; ?>