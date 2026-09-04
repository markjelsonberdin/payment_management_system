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

        $this->pdo->beginTransaction();
        try {
            // Create statement record
            $stmt = $this->pdo->prepare("INSERT INTO bank_statements (source_bank, filename, file_hash, uploaded_by, status) VALUES ('AUB', ?, ?, ?, 'Processing')");
            $stmt->execute([$originalFilename, $fileHash, $uploaderId]);
            $statementId = $this->pdo->lastInsertId();

            $handle = fopen($filePath, "r");
            if ($handle === false) {
                throw new Exception("Could not read CSV file.");
            }

            // Assume standard AUB columns: Date, Time, Reference, Description, Amount
            // In a real scenario, map these indices accurately based on the bank's format.
            $rowCount = 0;
            $headerSkipped = false;
            
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

            while (($data = fgetcsv($handle, 1000, ",")) !== false) {
                if (!$headerSkipped) {
                    $headerSkipped = true;
                    continue;
                }

                // Simplified parsing for AUB CSV
                if (count($data) >= 5) {
                    $date = date('Y-m-d', strtotime($data[0]));
                    $time = date('H:i:s', strtotime($data[1]));
                    $ref = trim($data[2]);
                    $desc = trim($data[3]);
                    $amount = (float)str_replace(',', '', $data[4]);

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
                }
            }
            fclose($handle);

            $this->pdo->prepare("UPDATE bank_statements SET status = 'Processed', row_count = ? WHERE id = ?")->execute([$rowCount, $statementId]);
            $this->pdo->commit();

            return ['success' => true, 'statement_id' => $statementId, 'rows_imported' => $rowCount];

        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Matches an OCR result against the bank statements.
     */
    public function reconcileConcern($ocrResultId) {
        $stmt = $this->pdo->prepare("SELECT * FROM ocr_results WHERE ocr_result_id = ?");
        $stmt->execute([$ocrResultId]);
        $ocr = $stmt->fetch();

        if (!$ocr) {
            return ['status' => 'NOT_FOUND', 'message' => 'OCR result not found.'];
        }

        $ref = $ocr['reference_number'];
        $amount = $ocr['extracted_amount'];
        $date = $ocr['transaction_date'];

        // Search bank records by reference number
        $stmtBank = $this->pdo->prepare("SELECT * FROM bank_statement_rows WHERE reference_number = ?");
        $stmtBank->execute([$ref]);
        $bankRows = $stmtBank->fetchAll();

        if (count($bankRows) === 0) {
            return ['status' => 'NOT_FOUND', 'message' => 'No matching bank record found for reference number.'];
        }

        // Check if amount and date match
        foreach ($bankRows as $row) {
            $amountMatch = abs((float)$row['amount'] - (float)$amount) < 0.01;
            $dateMatch = ($row['transaction_date'] === $date);

            if ($amountMatch && $dateMatch) {
                // Perfect Match
                return [
                    'status' => 'PERFECT_MATCH', 
                    'message' => 'OCR data perfectly matches bank record.',
                    'bank_row_id' => $row['id']
                ];
            } else if ($amountMatch && !$dateMatch) {
                return [
                    'status' => 'DATE_MISMATCH',
                    'message' => 'Amount matches, but dates differ (OCR: '.$date.', Bank: '.$row['transaction_date'].').',
                    'bank_row_id' => $row['id']
                ];
            } else if (!$amountMatch && $dateMatch) {
                return [
                    'status' => 'AMOUNT_MISMATCH',
                    'message' => 'Date matches, but amounts differ (OCR: '.$amount.', Bank: '.$row['amount'].').',
                    'bank_row_id' => $row['id']
                ];
            }
        }

        return ['status' => 'PARTIAL_MATCH', 'message' => 'Reference exists, but amount and date mismatch.'];
    }
}
