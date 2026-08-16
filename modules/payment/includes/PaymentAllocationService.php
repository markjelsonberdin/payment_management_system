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
     * @param string $context 'Enrollment' or 'DesignatedCategory'
     * @param int|null $categoryId Required if context is 'DesignatedCategory'
     * @throws Exception if validation fails or allocation rules are violated
     */
    public function allocatePayment($paymentId, $studentId, $billingId, $amountPaid, $context = 'Enrollment', $categoryId = null) {
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
            if ($context === 'Enrollment') {
                // Priority: RFID -> Miscellaneous -> Laboratory/Medical
                // Exclude Tuition entirely
                $stmt = $this->pdo->prepare("
                    SELECT bi.billing_item_id, bi.remaining_amount
                    FROM billing_items bi
                    JOIN fees f ON bi.fee_id = f.fee_id
                    JOIN fee_categories fc ON f.category_id = fc.category_id
                    WHERE bi.billing_id = :billing_id 
                      AND bi.status != 'Paid'
                      AND bi.remaining_amount > 0
                      AND fc.category_id != :tuition_cat
                    ORDER BY 
                        CASE WHEN f.fee_name = 'RFID' THEN 1
                             WHEN fc.category_name = 'Miscellaneous' THEN 2
                             WHEN fc.category_name = 'Laboratory & Computer' THEN 3
                             ELSE 4
                        END ASC,
                        bi.billing_item_id ASC
                    FOR UPDATE
                ");
                $stmt->execute([
                    ':billing_id' => $billingId,
                    ':tuition_cat' => self::TUITION_CATEGORY_ID
                ]);
            } elseif ($context === 'DesignatedCategory') {
                if (!$categoryId) {
                    throw new Exception("Category ID is required for Designated Category payments.");
                }
                if ($categoryId == self::TUITION_CATEGORY_ID) {
                    throw new Exception("Tuition is not student-payable.");
                }
                
                // Strict allocation only within the selected category
                $stmt = $this->pdo->prepare("
                    SELECT bi.billing_item_id, bi.remaining_amount
                    FROM billing_items bi
                    JOIN fees f ON bi.fee_id = f.fee_id
                    WHERE bi.billing_id = :billing_id 
                      AND f.category_id = :cat_id 
                      AND bi.status != 'Paid'
                      AND bi.remaining_amount > 0
                      AND f.category_id != :tuition_cat
                    ORDER BY bi.billing_item_id ASC
                    FOR UPDATE
                ");
                $stmt->execute([
                    ':billing_id' => $billingId, 
                    ':cat_id' => $categoryId,
                    ':tuition_cat' => self::TUITION_CATEGORY_ID
                ]);
            } else {
                throw new Exception("Invalid payment context specified.");
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
