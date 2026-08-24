<?php
/**
 * SMS 2 - Payment Management Module (Admin Setup)
 * PURPOSE: Manage Master List of Fees using Category-based Hierarchy.
 */
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../includes/authentication.php';
require_once __DIR__ . '/../../../../includes/audit.php';
require_once __DIR__ . '/../../database/db_connect.php';


requireAuth();
requirePaymentPermission('payment.fee_setup');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

global $pdo;

// ==========================================
// CSRF VALIDATION
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
}
// ==========================================
// 1. ADD NEW FEE
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_fee'])) {
    $fee_name = trim($_POST['fee_name']);
    $category_id = (int) $_POST['category_id'];
    $default_amount = (float) $_POST['default_amount'];
    $is_required = (int) $_POST['is_required'];

    try {
        // Insert gamit ang bagong schema (walang priority_order, category_id ang gamit)
        $sql = "INSERT INTO fees (fee_name, category_id, default_amount, is_required, status) 
                VALUES (:name, :category, :amount, :required, 'Active')";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':name'     => $fee_name,
            ':category' => $category_id,
            ':amount'   => $default_amount,
            ':required' => $is_required
        ]);

        header("Location: fee-setup-configuration.php?success=1");
        exit();
    } catch (PDOException $e) {
        header("Location: fee-setup-configuration.php?error=" . urlencode($e->getMessage()));
        exit();
    }
}

// ==========================================
// 2. EDIT EXISTING FEE
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_fee'])) {
    $fee_id = (int) $_POST['edit_fee_id'];
    $fee_name = trim($_POST['fee_name']);
    $category_id = (int) $_POST['category_id'];
    $default_amount = (float) $_POST['default_amount'];
    $is_required = (int) $_POST['is_required'];

    try {
        $stmt = $pdo->prepare("UPDATE fees SET fee_name = :name, category_id = :category, default_amount = :amount, is_required = :required WHERE fee_id = :id");
        $stmt->execute([
            ':name'     => $fee_name,
            ':category' => $category_id,
            ':amount'   => $default_amount,
            ':required' => $is_required,
            ':id'       => $fee_id
        ]);
        header("Location: fee-setup-configuration.php?success=edited");
        exit();
    } catch (PDOException $e) {
        header("Location: fee-setup-configuration.php?error=" . urlencode($e->getMessage()));
        exit();
    }
}

// ==========================================
// 3. ARCHIVE ACTIONS
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['archive_fee'])) {
    $fee_id = (int) $_POST['fee_id'];
    try {
        $pdo->prepare("UPDATE fees SET status = 'Inactive' WHERE fee_id = :fee_id")->execute([':fee_id' => $fee_id]);
        header("Location: fee-setup-configuration.php?success=archived");
        exit();
    } catch (PDOException $e) {
        header("Location: fee-setup-configuration.php?error=" . urlencode($e->getMessage()));
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['archive_category'])) {
    $category_id = (int) $_POST['category_id'];
    try {
        $stmt = $pdo->prepare("UPDATE fees SET status = 'Inactive' WHERE category_id = :category_id AND status = 'Active'");
        $stmt->execute([':category_id' => $category_id]);
        header("Location: fee-setup-configuration.php?success=archived_category&count=" . $stmt->rowCount());
        exit();
    } catch (PDOException $e) {
        header("Location: fee-setup-configuration.php?error=" . urlencode($e->getMessage()));
        exit();
    }
}

// ==========================================
// 4. RESTORE & PERMANENT DELETE
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_fee'])) {
    $fee_id = (int) $_POST['fee_id'];
    try {
        $pdo->prepare("UPDATE fees SET status = 'Active' WHERE fee_id = :fee_id")->execute([':fee_id' => $fee_id]);
        header("Location: fee-setup-configuration.php?success=restored");
        exit();
    } catch (Exception $e) {
        header("Location: fee-setup-configuration.php?error=" . urlencode($e->getMessage()));
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_fee'])) {
    $fee_id = (int) $_POST['fee_id'];
    try {
        $pdo->prepare("DELETE FROM fees WHERE fee_id = :fee_id")->execute([':fee_id' => $fee_id]);
        header("Location: fee-setup-configuration.php?success=deleted");
        exit();
    } catch (PDOException $e) {
        header("Location: fee-setup-configuration.php?error=" . urlencode($e->getMessage()));
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_all_archived'])) {
    try {
        $stmtDeleteAll = $pdo->prepare("DELETE FROM fees WHERE status = 'Inactive'");
        $stmtDeleteAll->execute();
        header("Location: fee-setup-configuration.php?success=deleted_all&count=" . $stmtDeleteAll->rowCount());
        exit();
    } catch (PDOException $e) {
        header("Location: fee-setup-configuration.php?error=" . urlencode($e->getMessage()));
        exit();
    }
}

// ==========================================
// FETCH MASTER DATA (READ)
// ==========================================
$groupedFees = [];
$archivedFeesList = [];
$categories = [];

try {
    // Kunin ang active categories para sa dropdown form
    $stmtCats = $pdo->query("SELECT * FROM fee_categories WHERE status = 'Active' ORDER BY priority_order ASC");
    $categories = $stmtCats->fetchAll(PDO::FETCH_ASSOC);

    // Fetch active fees (Naka-join sa categories para makuha ang pangalan at priority order)
    // Mapapansin mo na naka-sort by priority order muna, bago by fee_name alphabetically
    $stmt = $pdo->query("
        SELECT f.*, c.category_name, c.priority_order 
        FROM fees f 
        JOIN fee_categories c ON f.category_id = c.category_id 
        WHERE f.status = 'Active' 
        ORDER BY c.priority_order ASC, f.fee_name ASC
    ");
    $rawFeesList = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group fees by category_name
    foreach ($rawFeesList as $fee) {
        $catName = $fee['category_name'];
        if (!isset($groupedFees[$catName])) {
            $groupedFees[$catName] = [
                'category_id' => $fee['category_id'],
                'total_amount' => 0,
                'items' => []
            ];
        }
        $groupedFees[$catName]['items'][] = $fee;
        $groupedFees[$catName]['total_amount'] += $fee['default_amount'];
    }

    // Fetch archived fees
    $stmtArchived = $pdo->query("
        SELECT f.*, c.category_name 
        FROM fees f 
        LEFT JOIN fee_categories c ON f.category_id = c.category_id 
        WHERE f.status = 'Inactive' 
        ORDER BY f.fee_name ASC
    ");
    $archivedFeesList = $stmtArchived->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Error fetching database: " . $e->getMessage());
}

$pageTitle    = 'Fee Setup & Configuration';
$activeModule = 'payment';
$activePage   = 'fee-setup-configuration';
$breadcrumbs  = [
    ['label' => 'Payment Management', 'url' => BASE_URL . '/modules/payment/index.php'],
    ['label' => 'Fee Setup & Configuration', 'url' => null],
];

require_once __DIR__ . '/../../../../includes/breadcrumbs.php';
require_once __DIR__ . '/../../../../includes/layout-start.php';
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">

<?php renderBreadcrumbs($breadcrumbs); ?>

<div class="container-fluid py-4">
    
    <div class="row mb-4 align-items-center">
        <div class="col-md-6">
            <h2 class="mb-1 fw-bolder"><i class="fas fa-money-check-alt text-primary me-2"></i>Fee Configuration</h2>
            <p class="text-muted mb-0 fs-6">Manage the master list of fees. Fees are categorized and sorted automatically.</p>
        </div>
        <div class="col-md-6 text-md-end mt-3 mt-md-0">
            <div class="d-flex justify-content-md-end gap-2">
                <div class="input-group w-auto shadow-sm">
                    <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                    <input type="text" class="form-control border-start-0 ps-0 custom-accordion-search" placeholder="Search fee name...">
                </div>
                <button class="btn btn-light border shadow-sm fw-bold px-4" data-bs-toggle="modal" data-bs-target="#archivedFeesModal">
                    <i class="fas fa-box-archive me-1"></i> View Archived
                    <?php if (count($archivedFeesList) > 0): ?>
                        <span class="badge bg-secondary rounded-pill ms-1"><?= count($archivedFeesList) ?></span>
                    <?php endif; ?>
                </button>
                <button class="btn btn-primary shadow-sm fw-bold px-4" data-bs-toggle="modal" data-bs-target="#addFeeModal">
                    <i class="fas fa-plus me-1"></i> Add Fee
                </button>
            </div>
        </div>
    </div>

    <!-- Success/Error Alerts -->
    <?php if (isset($_GET['success'])): ?>
        <?php
        $successMsg = match($_GET['success']) {
            'archived' => 'Fee has been archived and removed from the active list.',
            'archived_category' => 'Fees in this category have been archived.',
            'edited' => 'Fee configuration has been updated successfully.',
            'restored' => 'Fee has been restored and is now active again.',
            'deleted'  => 'Fee has been permanently deleted.',
            'deleted_all' => 'Archived fees have been permanently deleted.',
            default    => 'Fee configuration has been saved to the database.',
        };
        ?>
        <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm rounded-3" role="alert">
            <i class="fas fa-check-circle me-2"></i> <strong>Success!</strong> <?= htmlspecialchars($successMsg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm rounded-3" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i> <strong>Error!</strong> <?= htmlspecialchars($_GET['error']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Accordion Card -->
    <div class="accordion mb-4" id="feesAccordion">
        <?php if (count($groupedFees) > 0): ?>
            <?php $accIndex = 0; foreach ($groupedFees as $catName => $group): $accIndex++; ?>
                <div class="accordion-item border-0 mb-3 shadow-sm rounded-4 overflow-hidden fee-accordion-item position-relative">
                    <h2 class="accordion-header" id="heading<?= $accIndex ?>">
                        <button class="accordion-button <?= $accIndex === 1 ? '' : 'collapsed' ?> bg-white fw-bold d-flex align-items-center p-3" type="button" data-bs-toggle="collapse" data-bs-target="#collapse<?= $accIndex ?>" aria-expanded="<?= $accIndex === 1 ? 'true' : 'false' ?>" aria-controls="collapse<?= $accIndex ?>" style="box-shadow: none;">
                            <div class="d-flex w-100 align-items-center pe-5">
                                <div class="flex-grow-1 text-dark fs-6" style="text-transform: uppercase; font-size: 0.85rem !important; letter-spacing: 0.5px;">
                                    <i class="fas fa-layer-group text-primary me-2 opacity-75"></i><span class="category-name"><?= htmlspecialchars($catName) ?></span>
                                    <div class="text-muted fw-normal mt-1 text-capitalize" style="font-size: 0.75rem; letter-spacing: 0;">
                                        PHP <?= number_format($group['total_amount'], 2) ?> Total &bull; <?= count($group['items']) ?> Items
                                    </div>
                                </div>
                            </div>
                        </button>
                    </h2>
                    
                    <!-- Archive Category Button Moved to Actions Column Header -->
                    <div id="collapse<?= $accIndex ?>" class="accordion-collapse collapse <?= $accIndex === 1 ? 'show' : '' ?>" aria-labelledby="heading<?= $accIndex ?>" data-bs-parent="#feesAccordion">
                        <div class="accordion-body p-0 bg-light border-top">
                            <table class="table table-borderless table-hover align-middle mb-0 m-0 fee-items-table">
                                <thead class="text-muted border-bottom" style="font-size: 0.70rem;">
                                    <tr>
                                        <th class="py-3 ps-4 w-50">FEE NAME</th>
                                        <th class="py-3 text-center">REQUIRED</th>
                                        <th class="py-3 text-center">STATUS</th>
                                        <th class="py-3 text-end">AMOUNT</th>
                                        <th class="py-3 text-center pe-4" style="width: 110px;">
                                            <div class="d-flex flex-column align-items-center justify-content-center gap-1">
                                                <span class="mb-1">ACTIONS</span>
                                                <button type="button" class="btn btn-sm btn-light text-danger shadow-sm border btn-archive-category-trigger px-3" data-bs-toggle="modal" data-bs-target="#archiveCategoryModal" data-category-id="<?= $group['category_id'] ?>" data-category-name="<?= htmlspecialchars($catName) ?>" data-item-count="<?= count($group['items']) ?>" title="Archive all fees in this category">
                                                    <i class="fas fa-box-archive me-1"></i> All
                                                </button>
                                            </div>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($group['items'] as $fee): ?>
                                        <tr class="border-bottom fee-item-row">
                                            <td class="py-3 ps-4">
                                                <div class="fw-semibold text-dark fee-name-text" style="font-size: 0.9rem;"><?= htmlspecialchars($fee['fee_name']) ?></div>
                                            </td>
                                            <td class="py-3 text-center">
                                                <?php if ($fee['is_required']): ?>
                                                    <i class="fas fa-check-circle text-success fs-5" data-bs-toggle="tooltip" title="Required"></i>
                                                <?php else: ?>
                                                    <i class="fas fa-minus-circle text-muted fs-5" data-bs-toggle="tooltip" title="Optional"></i>
                                                <?php endif; ?>
                                            </td>
                                            <td class="py-3 text-center">
                                                <span class="badge rounded-pill bg-success px-3 py-2">Active</span>
                                            </td>
                                            <td class="py-3 text-end text-success fw-bold">PHP <?= number_format($fee['default_amount'], 2) ?></td>
                                            <td class="py-3 text-center pe-4">
                                                <!-- EDIT BUTTON -->
                                                <button type="button" class="btn btn-sm btn-light text-primary shadow-sm me-1 btn-edit-trigger" 
                                                    data-bs-toggle="modal" data-bs-target="#editFeeModal" 
                                                    data-id="<?= $fee['fee_id'] ?>" 
                                                    data-name="<?= htmlspecialchars($fee['fee_name']) ?>" 
                                                    data-category="<?= $fee['category_id'] ?>" 
                                                    data-amount="<?= $fee['default_amount'] ?>" 
                                                    data-required="<?= $fee['is_required'] ?>" title="Edit Configuration">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <!-- ARCHIVE BUTTON -->
                                                <button type="button" class="btn btn-sm btn-light text-danger shadow-sm btn-archive-trigger" 
                                                    data-bs-toggle="modal" data-bs-target="#deleteFeeModal" 
                                                    data-fee-id="<?= $fee['fee_id'] ?>" data-fee-name="<?= htmlspecialchars($fee['fee_name']) ?>" title="Archive">
                                                    <i class="fas fa-box-archive"></i>
                                                </button>
                                            </td>
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
                No fee configurations found. Click "Add Fee" to create one.
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ========================================== -->
<!-- MODALS -->
<!-- ========================================== -->

<!-- 1. ADD FEE MODAL (No Priority Order Input) -->
<div class="modal fade" id="addFeeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white border-0 pb-3">
                <h5 class="modal-title fw-bold"><i class="fas fa-plus-circle me-2"></i>Add New Fee</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="" method="POST">
                <?= csrfField(); ?>
                <div class="modal-body bg-light p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold text-dark">Category Type <span class="text-danger">*</span></label>
                        <select class="form-select shadow-sm" name="category_id" required>
                            <option value="" disabled selected>Select Category</option>
                            <?php foreach($categories as $cat): ?>
                                <option value="<?= $cat['category_id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold text-dark">Fee Description/Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control shadow-sm" name="fee_name" placeholder="e.g. Energy Fee, Library Fee" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold text-dark">Amount (₱) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" class="form-control shadow-sm" name="default_amount" placeholder="0.00" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold text-dark">Is Required?</label>
                            <select class="form-select shadow-sm" name="is_required">
                                <option value="1" selected>Yes (Mandatory)</option>
                                <option value="0">No (Optional)</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-light shadow-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="submit_fee" class="btn btn-primary shadow-sm px-4">Save Fee</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 2. EDIT FEE MODAL (No Priority Order Input) -->
<div class="modal fade" id="editFeeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-white border-bottom pb-3">
                <h5 class="modal-title fw-bold text-primary"><i class="fas fa-edit me-2"></i>Edit Fee Configuration</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form action="" method="POST">
                <?= csrfField(); ?>
                <input type="hidden" name="edit_fee_id" id="editFeeId">
                <div class="modal-body bg-light p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold text-dark">Category Type</label>
                        <select class="form-select shadow-sm" name="category_id" id="editFeeCategory" required>
                            <?php foreach($categories as $cat): ?>
                                <option value="<?= $cat['category_id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold text-dark">Fee Description/Name</label>
                        <input type="text" class="form-control shadow-sm" name="fee_name" id="editFeeName" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold text-dark">Amount (₱)</label>
                            <input type="number" step="0.01" class="form-control shadow-sm" name="default_amount" id="editFeeAmount" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold text-dark">Mandatory?</label>
                            <select class="form-select shadow-sm" name="is_required" id="editFeeRequired">
                                <option value="1">Yes</option>
                                <option value="0">No</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-light shadow-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="edit_fee" class="btn btn-primary shadow-sm px-4">Update Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 3. ARCHIVE FEE MODAL -->
<div class="modal fade" id="deleteFeeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form action="" method="POST">
                <?= csrfField(); ?>
                <input type="hidden" name="fee_id" id="archiveFeeId">
                <div class="modal-body text-center p-4">
                    <i class="fas fa-box-archive text-warning mb-3" style="font-size: 3rem;"></i>
                    <h5 class="fw-bold">Archive Fee?</h5>
                    <p class="text-muted small">
                        Are you sure you want to archive <strong id="archiveFeeName"></strong>? It will no longer appear when generating new student billing.
                    </p>
                    <div class="d-flex justify-content-center gap-2 mt-4">
                        <button type="button" class="btn btn-light border shadow-sm w-50" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="archive_fee" class="btn btn-warning text-dark fw-bold shadow-sm w-50">Archive</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 3.5 ARCHIVE ENTIRE CATEGORY MODAL -->
<div class="modal fade" id="archiveCategoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form action="" method="POST">
                <?= csrfField(); ?>
                <input type="hidden" name="category_id" id="archiveCategoryId">
                <div class="modal-body text-center p-4">
                    <i class="fas fa-exclamation-triangle text-danger mb-3" style="font-size: 3rem;"></i>
                    <h5 class="fw-bold">Archive Category?</h5>
                    <p class="text-muted small">
                        Archive all <strong id="archiveCategoryCount"></strong> fees under <strong id="archiveCategoryName"></strong>?
                    </p>
                    <div class="d-flex justify-content-center gap-2 mt-4">
                        <button type="button" class="btn btn-light border shadow-sm w-50" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="archive_category" class="btn btn-danger shadow-sm w-50">Archive All</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 4. ARCHIVED FEES MODAL -->
<div class="modal fade" id="archivedFeesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-white border-bottom pb-3">
                <h5 class="modal-title fw-bold text-secondary"><i class="fas fa-box-archive me-2"></i>Archived Fees</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body bg-light" style="max-height: 400px; overflow-y: auto;">
                <?php if (count($archivedFeesList) > 0): ?>
                    <table class="table table-sm table-hover align-middle bg-white mb-0">
                        <thead class="text-uppercase text-secondary" style="font-size: 0.72rem;">
                            <tr>
                                <th>Fee Name</th>
                                <th>Category</th>
                                <th>Amount</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($archivedFeesList as $fee): ?>
                                <tr>
                                    <td class="fw-bold text-dark"><?= htmlspecialchars($fee['fee_name']) ?></td>
                                    <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($fee['category_name'] ?? 'Unknown') ?></span></td>
                                    <td class="text-success fw-bold">₱ <?= number_format($fee['default_amount'], 2) ?></td>
                                    <td class="text-end text-nowrap">
                                        <form action="" method="POST" class="d-inline">
                                            <?= csrfField(); ?>
                                            <input type="hidden" name="fee_id" value="<?= $fee['fee_id'] ?>">
                                            <button type="submit" name="restore_fee" class="btn btn-sm btn-light text-success shadow-sm me-1"><i class="fas fa-rotate-left me-1"></i> Restore</button>
                                        </form>
                                        <form action="" method="POST" class="d-inline" onsubmit="return confirm('Permanently delete this fee?');">
                                            <?= csrfField(); ?>
                                            <input type="hidden" name="fee_id" value="<?= $fee['fee_id'] ?>">
                                            <button type="submit" name="delete_fee" class="btn btn-sm btn-light text-danger shadow-sm"><i class="fas fa-trash-alt"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="text-center py-4 text-muted">No archived fees yet.</div>
                <?php endif; ?>
            </div>
            <div class="modal-footer border-0 bg-white d-flex justify-content-between">
                <?php if (count($archivedFeesList) > 0): ?>
                    <form action="" method="POST" class="m-0" onsubmit="return confirm('Delete ALL archived fees?');">
                        <?= csrfField(); ?>
                        <button type="submit" name="delete_all_archived" class="btn btn-outline-danger shadow-sm"><i class="fas fa-trash-alt me-1"></i> Delete All Permanently</button>
                    </form>
                <?php else: ?>
                    <div></div>
                <?php endif; ?>
                <button type="button" class="btn btn-light shadow-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.querySelector('.custom-accordion-search');
    if (searchInput) {
        searchInput.addEventListener('input', function(e) {
            const term = e.target.value.toLowerCase();
            const accordions = document.querySelectorAll('.fee-accordion-item');
            
            accordions.forEach(acc => {
                let hasVisibleItem = false;
                const rows = acc.querySelectorAll('.fee-item-row');
                
                rows.forEach(row => {
                    const feeName = row.querySelector('.fee-name-text').textContent.toLowerCase();
                    if (feeName.includes(term)) {
                        row.style.display = '';
                        hasVisibleItem = true;
                    } else {
                        row.style.display = 'none';
                    }
                });
                
                const catName = acc.querySelector('.category-name').textContent.toLowerCase();
                if (catName.includes(term)) {
                    rows.forEach(row => row.style.display = '');
                    acc.style.display = '';
                } else if (hasVisibleItem) {
                    acc.style.display = '';
                } else {
                    acc.style.display = 'none';
                }
            });
        });
    }
});
</script>
<script src="../../assets/js/fee-master-setup.js"></script>
<?php require_once __DIR__ . '/../../../../includes/layout-end.php'; ?>