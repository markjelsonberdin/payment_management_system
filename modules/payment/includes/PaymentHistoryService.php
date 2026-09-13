<?php
/**
 * Payment History & Ledger Service
 * Handles fetching of historical payments, official receipts, and ledger allocations.
 */
require_once __DIR__ . '/PaymentReportingScope.php';

class PaymentHistoryService {
    private $pdo;

    public const STATUSES = ['Pending', 'Verified', 'Rejected', 'Failed', 'Cancelled', 'Expired'];
    public const CHANNELS = ['Cash', 'GCash', 'Maya', 'Visa', 'Mastercard', 'Bank', 'PayMongo', 'QRPh'];
    public const DATE_RANGES = ['today', 'week', 'month'];

    private const SORT_COLUMNS = [
        'date' => 'p.created_at',
        'amount' => 'p.amount',
        'status' => 'p.payment_status',
        'channel' => 'p.payment_channel',
        'reference' => 'p.reference_number',
        'student' => 's.full_name',
        'total' => 'COALESCE(p.checkout_total, p.amount)',
    ];

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Retrieves summary statistics for the Payment Dashboard/Ledger.
     */
    public function getPaymentSummary() {
        $official = PaymentReportingScope::officialCondition();
        $stmt = $this->pdo->query("
            SELECT
                COALESCE(SUM(CASE WHEN {$official} THEN amount ELSE 0 END), 0) AS total_collections,
                COUNT(payment_id) AS total_transactions,
                COALESCE(SUM(CASE WHEN payment_status = 'Pending' THEN 1 ELSE 0 END), 0) AS pending_transactions,
                COALESCE(SUM(CASE WHEN {$official} AND payment_date = CURDATE() THEN amount ELSE 0 END), 0) AS today_collections
            FROM payments
        ");
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [
            'total_collections' => 0,
            'total_transactions' => 0,
            'pending_transactions' => 0,
            'today_collections' => 0,
        ];
    }

    /**
     * Retrieves all historical payments, optionally filtered by a search query.
     *
     * @param string $search
     * @return array
     */
    public function getAllPayments($search = '') {
        // We only join with students and billing
        // No cross-database joins to sms2_db.users to prevent schema coupling issues.
        $query = "
            SELECT
                p.payment_id, p.reference_number, p.amount, p.processing_fee, p.checkout_total, p.category_id, p.checkout_session_id, p.payment_method, p.payment_status, p.payment_date, p.created_at, p.payment_channel,
                s.student_number, s.full_name, s.course,
                b.total_amount, b.remaining_balance, b.billing_status
            FROM payments p
            JOIN students s ON p.student_id = s.student_id
            LEFT JOIN billing b ON p.billing_id = b.billing_id
            WHERE p.payment_status != 'Pending'
        ";

        $params = [];
        if (!empty($search)) {
            $query .= " AND (s.student_number LIKE :search OR s.full_name LIKE :search OR p.reference_number LIKE :search)";
            $params[':search'] = "%$search%";
        }

        $query .= " ORDER BY p.created_at DESC";

        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Sanitize incoming filter values against supported schema enums.
     */
    public function normalizeFilters(array $filters): array {
        $status = (string) ($filters['status'] ?? '');
        $channel = (string) ($filters['channel'] ?? '');
        $dateRange = (string) ($filters['date_range'] ?? '');
        $processedBy = trim((string) ($filters['processed_by'] ?? ''));

        return [
            'search' => trim((string) ($filters['search'] ?? '')),
            'status' => in_array($status, self::STATUSES, true) ? $status : '',
            'channel' => in_array($channel, self::CHANNELS, true) ? $channel : '',
            'date_range' => in_array($dateRange, self::DATE_RANGES, true) ? $dateRange : '',
            'processed_by' => ($processedBy !== '' && ctype_digit($processedBy)) ? $processedBy : '',
        ];
    }

    /**
     * Paginated payment list with dynamic filters and whitelist sorting.
     */
    public function getPaginatedPayments(array $filters, string $sortColumn = 'date', string $sortDir = 'DESC', int $limit = 15, int $offset = 0): array {
        $filters = $this->normalizeFilters($filters);
        [$whereSql, $params] = $this->buildFilterClause($filters);

        $orderBy = self::SORT_COLUMNS[$sortColumn] ?? self::SORT_COLUMNS['date'];
        $dir = strtoupper($sortDir) === 'ASC' ? 'ASC' : 'DESC';
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        $query = "
            SELECT
                p.payment_id, p.billing_id, p.verified_by, p.verified_at, p.receipt_number, p.remarks,
                p.reference_number, p.amount, p.processing_fee, p.checkout_total, p.category_id,
                p.checkout_session_id, p.payment_method, p.payment_status, p.payment_date,
                p.created_at, p.payment_channel, p.transaction_type,
                s.student_number, s.full_name, s.course,
                b.total_amount, b.discount_amount, b.remaining_balance, b.billing_status,
                b.billing_type, b.academic_year, b.semester
            FROM payments p
            JOIN students s ON p.student_id = s.student_id
            LEFT JOIN billing b ON p.billing_id = b.billing_id
            WHERE {$whereSql}
            ORDER BY {$orderBy} {$dir}, p.payment_id DESC
            LIMIT {$limit} OFFSET {$offset}
        ";

        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getTotalPaymentsCount(array $filters): int {
        $filters = $this->normalizeFilters($filters);
        [$whereSql, $params] = $this->buildFilterClause($filters);

        $stmt = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM payments p
            JOIN students s ON p.student_id = s.student_id
            LEFT JOIN billing b ON p.billing_id = b.billing_id
            WHERE {$whereSql}
        ");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Full filtered dataset for CSV export (capped).
     */
    public function getExportPayments(array $filters, string $sortColumn = 'date', string $sortDir = 'DESC', int $maxRows = 10000): array {
        return $this->getPaginatedPayments($filters, $sortColumn, $sortDir, $maxRows, 0);
    }

    /**
     * Allocations grouped by payment_id for the current page.
     */
    public function getAllocationsByPaymentIds(array $paymentIds): array {
        $paymentIds = array_values(array_filter(array_map('intval', $paymentIds)));
        if (!$paymentIds) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($paymentIds), '?'));
        $stmt = $this->pdo->prepare("
            SELECT pa.payment_id, pa.allocated_amount, pa.allocated_at, bi.fee_name
            FROM payment_allocations pa
            JOIN billing_items bi ON pa.billing_item_id = bi.billing_item_id
            WHERE pa.payment_id IN ({$placeholders})
            ORDER BY pa.allocated_at ASC
        ");
        $stmt->execute($paymentIds);

        $grouped = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $grouped[(int) $row['payment_id']][] = $row;
        }
        return $grouped;
    }

    /**
     * Opening / payments / closing snapshot per billing (no refunds/adjustments tables).
     */
    public function getLedgerSummariesByBillingIds(array $billingIds): array {
        $billingIds = array_values(array_filter(array_map('intval', $billingIds)));
        if (!$billingIds) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($billingIds), '?'));
        $stmt = $this->pdo->prepare("
            SELECT
                billing_id,
                total_amount,
                COALESCE(discount_amount, 0) AS discount_amount,
                remaining_balance
            FROM billing
            WHERE billing_id IN ({$placeholders})
        ");
        $stmt->execute($billingIds);
        $ledgers = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $opening = (float) $row['total_amount'] - (float) $row['discount_amount'];
            $ledgers[(int) $row['billing_id']] = [
                'opening_balance' => $opening,
                'payments_total' => 0.0,
                'closing_balance' => (float) $row['remaining_balance'],
            ];
        }

        $official = PaymentReportingScope::officialCondition();
        $payStmt = $this->pdo->prepare("
            SELECT billing_id, COALESCE(SUM(amount), 0) AS payments_total
            FROM payments
            WHERE billing_id IN ({$placeholders})
              AND {$official}
            GROUP BY billing_id
        ");
        $payStmt->execute($billingIds);
        foreach ($payStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $bid = (int) $row['billing_id'];
            if (isset($ledgers[$bid])) {
                $ledgers[$bid]['payments_total'] = (float) $row['payments_total'];
            }
        }

        return $ledgers;
    }

    /**
     * Retrieves a detailed breakdown of a single payment, including where the money was allocated.
     *
     * @param int $paymentId
     * @return array|null Returns payment header and a list of allocations.
     */
    public function getPaymentDetails($paymentId) {
        $stmtHeader = $this->pdo->prepare("
            SELECT
                p.payment_id, p.billing_id, p.verified_by, p.verified_at, p.receipt_number,
                p.reference_number, p.amount, p.processing_fee, p.checkout_total, p.category_id,
                p.checkout_session_id, p.payment_method, p.payment_status, p.created_at,
                p.payment_date, p.remarks, p.payment_channel, p.transaction_type,
                s.student_number, s.full_name, s.course,
                b.billing_type, b.academic_year, b.semester, b.total_amount, b.discount_amount, b.remaining_balance
            FROM payments p
            JOIN students s ON p.student_id = s.student_id
            LEFT JOIN billing b ON p.billing_id = b.billing_id
            WHERE p.payment_id = :pid
        ");
        $stmtHeader->execute([':pid' => $paymentId]);
        $payment = $stmtHeader->fetch(PDO::FETCH_ASSOC);

        if (!$payment) {
            return null;
        }

        $stmtAllocations = $this->pdo->prepare("
            SELECT pa.allocated_amount, bi.fee_name
            FROM payment_allocations pa
            JOIN billing_items bi ON pa.billing_item_id = bi.billing_item_id
            WHERE pa.payment_id = :pid
            ORDER BY pa.allocated_at ASC
        ");
        $stmtAllocations->execute([':pid' => $paymentId]);
        $payment['allocations'] = $stmtAllocations->fetchAll(PDO::FETCH_ASSOC);

        $billingId = (int) ($payment['billing_id'] ?? 0);
        $ledgers = $billingId ? $this->getLedgerSummariesByBillingIds([$billingId]) : [];
        $payment['ledger'] = $ledgers[$billingId] ?? [
            'opening_balance' => 0,
            'payments_total' => 0,
            'closing_balance' => (float) ($payment['remaining_balance'] ?? 0),
        ];

        return $payment;
    }

    private function buildFilterClause(array $filters): array {
        $where = ['1=1'];
        $params = [];

        if ($filters['search'] !== '') {
            $where[] = '(s.student_number LIKE :search OR s.full_name LIKE :search OR p.reference_number LIKE :search OR p.receipt_number LIKE :search)';
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        if ($filters['status'] !== '') {
            $where[] = 'p.payment_status = :status';
            $params[':status'] = $filters['status'];
        }

        if ($filters['channel'] !== '') {
            $where[] = 'p.payment_channel = :channel';
            $params[':channel'] = $filters['channel'];
        }

        if ($filters['date_range'] === 'today') {
            $where[] = 'p.payment_date = CURDATE()';
        } elseif ($filters['date_range'] === 'week') {
            $where[] = 'p.payment_date >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)';
            $where[] = 'p.payment_date <= CURDATE()';
        } elseif ($filters['date_range'] === 'month') {
            $where[] = 'YEAR(p.payment_date) = YEAR(CURDATE()) AND MONTH(p.payment_date) = MONTH(CURDATE())';
        }

        if ($filters['processed_by'] !== '') {
            $where[] = 'p.verified_by = :processed_by';
            $params[':processed_by'] = (int) $filters['processed_by'];
        }

        return [implode(' AND ', $where), $params];
    }
}
