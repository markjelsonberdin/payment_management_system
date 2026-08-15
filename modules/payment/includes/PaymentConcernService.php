<?php
/**
 * Payment Concern Service
 * Handles submission, retrieval, and verification of payment concerns.
 */
require_once __DIR__ . '/PaymentAllocationService.php';

class PaymentConcernService {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Submits a new payment concern.
     * 
     * @param string $studentNumber
     * @param string $issueType
     * @param string $referenceNo
     * @param string $remarks
     * @param string $receiptPath
     * @return int concern_id
     */
    public function submitConcern($studentNumber, $issueType, $referenceNo, $remarks, $receiptPath) {
        try {
            $this->pdo->beginTransaction();

            $stmtStud = $this->pdo->prepare("SELECT student_id FROM students WHERE student_number = :snum LIMIT 1");
            $stmtStud->execute([':snum' => $studentNumber]);
            $student = $stmtStud->fetch(PDO::FETCH_ASSOC);

            if (!$student) {
                throw new Exception("Student record not found.");
            }
            $studentId = $student['student_id'];

            $paymentId = null;
            if (!empty($referenceNo)) {
                $stmtPay = $this->pdo->prepare("SELECT payment_id FROM payments WHERE reference_number = :ref AND student_id = :sid LIMIT 1");
                $stmtPay->execute([':ref' => $referenceNo, ':sid' => $studentId]);
                $payRow = $stmtPay->fetch(PDO::FETCH_ASSOC);
                if ($payRow) {
                    $paymentId = $payRow['payment_id'];
                }
            }

            $combinedRemarks = "[$issueType] " . $remarks;
            $stmtInsert = $this->pdo->prepare("
                INSERT INTO payment_concerns (student_id, payment_id, receipt_path, verification_status, ocr_status, remarks) 
                VALUES (:sid, :pid, :rpath, 'Pending', 'Processing', :rem)
            ");
            $stmtInsert->execute([
                ':sid'   => $studentId,
                ':pid'   => $paymentId,
                ':rpath' => $receiptPath,
                ':rem'   => $combinedRemarks
            ]);

            $concernId = $this->pdo->lastInsertId();

            $this->pdo->commit();
            return $concernId;
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Retrieves all concerns for a specific student.
     */
    public function getStudentConcerns($studentId) {
        $stmt = $this->pdo->prepare("
            SELECT pc.*, p.amount, p.payment_date 
            FROM payment_concerns pc
            LEFT JOIN payments p ON pc.payment_id = p.payment_id
            WHERE pc.student_id = :sid
            ORDER BY pc.submitted_at DESC
        ");
        $stmt->execute([':sid' => $studentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retrieves all concerns for the Cashier/Accounting Queue
     */
    public function getQueue() {
        $stmt = $this->pdo->prepare("
            SELECT pc.*, p.amount as payment_amount, p.billing_id, p.payment_channel, 
                   s.student_number, s.full_name,
                   o.extracted_amount, o.bank_name, o.confidence_score, o.reference_number as ocr_ref, o.transaction_date
            FROM payment_concerns pc
            LEFT JOIN payments p ON pc.payment_id = p.payment_id
            JOIN students s ON pc.student_id = s.student_id
            LEFT JOIN ocr_results o ON pc.concern_id = o.concern_id
            ORDER BY pc.submitted_at DESC
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Verifies or rejects a payment concern (Phase 7H / 7I)
     */
    public function verifyConcern($concernId, $action, $reviewerId, $remarks, $billingId = null) {
        try {
            $this->pdo->beginTransaction();

            $stmtGet = $this->pdo->prepare("SELECT payment_id FROM payment_concerns WHERE concern_id = :cid");
            $stmtGet->execute([':cid' => $concernId]);
            $concern = $stmtGet->fetch(PDO::FETCH_ASSOC);
            $paymentId = $concern['payment_id'];

            if ($action === 'Verify') {
                // Update concern
                $stmtConc = $this->pdo->prepare("
                    UPDATE payment_concerns 
                    SET verification_status = 'Verified', reviewed_by = :reviewer, reviewed_at = CURRENT_TIMESTAMP, remarks = :remarks 
                    WHERE concern_id = :cid
                ");
                $stmtConc->execute([':reviewer' => $reviewerId, ':remarks' => $remarks, ':cid' => $concernId]);

                // Update or create payment?
                // For now, if payment_id is not null, update it. If it is null, we need to handle it.
                // Wait! If payment_id is null, we can't allocate.
                // We MUST ensure the UI passes enough data to create a payment if it was null, or we assume OCR extracted amount and student_id is enough.
                if (!$paymentId) {
                    throw new Exception("Cannot verify concern: No payment record linked. Cashier must link or create a payment first.");
                }

                $stmtPay = $this->pdo->prepare("UPDATE payments SET payment_status = 'Verified', verified_by = :reviewer, verified_at = CURRENT_TIMESTAMP WHERE payment_id = :pid");
                $stmtPay->execute([':reviewer' => $reviewerId, ':pid' => $paymentId]);

                // Phase 7F Convergence: Call the PaymentAllocationService
                $allocationService = new PaymentAllocationService($this->pdo);
                $allocationService->allocatePayment($paymentId);

            } else {
                // Action: Reject
                $stmtConc = $this->pdo->prepare("
                    UPDATE payment_concerns 
                    SET verification_status = 'Rejected', reviewed_by = :reviewer, reviewed_at = CURRENT_TIMESTAMP, remarks = :remarks 
                    WHERE concern_id = :cid
                ");
                $stmtConc->execute([':reviewer' => $reviewerId, ':remarks' => $remarks, ':cid' => $concernId]);

                if ($paymentId) {
                    $stmtPay = $this->pdo->prepare("UPDATE payments SET payment_status = 'Rejected' WHERE payment_id = :pid");
                    $stmtPay->execute([':pid' => $paymentId]);
                }
            }

            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
