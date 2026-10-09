<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once ROOT_PATH . '/modules/payment/includes/PayMongoIntegrationSecurity.php';
require_once ROOT_PATH . '/modules/payment/includes/PaymentPermissionManagementService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function permissionUpdateRespond(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (!isAuthenticated()) permissionUpdateRespond(['ok'=>false,'error'=>'AUTHENTICATION_REQUIRED'], 401);
requireAuth();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    permissionUpdateRespond(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'], 405);
}

$rawInput = (string) file_get_contents('php://input');
try {
    PaymentPermissionManagementService::rejectDuplicateDecisionKeysInJson($rawInput);
} catch (InvalidArgumentException $exception) {
    if ($exception->getMessage() === 'DUPLICATE_CANONICAL_PERMISSION') {
        permissionUpdateRespond(['ok'=>false,'error'=>'DUPLICATE_CANONICAL_PERMISSION','message'=>'Each canonical permission may be submitted only once.'], 422);
    }
    permissionUpdateRespond(['ok'=>false,'error'=>'INVALID_JSON'], 400);
}
$input = json_decode($rawInput, true);
if (!is_array($input)) permissionUpdateRespond(['ok'=>false,'error'=>'INVALID_JSON'], 400);
$csrf = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? ''));
if (!verifyCsrfToken($csrf)) permissionUpdateRespond(['ok'=>false,'error'=>'CSRF_INVALID'], 403);

try {
    $core = db();
    if (!$core) throw new RuntimeException('Core unavailable');
    $actor = PayMongoIntegrationSecurity::requireActiveMisActor($core, 'payment.permissions.manage');
    $decisions = $input['decisions'] ?? null;
    if (!is_array($decisions)) throw new InvalidArgumentException('DECISIONS_REQUIRED');
    $normalized = PaymentPermissionManagementService::normalizeDecisions($decisions);
    $result = (new PaymentPermissionManagementService($core, new StructuredActivityAuditWriter($core)))->update(
        (string) ($input['role'] ?? ''),
        $normalized,
        (string) ($input['expected_version'] ?? ''),
        (string) ($input['correlation_id'] ?? ''),
        $actor
    );
    permissionUpdateRespond(['ok'=>true,'data'=>$result]);
} catch (PaymentPermissionConflictException $exception) {
    permissionUpdateRespond(['ok'=>false,'error'=>'PERMISSION_VERSION_CONFLICT','message'=>'Permissions changed in another session. Reload and review again.'], 409);
} catch (PaymentPermissionDuplicateException $exception) {
    permissionUpdateRespond(['ok'=>false,'error'=>'DUPLICATE_PERMISSION_ROWS','message'=>'Duplicate Core permission rows must be repaired before editing.'], 409);
} catch (DomainException $exception) {
    $safe = in_array($exception->getMessage(), ['ACTOR_SESSION_STALE','ROLE_CEILING_EXCEEDED','PERMISSION_ADMIN_LOCKOUT','MIS_ADMIN_REQUIRED','CAPABILITY_UNAVAILABLE'], true)
        ? $exception->getMessage() : 'NOT_AUTHORIZED';
    permissionUpdateRespond(['ok'=>false,'error'=>$safe], 403);
} catch (InvalidArgumentException $exception) {
    $code = in_array($exception->getMessage(), ['DUPLICATE_CANONICAL_PERMISSION','PERMISSION_INVALID','DECISION_INVALID','DECISIONS_REQUIRED'], true)
        ? $exception->getMessage() : 'REQUEST_INVALID';
    $message = match ($code) {
        'DUPLICATE_CANONICAL_PERMISSION' => 'Each canonical permission may be submitted only once.',
        'PERMISSION_INVALID' => 'A permission key is not part of the supported catalog.',
        'DECISION_INVALID' => 'Each permission decision must be true or false.',
        default => 'The permission update request is invalid.',
    };
    permissionUpdateRespond(['ok'=>false,'error'=>$code,'message'=>$message], 422);
} catch (Throwable $exception) {
    error_log('Payment permission update failed: ' . get_class($exception));
    permissionUpdateRespond(['ok'=>false,'error'=>'PERMISSION_UPDATE_FAILED'], 500);
}
