<?php
/**
 * Payment Concern Verification Service (Rule Engine)
 * Evaluates OCR results before Cashier review (Phase 7D)
 */
class PaymentConcernVerificationService {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Evaluates the OCR result against strict business rules (Capstone Requirements).
     * 
     * @param int $concernId
     * @return array ['status' => 'Valid for Review' | 'Needs Correction', 'remarks' => string]
     */
    public function evaluateConcern($concernId) {
        // Fetch OCR Result and Context
        $stmt = $this->pdo->prepare("
            SELECT o.*, p.billing_id, b.remaining_balance, pc.payment_id, pc.receipt_path
            FROM ocr_results o
            JOIN payment_concerns pc ON o.concern_id = pc.concern_id
            LEFT JOIN payments p ON pc.payment_id = p.payment_id
            LEFT JOIN billing b ON p.billing_id = b.billing_id
            WHERE o.concern_id = :cid LIMIT 1
        ");
        $stmt->execute([':cid' => $concernId]);
        $ocr = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$ocr) {
            return ['status' => 'Needs Correction', 'remarks' => 'OCR confidence/error handling: No OCR data extracted. Please review manually.'];
        }

        $issues = [];

        // 1. Required fields validation
        if (empty($ocr['reference_number']) || empty($ocr['transaction_date']) || empty($ocr['extracted_amount']) || empty($ocr['bank_name']) || $ocr['reference_number'] === 'N/A') {
            $issues[] = "Required fields missing: OCR failed to extract one or more essential fields (Ref No, Date, Amount, Bank).";
        }

        // 2. Data format validation
        if (!is_numeric($ocr['extracted_amount'])) {
            $issues[] = "Data format validation: Extracted amount is not a valid number.";
        }
        if (strtotime($ocr['transaction_date']) === false) {
            $issues[] = "Data format validation: Invalid transaction date format.";
        }

        // 3. Amount validation (Positive, Reasonable, Not exceeding balance)
        if ($ocr['extracted_amount'] <= 0) {
            $issues[] = "Amount validation: Extracted amount is zero or negative.";
        }
        if ($ocr['billing_id'] && $ocr['extracted_amount'] > $ocr['remaining_balance']) {
            $issues[] = "Amount validation: Extracted amount (₱{$ocr['extracted_amount']}) exceeds the remaining billing balance (₱{$ocr['remaining_balance']}).";
        }

        // 4 & 5. Reference-number & Receipt duplicate check
        if (!empty($ocr['reference_number']) && $ocr['reference_number'] !== 'N/A') {
            $stmtDup = $this->pdo->prepare("
                SELECT payment_id FROM payments WHERE reference_number = :ref AND payment_status != 'Rejected'
                UNION 
                SELECT concern_id FROM ocr_results WHERE reference_number = :ref AND concern_id != :cid
            ");
            $stmtDup->execute([':ref' => $ocr['reference_number'], ':cid' => $concernId]);
            if ($stmtDup->fetch()) {
                $issues[] = "Receipt duplicate detection: Reference number '{$ocr['reference_number']}' has already been submitted or used in another payment.";
            }
        }

        // 6. Student/payment matching (Context check)
        if (empty($ocr['payment_id'])) {
            $issues[] = "Student/payment matching: Concern is not linked to a specific payment/billing record.";
        }

        // 7. Bank/channel validation
        $supportedBanks = ['GCash', 'Maya', 'BDO', 'BPI', 'UnionBank', 'LandBank', 'Metrobank', 'AUB'];
        $bankMatched = false;
        foreach ($supportedBanks as $bank) {
            if (stripos($ocr['bank_name'], $bank) !== false) {
                $bankMatched = true;    
                break;
            }
        }
        if (!$bankMatched && !empty($ocr['bank_name'])) {
            $issues[] = "Bank/channel validation: '{$ocr['bank_name']}' is not in the list of expected/supported payment channels.";
        }

        // 8. Date validation
        if (!empty($ocr['transaction_date'])) {
            $txDate = strtotime($ocr['transaction_date']);
            $now = time();
            $sixMonthsAgo = strtotime('-6 months', $now);

            if ($txDate > $now) {
                $issues[] = "Date validation: Transaction date is in the future.";
            } elseif ($txDate < $sixMonthsAgo) {
                $issues[] = "Date validation: Transaction date is too old (exceeds 6 months limit).";
            }
        }

        // 9. OCR confidence/error handling
        if ($ocr['confidence_score'] < 75) {
            $issues[] = "OCR confidence handling: Extraction confidence is too low ({$ocr['confidence_score']}%). Manual verification required.";
        }

        // Important: OCR should not automatically approve the payment.
        if (count($issues) > 0) {
            return [
                'status' => 'Needs Correction',
                'remarks' => implode(' | ', $issues)
            ];
        }

        return [
            'status' => 'Valid for Review',
            'remarks' => 'All capstone backend validations passed. Awaiting final Accounting verification.'
        ];
    }
}
