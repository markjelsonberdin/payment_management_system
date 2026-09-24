<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once __DIR__ . '/../database/db_connect.php';
require_once __DIR__ . '/../includes/PaymentReportingScope.php';
require_once __DIR__ . '/../includes/CashierReportingPeriod.php';
header('Content-Type: application/json; charset=utf-8');
requireAuth();
requirePaymentPermission('payment.cashier_dashboard');

$cashierId=(int)getCurrentUserId();
$period=$_GET['period'] ?? 'today';
try {
    if (!is_string($period)) throw new InvalidArgumentException('Invalid period.');
    $range=CashierReportingPeriod::resolve($period, null, $_GET['period_month'] ?? null, $_GET['period_year'] ?? null);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['ok'=>false,'error'=>'invalid_period','message'=>'The selected reporting period is invalid.']);
    exit;
}
$period=$range['period'];

function cashierScalar(PDO $pdo,string $sql,array $params): float { $stmt=$pdo->prepare($sql); $stmt->execute($params); return (float)($stmt->fetchColumn() ?: 0); }
function cashierTrend(PDO $pdo,int $cashierId,string $period,DateTimeImmutable $start,DateTimeImmutable $endExclusive,string $official): array {
    [$template]=CashierReportingPeriod::bucket($period);
    $academicGroup=sprintf($template,'p.verified_at');
    $saleGroup=sprintf($template,'cs.sold_at');
    $startSql=CashierReportingPeriod::sql($start); $endSql=CashierReportingPeriod::sql($endExclusive);
    $allocationJoin='LEFT JOIN (SELECT payment_id, SUM(allocated_amount) allocated_total FROM payment_allocations GROUP BY payment_id) academic_alloc ON academic_alloc.payment_id=p.payment_id';
    $queries=[
        ["SELECT {$academicGroup} point,COALESCE(SUM(academic_alloc.allocated_total),0) total FROM payments p {$allocationJoin} WHERE p.transaction_type='Walk-in' AND p.payment_method='Walk-in' AND p.payment_channel='Cash' AND p.verified_by=? AND p.verified_at IS NOT NULL AND {$official} AND p.verified_at>=? AND p.verified_at<? GROUP BY {$academicGroup}",[$cashierId,$startSql,$endSql]],
        ["SELECT {$saleGroup} point,COALESCE(SUM(cs.total_amount),0) total FROM cash_sales cs WHERE cs.cashier_id=? AND cs.sale_status='Completed' AND cs.sold_at>=? AND cs.sold_at<? GROUP BY {$saleGroup}",[$cashierId,$startSql,$endSql]],
    ];
    $values=[];
    foreach($queries as [$sql,$params]) { $stmt=$pdo->prepare($sql); $stmt->execute($params); foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $values[$row['point']]=($values[$row['point']] ?? 0)+(float)$row['total']; }
    return CashierReportingPeriod::bucketSeries($period,$start,$endExclusive,$values);
}

try {
    $official=PaymentReportingScope::officialCondition('p');
    $availablePeriodYears = [];
    try {
        $yearSql = "SELECT DISTINCT report_year FROM (
          SELECT YEAR(p.verified_at) report_year FROM payments p WHERE p.transaction_type='Walk-in' AND p.payment_method='Walk-in' AND p.payment_channel='Cash' AND p.verified_by=? AND p.verified_at IS NOT NULL AND {$official}
          UNION
          SELECT YEAR(cs.sold_at) report_year FROM cash_sales cs WHERE cs.cashier_id=? AND cs.sale_status='Completed'
        ) reporting_years WHERE report_year IS NOT NULL ORDER BY report_year DESC";
        $yearStmt = $pdo->prepare($yearSql);
        $yearStmt->execute([$cashierId, $cashierId]);
        $availablePeriodYears = array_map('intval', $yearStmt->fetchAll(PDO::FETCH_COLUMN));
        $currentReportYear = (int) (new DateTimeImmutable('now', new DateTimeZone(CashierReportingPeriod::TIMEZONE)))->format('Y');
        if (!in_array($currentReportYear, $availablePeriodYears, true)) $availablePeriodYears[] = $currentReportYear;
        rsort($availablePeriodYears, SORT_NUMERIC);
    } catch (Throwable $e) {
        error_log('Cashier dashboard reporting years: ' . $e->getMessage());
    }
    $currentStart=CashierReportingPeriod::sql($range['current_start']); $currentEnd=CashierReportingPeriod::sql($range['current_end_exclusive']);
    $priorStart=CashierReportingPeriod::sql($range['prior_start']); $priorEnd=CashierReportingPeriod::sql($range['prior_end_exclusive']);
    $allocationJoin='LEFT JOIN (SELECT payment_id, SUM(allocated_amount) allocated_total FROM payment_allocations GROUP BY payment_id) academic_alloc ON academic_alloc.payment_id=p.payment_id';
    $academicSql="SELECT COALESCE(SUM(academic_alloc.allocated_total),0) FROM payments p {$allocationJoin} WHERE p.transaction_type='Walk-in' AND p.payment_method='Walk-in' AND p.payment_channel='Cash' AND p.verified_by=? AND p.verified_at IS NOT NULL AND {$official} AND p.verified_at>=? AND p.verified_at<?";
    $salesSql="SELECT COALESCE(SUM(cs.total_amount),0) FROM cash_sales cs WHERE cs.cashier_id=? AND cs.sale_status='Completed' AND cs.sold_at>=? AND cs.sold_at<?";
    $academic=cashierScalar($pdo,$academicSql,[$cashierId,$currentStart,$currentEnd]);
    $sales=cashierScalar($pdo,$salesSql,[$cashierId,$currentStart,$currentEnd]);
    $academicPrior=cashierScalar($pdo,$academicSql,[$cashierId,$priorStart,$priorEnd]);
    $salesPrior=cashierScalar($pdo,$salesSql,[$cashierId,$priorStart,$priorEnd]);
    $academicCount=(int)cashierScalar($pdo,str_replace('COALESCE(SUM(academic_alloc.allocated_total),0)','COUNT(DISTINCT p.payment_id)',$academicSql),[$cashierId,$currentStart,$currentEnd]);
    $saleCount=(int)cashierScalar($pdo,str_replace('COALESCE(SUM(cs.total_amount),0)','COUNT(*)',$salesSql),[$cashierId,$currentStart,$currentEnd]);
    $mismatchSql="SELECT COUNT(*) FROM payments p {$allocationJoin} WHERE p.transaction_type='Walk-in' AND p.payment_method='Walk-in' AND p.payment_channel='Cash' AND p.verified_by=? AND p.verified_at IS NOT NULL AND {$official} AND p.verified_at>=? AND p.verified_at<? AND (academic_alloc.payment_id IS NULL OR p.amount<>COALESCE(academic_alloc.allocated_total,0))";
    $allocationMismatchCount=(int)cashierScalar($pdo,$mismatchSql,[$cashierId,$currentStart,$currentEnd]);

    $receiptSql="SELECT COUNT(*) FROM (
      SELECT p.payment_id record_id,MIN(e.rendered_at) first_render FROM payments p JOIN cashier_receipt_print_events e ON e.record_type='payment' AND e.record_id=p.payment_id
       WHERE p.transaction_type='Walk-in' AND p.payment_method='Walk-in' AND p.payment_channel='Cash' AND p.verified_by=? AND p.verified_at IS NOT NULL AND {$official} GROUP BY p.payment_id
      UNION ALL
      SELECT cs.cash_sale_id,MIN(e.rendered_at) FROM cash_sales cs JOIN cashier_receipt_print_events e ON e.record_type='cash_sale' AND e.record_id=cs.cash_sale_id
       WHERE cs.cashier_id=? AND cs.sale_status='Completed' GROUP BY cs.cash_sale_id
    ) originals WHERE first_render>=? AND first_render<?";
    $receipts=(int)cashierScalar($pdo,$receiptSql,[$cashierId,$cashierId,$currentStart,$currentEnd]);

    $optionalErrors=[];
    try{$categoryStmt=$pdo->prepare("SELECT COALESCE(NULLIF(fc.category_name,''),'Unmapped category') label,COALESCE(SUM(pa.allocated_amount),0) total FROM payments p JOIN payment_allocations pa ON pa.payment_id=p.payment_id JOIN billing_items bi ON bi.billing_item_id=pa.billing_item_id JOIN fees f ON f.fee_id=bi.fee_id LEFT JOIN fee_categories fc ON fc.category_id=f.category_id WHERE p.transaction_type='Walk-in' AND p.payment_method='Walk-in' AND p.payment_channel='Cash' AND p.verified_by=? AND p.verified_at IS NOT NULL AND {$official} AND p.verified_at>=? AND p.verified_at<? GROUP BY COALESCE(NULLIF(fc.category_name,''),'Unmapped category') ORDER BY total DESC, label");$categoryStmt->execute([$cashierId,$currentStart,$currentEnd]);$academicCategories=$categoryStmt->fetchAll(PDO::FETCH_ASSOC);}catch(Throwable $e){error_log('Cashier dashboard academic categories: '.$e->getMessage());$academicCategories=[];$optionalErrors['academic_categories']='error';}
    try{$saleCategoryStmt=$pdo->prepare("SELECT li.category_name_snapshot label,COALESCE(SUM(li.line_total),0) total,COALESCE(SUM(li.quantity),0) quantity FROM cash_sale_items li JOIN cash_sales cs ON cs.cash_sale_id=li.cash_sale_id WHERE cs.cashier_id=? AND cs.sale_status='Completed' AND cs.sold_at>=? AND cs.sold_at<? GROUP BY li.category_name_snapshot ORDER BY total DESC,li.category_name_snapshot");$saleCategoryStmt->execute([$cashierId,$currentStart,$currentEnd]);$saleCategories=$saleCategoryStmt->fetchAll(PDO::FETCH_ASSOC);}catch(Throwable $e){error_log('Cashier dashboard sales categories: '.$e->getMessage());$saleCategories=[];$optionalErrors['sale_categories']='error';}

    $recentSql="SELECT * FROM (
      SELECT p.verified_at happened_at,p.payment_id record_id,'Academic Payment' transaction_type,COALESCE(p.receipt_number,p.reference_number) receipt,s.student_number,s.full_name,COALESCE(aa.allocated_total,0) total,'Verified' status,aa.details,p.amount header_amount,(p.amount-COALESCE(aa.allocated_total,0)) amount_difference,IF(aa.payment_id IS NULL OR p.amount<>COALESCE(aa.allocated_total,0),1,0) allocation_mismatch
      FROM payments p JOIN students s ON s.student_id=p.student_id LEFT JOIN (SELECT pa.payment_id,SUM(pa.allocated_amount) allocated_total,CONVERT(GROUP_CONCAT(DISTINCT fc.category_name ORDER BY fc.category_name SEPARATOR ', ') USING utf8mb4) COLLATE utf8mb4_general_ci details FROM payment_allocations pa LEFT JOIN billing_items bi ON bi.billing_item_id=pa.billing_item_id LEFT JOIN fees f ON f.fee_id=bi.fee_id LEFT JOIN fee_categories fc ON fc.category_id=f.category_id GROUP BY pa.payment_id) aa ON aa.payment_id=p.payment_id
      WHERE p.transaction_type='Walk-in' AND p.payment_method='Walk-in' AND p.payment_channel='Cash' AND p.verified_by=? AND p.verified_at IS NOT NULL AND {$official} AND p.verified_at>=? AND p.verified_at<?
      UNION ALL
      SELECT cs.sold_at,cs.cash_sale_id,'School Sale',cs.receipt_number,s.student_number,s.full_name,cs.total_amount,cs.sale_status,CONVERT(GROUP_CONCAT(csi.item_name_snapshot ORDER BY csi.cash_sale_line_id SEPARATOR ', ') USING utf8mb4) COLLATE utf8mb4_general_ci,cs.total_amount,0,0
      FROM cash_sales cs JOIN students s ON s.student_id=cs.student_id JOIN cash_sale_items csi ON csi.cash_sale_id=cs.cash_sale_id
      WHERE cs.cashier_id=? AND cs.sale_status='Completed' AND cs.sold_at>=? AND cs.sold_at<? GROUP BY cs.cash_sale_id
    ) recent ORDER BY happened_at DESC LIMIT 10";
    try{$recentStmt=$pdo->prepare($recentSql);$recentStmt->execute([$cashierId,$currentStart,$currentEnd,$cashierId,$currentStart,$currentEnd]);$recentTransactions=$recentStmt->fetchAll(PDO::FETCH_ASSOC);}catch(Throwable $e){error_log('Cashier dashboard recent activity: '.$e->getMessage());$recentTransactions=[];$optionalErrors['recent_transactions']='error';}
    $missingVerifiedAt=(int)cashierScalar($pdo,"SELECT COUNT(*) FROM payments p WHERE p.transaction_type='Walk-in' AND p.payment_method='Walk-in' AND p.payment_channel='Cash' AND p.payment_status='Verified' AND p.verified_by=? AND p.verified_at IS NULL",[$cashierId]);

    $currentTrend=cashierTrend($pdo,$cashierId,$period,$range['current_start'],$range['current_end_exclusive'],$official);
    $priorTrend=cashierTrend($pdo,$cashierId,$period,$range['prior_start'],$range['prior_end_exclusive'],$official);
    $priorTrend['values']=array_slice(array_pad($priorTrend['values'],count($currentTrend['labels']),0.0),0,count($currentTrend['labels']));
    $sections=array_replace(['kpis'=>'ok','trend'=>array_sum($currentTrend['values'])>0?'ok':'no_data','academic_categories'=>$academicCategories?'ok':'no_data','sale_categories'=>$saleCategories?'ok':'no_data','recent_transactions'=>$recentTransactions?'ok':'no_data'],$optionalErrors);
    $hasPartial=!empty($optionalErrors);

    echo json_encode([
      'ok'=>true,'partial'=>$hasPartial,'period'=>$period,'period_label'=>$range['period_label'],'period_month'=>$range['period_month'],'period_year'=>$range['period_year'],'available_period_years'=>$availablePeriodYears,'timezone'=>$range['timezone'],'comparison_label'=>$range['comparison_label'],'generated_at'=>(new DateTimeImmutable('now',new DateTimeZone(CashierReportingPeriod::TIMEZONE)))->format(DATE_ATOM),'section_status'=>$sections,
      'range'=>['start_at'=>$currentStart,'end_exclusive'=>$currentEnd,'prior_start_at'=>$priorStart,'prior_end_exclusive'=>$priorEnd],
      'kpis'=>['academic'=>$academic,'sales'=>$sales,'total_cash'=>$academic+$sales,'transactions'=>$academicCount+$saleCount,'receipts'=>$receipts],
      'comparison'=>['academic'=>['current'=>$academic,'prior'=>$academicPrior],'sales'=>['current'=>$sales,'prior'=>$salesPrior],'total'=>['current'=>$academic+$sales,'prior'=>$academicPrior+$salesPrior]],
      'trend'=>['current'=>$currentTrend,'prior'=>$priorTrend],
      'academic_categories'=>$academicCategories,'sale_categories'=>$saleCategories,'recent_transactions'=>$recentTransactions,
      'data_integrity'=>['verified_walkins_missing_verified_at'=>$missingVerifiedAt,'allocation_mismatch_count'=>$allocationMismatchCount]
    ]);
} catch(Throwable $e) { error_log('Cashier dashboard error: '.$e->getMessage()); http_response_code(500); echo json_encode(['error'=>'Cashier dashboard data is unavailable. Verify the payment and school-sales setup.']); }
