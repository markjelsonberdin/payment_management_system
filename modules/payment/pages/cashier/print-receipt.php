<?php
/** Internal physical cashier payment receipt (not a BIR invoice). */
require_once __DIR__ . '/../../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once ROOT_PATH . '/includes/audit.php';
require_once __DIR__ . '/../../database/db_connect.php';

requireAuth();
$paymentId = (int) ($_GET['payment_id'] ?? 0);
$saleId = (int) ($_GET['cash_sale_id'] ?? 0);
if (($paymentId > 0) === ($saleId > 0)) {
    http_response_code(422); exit('Choose exactly one receipt record.');
}

$role = getCurrentUserRoleKey(); $viewerId = (int) getCurrentUserId();
$isStudent = $role === 'student'; $isCashier = $role === 'cashier';
$canReport = paymentEffectivePermission($role, 'ledger.view');
$recordType = $paymentId > 0 ? 'payment' : 'cash_sale'; $recordId = $paymentId > 0 ? $paymentId : $saleId;
$receipt = null; $lines = [];

try {
    if ($paymentId > 0) {
        $stmt = $pdo->prepare("SELECT p.payment_id, p.student_id, p.verified_by AS cashier_id, p.receipt_number, p.reference_number, p.amount, p.cash_received, p.change_amount, p.payment_date, p.verified_at AS created_at, p.remarks, p.payment_status, s.user_id AS student_user_id, s.student_number, s.full_name, s.course, s.year_level, b.academic_year, b.semester FROM payments p JOIN students s ON s.student_id=p.student_id LEFT JOIN billing b ON b.billing_id=p.billing_id WHERE p.payment_id=? AND p.transaction_type='Walk-in' AND p.payment_channel='Cash' LIMIT 1");
        $stmt->execute([$paymentId]); $receipt = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($receipt) {
            $lineStmt = $pdo->prepare('SELECT bi.fee_name AS description, 1 AS quantity, pa.allocated_amount AS unit_price, pa.allocated_amount AS line_total FROM payment_allocations pa JOIN billing_items bi ON bi.billing_item_id=pa.billing_item_id WHERE pa.payment_id=? ORDER BY pa.allocated_at, pa.allocation_id');
            $lineStmt->execute([$paymentId]); $lines = $lineStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } else {
        $stmt = $pdo->prepare("SELECT cs.cash_sale_id, cs.student_id, cs.cashier_id, cs.receipt_number, cs.total_amount AS amount, cs.cash_received, cs.change_amount, cs.sold_at AS created_at, cs.remarks, cs.sale_status AS payment_status, s.user_id AS student_user_id, s.student_number, s.full_name, s.course, s.year_level, cs.academic_year_snapshot AS academic_year, '' AS semester FROM cash_sales cs JOIN students s ON s.student_id=cs.student_id WHERE cs.cash_sale_id=? LIMIT 1");
        $stmt->execute([$saleId]); $receipt = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($receipt) {
            $lineStmt = $pdo->prepare('SELECT item_name_snapshot AS description, quantity, unit_price_snapshot AS unit_price, line_total FROM cash_sale_items WHERE cash_sale_id=? ORDER BY cash_sale_line_id');
            $lineStmt->execute([$saleId]); $lines = $lineStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch (Throwable $e) { error_log('Cashier receipt query failed: ' . $e->getMessage()); http_response_code(503); exit('Receipt data is temporarily unavailable.'); }

if (!$receipt || ($paymentId > 0 && $receipt['payment_status'] !== 'Verified') || ($saleId > 0 && $receipt['payment_status'] !== 'Completed')) { http_response_code(404); exit('Completed cashier receipt not found.'); }
if ($isStudent && (int) $receipt['student_user_id'] !== $viewerId) { http_response_code(403); exit('You may only open your own receipt.'); }
if ($isCashier && (int) $receipt['cashier_id'] !== $viewerId) { http_response_code(403); exit('You may only open receipts you processed.'); }
if (!$isStudent && !$isCashier && !$canReport) { http_response_code(403); exit('Receipt access denied.'); }

$cashierName = 'Cashier #' . (int) $receipt['cashier_id'];
try { $core = db(); if ($core) { $cashierStmt = $core->prepare('SELECT full_name FROM users WHERE id=? LIMIT 1'); $cashierStmt->execute([(int) $receipt['cashier_id']]); $cashierName = $cashierStmt->fetchColumn() ?: $cashierName; } } catch (Throwable $ignored) {}
$renderEventId = 0;
$firstRenderEventId = 0;
try {
    $event = $pdo->prepare('INSERT INTO cashier_receipt_print_events (record_type,record_id,rendered_by) VALUES (?,?,?)');
    $event->execute([$recordType,$recordId,$viewerId]);
    $renderEventId = (int) $pdo->lastInsertId();
    $first = $pdo->prepare('SELECT receipt_print_event_id FROM cashier_receipt_print_events WHERE record_type=? AND record_id=? ORDER BY rendered_at ASC, receipt_print_event_id ASC LIMIT 1');
    $first->execute([$recordType,$recordId]);
    $firstRenderEventId = (int) $first->fetchColumn();
} catch (Throwable $ignored) {}
$isReprint = $firstRenderEventId > 0 && $renderEventId !== $firstRenderEventId;
logActivity('render_cashier_receipt', sprintf('Rendered %s receipt %s%s.', $recordType, $receipt['receipt_number'] ?: $receipt['reference_number'], $isReprint ? ' (reprint)' : ''), 'payment', $viewerId);
$receiptNumber = $receipt['receipt_number'] ?: $receipt['reference_number'];
$autoPrint = $isCashier && (string) ($_GET['autoprint'] ?? '') === '1';
$backUrl = BASE_URL . ($saleId > 0 ? '/modules/payment/pages/cashier/payment-collection-portal.php?view=school-items' : '/modules/payment/pages/cashier/payment-collection-portal.php');
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Cashier Payment Receipt - <?= htmlspecialchars($receiptNumber) ?></title>
<style>
@page{size:A4 portrait;margin:11mm}*{box-sizing:border-box}body{margin:0;background:#eef1f5;color:#171717;font-family:Arial,Helvetica,sans-serif}.toolbar{max-width:190mm;margin:12px auto;text-align:right}.toolbar button,.toolbar a{padding:8px 14px;border:1px solid #173b7a;border-radius:4px;background:#173b7a;color:#fff;text-decoration:none;font-weight:700;cursor:pointer}.toolbar a{background:#fff;color:#173b7a;margin-right:7px}.receipt{width:190mm;min-height:270mm;margin:0 auto 12px;padding:10mm 11mm;background:#fff;border:1px solid #202020;position:relative}.school{display:flex;align-items:center;border-bottom:2px solid #161616;padding-bottom:8px}.school img{width:62px;height:62px;object-fit:contain;margin-right:13px}.school h1{font-family:Georgia,serif;font-size:19px;letter-spacing:.4px;margin:0;text-transform:uppercase}.school p{font-size:10px;line-height:1.35;margin:3px 0 0}.title-row{display:flex;justify-content:space-between;align-items:end;margin:12px 0 8px}.title-row h2{font-family:Georgia,serif;font-size:24px;letter-spacing:1px;margin:0}.receipt-no{text-align:right}.receipt-no strong{display:block;font-size:22px;color:#a33232;letter-spacing:1px}.receipt-no span{font-size:10px}.reprint{border:2px solid #a33232;color:#a33232;font-weight:bold;font-size:11px;padding:4px 7px;display:inline-block;margin-bottom:5px}.meta,.items,.summary{border-collapse:collapse;width:100%}.meta td,.items td,.items th,.summary td{border:1px solid #222;padding:6px 7px;font-size:11px}.meta .label{width:18%;font-weight:bold;background:#f5f5f5}.items th{background:#eee;text-transform:uppercase;font-size:10px}.items td:nth-child(2),.items td:nth-child(3),.items td:nth-child(4){text-align:right}.items .blank td{height:23px}.summary{width:46%;margin-left:auto;margin-top:8px}.summary td:first-child{font-weight:bold;background:#f5f5f5}.summary td:last-child{text-align:right;font-weight:bold}.summary .due td{font-size:13px}.signatures{display:flex;justify-content:space-between;margin-top:35px}.signatures div{width:42%;text-align:center;font-size:11px}.line{border-top:1px solid #111;margin-top:34px;padding-top:5px}.footer{position:absolute;bottom:10mm;left:11mm;right:11mm;border-top:1px solid #222;padding-top:5px}.footer strong{font-family:Georgia,serif;font-size:17px}.footer small{display:block;font-size:9px;margin-top:4px}.type{font-size:11px;margin:8px 0}.type b{border:1px solid #111;padding:3px 8px;margin-right:6px}@media print{body{background:#fff}.toolbar{display:none}.receipt{border:0;margin:0;width:auto;min-height:0;padding:0}.footer{position:static;margin-top:20px}.items{page-break-inside:auto}.items tr{page-break-inside:avoid}}
</style></head><body><div class="toolbar"><a href="<?= htmlspecialchars($backUrl) ?>">Back / Done</a><button onclick="window.print()">Print Student Copy</button></div><main class="receipt"><header class="school"><img src="<?= htmlspecialchars(BASE_URL) ?>/images/bcp-logo-source.png" alt="BCP logo"><div><h1>Bestlink College of the Philippines</h1><p>1071 Quirino Highway, Kaligayahan, Novaliches, Quezon City<br>Cashier Payment Receipt — Student Copy</p></div></header><div class="title-row"><div><div class="type"><b>☑ CASH PAYMENT</b><?= $saleId > 0 ? ' School Sale' : ' Academic Fee Payment' ?></div><h2>PAYMENT RECEIPT</h2></div><div class="receipt-no"><?php if($isReprint):?><div class="reprint">REPRINT — NOT VALID AS ORIGINAL</div><?php endif;?><span>Receipt No.</span><strong><?= htmlspecialchars($receiptNumber) ?></strong></div></div><table class="meta"><tr><td class="label">Student No.</td><td><?=htmlspecialchars($receipt['student_number'])?></td><td class="label">Date / Time</td><td><?=htmlspecialchars(date('m/d/Y h:i A',strtotime($receipt['created_at'])))?></td></tr><tr><td class="label">Student Name</td><td><?=htmlspecialchars($receipt['full_name'])?></td><td class="label">Course / Year</td><td><?=htmlspecialchars($receipt['course'].' / '.$receipt['year_level'])?></td></tr><tr><td class="label">Academic Term</td><td colspan="3"><?=htmlspecialchars(trim(($receipt['semester'] ?: '') . ' ' . ($receipt['academic_year'] ?: ''))) ?: '—'?></td></tr></table><table class="items"><thead><tr><th>Description / Nature of Payment</th><th>Quantity</th><th>Unit Price</th><th>Amount</th></tr></thead><tbody><?php foreach($lines as $line):?><tr><td><?=htmlspecialchars($line['description'])?></td><td><?=number_format((float)$line['quantity'],0)?></td><td>₱ <?=number_format((float)$line['unit_price'],2)?></td><td>₱ <?=number_format((float)$line['line_total'],2)?></td></tr><?php endforeach; if(!$lines):?><tr><td colspan="4">Cash payment allocation</td></tr><?php endif;?></tbody></table><table class="summary"><tr><td>Total Paid</td><td>₱ <?=number_format((float)$receipt['amount'],2)?></td></tr><tr><td>Cash Received</td><td>₱ <?=number_format((float)$receipt['cash_received'],2)?></td></tr><tr><td>Change</td><td>₱ <?=number_format((float)$receipt['change_amount'],2)?></td></tr><tr class="due"><td>AMOUNT RECEIVED</td><td>₱ <?=number_format((float)$receipt['amount'],2)?></td></tr></table><div class="signatures"><div><div class="line"><?=htmlspecialchars($receipt['full_name'])?><br><small>STUDENT / PAYOR</small></div></div><div><div class="line"><?=htmlspecialchars($cashierName)?><br><small>CASHIER</small></div></div></div><?php if(!empty($receipt['remarks'])):?><p style="font-size:10px;margin-top:20px"><b>Remarks:</b> <?=htmlspecialchars($receipt['remarks'])?></p><?php endif;?><footer class="footer"><strong>STUDENT'S COPY</strong><small>This is an internal Cashier Payment Receipt for school records. Keep this copy for payment verification.</small></footer></main><?php if ($autoPrint): ?><script>window.addEventListener("load",()=>window.print(),{once:true});</script><?php endif; ?></body></html>
