<?php
/**
 * SMS 2 - Google OCR Service
 * Centralized Engine for Payment Receipt Extraction
 */
use Google\Cloud\Vision\V1\ImageAnnotatorClient;

class GoogleOCRService {
    private $pdo;
    private $mockMode;

    public function __construct($pdo = null) {
        $this->pdo = $pdo;
        $this->mockMode = (getenv('OCR_MODE') === 'mock');
    }

    /**
     * Extracts text from image content (No DB save).
     * Used by Student Portal for live preview.
     */
    public function extractFromImage($imageContent) {
        $rawText = $this->callGoogleVision($imageContent);
        if ($rawText === null) {
            return [
                'success' => true,
                'extraction_status' => 'NO_TEXT_DETECTED',
                'data' => null,
                'raw_text' => null,
                'notes' => ['No text detected by Google Vision.']
            ];
        }
        return $this->parseText($rawText);
    }

    /**
     * Processes an existing receipt file for a Payment Concern (Saves to DB).
     * Used by Accounting.
     */
    public function processReceipt($concernId, $receiptPath) {
        if (!$this->pdo) {
            throw new Exception("Database connection required for processReceipt.");
        }

        $fullPath = ROOT_PATH . '/' . ltrim($receiptPath, '/');
        if (!file_exists($fullPath) || !is_file($fullPath)) {
            throw new Exception("Receipt file is missing or invalid: " . $fullPath);
        }

        $imageContent = file_get_contents($fullPath);
        $result = $this->extractFromImage($imageContent);

        $this->pdo->beginTransaction();
        try {
            if ($result['extraction_status'] === 'NO_TEXT_DETECTED' || empty($result['data'])) {
                $status = 'NO_TEXT_DETECTED';
                $notes = 'No text detected.';
                $amount = null;
                $ref = null;
                $bank = null;
                $date = null;
                $time = null;
                $rawJson = json_encode(['text' => '']);
            } else {
                $status = $result['extraction_status'];
                $notes = implode("\n", $result['notes']);
                $data = $result['data'];
                $amount = $data['amount'];
                $ref = $data['reference'];
                $bank = $data['bank'];
                $date = $data['date'];
                $time = $data['time'];
                $rawJson = json_encode(['text' => $result['raw_text']]);
            }

            // Save to ocr_results
            $stmt = $this->pdo->prepare("
                INSERT INTO ocr_results 
                (concern_id, extracted_amount, bank_name, reference_number, transaction_date, transaction_time, raw_json, extraction_status, extraction_notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    extracted_amount = VALUES(extracted_amount),
                    bank_name = VALUES(bank_name),
                    reference_number = VALUES(reference_number),
                    transaction_date = VALUES(transaction_date),
                    transaction_time = VALUES(transaction_time),
                    raw_json = VALUES(raw_json),
                    extraction_status = VALUES(extraction_status),
                    extraction_notes = VALUES(extraction_notes)
            ");
            $stmt->execute([
                $concernId,
                $amount !== null ? (float)$amount : null,
                $bank,
                $ref,
                $date,
                $time,
                $rawJson,
                $status,
                $notes
            ]);

            // Update concern status
            $upd = $this->pdo->prepare("UPDATE payment_concerns SET ocr_status = 'Completed' WHERE concern_id = ?");
            $upd->execute([$concernId]);

            $this->pdo->commit();
            
            return [
                'success' => true,
                'extraction_status' => $status,
                'data' => $result['data'] ?? null
            ];
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Calls Google Vision API or returns Mock Data.
     */
    private function callGoogleVision($imageContent) {
        $credentialsPath = ROOT_PATH . '/secure-config/google-credentials.json';
        
        // Also support reading from payment module .env if defined
        $envPath = ROOT_PATH . '/modules/payment/.env';
        if (file_exists($envPath)) {
            $envVars = @parse_ini_file($envPath);
            if ($envVars !== false && !empty($envVars['GOOGLE_APPLICATION_CREDENTIALS'])) {
                // Remove any quotes
                $credentialsPath = trim($envVars['GOOGLE_APPLICATION_CREDENTIALS'], '"\' ');
            }
        }
        
        if ($this->mockMode) {
            sleep(1);
            return "MOCK OCR SOURCE\nGCash\nAmount Paid: PHP 1,500.00\nRef No. 1029384756\nDate: 08/26/2026 14:30";
        }

        if (!file_exists($credentialsPath)) {
            throw new Exception("OCR_CONFIGURATION_ERROR: Google Vision credentials missing.");
        }

        $imageAnnotator = new ImageAnnotatorClient([
            'credentials' => $credentialsPath
        ]);
        
        $response = $imageAnnotator->documentTextDetection($imageContent);
        $annotation = $response->getFullTextAnnotation();
        $rawText = $annotation ? $annotation->getText() : null;
        
        $imageAnnotator->close();
        return $rawText;
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
            '/(?:Amount Paid|Paid Amount|Total Paid|Total Amount)\s*:?\s*(?:PHP|Php|₱)?\s*(\d{1,3}(?:,\d{3})*(?:\.\d{2})?)/i',
            '/(?:Amount|Total)\s*:?\s*(?:PHP|Php|₱)?\s*(\d{1,3}(?:,\d{3})*(?:\.\d{2})?)/i',
            '/(?:PHP|Php|₱)\s*(\d{1,3}(?:,\d{3})*(?:\.\d{2})?)/i'
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
        foreach ($banks as $b) {
            if (stripos($rawText, $b) !== false) {
                $foundBanks[] = $b;
            }
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
