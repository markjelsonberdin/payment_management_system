<?php
declare(strict_types=1);

require_once ROOT_PATH . '/includes/StructuredActivityAuditWriter.php';

final class PaymentPersonnelService
{
    public const MANAGED_ROLES = ['accounting_admin', 'accounting_officer', 'cashier'];
    private const ROLE_SQL = "('accounting_admin','accounting_officer','cashier')";

    /** @param callable(int):int $revokeSessions */
    public function __construct(
        private readonly PDO $pdo,
        private readonly StructuredActivityAuditWriter $audit,
        private readonly mixed $revokeSessions
    ) {}

    public static function isManagedRole(string $role): bool
    {
        return in_array($role, self::MANAGED_ROLES, true);
    }

    /** @return list<array<string,mixed>> */
    public function list(): array
    {
        $sql = 'SELECT id, full_name, username, email, role_key, status,
                       must_change_password, failed_login_attempts, locked_until,
                       last_seen_at, created_at
                FROM users WHERE role_key IN ' . self::ROLE_SQL . '
                ORDER BY role_key, full_name, id';
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $actor */
    public function create(array $input, array $actor): array
    {
        $this->assertFields($input, ['full_name', 'username', 'email', 'role_key', 'password', 'password_confirm']);
        $profile = $this->profile($input, true);
        $this->assertManagedRole($profile['role_key']);
        $password = (string) ($input['password'] ?? '');
        if ($password !== (string) ($input['password_confirm'] ?? '')) {
            throw new InvalidArgumentException('Password confirmation does not match.');
        }
        $validation = smsValidatePasswordForAccount($password, $profile['username'], $profile['email']);
        if (!$validation['ok']) throw new InvalidArgumentException($validation['message']);
        $this->assertUnique($profile['username'], $profile['email']);
        return $this->transaction(function () use ($profile, $password, $actor): array {
            $stmt = $this->pdo->prepare(
                "INSERT INTO users (username,email,password_hash,full_name,role_key,status,
                 must_change_password,password_changed_at,created_at,updated_at)
                 VALUES (?,?,?,?,?,'active',1,?,?,?)"
            );
            $now = $this->now();
            $stmt->execute([$profile['username'], $profile['email'], password_hash($password, PASSWORD_DEFAULT),
                $profile['full_name'], $profile['role_key'], $now, $now, $now]);
            $id = (int) $this->pdo->lastInsertId();
            $this->writeAudit('PAYMENT_USER_CREATED', $id, null, [
                'full_name' => $profile['full_name'], 'username' => $profile['username'],
                'email' => $profile['email'], 'role_key' => $profile['role_key'], 'status' => 'active',
            ], $actor);
            return ['user_id' => $id];
        });
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $actor */
    public function updateProfile(array $input, array $actor): array
    {
        $this->assertFields($input, ['user_id', 'full_name', 'username', 'email']);
        $id = $this->id($input);
        $profile = $this->profile($input, false);
        return $this->transaction(function () use ($id, $profile, $actor): array {
            $before = $this->target($id, true);
            $this->assertUnique($profile['username'], $profile['email'], $id);
            $stmt = $this->pdo->prepare(
                'UPDATE users SET full_name=?, username=?, email=?, updated_at=? WHERE id=? AND role_key IN ' . self::ROLE_SQL
            );
            $stmt->execute([$profile['full_name'], $profile['username'], $profile['email'], $this->now(), $id]);
            $this->assertChangedTarget($stmt, $id);
            $this->writeAudit('PAYMENT_USER_UPDATED', $id, $this->safeProfile($before), [
                'full_name' => $profile['full_name'], 'username' => $profile['username'],
                'email' => $profile['email'], 'role_key' => $before['role_key'], 'status' => $before['status'],
            ], $actor);
            return ['user_id' => $id];
        });
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $actor */
    public function assignRole(array $input, array $actor): array
    {
        $this->assertFields($input, ['user_id', 'role_key']);
        $id = $this->id($input);
        $role = (string) ($input['role_key'] ?? '');
        $this->assertManagedRole($role);
        return $this->transaction(function () use ($id, $role, $actor): array {
            $before = $this->target($id, true);
            $this->assertManagedRole((string) $before['role_key']);
            if ($before['role_key'] === $role) return ['user_id' => $id, 'changed' => false];
            $stmt = $this->pdo->prepare('UPDATE users SET role_key=?, updated_at=? WHERE id=? AND role_key IN ' . self::ROLE_SQL);
            $stmt->execute([$role, $this->now(), $id]);
            $this->assertChangedTarget($stmt, $id);
            ($this->revokeSessions)($id);
            $this->writeAudit('PAYMENT_USER_ROLE_CHANGED', $id,
                ['role_key' => $before['role_key']], ['role_key' => $role, 'sessions_revoked' => true,
                ], $actor);
            return ['user_id' => $id, 'changed' => true];
        });
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $actor */
    public function setAdministrativeStatus(array $input, array $actor, bool $active): array
    {
        $this->assertFields($input, ['user_id']);
        $id = $this->id($input);
        return $this->transaction(function () use ($id, $active, $actor): array {
            $before = $this->target($id, true);
            // A legacy/manual lock can be represented by status=locked with no
            // timestamp. Activation must not silently remove that security lock.
            $status = $active && $before['status'] === 'locked' ? 'locked' : ($active ? 'active' : 'inactive');
            $stmt = $this->pdo->prepare('UPDATE users SET status=?, updated_at=? WHERE id=? AND role_key IN ' . self::ROLE_SQL);
            $stmt->execute([$status, $this->now(), $id]);
            if (!$active) ($this->revokeSessions)($id);
            $this->writeAudit($active ? 'PAYMENT_USER_ACTIVATED' : 'PAYMENT_USER_DEACTIVATED', $id,
                ['status' => $before['status'], 'failed_login_attempts' => (int) $before['failed_login_attempts'],
                    'locked_until' => $before['locked_until']],
                ['status' => $status, 'failed_login_attempts' => (int) $before['failed_login_attempts'],
                    'locked_until' => $before['locked_until'], 'sessions_revoked' => !$active], $actor);
            return ['user_id' => $id];
        });
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $actor */
    public function unlock(array $input, array $actor): array
    {
        $this->assertFields($input, ['user_id']);
        $id = $this->id($input);
        return $this->transaction(function () use ($id, $actor): array {
            $before = $this->target($id, true);
            $afterStatus = $before['status'] === 'locked' ? 'active' : $before['status'];
            $stmt = $this->pdo->prepare(
                'UPDATE users SET failed_login_attempts=0, locked_until=NULL,
                 status=CASE WHEN status=\'locked\' THEN \'active\' ELSE status END, updated_at=?
                 WHERE id=? AND role_key IN ' . self::ROLE_SQL
            );
            $stmt->execute([$this->now(), $id]);
            $this->writeAudit('PAYMENT_USER_UNLOCKED', $id,
                ['status' => $before['status'], 'failed_login_attempts' => (int) $before['failed_login_attempts'],
                    'locked_until' => $before['locked_until']],
                ['status' => $afterStatus, 'failed_login_attempts' => 0, 'locked_until' => null], $actor);
            return ['user_id' => $id];
        });
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $actor */
    public function resetPassword(array $input, array $actor): array
    {
        $this->assertFields($input, ['user_id', 'password', 'password_confirm']);
        $id = $this->id($input);
        $password = (string) ($input['password'] ?? '');
        if ($password !== (string) ($input['password_confirm'] ?? '')) {
            throw new InvalidArgumentException('Password confirmation does not match.');
        }
        return $this->transaction(function () use ($id, $password, $actor): array {
            $before = $this->target($id, true);
            $validation = smsValidatePasswordForAccount($password, (string) $before['username'], (string) $before['email']);
            if (!$validation['ok']) throw new InvalidArgumentException($validation['message']);
            $stmt = $this->pdo->prepare(
                'UPDATE users SET password_hash=?, must_change_password=1, password_changed_at=?, updated_at=?
                 WHERE id=? AND role_key IN ' . self::ROLE_SQL
            );
            $now = $this->now();
            $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $now, $now, $id]);
            $this->assertChangedTarget($stmt, $id);
            ($this->revokeSessions)($id);
            $this->writeAudit('PAYMENT_USER_PASSWORD_RESET', $id,
                ['must_change_password' => (int) $before['must_change_password'], 'status' => $before['status'],
                    'failed_login_attempts' => (int) $before['failed_login_attempts'], 'locked_until' => $before['locked_until']],
                ['must_change_password' => 1, 'status' => $before['status'],
                    'failed_login_attempts' => (int) $before['failed_login_attempts'],
                    'locked_until' => $before['locked_until'], 'sessions_revoked' => true], $actor);
            return ['user_id' => $id];
        });
    }

    /** @param array<string,mixed> $input */
    private function assertFields(array $input, array $allowed): void
    {
        $allowed = array_merge(['action', 'csrf_token'], $allowed);
        foreach (array_keys($input) as $field) {
            if (!in_array((string) $field, $allowed, true)) {
                throw new InvalidArgumentException('Unsupported request field: ' . (string) $field);
            }
        }
    }

    /** @param array<string,mixed> $input */
    private function profile(array $input, bool $withRole): array
    {
        $profile = [
            'full_name' => trim((string) ($input['full_name'] ?? '')),
            'username' => strtolower(trim((string) ($input['username'] ?? ''))),
            'email' => strtolower(trim((string) ($input['email'] ?? ''))),
        ];
        if ($profile['full_name'] === '' || !preg_match('/^[a-z0-9._-]{3,80}$/', $profile['username'])
            || !filter_var($profile['email'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Full name, a valid username, and a valid email are required.');
        }
        if (mb_strlen($profile['full_name']) > 150 || mb_strlen($profile['email']) > 190) {
            throw new InvalidArgumentException('Account profile values exceed the allowed length.');
        }
        if ($withRole) $profile['role_key'] = (string) ($input['role_key'] ?? '');
        return $profile;
    }

    private function assertManagedRole(string $role): void
    {
        if (!self::isManagedRole($role)) throw new DomainException('PAYMENT_USER_ROLE_FORBIDDEN');
    }

    /** @return array<string,mixed> */
    private function target(int $id, bool $forUpdate): array
    {
        $suffix = $forUpdate && $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite' ? ' FOR UPDATE' : '';
        $stmt = $this->pdo->prepare(
            'SELECT id, full_name, username, email, role_key, status, must_change_password,
                    failed_login_attempts, locked_until FROM users
             WHERE id=? AND role_key IN ' . self::ROLE_SQL . ' LIMIT 1' . $suffix
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new DomainException('PAYMENT_USER_SCOPE_VIOLATION');
        return $row;
    }

    private function assertUnique(string $username, string $email, int $except = 0): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT username, email FROM users
             WHERE (LOWER(username)=LOWER(?) OR LOWER(email)=LOWER(?)) AND id<>?'
        );
        $stmt->execute([$username, $email, $except]);
        $usernameExists = false;
        $emailExists = false;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $usernameExists = $usernameExists || strcasecmp((string) $row['username'], $username) === 0;
            $emailExists = $emailExists || strcasecmp((string) $row['email'], $email) === 0;
        }
        if ($usernameExists && $emailExists) {
            throw new InvalidArgumentException('Username and email already exist.');
        }
        if ($usernameExists) throw new InvalidArgumentException('Username already exists.');
        if ($emailExists) throw new InvalidArgumentException('Email already exists.');
    }

    private function assertChangedTarget(PDOStatement $stmt, int $id): void
    {
        if ($stmt->rowCount() > 0) return;
        $this->target($id, false);
    }

    private function id(array $input): int
    {
        $id = filter_var($input['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id) throw new InvalidArgumentException('A valid target user is required.');
        return (int) $id;
    }

    /** @return array<string,mixed> */
    private function safeProfile(array $row): array
    {
        return ['full_name' => $row['full_name'], 'username' => $row['username'], 'email' => $row['email'],
            'role_key' => $row['role_key'], 'status' => $row['status']];
    }

    private function writeAudit(string $action, int $targetId, ?array $before, array $after, array $actor): void
    {
        $correlation = $this->uuidV4();
        $this->audit->write([
            'user_id' => (int) $actor['id'], 'user_name' => (string) $actor['name'],
            'role_key' => (string) $actor['role'], 'action' => $action, 'module_key' => 'payment',
            'entity_type' => 'payment_user', 'entity_id' => $targetId,
            'detail' => $action . ' for managed Payment user #' . $targetId,
            'before_state' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_state' => json_encode($after, JSON_THROW_ON_ERROR),
            'correlation_id' => $correlation, 'ip_address' => smsClientIp(),
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null,
        ]);
    }

    private function transaction(callable $operation): array
    {
        $started = !$this->pdo->inTransaction();
        if ($started) $this->pdo->beginTransaction();
        try {
            $result = $operation();
            if ($started) $this->pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($started && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    private function now(): string { return date('Y-m-d H:i:s'); }

    private function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
