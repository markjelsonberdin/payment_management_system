<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/modules/student-portal/config/config.php';

$pdo = studentPortalDb();
$stmtItems = $pdo->prepare("
    SELECT bi.*, f.fee_name, f.description, f.category_id, fc.category_name 
    FROM payment_db.billing_items bi 
    JOIN payment_db.fees f ON bi.fee_id = f.fee_id 
    LEFT JOIN payment_db.fee_categories fc ON f.category_id = fc.category_id
    WHERE bi.billing_id = 2
");
$stmtItems->execute();
$assessmentBreakdown = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

print_r($assessmentBreakdown);

$payableCategories = [];
foreach ($assessmentBreakdown as $item) {
    if ($item['category_id'] == 1) continue; // Skip Tuition
    if ($item['remaining_amount'] <= 0) continue;

    $catId = $item['category_id'] ?? 'OTHER';
    if (!isset($payableCategories[$catId])) {
        $payableCategories[$catId] = [
            'id' => $catId,
            'name' => $item['category_name'] ?: 'Other Fees',
            'amount' => 0.00
        ];
    }
    $payableCategories[$catId]['amount'] += (float)$item['remaining_amount'];
}
print_r($payableCategories);
