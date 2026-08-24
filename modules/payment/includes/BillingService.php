<?php
/**
 * Billing Service
 * Handles creation and retrieval of billing and invoices.
 */
class BillingService {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Generates a billing statement for a student in a single transaction.
     * 
     * @param int $studentId
     * @param string $academicYear
     * @param string $semester
     * @param string $billingType
     * @param array $feeIds Array of fee_id to include
     * @param float $discountAmount Optional discount to apply
     * @param int $generatedBy User ID generating this billing
     * @return int The newly created billing_id
     * @throws Exception
     */
    public function generateBilling($studentId, $academicYear, $semester, $billingType, $feeIds, $discountAmount = 0.00, $generatedBy = null) {
        if (empty($feeIds)) {
            throw new Exception("Cannot generate a billing without fees.");
        }

        try {
            $this->pdo->beginTransaction();

            // 1. Validate student exists
            $stmt = $this->pdo->prepare("SELECT student_id FROM students WHERE student_id = :sid");
            $stmt->execute([':sid' => $studentId]);
            if (!$stmt->fetch()) {
                throw new Exception("Student not found in local cache. Please ensure they are synced from Registrar.");
            }

            // 2. Fetch active fees
            $placeholders = str_repeat('?,', count($feeIds) - 1) . '?';
            $stmtFees = $this->pdo->prepare("
                SELECT fee_id, fee_name, default_amount 
                FROM fees 
                WHERE fee_id IN ($placeholders) AND status = 'Active'
            ");
            $stmtFees->execute($feeIds);
            $activeFees = $stmtFees->fetchAll(PDO::FETCH_ASSOC);

            if (empty($activeFees)) {
                throw new Exception("None of the selected fees are active or exist.");
            }

            // 3. Calculate Gross Assessment
            $grossAssessment = 0;
            foreach ($activeFees as $fee) {
                $grossAssessment += (float)$fee['default_amount'];
            }

            // 4. Validate Discount
            if ($discountAmount > $grossAssessment) {
                throw new Exception("Discount amount (₱" . number_format($discountAmount, 2) . ") cannot exceed Gross Assessment (₱" . number_format($grossAssessment, 2) . ").");
            }
            if ($discountAmount < 0) {
                throw new Exception("Discount amount cannot be negative.");
            }

            // 5. Calculate Initial Balance
            $remainingBalance = $grossAssessment - $discountAmount;
            $billingStatus = ($remainingBalance <= 0) ? 'Paid' : 'Unpaid';

            // 6. Create Billing Header
            $stmtBilling = $this->pdo->prepare("
                INSERT INTO billing 
                (student_id, academic_year, semester, billing_type, total_amount, discount_amount, remaining_balance, billing_status, generated_by)
                VALUES 
                (:sid, :ay, :sem, :type, :total, :disc, :rem, :status, :gen)
            ");
            $stmtBilling->execute([
                ':sid' => $studentId,
                ':ay' => $academicYear,
                ':sem' => $semester,
                ':type' => $billingType,
                ':total' => $grossAssessment,
                ':disc' => $discountAmount,
                ':rem' => $remainingBalance,
                ':status' => $billingStatus,
                ':gen' => $generatedBy
            ]);

            $billingId = $this->pdo->lastInsertId();

            // 7. Create Historical Snapshot (billing_items)
            $stmtItems = $this->pdo->prepare("
                INSERT INTO billing_items 
                (billing_id, fee_id, fee_name, amount, paid_amount, remaining_amount, status, source_context, added_by, added_at)
                VALUES 
                (:bid, :fid, :fname, :amt, 0.00, :rem, 'Unpaid', :ctx, :addedby, NOW())
            ");

            foreach ($activeFees as $fee) {
                $stmtItems->execute([
                    ':bid' => $billingId,
                    ':fid' => $fee['fee_id'],
                    ':fname' => $fee['fee_name'], // Historical Snapshot!
                    ':amt' => $fee['default_amount'],
                    ':rem' => $fee['default_amount'],
                    ':ctx' => $billingType,
                    ':addedby' => $generatedBy
                ]);
            }

            $this->pdo->commit();
            return $billingId;

        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Appends new fees to an existing billing/SOA and recalculates totals.
     * 
     * @param int $billingId
     * @param array $feeIds
     * @param string $context The reason/source for appending (e.g., 'Adjustment')
     * @param int $addedBy
     * @return array Summary of added and skipped fees, plus new balance
     * @throws Exception
     */
    public function appendFeesToBilling($billingId, $feeIds, $context, $addedBy = null) {
        if (empty($feeIds)) {
            return ['added' => [], 'skipped' => [], 'new_total' => 0, 'new_remaining' => 0];
        }

        try {
            $this->pdo->beginTransaction();

            // 1. Validate billing exists
            $stmt = $this->pdo->prepare("SELECT billing_id, discount_amount FROM billing WHERE billing_id = :bid FOR UPDATE");
            $stmt->execute([':bid' => $billingId]);
            $billingHeader = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$billingHeader) {
                throw new Exception("Billing record not found.");
            }

            // 2. Fetch active fees
            $placeholders = str_repeat('?,', count($feeIds) - 1) . '?';
            $stmtFees = $this->pdo->prepare("
                SELECT fee_id, fee_name, default_amount 
                FROM fees 
                WHERE fee_id IN ($placeholders) AND status = 'Active'
            ");
            $stmtFees->execute($feeIds);
            $activeFees = $stmtFees->fetchAll(PDO::FETCH_ASSOC);

            if (empty($activeFees)) {
                throw new Exception("None of the selected fees are active or exist.");
            }

            // 3. Find existing fees in this billing to prevent duplicates
            $stmtExisting = $this->pdo->prepare("SELECT fee_id FROM billing_items WHERE billing_id = :bid");
            $stmtExisting->execute([':bid' => $billingId]);
            $existingFeeIds = $stmtExisting->fetchAll(PDO::FETCH_COLUMN);

            $added = [];
            $skipped = [];

            // 4. Insert new items
            $stmtInsert = $this->pdo->prepare("
                INSERT INTO billing_items 
                (billing_id, fee_id, fee_name, amount, paid_amount, remaining_amount, status, source_context, added_by, added_at)
                VALUES 
                (:bid, :fid, :fname, :amt, 0.00, :rem, 'Unpaid', :ctx, :addedby, NOW())
            ");

            foreach ($activeFees as $fee) {
                if (in_array($fee['fee_id'], $existingFeeIds)) {
                    $skipped[$fee['fee_name']] = "Fee already exists in this SOA";
                    continue;
                }

                $stmtInsert->execute([
                    ':bid' => $billingId,
                    ':fid' => $fee['fee_id'],
                    ':fname' => $fee['fee_name'],
                    ':amt' => $fee['default_amount'],
                    ':rem' => $fee['default_amount'],
                    ':ctx' => $context,
                    ':addedby' => $addedBy
                ]);
                
                $added[] = ['fee_name' => $fee['fee_name'], 'amount' => $fee['default_amount']];
            }

            // 5. Recalculate totals based on items
            $stmtRecalc = $this->pdo->prepare("
                SELECT COALESCE(SUM(amount), 0) as total_amount, COALESCE(SUM(paid_amount), 0) as total_paid
                FROM billing_items 
                WHERE billing_id = :bid
            ");
            $stmtRecalc->execute([':bid' => $billingId]);
            $totals = $stmtRecalc->fetch(PDO::FETCH_ASSOC);

            $newTotalAmount = (float)$totals['total_amount'];
            $newTotalPaid = (float)$totals['total_paid'];
            $discount = (float)$billingHeader['discount_amount'];

            // remaining = (total - discount) - paid
            // Ensure remaining doesn't drop below 0 if paid > (total - discount)
            $newRemaining = max(0, ($newTotalAmount - $discount) - $newTotalPaid);
            
            if ($newRemaining <= 0) {
                $status = 'Paid';
            } elseif ($newTotalPaid > 0) {
                $status = 'Partial';
            } else {
                $status = 'Unpaid';
            }

            // 6. Update Header
            $stmtUpdate = $this->pdo->prepare("
                UPDATE billing 
                SET total_amount = :total, remaining_balance = :rem, billing_status = :status 
                WHERE billing_id = :bid
            ");
            $stmtUpdate->execute([
                ':total' => $newTotalAmount,
                ':rem' => $newRemaining,
                ':status' => $status,
                ':bid' => $billingId
            ]);

            $this->pdo->commit();

            return [
                'added' => $added,
                'skipped' => $skipped,
                'new_total' => $newTotalAmount,
                'new_remaining' => $newRemaining
            ];

        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Retrieves a fully structured billing invoice for the UI and Cashier.
     * 
     * @param int $billingId
     * @return array|null Returns header and category-grouped items.
     */
    public function getBillingData($billingId) {
        // 1. Fetch Header
        $stmt = $this->pdo->prepare("
            SELECT b.*, s.student_number, s.full_name 
            FROM billing b
            JOIN students s ON b.student_id = s.student_id
            WHERE b.billing_id = :bid
        ");
        $stmt->execute([':bid' => $billingId]);
        $billing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$billing) {
            return null;
        }

        // 2. Fetch Breakdown (Joined with category for UI grouping)
        $stmtItems = $this->pdo->prepare("
            SELECT bi.*, fc.category_name, fc.priority_order
            FROM billing_items bi
            JOIN fees f ON bi.fee_id = f.fee_id
            LEFT JOIN fee_categories fc ON f.category_id = fc.category_id
            WHERE bi.billing_id = :bid
            ORDER BY fc.priority_order ASC, bi.billing_item_id ASC
        ");
        $stmtItems->execute([':bid' => $billingId]);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        // 3. Group by Category
        $groupedItems = [];
        foreach ($items as $item) {
            $cat = $item['category_name'] ?? 'Uncategorized';
            if (!isset($groupedItems[$cat])) {
                $groupedItems[$cat] = [];
            }
            $groupedItems[$cat][] = [
                'billing_item_id' => $item['billing_item_id'],
                'fee_name' => $item['fee_name'], // Uses the snapshot
                'amount' => $item['amount'],
                'paid_amount' => $item['paid_amount'],
                'remaining_amount' => $item['remaining_amount'],
                'status' => $item['status']
            ];
        }

        $billing['breakdown'] = $groupedItems;
        return $billing;
    }
}
