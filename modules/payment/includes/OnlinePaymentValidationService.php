<?php
/**
 * Online Payment Validation Service
 * 
 * Provides strict backend validation for PayMongo and Online payments.
 * Prevents JavaScript tampering of amounts and enforces the "No Tuition" rule.
 */
class OnlinePaymentValidationService {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Validates an online payment request before creating a Checkout Session.
     * 
     * @param int $studentId
     * @param int $billingId
     * @param int $categoryId
     * @param float $requestedAmount
     * @return array ['is_valid' => bool, 'message' => string]
     */
    public function validatePaymentRequest($studentId, $billingId, $categoryId, $requestedAmount) {
        if ($requestedAmount <= 0) {
            return ['is_valid' => false, 'message' => 'Requested amount must be greater than zero.'];
        }

        // 1. Validate Tuition Exclusion
        // Category 1 is Tuition, which is covered by EJA Foundation.
        if ($categoryId == 1) {
            return ['is_valid' => false, 'message' => 'Tuition payments cannot be made through this channel. Tuition is covered by the EJA Foundation.'];
        }

        // 2. Validate Student Exists
        $stmtStudent = $this->pdo->prepare("SELECT student_id FROM students WHERE student_id = :sid");
        $stmtStudent->execute([':sid' => $studentId]);
        if (!$stmtStudent->fetch()) {
            return ['is_valid' => false, 'message' => 'Student record not found or not enrolled.'];
        }

        // 3. Validate Billing Exists and Belongs to Student
        $stmtBilling = $this->pdo->prepare("
            SELECT remaining_balance, billing_status 
            FROM billing 
            WHERE billing_id = :bid AND student_id = :sid
        ");
        $stmtBilling->execute([':bid' => $billingId, ':sid' => $studentId]);
        $billing = $stmtBilling->fetch(PDO::FETCH_ASSOC);

        if (!$billing) {
            return ['is_valid' => false, 'message' => 'Billing record not found or does not belong to the student.'];
        }

        if ($billing['remaining_balance'] <= 0 || $billing['billing_status'] === 'Paid') {
            return ['is_valid' => false, 'message' => 'This billing is already fully paid.'];
        }

        // 4. Validate Category & Calculate Eligible Remaining Balance
        $stmtCategory = $this->pdo->prepare("
            SELECT sum(bi.remaining_amount) as category_balance
            FROM billing_items bi
            JOIN fees f ON bi.fee_id = f.fee_id
            WHERE bi.billing_id = :bid AND f.category_id = :cid
        ");
        $stmtCategory->execute([':bid' => $billingId, ':cid' => $categoryId]);
        $categoryData = $stmtCategory->fetch(PDO::FETCH_ASSOC);

        $categoryBalance = $categoryData['category_balance'] ?? 0;

        if ($categoryBalance <= 0) {
            return ['is_valid' => false, 'message' => 'There is no outstanding balance for the selected category in this billing.'];
        }

        // 5. Amount validation (No Overpayment)
        if ($requestedAmount > $categoryBalance) {
            return [
                'is_valid' => false, 
                'message' => 'Requested amount (₱' . number_format($requestedAmount, 2) . ') exceeds the eligible remaining balance (₱' . number_format($categoryBalance, 2) . ') for this category.'
            ];
        }

        return ['is_valid' => true, 'message' => 'Payment request is valid.', 'eligible_balance' => $categoryBalance];
    }
}
