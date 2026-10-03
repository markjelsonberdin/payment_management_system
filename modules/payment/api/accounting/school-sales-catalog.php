<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once ROOT_PATH . '/modules/payment/includes/SchoolSalesCatalogApiController.php';

header('Content-Type: application/json; charset=utf-8');

/** @return never */
function schoolSalesCatalogApiRespond(array $response): void
{
    http_response_code((int) $response['status']);
    echo json_encode($response['body'], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** @return array<string,mixed> */
function schoolSalesCatalogApiInput(): array
{
    $raw = (string) file_get_contents('php://input');
    if ($raw === '') return [];
    $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded) || array_is_list($decoded)) throw new InvalidArgumentException('INVALID_JSON_OBJECT');
    return $decoded;
}

try {
    if (!isAuthenticated()) {
        schoolSalesCatalogApiRespond(['status' => 401, 'body' => ['ok' => false, 'error' => 'AUTHENTICATION_REQUIRED', 'message' => 'Authentication is required.']]);
    }

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $input = $method === 'POST' ? schoolSalesCatalogApiInput() : [];
    $permissions = [];
    foreach (['school_sales.catalog.view', 'school_sales.catalog.manage', 'school_sales.catalog.activate'] as $permission) {
        if (paymentRoleAllowsPermission(getCurrentUserRoleKey(), $permission) && userCanAccessModule($permission)) $permissions[] = $permission;
    }
    $csrf = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? ''));
    $correlationId = (string) ($_SERVER['HTTP_X_CORRELATION_ID'] ?? ($input['correlation_id'] ?? ''));
    $actor = [
        'user_id' => (int) (getCurrentUserId() ?? 0),
        'user_name' => getCurrentUserName(),
        'role_key' => getCurrentUserRoleKey(),
        'permissions' => $permissions,
        'ip_address' => smsClientIp(),
        'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null,
    ];

    $corePdo = db();
    require_once ROOT_PATH . '/modules/payment/database/db_connect.php';
    $coreWriter = $corePdo instanceof PDO ? new StructuredActivityAuditWriter($corePdo) : null;
    $outbox = new PaymentAuditOutboxService($pdo, $coreWriter);
    $service = new SchoolSalesCatalogService(
        $pdo,
        new SchoolSalesCatalogMutationInfrastructure($pdo, $outbox),
        new RegistrarSchoolSalesApplicabilityScopeProvider()
    );
    $controller = new SchoolSalesCatalogApiController($service);
    schoolSalesCatalogApiRespond($controller->handle($method, $_GET, $input, [
        'authenticated' => true,
        'csrf_valid' => $method !== 'POST' || verifyCsrfToken($csrf),
        'correlation_id' => $correlationId,
        'permissions' => $permissions,
        'actor' => $actor,
    ]));
} catch (JsonException|InvalidArgumentException $e) {
    schoolSalesCatalogApiRespond(['status' => 422, 'body' => ['ok' => false, 'error' => 'INVALID_REQUEST', 'message' => 'The request body must be a valid JSON object.']]);
} catch (Throwable $e) {
    error_log('School Sales Catalog API failure: ' . $e->getMessage());
    schoolSalesCatalogApiRespond(['status' => 500, 'body' => ['ok' => false, 'error' => 'CATALOG_API_UNAVAILABLE', 'message' => 'The School Sales catalog is temporarily unavailable.']]);
}
