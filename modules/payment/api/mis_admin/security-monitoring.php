<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once ROOT_PATH . '/modules/payment/includes/PaymentSecurityMonitoringService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function paymentSecurityRespond(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (!isAuthenticated()) {
    paymentSecurityRespond(['ok' => false, 'error' => 'AUTHENTICATION_REQUIRED',
        'message' => 'Authentication is required.'], 401);
}
if (!paymentRoleAllowsPermission(getCurrentUserRoleKey(), 'payment.security.view')
    || !userCanAccessModule('payment.security.view')) {
    paymentSecurityRespond(['ok' => false, 'error' => 'NOT_AUTHORIZED',
        'message' => 'You are not authorized to view Payment security monitoring.'], 403);
}
requireAuth();
requirePaymentPermission('payment.security.view');

try {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'GET') {
        header('Allow: GET');
        paymentSecurityRespond(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED', 'message' => 'Use GET.'], 405);
    }
    $pdo = db();
    if (!$pdo) throw new RuntimeException('Core database unavailable.');
    $actor = $pdo->prepare('SELECT role_key, status FROM users WHERE id = ? LIMIT 1');
    $actor->execute([getCurrentUserId()]);
    $actorRow = $actor->fetch(PDO::FETCH_ASSOC);
    if (!$actorRow || $actorRow['status'] !== 'active' || $actorRow['role_key'] !== 'mis_admin'
        || $actorRow['role_key'] !== getCurrentUserRoleKey()) {
        paymentSecurityRespond(['ok' => false, 'error' => 'ACTOR_SESSION_STALE',
            'message' => 'Your account authority changed. Sign in again.'], 403);
    }
    $data = (new PaymentSecurityMonitoringService($pdo))->load($_GET);
    paymentSecurityRespond(['ok' => true, 'data' => $data]);
} catch (InvalidArgumentException $e) {
    paymentSecurityRespond(['ok' => false, 'error' => 'INVALID_FILTER', 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('Payment security monitoring failed: ' . get_class($e));
    paymentSecurityRespond(['ok' => false, 'error' => 'SECURITY_MONITORING_UNAVAILABLE',
        'message' => 'Security monitoring is temporarily unavailable.'], 500);
}
