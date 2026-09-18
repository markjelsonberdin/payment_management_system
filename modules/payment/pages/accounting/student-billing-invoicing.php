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
require_once __DIR__ . '/../../includes/FeeBillingWorkflow.php';

// I-enforce ang login at module access
requireAuth();
requirePaymentPermission('payment.billing');
$feeWorkflow = new FeeBillingWorkflow($pdo);
$feeWorkflowAvailable = $feeWorkflow->available();
$campaignId = max(0, (int) ($_GET['campaign_id'] ?? $_POST['campaign_id'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['start_bulk_billing'])) {
    requireCsrf();
    try {
        if (!$feeWorkflowAvailable || !$feeWorkflow->generationEnabled()) {
            throw new RuntimeException('Bulk billing is unavailable until the Payment migration and PAYMENT_BULK_BILLING_ENABLED setting are ready.');
        }
        $campaignId = $feeWorkflow->submitCategory(
            (int) ($_POST['category_id'] ?? 0),
            trim((string) ($_POST['academic_year'] ?? '')),
            (string) ($_POST['semester'] ?? ''),
            (string) ($_POST['year_level'] ?? ''),
            (int) getCurrentUserId()
        );
        if (!$feeWorkflow->start($campaignId, (int) getCurrentUserId())) {
            throw new RuntimeException('Bulk billing could not be started. Refresh the page and try again.');
        }
        logActivity('bulk_billing_started', 'Campaign #' . $campaignId . ' started by Accounting', 'payment');
        header('Location: student-billing-invoicing.php?campaign_id=' . $campaignId . '&bulk_started=1');
    } catch (Throwable $e) {
        header('Location: student-billing-invoicing.php?error=' . urlencode($e->getMessage()));
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fee_campaign_action'])) {
    requireCsrf();
    try {
        if (!$feeWorkflowAvailable || $campaignId <= 0) {
            throw new RuntimeException('Billing review is unavailable.');
        }
        $action = (string) $_POST['fee_campaign_action'];
        if ($action === 'start') {
            if (!$feeWorkflow->start($campaignId, (int) getCurrentUserId())) {
                throw new RuntimeException('Billing could not be started. Refresh the page and try again.');
            }
            logActivity('bulk_billing_started', 'Campaign #' . $campaignId . ' started by Accounting', 'payment');
        } elseif ($action === 'chunk') {
            $result = $feeWorkflow->runChunk($campaignId, (int) getCurrentUserId());
            if (($result['status'] ?? '') === 'Completed') {
                logActivity('fee_billing_complete', 'Campaign #' . $campaignId . ': added ' . (int) ($result['added_count'] ?? 0) . ', existing ' . (int) ($result['existing_count'] ?? 0) . ', failed ' . (int) ($result['failed_count'] ?? 0), 'payment');
            }
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => true, 'status' => $result['status'] ?? '', 'added' => (int) ($result['added_count'] ?? 0), 'existing' => (int) ($result['existing_count'] ?? 0), 'failed' => (int) ($result['failed_count'] ?? 0)]);
            exit;
        } elseif ($action === 'retry_failed') {
            if (!$feeWorkflow->retryFailures($campaignId)) {
                throw new RuntimeException('No completed run with failures is available to retry.');
            }
            logActivity('fee_billing_retry', 'Retrying failed assignments in campaign #' . $campaignId, 'payment');
        } else {
            throw new InvalidArgumentException('Unknown billing action.');
        }
        header('Location: student-billing-invoicing.php?campaign_id=' . $campaignId);
    } catch (Throwable $e) {
        if (($_POST['fee_campaign_action'] ?? '') === 'chunk') {
            http_response_code(409);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            exit;
        }
        header('Location: student-billing-invoicing.php?campaign_id=' . $campaignId . '&error=' . urlencode($e->getMessage()));
    }
    exit;
}

$feeCampaigns = [];
$selectedCampaign = null;
$feePreview = null;
if ($feeWorkflowAvailable) {
    $feeCampaigns = $feeWorkflow->list();
    if ($campaignId > 0) {
        $selectedCampaign = $feeWorkflow->get($campaignId);
        if ($selectedCampaign && $selectedCampaign['status'] === 'Submitted') {
            $feePreview = $feeWorkflow->preview($selectedCampaign);
            logActivity('fee_eligibility_preview', 'Previewed campaign #' . $campaignId . ': eligible ' . (int) $feePreview['eligible'] . ', unresolved ' . (int) $feePreview['unresolved'], 'payment');
        }
    }
}

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
            SELECT billing_id FROM billing 
            WHERE student_id = :student_id 
            AND academic_year = :ay 
            AND semester = :sem
        ");
        $stmtCheck->execute([
            ':student_id' => $student_id,
            ':ay'         => $academic_year,
            ':sem'        => $semester
        ]);

        $existingBilling = $stmtCheck->fetch(PDO::FETCH_ASSOC);
        $billingService = new BillingService($pdo);

        if ($existingBilling) {
            // Append to existing SOA
            $billing_id = $existingBilling['billing_id'];
            $appendResult = $billingService->appendFeesToBilling(
                $billing_id, 
                $selected_fees, 
                $billing_type, 
                $generated_by
            );
            
            $_SESSION['append_result'] = $appendResult;
            logActivity('append_billing', "Appended $billing_type fees to existing SOA for Student: $student_number", 'payment');
            header("Location: $redirect_url?success=append");
            exit();
        } else {
            // Generate new SOA
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
        }

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
        FROM billing
    ");
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

    $totalReceivables = $summary['total_receivables'] ?? 0;
    $unpaidCount = $summary['unpaid_count'] ?? 0;
    $paidCount = $summary['paid_count'] ?? 0;

    // Kunin ang Billing List (Fast Local Query gamit ang students reference)
    $stmt = $pdo->query("
        SELECT b.billing_id, b.billing_type, b.academic_year, b.semester, 
               b.total_amount, b.remaining_balance, b.billing_status, b.created_at,
               s.student_number, s.full_name
        FROM billing b
        JOIN students s ON b.student_id = s.student_id
        ORDER BY b.created_at DESC
    ");
    $billingList = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Kunin ang Active Fees kasama ang Category Details
    $stmtFees = $pdo->query("
        SELECT f.fee_id, f.fee_name, f.default_amount, f.is_required, c.category_id, c.category_name
        FROM fees f
        JOIN fee_categories c ON f.category_id = c.category_id
        WHERE f.status = 'Active' AND c.status = 'Active'
        ORDER BY c.priority_order ASC, f.fee_name ASC
    ");
    $activeFees = $stmtFees->fetchAll(PDO::FETCH_ASSOC);

    $categoryBillingOptions = $pdo->query("SELECT c.category_id, c.category_name, COUNT(f.fee_id) AS fee_count, COALESCE(SUM(f.default_amount), 0) AS total_amount FROM fee_categories c JOIN fees f ON f.category_id = c.category_id AND f.status = 'Active' AND f.default_amount > 0 WHERE c.status = 'Active' GROUP BY c.category_id, c.category_name, c.priority_order ORDER BY c.priority_order ASC, c.category_name ASC")->fetchAll(PDO::FETCH_ASSOC);

    // Grouping logic para sa Modal
    foreach ($activeFees as $fee) {
        $cat = $fee['category_name'];
        if (!isset($groupedActiveFees[$cat])) {
            $groupedActiveFees[$cat] = [];
        }
        $groupedActiveFees[$cat][] = $fee;
    }

} catch (PDOException $e) {
    $billingList = []; $activeFees = []; $groupedActiveFees = []; $categoryBillingOptions = []; $totalReceivables = 0; $unpaidCount = 0; $paidCount = 0;
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
            <h2 class="mb-1 fw-bolder"><i class="ti ti-file-invoice text-primary me-2"></i>Billing & Invoicing</h2>
            <p class="text-muted mb-0 fs-6">Generate and manage student statement of accounts (SOA).</p>
        </div>
        <div class="col-md-6 text-md-end mt-3 mt-md-0">
            <div class="d-flex justify-content-md-end gap-2">
                <div class="input-group w-auto shadow-sm">
                    <span class="input-group-text bg-white border-end-0"><i class="ti ti-search text-muted"></i></span>
                    <input type="text" class="form-control border-start-0 ps-0 table-live-search-input" data-table-target="#billingTable" placeholder="Search student no...">
                </div>
                <?php if (in_array(getCurrentUserRoleKey(), ['accounting_officer', 'superadmin'], true)): ?>
                    <button type="button" class="btn btn-primary shadow-sm fw-bold px-4" data-bs-toggle="modal" data-bs-target="#bulkBillingModal">
                        <i class="fas fa-layer-group me-1"></i> Bulk Generate by Year Level
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <h5 class="fw-bold">Bulk Billing Runs</h5>
            <p class="text-muted small">Accounting selects an active fee category, term, and year level. Every active fee in the category is applied to enrolled students in that year level, with one combined student notice for newly added billing items.</p>
            <?php if (!$feeWorkflowAvailable): ?>
                <div class="alert alert-warning mb-0">Disabled until the approved Payment workflow migration is installed.</div>
            <?php else: ?>
                <div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Fee Category</th><th>Term</th><th>Cohort</th><th>Status</th><th>Assigned</th><th>Failed</th><th></th></tr></thead><tbody>
                    <?php foreach ($feeCampaigns as $campaign): ?>
                        <tr><td><?= htmlspecialchars($campaign['category_name_snapshot'] ?: $campaign['fee_name_snapshot']) ?> (₱<?= number_format((float) $campaign['amount_snapshot'], 2) ?>)</td><td><?= htmlspecialchars($campaign['academic_year'] . ' / ' . $campaign['semester']) ?></td><td><?= htmlspecialchars(($campaign['course'] ?: 'All courses') . ' / ' . ($campaign['year_level'] ?: 'All levels')) ?></td><td><?= htmlspecialchars($campaign['status']) ?></td><td><?= (int) $campaign['added_count'] ?></td><td><?= (int) $campaign['failed_count'] ?></td><td><a class="btn btn-sm btn-outline-primary" href="?campaign_id=<?= (int) $campaign['campaign_id'] ?>">Review</a></td></tr>
                    <?php endforeach; ?>
                </tbody></table></div>
                <?php if ($selectedCampaign): ?>
                    <div class="border rounded p-3 bg-light">
                        <h6 class="fw-bold mb-2">Campaign #<?= (int) $selectedCampaign['campaign_id'] ?> — <?= htmlspecialchars($selectedCampaign['category_name_snapshot'] ?: $selectedCampaign['fee_name_snapshot']) ?></h6>
                        <?php if ($feePreview): ?>
                            <p class="mb-2">Eligibility rule: <strong>status = Enrolled</strong> · Eligible: <strong><?= number_format($feePreview['eligible']) ?></strong> · Excluded: <strong><?= number_format($feePreview['excluded']) ?></strong> · Already assigned: <strong><?= number_format($feePreview['already_assigned']) ?></strong> · Projected new billing: <strong>₱<?= number_format($feePreview['projected_amount'], 2) ?></strong></p>
                            <details class="mb-3"><summary>View first eligible students</summary><ul><?php foreach ($feePreview['students'] as $student): ?><li><?= htmlspecialchars($student['student_number'] . ' — ' . $student['full_name'] . ' (' . $student['course'] . ', ' . $student['year_level'] . ')') ?></li><?php endforeach; ?></ul></details>
                            <?php if ($feeWorkflow->generationEnabled()): ?>
                                <form method="post" class="d-inline" onsubmit="return confirm('Start bulk billing for this enrolled year level?');"><?= csrfField(); ?><input type="hidden" name="campaign_id" value="<?= (int) $campaignId ?>"><button name="fee_campaign_action" value="start" class="btn btn-primary">Start Bulk Billing</button></form>
                            <?php else: ?>
                                <div class="alert alert-warning mt-2 mb-0">Generation remains locked until the student course/year source and Payment database migration are verified.</div>
                            <?php endif; ?>
                        <?php elseif ($selectedCampaign['status'] === 'Running'): ?>
                            <p>Added <?= (int) $selectedCampaign['added_count'] ?> · Already assigned <?= (int) $selectedCampaign['existing_count'] ?> · Failed <?= (int) $selectedCampaign['failed_count'] ?></p>
                            <button type="button" id="resumeFeeRun" class="btn btn-primary">Process / Resume Billing</button><span id="feeRunProgress" class="ms-2 text-muted"></span>
                        <?php else: ?>
                            <p class="mb-0">Status: <?= htmlspecialchars($selectedCampaign['status']) ?>. Added <?= (int) $selectedCampaign['added_count'] ?>; existing <?= (int) $selectedCampaign['existing_count'] ?>; failed <?= (int) $selectedCampaign['failed_count'] ?>.</p>
                            <?php if ($selectedCampaign['status'] === 'Completed' && (int) $selectedCampaign['failed_count'] > 0 && $feeWorkflow->generationEnabled()): ?>
                                <form method="post" class="mt-2"><?= csrfField(); ?><input type="hidden" name="campaign_id" value="<?= (int) $campaignId ?>"><button name="fee_campaign_action" value="retry_failed" class="btn btn-outline-warning">Retry failed students</button></form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($selectedCampaign && $selectedCampaign['status'] === 'Running'): ?>
    <script>
    document.getElementById('resumeFeeRun')?.addEventListener('click', async function () {
        this.disabled = true;
        const progress = document.getElementById('feeRunProgress');
        const form = new FormData();
        form.set('csrf_token', <?= json_encode(csrfToken()) ?>);
        form.set('campaign_id', <?= (int) $campaignId ?>);
        form.set('fee_campaign_action', 'chunk');
        try {
            while (true) {
                const response = await fetch(location.pathname, {method: 'POST', body: form, credentials: 'same-origin', headers: {'Accept': 'application/json'}});
                const result = await response.json();
                if (!response.ok || !result.ok) throw new Error(result.error || 'Processing stopped');
                progress.textContent = `Added ${result.added}, already assigned ${result.existing}, failed ${result.failed}`;
                if (result.status === 'Completed') { location.reload(); return; }
            }
        } catch (error) { progress.textContent = error.message + '. You can safely resume.'; this.disabled = false; }
    });
    <?php if (isset($_GET['bulk_started'])): ?>
    document.getElementById('resumeFeeRun')?.click();
    <?php endif; ?>
    </script>
    <?php endif; ?>

    <!-- Alerts -->
    <?php if (isset($_GET['success']) && $_GET['success'] == 1): ?>
        <div class="alert alert-success shadow-sm"><i class="ti ti-circle-check me-2"></i> Billing generated successfully!</div>
    <?php endif; ?>
    <?php if (isset($_GET['success']) && $_GET['success'] == 'append' && isset($_SESSION['append_result'])): ?>
        <?php $res = $_SESSION['append_result']; ?>
        <div class="alert alert-success shadow-sm">
            <h6 class="alert-heading fw-bold mb-2"><i class="ti ti-circle-check me-2"></i> Billing Updated Successfully</h6>
            <div class="row">
                <div class="col-md-6">
                    <strong>Added:</strong>
                    <?php if(empty($res['added'])): ?>
                        <div class="text-muted small">No new fees added.</div>
                    <?php else: ?>
                        <ul class="mb-2 small">
                            <?php foreach($res['added'] as $add): ?>
                                <li><i class="fas fa-check text-success me-1"></i> <?= htmlspecialchars($add['fee_name']) ?> — ₱ <?= number_format($add['amount'], 2) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
                <div class="col-md-6">
                    <strong>Skipped:</strong>
                    <?php if(empty($res['skipped'])): ?>
                        <div class="text-muted small">None.</div>
                    <?php else: ?>
                        <ul class="mb-2 small text-danger">
                            <?php foreach($res['skipped'] as $feeName => $reason): ?>
                                <li><i class="ti ti-alert-triangle me-1"></i> <?= htmlspecialchars($feeName) ?> — <span class="text-muted"><?= htmlspecialchars($reason) ?></span></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
            <hr class="my-2 opacity-25">
            <div class="d-flex gap-3 small fw-bold">
                <span>New Total: ₱ <?= number_format($res['new_total'], 2) ?></span>
                <span class="text-primary">Remaining: ₱ <?= number_format($res['new_remaining'], 2) ?></span>
            </div>
        </div>
        <?php unset($_SESSION['append_result']); ?>
    <?php endif; ?>
    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger shadow-sm"><i class="ti ti-alert-triangle me-2"></i> <?= htmlspecialchars($_GET['error']) ?></div>
    <?php endif; ?>
    <?php if (isset($dbError)): ?>
        <div class="alert alert-danger shadow-sm"><i class="ti ti-alert-triangle me-2"></i> Database Error: <?= htmlspecialchars($dbError) ?></div>
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
                                            <i class="ti ti-eye me-1"></i> View
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
<!-- BULK BILLING BY YEAR LEVEL -->
<!-- ========================================== -->
<div class="modal fade" id="bulkBillingModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white border-0">
                <h5 class="modal-title fw-bold"><i class="fas fa-layer-group me-2"></i>Bulk Generate Billing</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <?= csrfField(); ?>
                <div class="modal-body bg-light">
                    <p class="text-muted small">This applies every active fee in one category to students marked <strong>Enrolled</strong> in the selected year level. Existing fee items are skipped and students receive one combined notification for new items.</p>
                    <div class="mb-3"><label class="form-label fw-bold">Fee Category</label><select name="category_id" class="form-select" required><option value="">Select active fee category</option><?php foreach ($categoryBillingOptions as $category): ?><option value="<?= (int) $category['category_id'] ?>"><?= htmlspecialchars($category['category_name']) ?> — <?= (int) $category['fee_count'] ?> fee(s), ₱<?= number_format((float) $category['total_amount'], 2) ?></option><?php endforeach; ?></select></div>
                    <details class="mb-3 small"><summary class="fw-semibold">View active fees included in each category</summary><ul class="mb-0 mt-2"><?php foreach ($categoryBillingOptions as $category): ?><li><strong><?= htmlspecialchars($category['category_name']) ?></strong> (₱<?= number_format((float) $category['total_amount'], 2) ?>): <?php $names = []; foreach ($activeFees as $fee) { if ((int) $fee['category_id'] === (int) $category['category_id']) $names[] = $fee['fee_name']; } echo htmlspecialchars(implode(', ', $names)); ?></li><?php endforeach; ?></ul></details>
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label fw-bold">Academic Year</label><input name="academic_year" class="form-control" pattern="[0-9]{4}-[0-9]{4}" value="2026-2027" required></div>
                        <div class="col-md-6"><label class="form-label fw-bold">Semester</label><select name="semester" class="form-select" required><option value="1st">1st Semester</option><option value="2nd">2nd Semester</option><option value="Summer">Summer</option></select></div>
                        <div class="col-12"><label class="form-label fw-bold">Target Year Level</label><select name="year_level" class="form-select" required><option value="">Select year level</option><option value="1">1st Year</option><option value="2">2nd Year</option><option value="3">3rd Year</option><option value="4">4th Year</option></select></div>
                    </div>
                </div>
                <div class="modal-footer border-0"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button name="start_bulk_billing" value="1" class="btn btn-primary">Generate Billing</button></div>
            </form>
        </div>
    </div>
</div>

<!-- Individual billing remains available for exceptional/manual adjustments. -->
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
                            <span class="input-group-text bg-white"><i class="ti ti-school text-muted"></i></span>
                            <input type="text" class="form-control border-start-0" id="studentSearchInput" name="student_number" placeholder="e.g. S230106713" required autocomplete="off">
                        </div>
                        <small id="studentNameHint" class="mt-1 d-block text-muted" style="min-height: 20px;"></small>
                    </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold text-dark">Billing Type <span class="text-danger">*</span></label>
                            <select class="form-select" name="billing_type" id="billingTypeSelect" required>
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
                            <h6 class="fw-bold mb-0 text-primary"><i class="ti ti-list-check me-2"></i>Select Applicable Fees</h6>
                            <div class="input-group input-group-sm w-50 shadow-sm">
                                <span class="input-group-text bg-white border-end-0"><i class="ti ti-search text-muted"></i></span>
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
                                                            <input class="form-check-input fee-checkbox" type="checkbox" name="selected_fees[]" value="<?= $fee['fee_id'] ?>" data-amount="<?= $fee['default_amount'] ?>" data-required="<?= $fee['is_required'] ? '1' : '0' ?>" id="fee_<?= $fee['fee_id'] ?>" <?= $fee['is_required'] ? 'checked' : '' ?>>
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
                                    <i class="ti ti-alert-circle mb-2 fs-4"></i><br>
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
<script src="<?= BASE_URL ?>/modules/payment/assets/js/payment-search.js"></script>

<?php require_once __DIR__ . '/../../../../includes/layout-end.php'; ?>
