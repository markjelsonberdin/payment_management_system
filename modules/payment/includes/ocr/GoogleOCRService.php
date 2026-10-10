<?php
/**
 * SMS 2 - Retired Google OCR compatibility parser.
 *
 * Provider requests must use GoogleVisionOcrService through ReceiptOcrProcessor,
 * which reserves quota and enforces idempotency/cache policy before calling Vision.
 */
class GoogleOCRService {
    /** @deprecated Retained for legacy parser callers; this class cannot make provider requests. */
    public function __construct($pdo = null) {
        // Keep the historical constructor signature. The legacy environment
        // toggle is intentionally ignored and cannot re-enable Vision access.
    }

    /** @deprecated Use ReceiptOcrProcessor for provider-backed extraction. */
    public function extractFromImage($imageContent) {
        throw new LogicException('LEGACY_OCR_SERVICE_RETIRED');
    }

    /** @deprecated Use ReceiptOcrProcessor for quota-guarded Payment Concern scans. */
    public function processReceipt($concernId, $receiptPath, $scannedBy = null) {
        throw new LogicException('LEGACY_OCR_SERVICE_RETIRED');
    }
    /**
     * Centralized Regex Extraction Engine
     */
    private function parseText($rawText) {
        $notes = [];
        $data = [
            'amount' => null,
            'reference' => null,
            'date' => null,
            'time' => null,
            'bank' => null
        ];
        
        $statuses = [
            'amount' => 'MISSING',
            'reference' => 'MISSING',
            'date' => 'MISSING',
            'bank' => 'MISSING'
        ];

        // 1. Amount Extraction (Prioritize context)
        // Match things like "Amount Paid: PHP 1,500.00", "Total: 1500"
        $amountRegexes = [
            '/(?:Amount Paid|Paid Amount|Total Paid|Total Amount)\s*:?\s*(?:PHP|\x{20B1})?\s*(\d+(?:,\d{3})*(?:\.\d{2})?)/iu',
            '/(?:Amount|Total)\s*:?\s*(?:PHP|\x{20B1})?\s*(\d+(?:,\d{3})*(?:\.\d{2})?)/iu',
            '/(?:PHP|\x{20B1})\s*(\d+(?:,\d{3})*(?:\.\d{2})?)/iu'
        ];
        foreach ($amountRegexes as $regex) {
            if (preg_match_all($regex, $rawText, $matches)) {
                $candidates = array_unique($matches[1]);
                if (count($candidates) === 1) {
                    $data['amount'] = str_replace(',', '', $candidates[0]);
                    $statuses['amount'] = 'FOUND';
                } else if (count($candidates) > 1) {
                    $statuses['amount'] = 'AMBIGUOUS';
                    $notes[] = "Multiple amount candidates found.";
                }
                break; // Stop after first successful pattern group
            }
        }

        // 2. Reference Extraction
        $refRegexes = [
            '/(?:Reference No\.?|Ref\.?\s*No\.?|Transaction ID|Transaction No\.?|Transaction Reference|Trace No\.?|Payment Ref\.?|Confirmation No\.?)\s*:?\s*([A-Za-z0-9\-_.]+)/i',
            '/(?:Ref\.?)\s*:?\s*([A-Za-z0-9\-_.]+)/i'
        ];
        foreach ($refRegexes as $regex) {
            if (preg_match_all($regex, $rawText, $matches)) {
                $candidates = array_unique($matches[1]);
                if (count($candidates) === 1) {
                    $data['reference'] = $candidates[0];
                    $statuses['reference'] = 'FOUND';
                } else if (count($candidates) > 1) {
                    $statuses['reference'] = 'AMBIGUOUS';
                    $notes[] = "Multiple reference candidates found.";
                }
                break;
            }
        }

        // 3. Date & Time Extraction
        // Dates like 08/26/2026, 2026-08-26, Aug 26, 2026
        if (preg_match('/(\d{2}[\/\-]\d{2}[\/\-]\d{4}|\d{4}[\/\-]\d{2}[\/\-]\d{2}|(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]* \d{1,2},? \d{4})/i', $rawText, $dMatch)) {
            $data['date'] = date('Y-m-d', strtotime($dMatch[1]));
            $statuses['date'] = 'FOUND';
        }
        
        if (preg_match('/(\d{1,2}:\d{2}(?::\d{2})?\s*(?:AM|PM|am|pm)?)/i', $rawText, $tMatch)) {
            $data['time'] = date('H:i:s', strtotime($tMatch[1]));
        }

        // 4. Bank / Channel Extraction
        $banks = ['GCash', 'Maya', 'AUB', 'BDO', 'BPI', 'UnionBank', 'LandBank', 'Metrobank'];
        $foundBanks = [];
        // HelloMoney/HMA identifies the receipt channel, not the authoritative
        // reconciliation source. The corresponding transaction must still be
        // present in an imported AUB statement.
        if (preg_match('/\bhello\s*money\b|\bHMA\b/i', $rawText)) {
            $foundBanks[] = 'HelloMoney';
        }
        foreach ($banks as $b) {
            if (stripos($rawText, $b) !== false) {
                $foundBanks[] = $b;
            }
        }
        if (in_array('HelloMoney', $foundBanks, true) && in_array('AUB', $foundBanks, true)) {
            $foundBanks = array_values(array_diff($foundBanks, ['AUB']));
        }
        if (count($foundBanks) === 1) {
            $data['bank'] = $foundBanks[0];
            $statuses['bank'] = 'FOUND';
        } else if (count($foundBanks) > 1) {
            $statuses['bank'] = 'AMBIGUOUS';
            $notes[] = "Multiple bank candidates found.";
        }

        // 5. Global Extraction Status
        $hasMissing = in_array('MISSING', $statuses);
        $hasAmbiguous = in_array('AMBIGUOUS', $statuses);

        if ($hasAmbiguous) {
            $globalStatus = 'AMBIGUOUS';
        } else if ($hasMissing) {
            $globalStatus = 'PARTIAL';
        } else {
            $globalStatus = 'COMPLETE';
        }

        return [
            'success' => true,
            'extraction_status' => $globalStatus,
            'data' => $data,
            'raw_text' => $rawText,
            'notes' => $notes
        ];
    }
}
