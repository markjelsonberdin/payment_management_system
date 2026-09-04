<?php
/**
 * SMS 2 - Bank Reconciliation Portal
 * Module: Payment Management
 */
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../includes/authentication.php';
require_once __DIR__ . '/../../../../includes/audit.php';
require_once __DIR__ . '/../../database/db_connect.php';

requireAuth();
// Assuming same permission as concern review for accounting
requirePaymentPermission('payment.concern_review');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Fetch all uploaded statements
try {
    $stmt = $pdo->query("
        SELECT s.*, u.username as uploaded_by_name 
        FROM bank_statements s 
        LEFT JOIN users u ON s.uploaded_by = u.user_id 
        ORDER BY s.uploaded_at DESC
    ");
    $statements = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $statements = [];
    $dbError = $e->getMessage();
}

$pageTitle    = 'Bank Reconciliation';
$activeModule = 'payment';
$activePage   = 'accounting/bank-reconciliation';
$breadcrumbs  = [
    ['label' => 'Payment Management', 'url' => BASE_URL . '/modules/payment/index.php'],
    ['label' => 'Bank Reconciliation', 'url' => null],
];

require_once __DIR__ . '/../../../../includes/breadcrumbs.php';
require_once __DIR__ . '/../../../../includes/layout-start.php';
?>

<?php renderBreadcrumbs($breadcrumbs); ?>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="row mb-4 align-items-center">
        <div class="col-md-8">
            <h2 class="mb-1 fw-bolder"><i class="fas fa-university text-primary me-2"></i>Bank Reconciliation</h2>
            <p class="text-muted mb-0 fs-6">Upload AUB CSV statements to automatically verify student payment concerns.</p>
        </div>
        <div class="col-md-4 text-md-end mt-3 mt-md-0">
            <a href="payment-concern-portal.php" class="btn btn-outline-secondary shadow-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to Concerns
            </a>
        </div>
    </div>

    <!-- Alert Container for AJAX Responses -->
    <div id="alertContainer"></div>

    <div class="row g-4">
        <!-- Upload Card -->
        <div class="col-lg-4">
            <div class="card shadow-sm border-0 rounded-4">
                <div class="card-header bg-white border-0 pt-4 pb-0">
                    <h6 class="fw-bold mb-0"><i class="fas fa-cloud-upload-alt text-primary me-2"></i>Upload Statement</h6>
                </div>
                <div class="card-body">
                    <form id="uploadCsvForm" enctype="multipart/form-data">
                        <?= csrfField(); ?>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">Bank Name</label>
                            <select class="form-select bg-light" name="bank_name" disabled>
                                <option value="AUB" selected>AUB (Asia United Bank)</option>
                            </select>
                            <small class="text-muted" style="font-size: 0.7rem;">Currently only AUB format is supported.</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted">CSV File</label>
                            <input type="file" class="form-control" name="statement_file" accept=".csv" required>
                        </div>
                        <button type="submit" class="btn btn-primary w-100 fw-bold shadow-sm" id="btnUpload">
                            <i class="fas fa-upload me-1"></i> Import Records
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- History Card -->
        <div class="col-lg-8">
            <div class="card shadow-sm border-0 rounded-4 overflow-hidden h-100">
                <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold mb-0"><i class="fas fa-history text-secondary me-2"></i>Upload History</h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 text-nowrap">
                            <thead class="bg-light text-uppercase text-secondary" style="font-size: 0.75rem; letter-spacing: 0.5px;">
                                <tr>
                                    <th class="ps-4 py-3">File Name</th>
                                    <th class="py-3">Uploaded By</th>
                                    <th class="py-3">Date Uploaded</th>
                                    <th class="py-3 text-center">Rows</th>
                                    <th class="py-3 text-center">Status</th>
                                </tr>
                            </thead>
                            <tbody class="border-top-0">
                                <?php if (count($statements) > 0): ?>
                                    <?php foreach ($statements as $stmt): ?>
                                        <tr>
                                            <td class="ps-4 fw-bold text-dark">
                                                <i class="fas fa-file-csv text-success me-2"></i><?= htmlspecialchars($stmt['filename']) ?>
                                            </td>
                                            <td class="text-muted small"><?= htmlspecialchars($stmt['uploaded_by_name'] ?? 'System') ?></td>
                                            <td class="text-muted small"><?= date('M d, Y h:i A', strtotime($stmt['uploaded_at'])) ?></td>
                                            <td class="text-center fw-bold text-primary"><?= number_format($stmt['row_count']) ?></td>
                                            <td class="text-center">
                                                <?php if($stmt['status'] === 'Processed'): ?>
                                                    <span class="badge bg-success rounded-pill px-3">Processed</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary rounded-pill px-3"><?= htmlspecialchars($stmt['status']) ?></span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" class="py-5 text-center text-muted">
                                            <i class="fas fa-folder-open fs-3 mb-2 text-secondary opacity-50 d-block"></i>
                                            No bank statements uploaded yet.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('uploadCsvForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const btn = document.getElementById('btnUpload');
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Uploading...';
    btn.disabled = true;

    const formData = new FormData(this);

    fetch("<?= BASE_URL ?>/modules/payment/api/accounting/import-bank-statement.php", {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showAlert('success', 'Bank statement imported successfully! Reloading...');
            setTimeout(() => window.location.reload(), 1500);
        } else {
            showAlert('danger', 'Error: ' + data.message);
            btn.innerHTML = originalText;
            btn.disabled = false;
        }
    })
    .catch(error => {
        showAlert('danger', 'Network error occurred while uploading.');
        console.error(error);
        btn.innerHTML = originalText;
        btn.disabled = false;
    });
});

function showAlert(type, message) {
    const container = document.getElementById('alertContainer');
    container.innerHTML = `
        <div class="alert alert-${type} alert-dismissible shadow-sm">
            <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'} me-2"></i> ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    `;
}
</script>

<?php require_once __DIR__ . '/../../../../includes/layout-end.php'; ?>
