<?php
/**
 * Internal Pending Payment Service
 * 
 * Handles the creation of 'Pending' payment records before handing off
 * the user to third-party payment gateways (like PayMongo).
 * This ensures we have an internal trace of every checkout attempt.
 */
class InternalPendingPaymentService {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Creates a pending payment record.
     * 
     * @param int $studentId
     * @param int $billingId
     * @param float $amount
     * @param int $categoryId The designated fee category chosen post-enrollment
     * @return int The newly created payment_id
     */
    public function createPendingPayment($studentId, $billingId, $amount, $categoryId) {
        // Store context in remarks since we need to remember what category they paid for
        // after the webhook returns asynchronously.
        $context = json_encode([
            'category_id' => $categoryId,
            'source' => 'PayMongo Checkout'
        ]);

        $stmt = $this->pdo->prepare("
            INSERT INTO payments (
                student_id, 
                billing_id, 
                transaction_type, 
                payment_method, 
                payment_channel, 
                amount, 
                payment_status, 
                payment_date, 
                remarks
            ) VALUES (
                :sid,
                :bid,
                'Online',
                'Online',
                'PayMongo',
                :amount,
                'Pending',
                CURDATE(),
                :remarks
            )
        ");

        $stmt->execute([
            ':sid' => $studentId,
            ':bid' => $billingId,
            ':amount' => $amount,
            ':remarks' => $context
        ]);

        return $this->pdo->lastInsertId();
    }

    /**
     * Updates the reference number of a pending payment (e.g., with PayMongo Session ID)
     * 
     * @param int $paymentId
     * @param string $referenceNumber
     */
    public function updateReferenceNumber($paymentId, $referenceNumber) {
        $stmt = $this->pdo->prepare("
            UPDATE payments 
            SET reference_number = :ref 
            WHERE payment_id = :pid
        ");
        $stmt->execute([
            ':ref' => $referenceNumber,
            ':pid' => $paymentId
        ]);
    }
}
