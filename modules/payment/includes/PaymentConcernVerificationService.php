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
     * @return array ['status' => 'READY_FOR_REVIEW' | 'NEEDS_CORRECTION' | 'POSSIBLE_DUPLICATE' | 'AMBIGUOUS' | 'NO_TEXT', 'remarks' => string]
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
            return ['status' => 'NEEDS_CORRECTION', 'remarks' => 'No OCR data extracted. Please review manually.'];
        }

        if ($ocr['extraction_status'] === 'NO_TEXT_DETECTED') {
            return ['status' => 'NO_TEXT', 'remarks' => 'Google Vision could not detect any text in the receipt.'];
        }

        $issues = [];
        $status = 'READY_FOR_REVIEW';

        if ($ocr['extraction_status'] === 'AMBIGUOUS') {
            $status = 'AMBIGUOUS';
            $issues[] = "Multiple plausible candidates found for one or more fields. Manual review required.";
        } else if ($ocr['extraction_status'] === 'PARTIAL') {
            $status = 'NEEDS_CORRECTION';
            $issues[] = "Required fields missing from OCR extraction (Ref No, Date, Amount, or Bank).";
        }

        // Amount validation (Positive, Reasonable, Not exceeding balance if linked to billing)
        if ($ocr['extracted_amount'] !== null && $ocr['extracted_amount'] <= 0) {
            $issues[] = "Extracted amount is zero or negative.";
            $status = 'NEEDS_CORRECTION';
        }
        if ($ocr['extracted_amount'] !== null && !empty($ocr['billing_id']) && $ocr['extracted_amount'] > $ocr['remaining_balance']) {
            $issues[] = "Extracted amount (₱{$ocr['extracted_amount']}) exceeds the remaining billing balance (₱{$ocr['remaining_balance']}).";
            $status = 'NEEDS_CORRECTION';
        }

        // Reference-number duplicate check across payments and other concerns
        if (!empty($ocr['reference_number'])) {
            $stmtDup = $this->pdo->prepare("
                SELECT payment_id FROM payments WHERE reference_number = :ref AND payment_status != 'Rejected'
                UNION 
                SELECT concern_id FROM ocr_results WHERE reference_number = :ref AND concern_id != :cid
            ");
            $stmtDup->execute([':ref' => $ocr['reference_number'], ':cid' => $concernId]);
            if ($stmtDup->fetch()) {
                $status = 'POSSIBLE_DUPLICATE';
                $issues[] = "Reference number '{$ocr['reference_number']}' has already been submitted or used in another payment.";
            }
        }

        // Note: We deliberately DO NOT fail if payment_id is empty, because manual receipt uploads often have no initial payment record until verified.

        // Bank/channel validation
        $supportedBanks = ['GCash', 'Maya', 'BDO', 'BPI', 'UnionBank', 'LandBank', 'Metrobank', 'AUB'];
        $bankMatched = false;
        if (!empty($ocr['bank_name'])) {
            foreach ($supportedBanks as $bank) {
                if (stripos($ocr['bank_name'], $bank) !== false) {
                    $bankMatched = true;    
                    break;
                }
            }
            if (!$bankMatched) {
                $issues[] = "'{$ocr['bank_name']}' is not in the list of standard supported channels (May require manual verification).";
            }
        }

        // Date validation
        if (!empty($ocr['transaction_date'])) {
            $txDate = strtotime($ocr['transaction_date']);
            $now = time();
            $sixMonthsAgo = strtotime('-6 months', $now);

            if ($txDate > $now + 86400) {
                $issues[] = "Transaction date is in the future.";
                $status = 'NEEDS_CORRECTION';
            } elseif ($txDate < $sixMonthsAgo) {
                $issues[] = "Transaction date is too old (exceeds 6 months limit).";
                $status = 'NEEDS_CORRECTION';
            }
        }

        if (count($issues) > 0) {
            return [
                'status' => $status !== 'READY_FOR_REVIEW' ? $status : 'NEEDS_CORRECTION',
                'remarks' => implode(' | ', $issues)
            ];
        }

        return [
            'status' => 'READY_FOR_REVIEW',
            'remarks' => 'All automated backend validations passed. Awaiting final Accounting verification.'
        ];
    }
}
