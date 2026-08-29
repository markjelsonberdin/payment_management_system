<?php
/**
 * SMS 2 - Student Portal: Payment Concern Portal
 * Standalone page for submitting payment issues and uploading receipts.
 */

// 1. Core Configurations & Authentication
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../../payment/database/db_connect.php';
require_once __DIR__ . '/../../payment/includes/PaymentConcernService.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once ROOT_PATH . '/includes/breadcrumbs.php';

// 2. Page Meta Setup
$pageTitle = 'Payment Concern Portal';
$activeModule = 'student_portal';
$activePage = 'student-concern-portal';
$breadcrumbs = [
    ['label' => 'Student Portal', 'url' => BASE_URL . '/modules/student-portal/pages/my-profile.php'],
    ['label' => 'Payment History', 'url' => 'payment-history.php'],
    ['label' => 'Payment Concern Portal', 'url' => null],
];

$studentId = $_SESSION['student_id'] ?? 'S230106713';
$successMsg = '';
$errorMsg = '';

// ==========================================
// HANDLE CONCERN SUBMISSION (POST)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_concern'])) {
    
    // CSRF Validation
    if (!csrfVerify()) {
        $errorMsg = "Security Error: Invalid session or CSRF token.";
    } else {
        $issue_type = trim($_POST['issue_type']);
        $reference_no = trim($_POST['reference_no']);
        $remarks = trim($_POST['remarks']);
        
        // File Upload Handling
        if (isset($_FILES['receipt_image']) && $_FILES['receipt_image']['error'] === UPLOAD_ERR_OK) {
            $fileTmpPath = $_FILES['receipt_image']['tmp_name'];
            $fileName = $_FILES['receipt_image']['name'];
            $fileSize = $_FILES['receipt_image']['size'];
            $fileType = $_FILES['receipt_image']['type'];

            // Allowed extensions and MIME types
            $allowedMimeTypes = ['image/jpeg', 'image/png', 'application/pdf'];
            $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf'];

            if (!in_array($fileType, $allowedMimeTypes) || !in_array($fileExtension, $allowedExtensions)) {
                $errorMsg = "Invalid file type. Only JPG, PNG, and PDF files are allowed.";
            } elseif ($fileSize > 2 * 1024 * 1024) { // 2MB limit
                $errorMsg = "File size exceeds the 2MB limit.";
            } else {
                // Secure file renaming to prevent overriding or malicious script execution
                $newFileName = md5(time() . $fileName) . '.' . $fileExtension;
                $uploadFileDir = ROOT_PATH . '/uploads/receipts/';
                
                if (!is_dir($uploadFileDir)) {
                    mkdir($uploadFileDir, 0755, true);
                }
                
                $dest_path = $uploadFileDir . $newFileName;
                $relativePath = 'uploads/receipts/' . $newFileName;

                if (move_uploaded_file($fileTmpPath, $dest_path)) {
                    try {
                        global $pdo;
                        $concernService = new PaymentConcernService($pdo);
                        $concernId = $concernService->submitConcern(
                            $studentId,
                            $issue_type,
                            $reference_no,
                            $remarks,
                            $relativePath
                        );

                        // Save OCR Results if provided by frontend
                        $ocrRawText = $_POST['ocr_raw_text'] ?? null;
                        if (!empty($ocrRawText)) {
                            $ocrAmount = $_POST['ocr_amount'] ?? null;
                            $ocrRef = $_POST['ocr_reference'] ?? null;
                            
                            $stmtOcr = $pdo->prepare("
                                INSERT INTO payment_db.ocr_results 
                                (concern_id, extracted_amount, reference_number, raw_json) 
                                VALUES (?, ?, ?, ?)
                            ");
                            $stmtOcr->execute([
                                $concernId, 
                                $ocrAmount ? (float)$ocrAmount : null, 
                                $ocrRef, 
                                json_encode(['text' => $ocrRawText])
                            ]);
                        }

                        $successMsg = "Your support ticket #$concernId has been submitted successfully!";
                    } catch (Exception $e) {
                        $errorMsg = "Submission Error: " . $e->getMessage();
                    }
                } else {
                    $errorMsg = "Error moving the uploaded file to destination folder.";
                }
            }
        } else {
            $errorMsg = "Please upload a valid receipt or screenshot.";
        }
    }
}

// 3. Database Fetch Logic for existing concerns
$myConcerns = [];

try {
    global $pdo;
    
    if ($pdo) {
        $stmtStud = $pdo->prepare("SELECT student_id FROM payment_db.students WHERE student_number = :snum LIMIT 1");
        $stmtStud->execute([':snum' => $studentId]);
        $studRow = $stmtStud->fetch(PDO::FETCH_ASSOC);
        
        if ($studRow) {
            $concernService = new PaymentConcernService($pdo);
            $myConcerns = $concernService->getStudentConcerns($studRow['student_id']);
        }
    }
} catch (PDOException $e) {
    // Silently handle errors
}

// 4. Load Header
require_once ROOT_PATH . '/includes/layout-start.php';
?>

<?php renderBreadcrumbs($breadcrumbs); ?>

<div class="student-portal">
    
    <div class="page-header student-portal-header d-flex justify-content-between align-items-center mb-4">
        <div>
            <span class="student-kicker text-uppercase text-danger fw-bold small">Support Helpdesk</span>
            <h2 class="fw-bolder m-0"><i class="fas fa-headset text-danger me-2"></i>Payment Concern Portal</h2>
            <p class="text-muted m-0 mt-1">Submit missing payments or report issues regarding your transactions.</p>
        </div>
    </div>

    <?php if (!empty($successMsg)): ?>
        <div class="alert alert-success shadow-sm border-0"><i class="fas fa-check-circle me-2"></i> <?= htmlspecialchars($successMsg) ?></div>
    <?php endif; ?>
    <?php if (!empty($errorMsg)): ?>
        <div class="alert alert-danger shadow-sm border-0"><i class="fas fa-exclamation-triangle me-2"></i> <?= htmlspecialchars($errorMsg) ?></div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Submission Form (Left Side) -->
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm rounded-3">
                <div class="card-header bg-white border-bottom p-4">
                    <h5 class="fw-bold m-0"><i class="fas fa-file-upload text-primary me-2"></i>Submit a Concern</h5>
                </div>
                <div class="card-body p-4">
                    <form action="" method="POST" enctype="multipart/form-data">
                        <?= csrfField(); ?>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold small text-muted">Issue Type</label>
                            <select class="form-select shadow-sm" name="issue_type" required>
                                <option value="">Select an issue...</option>
                                <option value="missing_payment">Paid via GCash/Maya but still Pending</option>
                                <option value="wrong_amount">Amount reflected is incorrect</option>
                                <option value="bank_transfer">Manual Bank Transfer verification</option>
                                <option value="other">Other</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold small text-muted">Reference Number (Optional)</label>
                            <input type="text" class="form-control shadow-sm" name="reference_no" id="refNoInput" placeholder="e.g. 000123456789">
                            <small class="text-muted" style="font-size: 0.75rem;">If you have a reference number from your text/email receipt.</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold small text-muted">
                                Upload Receipt / Screenshot 
                                <span id="ocrStatusBadge" class="badge bg-secondary ms-2 d-none"></span>
                            </label>
                            <input type="file" class="form-control shadow-sm" name="receipt_image" id="receiptImageInput" accept="image/png, image/jpeg, application/pdf" required>
                            <small class="text-muted" style="font-size: 0.75rem;">Accepted formats: JPG, PNG, PDF. Max size: 2MB.</small>
                        </div>
                        
                        <!-- Hidden fields for OCR Results -->
                        <input type="hidden" name="ocr_raw_text" id="ocrRawText">
                        <input type="hidden" name="ocr_amount" id="ocrAmount">
                        <input type="hidden" name="ocr_reference" id="ocrReference">

                        <div class="mb-4">
                            <label class="form-label fw-bold small text-muted">Additional Remarks</label>
                            <textarea class="form-control shadow-sm" name="remarks" rows="3" placeholder="Provide more details about your payment issue..."></textarea>
                        </div>

                        <button type="submit" name="submit_concern" class="btn btn-primary w-100 fw-bold py-2 shadow-sm">
                            <i class="fas fa-paper-plane me-2"></i>Submit Ticket
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- History of Concerns (Right Side) -->
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm rounded-3 h-100">
                <div class="card-header bg-white border-bottom p-4">
                    <h5 class="fw-bold m-0"><i class="fas fa-clipboard-list text-secondary me-2"></i>My Support Tickets</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light text-muted" style="font-size: 0.75rem;">
                                <tr>
                                    <th class="py-3 ps-4">DATE SUBMITTED</th>
                                    <th class="py-3">TICKET INFO</th>
                                    <th class="py-3">STATUS</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($myConcerns) > 0): ?>
                                    <?php foreach ($myConcerns as $concern): ?>
                                        <?php
                                            $statusBg = match($concern['verification_status']) {
                                                'Verified' => '#bbf7d0',
                                                'Pending' => '#fef08a',
                                                'Rejected' => '#fecaca',
                                                default => '#e5e7eb'
                                            };
                                            $statusText = match($concern['verification_status']) {
                                                'Verified' => '#15803d',
                                                'Pending' => '#b45309',
                                                'Rejected' => '#b91c1c',
                                                default => '#374151'
                                            };
                                        ?>
                                        <tr class="border-bottom">
                                            <td class="py-3 ps-4 text-dark">
                                                <div class="fw-bold"><?= date('M d, Y', strtotime($concern['submitted_at'])) ?></div>
                                                <small class="text-muted" style="font-size: 0.75rem;"><?= date('h:i A', strtotime($concern['submitted_at'])) ?></small>
                                            </td>
                                            <td class="py-3">
                                                <div class="fw-bold text-dark">Ref: <?= htmlspecialchars($concern['reference_number'] ?? 'N/A') ?></div>
                                                <small class="text-muted" style="font-size: 0.8rem;"><?= htmlspecialchars(substr($concern['remarks'], 0, 30)) ?>...</small>
                                            </td>
                                            <td class="py-3">
                                                <span class="badge rounded-pill fw-bold" style="background-color: <?= $statusBg ?>; color: <?= $statusText ?>;">
                                                    <?= htmlspecialchars($concern['verification_status']) ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="3" class="py-5 text-center text-muted">
                                            <i class="fas fa-check-circle fs-3 mb-2 text-success opacity-50 d-block"></i>
                                            You have no active payment concerns.
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

<!-- 5. Load Footer -->
<?php require_once ROOT_PATH . '/includes/layout-end.php'; ?>

<script>
document.getElementById('receiptImageInput').addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (!file) return;

    // Only process images for OCR (skip PDFs for this simple implementation)
    if (!file.type.startsWith('image/')) return;

    const badge = document.getElementById('ocrStatusBadge');
    badge.className = 'badge bg-primary ms-2';
    badge.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Scanning AI...';
    badge.classList.remove('d-none');

    const formData = new FormData();
    formData.append('receipt', file);
    // Assumes csrfToken() generates a valid token, or we grab it from the form
    const formCsrf = document.querySelector('input[name="csrf_token"]');
    formData.append('csrf_token', formCsrf ? formCsrf.value : "<?= function_exists('csrfToken') ? csrfToken() : '' ?>");

    fetch("<?= BASE_URL ?>/modules/student-portal/api/ocr/process-receipt.php", {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            badge.className = 'badge bg-success ms-2';
            badge.innerHTML = '<i class="fas fa-check"></i> Scanned';
            
            // Populate hidden fields
            document.getElementById('ocrRawText').value = data.raw_text || '';
            document.getElementById('ocrAmount').value = data.amount || '';
            document.getElementById('ocrReference').value = data.reference_number || '';
            
            // Auto-fill Reference Number if empty
            const refInput = document.getElementById('refNoInput');
            if (!refInput.value && data.reference_number) {
                refInput.value = data.reference_number;
            }
            
            // Optional: Auto-fill remarks with Amount if found
            if (data.amount) {
                const remarksInput = document.querySelector('textarea[name="remarks"]');
                if (!remarksInput.value.includes('Amount')) {
                    const prefix = remarksInput.value ? remarksInput.value + '\n' : '';
                    remarksInput.value = prefix + 'Extracted Amount: PHP ' + data.amount;
                }
            }
        } else {
            badge.className = 'badge bg-warning text-dark ms-2';
            badge.innerHTML = '<i class="fas fa-exclamation-triangle"></i> OCR Failed';
            console.warn("OCR Failed:", data.error, data.message);
        }
    })
    .catch(err => {
        badge.className = 'badge bg-secondary ms-2';
        badge.innerHTML = 'OCR Unavailable';
        console.error("Network error during OCR:", err);
    });
});
</script>