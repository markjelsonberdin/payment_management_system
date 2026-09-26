<?php

declare(strict_types=1);

require_once __DIR__ . '/../modules/payment/includes/FeeSetupService.php';

$dsn = (string) getenv('FEE_SETUP_TEST_DSN');
if ($dsn === '' || !preg_match('/dbname=([^;]+)/', $dsn, $match) || !str_ends_with($match[1], '_test')) {
    fwrite(STDERR, "Refusing to run: FEE_SETUP_TEST_DSN must target a database whose name ends in _test.\n");
    exit(2);
}

$pdo = new PDO(
    $dsn,
    (string) (getenv('FEE_SETUP_TEST_USER') ?: 'root'),
    (string) (getenv('FEE_SETUP_TEST_PASS') ?: ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$checks = 0;
$auditEvents = [];
$service = new FeeSetupService($pdo, static function (string $action, string $detail) use (&$auditEvents): void {
    $auditEvents[] = [$action, $detail];
});

function check(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException("Assertion failed: $message");
    }
}

function expectFeeError(string $code, callable $operation): void
{
    try {
        $operation();
    } catch (FeeSetupException $e) {
        check($e->errorCode === $code, "Expected $code, received {$e->errorCode}");
        return;
    }
    throw new RuntimeException("Expected FeeSetupException $code");
}

function scopeAll(): array
{
    return [[
        'course' => null,
        'year_level' => null,
        'applies_to_all_courses' => true,
        'applies_to_all_year_levels' => true,
    ]];
}

function versionInput(string $year = '2098-2099', string $semester = '1st', float $amount = 1200.00): array
{
    return [
        'academic_year' => $year,
        'semester' => $semester,
        'amount' => $amount,
        'behavior' => 'Standard',
        'is_required' => true,
        'description' => 'Isolated backend regression record.',
        'applicability' => scopeAll(),
    ];
}

$actorId = 990001;
$taxonomy = $service->taxonomy();
check(count($taxonomy) === 2, 'taxonomy contains exactly two groups');
check(array_sum(array_map(static fn(array $group): int => count($group['types']), $taxonomy)) === 8, 'taxonomy contains exactly eight types');

$typeIds = [];
foreach ($taxonomy as $group) {
    foreach ($group['types'] as $type) {
        $typeIds[$type['type_code']] = $type['fee_type_id'];
    }
}

$legacyCountBefore = count($service->legacyFees());
$catalogBefore = count($service->catalog());

$identity = $service->createIdentity([
    'fee_code' => ' test-backend-001 ',
    'fee_name' => 'Backend Test Fee',
    'fee_type_id' => $typeIds['MISCELLANEOUS'],
    'identity_status' => 'Active',
    'description' => 'Backend-only isolated test.',
], $actorId);
$feeId = $identity['fee_id'];
check($identity['fee_code'] === 'TEST-BACKEND-001', 'fee code is canonical uppercase');
check($identity['identity_status'] === 'Active', 'new identity starts Active');

$stored = $pdo->query("SELECT category_id, default_amount, is_required, status FROM fees WHERE fee_id=$feeId")->fetch();
check($stored['category_id'] === null, 'new identity has no legacy category');
check((float) $stored['default_amount'] === 0.0, 'new identity has zero legacy default amount');
check((int) $stored['is_required'] === 0, 'new identity has false legacy required flag');
check($stored['status'] === 'Inactive', 'new identity is isolated from legacy billing');
check(count($service->catalog()) === $catalogBefore + 1, 'managed catalog includes the new identity');
check(count($service->legacyFees()) === $legacyCountBefore, 'managed identity is excluded from legacy list');

expectFeeError('FEE_CODE_EXISTS', static fn() => $service->createIdentity([
    'fee_code' => 'TEST-BACKEND-001',
    'fee_name' => 'Duplicate Test Fee',
    'fee_type_id' => $typeIds['TUITION'],
    'identity_status' => 'Active',
], $actorId));

expectFeeError('FEE_CODE_IMMUTABLE', static fn() => $service->updateIdentity($feeId, [
    'fee_code' => 'TEST-BACKEND-CHANGED',
], $actorId));

$updated = $service->updateIdentity($feeId, [
    'fee_name' => 'Backend Test Fee Corrected',
    'fee_type_id' => $typeIds['SUPPLEMENTARY'],
], $actorId);
check($updated['fee_name'] === 'Backend Test Fee Corrected', 'display name correction succeeds');
check($updated['fee_type_id'] === $typeIds['SUPPLEMENTARY'], 'type correction succeeds before versions exist');

$versionOne = $service->createDraftVersion($feeId, versionInput(), $actorId);
check($versionOne['version_no'] === 1, 'first version number is one');
check($versionOne['effective_status'] === 'Draft', 'new version starts Draft');
check((int) $versionOne['created_by'] === $actorId && (int) $versionOne['updated_by'] === $actorId, 'actor is stored on version creation');
check(count($versionOne['applicability']) === 1, 'draft applicability is created atomically');

expectFeeError('FEE_TYPE_LOCKED', static fn() => $service->updateIdentity($feeId, [
    'fee_type_id' => $typeIds['TUITION'],
], $actorId));

$editedDraft = $service->updateDraftVersion($versionOne['fee_version_id'], versionInput('2098-2099', '1st', 1350.50), $actorId);
check($editedDraft['amount'] === 1350.5, 'Draft financial fields are editable');

expectFeeError('DUPLICATE_APPLICABILITY', static fn() => $service->replaceDraftApplicability(
    $versionOne['fee_version_id'],
    array_merge(scopeAll(), scopeAll()),
    $actorId
));

$specificScopes = [[
    'course' => 'BSIT',
    'year_level' => '2nd Year',
    'applies_to_all_courses' => false,
    'applies_to_all_year_levels' => false,
]];
$scoped = $service->replaceDraftApplicability($versionOne['fee_version_id'], $specificScopes, $actorId);
check($scoped['applicability'][0]['course'] === 'BSIT', 'specific applicability is persisted');

$active = $service->activateVersion($versionOne['fee_version_id'], $actorId);
check($active['effective_status'] === 'Active' && $active['activated_at'] !== null, 'Draft transitions to Active');
expectFeeError('VERSION_NOT_DRAFT', static fn() => $service->updateDraftVersion(
    $versionOne['fee_version_id'], versionInput('2098-2099', '1st', 1400), $actorId
));

$versionTwo = $service->createDraftVersion($feeId, versionInput('2098-2099', '1st', 1500), $actorId);
check($versionTwo['version_no'] === 2, 'sequential numbering uses the next version number');
expectFeeError('ACTIVE_VERSION_EXISTS', static fn() => $service->activateVersion($versionTwo['fee_version_id'], $actorId));

$archivedDraft = $service->archiveVersion($versionTwo['fee_version_id'], $actorId);
check($archivedDraft['effective_status'] === 'Archived', 'Draft transitions to Archived');
expectFeeError('VERSION_IMMUTABLE', static fn() => $service->archiveVersion($versionTwo['fee_version_id'], $actorId));

expectFeeError('IDENTITY_HAS_OPEN_VERSIONS', static fn() => $service->archiveIdentity($feeId, $actorId));
$archivedActive = $service->archiveVersion($versionOne['fee_version_id'], $actorId);
check($archivedActive['effective_status'] === 'Archived', 'Active transitions to Archived');
$archivedIdentity = $service->archiveIdentity($feeId, $actorId);
check($archivedIdentity['identity_status'] === 'Archived', 'identity archives after all versions archive');
expectFeeError('IDENTITY_ARCHIVED', static fn() => $service->createDraftVersion($feeId, versionInput('2099-2100'), $actorId));

$legacy = $service->legacyFees()[0] ?? null;
check(is_array($legacy), 'an unresolved legacy fee exists for classification tests');
$legacyId = (int) $legacy['fee_id'];
$legacyBefore = $pdo->query("SELECT category_id, default_amount, is_required, status FROM fees WHERE fee_id=$legacyId")->fetch();
$legacyVersionCountBefore = (int) $pdo->query("SELECT COUNT(*) FROM fee_versions WHERE fee_id=$legacyId")->fetchColumn();
$classification = [
    'fee_code' => 'LEGACY-BACKEND-TEST',
    'fee_type_id' => $typeIds['LABORATORY'],
    'identity_status' => 'Active',
    'version' => versionInput('2099-2100', '2nd', 777.77),
    'applicability' => scopeAll(),
];
$classification['version']['applicability'] = null;
$preview = $service->previewLegacyClassification($legacyId, $classification);
check($preview['proposed']['identity_status'] === 'Active', 'classification preview requires Active identity');
check((int) $pdo->query("SELECT COUNT(*) FROM fee_versions WHERE fee_id=$legacyId")->fetchColumn() === $legacyVersionCountBefore, 'classification preview writes nothing');

$classified = $service->commitLegacyClassification($legacyId, $classification, $actorId);
check($classified['identity_status'] === 'Active', 'classification creates an Active managed identity');
check(count($classified['versions']) === 1 && $classified['versions'][0]['effective_status'] === 'Draft', 'classification creates one initial Draft');
$legacyAfter = $pdo->query("SELECT category_id, default_amount, is_required, status FROM fees WHERE fee_id=$legacyId")->fetch();
check($legacyAfter === $legacyBefore, 'classification preserves all legacy compatibility fields');
expectFeeError('LEGACY_FEE_ALREADY_CLASSIFIED', static fn() => $service->commitLegacyClassification(
    $legacyId, $classification, $actorId
));

$otherLegacy = $service->legacyFees()[0] ?? null;
check(is_array($otherLegacy), 'another unresolved legacy fee exists for rollback test');
$otherLegacyId = (int) $otherLegacy['fee_id'];
$beforeConflict = $pdo->query("SELECT fee_code,fee_type_id,identity_status FROM fees WHERE fee_id=$otherLegacyId")->fetch();
expectFeeError('FEE_CODE_EXISTS', static fn() => $service->commitLegacyClassification(
    $otherLegacyId, $classification, $actorId
));
$afterConflict = $pdo->query("SELECT fee_code,fee_type_id,identity_status FROM fees WHERE fee_id=$otherLegacyId")->fetch();
check($afterConflict === $beforeConflict, 'failed classification rolls back identity changes');
check((int) $pdo->query("SELECT COUNT(*) FROM fee_versions WHERE fee_id=$otherLegacyId")->fetchColumn() === 0, 'failed classification creates no version');

$failingAuditService = new FeeSetupService($pdo, static function (): void {
    throw new RuntimeException('Simulated Core audit outage.');
});
$auditFailureIdentity = $failingAuditService->createIdentity([
    'fee_code' => 'TEST-AUDIT-FAILURE',
    'fee_name' => 'Audit Failure Test',
    'fee_type_id' => $typeIds['MISCELLANEOUS'],
    'identity_status' => 'Active',
], $actorId);
check($auditFailureIdentity['fee_id'] > 0, 'audit failure does not roll back committed Payment data');
check(count($auditEvents) >= 8, 'successful mutations emitted audit events');

$apiSource = (string) file_get_contents(__DIR__ . '/../modules/payment/api/accounting/fee-setup.php');
check(str_contains($apiSource, 'requireAuth()'), 'API requires authentication');
check(str_contains($apiSource, "requirePaymentPermission('payment.fee_setup')"), 'API requires Accounting Fee Setup permission');
check(str_contains($apiSource, 'verifyCsrfToken'), 'API validates CSRF on POST');

echo "Fee Setup backend regression passed: $checks checks.\n";
