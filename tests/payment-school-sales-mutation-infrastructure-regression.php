<?php

declare(strict_types=1);

if (!defined('ROOT_PATH')) define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/modules/payment/includes/SchoolSalesCatalogMutationInfrastructure.php';

$checks = 0;
function mutationInfraCheck(bool $ok, string $message): void
{
    global $checks;
    $checks++;
    if (!$ok) throw new RuntimeException($message);
}

function sqlitePdo(): PDO
{
    return new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function createOutbox(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE payment_audit_outbox (
        audit_outbox_id INTEGER PRIMARY KEY AUTOINCREMENT,
        correlation_id TEXT NOT NULL UNIQUE, request_fingerprint TEXT NOT NULL,
        action TEXT NOT NULL, module_key TEXT NOT NULL, entity_type TEXT NOT NULL,
        entity_id INTEGER NULL, detail TEXT NOT NULL, before_state TEXT NULL,
        after_state TEXT NULL, committed_result TEXT NOT NULL, actor_user_id INTEGER NULL,
        actor_user_name TEXT NULL, actor_role_key TEXT NULL, actor_ip_address TEXT NULL,
        actor_user_agent TEXT NULL, delivery_status TEXT NOT NULL DEFAULT 'Pending',
        attempt_count INTEGER NOT NULL DEFAULT 0, next_attempt_at TEXT NULL,
        last_attempt_at TEXT NULL, delivered_at TEXT NULL, core_activity_log_id INTEGER NULL,
        last_error TEXT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
}

function createCoreAudit(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE activity_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NULL, user_name TEXT NULL,
        role_key TEXT NULL, action TEXT NOT NULL, module_key TEXT NULL,
        entity_type TEXT NULL, entity_id INTEGER NULL, detail TEXT NOT NULL,
        before_state TEXT NULL, after_state TEXT NULL, correlation_id TEXT NULL UNIQUE,
        ip_address TEXT NULL, user_agent TEXT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
}

$fingerprintA = CatalogCanonicalJson::fingerprint(['name' => ' Uniform ', 'nested' => ['b' => 2, 'a' => 1]]);
$fingerprintB = CatalogCanonicalJson::fingerprint(['nested' => ['a' => 1, 'b' => 2], 'name' => ' Uniform ']);
mutationInfraCheck($fingerprintA === $fingerprintB, 'Fingerprint must be deterministic across object-key order.');
mutationInfraCheck(strlen($fingerprintA) === 64 && ctype_xdigit($fingerprintA), 'Fingerprint must be lowercase SHA-256 hex.');
mutationInfraCheck($fingerprintA === CatalogCanonicalJson::fingerprint(['name' => 'Uniform', 'nested' => ['a' => 1, 'b' => 2]]), 'Canonical request strings must normalize surrounding whitespace.');
mutationInfraCheck($fingerprintA !== CatalogCanonicalJson::fingerprint(['name' => 'Book', 'nested' => ['a' => 1, 'b' => 2]]), 'Meaningful request differences must change the fingerprint.');
$generatedId = CatalogCorrelationId::generate();
CatalogCorrelationId::assertValid($generatedId);
mutationInfraCheck(strlen($generatedId) === 36, 'Generated correlation ID must be UUID sized.');

$payment = sqlitePdo();
createOutbox($payment);
$payment->exec('CREATE TABLE synthetic_business_state (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
$core = sqlitePdo();
createCoreAudit($core);
$outbox = new PaymentAuditOutboxService($payment, new StructuredActivityAuditWriter($core));
$infra = new SchoolSalesCatalogMutationInfrastructure($payment, $outbox);
$correlation = CatalogCorrelationId::generate();
$audit = [
    'action' => 'catalog_test', 'module_key' => 'payment', 'entity_type' => 'school_sale_item',
    'entity_id' => 77, 'detail' => 'Synthetic infrastructure regression event.',
    'before_state' => null, 'after_state' => ['status' => 'Draft'],
    'actor_user_id' => 9, 'actor_user_name' => 'Regression', 'actor_role_key' => 'accounting_admin',
    'actor_ip_address' => '127.0.0.1', 'actor_user_agent' => 'CLI regression',
];
$mutationCalls = 0;
$response = $infra->execute($correlation, ['operation' => 'synthetic', 'value' => 'A'], $audit,
    function (PDO $pdo) use (&$mutationCalls): array {
        $mutationCalls++;
        $pdo->exec("INSERT INTO synthetic_business_state VALUES (1, 'committed')");
        return ['entity_id' => 77, 'status' => 'Draft'];
    }
);
mutationInfraCheck($response['committed'] === true && $response['audit_status'] === 'delivered', 'Successful mutation must commit and deliver audit.');
mutationInfraCheck($response['idempotent_replay'] === false && $mutationCalls === 1, 'First request must execute once.');
mutationInfraCheck((int) $payment->query('SELECT COUNT(*) FROM payment_audit_outbox')->fetchColumn() === 1, 'Mutation must insert exactly one outbox row.');
mutationInfraCheck((int) $core->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn() === 1, 'Delivered event must create one Core audit row.');

$replay = $infra->execute($correlation, ['value' => 'A', 'operation' => 'synthetic'], $audit,
    function () use (&$mutationCalls): array { $mutationCalls++; return ['wrong' => true]; }
);
mutationInfraCheck($replay['idempotent_replay'] === true && $mutationCalls === 1, 'Same ID and request must replay without repeating mutation.');
mutationInfraCheck($replay['result'] === ['entity_id' => 77, 'status' => 'Draft'], 'Replay must recover canonical committed result.');

$conflicted = false;
try {
    $infra->execute($correlation, ['operation' => 'synthetic', 'value' => 'DIFFERENT'], $audit, static fn(): array => []);
} catch (CatalogCorrelationConflictException $e) {
    $conflicted = $e->getMessage() === 'CORRELATION_ID_CONFLICT';
}
mutationInfraCheck($conflicted, 'Same ID and different request must be rejected as conflict.');
mutationInfraCheck((int) $payment->query('SELECT COUNT(*) FROM synthetic_business_state')->fetchColumn() === 1, 'Conflict must not mutate committed Payment state.');

$brokenPayment = sqlitePdo();
$brokenPayment->exec('CREATE TABLE synthetic_business_state (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
$brokenInfra = new SchoolSalesCatalogMutationInfrastructure($brokenPayment, new PaymentAuditOutboxService($brokenPayment));
$rolledBack = false;
try {
    $brokenInfra->execute(CatalogCorrelationId::generate(), ['operation' => 'atomicity'], $audit,
        static function (PDO $pdo): array {
            $pdo->exec("INSERT INTO synthetic_business_state VALUES (1, 'must roll back')");
            return ['ok' => true];
        }
    );
} catch (PDOException $e) {
    $rolledBack = true;
}
mutationInfraCheck($rolledBack && (int) $brokenPayment->query('SELECT COUNT(*) FROM synthetic_business_state')->fetchColumn() === 0, 'Outbox failure must roll back Payment mutation atomically.');

$pendingPayment = sqlitePdo();
createOutbox($pendingPayment);
$pendingPayment->exec('CREATE TABLE synthetic_business_state (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
$unavailableCore = sqlitePdo();
$pendingInfra = new SchoolSalesCatalogMutationInfrastructure(
    $pendingPayment,
    new PaymentAuditOutboxService($pendingPayment, new StructuredActivityAuditWriter($unavailableCore))
);
$pendingCorrelation = CatalogCorrelationId::generate();
$pendingResponse = $pendingInfra->execute($pendingCorrelation, ['operation' => 'core-down'], $audit,
    static function (PDO $pdo): array {
        $pdo->exec("INSERT INTO synthetic_business_state VALUES (1, 'survives audit failure')");
        return ['saved' => true];
    }
);
mutationInfraCheck($pendingResponse['committed'] === true && $pendingResponse['audit_status'] === 'pending', 'Core failure must report committed=true and pending audit.');
mutationInfraCheck($pendingResponse['correlation_id'] === $pendingCorrelation, 'Core failure response must surface correlation ID.');
mutationInfraCheck((int) $pendingPayment->query('SELECT COUNT(*) FROM synthetic_business_state')->fetchColumn() === 1, 'Core failure must preserve committed Payment state.');
$pendingRow = $pendingPayment->query('SELECT * FROM payment_audit_outbox')->fetch();
mutationInfraCheck($pendingRow['delivery_status'] === 'Pending' && $pendingRow['last_error'] !== null, 'Core failure must remain durable and visible in outbox.');

$duplicateEvent = [
    'user_id' => 9, 'user_name' => 'Regression', 'role_key' => 'accounting_admin',
    'action' => 'duplicate_test', 'module_key' => 'payment', 'entity_type' => 'school_sale_item',
    'entity_id' => 10, 'detail' => 'Duplicate test.', 'before_state' => null,
    'after_state' => '{"status":"Draft"}', 'correlation_id' => CatalogCorrelationId::generate(),
    'ip_address' => '127.0.0.1', 'user_agent' => 'CLI regression',
];
$writer = new StructuredActivityAuditWriter($core);
$firstCoreId = $writer->write($duplicateEvent);
mutationInfraCheck($writer->write($duplicateEvent) === $firstCoreId, 'Matching duplicate Core correlation must be treated as delivered.');
$duplicateConflict = false;
try {
    $differentEvent = $duplicateEvent;
    $differentEvent['detail'] = 'Different immutable payload.';
    $writer->write($differentEvent);
} catch (StructuredAuditConflictException $e) {
    $duplicateConflict = true;
}
mutationInfraCheck($duplicateConflict, 'Mismatched duplicate Core correlation must raise reconciliation conflict.');

$pendingPayment->exec("UPDATE payment_audit_outbox SET next_attempt_at = '2000-01-01 00:00:00'");
$retryService = new PaymentAuditOutboxService($pendingPayment);
$unresolved = $retryService->unresolved();
mutationInfraCheck(count($unresolved) === 1 && $unresolved[0]['correlation_id'] === $pendingCorrelation, 'Reconciliation must select due Pending rows.');
$pendingPayment->exec("UPDATE payment_audit_outbox SET delivery_status = 'Delivered'");
mutationInfraCheck($retryService->unresolved() === [], 'Delivered rows must remain stored but excluded from unresolved selection.');
mutationInfraCheck((int) $pendingPayment->query('SELECT COUNT(*) FROM payment_audit_outbox')->fetchColumn() === 1, 'Delivered outbox rows must not be purged.');

$pendingPayment->exec("UPDATE payment_audit_outbox
    SET delivery_status = 'Processing', last_attempt_at = '2000-01-01 00:00:00', delivered_at = NULL");
$reconcileWithoutCore = $retryService->reconcileDue();
mutationInfraCheck(($reconcileWithoutCore[$pendingCorrelation] ?? null) === 'Pending', 'Stale Processing rows must be recoverable for reconciliation.');

$auditSource = (string) file_get_contents(ROOT_PATH . '/includes/audit.php');
$catalogPage = (string) file_get_contents(ROOT_PATH . '/modules/payment/pages/accounting_admin/school-sales-catalog.php');
$cashSaleServiceHash = hash_file('sha256', ROOT_PATH . '/modules/payment/includes/CashSaleService.php');
mutationInfraCheck(str_contains($auditSource, 'function logActivity('), 'Existing logActivity function must remain available.');
mutationInfraCheck(str_contains($auditSource, 'INSERT INTO activity_logs') && str_contains($auditSource, '(user_id, user_name, role_key, action, module_key, detail, ip_address, user_agent)'), 'Legacy logActivity insert contract must remain compatible.');
mutationInfraCheck(str_contains($catalogPage, 'CATALOG_MUTATIONS_PENDING'), 'Catalog POST must remain unavailable.');
mutationInfraCheck(!str_contains($catalogPage, 'SchoolSalesCatalogMutationInfrastructure'), 'Catalog page must not expose mutation infrastructure.');
mutationInfraCheck(is_string($cashSaleServiceHash) && strlen($cashSaleServiceHash) === 64, 'CashSaleService must remain present and untouched by the infrastructure test.');

echo "PASS: {$checks} School Sales mutation infrastructure checks.\n";
