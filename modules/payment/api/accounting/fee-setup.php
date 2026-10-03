<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';

requireAuth();
requirePaymentPermission('fee.manage');

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../database/db_connect.php';
require_once __DIR__ . '/../../includes/FeeSetupService.php';

/** @return never */
function feeSetupRespond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** @return array<string,mixed> */
function feeSetupInput(): array
{
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($contentType, 'application/json')) {
        $raw = (string) file_get_contents('php://input');
        if ($raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new FeeSetupException('VALIDATION_FAILED', 'Request body must contain valid JSON.');
        }
        if (!is_array($decoded)) {
            throw new FeeSetupException('VALIDATION_FAILED', 'Request body must be a JSON object.');
        }
        return $decoded;
    }
    return $_POST;
}

$auditLogger = static function (string $action, string $detail): void {
    logActivity($action, $detail, 'payment');
};

$service = new FeeSetupService($pdo, $auditLogger);
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

try {
    if ($method === 'GET') {
        $action = (string) ($_GET['action'] ?? 'taxonomy');
        $data = match ($action) {
            'taxonomy' => $service->taxonomy(),
            'catalog' => $service->catalog(),
            'fee' => $service->fee((int) ($_GET['fee_id'] ?? 0)),
            'legacy_fees' => $service->legacyFees(),
            'archives' => $service->archives(),
            default => throw new FeeSetupException('UNKNOWN_ACTION', 'Unknown Fee Setup action.', 404),
        };
        feeSetupRespond(['ok' => true, 'data' => $data, 'csrf_token' => generateCsrfToken()]);
    }

    if ($method !== 'POST') {
        header('Allow: GET, POST');
        throw new FeeSetupException('METHOD_NOT_ALLOWED', 'Use GET or POST for this endpoint.', 405);
    }

    $input = feeSetupInput();
    $csrfToken = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? ''));
    if (!verifyCsrfToken($csrfToken)) {
        throw new FeeSetupException('CSRF_INVALID', 'Your security token is invalid or expired. Refresh and try again.', 403);
    }

    $action = (string) ($input['action'] ?? '');
    $actorId = (int) (getCurrentUserId() ?? 0);
    $payload = isset($input['data']) && is_array($input['data']) ? $input['data'] : $input;

    $data = match ($action) {
        'create_identity' => $service->createIdentity($payload, $actorId),
        'update_identity' => $service->updateIdentity((int) ($input['fee_id'] ?? 0), $payload, $actorId),
        'archive_identity' => $service->archiveIdentity((int) ($input['fee_id'] ?? 0), $actorId),
        'create_draft_version' => $service->createDraftVersion((int) ($input['fee_id'] ?? 0), $payload, $actorId),
        'update_draft_version' => $service->updateDraftVersion((int) ($input['fee_version_id'] ?? 0), $payload, $actorId),
        'replace_draft_applicability' => $service->replaceDraftApplicability(
            (int) ($input['fee_version_id'] ?? 0),
            isset($payload['applicability']) && is_array($payload['applicability']) ? $payload['applicability'] : [],
            $actorId
        ),
        'activate_version' => $service->activateVersion((int) ($input['fee_version_id'] ?? 0), $actorId),
        'archive_version' => $service->archiveVersion((int) ($input['fee_version_id'] ?? 0), $actorId),
        'preview_legacy_classification' => $service->previewLegacyClassification(
            (int) ($input['fee_id'] ?? 0),
            $payload
        ),
        'commit_legacy_classification' => $service->commitLegacyClassification(
            (int) ($input['fee_id'] ?? 0),
            $payload,
            $actorId
        ),
        default => throw new FeeSetupException('UNKNOWN_ACTION', 'Unknown Fee Setup action.', 404),
    };

    feeSetupRespond(['ok' => true, 'data' => $data]);
} catch (FeeSetupException $e) {
    feeSetupRespond([
        'ok' => false,
        'error' => $e->errorCode,
        'message' => $e->getMessage(),
    ], $e->httpStatus);
} catch (Throwable $e) {
    error_log('Fee Setup API failure: ' . $e->getMessage());
    feeSetupRespond([
        'ok' => false,
        'error' => 'FEE_SETUP_UNAVAILABLE',
        'message' => 'Fee Setup is temporarily unavailable.',
    ], 500);
}
