<?php
/**
 * Online Payment Verification Service
 * 
 * Verifies that the successful PayMongo transaction matches our internal records
 * exactly (e.g. verifying amounts) before we process the final allocation.
 */
class OnlinePaymentVerificationService {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Verifies the PayMongo payment against internal records.
     * 
     * @param string $sessionId PayMongo Checkout Session ID
     * @param int $paymongoAmountInCents Amount actually paid in PayMongo
     * @return array Returns ['is_valid' => true, 'payment_id' => $id, 'remarks' => $json]
     * @throws Exception If validation fails
     */
    public function verifyPayment($sessionId, $paymongoAmountInCents) {
        $stmt = $this->pdo->prepare("SELECT payment_id, amount, payment_status, remarks FROM payments WHERE reference_number = :ref");
        $stmt->execute([':ref' => $sessionId]);
        $internalPayment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$internalPayment) {
            throw new Exception("No internal payment record found for Session ID: $sessionId");
        }

        if ($internalPayment['payment_status'] !== 'Pending') {
            throw new Exception("Payment record is no longer Pending. Current status: " . $internalPayment['payment_status']);
        }

        // PayMongo amount is in cents, ours is decimal
        $internalAmountInCents = (int) round((float) $internalPayment['amount'] * 100);

        if ($paymongoAmountInCents !== $internalAmountInCents) {
            throw new Exception("Amount mismatch. PayMongo received $paymongoAmountInCents cents, but internal record expects $internalAmountInCents cents.");
        }

        return [
            'is_valid' => true,
            'payment_id' => $internalPayment['payment_id'],
            'remarks' => $internalPayment['remarks']
        ];
    }
}
