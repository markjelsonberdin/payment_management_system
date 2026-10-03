<?php
require_once __DIR__ . '/../../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';

if (!paymentSchoolSalesSellingEnabled()) {
    http_response_code(404);
    exit('School Sales is unavailable.');
}

require_once __DIR__ . '/../../database/db_connect.php';
require_once __DIR__ . '/../../includes/CashSaleService.php';
require_once __DIR__ . '/../../includes/PaymentNotificationService.php';
require_once ROOT_PATH . '/includes/audit.php';

requireAuth();
requirePaymentPermission('payment.school_sales');
$cashierId = (int) getCurrentUserId();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_sale'])) {
    try {
        if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) throw new RuntimeException('Invalid CSRF token.');
        $items = json_decode((string) ($_POST['items_json'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        $result = (new CashSaleService($pdo))->create((int) $_POST['student_id'], $cashierId, $items, (float) $_POST['cash_received'], trim((string) ($_POST['remarks'] ?? '')));
        (new PaymentNotificationService($pdo))->notifySchoolSale((int) $_POST['student_id'], $result['cash_sale_id'], $result['total'], $result['receipt_number']);
        logActivity('process_cash_school_sale', 'Processed school sale of PHP ' . number_format($result['total'], 2) . ' with OR ' . $result['receipt_number'], 'payment', $cashierId);
        header('Location: print-receipt.php?cash_sale_id=' . (int) $result['cash_sale_id'] . '&autoprint=1'); exit;
    } catch (Throwable $e) {
        header('Location: school-sales.php?error=' . urlencode($e->getMessage())); exit;
    }
}
$items = (new CashSaleService($pdo))->activeItems();
$recentSales = [];
try {
    $recent = $pdo->prepare("SELECT cs.cash_sale_id, cs.receipt_number, cs.total_amount, cs.cash_received, cs.change_amount, cs.sold_at, s.student_number, s.full_name, GROUP_CONCAT(CONCAT(csi.item_name_snapshot, ' x', csi.quantity) SEPARATOR ', ') AS items FROM cash_sales cs JOIN students s ON s.student_id=cs.student_id JOIN cash_sale_items csi ON csi.cash_sale_id=cs.cash_sale_id WHERE cs.cashier_id=? AND cs.sale_status='Completed' GROUP BY cs.cash_sale_id ORDER BY cs.sold_at DESC LIMIT 25");
    $recent->execute([$cashierId]); $recentSales = $recent->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $ignored) {}
$pageTitle = 'School Sales'; $activeModule = 'payment'; $activePage = 'cashier/school-sales';
$breadcrumbs = [['label' => 'Payment Management', 'url' => BASE_URL . '/modules/payment/index.php'], ['label' => 'School Sales', 'url' => null]];
require_once ROOT_PATH . '/includes/breadcrumbs.php'; require_once ROOT_PATH . '/includes/layout-start.php';
?>
<?php renderBreadcrumbs($breadcrumbs); ?>
<div class="container-fluid py-4">
 <div class="d-flex justify-content-between align-items-center mb-4"><div><h2 class="fw-bolder mb-1"><i class="ti ti-shopping-cart text-primary me-2"></i>School Sales</h2><p class="text-muted mb-0">Cash-only, student-linked sales. These do not change academic balances.</p></div><span class="badge bg-success">Cashier: <?= htmlspecialchars(getCurrentUserName()) ?></span></div>
 <?php if (isset($_GET['success'])): ?><div class="alert alert-success">Sale completed. Receipt <strong><?= htmlspecialchars($_GET['or'] ?? '') ?></strong> was issued. <?php if ((int)($_GET['cash_sale_id']??0)>0): ?><a class="btn btn-sm btn-success ms-3" target="_blank" href="print-receipt.php?cash_sale_id=<?= (int)$_GET['cash_sale_id'] ?>"><i class="ti ti-printer me-1"></i>Print Student Copy</a><?php endif; ?></div><?php endif; ?>
 <?php if (isset($_GET['error'])): ?><div class="alert alert-danger"><?= htmlspecialchars($_GET['error']) ?></div><?php endif; ?>
 <form method="post" id="saleForm"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>"><input type="hidden" name="complete_sale" value="1"><input type="hidden" name="student_id" id="saleStudentId"><input type="hidden" name="items_json" id="saleItemsJson">
 <div class="row g-4"><div class="col-lg-4"><div class="card border-0 shadow-sm"><div class="card-body"><h5>1. Student</h5><div class="input-group"><input id="saleStudentNumber" class="form-control" placeholder="Student number"><button type="button" id="findSaleStudent" class="btn btn-primary">Find</button></div><div id="saleStudentInfo" class="d-none mt-3 bg-light rounded p-3"><strong id="saleStudentName"></strong><div class="small text-muted" id="saleStudentMeta"></div></div></div></div></div>
 <div class="col-lg-8"><div class="card border-0 shadow-sm"><div class="card-body"><h5>2. Approved School Items</h5><?php if (!$items): ?><div class="alert alert-warning mb-0">No active school-sale items yet. Accounting must configure approved items first.</div><?php else: ?><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Category</th><th>Item</th><th class="text-end">Price</th><th style="width:120px">Qty</th></tr></thead><tbody><?php foreach ($items as $item): ?><tr><td><?= htmlspecialchars($item['category_name']) ?></td><td><?= htmlspecialchars($item['item_name']) ?></td><td class="text-end">PHP <?= number_format((float) $item['unit_price'], 2) ?></td><td><input class="form-control sale-quantity" type="number" min="0" max="100" value="0" data-id="<?= (int) $item['sale_item_id'] ?>" data-price="<?= htmlspecialchars($item['unit_price']) ?>"></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?><div class="row mt-3"><div class="col-md-4"><label class="form-label">Sale Total</label><input id="saleTotal" class="form-control fw-bold" readonly value="0.00"></div><div class="col-md-4"><label class="form-label">Cash Received</label><input name="cash_received" id="saleCash" class="form-control" type="number" min="0" step="0.01" required></div><div class="col-md-4"><label class="form-label">Change</label><input id="saleChange" class="form-control" readonly value="0.00"></div></div><div class="mt-3"><label class="form-label">Remarks</label><textarea name="remarks" class="form-control" rows="2"></textarea></div><button class="btn btn-success mt-3" id="completeSale" disabled><i class="ti ti-printer me-1"></i>Complete Sale & Issue OR</button></div></div></div></div></form>
</div>
<div class="container-fluid pb-4"><div class="card border-0 shadow-sm"><div class="card-body"><div class="d-flex justify-content-between"><h5 class="mb-3">My Recent School Sales</h5><small class="text-muted">Only transactions processed by your account</small></div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>OR</th><th>Student</th><th>Items</th><th>Date</th><th class="text-end">Total</th><th></th></tr></thead><tbody><?php if ($recentSales): foreach ($recentSales as $sale): ?><tr><td class="fw-bold"><?= htmlspecialchars($sale['receipt_number']) ?></td><td><?= htmlspecialchars($sale['full_name']) ?><small class="d-block text-muted"><?= htmlspecialchars($sale['student_number']) ?></small></td><td><?= htmlspecialchars($sale['items']) ?></td><td><?= htmlspecialchars($sale['sold_at']) ?></td><td class="text-end">PHP <?= number_format((float)$sale['total_amount'],2) ?></td><td><a class="btn btn-sm btn-outline-primary" target="_blank" href="print-receipt.php?cash_sale_id=<?= (int)$sale['cash_sale_id'] ?>"><i class="ti ti-printer"></i></a></td></tr><?php endforeach; else: ?><tr><td colspan="6" class="text-center text-muted py-4">No school sales recorded yet.</td></tr><?php endif; ?></tbody></table></div></div></div></div>
<script>
const findBtn=document.getElementById('findSaleStudent'), studentInput=document.getElementById('saleStudentNumber'), studentId=document.getElementById('saleStudentId'), studentName=document.getElementById('saleStudentName'), studentMeta=document.getElementById('saleStudentMeta'), studentInfo=document.getElementById('saleStudentInfo'), saleTotal=document.getElementById('saleTotal'), saleItems=document.getElementById('saleItemsJson'), saleCash=document.getElementById('saleCash'), saleChange=document.getElementById('saleChange'), completeSale=document.getElementById('completeSale');
findBtn.onclick=async()=>{const n=studentInput.value.trim(); const r=await fetch('../../api/fetch_cash_sale_student.php?student_number='+encodeURIComponent(n)); const d=await r.json(); if(!d.success){alert(d.message);return;} studentId.value=d.student_id; studentName.textContent=d.name+' ('+d.student_number+')'; studentMeta.textContent=d.course_year; studentInfo.classList.remove('d-none'); calc();};
function calc(){let total=0, items=[];document.querySelectorAll('.sale-quantity').forEach(i=>{const q=parseInt(i.value)||0;if(q>0){total+=q*parseFloat(i.dataset.price);items.push({sale_item_id:i.dataset.id,quantity:q});}});saleTotal.value=total.toFixed(2); saleItems.value=JSON.stringify(items);const cash=parseFloat(saleCash.value)||0;saleChange.value=Math.max(0,cash-total).toFixed(2);completeSale.disabled=!(studentId.value&&items.length&&cash>=total)}
document.querySelectorAll('.sale-quantity').forEach(i=>i.oninput=calc); saleCash.oninput=calc;
const prefillStudent=new URLSearchParams(window.location.search).get('student_number'); if(prefillStudent){studentInput.value=prefillStudent; findBtn.click();}
</script>
<?php require_once ROOT_PATH . '/includes/layout-end.php'; ?>
