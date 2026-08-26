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
require_once ROOT_PATH . '/modules/payment/includes/PaymentChannelService.php';
require_once ROOT_PATH . '/modules/payment/includes/ConvenienceFeeService.php';


if (isset($_GET['process']) && $_GET['process'] === 'soa') {
    header('Location: statement-of-account.php');
    exit;
}

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
                    SELECT bi.*, f.fee_name, f.description, f.category_id, fc.category_name, bi.source_context 
                    FROM payment_db.billing_items bi 
                    JOIN payment_db.fees f ON bi.fee_id = f.fee_id 
                    LEFT JOIN payment_db.fee_categories fc ON f.category_id = fc.category_id
                    WHERE bi.billing_id = :billing_id
                ");
                $stmtItems->execute([':billing_id' => $billingDetails['billing_id']]);
                $assessmentBreakdown = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

                // Group assessment by category for accordion UI
                $groupedAssessment = [];
                foreach ($assessmentBreakdown as $item) {
                    $catName = $item['category_name'] ?: 'Other Fees';
                    if (!isset($groupedAssessment[$catName])) {
                        $groupedAssessment[$catName] = [
                            'total_amount' => 0,
                            'paid_amount' => 0,
                            'status' => 'Unpaid',
                            'items' => []
                        ];
                    }
                    $groupedAssessment[$catName]['items'][] = $item;
                    $groupedAssessment[$catName]['total_amount'] += $item['amount'];
                    $groupedAssessment[$catName]['paid_amount'] += ($item['amount'] - $item['remaining_amount']);
                }
                
                // Evaluate category status
                foreach ($groupedAssessment as $catName => &$catData) {
                    if ($catData['paid_amount'] >= $catData['total_amount'] && $catData['total_amount'] > 0) {
                        $catData['status'] = 'Paid';
                    } elseif ($catData['paid_amount'] > 0) {
                        $catData['status'] = 'Partial';
                    } else {
                        $catData['status'] = 'Unpaid';
                    }
                }
                unset($catData);

                // Phase 11: Compute Context-Aware Payable Options
                $payableOptions = [];
                
                // Group A: Enrollment Assessment Total
                $enrollmentTotal = 0;
                foreach ($assessmentBreakdown as $item) {
                    if ($item['category_id'] == 1) continue; // Skip Tuition
                    if ($item['remaining_amount'] <= 0) continue;
                    
                    if ($item['source_context'] === 'Enrollment Assessment') {
                        $enrollmentTotal += (float)$item['remaining_amount'];
                    }
                }
                
                if ($enrollmentTotal > 0) {
                    $payableOptions[] = [
                        'value_id' => 'enrollment_priority',
                        'allocation_context' => 'ENROLLMENT_PRIORITY',
                        'billing_item_id' => null,
                        'name' => 'Enrollment Fees (Priority Allocation)',
                        'amount' => $enrollmentTotal
                    ];
                }

                // Group B & C: Specific Items (Standard / Adjustment)
                foreach ($assessmentBreakdown as $item) {
                    if ($item['category_id'] == 1) continue; // Skip Tuition
                    if ($item['remaining_amount'] <= 0) continue;
                    
                    if ($item['source_context'] !== 'Enrollment Assessment') {
                        $payableOptions[] = [
                            'value_id' => $item['billing_item_id'],
                            'allocation_context' => 'SPECIFIC_ITEM',
                            'billing_item_id' => $item['billing_item_id'],
                            'name' => $item['fee_name'] . ' (' . $item['source_context'] . ')',
                            'amount' => (float)$item['remaining_amount']
                        ];
                    }
                }
            }
        }
    }
} catch (PDOException $e) {
} catch (PDOException $e) {
    // Silently handle errors para hindi masira ang UI
}

// 4. Removed synchronous fetch of PaymentChannels here. Now handled via AJAX.

// 5. Load the UI Header (Sidebar, Topbar, CSS)
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
    
    <?php if (isset($_GET['payment']) && $_GET['payment'] === 'success'): ?>
        <div class="alert alert-success border-0 shadow-sm rounded-3 mb-4 d-flex align-items-center">
            <i class="fas fa-check-circle fs-4 me-3"></i>
            <div>
                <strong>Payment Successful!</strong><br>
                Your transaction has been processed. It may take a few moments to reflect in your account balance below.
            </div>
        </div>
    <?php elseif (isset($_GET['payment']) && $_GET['payment'] === 'cancelled'): ?>
        <div class="alert alert-warning border-0 shadow-sm rounded-3 mb-4 d-flex align-items-center">
            <i class="fas fa-exclamation-triangle fs-4 me-3"></i>
            <div>
                <strong>Payment Cancelled</strong><br>
                You cancelled the checkout process. No charges were made.
            </div>
        </div>
    <?php endif; ?>

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
            
            <div class="accordion mb-4" id="breakdownAccordion">
                <?php if (count($groupedAssessment) > 0): ?>
                    <?php $accIndex = 0; foreach ($groupedAssessment as $catName => $catData): $accIndex++; ?>
                        <?php
                            $catStatusBg = match($catData['status']) {
                                'Paid' => '#bbf7d0',
                                'Partial' => '#fef08a',
                                default => '#fecaca'
                            };
                            $catStatusText = match($catData['status']) {
                                'Paid' => '#15803d',
                                'Partial' => '#b45309',
                                default => '#b91c1c'
                            };
                        ?>
                        <div class="accordion-item border-0 mb-3 shadow-sm rounded-4 overflow-hidden">
                            <h2 class="accordion-header" id="heading<?= $accIndex ?>">
                                <button class="accordion-button <?= $accIndex === 1 ? '' : 'collapsed' ?> bg-white fw-bold d-flex align-items-center justify-content-between p-3" type="button" data-bs-toggle="collapse" data-bs-target="#collapse<?= $accIndex ?>" aria-expanded="<?= $accIndex === 1 ? 'true' : 'false' ?>" aria-controls="collapse<?= $accIndex ?>" style="box-shadow: none;">
                                    <div class="d-flex w-100 align-items-center me-3">
                                        <div class="flex-grow-1 text-dark fs-6" style="text-transform: uppercase; font-size: 0.85rem !important; letter-spacing: 0.5px;">
                                            <i class="fas fa-layer-group text-primary me-2 opacity-75"></i><?= htmlspecialchars($catName) ?>
                                            <div class="text-muted fw-normal mt-1 text-capitalize" style="font-size: 0.75rem; letter-spacing: 0;">
                                                PHP <?= number_format($catData['paid_amount'], 2) ?> of PHP <?= number_format($catData['total_amount'], 2) ?> Paid
                                            </div>
                                        </div>
                                        <span class="badge rounded-pill ms-auto" style="background-color: <?= $catStatusBg ?>; color: <?= $catStatusText ?>; font-size: 0.75rem;">
                                            <?= htmlspecialchars($catData['status']) ?>
                                        </span>
                                    </div>
                                </button>
                            </h2>
                            <div id="collapse<?= $accIndex ?>" class="accordion-collapse collapse <?= $accIndex === 1 ? 'show' : '' ?>" aria-labelledby="heading<?= $accIndex ?>" data-bs-parent="#breakdownAccordion">
                                <div class="accordion-body p-0 bg-light border-top">
                                    <table class="table table-borderless table-hover align-middle mb-0 m-0">
                                        <thead class="text-muted border-bottom" style="font-size: 0.70rem;">
                                            <tr>
                                                <th class="py-3 ps-4 w-50">FEE DETAILS</th>
                                                <th class="py-3 text-end">ASSESSMENT</th>
                                                <th class="py-3 text-end pe-4">PAID</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($catData['items'] as $item): ?>
                                                <tr class="border-bottom">
                                                    <td class="py-3 ps-4">
                                                        <div class="fw-semibold text-dark d-flex align-items-center gap-2" style="font-size: 0.9rem;">
                                                            <?= htmlspecialchars($item['fee_name']) ?>
                                                            <?php if (!empty($item['source_context'])): ?>
                                                                <span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25 fw-normal" style="font-size: 0.65rem; padding: 0.2rem 0.5rem;"><?= htmlspecialchars($item['source_context']) ?></span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <?php if (!empty($item['description'])): ?>
                                                            <small class="text-muted d-block mt-1" style="font-size: 0.75rem;"><?= htmlspecialchars($item['description']) ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="py-3 text-end text-dark">PHP <?= number_format($item['amount'], 2) ?></td>
                                                    <td class="py-3 text-end pe-4 text-success fw-bold">PHP <?= number_format($item['amount'] - $item['remaining_amount'], 2) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="alert alert-light text-center text-muted mb-0 border shadow-sm rounded-4">
                        <i class="fas fa-folder-open fs-4 d-block mb-2 text-secondary"></i>
                        No assessment records found. You currently have no active billing.
                    </div>
                <?php endif; ?>
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
                <select id="paymongoCategorySelect" class="form-select mb-3 shadow-sm" onchange="updatePaymongoAmount()">
                    <?php if (empty($payableOptions)): ?>
                        <option value="">No eligible fees available to pay</option>
                    <?php else: ?>
                        <?php foreach ($payableOptions as $opt): ?>
                            <option value="<?= $opt['value_id'] ?>" 
                                    data-context="<?= $opt['allocation_context'] ?>" 
                                    data-item-id="<?= $opt['billing_item_id'] ?>" 
                                    data-amount="<?= $opt['amount'] ?>">
                                <?= htmlspecialchars($opt['name']) ?> (PHP <?= number_format($opt['amount'], 2) ?>)
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>

                <label class="form-label fw-bold text-dark small mb-2">Amount to Pay (PHP):</label>
                <input type="number" id="paymongoAmountInput" class="form-control mb-3 shadow-sm" step="0.01" placeholder="Enter amount to pay">
                <label class="form-label fw-bold text-dark small mb-3">Choose Payment Method:</label>
                
                <!-- Container for dynamically loaded payment buttons -->
                <div id="payment-methods-container" class="d-grid gap-2">
                    <div class="text-center py-3 text-muted" id="payment-methods-loading">
                        <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                        <small>Loading available payment options...</small>
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
function updatePaymongoAmount() {
    const selectEl = document.getElementById('paymongoCategorySelect');
    if (selectEl && selectEl.selectedIndex >= 0) {
        const option = selectEl.options[selectEl.selectedIndex];
        const amount = option.getAttribute('data-amount');
        if (amount) {
            document.getElementById('paymongoAmountInput').value = parseFloat(amount).toFixed(2);
        }
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const modalEl = document.getElementById('paymongoModal');
    if (modalEl) {
        modalEl.addEventListener('show.bs.modal', function () {
            updatePaymongoAmount();
        });
    }
});

function initiatePayMongoCheckout(channel) {
    const selectEl = document.getElementById('paymongoCategorySelect');
    
    if (selectEl.selectedIndex < 0 || !selectEl.value) {
        alert("Please select a fee to pay.");
        return;
    }
    
    const option = selectEl.options[selectEl.selectedIndex];
    const allocationContext = option.getAttribute('data-context');
    const billingItemId = option.getAttribute('data-item-id');
    const maxAmount = parseFloat(option.getAttribute('data-amount'));
    const amountInput = document.getElementById('paymongoAmountInput');
    const inputAmount = parseFloat(amountInput.value);

    if (isNaN(inputAmount) || inputAmount <= 0) {
        alert("Please enter a valid amount.");
        return;
    }

    if (inputAmount > maxAmount) {
        alert("Amount cannot exceed the remaining balance for this category (PHP " + maxAmount.toFixed(2) + ").");
        return;
    }

    if (maxAmount >= 1000 && inputAmount < 1000) {
        alert("The minimum payment amount is PHP 1,000.00.");
        return;
    }

    if (maxAmount < 1000 && inputAmount !== maxAmount) {
        alert("Since the balance is below PHP 1,000.00, you must pay the exact remaining amount (PHP " + maxAmount.toFixed(2) + ").");
        return;
    }

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
    fetch("<?= BASE_URL ?>/modules/payment/api/paymongo/create-checkout.php", {
        method: "POST",
        headers: {
            "Content-Type": "application/json"
        },
        body: JSON.stringify({
            student_id: studentId,
            billing_id: billingId,
            allocation_context: allocationContext,
            billing_item_id: billingItemId ? parseInt(billingItemId) : null,
            amount: inputAmount,
            channel: channel
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.checkout_url) {
            // Redirect the student to PayMongo secure checkout page
            window.location.href = data.checkout_url;
        } else {
            alert("Checkout Failed: " + (data.error || "Unknown error occurred."));
            resetCheckoutUI();
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert("A network error occurred while generating the checkout link.");
        resetCheckoutUI();
    });
}

// Fetch available payment channels dynamically when page loads
document.addEventListener('DOMContentLoaded', function() {
    fetch("<?= BASE_URL ?>/modules/payment/api/paymongo/available-channels.php")
        .then(response => response.json())
        .then(data => {
            const container = document.getElementById('payment-methods-container');
            container.innerHTML = ''; // Clear loading
            
            if (data.success && data.channels && data.channels.length > 0) {
                const channelData = {
                    'gcash': { bg: 'bg-primary', color: 'text-white', icon: 'G', name: 'GCash', desc: 'Fast & secure e-wallet payment' },
                    'maya': { bg: 'bg-success', color: 'text-white', icon: 'M', name: 'Maya', desc: 'Pay using your Maya account' },
                    'qrph': { bg: 'bg-warning', color: 'text-dark', icon: '<i class="fas fa-qrcode"></i>', name: 'QR Ph', desc: 'Scan to pay via any supported app' },
                    'card': { bg: 'bg-dark', color: 'text-white', icon: '<i class="fas fa-credit-card"></i>', name: 'Credit / Debit Card', desc: 'Visa, Mastercard, JCB' }
                };

                data.channels.forEach(ch => {
                    const info = channelData[ch.code];
                    if (info) {
                        const btn = document.createElement('div');
                        btn.className = 'p-3 border rounded-3 bg-white shadow-sm d-flex justify-content-between align-items-center paymongo-btn';
                        btn.style.cursor = 'pointer';
                        btn.onclick = () => initiatePayMongoCheckout(ch.code);
                        btn.innerHTML = `
                            <div class="d-flex align-items-center gap-3">
                                <div class="${info.bg} ${info.color} rounded-circle d-flex align-items-center justify-content-center fw-bold" style="width: 40px; height: 40px;">${info.icon}</div>
                                <div>
                                    <div class="fw-bold text-dark mb-0">${info.name}</div>
                                    <small class="text-muted">${info.desc}</small>
                                </div>
                            </div>
                            <i class="fas fa-chevron-right text-muted"></i>
                        `;
                        container.appendChild(btn);
                    }
                });
            } else {
                container.innerHTML = `
                    <div class="text-center py-4 text-muted border rounded-3 bg-light">
                        <i class="fas fa-times-circle fs-3 mb-2 text-secondary"></i>
                        <div class="fw-bold">No Channels Available</div>
                        <small>Online payment is currently unavailable.</small>
                    </div>
                `;
            }
        })
        .catch(err => {
            console.error('Failed to load payment channels', err);
            document.getElementById('payment-methods-container').innerHTML = `
                <div class="text-center py-3 text-danger border rounded-3 bg-light">
                    <small>Failed to load payment options. Please refresh the page.</small>
                </div>
            `;
        });
});

function resetCheckoutUI() {
    document.getElementById('checkoutLoading').classList.add('d-none');
    document.querySelectorAll('.paymongo-btn').forEach(btn => btn.style.pointerEvents = 'auto');
}
</script>
<?php endif; ?>

<!-- 5. Load the UI Footer (Scripts, closing tags) -->
<?php require_once ROOT_PATH . '/includes/layout-end.php'; ?>