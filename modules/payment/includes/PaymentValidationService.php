<?php
/**
 * PaymentValidationService
 * 
 * Centralized backend validation for online payment requests.
 * Ensures that the student, billing, amount, and payment channel are all valid
 * before generating a PayMongo Checkout Session.
 */

require_once __DIR__ . '/PaymentChannelService.php';

class PaymentValidationService {
    private $pdo;
    private $channelService;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->channelService = new PaymentChannelService($pdo);
    }

    /**
     * Validates an online payment request
     * 
     * @param int $studentId The ID of the student making the payment
     * @param int $billingId The ID of the billing to pay
     * @param float $amount The requested payment amount
     * @param string $channel The selected payment channel (e.g. gcash, card)
     * @param string $allocationContext The allocation strategy (ENROLLMENT_PRIORITY or SPECIFIC_ITEM)
     * @param int|null $billingItemId Optional specific item ID if SPECIFIC_ITEM
     * @return array Contains 'valid' => bool, and 'error' => string if invalid.
     */
    public function validatePaymentRequest($studentId, $billingId, $amount, $channel, $allocationContext = 'ENROLLMENT_PRIORITY', $billingItemId = null): array {
        try {
            // 1. Amount basic validation
            if ($amount <= 0) {
                return ['valid' => false, 'error' => 'Payment amount must be greater than zero.'];
            }

            // 2. Billing existence and ownership check
            $stmt = $this->pdo->prepare("SELECT student_id, remaining_balance, billing_status FROM payment_db.billing WHERE billing_id = :billing_id");
            $stmt->execute([':billing_id' => $billingId]);
            $billing = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$billing) {
                return ['valid' => false, 'error' => 'Billing record not found.'];
            }

            if ($billing['student_id'] != $studentId) {
                return ['valid' => false, 'error' => 'You are not authorized to pay this billing.'];
            }

            if ($billing['billing_status'] === 'Paid') {
                return ['valid' => false, 'error' => 'This billing is already fully paid.'];
            }

            // 3. CONTEXT-AWARE TARGET BALANCE CALCULATION
            $targetBalance = 0;

            if ($allocationContext === 'SPECIFIC_ITEM') {
                if (!$billingItemId) {
                    return ['valid' => false, 'error' => 'Billing item ID is required for specific item payments.'];
                }

                // Verify exact billing item belongs to this billing and is unpaid
                $stmtItem = $this->pdo->prepare("
                    SELECT remaining_amount 
                    FROM payment_db.billing_items 
                    WHERE billing_item_id = :item_id AND billing_id = :billing_id
                ");
                $stmtItem->execute([
                    ':item_id' => $billingItemId, 
                    ':billing_id' => $billingId
                ]);
                
                $item = $stmtItem->fetch(PDO::FETCH_ASSOC);

                if (!$item) {
                    return ['valid' => false, 'error' => 'Specific billing item not found in this SOA.'];
                }

                if ((float)$item['remaining_amount'] <= 0) {
                    return ['valid' => false, 'error' => 'This specific fee is already fully paid.'];
                }

                $targetBalance = (float)$item['remaining_amount'];

            } else if ($allocationContext === 'ENROLLMENT_PRIORITY') {
                if ($billingItemId !== null) {
                    return ['valid' => false, 'error' => 'Billing item ID must be empty for enrollment priority payments.'];
                }

                // Sum all eligible enrollment assessment items (exclude tuition = 1)
                $stmtEnrollment = $this->pdo->prepare("
                    SELECT SUM(bi.remaining_amount) 
                    FROM payment_db.billing_items bi
                    JOIN payment_db.fees f ON bi.fee_id = f.fee_id
                    WHERE bi.billing_id = :billing_id 
                      AND bi.source_context = 'Enrollment Assessment'
                      AND f.category_id != 1
                      AND bi.remaining_amount > 0
                ");
                $stmtEnrollment->execute([':billing_id' => $billingId]);
                $enrollmentBalance = (float)$stmtEnrollment->fetchColumn();

                if ($enrollmentBalance <= 0) {
                    return ['valid' => false, 'error' => 'No outstanding enrollment assessment fees available to pay online.'];
                }

                $targetBalance = $enrollmentBalance;
            } else {
                return ['valid' => false, 'error' => 'Invalid payment allocation context.'];
            }

            // 4. Validate Amount Against Target Balance & Universal Minimum Rule
            if ($amount > $targetBalance) {
                // Floating point safe comparison
                if (abs($amount - $targetBalance) > 0.01) {
                    return ['valid' => false, 'error' => 'Payment amount cannot exceed the target balance of ₱' . number_format($targetBalance, 2)];
                }
            }

            $minimumAllowed = min(1000.00, $targetBalance);

            // Floating point safe less-than comparison
            if ($amount < ($minimumAllowed - 0.01)) {
                if ($targetBalance >= 1000) {
                    return ['valid' => false, 'error' => 'The minimum payment amount is ₱1,000.00 since your payable balance is ₱1,000.00 or more.'];
                } else {
                    return ['valid' => false, 'error' => 'For balances below ₱1,000.00, you must pay the exact remaining balance of ₱' . number_format($targetBalance, 2)];
                }
            }

            // 4. Payment method availability check
            $paymongo = new PayMongoService();
            $env = $this->channelService->getActiveEnvironment();
            $statuses = $this->channelService->getChannelStatuses($paymongo, $env);
            if (!isset($statuses[$channel])) {
                return ['valid' => false, 'error' => 'The selected payment channel is invalid or unavailable.'];
            }

            if ($statuses[$channel]['status'] !== 'AVAILABLE') {
                return ['valid' => false, 'error' => 'The selected payment channel is currently unavailable.'];
            }
            // We trust the student ownership of the billing record (checked above in step 2)
            // Optional: If you only allow 'Enrolled' or 'Verified' students to pay, check here.
            // if ($student['status'] !== 'Enrolled' && $student['status'] !== 'Verified') {
            //     return ['valid' => false, 'error' => 'Your student account is not currently active for online payments.'];
            // }

            return ['valid' => true];

        } catch (Exception $e) {
            return ['valid' => false, 'error' => 'An internal error occurred during payment validation.'];
        }
    }
}
