<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../config/config.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once ROOT_PATH . '/includes/security.php';
require_once ROOT_PATH . '/includes/security-workflow.php';

header('Content-Type: application/json; charset=utf-8');
requireAuth();
requirePaymentPermission('payment_users.view');

// This endpoint is intentionally MIS-only.  Its name is retained temporarily
// so deployed clients do not receive an unsafe fallback while routes migrate.
const ACCOUNTING_MANAGED_ROLE_KEYS = ['accounting_admin', 'accounting_officer', 'cashier'];

function accountingUsersRespond(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

try {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
        accountingUsersRespond(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED', 'message' => 'Use POST.'], 405);
    }
    $raw = (string) file_get_contents('php://input');
    $input = $raw !== '' ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : $_POST;
    if (!is_array($input)) {
        throw new InvalidArgumentException('Request must be an object.');
    }
    $csrf = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $input['csrf_token'] ?? '');
    if (!verifyCsrfToken($csrf)) {
        accountingUsersRespond(['ok' => false, 'error' => 'CSRF_INVALID', 'message' => 'Your security token is invalid or expired.'], 403);
    }
    $pdo = db();
    if (!$pdo) {
        throw new RuntimeException('Database unavailable.');
    }
    $action = (string) ($input['action'] ?? 'list');
    if ($action === 'list') {
        $stmt = $pdo->query("SELECT id, full_name, username, email, role_key, status, must_change_password, last_seen_at, created_at FROM users WHERE role_key IN ('accounting_admin', 'accounting_officer', 'cashier') ORDER BY role_key, full_name, id");
        accountingUsersRespond(['ok' => true, 'data' => $stmt->fetchAll() ?: [], 'csrf_token' => generateCsrfToken()]);
    }

    $id = (int) ($input['user_id'] ?? 0);
    if ($action !== 'create') {
        $scope = $pdo->prepare("SELECT id, username, role_key, status FROM users WHERE id = ? AND role_key IN ('accounting_admin', 'accounting_officer', 'cashier') LIMIT 1");
        $scope->execute([$id]);
        $target = $scope->fetch();
        if (!$target) {
            accountingUsersRespond(['ok' => false, 'error' => 'ACCOUNTING_USER_SCOPE_VIOLATION', 'message' => 'The selected account is outside the Accounting/Cashier scope.'], 403);
        }
    }

    if ($action === 'create' || $action === 'edit') {
        requirePaymentPermission($action === 'create' ? 'payment_users.create' : 'payment_users.update');
        $fullName = trim((string) ($input['full_name'] ?? ''));
        $username = strtolower(trim((string) ($input['username'] ?? '')));
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $roleKey = (string) ($input['role_key'] ?? ($target['role_key'] ?? 'accounting_officer'));
        if (!in_array($roleKey, ACCOUNTING_MANAGED_ROLE_KEYS, true)) {
            accountingUsersRespond(['ok' => false, 'error' => 'ACCOUNTING_USER_ROLE_FORBIDDEN', 'message' => 'Only Accounting Officer or Cashier can be managed here.'], 403);
        }
        if ($fullName === '' || $username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Full name, username, and a valid email are required.');
        }
        if ($action === 'create') {
            $password = (string) ($input['password'] ?? '');
            if ($password !== (string) ($input['password_confirm'] ?? '')) throw new InvalidArgumentException('Password confirmation does not match.');
            $validation = smsValidatePasswordForAccount($password, $username, $email);
            if (!$validation['ok']) throw new InvalidArgumentException($validation['message']);
            $stmt = $pdo->prepare("INSERT INTO users (username,email,password_hash,full_name,role_key,status,must_change_password,password_changed_at,created_at,updated_at) VALUES (?,?,?,?,?,'active',1,NOW(),NOW(),NOW())");
            $stmt->execute([$username, $email, password_hash($password, PASSWORD_BCRYPT), $fullName, $roleKey]);
            $id = (int) $pdo->lastInsertId();
            logActivity('accounting_user_create', "Created {$roleKey} #{$id} ({$username})", 'payment');
        } else {
            $stmt = $pdo->prepare("UPDATE users SET full_name=?, username=?, email=?, role_key=?, updated_at=NOW() WHERE id=? AND role_key IN ('accounting_admin', 'accounting_officer', 'cashier')");
            $stmt->execute([$fullName, $username, $email, $roleKey, $id]);
            if ($stmt->rowCount() < 1) {
                $exists = $pdo->prepare("SELECT id FROM users WHERE id=? AND role_key IN ('accounting_admin', 'accounting_officer', 'cashier')");
                $exists->execute([$id]);
                if (!$exists->fetch()) throw new RuntimeException('Accounting Officer update failed.');
            }
            logActivity('accounting_user_edit', "Updated {$roleKey} #{$id} ({$username})", 'payment');
        }
    } elseif ($action === 'set_status') {
        requirePaymentPermission('payment_users.activate');
        $status = (string) ($input['status'] ?? '');
        if (!in_array($status, ['active', 'inactive'], true)) throw new InvalidArgumentException('Status must be active or inactive.');
        $stmt = $pdo->prepare("UPDATE users SET status=?, failed_login_attempts=0, locked_until=NULL, updated_at=NOW() WHERE id=? AND role_key IN ('accounting_admin', 'accounting_officer', 'cashier')");
        $stmt->execute([$status, $id]);
        logActivity('accounting_user_status', "Changed Accounting/Cashier user #{$id} status to {$status}", 'payment');
    } elseif ($action === 'reset_password') {
        requirePaymentPermission('payment_users.reset_password');
        $password = (string) ($input['password'] ?? '');
        if ($password !== (string) ($input['password_confirm'] ?? '')) throw new InvalidArgumentException('Password confirmation does not match.');
        $identity = $pdo->prepare("SELECT username,email FROM users WHERE id=? AND role_key IN ('accounting_admin', 'accounting_officer', 'cashier') LIMIT 1");
        $identity->execute([$id]); $identityRow = $identity->fetch();
        $validation = smsValidatePasswordForAccount($password, (string)($identityRow['username'] ?? ''), (string)($identityRow['email'] ?? ''));
        if (!$validation['ok']) throw new InvalidArgumentException($validation['message']);
        $stmt = $pdo->prepare("UPDATE users SET password_hash=?, must_change_password=1, password_changed_at=NOW(), failed_login_attempts=0, locked_until=NULL, updated_at=NOW() WHERE id=? AND role_key IN ('accounting_admin', 'accounting_officer', 'cashier')");
        $stmt->execute([password_hash($password, PASSWORD_BCRYPT), $id]);
        logActivity('accounting_user_password_reset', "Reset password for Accounting/Cashier user #{$id}; forced change enabled", 'payment');
    } else {
        accountingUsersRespond(['ok' => false, 'error' => 'UNKNOWN_ACTION', 'message' => 'Unknown accounting-user action.'], 404);
    }
    accountingUsersRespond(['ok' => true, 'data' => ['user_id' => $id], 'csrf_token' => generateCsrfToken()]);
} catch (PDOException $e) {
    $message = str_contains($e->getMessage(), 'Duplicate') ? 'Username or email already exists.' : 'Unable to save the Accounting/Cashier account.';
    accountingUsersRespond(['ok' => false, 'error' => 'ACCOUNTING_USER_SAVE_FAILED', 'message' => $message], 422);
} catch (InvalidArgumentException $e) {
    accountingUsersRespond(['ok' => false, 'error' => 'VALIDATION_FAILED', 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('Accounting users API: ' . $e->getMessage());
    accountingUsersRespond(['ok' => false, 'error' => 'ACCOUNTING_USER_SYSTEM_ERROR', 'message' => 'Unable to complete the accounting-user request.'], 500);
}
