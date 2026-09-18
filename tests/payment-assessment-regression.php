<?php
/** In-memory checks only: never connects to a configured SMS2 database. */
declare(strict_types=1);

require_once __DIR__ . '/../modules/payment/includes/BillingService.php';
require_once __DIR__ . '/../modules/payment/includes/PaymentValidationService.php';

function expect(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE students (student_id INTEGER PRIMARY KEY)");
$pdo->exec("CREATE TABLE fee_categories (category_id INTEGER PRIMARY KEY, status TEXT)");
$pdo->exec("CREATE TABLE fees (fee_id INTEGER PRIMARY KEY, category_id INTEGER, fee_name TEXT, default_amount REAL, status TEXT)");
$pdo->exec("CREATE TABLE billing (billing_id INTEGER PRIMARY KEY, student_id INTEGER, remaining_balance REAL, billing_status TEXT)");
$pdo->exec("CREATE TABLE billing_items (billing_item_id INTEGER PRIMARY KEY, billing_id INTEGER, fee_id INTEGER, fee_name TEXT, remaining_amount REAL)");
$pdo->exec("INSERT INTO students VALUES (1), (2)");
$pdo->exec("INSERT INTO fee_categories VALUES (1, 'Active'), (2, 'Active'), (3, 'Inactive')");
$pdo->exec("INSERT INTO fees VALUES (10, 1, 'Tuition', 500, 'Active'), (11, 2, 'Library', 100, 'Active'), (12, 2, 'Retired', 100, 'Inactive'), (13, 3, 'Inactive Category', 100, 'Active')");
$pdo->exec("INSERT INTO billing VALUES (7, 1, 600, 'Unpaid')");
$pdo->exec("INSERT INTO billing_items VALUES (70, 7, 10, 'Tuition', 500), (71, 7, 11, 'Library Original', 100)");

$billingService = new BillingService($pdo);
$selectedFees = new ReflectionMethod(BillingService::class, 'getActiveSelectedFees');
expect(count($selectedFees->invoke($billingService, [11])) === 1, 'Active fee should pass.');
foreach ([[11, 999], [12], [13], [11, 11], ['not-an-id']] as $ids) {
    try {
        $selectedFees->invoke($billingService, $ids);
        throw new RuntimeException('Invalid fee selection was accepted: ' . json_encode($ids));
    } catch (Exception $e) {
        expect(!str_starts_with($e->getMessage(), 'Invalid fee selection was accepted'), $e->getMessage());
    }
}

$validator = new PaymentValidationService($pdo);
$tuition = $validator->validatePaymentRequest(1, 7, 500, 'qrph', 'SPECIFIC_ITEM', 70);
expect($tuition['valid'] === false && str_contains($tuition['error'], 'Tuition'), 'Direct Tuition payment should be rejected.');
$wrongStudent = $validator->validatePaymentRequest(2, 7, 100, 'qrph', 'SPECIFIC_ITEM', 71);
expect($wrongStudent['valid'] === false && str_contains($wrongStudent['error'], 'authorized'), 'Other student billing should be rejected.');

$pdo->exec("UPDATE fees SET fee_name = 'Library Renamed' WHERE fee_id = 11");
$storedLabel = $pdo->query('SELECT bi.fee_name FROM billing_items bi WHERE bi.billing_item_id = 71')->fetchColumn();
expect($storedLabel === 'Library Original', 'Historical billing-item name must not follow fee-master renames.');

echo "PASS: fee ID validation, direct Tuition rejection, billing ownership, historical label snapshot.\n";
