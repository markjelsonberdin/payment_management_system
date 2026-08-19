<?php
/**
 * Finance Dashboard Data API
 * Returns JSON data for the Chart.js dashboard.
 */
require_once __DIR__ . '/../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';

header('Content-Type: application/json');

// Ensure only authorized users can access this data
$roleKey = getCurrentUserRoleKey();
if (!in_array($roleKey, ['admin', 'superadmin', 'finance', 'cashier'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized access']);
    exit;
}

$paymentDb = null;
$smsDb = null;
try {
    $paymentDb = new PDO('mysql:host=127.0.0.1;port=3307;dbname=payment_db;charset=utf8mb4', 'root', '');
    $paymentDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $smsDb = new PDO('mysql:host=127.0.0.1;port=3307;dbname=sms2_db;charset=utf8mb4', 'root', '');
    $smsDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

$data = [
    'kpis' => [
        'collections_today' => 0,
        'pending_payments' => 0,
        'collected_month' => 0,
        'outstanding_balance' => 0
    ],
    'collections_by_category' => [],
    'collection_trend' => [
        'labels' => [],
        'data' => []
    ],
    'payment_status' => [
        'labels' => ['Verified', 'Pending', 'Failed', 'Cancelled'],
        'data' => [0, 0, 0, 0]
    ],
    'payment_channels' => [],
    'recent_activity' => []
];

try {
    // --- 1. KPIs ---
    
    // Collections Today
    $stmt = $paymentDb->query("SELECT SUM(amount) FROM payments WHERE DATE(payment_date) = CURDATE() AND payment_status = 'Verified'");
    $data['kpis']['collections_today'] = (float)($stmt->fetchColumn() ?: 0);

    // Pending Payments
    $stmt = $paymentDb->query("SELECT COUNT(*) FROM payments WHERE payment_status = 'Pending'");
    $data['kpis']['pending_payments'] = (int)($stmt->fetchColumn() ?: 0);

    // Total Collected Month
    $stmt = $paymentDb->query("SELECT SUM(amount) FROM payments WHERE MONTH(payment_date) = MONTH(CURDATE()) AND YEAR(payment_date) = YEAR(CURDATE()) AND payment_status = 'Verified'");
    $data['kpis']['collected_month'] = (float)($stmt->fetchColumn() ?: 0);

    // Outstanding Balance (Sum of remaining balance in billing)
    $stmt = $paymentDb->query("SELECT SUM(remaining_balance) FROM billing WHERE billing_status != 'Paid'");
    $data['kpis']['outstanding_balance'] = (float)($stmt->fetchColumn() ?: 0);

    // --- 2. Collection by Category ---
    // Exclude 'Tuition' as per requirements
    $stmt = $paymentDb->query("
        SELECT fc.category_name, SUM(pa.allocated_amount) as total
        FROM payment_allocations pa
        JOIN billing_items bi ON pa.billing_item_id = bi.billing_item_id
        JOIN fees f ON bi.fee_id = f.fee_id
        JOIN fee_categories fc ON f.category_id = fc.category_id
        WHERE fc.category_name != 'Tuition'
        GROUP BY fc.category_name
        ORDER BY total DESC
    ");
    $data['collections_by_category'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // --- 3. Collection Trend (Last 7 Days) ---
    $stmt = $paymentDb->query("
        SELECT DATE(payment_date) as date, SUM(amount) as total 
        FROM payments 
        WHERE payment_status = 'Verified' 
          AND payment_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
        GROUP BY DATE(payment_date)
        ORDER BY date ASC
    ");
    $trends = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fill missing days with 0
    $trendMap = [];
    foreach ($trends as $t) {
        $trendMap[$t['date']] = (float)$t['total'];
    }
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $data['collection_trend']['labels'][] = date('M d', strtotime("-$i days"));
        $data['collection_trend']['data'][] = $trendMap[$date] ?? 0;
    }

    // --- 4. Payment Status ---
    $stmt = $paymentDb->query("
        SELECT payment_status, COUNT(*) as count 
        FROM payments 
        GROUP BY payment_status
    ");
    $statuses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $statusMap = [];
    foreach ($statuses as $s) {
        $statusMap[$s['payment_status']] = (int)$s['count'];
    }
    $data['payment_status']['data'] = [
        $statusMap['Verified'] ?? 0,
        $statusMap['Pending'] ?? 0,
        $statusMap['Failed'] ?? 0,
        $statusMap['Cancelled'] ?? 0
    ];

    // --- 5. Payment Channel ---
    $stmt = $paymentDb->query("
        SELECT payment_method, SUM(amount) as total 
        FROM payments 
        WHERE payment_status = 'Verified'
        GROUP BY payment_method
    ");
    $channels = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($channels as $c) {
        $data['payment_channels'][] = [
            'method' => $c['payment_method'],
            'total' => (float)$c['total']
        ];
    }

    // --- 6. Recent Payment Activity ---
    $stmt = $smsDb->query("
        SELECT detail, created_at 
        FROM activity_logs 
        WHERE module_key = 'payment' 
        ORDER BY created_at DESC 
        LIMIT 10
    ");
    $data['recent_activity'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($data);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
