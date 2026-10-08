<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/modules/payment/includes/PaymentAllocationService.php';

$count = 0;
$check = static function (bool $condition, string $label) use (&$count): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    $count++;
};
$rejects = static function (callable $operation, string $fragment, string $label) use ($check): void {
    try { $operation(); $check(false, $label); }
    catch (Throwable $exception) { $check(str_contains($exception->getMessage(), $fragment), $label); }
};
$database = static function (): PDO {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE payments (
        payment_id INTEGER PRIMARY KEY, student_id INTEGER NOT NULL, billing_id INTEGER NOT NULL,
        transaction_type TEXT NOT NULL, payment_method TEXT NOT NULL, payment_channel TEXT NOT NULL,
        gateway_environment TEXT, payment_intent_id TEXT, payment_status TEXT NOT NULL,
        verified_by INTEGER, verified_at TEXT, amount TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE billing (
        billing_id INTEGER PRIMARY KEY, student_id INTEGER NOT NULL, total_amount TEXT NOT NULL,
        discount_amount TEXT NOT NULL DEFAULT '0.00', remaining_balance TEXT NOT NULL,
        billing_status TEXT NOT NULL, updated_at TEXT
    )");
    $pdo->exec("CREATE TABLE fee_categories (category_id INTEGER PRIMARY KEY, priority_order INTEGER NOT NULL)");
    $pdo->exec("CREATE TABLE fees (fee_id INTEGER PRIMARY KEY, category_id INTEGER)");
    $pdo->exec("CREATE TABLE billing_items (
        billing_item_id INTEGER PRIMARY KEY, billing_id INTEGER NOT NULL, fee_id INTEGER NOT NULL,
        amount TEXT NOT NULL, paid_amount TEXT NOT NULL, remaining_amount TEXT NOT NULL,
        status TEXT NOT NULL, source_context TEXT
    )");
    $pdo->exec("CREATE TABLE payment_allocations (
        allocation_id INTEGER PRIMARY KEY AUTOINCREMENT, payment_id INTEGER NOT NULL,
        billing_item_id INTEGER NOT NULL, allocated_amount TEXT NOT NULL,
        UNIQUE(payment_id,billing_item_id)
    )");
    $pdo->exec("CREATE TRIGGER allocation_apply AFTER INSERT ON payment_allocations BEGIN
        UPDATE billing_items SET paid_amount = printf('%.2f', CAST(paid_amount AS REAL) + CAST(NEW.allocated_amount AS REAL)),
          remaining_amount = printf('%.2f', CAST(remaining_amount AS REAL) - CAST(NEW.allocated_amount AS REAL)),
          status = CASE WHEN CAST(remaining_amount AS REAL) - CAST(NEW.allocated_amount AS REAL) <= 0 THEN 'Paid' ELSE 'Partial' END
        WHERE billing_item_id = NEW.billing_item_id;
    END");
    $pdo->exec("INSERT INTO billing VALUES (10,1,'150.00','0.00','150.00','Unpaid',NULL),(20,2,'100.00','0.00','100.00','Unpaid',NULL)");
    $pdo->exec("INSERT INTO fee_categories VALUES (1,1),(2,2),(3,3)");
    $pdo->exec("INSERT INTO fees VALUES (1,2),(2,3),(3,1)");
    $pdo->exec("INSERT INTO billing_items VALUES
        (101,10,1,'100.00','0.00','100.00','Unpaid','Enrollment'),
        (102,10,2,'50.00','0.00','50.00','Unpaid','Enrollment'),
        (201,20,1,'100.00','0.00','100.00','Unpaid','Enrollment')");
    return $pdo;
};
$insertPayment = static function (
    PDO $pdo, int $id, string $type, string $method, string $channel, ?string $environment,
    string $status, string $amount, int $student = 1, int $billing = 10, ?int $verifiedBy = 9,
    ?string $verifiedAt = '2026-10-08 10:00:00', ?string $intent = null
): void {
    $stmt = $pdo->prepare('INSERT INTO payments VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([$id,$student,$billing,$type,$method,$channel,$environment,$intent,$status,$verifiedBy,$verifiedAt,$amount]);
};

$pdo = $database();
$insertPayment($pdo,1,'Walk-in','Walk-in','Cash',null,'Verified','120.00');
$result = (new PaymentAllocationService($pdo))->allocatePayment(1,1,10,120.00,'GENERAL_PRIORITY');
$check($result['allocated_amount'] === '120.00' && !$result['idempotent_replay'], 'Valid Cashier payment allocates exact value.');
$check((string)$pdo->query('SELECT COALESCE(SUM(allocated_amount),0) FROM payment_allocations WHERE payment_id=1')->fetchColumn() === '120', 'Allocation total equals payment amount.');
$replay = (new PaymentAllocationService($pdo))->allocatePayment(1,1,10,120.00,'GENERAL_PRIORITY');
$check($replay['idempotent_replay'] === true, 'Fully allocated repeat is idempotent.');

$pdo = $database();
$insertPayment($pdo,2,'Online','Online','QRPh','live','Verified','50.00',1,10,null,'2026-10-08 10:00:00','pi_live');
$check((new PaymentAllocationService($pdo))->allocatePayment(2,1,10,50.00,'SPECIFIC_ITEM',102)['allocated_amount'] === '50.00', 'Valid live QR Ph payment allocates.');

$pdo = $database();
$insertPayment($pdo,3,'Payment Concern','Bank Transfer','Bank',null,'Verified','50.00');
$check((new PaymentAllocationService($pdo))->allocatePayment(3,1,10,50.00)['allocated_amount'] === '50.00', 'Verified Payment Concern allocates.');

$pdo = $database();
$insertPayment($pdo,4,'Walk-in','Walk-in','Cash',null,'Verified','20.00');
$service = new PaymentAllocationService($pdo);
$rejects(fn() => $service->allocatePayment(4,2,10,20.00), 'ownership', 'Wrong student is rejected.');
$rejects(fn() => $service->allocatePayment(4,1,20,20.00), 'ownership', 'Wrong billing association is rejected.');

$pdo = $database();
$insertPayment($pdo,5,'Walk-in','Walk-in','Cash',null,'Pending','20.00',1,10,9,null);
$rejects(fn() => (new PaymentAllocationService($pdo))->allocatePayment(5,1,10,20.00), 'verified', 'Pending payment is rejected.');

$pdo = $database();
$insertPayment($pdo,6,'Online','Online','QRPh','test','Verified','20.00',1,10,null,'2026-10-08 10:00:00','pi_test');
$rejects(fn() => (new PaymentAllocationService($pdo))->allocatePayment(6,1,10,20.00), 'authorized live', 'Test online payment cannot change balances.');

$pdo = $database();
$insertPayment($pdo,7,'Walk-in','Walk-in','Cash',null,'Verified','20.00');
$service = new PaymentAllocationService($pdo);
$rejects(fn() => $service->allocatePayment(7,1,10,0.00), 'positive', 'Zero allocation is rejected.');
$rejects(fn() => $service->allocatePayment(7,1,10,-1.00), 'nonnegative', 'Negative allocation is rejected.');
$rejects(fn() => $service->allocatePayment(7,1,10,20.001), 'two decimal', 'Invalid precision is rejected.');
$rejects(fn() => $service->allocatePayment(7,1,10,19.00), 'equal the verified payment', 'Amount different from payment is rejected.');

$pdo = $database();
$insertPayment($pdo,8,'Walk-in','Walk-in','Cash',null,'Verified','200.00');
$rejects(fn() => (new PaymentAllocationService($pdo))->allocatePayment(8,1,10,200.00), 'exceeds the payable', 'Allocation beyond target balance is rejected.');
$check((int)$pdo->query('SELECT COUNT(*) FROM payment_allocations')->fetchColumn() === 0, 'Failed owned transaction rolls back allocation writes.');

$pdo = $database();
$insertPayment($pdo,9,'Walk-in','Walk-in','Cash',null,'Verified','100.00');
$pdo->exec("INSERT INTO payment_allocations(payment_id,billing_item_id,allocated_amount) VALUES(9,101,'25.00')");
$rejects(fn() => (new PaymentAllocationService($pdo))->allocatePayment(9,1,10,100.00,'GENERAL_PRIORITY'), 'Partially allocated', 'Existing partial allocation requires reconciliation.');

$pdo = $database();
$insertPayment($pdo,10,'Walk-in','Walk-in','Cash',null,'Verified','50.00');
$pdo->beginTransaction();
$result = (new PaymentAllocationService($pdo))->allocatePayment(10,1,10,50.00,'SPECIFIC_ITEM',102);
$check($pdo->inTransaction(), 'Service does not commit caller-owned transaction.');
$pdo->rollBack();
$check((int)$pdo->query('SELECT COUNT(*) FROM payment_allocations WHERE payment_id=10')->fetchColumn() === 0, 'Caller rollback reverses allocation and trigger effects.');

$pdo = $database();
$insertPayment($pdo,11,'Walk-in','Walk-in','Cash',null,'Verified','10.00');
$rejects(fn() => (new PaymentAllocationService($pdo))->allocatePayment(11,1,10,10.00,'SPECIFIC_ITEM',201), 'No eligible', 'Target from another billing is rejected.');

$pdo = $database();
$insertPayment($pdo,12,'Walk-in','Walk-in','Cash',null,'Verified','10.00');
$pdo->exec("CREATE TRIGGER reject_allocation BEFORE INSERT ON payment_allocations BEGIN SELECT RAISE(ABORT, 'simulated trigger failure'); END");
$rejects(fn() => (new PaymentAllocationService($pdo))->allocatePayment(12,1,10,10.00,'SPECIFIC_ITEM',101), 'simulated trigger failure', 'Trigger failure is surfaced.');
$check((int)$pdo->query('SELECT COUNT(*) FROM payment_allocations')->fetchColumn() === 0
    && (string)$pdo->query('SELECT remaining_amount FROM billing_items WHERE billing_item_id=101')->fetchColumn() === '100.00',
    'Trigger failure rolls back allocation and billing-item state.');

$cashier = file_get_contents(dirname(__DIR__) . '/modules/payment/pages/cashier/payment-collection-portal.php');
$webhook = file_get_contents(dirname(__DIR__) . '/modules/payment/api/paymongo/webhook.php');
$concern = file_get_contents(dirname(__DIR__) . '/modules/payment/includes/PaymentConcernService.php');
$check(str_contains($cashier, 'allocationTargetId =') && str_contains($cashier, 'SPECIFIC_ITEM') && str_contains($cashier, 'item_id : $category_id'), 'Cashier passes the correct target identifier.');
$check(strpos($webhook,'beginTransaction()') < strpos($webhook,'allocatePayment(') && strpos($webhook,'allocatePayment(') < strpos($webhook,'insertPending(') && strpos($webhook,'insertPending(') < strpos($webhook,'commit()'), 'PayMongo allocation and audit remain in one caller-owned transaction.');
$check(str_contains($concern,'beginTransaction()') && str_contains($concern,'allocatePayment(') && str_contains($concern,'->commit()'), 'Payment Concern allocation remains in its approval transaction.');

echo "Batch 4J centralized allocation integrity: {$count} assertions passed.\n";
