<?php
/**
 * SMS 2 - Payment History & Ledger System
 * Presentation layer only — does not change payment processing.
 */
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../includes/authentication.php';
require_once __DIR__ . '/../../../../includes/audit.php';
require_once __DIR__ . '/../../database/db_connect.php';
require_once __DIR__ . '/../../includes/PaymentHistoryService.php';

requireAuth();
requirePaymentPermission('payment.ledger');

function paymentHistoryIsOnline(array $pay): bool
{
    $channel = strtolower((string) ($pay['payment_channel'] ?? $pay['payment_method'] ?? ''));
    return in_array($channel, ['gcash', 'maya', 'card', 'qrph', 'paymongo', 'visa', 'mastercard'], true);
}

function paymentHistoryAmounts(array $pay): array
{
    $applied = (float) $pay['amount'];
    $isOnline = paymentHistoryIsOnline($pay);
    $fee = $isOnline ? (float) ($pay['processing_fee'] ?? 0) : 0;
    $total = $isOnline ? (float) ($pay['checkout_total'] ?? $applied) : $applied;
    return [$applied, $fee, $total];
}

function paymentHistoryStatusClass(string $status): string
{
    return match ($status) {
        'Verified' => 'bg-success',
        'Pending' => 'bg-warning text-dark',
        'Rejected' => 'bg-danger',
        'Failed' => 'bg-secondary',
        default => 'bg-secondary',
    };
}

function paymentHistoryStatusBadge(string $status): string
{
    return match ($status) {
        'Verified' => '<span class="badge rounded-pill bg-success-subtle text-success border border-success-subtle px-3 py-1 fw-semibold"><i class="ti ti-circle-check me-1"></i>Verified</span>',
        'Pending' => '<span class="badge rounded-pill bg-warning-subtle text-warning border border-warning-subtle px-3 py-1 fw-semibold"><i class="ti ti-clock me-1"></i>Pending</span>',
        'Rejected' => '<span class="badge rounded-pill bg-danger-subtle text-danger border border-danger-subtle px-3 py-1 fw-semibold"><i class="ti ti-circle-x me-1"></i>Rejected</span>',
        'Failed' => '<span class="badge rounded-pill bg-secondary-subtle text-secondary border border-secondary-subtle px-3 py-1 fw-semibold"><i class="ti ti-alert-circle me-1"></i>Failed</span>',
        default => '<span class="badge rounded-pill bg-secondary-subtle text-secondary border border-secondary-subtle px-3 py-1 fw-semibold">' . htmlspecialchars($status) . '</span>',
    };
}

function paymentHistoryQueryUrl(array $base, array $overrides = []): string
{
    $query = array_merge($base, $overrides);
    foreach ($query as $key => $value) {
        if ($value === '' || $value === null) {
            unset($query[$key]);
        }
    }
    $qs = http_build_query($query);
    return 'payment-history-ledger-system.php' . ($qs !== '' ? '?' . $qs : '');
}

$perPage = 8;
$currentPage = max(1, (int) ($_GET['page'] ?? 1));
$sort = (string) ($_GET['sort'] ?? 'date');
$dir = strtoupper((string) ($_GET['dir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
$allowedSort = ['date', 'amount', 'status', 'channel', 'reference', 'student', 'total'];
if (!in_array($sort, $allowedSort, true)) {
    $sort = 'date';
}

$filters = [
    'search' => trim((string) ($_GET['search'] ?? '')),
    'status' => (string) ($_GET['status'] ?? ''),
    'channel' => (string) ($_GET['channel'] ?? ''),
    'date_range' => (string) ($_GET['date_range'] ?? ''),
];

$paymentList = [];
$allocationsByPayment = [];
$ledgerByBilling = [];
$totalCollections = 0;
$totalTransactions = 0;
$pendingTransactions = 0;
$todayCollections = 0;
$totalRows = 0;
$totalPages = 1;
$offset = 0;

try {
    $historyService = new PaymentHistoryService($pdo);
    $filters = $historyService->normalizeFilters($filters);

    $summary = $historyService->getPaymentSummary();
    $totalCollections = (float) ($summary['total_collections'] ?? 0);
    $totalTransactions = (int) ($summary['total_transactions'] ?? 0);
    $pendingTransactions = (int) ($summary['pending_transactions'] ?? 0);
    $todayCollections = (float) ($summary['today_collections'] ?? 0);

    $totalRows = $historyService->getTotalPaymentsCount($filters);
    $totalPages = max(1, (int) ceil($totalRows / $perPage));
    if ($currentPage > $totalPages) {
        $currentPage = $totalPages;
    }
    $offset = ($currentPage - 1) * $perPage;

    $paymentList = $historyService->getPaginatedPayments($filters, $sort, $dir, $perPage, $offset);
    $paymentIds = array_column($paymentList, 'payment_id');
    $billingIds = array_column($paymentList, 'billing_id');
    $allocationsByPayment = $historyService->getAllocationsByPaymentIds($paymentIds);
    $ledgerByBilling = $historyService->getLedgerSummariesByBillingIds($billingIds);
} catch (Exception $e) {
    $dbError = $e->getMessage();
}

$queryBase = array_filter([
    'search' => $filters['search'] ?? '',
    'status' => $filters['status'] ?? '',
    'channel' => $filters['channel'] ?? '',
    'date_range' => $filters['date_range'] ?? '',
    'sort' => $sort,
    'dir' => $dir,
], static function ($value) {
    return $value !== '' && $value !== null;
});

$exportQuery = http_build_query(array_merge($queryBase, ['sort' => $sort, 'dir' => $dir]));
$exportUrl = BASE_URL . '/modules/payment/api/export-history.php' . ($exportQuery !== '' ? '?' . $exportQuery : '');

$pageTitle    = 'Payment History & Ledger System';
$activeModule = 'payment';
$activePage   = 'accounting/payment-history-ledger-system';
$breadcrumbs  = [
    ['label' => 'Payment Management', 'url' => BASE_URL . '/modules/payment/index.php'],
    ['label' => 'Payment History & Ledger System', 'url' => null],
];

require_once __DIR__ . '/../../../../includes/breadcrumbs.php';
require_once __DIR__ . '/../../../../includes/layout-start.php';

$fromRow = $totalRows === 0 ? 0 : $offset + 1;
$toRow = min($offset + count($paymentList), $totalRows);
?>

<?php renderBreadcrumbs($breadcrumbs); ?>

<div class="container-fluid py-4 ph-ledger">
    <div class="row mb-4 align-items-center">
        <div class="col-lg-7">
            <h2 class="mb-1 fw-bolder"><i class="fas fa-history text-primary me-2"></i>Payment History & Ledger</h2>
            <p class="text-muted mb-0 fs-6">Audit walk-in and online collections, official receipts, and student ledger balances.</p>
        </div>
        <div class="col-lg-5 text-lg-end mt-3 mt-lg-0">
            <a href="<?= htmlspecialchars($exportUrl) ?>" class="btn btn-outline-primary fw-bold shadow-sm" id="btnExportHistory">
                <i class="fas fa-file-csv me-1"></i> Export CSV
            </a>
        </div>
    </div>

    <?php if (isset($dbError)): ?>
        <div class="alert alert-danger shadow-sm"><i class="ti ti-alert-triangle me-2"></i> <?= htmlspecialchars($dbError) ?></div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-6 col-xl-3">
            <div class="card border-0 shadow-sm rounded-4 h-100 border-start border-success border-4">
                <div class="card-body">
                    <p class="text-muted fw-bold mb-1 text-uppercase" style="font-size: 0.75rem;">Total Collections</p>
                    <h3 class="fw-bolder mb-0 text-success">₱ <?= number_format($totalCollections, 2) ?></h3>
                    <small class="text-muted">Verified amounts applied</small>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="card border-0 shadow-sm rounded-4 h-100 border-start border-primary border-4">
                <div class="card-body">
                    <p class="text-muted fw-bold mb-1 text-uppercase" style="font-size: 0.75rem;">Total Transactions</p>
                    <h3 class="fw-bolder mb-0 text-dark"><?= number_format($totalTransactions) ?></h3>
                    <small class="text-muted">All recorded payments</small>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="card border-0 shadow-sm rounded-4 h-100 border-start border-warning border-4">
                <div class="card-body">
                    <p class="text-muted fw-bold mb-1 text-uppercase" style="font-size: 0.75rem;">Pending Transactions</p>
                    <h3 class="fw-bolder mb-0 text-warning"><?= number_format($pendingTransactions) ?></h3>
                    <small class="text-muted">Awaiting verification</small>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="card border-0 shadow-sm rounded-4 h-100 border-start border-info border-4">
                <div class="card-body">
                    <p class="text-muted fw-bold mb-1 text-uppercase" style="font-size: 0.75rem;">Today's Collections</p>
                    <h3 class="fw-bolder mb-0 text-info">₱ <?= number_format($todayCollections, 2) ?></h3>
                    <small class="text-muted">Verified today</small>
                </div>
            </div>
        </div>
    </div>

    <form method="get" action="payment-history-ledger-system.php" class="card border-0 shadow-sm rounded-4 mb-4" id="historyFilterForm">
        <div class="card-body p-3">
            <div class="row g-2 align-items-end">
                <div class="col-lg-3">
                    <label class="form-label small fw-bold text-muted mb-1">Search</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white"><i class="ti ti-search text-muted"></i></span>
                        <input type="text" name="search" value="<?= htmlspecialchars($filters['search'] ?? '') ?>" class="form-control" placeholder="Student, OR, or reference no.">
                    </div>
                </div>
                <div class="col-sm-6 col-lg-2">
                    <label class="form-label small fw-bold text-muted mb-1">Date Range</label>
                    <select name="date_range" class="form-select">
                        <option value="">All dates</option>
                        <option value="today" <?= ($filters['date_range'] ?? '') === 'today' ? 'selected' : '' ?>>Today</option>
                        <option value="week" <?= ($filters['date_range'] ?? '') === 'week' ? 'selected' : '' ?>>This Week</option>
                        <option value="month" <?= ($filters['date_range'] ?? '') === 'month' ? 'selected' : '' ?>>This Month</option>
                    </select>
                </div>
                <div class="col-sm-6 col-lg-2">
                    <label class="form-label small fw-bold text-muted mb-1">Payment Channel</label>
                    <select name="channel" class="form-select">
                        <option value="">All channels</option>
                        <?php foreach (PaymentHistoryService::CHANNELS as $channel): ?>
                            <option value="<?= htmlspecialchars($channel) ?>" <?= ($filters['channel'] ?? '') === $channel ? 'selected' : '' ?>><?= htmlspecialchars($channel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-6 col-lg-2">
                    <label class="form-label small fw-bold text-muted mb-1">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All statuses</option>
                        <?php foreach (PaymentHistoryService::STATUSES as $status): ?>
                            <option value="<?= htmlspecialchars($status) ?>" <?= ($filters['status'] ?? '') === $status ? 'selected' : '' ?>><?= htmlspecialchars($status) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-6 col-lg-3 d-flex gap-2">
                    <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
                    <input type="hidden" name="dir" value="<?= htmlspecialchars($dir) ?>">
                    <button type="submit" class="btn btn-primary fw-bold flex-grow-1">Apply Filters</button>
                    <a href="payment-history-ledger-system.php" class="btn btn-outline-secondary">Reset</a>
                </div>
            </div>
        </div>
    </form>

    <div class="card shadow-sm border-0 rounded-4 overflow-hidden position-relative">
        <div class="ph-ledger-loading d-none" id="historyLoadingState" aria-hidden="true">
            <div class="text-center">
                <div class="spinner-border text-primary mb-2" role="status"></div>
                <div class="fw-bold text-secondary">Loading transactions…</div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-sm align-middle mb-0 text-nowrap" id="historyTable">
                    <thead class="bg-light text-uppercase text-secondary" style="font-size: 0.75rem; letter-spacing: 0.5px;">
                        <tr>
                            <?php
                            $headersBeforeFee = [
                                'reference' => ['OR / Ref No.', ''],
                                'student'   => ['Student Details', ''],
                                'channel'   => ['Payment Channel', ''],
                                'amount'    => ['Amount Applied', 'text-end'],
                            ];
                            $headersAfterFee = [
                                'total'  => ['Total', 'text-end'],
                                'status' => ['Status', 'text-center'],
                                'date'   => ['Date & Time', ''],
                            ];
                            ?>
                            <?php foreach ($headersBeforeFee as $key => [$label, $extraClass]): ?>
                                <?php
                                $nextDir = ($sort === $key && $dir === 'ASC') ? 'DESC' : 'ASC';
                                $icon = 'fa-sort';
                                if ($sort === $key) {
                                    $icon = $dir === 'ASC' ? 'fa-sort-up' : 'fa-sort-down';
                                }
                                $thClass = trim($extraClass . ($key === 'reference' ? ' ps-4 py-2' : ' py-2'));
                                ?>
                                <th class="<?= htmlspecialchars($thClass) ?>">
                                    <a class="text-secondary text-decoration-none" href="<?= htmlspecialchars(paymentHistoryQueryUrl($queryBase, ['sort' => $key, 'dir' => $nextDir, 'page' => 1])) ?>">
                                        <?= htmlspecialchars($label) ?> <i class="fas <?= $icon ?> ms-1"></i>
                                    </a>
                                </th>
                            <?php endforeach; ?>
                            <th class="py-2 text-end">Processing Fee</th>
                            <?php foreach ($headersAfterFee as $key => [$label, $extraClass]): ?>
                                <?php
                                $nextDir = ($sort === $key && $dir === 'ASC') ? 'DESC' : 'ASC';
                                $icon = 'fa-sort';
                                if ($sort === $key) {
                                    $icon = $dir === 'ASC' ? 'fa-sort-up' : 'fa-sort-down';
                                }
                                $thClass = trim($extraClass . ' py-2');
                                ?>
                                <th class="<?= htmlspecialchars($thClass) ?>">
                                    <a class="text-secondary text-decoration-none" href="<?= htmlspecialchars(paymentHistoryQueryUrl($queryBase, ['sort' => $key, 'dir' => $nextDir, 'page' => 1])) ?>">
                                        <?= htmlspecialchars($label) ?> <i class="fas <?= $icon ?> ms-1"></i>
                                    </a>
                                </th>
                            <?php endforeach; ?>
                            <th class="text-end pe-4 py-2">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="border-top-0">
                        <?php if (count($paymentList) > 0): ?>
                            <?php foreach ($paymentList as $pay): ?>
                                <?php
                                [$amtApplied, $procFee, $chkTotal] = paymentHistoryAmounts($pay);
                                $statusBadge = paymentHistoryStatusBadge((string) $pay['payment_status']);
                                $statusClass = paymentHistoryStatusClass((string) $pay['payment_status']);
                                $alloc = $allocationsByPayment[(int) $pay['payment_id']] ?? [];
                                $ledger = $ledgerByBilling[(int) ($pay['billing_id'] ?? 0)] ?? [
                                    'opening_balance' => 0,
                                    'payments_total' => 0,
                                    'closing_balance' => (float) ($pay['remaining_balance'] ?? 0),
                                ];
                                $modalPayload = [
                                    'payment_id' => (int) $pay['payment_id'],
                                    'reference_number' => $pay['reference_number'] ?? 'N/A',
                                    'receipt_number' => $pay['receipt_number'] ?? '',
                                    'full_name' => $pay['full_name'] ?? '',
                                    'student_number' => $pay['student_number'] ?? '',
                                    'course' => $pay['course'] ?? '',
                                    'payment_channel' => $pay['payment_channel'] ?? $pay['payment_method'] ?? '',
                                    'transaction_type' => $pay['transaction_type'] ?? '',
                                    'payment_status' => $pay['payment_status'] ?? '',
                                    'status_class' => $statusClass,
                                    'amount' => $amtApplied,
                                    'processing_fee' => $procFee,
                                    'checkout_total' => $chkTotal,
                                    'payment_date' => $pay['payment_date'] ?? '',
                                    'created_at' => $pay['created_at'] ?? '',
                                    'verified_at' => $pay['verified_at'] ?? '',
                                    'verified_by' => $pay['verified_by'] ?? '',
                                    'remarks' => $pay['remarks'] ?? '',
                                    'billing_type' => $pay['billing_type'] ?? '',
                                    'academic_year' => $pay['academic_year'] ?? '',
                                    'semester' => $pay['semester'] ?? '',
                                    'allocations' => $alloc,
                                    'ledger' => $ledger,
                                ];
                                ?>
                                <tr>
                                    <td class="ps-4 fw-bold text-primary">#<?= htmlspecialchars($pay['reference_number'] ?? 'N/A') ?></td>
                                    <td>
                                        <div class="fw-bold text-dark"><?= htmlspecialchars($pay['full_name']) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($pay['student_number']) ?> (<?= htmlspecialchars($pay['course'] ?? '') ?>)</small>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border px-2 py-1"><?= htmlspecialchars($pay['payment_channel'] ?? $pay['payment_method']) ?></span>
                                    </td>
                                    <td class="fw-bold text-dark text-end">₱ <?= number_format($amtApplied, 2) ?></td>
                                    <td class="text-muted text-end">₱ <?= number_format($procFee, 2) ?></td>
                                    <td class="fw-bold text-primary text-end">₱ <?= number_format($chkTotal, 2) ?></td>
                                    <td class="text-center">
                                        <?= $statusBadge ?>
                                    </td>
                                    <td>
                                        <div class="text-dark"><?= !empty($pay['payment_date']) ? date('M d, Y', strtotime($pay['payment_date'])) : '—' ?></div>
                                        <small class="text-muted"><?= !empty($pay['created_at']) ? date('h:i A', strtotime($pay['created_at'])) : '' ?></small>
                                    </td>
                                    <td class="text-end pe-4">
                                        <button type="button"
                                            class="btn btn-sm btn-light text-primary border shadow-sm js-view-ledger text-nowrap"
                                            data-ledger="<?= htmlspecialchars(json_encode($modalPayload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT), ENT_QUOTES) ?>">
                                            <i class="ti ti-eye me-1"></i> View Ledger
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" class="text-center py-5 text-muted">
                                    <div class="py-4">
                                        <i class="ti ti-receipt fa-3x mb-3 text-secondary opacity-50"></i>
                                        <h5 class="fw-bold text-dark">No payment history found</h5>
                                        <p class="text-muted mb-3">Try adjusting your search term, payment channel, status, or date range.</p>
                                        <?php if (!empty($filters['search']) || !empty($filters['status']) || !empty($filters['channel']) || !empty($filters['date_range'])): ?>
                                            <a href="payment-history-ledger-system.php" class="btn btn-sm btn-outline-primary fw-semibold">
                                                <i class="fas fa-undo me-1"></i> Clear all filters
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php if ($totalRows > 0): ?>
            <div class="card-footer bg-white border-top py-3 px-4 d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div class="text-muted small">
                    Showing <span class="fw-bold text-dark"><?= $fromRow ?></span> to <span class="fw-bold text-dark"><?= $toRow ?></span> of <span class="fw-bold text-dark"><?= number_format($totalRows) ?></span> entries
                </div>
                <nav aria-label="Payment history pages">
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= htmlspecialchars(paymentHistoryQueryUrl($queryBase, ['page' => max(1, $currentPage - 1)])) ?>" aria-label="Previous">
                                <i class="ti ti-chevron-left"></i>
                            </a>
                        </li>
                        <?php
                        $windowStart = max(1, $currentPage - 2);
                        $windowEnd = min($totalPages, $currentPage + 2);
                        for ($i = $windowStart; $i <= $windowEnd; $i++):
                        ?>
                            <li class="page-item <?= $i === $currentPage ? 'active' : '' ?>">
                                <a class="page-link" href="<?= htmlspecialchars(paymentHistoryQueryUrl($queryBase, ['page' => $i])) ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= htmlspecialchars(paymentHistoryQueryUrl($queryBase, ['page' => min($totalPages, $currentPage + 1)])) ?>" aria-label="Next">
                                <i class="ti ti-chevron-right"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="ledgerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg overflow-hidden" style="border-radius: 1rem;">
            <div class="modal-header bg-primary text-white border-bottom-0 p-4">
                <h5 class="modal-title fw-bolder mb-0"><i class="ti ti-file-invoice me-2 opacity-75"></i>Ledger & Transaction Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4 bg-light" id="ledgerModalBody"></div>
            <div class="modal-footer bg-white border-top-0 px-4 py-3">
                <button type="button" class="btn btn-secondary px-4 fw-semibold" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<style>
.ph-ledger-loading {
    position: absolute;
    inset: 0;
    z-index: 5;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(255,255,255,0.72);
}
[data-theme="dark"] .ph-ledger-loading {
    background: rgba(15, 23, 42, 0.72);
}

.ph-ledger .pagination .page-item .page-link {
    border-radius: 0.4rem !important;
    margin: 0 3px;
    min-width: 36px;
    text-align: center;
    padding: 0.375rem 0.75rem;
    box-shadow: none !important;
}
</style>

<script src="<?= BASE_URL ?>/modules/payment/assets/js/payment-history-ledger.js?v=2"></script>
<?php require_once __DIR__ . '/../../../../includes/layout-end.php'; ?>
