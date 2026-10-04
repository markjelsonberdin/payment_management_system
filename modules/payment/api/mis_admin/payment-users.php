<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once ROOT_PATH . '/includes/security.php';
require_once ROOT_PATH . '/includes/module-controls.php';
require_once ROOT_PATH . '/modules/payment/includes/PaymentPersonnelService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
requireAuth();
requirePaymentPermission('payment_users.view');

function paymentUsersRespond(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

try {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
        header('Allow: POST');
        paymentUsersRespond(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED', 'message' => 'Use POST.'], 405);
    }
    $raw = (string) file_get_contents('php://input');
    $input = $raw !== '' ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : $_POST;
    if (!is_array($input)) throw new InvalidArgumentException('Request must be an object.');
    $csrf = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $input['csrf_token'] ?? '');
    if (!verifyCsrfToken($csrf)) {
        paymentUsersRespond(['ok' => false, 'error' => 'CSRF_INVALID', 'message' => 'Your security token is invalid or expired.'], 403);
    }
    $pdo = db();
    if (!$pdo) throw new RuntimeException('Database unavailable.');
    $actorQuery = $pdo->prepare('SELECT id, full_name, role_key, status FROM users WHERE id=? LIMIT 1');
    $actorQuery->execute([getCurrentUserId()]);
    $actorRow = $actorQuery->fetch(PDO::FETCH_ASSOC);
    if (!$actorRow || $actorRow['status'] !== 'active' || $actorRow['role_key'] !== 'mis_admin'
        || $actorRow['role_key'] !== getCurrentUserRoleKey()) {
        paymentUsersRespond(['ok' => false, 'error' => 'ACTOR_SESSION_STALE',
            'message' => 'Your account authority changed. Sign in again.'], 403);
    }
    $service = new PaymentPersonnelService(
        $pdo,
        new StructuredActivityAuditWriter($pdo),
        static fn(int $userId): int => smsBumpUserKickEpoch($pdo, $userId)
    );
    $actor = ['id' => (int) $actorRow['id'], 'name' => (string) $actorRow['full_name'],
        'role' => (string) $actorRow['role_key']];
    $action = (string) ($input['action'] ?? 'list');
    switch ($action) {
        case 'list': $data = $service->list(); break;
        case 'create':
            requirePaymentPermission('payment_users.create');
            $data = $service->create($input, $actor); break;
        case 'update_profile':
            requirePaymentPermission('payment_users.update');
            $data = $service->updateProfile($input, $actor); break;
        case 'assign_role':
            requirePaymentPermission('payment_users.role.assign');
            $data = $service->assignRole($input, $actor); break;
        case 'activate':
            requirePaymentPermission('payment_users.activate');
            $data = $service->setAdministrativeStatus($input, $actor, true); break;
        case 'deactivate':
            requirePaymentPermission('payment_users.deactivate');
            $data = $service->setAdministrativeStatus($input, $actor, false); break;
        case 'unlock':
            requirePaymentPermission('payment_users.unlock');
            $data = $service->unlock($input, $actor); break;
        case 'reset_password':
            requirePaymentPermission('payment_users.password.reset');
            $data = $service->resetPassword($input, $actor); break;
        default:
            paymentUsersRespond(['ok' => false, 'error' => 'UNKNOWN_ACTION', 'message' => 'Unknown Payment personnel action.'], 404);
    }
    paymentUsersRespond(['ok' => true, 'data' => $data, 'csrf_token' => generateCsrfToken()]);
} catch (DomainException $e) {
    paymentUsersRespond(['ok' => false, 'error' => $e->getMessage(),
        'message' => 'The selected account or role is outside the permitted Payment personnel scope.'], 403);
} catch (JsonException $e) {
    paymentUsersRespond(['ok' => false, 'error' => 'INVALID_JSON', 'message' => 'Request JSON is invalid.'], 400);
} catch (InvalidArgumentException $e) {
    paymentUsersRespond(['ok' => false, 'error' => 'VALIDATION_FAILED', 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('Payment personnel operation failed: ' . get_class($e));
    paymentUsersRespond(['ok' => false, 'error' => 'PAYMENT_USER_SYSTEM_ERROR',
        'message' => 'Unable to complete the Payment personnel request.'], 500);
}
