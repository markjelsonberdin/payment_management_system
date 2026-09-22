<?php
require_once __DIR__ . '/../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once __DIR__ . '/../database/db_connect.php';
require_once __DIR__ . '/../includes/PaymentReportingScope.php';

header('Content-Type: application/json');
requireAuth(); requirePaymentPermission('payment.cashier_dashboard');
$cashierId = (int) getCurrentUserId();
$period = (string) ($_GET['period'] ?? 'today');
if (!in_array($period, ['today', 'week', 'month', 'year'], true)) $period = 'today';
$ranges = [
 'today' => ['CURDATE()', 'CURDATE()', 'DATE_SUB(CURDATE(), INTERVAL 1 YEAR)', 'DATE_SUB(CURDATE(), INTERVAL 1 YEAR)'],
 'week' => ['DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)', 'CURDATE()', 'DATE_SUB(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 1 YEAR)', 'DATE_SUB(CURDATE(), INTERVAL 1 YEAR)'],
 'month' => ['DATE_FORMAT(CURDATE(), "%Y-%m-01")', 'LAST_DAY(CURDATE())', 'DATE_SUB(DATE_FORMAT(CURDATE(), "%Y-%m-01"), INTERVAL 1 YEAR)', 'LAST_DAY(DATE_SUB(CURDATE(), INTERVAL 1 YEAR))'],
 'year' => ['DATE_FORMAT(CURDATE(), "%Y-01-01")', 'DATE_FORMAT(CURDATE(), "%Y-12-31")', 'DATE_SUB(DATE_FORMAT(CURDATE(), "%Y-01-01"), INTERVAL 1 YEAR)', 'DATE_SUB(DATE_FORMAT(CURDATE(), "%Y-12-31"), INTERVAL 1 YEAR)'],
];
[$start, $end, $priorStart, $priorEnd] = $ranges[$period];
$official = PaymentReportingScope::officialCondition('p');
$sum = function(string $sql) use ($pdo, $cashierId): float { $s=$pdo->prepare($sql); $s->execute([$cashierId]); return (float) ($s->fetchColumn() ?: 0); };
try {
 $academic = $sum("SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE p.transaction_type='Walk-in' AND p.payment_channel='Cash' AND p.verified_by=? AND {$official} AND p.payment_date BETWEEN {$start} AND {$end}");
 $academicPrior = $sum("SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE p.transaction_type='Walk-in' AND p.payment_channel='Cash' AND p.verified_by=? AND {$official} AND p.payment_date BETWEEN {$priorStart} AND {$priorEnd}");
 $sales = $sum("SELECT COALESCE(SUM(total_amount),0) FROM cash_sales WHERE cashier_id=? AND sale_status='Completed' AND DATE(sold_at) BETWEEN {$start} AND {$end}");
 $salesPrior = $sum("SELECT COALESCE(SUM(total_amount),0) FROM cash_sales WHERE cashier_id=? AND sale_status='Completed' AND DATE(sold_at) BETWEEN {$priorStart} AND {$priorEnd}");
 $transactions = $sum("SELECT COUNT(*) FROM payments p WHERE p.transaction_type='Walk-in' AND p.payment_channel='Cash' AND p.verified_by=? AND {$official} AND p.payment_date BETWEEN {$start} AND {$end}") + $sum("SELECT COUNT(*) FROM cash_sales WHERE cashier_id=? AND sale_status='Completed' AND DATE(sold_at) BETWEEN {$start} AND {$end}");
 $outstanding = (float) ($pdo->query("SELECT COALESCE(SUM(remaining_balance),0) FROM billing WHERE billing_status IN ('Unpaid','Partial')")->fetchColumn() ?: 0);
 $currentCollectible = (float) ($pdo->query("SELECT COALESCE(SUM(total_amount-discount_amount),0) FROM billing WHERE YEAR(created_at)=YEAR(CURDATE())")->fetchColumn() ?: 0);
 $currentAcademicAll = (float) ($pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments p WHERE {$official} AND YEAR(payment_date)=YEAR(CURDATE())")->fetchColumn() ?: 0);
 $priorCollectible = (float) ($pdo->query("SELECT COALESCE(SUM(total_amount-discount_amount),0) FROM billing WHERE YEAR(created_at)=YEAR(CURDATE())-1")->fetchColumn() ?: 0);
 $priorAcademicAll = (float) ($pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments p WHERE {$official} AND YEAR(payment_date)=YEAR(CURDATE())-1")->fetchColumn() ?: 0);
 $efficiency = $currentCollectible > 0 ? ($currentAcademicAll/$currentCollectible)*100 : 0;
 $priorEfficiency = $priorCollectible > 0 ? ($priorAcademicAll/$priorCollectible)*100 : 0;
 $cat = $pdo->prepare("SELECT category_name_snapshot AS label, SUM(line_total) total FROM cash_sale_items li JOIN cash_sales s ON s.cash_sale_id=li.cash_sale_id WHERE s.cashier_id=? AND s.sale_status='Completed' AND DATE(s.sold_at) BETWEEN {$start} AND {$end} GROUP BY category_name_snapshot ORDER BY total DESC"); $cat->execute([$cashierId]);
 echo json_encode(['period'=>$period,'kpis'=>['academic'=>$academic,'sales'=>$sales,'total'=>$academic+$sales,'transactions'=>(int)$transactions,'outstanding'=>$outstanding,'efficiency'=>$efficiency],'comparison'=>['sales_percent'=>$salesPrior>0?(($sales-$salesPrior)/$salesPrior)*100:null,'academic_percent'=>$academicPrior>0?(($academic-$academicPrior)/$academicPrior)*100:null,'efficiency_points'=>$efficiency-$priorEfficiency],'sales_categories'=>$cat->fetchAll(PDO::FETCH_ASSOC)]);
} catch (Throwable $e) { http_response_code(500); echo json_encode(['error'=>'Cashier dashboard data is unavailable. Apply the cashier school-sales migration first.']); }
