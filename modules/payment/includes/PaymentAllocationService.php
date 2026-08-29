<?php
/**
 * Payment Allocation Service
 * Handles the distribution of payment amounts to specific billing items
 * based on the context (Enrollment vs Post-Enrollment).
 */
class PaymentAllocationService {
    private $pdo;

    // Fixed ID for Tuition based on schema
    const TUITION_CATEGORY_ID = 1;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Executes the payment allocation engine.
     * 
     * @param int $paymentId
     * @param int $studentId
     * @param int $billingId
     * @param float $amountPaid The exact amount to allocate
     * @param string $allocationContext 'ENROLLMENT_PRIORITY' or 'SPECIFIC_ITEM'
     * @param int|null $billingItemId Required if context is 'SPECIFIC_ITEM'
     * @throws Exception if validation fails or allocation rules are violated
     */
    public function allocatePayment($paymentId, $studentId, $billingId, $amountPaid, $allocationContext = 'ENROLLMENT_PRIORITY', $billingItemId = null) {
        $ownsTransaction = false;
        try {
            if (!$this->pdo->inTransaction()) {
                $this->pdo->beginTransaction();
                $ownsTransaction = true;
            }

            // 1. Validate payment amount
            if ($amountPaid <= 0) {
                throw new Exception("Payment amount must be greater than zero.");
            }

            // 2. Validate billing belongs to student
            $stmt = $this->pdo->prepare("SELECT student_id FROM billing WHERE billing_id = :billing_id");
            $stmt->execute([':billing_id' => $billingId]);
            $billingOwner = $stmt->fetchColumn();

            if ($billingOwner === false) {
                throw new Exception("Billing record not found.");
            }
            if ($billingOwner != $studentId) {
                throw new Exception("Cross-billing attempt: Billing does not belong to the student.");
            }

            // 3. Determine eligible billing items and lock them for concurrency
            if ($allocationContext === 'GENERAL_PRIORITY') {
                $stmt = $this->pdo->prepare("
                    SELECT bi.billing_item_id, bi.remaining_amount
                    FROM payment_db.billing_items bi
                    JOIN payment_db.fees f ON bi.fee_id = f.fee_id
                    JOIN payment_db.fee_categories fc ON f.category_id = fc.category_id
                    WHERE bi.billing_id = :billing_id 
                      AND bi.status != 'Paid'
                      AND bi.remaining_amount > 0
                    ORDER BY 
                        fc.priority_order ASC,
                        bi.billing_item_id ASC
                    FOR UPDATE
                ");
                $stmt->execute([':billing_id' => $billingId]);
            } elseif ($allocationContext === 'ENROLLMENT_PRIORITY') {
                // Rely on existing configured fee priority (fc.priority_order)
                // Exclude Tuition entirely
                $stmt = $this->pdo->prepare("
                    SELECT bi.billing_item_id, bi.remaining_amount
                    FROM payment_db.billing_items bi
                    JOIN payment_db.fees f ON bi.fee_id = f.fee_id
                    JOIN payment_db.fee_categories fc ON f.category_id = fc.category_id
                    WHERE bi.billing_id = :billing_id 
                      AND bi.source_context = 'Enrollment Assessment'
                      AND bi.status != 'Paid'
                      AND bi.remaining_amount > 0
                      AND fc.category_id != :tuition_cat
                    ORDER BY 
                        fc.priority_order ASC,
                        bi.billing_item_id ASC
                    FOR UPDATE
                ");
                $stmt->execute([
                    ':billing_id' => $billingId,
                    ':tuition_cat' => self::TUITION_CATEGORY_ID
                ]);
            } elseif ($allocationContext === 'SPECIFIC_ITEM') {
                if (!$billingItemId) {
                    throw new Exception("Billing item ID is required for SPECIFIC_ITEM allocation.");
                }
                
                // Strict allocation only to the exact billing item
                $stmt = $this->pdo->prepare("
                    SELECT bi.billing_item_id, bi.remaining_amount
                    FROM payment_db.billing_items bi
                    JOIN payment_db.fees f ON bi.fee_id = f.fee_id
                    WHERE bi.billing_id = :billing_id 
                      AND bi.billing_item_id = :item_id
                      AND bi.status != 'Paid'
                      AND bi.remaining_amount > 0
                    FOR UPDATE
                ");
                $stmt->execute([
                    ':billing_id' => $billingId, 
                    ':item_id' => $billingItemId
                ]);
            } elseif ($allocationContext === 'CATEGORY_PRIORITY') {
                if (!$billingItemId) {
                    throw new Exception("Category ID is required for CATEGORY_PRIORITY allocation.");
                }

                $stmt = $this->pdo->prepare("
                    SELECT bi.billing_item_id, bi.remaining_amount
                    FROM payment_db.billing_items bi
                    JOIN payment_db.fees f ON bi.fee_id = f.fee_id
                    WHERE bi.billing_id = :billing_id 
                      AND f.category_id = :cat_id
                      AND bi.status != 'Paid'
                      AND bi.remaining_amount > 0
                    ORDER BY bi.billing_item_id ASC
                    FOR UPDATE
                ");
                $stmt->execute([
                    ':billing_id' => $billingId, 
                    ':cat_id' => $billingItemId // Here, the 6th arg is the category_id
                ]);
            } else {
                throw new Exception("Invalid payment allocation context specified.");
            }

            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 4. Calculate total payable in this scope
            $totalPayable = 0;
            foreach ($items as $item) {
                $totalPayable += (float)$item['remaining_amount'];
            }

            if (empty($items) || $totalPayable <= 0) {
                throw new Exception("No eligible billing items found for allocation.");
            }

            // 5. STRICT Validation: No over-allocations allowed
            if ($amountPaid > $totalPayable) {
                throw new Exception("Over-allocation attempt. Payment amount (₱" . number_format($amountPaid, 2) . ") exceeds the payable balance in this scope (₱" . number_format($totalPayable, 2) . ").");
            }

            $remainingToAllocate = $amountPaid;

            // 6. Distribute funds sequentially
            foreach ($items as $item) {
                if ($remainingToAllocate <= 0) {
                    break;
                }

                $itemBalance = (float)$item['remaining_amount'];
                $allocate = min($remainingToAllocate, $itemBalance);

                if ($allocate <= 0) {
                    continue;
                }

                // 7. Insert allocation (Idempotent approach handling duplicate attempts)
                try {
                    $stmtAlloc = $this->pdo->prepare("
                        INSERT INTO payment_allocations (payment_id, billing_item_id, allocated_amount)
                        VALUES (:payment_id, :billing_item_id, :allocated_amount)
                    ");
                    $stmtAlloc->execute([
                        ':payment_id' => $paymentId,
                        ':billing_item_id' => $item['billing_item_id'],
                        ':allocated_amount' => $allocate
                    ]);
                } catch (PDOException $e) {
                    // Check for duplicate entry (1062)
                    if ($e->getCode() == 23000 || strpos($e->getMessage(), '1062') !== false) {
                        throw new Exception("Duplicate allocation detected for this payment.");
                    }
                    throw $e;
                }

                $remainingToAllocate -= $allocate;
            }

            // 8. Recalculate Billing Summary using exact billing_id
            $this->updateBillingSummary($billingId);

            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (Exception $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Explicitly refreshes the parent billing summary after items are updated
     */
    private function updateBillingSummary($billingId) {
        $stmt = $this->pdo->prepare("
            SELECT COALESCE(SUM(remaining_amount), 0) AS total_remaining
            FROM billing_items
            WHERE billing_id = :billing_id
        ");
        $stmt->execute([':billing_id' => $billingId]);
        $totalRemaining = (float)$stmt->fetchColumn();

        $stmtOriginal = $this->pdo->prepare("SELECT total_amount FROM billing WHERE billing_id = :billing_id");
        $stmtOriginal->execute([':billing_id' => $billingId]);
        $totalAmount = (float)$stmtOriginal->fetchColumn();

        if ($totalRemaining <= 0) {
            $status = 'Paid';
        } elseif ($totalRemaining < $totalAmount) {
            $status = 'Partial';
        } else {
            $status = 'Unpaid';
        }

        $stmtUpdate = $this->pdo->prepare("
            UPDATE billing
            SET remaining_balance = :bal, billing_status = :status, updated_at = CURRENT_TIMESTAMP
            WHERE billing_id = :billing_id
        ");
        $stmtUpdate->execute([
            ':bal' => $totalRemaining,
            ':status' => $status,
            ':billing_id' => $billingId
        ]);
    }
}
