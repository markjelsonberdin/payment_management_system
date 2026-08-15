<?php
/**
 * SMS 2 - Collection Reporting & Analytics
 * Module: Payment Management
 */
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../includes/authentication.php';
require_once __DIR__ . '/../../../../includes/audit.php';
require_once __DIR__ . '/../../database/db_connect.php';
require_once __DIR__ . '/../../includes/ScholarshipDiscountService.php';

// I-enforce ang login at module access
requireAuth();
requireModuleAccess('payment');

// ==========================================
// BACKEND: PROCESS SCHOLARSHIP APPLICATION
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_discount'])) {
    
    $student_number = trim($_POST['student_number']);
    $billing_id = $_POST['billing_id'];
    $scholarship_name = $_POST['scholarship_name'];
    $discount_type = $_POST['discount_type']; // 'Percentage' or 'Fixed Amount'[cite: 22]
    $discount_value = (float) $_POST['discount_value'];
    $computed_discount = (float) $_POST['computed_discount_amount'];

    // Dynamic User ID (Walang hardcoded data)
    $approved_by = getCurrentUserId(); // Gamitin natin ang SMS2 function

    try {
        $discountService = new ScholarshipDiscountService($pdo);
        $discountService->applyScholarshipDiscount(
            $billing_id, 
            $scholarship_name, 
            $discount_type, 
            $discount_value, 
            $approved_by
        );

        header("Location: discount-scholarship-application.php?success=1");
        exit();

    } catch (Exception $e) {
        header("Location: discount-scholarship-application.php?error=" . urlencode($e->getMessage()));
        exit();
    }
}

$pageTitle    = 'Discount & Scholarship';
$activeModule = 'payment';
$activePage   = 'accounting/discount-scholarship-application';
$breadcrumbs  = [
    ['label' => 'Payment Management', 'url' => BASE_URL . '/modules/payment/index.php'],
    ['label' => 'Discount & Scholarship', 'url' => null],
];

require_once __DIR__ . '/../../../../includes/breadcrumbs.php';
require_once __DIR__ . '/../../../../includes/layout-start.php';
?>

<?php renderBreadcrumbs($breadcrumbs); ?>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="row mb-4 align-items-center">
        <div class="col-md-8">
            <h2 class="mb-1 fw-bolder"><i class="fas fa-award text-primary me-2"></i>Discount & Scholarship</h2>
            <p class="text-muted mb-0 fs-6">Apply academic scholarships, grants, and sibling discounts to student accounts.</p>
        </div>
    </div>

    <!-- Alerts -->
    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible shadow-sm"><i class="fas fa-check-circle me-2"></i> Discount successfully applied! <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger alert-dismissible shadow-sm"><i class="fas fa-exclamation-circle me-2"></i> <?= htmlspecialchars($_GET['error']) ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <div class="row">
        <!-- LEFT COLUMN: Search & Billing Info -->
        <div class="col-lg-4 mb-4">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-3">1. Select Student</h5>
                    
                    <div class="input-group mb-3">
                        <span class="input-group-text bg-light"><i class="fas fa-search"></i></span>
                        <input type="text" class="form-control" id="searchStudentDiscount" placeholder="Enter Student Number" autocomplete="off">
                        <button class="btn btn-primary" type="button" id="btnSearchBilling">Search</button>
                    </div>
                    
                    <!-- Dito lalabas ang result ng student at billing -->
                    <div id="billingDetailsContainer" class="d-none mt-4">
                        <div class="alert alert-info border-0 bg-light">
                            <h6 class="fw-bold text-dark mb-1" id="dispStudentName">---</h6>
                            <p class="text-muted small mb-0" id="dispStudentNumber">---</p>
                        </div>
                        
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Billing Reference:</span>
                            <span class="fw-bold text-dark" id="dispBillingId">---</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Original Balance:</span>
                            <span class="fw-bold text-danger fs-5" id="dispOriginalBalance">₱ 0.00</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- RIGHT COLUMN: Scholarship Selection (Based on your UI image) -->
        <div class="col-lg-8 mb-4">
            <div class="card border-0 shadow-sm rounded-4 h-100 opacity-50" id="scholarshipPanel" style="pointer-events: none;">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-4">2. Available Scholarships & Discounts</h5>
                    
                    <form action="" method="POST" id="discountForm">
                        <!-- Hidden Inputs for Submission -->
                        <input type="hidden" name="student_number" id="inputStudentNumber">
                        <input type="hidden" name="billing_id" id="inputBillingId">
                        <input type="hidden" name="scholarship_name" id="inputScholarshipName">
                        <input type="hidden" name="discount_type" id="inputDiscountType">
                        <input type="hidden" name="discount_value" id="inputDiscountValue">
                        <input type="hidden" name="computed_discount_amount" id="inputComputedAmount">

                        <div class="row g-3 mb-4">
                            <!-- Scholarship Cards -->
                            <div class="col-md-6">
                                <div class="card scholarship-card border rounded-3 p-3 cursor-pointer" data-name="Academic Excellence" data-type="Percentage" data-value="50">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <div class="bg-primary bg-opacity-10 text-primary p-2 rounded"><i class="fas fa-medal"></i></div>
                                        <span class="badge bg-success bg-opacity-10 text-success">Active</span>
                                    </div>
                                    <h6 class="fw-bold mb-0">Academic Excellence</h6>
                                    <small class="text-muted d-block mb-3">Merit-based</small>
                                    <div class="d-flex justify-content-between align-items-end">
                                        <h4 class="text-primary fw-bolder mb-0">50%</h4>
                                        <small class="text-muted fw-bold">Discount</small>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="card scholarship-card border rounded-3 p-3 cursor-pointer" data-name="Dean's Lister" data-type="Percentage" data-value="25">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <div class="bg-primary bg-opacity-10 text-primary p-2 rounded"><i class="fas fa-award"></i></div>
                                        <span class="badge bg-success bg-opacity-10 text-success">Active</span>
                                    </div>
                                    <h6 class="fw-bold mb-0">Dean's Lister</h6>
                                    <small class="text-muted d-block mb-3">Merit-based</small>
                                    <div class="d-flex justify-content-between align-items-end">
                                        <h4 class="text-primary fw-bolder mb-0">25%</h4>
                                        <small class="text-muted fw-bold">Discount</small>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="card scholarship-card border rounded-3 p-3 cursor-pointer" data-name="Financial Aid Grant" data-type="Percentage" data-value="30">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <div class="bg-primary bg-opacity-10 text-primary p-2 rounded"><i class="fas fa-hand-holding-heart"></i></div>
                                        <span class="badge bg-success bg-opacity-10 text-success">Active</span>
                                    </div>
                                    <h6 class="fw-bold mb-0">Financial Aid Grant</h6>
                                    <small class="text-muted d-block mb-3">Need-based</small>
                                    <div class="d-flex justify-content-between align-items-end">
                                        <h4 class="text-primary fw-bolder mb-0">30%</h4>
                                        <small class="text-muted fw-bold">Discount</small>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="card scholarship-card border rounded-3 p-3 cursor-pointer" data-name="Sibling Discount" data-type="Percentage" data-value="10">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <div class="bg-primary bg-opacity-10 text-primary p-2 rounded"><i class="fas fa-user-friends"></i></div>
                                        <span class="badge bg-success bg-opacity-10 text-success">Active</span>
                                    </div>
                                    <h6 class="fw-bold mb-0">Sibling Discount</h6>
                                    <small class="text-muted d-block mb-3">Discount</small>
                                    <div class="d-flex justify-content-between align-items-end">
                                        <h4 class="text-primary fw-bolder mb-0">10%</h4>
                                        <small class="text-muted fw-bold">Discount</small>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Computation Summary -->
                        <div class="bg-light p-3 rounded-3 d-flex justify-content-between align-items-center mb-4 border">
                            <div>
                                <span class="text-muted d-block small fw-bold text-uppercase">Less: Computed Discount</span>
                                <h4 class="text-success fw-bolder mb-0" id="dispComputedDiscount">- ₱ 0.00</h4>
                            </div>
                            <div class="text-end">
                                <span class="text-muted d-block small fw-bold text-uppercase">New Balance Due</span>
                                <h3 class="text-dark fw-bolder mb-0" id="dispNewBalance">₱ 0.00</h3>
                            </div>
                        </div>

                        <div class="text-end">
                            <button type="submit" name="apply_discount" class="btn btn-primary fw-bold px-4 shadow-sm" id="btnSubmitDiscount" disabled>
                                Apply Discount to Account
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Custom Styles para magmukhang clickable yung cards -->
<style>
    .scholarship-card { transition: all 0.2s ease-in-out; cursor: pointer; }
    .scholarship-card:hover { border-color: #0d6efd !important; transform: translateY(-3px); box-shadow: 0 4px 15px rgba(13,110,253,0.15); }
    .scholarship-card.selected { border: 2px solid #0d6efd !important; background-color: #f8fbff; box-shadow: 0 4px 15px rgba(13,110,253,0.2); }
</style>

<script src="../../assets/js/discount-scholarship.js"></script>
<script src="<?= BASE_URL ?>/assets/js/payment-search.js"></script>
<?php require_once __DIR__ . '/../../../../includes/layout-end.php'; ?>