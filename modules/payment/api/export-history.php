<?php
/**
 * SMS 2 - Export Payment History CSV
 * Honors the same RBAC and filters as the ledger page.
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/authentication.php';
require_once __DIR__ . '/../database/db_connect.php';
require_once __DIR__ . '/../includes/PaymentHistoryService.php';
require_once __DIR__ . '/../includes/SimpleXlsxWriter.php';

requireAuth();
$cashierHistoryMode = getCurrentUserRoleKey() === 'cashier';
requirePaymentPermission($cashierHistoryMode ? 'payment.walkin_history' : 'ledger.export');

function exportHistoryIsOnline(array $pay): bool
{
    $channel = strtolower((string) ($pay['payment_channel'] ?? $pay['payment_method'] ?? ''));
    return in_array($channel, ['gcash', 'maya', 'card', 'qrph', 'paymongo', 'visa', 'mastercard'], true);
}

$filters = [
    'search' => trim((string) ($_GET['search'] ?? '')),
    'status' => (string) ($_GET['status'] ?? ''),
    'channel' => (string) ($_GET['channel'] ?? ''),
    'date_range' => (string) ($_GET['date_range'] ?? ''),
    'processed_by' => (string) ($_GET['processed_by'] ?? ''),
    'category_id' => (string) ($_GET['category_id'] ?? ''),
];

$sort = (string) ($_GET['sort'] ?? 'date');
$dir = strtoupper((string) ($_GET['dir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
$allowedSort = ['date', 'amount', 'status', 'channel', 'reference', 'student', 'total'];
if (!in_array($sort, $allowedSort, true)) {
    $sort = 'date';
}

try {
    $historyService = new PaymentHistoryService($pdo, $cashierHistoryMode ? (int) getCurrentUserId() : null);
    $rows = $historyService->getExportPayments($filters, $sort, $dir, 10000);
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Unable to export payment history.';
    exit;
}

$rowsForExcel = [[
    'OR / Ref No.', 'Receipt No.', 'Student Number', 'Student Name', 'Course',
    'Transaction Type', 'Payment Channel', 'Applied Allocation Amount', 'Processing Fee',
    'Checkout Total', 'Status', 'Legacy Payment Date', 'Created At (Attempt)', 'Verified At (Official)', 'Remarks', 'Header Amount', 'Environment', 'Reporting Date'
]];
foreach ($rows as $pay) {
    $applied = (float) $pay['applied_amount'];
    $isOnline = exportHistoryIsOnline($pay);
    $fee = $isOnline ? (float) ($pay['processing_fee'] ?? 0) : 0;
    $total = $isOnline ? (float) ($pay['checkout_total'] ?? $pay['amount']) : (float) $pay['amount'];
    $rowsForExcel[] = [
        $pay['reference_number'] ?? '', $pay['receipt_number'] ?? '',
        $pay['student_number'] ?? '', $pay['full_name'] ?? '', $pay['course'] ?? '',
        $pay['transaction_type'] ?? '', $pay['payment_channel'] ?? $pay['payment_method'] ?? '',
        number_format($applied, 2, '.', ''), number_format($fee, 2, '.', ''),
        number_format($total, 2, '.', ''), $pay['payment_status'] ?? '',
        $pay['payment_date'] ?? '', $pay['created_at'] ?? '', $pay['verified_at'] ?? '',
        $pay['remarks'] ?? '',
        number_format((float) $pay['amount'], 2, '.', ''), $pay['gateway_environment'] ?? 'Unknown', $pay['recorded_at'] ?? '',
    ];
}
SimpleXlsxWriter::download('accounting-payment-ledger-' . date('Ymd-His') . '.xlsx', $rowsForExcel);
