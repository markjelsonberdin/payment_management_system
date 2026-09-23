<?php
require_once __DIR__ . '/../../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once __DIR__ . '/../../database/db_connect.php';
require_once __DIR__ . '/../../includes/PaymentReportingScope.php';
require_once __DIR__ . '/../../includes/CashierReportingPeriod.php';

requireAuth();
requirePaymentPermission('payment.walkin_history');
$cashierId = (int) getCurrentUserId();
$filters = [
    'search' => trim((string) ($_GET['search'] ?? '')),
    'status' => (string) ($_GET['status'] ?? ''),
    'channel' => (string) ($_GET['channel'] ?? ''),
    'date_range' => (string) ($_GET['date_range'] ?? ''),
];
$rows = [];
$error = '';

try {
    $official = PaymentReportingScope::officialCondition('p');
    $where = ['1=1'];
    $params = [$cashierId, $cashierId];
    if (in_array($filters['date_range'], ['today','week','month','year'], true)) {
        $selectedRange = CashierReportingPeriod::resolve($filters['date_range']);
        $where[] = 'h.recorded_at >= ? AND h.recorded_at < ?';
        $params[] = CashierReportingPeriod::sql($selectedRange['current_start']);
        $params[] = CashierReportingPeriod::sql($selectedRange['current_end_exclusive']);
    }
    if ($filters['search'] !== '') {
        $where[] = '(h.student_number LIKE ? OR h.full_name LIKE ? OR h.receipt LIKE ? OR h.reference LIKE ? OR h.details LIKE ?)';
        $needle = '%' . $filters['search'] . '%';
        array_push($params, $needle, $needle, $needle, $needle, $needle);
    }
    if (in_array($filters['status'], ['Verified','Completed'], true)) {
        $where[] = 'h.status=?'; $params[] = $filters['status'];
    }
    if ($filters['channel'] !== '' && $filters['channel'] !== 'Cash') $where[] = '1=0';

    $sql = "SELECT * FROM (
        SELECT p.verified_at AS recorded_at, p.payment_id AS record_id, 'Academic Payment' AS transaction_type,
            COALESCE(p.receipt_number,p.reference_number) AS receipt, p.reference_number,
            s.student_number, s.full_name, COALESCE(aa.allocated_total,0) AS total, p.payment_status AS status,
            aa.details, p.amount AS header_amount,
            (p.amount-COALESCE(aa.allocated_total,0)) AS amount_difference,
            IF(aa.payment_id IS NULL OR p.amount<>COALESCE(aa.allocated_total,0),1,0) AS allocation_mismatch,
            b.academic_year, b.semester, b.billing_type
        FROM payments p JOIN students s ON s.student_id=p.student_id
        LEFT JOIN billing b ON b.billing_id=p.billing_id
        LEFT JOIN (
            SELECT pa.payment_id, SUM(pa.allocated_amount) AS allocated_total,
                GROUP_CONCAT(DISTINCT fc.category_name ORDER BY fc.category_name SEPARATOR ', ') AS details
            FROM payment_allocations pa
            LEFT JOIN billing_items bi ON bi.billing_item_id=pa.billing_item_id
            LEFT JOIN fees f ON f.fee_id=bi.fee_id
            LEFT JOIN fee_categories fc ON fc.category_id=f.category_id
            GROUP BY pa.payment_id
        ) aa ON aa.payment_id=p.payment_id
        WHERE p.transaction_type='Walk-in' AND p.payment_channel='Cash' AND p.verified_by=? AND p.verified_at IS NOT NULL AND {$official}
        UNION ALL
        SELECT cs.sold_at, cs.cash_sale_id, 'School Sale', cs.receipt_number, cs.receipt_number,
            s.student_number, s.full_name, cs.total_amount, cs.sale_status,
            GROUP_CONCAT(csi.item_name_snapshot ORDER BY csi.cash_sale_line_id SEPARATOR ', '), cs.total_amount, 0, 0,
            cs.academic_year_snapshot, NULL, 'School Sale'
        FROM cash_sales cs JOIN students s ON s.student_id=cs.student_id
        JOIN cash_sale_items csi ON csi.cash_sale_id=cs.cash_sale_id
        WHERE cs.cashier_id=? AND cs.sale_status='Completed'
        GROUP BY cs.cash_sale_id
    ) h WHERE " . implode(' AND ', $where) . ' ORDER BY h.recorded_at DESC LIMIT 300';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $error = 'Cashier history is unavailable. Verify the cashier school-sales migration.'; }

$pageTitle = 'My Walk-in Transactions';
$activeModule = 'payment';
$activePage = 'cashier/walk-in-transaction-history';
$breadcrumbs = [['label'=>'Payment Management','url'=>BASE_URL.'/modules/payment/index.php'],['label'=>$pageTitle,'url'=>null]];
$exportUrl = BASE_URL . '/modules/payment/api/export-cashier-history.php?' . http_build_query($filters);
require_once ROOT_PATH . '/includes/breadcrumbs.php';
require_once ROOT_PATH . '/includes/layout-start.php';
?>
<?php renderBreadcrumbs($breadcrumbs); ?>
<div class="container-fluid py-4">
 <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4"><div><h2 class="fw-bolder mb-1">My Walk-in Transactions</h2><p class="text-muted mb-0">Your completed academic cash payments and school sales only.</p></div><a class="btn btn-outline-primary" href="<?= htmlspecialchars($exportUrl) ?>"><i class="ti ti-file-spreadsheet me-1"></i>Export Excel</a></div>
 <?php if($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
 <form class="card border-0 shadow-sm mb-4" method="get"><div class="card-body"><div class="row g-2 align-items-end">
  <div class="col-lg-4"><label class="form-label small fw-bold">Search</label><input class="form-control" name="search" value="<?=htmlspecialchars($filters['search'])?>" placeholder="Student, OR, category, or item"></div>
  <div class="col-sm-4 col-lg-2"><label class="form-label small fw-bold">Period</label><select class="form-select" name="date_range"><option value="">All dates</option><?php foreach(['today'=>'Today','week'=>'This Week','month'=>'This Month','year'=>'This Year'] as $key=>$label): ?><option value="<?=$key?>" <?=$filters['date_range']===$key?'selected':''?>><?=$label?></option><?php endforeach;?></select></div>
  <div class="col-sm-4 col-lg-2"><label class="form-label small fw-bold">Status</label><select class="form-select" name="status"><option value="">All completed</option><option value="Verified" <?=$filters['status']==='Verified'?'selected':''?>>Academic Verified</option><option value="Completed" <?=$filters['status']==='Completed'?'selected':''?>>School Sale Completed</option></select></div>
  <div class="col-sm-4 col-lg-2"><label class="form-label small fw-bold">Payment Method</label><select class="form-select" name="channel"><option value="">Cash only</option><option value="Cash" <?=$filters['channel']==='Cash'?'selected':''?>>Cash</option></select></div>
  <div class="col-lg-2 d-flex gap-2"><button class="btn btn-primary flex-grow-1">Apply</button><a class="btn btn-outline-secondary" href="walk-in-transaction-history.php">Reset</a></div>
 </div></div></form>
 <div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Date / Time</th><th>OR / Reference</th><th>Student</th><th>Type</th><th>Category / Items</th><th>Academic Context</th><th class="text-end">Amount</th><th></th></tr></thead><tbody>
 <?php if(!$rows): ?><tr><td colspan="8" class="text-center text-muted py-5">No completed cashier transactions match your filters.</td></tr><?php endif; foreach($rows as $row): $receiptUrl = $row['transaction_type']==='School Sale' ? 'print-receipt.php?cash_sale_id='.(int)$row['record_id'] : 'print-receipt.php?payment_id='.(int)$row['record_id']; $allocationMismatch=(int)$row['allocation_mismatch']===1; ?>
 <tr><td><?=htmlspecialchars($row['recorded_at'])?></td><td class="fw-semibold"><?=htmlspecialchars($row['receipt'])?></td><td><?=htmlspecialchars($row['full_name'])?><small class="d-block text-muted"><?=htmlspecialchars($row['student_number'])?></small></td><td><span class="badge <?=$row['transaction_type']==='School Sale'?'bg-info':'bg-primary'?>"><?=htmlspecialchars($row['transaction_type'])?></span></td><td class="small"><?=htmlspecialchars($row['details'] ?: '—')?></td><td class="small"><?=htmlspecialchars(trim(($row['academic_year'] ?: '') . ' ' . ($row['semester'] ?: '')) ?: '—')?><small class="d-block text-muted"><?=htmlspecialchars($row['billing_type'] ?: '')?></small></td><td class="text-end fw-bold">PHP <?=number_format((float)$row['total'],2)?><?php if($allocationMismatch): ?><small class="d-block text-danger"><span class="badge bg-danger">Allocation mismatch</span><br>Header: PHP <?=number_format((float)$row['header_amount'],2)?><br>Allocated: PHP <?=number_format((float)$row['total'],2)?><br>Difference: PHP <?=number_format((float)$row['amount_difference'],2)?></small><?php endif; ?></td><td><a class="btn btn-sm btn-outline-primary" target="_blank" href="<?=$receiptUrl?>"><i class="ti ti-printer"></i></a></td></tr>
 <?php endforeach; ?></tbody></table></div></div></div>
</div>
<?php require_once ROOT_PATH . '/includes/layout-end.php'; ?>
