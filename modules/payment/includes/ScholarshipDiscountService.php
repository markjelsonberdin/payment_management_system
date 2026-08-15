<?php
/**
 * Scholarship & Discount Service
 * Handles application, validation, and balance recomputation of discounts.
 */
class ScholarshipDiscountService {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Applies a scholarship/discount to a specific billing.
     * 
     * @param int $billingId
     * @param string $scholarshipName
     * @param string $discountType 'Percentage' or 'Fixed Amount'
     * @param float $discountValue
     * @param int $approvedBy User ID of the MIS/Admin
     * @return int The ID of the newly created scholarship record
     * @throws Exception If validation fails
     */
    public function applyScholarshipDiscount($billingId, $scholarshipName, $discountType, $discountValue, $approvedBy) {
        try {
            $this->pdo->beginTransaction();

            // 1 & 2. Validate billing exists and get current status
            $stmtBilling = $this->pdo->prepare("
                SELECT student_id, total_amount, discount_amount, remaining_balance, billing_status 
                FROM billing 
                WHERE billing_id = :bid 
                FOR UPDATE
            ");
            $stmtBilling->execute([':bid' => $billingId]);
            $billing = $stmtBilling->fetch(PDO::FETCH_ASSOC);

            if (!$billing) {
                throw new Exception("Billing record not found.");
            }

            if ($billing['billing_status'] === 'Paid') {
                throw new Exception("Cannot apply discount to a fully paid billing.");
            }

            // 3 & 4. Validate one active scholarship per billing
            $stmtCheckActive = $this->pdo->prepare("
                SELECT scholarship_id FROM scholarships 
                WHERE billing_id = :bid AND status = 'Active'
            ");
            $stmtCheckActive->execute([':bid' => $billingId]);
            
            if ($stmtCheckActive->fetch()) {
                throw new Exception("This billing already has an active scholarship/discount applied.");
            }

            // 5. Calculate Discount
            $computedDiscount = 0.00;
            $percentage = null;
            $grossAssessment = (float)$billing['total_amount'];

            if ($discountType === 'Percentage') {
                if ($discountValue <= 0 || $discountValue > 100) {
                    throw new Exception("Percentage discount must be between 1 and 100.");
                }
                $percentage = $discountValue;
                $computedDiscount = $grossAssessment * ($percentage / 100);
            } elseif ($discountType === 'Fixed Amount') {
                if ($discountValue <= 0) {
                    throw new Exception("Fixed discount amount must be greater than zero.");
                }
                $computedDiscount = $discountValue;
            } else {
                throw new Exception("Invalid discount type.");
            }

            // Ensure discount does not exceed gross assessment
            if ($computedDiscount > $grossAssessment) {
                throw new Exception("Computed discount (₱" . number_format($computedDiscount, 2) . ") cannot exceed the Gross Assessment (₱" . number_format($grossAssessment, 2) . ").");
            }

            // 6, 7 & 8. Recalculate billing values
            // We ADD to any existing discount_amount (though rule says 1 active discount per billing, so it should be the only one)
            $newDiscountAmount = (float)$billing['discount_amount'] + $computedDiscount;
            
            // Subtract discount from the current remaining balance
            $newRemainingBalance = max(0, (float)$billing['remaining_balance'] - $computedDiscount);
            
            $newStatus = ($newRemainingBalance <= 0) ? 'Paid' : 'Partial';

            $stmtUpdateBilling = $this->pdo->prepare("
                UPDATE billing 
                SET discount_amount = :disc_amt,
                    remaining_balance = :rem_bal,
                    billing_status = :status
                WHERE billing_id = :bid
            ");
            $stmtUpdateBilling->execute([
                ':disc_amt' => $newDiscountAmount,
                ':rem_bal'  => $newRemainingBalance,
                ':status'   => $newStatus,
                ':bid'      => $billingId
            ]);

            // 9. Save Scholarship Record
            $stmtSch = $this->pdo->prepare("
                INSERT INTO scholarships 
                (student_id, billing_id, approved_by, scholarship_name, discount_type, discount_percentage, discount_amount, status, approved_at) 
                VALUES 
                (:sid, :bid, :approved_by, :name, :type, :percentage, :amount, 'Active', CURRENT_TIMESTAMP)
            ");
            $stmtSch->execute([
                ':sid'         => $billing['student_id'],
                ':bid'         => $billingId,
                ':approved_by' => $approvedBy,
                ':name'        => $scholarshipName,
                ':type'        => $discountType,
                ':percentage'  => $percentage,
                ':amount'      => $computedDiscount
            ]);

            $scholarshipId = $this->pdo->lastInsertId();

            // 10. Generate Audit Log using the centralized SMS2 activity_logs
            if (function_exists('logActivity')) {
                logActivity(
                    'apply_scholarship',
                    "Applied $scholarshipName (₱" . number_format($computedDiscount, 2) . ") to Billing #$billingId",
                    'payment'
                );
            }

            $this->pdo->commit();
            return $scholarshipId;

        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
