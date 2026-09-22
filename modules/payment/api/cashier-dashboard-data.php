<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once __DIR__ . '/../database/db_connect.php';
require_once __DIR__ . '/../includes/PaymentReportingScope.php';

header('Content-Type: application/json; charset=utf-8');
requireAuth();
requirePaymentPermission('payment.cashier_dashboard');

$cashierId = (int) getCurrentUserId();
$period = (string) ($_GET['period'] ?? 'today');
if (!in_array($period, ['today', 'week', 'month', 'year'], true)) {
    $period = 'today';
}

function cashierRange(string $period, bool $prior = false): array
{
    $today = new DateTimeImmutable('today');
    if ($period === 'today') {
        $start = $prior ? $today->modify('-1 day') : $today;
        $end = $start;
    } elseif ($period === 'week') {
        $currentStart = $today->modify('monday this week');
        $daysElapsed = (int) $currentStart->diff($today)->format('%a');
        $start = $prior ? $currentStart->modify('-7 days') : $currentStart;
        $end = $start->modify('+' . $daysElapsed . ' days');
    } elseif ($period === 'month') {
        $currentStart = $today->modify('first day of this month');
        $daysElapsed = (int) $currentStart->diff($today)->format('%a');
        $start = $prior ? $currentStart->modify('first day of previous month') : $currentStart;
        $last = $start->modify('last day of this month');
        $end = $start->modify('+' . $daysElapsed . ' days');
        if ($end > $last) $end = $last;
    } else {
        $currentStart = $today->setDate((int) $today->format('Y'), 1, 1);
        $start = $prior ? $currentStart->modify('-1 year') : $currentStart;
        $end = $prior ? $today->modify('-1 year') : $today;
    }
    return [$start->format('Y-m-d'), $end->format('Y-m-d')];
}

function cashierScalar(PDO $pdo, string $sql, array $params): float
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (float) ($stmt->fetchColumn() ?: 0);
}

function cashierTrend(PDO $pdo, int $cashierId, string $period, string $start, string $end, bool $prior, string $official): array
{
    $academicGroup = $period === 'year' ? "DATE_FORMAT(p.created_at, '%Y-%m')" : "DATE(p.created_at)";
    $saleGroup = $period === 'year' ? "DATE_FORMAT(sold_at, '%Y-%m')" : "DATE(sold_at)";
    $academicSql = "SELECT {$academicGroup} AS point, COALESCE(SUM(amount),0) AS total
        FROM payments p
        WHERE p.transaction_type='Walk-in' AND p.payment_channel='Cash' AND p.verified_by=?
          AND {$official} AND DATE(p.created_at) BETWEEN ? AND ?
        GROUP BY {$academicGroup}";
    $saleSql = "SELECT {$saleGroup} AS point, COALESCE(SUM(total_amount),0) AS total
        FROM cash_sales
        WHERE cashier_id=? AND sale_status='Completed' AND DATE(sold_at) BETWEEN ? AND ?
        GROUP BY {$saleGroup}";

    $points = [];
    foreach ([[$academicSql, [$cashierId, $start, $end]], [$saleSql, [$cashierId, $start, $end]]] as [$sql, $params]) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $points[$row['point']] = ($points[$row['point']] ?? 0) + (float) $row['total'];
        }
    }

    $cursor = new DateTimeImmutable($start);
    $last = new DateTimeImmutable($end);
    $labels = [];
    $values = [];
    while ($cursor <= $last) {
        $key = $period === 'year' ? $cursor->format('Y-m') : $cursor->format('Y-m-d');
        if ($period === 'year') {
            $labels[$key] = $cursor->format('M');
            $cursor = $cursor->modify('first day of next month');
        } else {
            $labels[$key] = $period === 'today' ? $cursor->format('M d') : $cursor->format('M d');
            $cursor = $cursor->modify('+1 day');
        }
        $values[] = round((float) ($points[$key] ?? 0), 2);
    }
    return ['labels' => array_values($labels), 'values' => $values];
}

try {
    [$start, $end] = cashierRange($period);
    [$priorStart, $priorEnd] = cashierRange($period, true);
    $official = PaymentReportingScope::officialCondition('p');

    $academicSql = "SELECT COALESCE(SUM(p.amount),0) FROM payments p
        WHERE p.transaction_type='Walk-in' AND p.payment_channel='Cash' AND p.verified_by=?
          AND {$official} AND DATE(p.created_at) BETWEEN ? AND ?";
    $salesSql = "SELECT COALESCE(SUM(total_amount),0) FROM cash_sales
        WHERE cashier_id=? AND sale_status='Completed' AND DATE(sold_at) BETWEEN ? AND ?";
    $academic = cashierScalar($pdo, $academicSql, [$cashierId, $start, $end]);
    $sales = cashierScalar($pdo, $salesSql, [$cashierId, $start, $end]);
    $academicPrior = cashierScalar($pdo, $academicSql, [$cashierId, $priorStart, $priorEnd]);
    $salesPrior = cashierScalar($pdo, $salesSql, [$cashierId, $priorStart, $priorEnd]);

    $academicCount = (int) cashierScalar($pdo, str_replace('COALESCE(SUM(p.amount),0)', 'COUNT(*)', $academicSql), [$cashierId, $start, $end]);
    $saleCount = (int) cashierScalar($pdo, str_replace('COALESCE(SUM(total_amount),0)', 'COUNT(*)', $salesSql), [$cashierId, $start, $end]);

    $categoryStmt = $pdo->prepare("SELECT fc.category_name AS label, COALESCE(SUM(pa.allocated_amount),0) AS total
        FROM payments p
        JOIN payment_allocations pa ON pa.payment_id=p.payment_id
        JOIN billing_items bi ON bi.billing_item_id=pa.billing_item_id
        JOIN fees f ON f.fee_id=bi.fee_id
        JOIN fee_categories fc ON fc.category_id=f.category_id
        WHERE p.transaction_type='Walk-in' AND p.payment_channel='Cash' AND p.verified_by=?
          AND {$official} AND DATE(p.created_at) BETWEEN ? AND ?
        GROUP BY fc.category_id, fc.category_name
        ORDER BY total DESC, fc.category_name ASC");
    $categoryStmt->execute([$cashierId, $start, $end]);

    $saleCategoryStmt = $pdo->prepare("SELECT li.category_name_snapshot AS label, COALESCE(SUM(li.line_total),0) AS total, COALESCE(SUM(li.quantity),0) AS quantity
        FROM cash_sale_items li
        JOIN cash_sales cs ON cs.cash_sale_id=li.cash_sale_id
        WHERE cs.cashier_id=? AND cs.sale_status='Completed' AND DATE(cs.sold_at) BETWEEN ? AND ?
        GROUP BY li.category_name_snapshot
        ORDER BY total DESC, li.category_name_snapshot ASC");
    $saleCategoryStmt->execute([$cashierId, $start, $end]);

    $recentSql = "SELECT * FROM (
        SELECT p.created_at AS happened_at, p.payment_id AS record_id, 'Academic Payment' AS transaction_type,
               COALESCE(p.receipt_number,p.reference_number) AS receipt, s.student_number, s.full_name,
               p.amount AS total, 'Cash' AS channel, 'Verified' AS status,
               GROUP_CONCAT(DISTINCT fc.category_name ORDER BY fc.category_name SEPARATOR ', ') AS details
        FROM payments p
        JOIN students s ON s.student_id=p.student_id
        LEFT JOIN payment_allocations pa ON pa.payment_id=p.payment_id
        LEFT JOIN billing_items bi ON bi.billing_item_id=pa.billing_item_id
        LEFT JOIN fees f ON f.fee_id=bi.fee_id
        LEFT JOIN fee_categories fc ON fc.category_id=f.category_id
        WHERE p.transaction_type='Walk-in' AND p.payment_channel='Cash' AND p.verified_by=?
          AND {$official} AND DATE(p.created_at) BETWEEN ? AND ?
        GROUP BY p.payment_id
        UNION ALL
        SELECT cs.sold_at AS happened_at, cs.cash_sale_id AS record_id, 'School Sale' AS transaction_type,
               cs.receipt_number AS receipt, s.student_number, s.full_name, cs.total_amount AS total,
               'Cash' AS channel, cs.sale_status AS status,
               GROUP_CONCAT(csi.item_name_snapshot ORDER BY csi.cash_sale_line_id SEPARATOR ', ') AS details
        FROM cash_sales cs
        JOIN students s ON s.student_id=cs.student_id
        JOIN cash_sale_items csi ON csi.cash_sale_id=cs.cash_sale_id
        WHERE cs.cashier_id=? AND cs.sale_status='Completed' AND DATE(cs.sold_at) BETWEEN ? AND ?
        GROUP BY cs.cash_sale_id
    ) recent ORDER BY happened_at DESC LIMIT 10";
    $recentStmt = $pdo->prepare($recentSql);
    $recentStmt->execute([$cashierId, $start, $end, $cashierId, $start, $end]);

    echo json_encode([
        'period' => $period,
        'range' => ['start' => $start, 'end' => $end, 'prior_start' => $priorStart, 'prior_end' => $priorEnd],
        'kpis' => [
            'academic' => $academic,
            'sales' => $sales,
            'total_cash' => $academic + $sales,
            'transactions' => $academicCount + $saleCount,
            'receipts' => $academicCount + $saleCount,
        ],
        'comparison' => [
            'current_total' => $academic + $sales,
            'prior_total' => $academicPrior + $salesPrior,
        ],
        'trend' => [
            'current' => cashierTrend($pdo, $cashierId, $period, $start, $end, false, $official),
            'prior' => cashierTrend($pdo, $cashierId, $period, $priorStart, $priorEnd, true, $official),
        ],
        'academic_categories' => $categoryStmt->fetchAll(PDO::FETCH_ASSOC),
        'sale_categories' => $saleCategoryStmt->fetchAll(PDO::FETCH_ASSOC),
        'recent_transactions' => $recentStmt->fetchAll(PDO::FETCH_ASSOC),
        'inventory_supported' => false,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Cashier dashboard data is unavailable. Verify the payment and school-sales database migrations.']);
}