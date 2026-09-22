<?php
require_once __DIR__ . '/../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once __DIR__ . '/../database/db_connect.php';
require_once __DIR__ . '/../includes/PaymentReportingScope.php';
require_once __DIR__ . '/../includes/SimpleXlsxWriter.php';

requireAuth();
requirePaymentPermission('payment.walkin_history');
$cashierId = (int) getCurrentUserId();

$dateRange = (string) ($_GET['date_range'] ?? '');
$search = trim((string) ($_GET['search'] ?? ''));
$status = (string) ($_GET['status'] ?? '');
$channel = (string) ($_GET['channel'] ?? '');

try {
    $official = PaymentReportingScope::officialCondition('p');
    $where = ['1=1'];
    $params = [$cashierId, $cashierId];

    if (in_array($dateRange, ['today', 'week', 'month', 'year'], true)) {
        $where[] = match ($dateRange) {
            'today' => 'DATE(h.recorded_at)=CURDATE()',
            'week' => 'DATE(h.recorded_at)>=DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)',
            'month' => 'YEAR(h.recorded_at)=YEAR(CURDATE()) AND MONTH(h.recorded_at)=MONTH(CURDATE())',
            'year' => 'YEAR(h.recorded_at)=YEAR(CURDATE())',
        };
    }
    if ($search !== '') {
        $where[] = '(h.student_number LIKE ? OR h.full_name LIKE ? OR h.receipt LIKE ? OR h.reference LIKE ? OR h.details LIKE ?)';
        $needle = '%' . $search . '%';
        array_push($params, $needle, $needle, $needle, $needle, $needle);
    }
    if (in_array($status, ['Verified', 'Completed'], true)) {
        $where[] = 'h.status = ?';
        $params[] = $status;
    }
    if ($channel !== '' && $channel !== 'Cash') {
        $where[] = '1=0';
    }

    $sql = "SELECT * FROM (
        SELECT 'Academic Payment' AS transaction_type, p.payment_id AS record_id,
            COALESCE(p.receipt_number,p.reference_number) AS receipt, p.reference_number,
            s.student_number, s.full_name, p.amount AS total, p.created_at AS recorded_at,
            p.remarks, 'Cash' AS payment_method, p.payment_status AS status,
            GROUP_CONCAT(DISTINCT fc.category_name ORDER BY fc.category_name SEPARATOR ', ') AS details
        FROM payments p
        JOIN students s ON s.student_id=p.student_id
        LEFT JOIN payment_allocations pa ON pa.payment_id=p.payment_id
        LEFT JOIN billing_items bi ON bi.billing_item_id=pa.billing_item_id
        LEFT JOIN fees f ON f.fee_id=bi.fee_id
        LEFT JOIN fee_categories fc ON fc.category_id=f.category_id
        WHERE p.transaction_type='Walk-in' AND p.payment_channel='Cash' AND p.verified_by=? AND {$official}
        GROUP BY p.payment_id
        UNION ALL
        SELECT 'School Sale', cs.cash_sale_id, cs.receipt_number, cs.receipt_number,
            s.student_number, s.full_name, cs.total_amount, cs.sold_at,
            cs.remarks, 'Cash', cs.sale_status,
            GROUP_CONCAT(csi.item_name_snapshot ORDER BY csi.cash_sale_line_id SEPARATOR ', ')
        FROM cash_sales cs
        JOIN students s ON s.student_id=cs.student_id
        JOIN cash_sale_items csi ON csi.cash_sale_id=cs.cash_sale_id
        WHERE cs.cashier_id=? AND cs.sale_status='Completed'
        GROUP BY cs.cash_sale_id
    ) h WHERE " . implode(' AND ', $where) . ' ORDER BY h.recorded_at DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = [['Transaction Date', 'Reference No.', 'OR No.', 'Student Number', 'Student Name', 'Transaction Type', 'Fee Category / Item', 'Amount', 'Payment Method', 'Status', 'Processed By', 'Remarks']];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rows[] = [$row['recorded_at'], $row['reference'], $row['receipt'], $row['student_number'], $row['full_name'], $row['transaction_type'], $row['details'], $row['total'], $row['payment_method'], $row['status'], getCurrentUserName(), $row['remarks']];
    }
    SimpleXlsxWriter::download('cashier-transactions-' . date('Ymd-His') . '.xlsx', $rows);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Unable to export cashier history.';
}