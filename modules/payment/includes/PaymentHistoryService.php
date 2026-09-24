<?php
/**
 * Payment History & Ledger Service
 * Handles fetching of historical payments, official receipts, and ledger allocations.
 */
require_once __DIR__ . '/PaymentReportingScope.php';
require_once __DIR__ . '/AccountingReportingPeriod.php';

class PaymentHistoryService {
    private $pdo;
    private ?int $walkInCashierId;

    public const STATUSES = ['Pending', 'Verified', 'Rejected', 'Failed', 'Cancelled', 'Expired'];
    public const CHANNELS = ['Cash', 'GCash', 'Maya', 'Visa', 'Mastercard', 'Bank', 'PayMongo', 'QRPh'];
    public const DATE_RANGES = ['today', 'week', 'month', 'year'];

    private const SORT_COLUMNS = [
        'date' => 'recorded_at',
        'amount' => 'applied_amount',
        'status' => 'p.payment_status',
        'channel' => 'p.payment_channel',
        'reference' => 'p.reference_number',
        'student' => 's.full_name',
        'total' => 'COALESCE(p.checkout_total, p.amount)',
    ];

    public function __construct($pdo, ?int $walkInCashierId = null) {
        $this->pdo = $pdo;
        $this->walkInCashierId = $walkInCashierId;
    }

    /**
     * Retrieves summary statistics for the Payment Dashboard/Ledger.
     */
    public function getPaymentSummary(array $filters = []) {
        $filters = $this->normalizeFilters($filters);
        $official = PaymentReportingScope::officialCondition('p');
        [$where, $summaryParams] = $this->buildFilterClause($filters);
        $allocationJoin = $this->allocationJoin($filters);
        $stmt = $this->pdo->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN {$official} THEN COALESCE(a.applied_amount,0) ELSE 0 END), 0) AS total_collections,
                COUNT(p.payment_id) AS total_transactions,
                COALESCE(SUM(CASE WHEN p.payment_status = 'Pending' THEN 1 ELSE 0 END), 0) AS pending_transactions,
                COALESCE(SUM(CASE WHEN {$official} AND DATE(p.verified_at) = CURDATE() THEN COALESCE(a.applied_amount,0) ELSE 0 END), 0) AS today_collections
            FROM payments p
            JOIN students s ON s.student_id=p.student_id
            LEFT JOIN billing b ON b.billing_id=p.billing_id
            {$allocationJoin}
            WHERE {$where}
        ");
        $stmt->execute($summaryParams);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [
            'total_collections' => 0,
            'total_transactions' => 0,
            'pending_transactions' => 0,
            'today_collections' => 0,
        ];
    }


    /**
     * Sanitize incoming filter values against supported schema enums.
     */
    public function normalizeFilters(array $filters): array {
        $status = (string) ($filters['status'] ?? '');
        $channel = (string) ($filters['channel'] ?? '');
        $dateRange = (string) ($filters['date_range'] ?? '');
        $processedBy = trim((string) ($filters['processed_by'] ?? ''));
        $categoryId = trim((string) ($filters['category_id'] ?? ''));

        return [
            'search' => trim((string) ($filters['search'] ?? '')),
            'status' => in_array($status, self::STATUSES, true) ? $status : '',
            'channel' => in_array($channel, self::CHANNELS, true) ? $channel : '',
            'date_range' => in_array($dateRange, self::DATE_RANGES, true) ? $dateRange : '',
            'processed_by' => ($processedBy !== '' && ctype_digit($processedBy)) ? $processedBy : '',
            'category_id' => ($categoryId !== '' && ctype_digit($categoryId)) ? $categoryId : '',
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
        $limit = max(1, min(10000, $limit));
        $offset = max(0, $offset);
        $official = PaymentReportingScope::officialCondition('p');
        $allocationJoin = $this->allocationJoin($filters);

        $query = "
            SELECT
                CASE WHEN {$official} THEN COALESCE(a.applied_amount,0) ELSE 0 END AS applied_amount,
                CASE WHEN {$official} THEN p.verified_at ELSE p.created_at END AS recorded_at,
                p.gateway_environment,
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
            {$allocationJoin}
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

        $official = PaymentReportingScope::officialCondition('p');
        $cashierScope = $this->walkInCashierId !== null ? " AND p.transaction_type = 'Walk-in' AND p.verified_by = ?" : '';
        $payStmt = $this->pdo->prepare("
            SELECT bi.billing_id, COALESCE(SUM(pa.allocated_amount), 0) AS payments_total
            FROM payment_allocations pa
            JOIN payments p ON p.payment_id=pa.payment_id
            JOIN billing_items bi ON bi.billing_item_id=pa.billing_item_id
            WHERE bi.billing_id IN ({$placeholders})
              AND {$official}
              {$cashierScope}
            GROUP BY bi.billing_id
        ");
        $payParams = $billingIds;
        if ($this->walkInCashierId !== null) {
            $payParams[] = $this->walkInCashierId;
        }
        $payStmt->execute($payParams);
        foreach ($payStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $bid = (int) $row['billing_id'];
            if (isset($ledgers[$bid])) {
                $ledgers[$bid]['payments_total'] = (float) $row['payments_total'];
            }
        }

        return $ledgers;
    }



    private function buildFilterClause(array $filters): array {
        $where = ['1=1'];
        $params = [];
        if ($this->walkInCashierId !== null) {
            $where[] = "p.transaction_type = 'Walk-in'";
            $where[] = 'p.verified_by = :cashier_scope_id';
            $params[':cashier_scope_id'] = $this->walkInCashierId;
        }

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

        if ($filters['date_range'] !== '') {
            $range = AccountingReportingPeriod::fromRequest(['period' => $filters['date_range']]);
            $official = PaymentReportingScope::officialCondition('p');
            $eventTime = "CASE WHEN {$official} THEN p.verified_at ELSE p.created_at END";
            $where[] = "({$eventTime}) >= :range_start AND ({$eventTime}) < :range_end";
            $params[':range_start'] = AccountingReportingPeriod::sql($range['start_at']);
            $params[':range_end'] = AccountingReportingPeriod::sql($range['end_exclusive']);
        }

        if ($filters['processed_by'] !== '') {
            $where[] = 'p.verified_by = :processed_by';
            $params[':processed_by'] = (int) $filters['processed_by'];
        }

        if ($filters['category_id'] !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM payment_allocations pa2 JOIN billing_items bi2 ON bi2.billing_item_id = pa2.billing_item_id JOIN fees f2 ON f2.fee_id = bi2.fee_id WHERE pa2.payment_id = p.payment_id AND f2.category_id = :category_id)';
            $params[':category_id'] = (int) $filters['category_id'];
        }

        return [implode(' AND ', $where), $params];
    }

    /** One row per payment; category selection restricts applied amounts, not just membership. */
    private function allocationJoin(array $filters): string {
        $category = (int) ($filters['category_id'] ?? 0);
        $categoryWhere = $category > 0 ? 'WHERE f.category_id = ' . $category : '';
        return "LEFT JOIN (
            SELECT pa.payment_id, SUM(pa.allocated_amount) AS applied_amount
            FROM payment_allocations pa
            JOIN billing_items bi ON bi.billing_item_id=pa.billing_item_id
            JOIN fees f ON f.fee_id=bi.fee_id
            {$categoryWhere}
            GROUP BY pa.payment_id
        ) a ON a.payment_id=p.payment_id";
    }
}
