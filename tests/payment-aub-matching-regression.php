<?php
/** Run: php tests/payment-aub-matching-regression.php */
require_once __DIR__ . '/../modules/payment/includes/bank_recon/BankReconciliationService.php';
require_once __DIR__ . '/../modules/payment/includes/ocr/GoogleOCRService.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE students (student_id INTEGER PRIMARY KEY, student_number TEXT)");
$pdo->exec("CREATE TABLE payment_concerns (concern_id INTEGER PRIMARY KEY, student_id INTEGER, verification_status TEXT)");
$pdo->exec("CREATE TABLE ocr_results (ocr_result_id INTEGER PRIMARY KEY, concern_id INTEGER, reference_number TEXT, extracted_amount TEXT, transaction_date TEXT, bank_name TEXT, extraction_status TEXT, raw_json TEXT)");
$pdo->exec("CREATE TABLE bank_statements (id INTEGER PRIMARY KEY, source_bank TEXT, filename TEXT)");
$pdo->exec("CREATE TABLE bank_statement_rows (id INTEGER PRIMARY KEY, statement_id INTEGER, reference_number TEXT, amount TEXT, transaction_date TEXT, transaction_time TEXT, status TEXT, matched_concern_id INTEGER, matched_payment_id INTEGER)");
$pdo->exec("INSERT INTO students VALUES (1, 'S230113895'), (2, 'S230999999')");
$pdo->exec("INSERT INTO payment_concerns VALUES (1, 1, 'Pending'), (2, 1, 'Pending'), (3, 1, 'Pending'), (4, 1, 'Pending'), (5, 1, 'Pending'), (6, 1, 'Pending'), (7, 1, 'Pending'), (8, 1, 'Pending'), (9, 1, 'Pending'), (10, 2, 'Pending')");
$pdo->exec("INSERT INTO ocr_results (ocr_result_id, concern_id, reference_number, extracted_amount, transaction_date, bank_name, extraction_status) VALUES
    (1, 1, ' AUB-123 ', '2500.00', '2026-09-18', 'AUB', 'COMPLETE'),
    (2, 2, 'DIFF-1', '2500.00', '2026-09-18', 'AUB', 'COMPLETE'),
    (3, 3, 'GCASH-1', '2500.00', '2026-09-18', 'GCash', 'COMPLETE'),
    (4, 4, 'NONE', '2500.00', '2026-09-18', 'AUB', 'COMPLETE'),
    (5, 5, 'DUPE-1', '2500.00', '2026-09-18', 'AUB', 'COMPLETE'),
    (6, 6, 'DOUBLE-1', '2500.00', '2026-09-18', 'AUB', 'COMPLETE'),
    (7, 7, 'DATE-1', '2500.00', '2026-09-18', 'AUB', 'COMPLETE'),
    (8, 8, 'PARTIAL-1', '2500.00', '2026-09-18', 'AUB', 'PARTIAL'),
    (9, 9, 'HMBP-001', '2100.00', '2026-08-22', 'HelloMoney', 'COMPLETE'),
    (10, 10, 'HMBP-001', '2100.00', '2026-08-22', 'HelloMoney', 'COMPLETE')");
$hmaText = "Pay Bills\nhellomoney\nReference No.\nHMBP-001\nDate\n08/22/2026 7:59 PM\nStudent Number\n230113895\nAmount\n2,100.00";
$setReceiptText = $pdo->prepare('UPDATE ocr_results SET raw_json = ? WHERE ocr_result_id = ?');
$setReceiptText->execute([json_encode(['text' => $hmaText]), 9]);
$setReceiptText->execute([json_encode(['text' => $hmaText]), 10]);
$ocrParser = new ReflectionMethod(GoogleOCRService::class, 'parseText');
$ocrService = (new ReflectionClass(GoogleOCRService::class))->newInstanceWithoutConstructor();
$parsedHma = $ocrParser->invoke($ocrService, $hmaText);
if ($parsedHma['extraction_status'] !== 'COMPLETE'
    || $parsedHma['data']['bank'] !== 'HelloMoney'
    || $parsedHma['data']['reference'] !== 'HMBP-001'
    || $parsedHma['data']['amount'] !== '2100.00'
    || $parsedHma['data']['date'] !== '2026-08-22') {
    throw new RuntimeException('HelloMoney receipt fields were not extracted as expected.');
}
$pdo->exec("INSERT INTO bank_statements VALUES (1, 'AUB', 'statement.csv')");
$pdo->exec("INSERT INTO bank_statement_rows VALUES
    (1, 1, 'AUB-123', '2000.00', '2026-09-18', '12:00:00', 'Unmatched', NULL, NULL),
    (2, 1, 'AUB-123', '2500.00', '2026-09-18', '12:01:00', 'Unmatched', NULL, NULL),
    (3, 1, 'DIFF-1', '1500.00', '2026-09-18', '12:02:00', 'Unmatched', NULL, NULL),
    (4, 1, 'GCASH-1', '2500.00', '2026-09-18', '12:03:00', 'Unmatched', NULL, NULL),
    (5, 1, 'DUPE-1', '2500.00', '2026-09-18', '12:04:00', 'Matched', 99, 99),
    (6, 1, 'DOUBLE-1', '2500.00', '2026-09-18', '12:05:00', 'Unmatched', NULL, NULL),
    (7, 1, 'DOUBLE-1', '2500.00', '2026-09-18', '12:06:00', 'Matched', 99, 99),
    (8, 1, 'DATE-1', '2500.00', '2026-09-17', '12:07:00', 'Unmatched', NULL, NULL),
    (9, 1, 'PARTIAL-1', '2500.00', '2026-09-18', '12:08:00', 'Unmatched', NULL, NULL),
    (10, 1, 'HMBP-001', '2100.00', '2026-08-22', '19:59:00', 'Unmatched', NULL, NULL)");

$service = new BankReconciliationService($pdo);
$expected = [
    1 => 'PERFECT_MATCH',
    2 => 'MISMATCH',
    3 => 'SOURCE_UNSUPPORTED',
    4 => 'NO_MATCH',
    5 => 'MISMATCH',
    6 => 'MISMATCH',
    7 => 'POSSIBLE_MATCH',
    8 => 'OCR_INCOMPLETE',
    9 => 'PERFECT_MATCH',
    10 => 'MISMATCH',
];
foreach ($expected as $ocrId => $status) {
    $actual = $service->reconcileConcern($ocrId);
    if ($actual['status'] !== $status) {
        throw new RuntimeException("OCR $ocrId: expected $status, got {$actual['status']}");
    }
}
if ($service->reconcileConcern(1)['bank_row_id'] !== 2) {
    throw new RuntimeException('Matching must choose the exact row, not the first mismatching row.');
}
if (BankReconciliationService::amountInCents('2500.01') !== 250001 || BankReconciliationService::amountInCents('-0.01') !== -1) {
    throw new RuntimeException('Decimal amount comparison is incorrect.');
}
echo "AUB matching regression checks passed.\n";
