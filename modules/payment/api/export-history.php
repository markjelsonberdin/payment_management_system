<?php
/**
 * SMS 2 - Export Payment History CSV
 * Honors the same RBAC and filters as the ledger page.
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/authentication.php';
require_once __DIR__ . '/../database/db_connect.php';
require_once __DIR__ . '/../includes/PaymentHistoryService.php';

requireAuth();
requirePaymentPermission('payment.ledger');

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
];

$sort = (string) ($_GET['sort'] ?? 'date');
$dir = strtoupper((string) ($_GET['dir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
$allowedSort = ['date', 'amount', 'status', 'channel', 'reference', 'student', 'total'];
if (!in_array($sort, $allowedSort, true)) {
    $sort = 'date';
}

try {
    $historyService = new PaymentHistoryService($pdo);
    $rows = $historyService->getExportPayments($filters, $sort, $dir, 10000);
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Unable to export payment history.';
    exit;
}

$filename = 'payment-history-' . date('Ymd-His') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, [
    'OR / Ref No.',
    'Receipt No.',
    'Student Number',
    'Student Name',
    'Course',
    'Payment Channel',
    'Amount Applied',
    'Processing Fee',
    'Total',
    'Status',
    'Payment Date',
    'Created At',
    'Verified At',
    'Processed By (verified_by)',
    'Remarks',
]);

foreach ($rows as $pay) {
    $applied = (float) $pay['amount'];
    $isOnline = exportHistoryIsOnline($pay);
    $fee = $isOnline ? (float) ($pay['processing_fee'] ?? 0) : 0;
    $total = $isOnline ? (float) ($pay['checkout_total'] ?? $applied) : $applied;

    fputcsv($out, [
        $pay['reference_number'] ?? '',
        $pay['receipt_number'] ?? '',
        $pay['student_number'] ?? '',
        $pay['full_name'] ?? '',
        $pay['course'] ?? '',
        $pay['payment_channel'] ?? $pay['payment_method'] ?? '',
        number_format($applied, 2, '.', ''),
        number_format($fee, 2, '.', ''),
        number_format($total, 2, '.', ''),
        $pay['payment_status'] ?? '',
        $pay['payment_date'] ?? '',
        $pay['created_at'] ?? '',
        $pay['verified_at'] ?? '',
        $pay['verified_by'] ?? '',
        $pay['remarks'] ?? '',
    ]);
}

fclose($out);
exit;
