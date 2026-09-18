<?php
/**
 * Bank Reconciliation Service
 * Handles AUB CSV imports and OCR matching logic.
 */
class BankReconciliationService {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Imports an AUB CSV bank statement.
     */
    public function importAUBStatement($filePath, $uploaderId, $originalFilename) {
        if (!file_exists($filePath)) {
            throw new Exception("File not found.");
        }

        $fileHash = hash_file('sha256', $filePath);

        // Check for duplicates
        $stmt = $this->pdo->prepare("SELECT id FROM bank_statements WHERE file_hash = ?");
        $stmt->execute([$fileHash]);
        if ($stmt->fetch()) {
            throw new Exception("This bank statement has already been uploaded.");
        }

        $handle = null;
        $this->pdo->beginTransaction();
        try {
            // Create statement record
            $stmt = $this->pdo->prepare("INSERT INTO bank_statements (source_bank, filename, file_hash, uploaded_by, status) VALUES ('AUB', ?, ?, ?, 'Pending')");
            $stmt->execute([$originalFilename, $fileHash, $uploaderId]);
            $statementId = $this->pdo->lastInsertId();

            $handle = fopen($filePath, "r");
            if ($handle === false) {
                throw new Exception("Could not read CSV file.");
            }

            // Only the repository's documented five-column import shape is
            // supported. Never silently discard the first transaction row.
            $header = fgetcsv($handle, 0, ',');
            $columns = $header === false ? [] : array_map(static function ($value) {
                return strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string)$value)));
            }, $header);
            if (array_slice($columns, 0, 5) !== ['date', 'time', 'reference', 'description', 'amount']) {
                throw new Exception('Unsupported AUB CSV columns. Expected Date, Time, Reference, Description, Amount. Import was rolled back.');
            }
            $rowCount = 0;
            
            $insertRow = $this->pdo->prepare("
                INSERT INTO bank_statement_rows (statement_id, transaction_date, transaction_time, reference_number, description, amount, raw_row_data)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            // Row-level duplicate check query
            $checkRow = $this->pdo->prepare("
                SELECT 1 FROM bank_statement_rows bsr
                JOIN bank_statements bs ON bsr.statement_id = bs.id
                WHERE bs.source_bank = 'AUB' 
                  AND bsr.reference_number = ? 
                  AND bsr.transaction_date = ? 
                  AND bsr.transaction_time = ? 
                  AND bsr.amount = ?
            ");

            while (($data = fgetcsv($handle, 0, ",")) !== false) {
                // The current supported CSV shape is Date, Time, Reference,
                // Description, Amount. Reject malformed rows instead of
                // silently importing a 1970 date or a zero-value payment.
                if (count($data) >= 5 && count(array_filter($data, static fn($value) => trim((string)$value) !== '')) > 0) {
                    $dateTimestamp = strtotime(trim((string)$data[0]));
                    $timeTimestamp = strtotime(trim((string)$data[1]));
                    $ref = trim($data[2]);
                    $desc = trim($data[3]);
                    $amount = trim(str_replace(',', '', (string)$data[4]));
                    if ($dateTimestamp === false || $timeTimestamp === false || $ref === '' || self::amountInCents($amount) === null || self::amountInCents($amount) <= 0) {
                        throw new Exception('AUB CSV contains an invalid date, time, reference or amount. Import was rolled back.');
                    }
                    $date = date('Y-m-d', $dateTimestamp);
                    $time = date('H:i:s', $timeTimestamp);

                    // Application-level duplicate check
                    $checkRow->execute([$ref, $date, $time, $amount]);
                    if ($checkRow->fetch()) {
                        continue; // Skip duplicate row
                    }

                    $insertRow->execute([
                        $statementId,
                        $date,
                        $time,
                        $ref,
                        $desc,
                        $amount,
                        json_encode($data)
                    ]);
                    $rowCount++;
                } elseif (count(array_filter($data, static fn($value) => trim((string)$value) !== '')) > 0) {
                    throw new Exception('AUB CSV contains an incomplete transaction row. Import was rolled back.');
                }
            }
            if ($rowCount === 0) {
                throw new Exception('No new AUB transactions were found in the CSV. Import was rolled back.');
            }

            $this->pdo->prepare("UPDATE bank_statements SET status = 'Processed', row_count = ? WHERE id = ?")->execute([$rowCount, $statementId]);
            $this->pdo->commit();

            return ['success' => true, 'statement_id' => $statementId, 'rows_imported' => $rowCount];

        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    /**
     * Matches an OCR result against the bank statements.
     */
    public function reconcileConcern($ocrResultId, $lockRows = false) {
        $stmt = $this->pdo->prepare("SELECT o.*, pc.verification_status, s.student_number AS expected_student_number FROM ocr_results o JOIN payment_concerns pc ON pc.concern_id = o.concern_id JOIN students s ON s.student_id = pc.student_id WHERE o.ocr_result_id = ?");
        $stmt->execute([$ocrResultId]);
        $ocr = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$ocr) {
            return ['status' => 'OCR_PENDING', 'message' => 'OCR result is not available yet.', 'candidates' => []];
        }

        $reference = strtoupper(trim((string)($ocr['reference_number'] ?? '')));
        $amount = $ocr['extracted_amount'];
        $date = $ocr['transaction_date'];
        if ($reference === '' || self::amountInCents($amount) === null || self::amountInCents($amount) <= 0 || !$date || $ocr['extraction_status'] !== 'COMPLETE') {
            return ['status' => 'OCR_INCOMPLETE', 'message' => 'OCR evidence is incomplete or needs correction.', 'candidates' => []];
        }
        $detectedBank = strtoupper(trim((string)($ocr['bank_name'] ?? '')));
        $isHelloMoney = str_contains($detectedBank, 'HELLOMONEY') || str_contains($detectedBank, 'HELLO MONEY') || $detectedBank === 'HMA';
        if ($detectedBank !== '' && !$isHelloMoney && !str_contains($detectedBank, 'AUB') && !str_contains($detectedBank, 'ASIA UNITED BANK')) {
            return ['status' => 'SOURCE_UNSUPPORTED', 'message' => 'This receipt channel is not supported by the AUB reconciliation workflow.', 'candidates' => []];
        }
        $receiptStudentNumber = null;
        if ($isHelloMoney) {
            $ocrText = json_decode((string)($ocr['raw_json'] ?? ''), true)['text'] ?? '';
            if (preg_match('/\bStudent\s*(?:Number|No\.?|ID)\s*:?\s*(S?\d{7,12})\b/i', $ocrText, $studentMatch)) {
                $receiptStudentNumber = strtoupper($studentMatch[1]);
            }
            if ($receiptStudentNumber === null) {
                return ['status' => 'OCR_INCOMPLETE', 'message' => 'HelloMoney receipt student number was not extracted. Re-scan or investigate before approval.', 'candidates' => []];
            }
            $normalizeStudentNumber = static function ($number) {
                return preg_replace('/^S(?=\d+$)/', '', strtoupper(trim((string)$number)));
            };
            if ($normalizeStudentNumber($receiptStudentNumber) !== $normalizeStudentNumber($ocr['expected_student_number'])) {
                return [
                    'status' => 'MISMATCH',
                    'message' => 'HelloMoney receipt student number differs from the concern student. Do not credit this student.',
                    'receipt_student_number' => $receiptStudentNumber,
                    'expected_student_number' => $ocr['expected_student_number'],
                    'student_number_match' => false,
                    'candidates' => [],
                ];
            }
        }

        // Only AUB imports are implemented. Never search other payment channels.
        $sql = "SELECT r.id, r.reference_number, r.amount, r.transaction_date, r.transaction_time,
                       r.status, r.matched_concern_id, r.matched_payment_id,
                       s.source_bank, s.filename, s.id AS statement_id
                FROM bank_statement_rows r
                JOIN bank_statements s ON s.id = r.statement_id
                WHERE s.source_bank = 'AUB' AND UPPER(TRIM(r.reference_number)) = ?
                ORDER BY r.transaction_date DESC, r.id DESC LIMIT 21";
        if ($lockRows) {
            $sql .= ' FOR UPDATE';
        }
        $stmtBank = $this->pdo->prepare($sql);
        $stmtBank->execute([$reference]);
        $rows = $stmtBank->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) {
            return ['status' => 'NO_MATCH', 'message' => 'No matching AUB transaction reference was found in imported records.', 'candidates' => []];
        }
        if (count($rows) > 20) {
            return ['status' => 'POSSIBLE_MATCH', 'message' => 'More than 20 AUB records share this reference. Investigate before approval.', 'candidates' => []];
        }

        $candidates = [];
        foreach ($rows as $row) {
            $amountMatch = self::amountInCents($row['amount']) === self::amountInCents($amount);
            $dateMatch = $row['transaction_date'] === $date;
            $usedElsewhere = $row['status'] !== 'Unmatched' && (int)($row['matched_concern_id'] ?? 0) !== (int)$ocr['concern_id'];
            $candidate = [
                'id' => (int)$row['id'],
                'reference' => $row['reference_number'],
                'amount' => $row['amount'],
                'date' => $row['transaction_date'],
                'time' => $row['transaction_time'],
                'source' => $row['source_bank'],
                'statement' => $row['filename'],
                'statement_id' => (int)$row['statement_id'],
                'row_status' => $row['status'],
                'linked_concern_id' => $row['matched_concern_id'] !== null ? (int)$row['matched_concern_id'] : null,
                'linked_payment_id' => $row['matched_payment_id'] !== null ? (int)$row['matched_payment_id'] : null,
                'reference_match' => true,
                'amount_match' => $amountMatch,
                'date_match' => $dateMatch,
                'used_elsewhere' => $usedElsewhere,
            ];
            $candidate['score'] = ($amountMatch ? 2 : 0) + ($dateMatch ? 1 : 0);
            $candidates[] = $candidate;
        }
        usort($candidates, static function ($a, $b) {
            return ($a['used_elsewhere'] <=> $b['used_elsewhere'])
                ?: ($b['score'] <=> $a['score'])
                ?: ($b['id'] <=> $a['id']);
        });
        $best = $candidates[0];
        $perfectCount = count(array_filter($candidates, static function ($candidate) {
            return $candidate['score'] === 3 && !$candidate['used_elsewhere'];
        }));
        $alreadyConsumed = count(array_filter($candidates, static function ($candidate) {
            return $candidate['score'] === 3 && $candidate['used_elsewhere'];
        })) > 0;
        if ($alreadyConsumed) {
            $status = 'MISMATCH';
            $message = 'An identical AUB transaction has already been linked to another concern.';
        } elseif ($best['used_elsewhere']) {
            $status = 'MISMATCH';
            $message = 'The closest AUB record is already linked or unavailable.';
        } elseif ($best['score'] === 3 && $perfectCount === 1) {
            $status = 'PERFECT_MATCH';
            $message = 'Reference, amount and date agree with one available AUB transaction.';
        } elseif ($best['score'] === 3) {
            $status = 'POSSIBLE_MATCH';
            $message = 'Multiple exact AUB candidates exist. Manual investigation is required.';
        } elseif ($best['amount_match']) {
            $status = 'POSSIBLE_MATCH';
            $message = 'Reference and amount agree, but the transaction dates differ.';
        } else {
            $status = 'MISMATCH';
            $message = 'An AUB reference exists, but the amounts differ.';
        }

        return [
            'status' => $status,
            'message' => $message,
            'bank_row_id' => $best['id'],
            'reference' => ['receipt_value' => $ocr['reference_number'], 'bank_value' => $best['reference'], 'status' => 'MATCH'],
            'amount' => ['receipt_value' => $amount, 'bank_value' => $best['amount'], 'difference_cents' => self::amountInCents($amount) - self::amountInCents($best['amount']), 'status' => $best['amount_match'] ? 'MATCH' : 'DIFFERENCE'],
            'date' => ['receipt_value' => $date, 'bank_value' => $best['date'], 'status' => $best['date_match'] ? 'MATCH' : 'DIFFERENCE'],
            'receipt_student_number' => $receiptStudentNumber,
            'expected_student_number' => $isHelloMoney ? $ocr['expected_student_number'] : null,
            'student_number_match' => $isHelloMoney ? true : null,
            'matched_transaction' => $best,
            'candidates' => $candidates,
        ];
    }

    public static function amountInCents($amount) {
        if ($amount === null || !preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', trim((string)$amount), $parts)) {
            return null;
        }
        $whole = (int)$parts[2];
        $fraction = (int)str_pad($parts[3] ?? '', 2, '0');
        $cents = $whole * 100 + $fraction;
        return $parts[1] === '-' ? -$cents : $cents;
    }
}
