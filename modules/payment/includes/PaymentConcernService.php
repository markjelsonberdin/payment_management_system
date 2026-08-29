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
    public function verifyConcern($concernId, $action, $reviewerId, $remarks, $billingId = null, $verifiedData = []) {
        try {
            $this->pdo->beginTransaction();

            $stmtGet = $this->pdo->prepare("SELECT payment_id, student_id FROM payment_concerns WHERE concern_id = :cid");
            $stmtGet->execute([':cid' => $concernId]);
            $concern = $stmtGet->fetch(PDO::FETCH_ASSOC);
            $paymentId = $concern['payment_id'];
            $studentId = $concern['student_id'];

            if ($action === 'Verify') {
                // Update concern
                $stmtConc = $this->pdo->prepare("
                    UPDATE payment_concerns 
                    SET verification_status = 'Verified', reviewed_by = :reviewer, reviewed_at = CURRENT_TIMESTAMP, remarks = :remarks 
                    WHERE concern_id = :cid
                ");
                $stmtConc->execute([':reviewer' => $reviewerId, ':remarks' => $remarks, ':cid' => $concernId]);

                if (!$paymentId) {
                    // Fetch latest billing_id if not provided
                    if (!$billingId) {
                        $stmtFindBilling = $this->pdo->prepare("SELECT billing_id FROM billing WHERE student_id = :sid ORDER BY billing_id DESC LIMIT 1");
                        $stmtFindBilling->execute([':sid' => $studentId]);
                        $billingId = $stmtFindBilling->fetchColumn();
                        if (!$billingId) {
                            throw new Exception("Cannot verify: Student does not have an active billing record.");
                        }
                    }

                    // Create official payment record
                    if (empty($verifiedData['amount']) || empty($verifiedData['reference']) || empty($verifiedData['channel']) || empty($verifiedData['date'])) {
                        throw new Exception("Cannot create payment: Missing verified data (amount, reference, channel, date).");
                    }

                    $stmtInsertPay = $this->pdo->prepare("
                        INSERT INTO payments (student_id, billing_id, amount, payment_date, payment_channel, reference_number, payment_status, verified_by, verified_at, transaction_type, payment_method)
                        VALUES (:sid, :bid, :amt, :pdate, :chan, :ref, 'Verified', :reviewer, CURRENT_TIMESTAMP, 'Payment Concern', 'Bank Transfer')
                    ");
                    $stmtInsertPay->execute([
                        ':sid' => $studentId,
                        ':bid' => $billingId,
                        ':amt' => $verifiedData['amount'],
                        ':pdate' => $verifiedData['date'],
                        ':chan' => $verifiedData['channel'],
                        ':ref' => $verifiedData['reference'],
                        ':reviewer' => $reviewerId
                    ]);
                    $paymentId = $this->pdo->lastInsertId();

                    // Update concern with new payment_id
                    $updConc = $this->pdo->prepare("UPDATE payment_concerns SET payment_id = ? WHERE concern_id = ?");
                    $updConc->execute([$paymentId, $concernId]);
                } else {
                    $stmtPay = $this->pdo->prepare("UPDATE payments SET payment_status = 'Verified', verified_by = :reviewer, verified_at = CURRENT_TIMESTAMP WHERE payment_id = :pid");
                    $stmtPay->execute([':reviewer' => $reviewerId, ':pid' => $paymentId]);
                    
                    // Fetch billing_id and amount for allocation
                    $stmtGetBill = $this->pdo->prepare("SELECT billing_id, amount FROM payments WHERE payment_id = :pid");
                    $stmtGetBill->execute([':pid' => $paymentId]);
                    $payData = $stmtGetBill->fetch(PDO::FETCH_ASSOC);
                    $billingId = $payData['billing_id'];
                    $verifiedData['amount'] = $payData['amount'];
                }

                // Phase 7F Convergence: Call the PaymentAllocationService
                $allocationService = new PaymentAllocationService($this->pdo);
                $allocationService->allocatePayment($paymentId, $studentId, $billingId, (float)$verifiedData['amount']);


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
