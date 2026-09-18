<?php
/**
 * Payment Concern Service
 * Handles submission, retrieval, and verification of payment concerns.
 */
require_once __DIR__ . '/PaymentAllocationService.php';
require_once __DIR__ . '/bank_recon/BankReconciliationService.php';
require_once __DIR__ . '/PaymentNotificationService.php';

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
                   o.ocr_result_id, o.extracted_amount, o.bank_name, o.confidence_score,
                   o.reference_number as ocr_ref, o.transaction_date, o.extraction_status
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
        if (!in_array($action, ['Verify', 'Reject', 'Hold'], true)) {
            throw new Exception('Invalid concern decision.');
        }
        try {
            $this->pdo->beginTransaction();

            $stmtGet = $this->pdo->prepare("SELECT payment_id, student_id, verification_status, ocr_status FROM payment_concerns WHERE concern_id = :cid FOR UPDATE");
            $stmtGet->execute([':cid' => $concernId]);
            $concern = $stmtGet->fetch(PDO::FETCH_ASSOC);
            
            if (!$concern) {
                throw new Exception("Payment concern not found.");
            }
            if (!in_array($concern['verification_status'], ['Pending', 'On Hold'], true)) {
                throw new Exception("This concern has already been processed (Status: {$concern['verification_status']}).");
            }

            $paymentId = $concern['payment_id'];
            $studentId = $concern['student_id'];

            if ($action === 'Hold') {
                if ($remarks === '') {
                    throw new Exception('An investigation reason is required to hold this concern.');
                }
                $hold = $this->pdo->prepare("UPDATE payment_concerns SET verification_status = 'On Hold', hold_reason = ?, held_by = ?, held_at = CURRENT_TIMESTAMP WHERE concern_id = ?");
                $hold->execute([$remarks, $reviewerId, $concernId]);
            } elseif ($action === 'Reject') {
                if ($remarks === '') {
                    throw new Exception('A rejection reason is required.');
                }
                $reject = $this->pdo->prepare("UPDATE payment_concerns SET verification_status = 'Rejected', reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP, remarks = CONCAT(COALESCE(remarks, ''), '\n[Review] ', ?), hold_reason = NULL, held_by = NULL, held_at = NULL WHERE concern_id = ?");
                $reject->execute([$reviewerId, $remarks, $concernId]);
                // Rejecting a concern must not reverse or reject an existing payment.
            } else {
                if ($concern['ocr_status'] !== 'Completed') {
                    throw new Exception('Run OCR and review the receipt before approval.');
                }
                $stmtOcr = $this->pdo->prepare("SELECT ocr_result_id FROM ocr_results WHERE concern_id = ? LIMIT 1");
                $stmtOcr->execute([$concernId]);
                $ocrResultId = $stmtOcr->fetchColumn();
                if (!$ocrResultId) {
                    throw new Exception('OCR evidence is missing.');
                }
                $recon = new BankReconciliationService($this->pdo);
                $match = $recon->reconcileConcern((int)$ocrResultId, true);
                if ($match['status'] !== 'PERFECT_MATCH') {
                    throw new Exception('Approval requires one available exact AUB match. Current result: ' . $match['status']);
                }
                $bank = $match['matched_transaction'];
                if ($bank['row_status'] !== 'Unmatched' || $bank['linked_concern_id'] !== null || $bank['linked_payment_id'] !== null) {
                    throw new Exception('The AUB transaction has already been used.');
                }

                // Older verified payments predate the bank-row link. Do not
                // credit the same AUB reference again through a new concern.
                $existingVerified = $this->pdo->prepare("SELECT payment_id FROM payments WHERE UPPER(TRIM(reference_number)) = ? AND payment_status = 'Verified' AND payment_id <> ? LIMIT 1 FOR UPDATE");
                $existingVerified->execute([strtoupper(trim((string)$bank['reference'])), (int)$paymentId]);
                if ($existingVerified->fetchColumn()) {
                    throw new Exception('A verified payment already uses this reference. Investigate before posting another.');
                }

                if ($paymentId) {
                    $stmtPay = $this->pdo->prepare("SELECT * FROM payments WHERE payment_id = ? AND student_id = ? FOR UPDATE");
                    $stmtPay->execute([$paymentId, $studentId]);
                    $payment = $stmtPay->fetch(PDO::FETCH_ASSOC);
                    if (!$payment || $payment['payment_status'] !== 'Pending' || $payment['transaction_type'] !== 'Payment Concern' || $payment['payment_channel'] !== 'Bank') {
                        throw new Exception('Linked payment is not an unposted AUB concern payment. Investigate it instead of posting again.');
                    }
                    if (BankReconciliationService::amountInCents($payment['amount']) !== BankReconciliationService::amountInCents($bank['amount'])
                        || strtoupper(trim((string)$payment['reference_number'])) !== strtoupper(trim((string)$bank['reference']))
                        || $payment['payment_date'] !== $bank['date']) {
                        throw new Exception('Linked payment details differ from the AUB transaction.');
                    }
                    $allocated = $this->pdo->prepare("SELECT COUNT(*) FROM payment_allocations WHERE payment_id = ?");
                    $allocated->execute([$paymentId]);
                    if ((int)$allocated->fetchColumn() > 0) {
                        throw new Exception('This payment already has allocations.');
                    }
                    $billingId = (int)$payment['billing_id'];
                    $this->pdo->prepare("UPDATE payments SET payment_status = 'Verified', verified_by = ?, verified_at = CURRENT_TIMESTAMP WHERE payment_id = ?")
                        ->execute([$reviewerId, $paymentId]);
                } else {
                    if (!$billingId) {
                        throw new Exception('Select the student billing to credit before approval.');
                    }
                    // The chosen billing must belong to the concern's student.
                    $stmtBilling = $this->pdo->prepare("SELECT billing_id FROM billing WHERE billing_id = ? AND student_id = ? FOR UPDATE");
                    $stmtBilling->execute([$billingId, $studentId]);
                    if (!$stmtBilling->fetchColumn()) {
                        throw new Exception('No billing owned by this student is available for the concern.');
                    }
                    $insert = $this->pdo->prepare("INSERT INTO payments (student_id, billing_id, amount, payment_date, payment_channel, reference_number, payment_status, verified_by, verified_at, transaction_type, payment_method) VALUES (?, ?, ?, ?, 'Bank', ?, 'Verified', ?, CURRENT_TIMESTAMP, 'Payment Concern', 'Bank Transfer')");
                    $insert->execute([$studentId, $billingId, $bank['amount'], $bank['date'], $bank['reference'], $reviewerId]);
                    $paymentId = (int)$this->pdo->lastInsertId();
                }

                $link = $this->pdo->prepare("UPDATE bank_statement_rows SET status = 'Matched', matched_concern_id = ?, matched_payment_id = ?, matched_by = ?, matched_at = CURRENT_TIMESTAMP WHERE id = ? AND status = 'Unmatched' AND matched_concern_id IS NULL AND matched_payment_id IS NULL");
                $link->execute([$concernId, $paymentId, $reviewerId, $bank['id']]);
                if ($link->rowCount() !== 1) {
                    throw new Exception('AUB transaction was consumed during approval. No payment was posted.');
                }
                $allocationService = new PaymentAllocationService($this->pdo);
                $allocationService->allocatePayment($paymentId, $studentId, $billingId, (float)$bank['amount']);
                $approve = $this->pdo->prepare("UPDATE payment_concerns SET payment_id = ?, verification_status = 'Verified', reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP, hold_reason = NULL, held_by = NULL, held_at = NULL WHERE concern_id = ?");
                $approve->execute([$paymentId, $reviewerId, $concernId]);
            }

            $this->pdo->commit();
            if ($action === 'Verify' && !empty($paymentId)) {
                $noticePayment = $this->pdo->prepare("SELECT student_id, payment_id, amount, payment_channel, reference_number FROM payments WHERE payment_id = ? AND payment_status = 'Verified' LIMIT 1");
                $noticePayment->execute([$paymentId]);
                $postedPayment = $noticePayment->fetch(PDO::FETCH_ASSOC);
                if ($postedPayment) {
                    (new PaymentNotificationService($this->pdo))->notifyVerifiedPayment(
                        (int) $postedPayment['student_id'],
                        (int) $postedPayment['payment_id'],
                        (float) $postedPayment['amount'],
                        'Verified payment concern (' . (string) $postedPayment['payment_channel'] . ')',
                        (string) $postedPayment['reference_number']
                    );
                }
            }
            return true;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
