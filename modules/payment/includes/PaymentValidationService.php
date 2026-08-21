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
     * @return array Contains 'valid' => bool, and 'error' => string if invalid.
     */
    public function validatePaymentRequest($studentId, $billingId, $amount, $channel): array {
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

            $remainingBalance = (float)$billing['remaining_balance'];

            // 3. Amount validation against remaining balance & Phase 7 Partial Payment Rules
            if ($amount > $remainingBalance) {
                return ['valid' => false, 'error' => 'Payment amount cannot exceed the remaining balance of ₱' . number_format($remainingBalance, 2)];
            }

            if ($remainingBalance >= 1000) {
                if ($amount < 1000) {
                    return ['valid' => false, 'error' => 'The minimum payment amount is ₱1,000.00 since your balance is ₱1,000.00 or more.'];
                }
            } else {
                if (abs($amount - $remainingBalance) > 0.01) { // Floating point safe comparison
                    return ['valid' => false, 'error' => 'For balances below ₱1,000.00, you must pay the exact remaining balance of ₱' . number_format($remainingBalance, 2)];
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
