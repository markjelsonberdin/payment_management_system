<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once __DIR__ . '/../../database/db_connect.php';
require_once __DIR__ . '/../../includes/AccountingReportingPageService.php';
requireAuth();
requirePaymentPermission('report.view');

$pageTitle = 'Collection & Analytics';
$activeModule = 'payment';
$activePage = 'accounting/collection-reporting-analytics';
$breadcrumbs = [
    ['label' => 'Payment Management', 'url' => BASE_URL . '/modules/payment/index.php'],
    ['label' => 'Collection & Analytics', 'url' => null],
];
$report = null;
$reportError = null;
try {
    $report = (new AccountingReportingPageService($pdo))->loadForWeb($_GET);
} catch (InvalidArgumentException $e) {
    $reportError = $e->getMessage();
} catch (Throwable $e) {
    error_log('Accounting collection report page: ' . $e->getMessage());
    $reportError = 'Unable to load report data.';
}
$scope = $report['scope'] ?? [];
$filters = $report['filter_options'] ?? ['terms' => [], 'channels' => [], 'methods' => [], 'categories' => [], 'report_years' => []];
$reportingControlPeriod = $scope['period'] ?? ($_GET['period'] ?? 'today');
$reportingControlMonth = $scope['period_month'] ?? ($_GET['period_month'] ?? (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('n'));
$reportingControlYear = $scope['period_year'] ?? ($_GET['period_year'] ?? (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y'));
$reportingControlYears = $filters['report_years'];
require_once ROOT_PATH . '/includes/breadcrumbs.php';
require_once ROOT_PATH . '/includes/layout-start.php';
?>
<?php renderBreadcrumbs($breadcrumbs); ?>
<link rel="stylesheet" href="<?= BASE_URL ?>/modules/payment/assets/css/reporting-period-controls.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/modules/payment/assets/css/payment-components.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/modules/payment/assets/css/payment-operational-tables.css">
<main class="container-fluid payment-page py-4">
  <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
    <div>
      <h1 class="h3 mb-1"><i class="ti ti-chart-pie text-primary me-2" aria-hidden="true"></i>Collection &amp; Analytics</h1>
      <p class="text-muted mb-0">Detailed official collections and allocation-backed reporting.</p>
    </div>
    <?php if ($report !== null): ?>
      <div class="d-flex flex-wrap gap-2">
        <button class="btn btn-outline-primary" type="button" onclick="window.print()"><i class="ti ti-printer me-1" aria-hidden="true"></i>Print</button>
        <a class="btn btn-primary" href="<?= BASE_URL ?>/modules/payment/api/export-accounting-collections.php?<?= htmlspecialchars(http_build_query($_GET), ENT_QUOTES, 'UTF-8') ?>"><i class="ti ti-file-spreadsheet me-1" aria-hidden="true"></i>Export XLSX</a>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($reportError !== null): ?>
    <div class="alert alert-danger" role="alert"><?= htmlspecialchars($reportError, ENT_QUOTES, 'UTF-8') ?></div>
  <?php else: ?>
    <?php if (in_array('error', $report['section_status'] ?? [], true)): ?>
      <div class="alert alert-warning" role="status">Some report sections are currently unavailable.</div>
    <?php endif; ?>

    <form id="collectionReportFilters" class="card border-0 shadow-sm mb-4 payment-table-toolbar">
      <div class="card-body">
        <div class="row g-3 align-items-end mb-3">
          <div class="col-sm-6 col-lg-3">
            <label class="form-label" for="reportAcademicYear">Academic Year</label>
            <select class="form-select" id="reportAcademicYear" name="academic_year">
              <?php foreach ($filters['terms'] as $term): ?>
                <option value="<?= htmlspecialchars((string) $term['academic_year'], ENT_QUOTES, 'UTF-8') ?>" <?= ($scope['academic_year'] ?? '') === $term['academic_year'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $term['academic_year'], ENT_QUOTES, 'UTF-8') ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-6 col-lg-2">
            <label class="form-label" for="reportSemester">Semester</label>
            <select class="form-select" id="reportSemester" name="semester">
              <?php foreach (['1st', '2nd', 'Summer'] as $semester): ?>
                <option value="<?= $semester ?>" <?= ($scope['semester'] ?? '1st') === $semester ? 'selected' : '' ?>><?= $semester ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-6 col-lg-3">
            <label class="form-label" for="reportMethod">Payment Method</label>
            <select class="form-select" id="reportMethod" name="payment_method">
              <option value="">All methods</option>
              <?php foreach ($filters['methods'] as $method): ?>
                <option value="<?= htmlspecialchars((string) $method, ENT_QUOTES, 'UTF-8') ?>" <?= ($scope['payment_method'] ?? '') === $method ? 'selected' : '' ?>><?= htmlspecialchars((string) $method, ENT_QUOTES, 'UTF-8') ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-6 col-lg-2">
            <label class="form-label" for="reportChannel">Payment Channel</label>
            <select class="form-select" id="reportChannel" name="payment_channel">
              <option value="">All channels</option>
              <?php foreach ($filters['channels'] as $channel): ?>
                <option value="<?= htmlspecialchars((string) $channel, ENT_QUOTES, 'UTF-8') ?>" <?= ($scope['payment_channel'] ?? '') === $channel ? 'selected' : '' ?>><?= htmlspecialchars((string) $channel, ENT_QUOTES, 'UTF-8') ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-6 col-lg-2">
            <label class="form-label" for="reportCategory">Fee Category</label>
            <select class="form-select" id="reportCategory" name="fee_category">
              <option value="">All categories</option>
              <?php foreach ($filters['categories'] as $category): ?>
                <option value="<?= (int) $category['category_id'] ?>" <?= (int) ($scope['fee_category'] ?? 0) === (int) $category['category_id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $category['category_name'], ENT_QUOTES, 'UTF-8') ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="row g-3 align-items-end">
          <div class="col-xl-8">
            <label class="form-label">Reporting Period</label>
            <?php require __DIR__ . '/../../includes/reporting-period-controls.php'; ?>
          </div>
          <div class="col-md-8 col-xl-3">
            <label class="form-label" for="reportSearch">Student / Reference</label>
            <input class="form-control" id="reportSearch" name="search" maxlength="100" value="<?= htmlspecialchars((string) ($scope['search'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="Name, student no., OR or reference">
          </div>
          <div class="col-md-4 col-xl-1">
            <a class="btn btn-outline-secondary w-100" href="<?= BASE_URL ?>/modules/payment/pages/accounting/collection-reporting-analytics.php" title="Clear all filters"><i class="ti ti-x" aria-hidden="true"></i><span class="visually-hidden">Clear filters</span></a>
          </div>
        </div>
      </div>
    </form>

    <?php if (($report['section_status']['recent_collections'] ?? '') === 'no_data'): ?>
      <div class="alert alert-light" role="status">No transactions found for this period.</div>
    <?php endif; ?>

    <?php $kpis = $report['kpis'] ?? []; $methodTotals = []; foreach (($report['payment_methods'] ?? []) as $methodRow) $methodTotals[$methodRow['label']] = $methodRow['total']; $methodMetricsAvailable = ($report['section_status']['payment_methods'] ?? '') !== 'error'; ?>
    <div class="row g-3 mb-4">
      <?php foreach ([
          ['Total Collected', $kpis['official_academic_collections'] ?? null, 'Verified official allocations'],
          ['Verified Payment Count', $kpis['verified_payment_count'] ?? null, 'One count per payment'],
          ['Average Payment Amount', $kpis['average_payment_amount'] ?? null, 'Applied allocation per payment'],
          ['Cash Collection', $methodTotals['Cash'] ?? ($methodMetricsAvailable ? 0 : null), 'Verified walk-in cash'],
          ['Live Online Collection', $methodTotals['Online'] ?? ($methodMetricsAvailable ? 0 : null), 'Verified Live allocations'],
          ['Bank Transfer Collection', $methodTotals['Bank Transfer'] ?? ($methodMetricsAvailable ? 0 : null), 'Verified bank allocations'],
      ] as [$label, $value, $note]): ?>
        <div class="col-sm-6 col-xl-4"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="small text-muted text-uppercase fw-semibold"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></div><div class="h4 fw-bold my-2"><?php if ($value === null): ?>Unavailable<?php elseif (str_ends_with($label, 'Count')): ?><?= number_format((int) $value) ?><?php else: ?>PHP <?= number_format((float) $value, 2) ?><?php endif; ?></div><small class="text-muted"><?= htmlspecialchars($note, ENT_QUOTES, 'UTF-8') ?></small></div></div></div>
      <?php endforeach; ?>
    </div>

    <div class="row g-3 mb-4">
      <div class="col-xl-7"><section class="card border-0 shadow-sm h-100"><div class="card-body"><h2 class="h5">Collection Trend</h2><p class="small text-muted mb-3"><?= htmlspecialchars((string) ($report['trend']['comparison_label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Period</th><th class="text-end">Selected</th><th class="text-end">Comparison</th></tr></thead><tbody>
        <?php foreach (($report['trend']['labels'] ?? []) as $index => $label): ?><tr><td><?= htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') ?></td><td class="text-end">PHP <?= number_format((float) ($report['trend']['current'][$index] ?? 0), 2) ?></td><td class="text-end">PHP <?= number_format((float) ($report['trend']['prior'][$index] ?? 0), 2) ?></td></tr><?php endforeach; ?>
        <?php if (empty($report['trend']['labels'])): ?><tr><td colspan="3" class="text-center text-muted py-3">Trend information is unavailable for this period.</td></tr><?php endif; ?>
      </tbody></table></div></div></section></div>
      <div class="col-xl-5"><section class="card border-0 shadow-sm h-100"><div class="card-body"><h2 class="h5">Collection by Payment Method</h2><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Method</th><th class="text-end">Applied Amount</th></tr></thead><tbody><?php foreach (($report['payment_methods'] ?? []) as $row): ?><tr><td><?= htmlspecialchars((string) $row['label'], ENT_QUOTES, 'UTF-8') ?></td><td class="text-end">PHP <?= number_format((float) $row['total'], 2) ?></td></tr><?php endforeach; ?><?php if (empty($report['payment_methods'])): ?><tr><td colspan="2" class="text-center text-muted py-3">No method breakdown for this period.</td></tr><?php endif; ?></tbody></table></div></div></section></div>
    </div>

    <div class="row g-3 mb-4">
      <div class="col-lg-6"><section class="card border-0 shadow-sm h-100"><div class="card-body"><h2 class="h5">Collection by Fee Category</h2><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Category</th><th class="text-end">Applied Amount</th></tr></thead><tbody><?php foreach (($report['fee_categories'] ?? []) as $row): ?><tr><td><?= htmlspecialchars((string) $row['label'], ENT_QUOTES, 'UTF-8') ?></td><td class="text-end">PHP <?= number_format((float) $row['total'], 2) ?></td></tr><?php endforeach; ?><?php if (empty($report['fee_categories'])): ?><tr><td colspan="2" class="text-center text-muted py-3">No category breakdown for this period.</td></tr><?php endif; ?></tbody></table></div></div></section></div>
      <div class="col-lg-6"><section class="card border-0 shadow-sm h-100"><div class="card-body"><h2 class="h5">Receivables &amp; Term Snapshot</h2><div class="d-flex justify-content-between py-2 border-bottom"><span>Net assessed amount</span><strong>PHP <?= number_format((float) ($kpis['net_assessed_amount'] ?? 0), 2) ?></strong></div><div class="d-flex justify-content-between py-2 border-bottom"><span>Remaining balance</span><strong>PHP <?= number_format((float) ($kpis['outstanding_balance'] ?? 0), 2) ?></strong></div><div class="d-flex justify-content-between py-2"><span>Term-to-date collection efficiency</span><strong><?= ($kpis['collection_efficiency'] ?? null) === null ? 'N/A' : number_format((float) $kpis['collection_efficiency'], 2) . '%' ?></strong></div><small class="text-muted d-block mt-2"><?= htmlspecialchars((string) ($kpis['snapshot_note'] ?? ''), ENT_QUOTES, 'UTF-8') ?></small></div></section></div>
    </div>

    <section class="card border-0 shadow-sm payment-operational-table"><div class="card-body">
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3"><div><h2 class="h5 mb-1">Detailed Official Collections</h2><p class="small text-muted mb-0"><?= htmlspecialchars((string) ($scope['academic_year'] ?? ''), ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars((string) ($scope['semester'] ?? ''), ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars((string) ($scope['period_label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p></div><span class="badge text-bg-light">Allocation-based</span></div>
      <div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Date &amp; Time</th><th>Student</th><th>OR / Reference</th><th>Payment Method</th><th>Channel</th><th>AY / Semester</th><th>Fee Category Allocation</th><th class="text-end">Applied Amount</th><th>Status</th><th>Verifier ID</th></tr></thead><tbody>
      <?php foreach (($report['recent_collections'] ?? []) as $row): $verifiedAt = new DateTimeImmutable((string) $row['verified_at'], new DateTimeZone('Asia/Manila')); ?>
        <tr><td class="text-nowrap"><span class="payment-table-primary"><?= htmlspecialchars($verifiedAt->format('M j, Y'), ENT_QUOTES, 'UTF-8') ?></span><small class="payment-table-secondary"><?= htmlspecialchars($verifiedAt->format('g:i A'), ENT_QUOTES, 'UTF-8') ?></small></td><td><span class="payment-table-primary"><?= htmlspecialchars((string) ($row['full_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span><small class="payment-table-secondary"><?= htmlspecialchars((string) ($row['student_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?></small></td><td><span class="payment-table-primary"><?= htmlspecialchars((string) ($row['receipt_number'] ?: $row['reference_number']), ENT_QUOTES, 'UTF-8') ?></span><?php if (!empty($row['reference_number']) && $row['reference_number'] !== $row['receipt_number']): ?><small class="payment-table-secondary"><?= htmlspecialchars((string) $row['reference_number'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?></td><td><span class="badge bg-light text-dark border"><?= htmlspecialchars((string) ($row['payment_method'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span></td><td><span class="badge bg-primary-subtle text-primary border border-primary-subtle"><?= htmlspecialchars((string) ($row['payment_channel'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span></td><td><span class="payment-table-primary"><?= htmlspecialchars((string) ($row['academic_year'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span><small class="payment-table-secondary"><?= htmlspecialchars((string) ($row['semester'] ?? ''), ENT_QUOTES, 'UTF-8') ?></small></td><td class="payment-table-secondary"><?= htmlspecialchars((string) ($row['allocation_breakdown'] ?? 'Unmapped category'), ENT_QUOTES, 'UTF-8') ?></td><td class="payment-table-money">₱ <?= number_format((float) $row['total_applied'], 2) ?></td><td><span class="badge text-bg-success"><?= htmlspecialchars((string) $row['payment_status'], ENT_QUOTES, 'UTF-8') ?></span></td><td><?= $row['verified_by'] === null ? '—' : (int) $row['verified_by'] ?></td></tr>
      <?php endforeach; ?>
      <?php if (empty($report['recent_collections'])): ?><tr><td colspan="10" class="payment-table-empty">No collection records found for the selected filters.</td></tr><?php endif; ?>
      </tbody></table></div>
      <?php $pagination = $report['recent_collections_pagination'] ?? null; if ($pagination && $pagination['total_pages'] > 1): $pageQuery = static function (int $page) use ($pagination): string { return '?' . http_build_query(array_merge($_GET, ['page' => $page, 'page_size' => $pagination['page_size']])); }; $page = (int) $pagination['page']; $pages = (int) $pagination['total_pages']; $firstPage = max(1, $page - 2); $lastPage = min($pages, $page + 2); ?>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 pt-3">
          <small class="text-muted">Showing <?= number_format((($pagination['page'] - 1) * $pagination['page_size']) + 1) ?>–<?= number_format(min($pagination['page'] * $pagination['page_size'], $pagination['total_records'])) ?> of <?= number_format($pagination['total_records']) ?> records</small>
          <nav class="payment-pagination" aria-label="Detailed official collections pages"><ul class="pagination pagination-sm mb-0">
            <?php if ($pagination['has_previous']): ?><li class="page-item"><a class="page-link" href="<?= htmlspecialchars($pageQuery($page - 1), ENT_QUOTES, 'UTF-8') ?>" aria-label="Previous page">‹ Previous</a></li><?php else: ?><li class="page-item disabled"><span class="page-link" aria-disabled="true">‹ Previous</span></li><?php endif; ?>
            <?php if ($firstPage > 1): ?><li class="page-item"><a class="page-link" href="<?= htmlspecialchars($pageQuery(1), ENT_QUOTES, 'UTF-8') ?>">1</a></li><?php if ($firstPage > 2): ?><li class="page-item disabled" aria-hidden="true"><span class="page-link">…</span></li><?php endif; ?><?php endif; ?>
            <?php for ($pageNumber = $firstPage; $pageNumber <= $lastPage; $pageNumber++): ?><li class="page-item <?= $pageNumber === $page ? 'active' : '' ?>"><?php if ($pageNumber === $page): ?><span class="page-link" aria-current="page"><?= $pageNumber ?></span><?php else: ?><a class="page-link" href="<?= htmlspecialchars($pageQuery($pageNumber), ENT_QUOTES, 'UTF-8') ?>"><?= $pageNumber ?></a><?php endif; ?></li><?php endfor; ?>
            <?php if ($lastPage < $pages): ?><?php if ($lastPage < $pages - 1): ?><li class="page-item disabled" aria-hidden="true"><span class="page-link">…</span></li><?php endif; ?><li class="page-item"><a class="page-link" href="<?= htmlspecialchars($pageQuery($pages), ENT_QUOTES, 'UTF-8') ?>"><?= $pages ?></a></li><?php endif; ?>
            <?php if ($pagination['has_next']): ?><li class="page-item"><a class="page-link" href="<?= htmlspecialchars($pageQuery($page + 1), ENT_QUOTES, 'UTF-8') ?>" aria-label="Next page">Next ›</a></li><?php else: ?><li class="page-item disabled"><span class="page-link" aria-disabled="true">Next ›</span></li><?php endif; ?>
          </ul></nav>
        </div>
      <?php endif; ?>
    </div></section>
  <?php endif; ?>
</main>
<script src="<?= BASE_URL ?>/modules/payment/assets/js/reporting-period-controls.js?v=1"></script>
<?php require_once ROOT_PATH . '/includes/layout-end.php'; ?>
