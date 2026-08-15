<?php
/**
 * Payment History & Ledger Service
 * Handles fetching of historical payments, official receipts, and ledger allocations.
 */
class PaymentHistoryService {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Retrieves summary statistics for the Payment Dashboard/Ledger.
     */
    public function getPaymentSummary() {
        $stmt = $this->pdo->query("
            SELECT 
                SUM(CASE WHEN payment_status = 'Verified' THEN amount ELSE 0 END) as total_collections,
                COUNT(payment_id) as total_transactions
            FROM payments
        ");
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Retrieves all historical payments, optionally filtered by a search query.
     * 
     * @param string $search
     * @return array
     */
    public function getAllPayments($search = '') {
        // We only join with payment_db.students and payment_db.billing
        // No cross-database joins to sms2_db.users to prevent schema coupling issues.
        $query = "
            SELECT 
                p.payment_id, p.reference_number, p.amount, p.payment_method, p.payment_status, p.created_at,
                s.student_number, s.full_name,
                b.total_amount, b.remaining_balance, b.billing_status
            FROM payments p
            JOIN students s ON p.student_id = s.student_id
            LEFT JOIN billing b ON p.billing_id = b.billing_id
        ";

        $params = [];
        if (!empty($search)) {
            $query .= " WHERE s.student_number LIKE :search OR s.full_name LIKE :search OR p.reference_number LIKE :search";
            $params[':search'] = "%$search%";
        }

        $query .= " ORDER BY p.created_at DESC";

        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retrieves a detailed breakdown of a single payment, including where the money was allocated.
     * 
     * @param int $paymentId
     * @return array|null Returns payment header and a list of allocations.
     */
    public function getPaymentDetails($paymentId) {
        // 1. Fetch Payment Header
        $stmtHeader = $this->pdo->prepare("
            SELECT 
                p.payment_id, p.reference_number, p.amount, p.payment_method, p.payment_status, p.created_at, p.remarks,
                s.student_number, s.full_name,
                b.billing_type, b.academic_year, b.semester
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

        // 2. Fetch Allocations (How the payment was distributed to billing items)
        $stmtAllocations = $this->pdo->prepare("
            SELECT pa.allocated_amount, bi.fee_name
            FROM payment_allocations pa
            JOIN billing_items bi ON pa.billing_item_id = bi.billing_item_id
            WHERE pa.payment_id = :pid
            ORDER BY pa.allocated_at ASC
        ");
        $stmtAllocations->execute([':pid' => $paymentId]);
        $payment['allocations'] = $stmtAllocations->fetchAll(PDO::FETCH_ASSOC);

        return $payment;
    }
}
