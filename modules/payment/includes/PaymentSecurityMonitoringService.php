<?php
declare(strict_types=1);

final class PaymentSecurityMonitoringService
{
    public const MANAGED_ROLES = ['accounting_admin', 'accounting_officer', 'cashier'];
    public const PAGE_SIZES = [25, 50, 100];
    private const ROLE_SQL = "('accounting_admin','accounting_officer','cashier')";
    private const EVENT_ALIASES = [
        'login_failed' => ['type' => 'LOGIN_FAILED', 'label' => 'Login failed', 'severity' => 'warning', 'result' => 'DENIED'],
        'lockout' => ['type' => 'ACCOUNT_LOCKED', 'label' => 'Account locked', 'severity' => 'warning', 'result' => 'LOCKED'],
        'PAYMENT_USER_UNLOCKED' => ['type' => 'PAYMENT_USER_UNLOCKED', 'label' => 'Account unlocked', 'severity' => 'info', 'result' => 'SUCCESS'],
        'PAYMENT_USER_PASSWORD_RESET' => ['type' => 'PAYMENT_USER_PASSWORD_RESET', 'label' => 'Password reset', 'severity' => 'info', 'result' => 'SUCCESS'],
        'PAYMENT_USER_DEACTIVATED' => ['type' => 'PAYMENT_USER_DEACTIVATED', 'label' => 'Account disabled', 'severity' => 'info', 'result' => 'SUCCESS'],
        'PAYMENT_USER_ACTIVATED' => ['type' => 'PAYMENT_USER_ACTIVATED', 'label' => 'Account activated', 'severity' => 'info', 'result' => 'SUCCESS'],
        'PAYMENT_USER_ROLE_CHANGED' => ['type' => 'PAYMENT_USER_ROLE_CHANGED', 'label' => 'Payment role changed', 'severity' => 'info', 'result' => 'SUCCESS'],
        'accounting_user_password_reset' => ['type' => 'PAYMENT_USER_PASSWORD_RESET', 'label' => 'Password reset', 'severity' => 'info', 'result' => 'SUCCESS'],
        'accounting_user_status' => ['type' => 'PAYMENT_USER_STATUS_CHANGED', 'label' => 'Account status changed', 'severity' => 'info', 'result' => 'SUCCESS'],
    ];

    public function __construct(private readonly PDO $pdo, private readonly ?DateTimeImmutable $clock = null) {}

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function load(array $filters): array
    {
        $filters = $this->validateFilters($filters);
        return [
            'summary' => $this->summary(),
            'personnel' => $this->personnel($filters),
            'events' => $this->events($filters),
            'filters' => $filters,
            'meta' => [
                'managed_roles' => self::MANAGED_ROLES,
                'page_sizes' => self::PAGE_SIZES,
                'active_session_count_available' => false,
            ],
        ];
    }

    /** @return array<string,int> */
    public function summary(): array
    {
        $rows = $this->pdo->query(
            'SELECT status, failed_login_attempts, locked_until FROM users WHERE role_key IN ' . self::ROLE_SQL
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $summary = ['locked_accounts' => 0, 'accounts_with_failed_attempts' => 0,
            'disabled_accounts' => 0, 'recent_session_revocations' => 0];
        foreach ($rows as $row) {
            $state = $this->deriveState($row);
            if ($state['security_state'] === 'LOCKED') $summary['locked_accounts']++;
            if ((int) ($row['failed_login_attempts'] ?? 0) > 0) $summary['accounts_with_failed_attempts']++;
            if ($state['administrative_state'] === 'INACTIVE') $summary['disabled_accounts']++;
        }
        $summary['recent_session_revocations'] = $this->recentSessionRevocations();
        return $summary;
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function personnel(array $filters): array
    {
        $where = ['role_key IN ' . self::ROLE_SQL];
        $params = [];
        if ($filters['role'] !== '') { $where[] = 'role_key = ?'; $params[] = $filters['role']; }
        if ($filters['search'] !== '') {
            $where[] = '(LOWER(full_name) LIKE ? OR LOWER(username) LIKE ? OR LOWER(email) LIKE ?)';
            $needle = '%' . strtolower($filters['search']) . '%';
            array_push($params, $needle, $needle, $needle);
        }
        if ($filters['failed_attempts'] === 'present') $where[] = 'failed_login_attempts > 0';
        if ($filters['administrative_status'] === 'active') $where[] = "status IN ('active','locked')";
        if ($filters['administrative_status'] === 'inactive') $where[] = "status IN ('inactive','suspended')";
        if ($filters['lock_state'] === 'locked') {
            $where[] = "((locked_until IS NOT NULL AND locked_until > ?) OR (status = 'locked' AND locked_until IS NULL))";
            $params[] = $this->now()->format('Y-m-d H:i:s');
        } elseif ($filters['lock_state'] === 'unlocked') {
            $where[] = "locked_until IS NULL AND status <> 'locked'";
        } elseif ($filters['lock_state'] === 'lock_expired') {
            $where[] = 'locked_until IS NOT NULL AND locked_until <= ?';
            $params[] = $this->now()->format('Y-m-d H:i:s');
        }
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM users WHERE ' . implode(' AND ', $where));
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $offset = ($filters['personnel_page'] - 1) * $filters['page_size'];
        $rows = $this->pdo->prepare(
            'SELECT id, full_name, username, email, role_key, status, failed_login_attempts, locked_until
             FROM users WHERE ' . implode(' AND ', $where) . ' ORDER BY role_key, full_name, id
             LIMIT ' . $filters['page_size'] . ' OFFSET ' . $offset
        );
        $rows->execute($params);
        $items = [];
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $state = $this->deriveState($row);
            $items[] = [
                'id' => (int) $row['id'], 'full_name' => (string) $row['full_name'],
                'username' => (string) $row['username'], 'email' => (string) $row['email'],
                'role_key' => (string) $row['role_key'],
                'administrative_state' => $state['administrative_state'],
                'security_state' => $state['security_state'],
                'current_failed_attempts' => max(0, (int) $row['failed_login_attempts']),
                'locked_until' => $row['locked_until'] ?: null,
                'last_security_activity' => $this->lastSecurityActivity((int) $row['id']),
                'alerts' => $this->alerts($row, $state),
            ];
        }
        return ['items' => $items, 'total' => $total,
            'page' => $filters['personnel_page'], 'page_size' => $filters['page_size'],
            'pages' => max(1, (int) ceil($total / $filters['page_size']))];
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function events(array $filters): array
    {
        $users = $this->managedUsers();
        if ($users === []) return ['items' => [], 'total' => 0, 'page' => $filters['event_page'], 'page_size' => $filters['page_size'], 'pages' => 1];
        $actions = array_keys(self::EVENT_ALIASES);
        $actionMarks = implode(',', array_fill(0, count($actions), '?'));
        $userMarks = implode(',', array_fill(0, count($users), '?'));
        $where = ["action IN ($actionMarks)", "(entity_id IN ($userMarks) OR (entity_id IS NULL AND user_id IN ($userMarks)))"];
        $params = array_merge($actions, array_keys($users), array_keys($users));
        if ($filters['date_from'] !== '') { $where[] = 'created_at >= ?'; $params[] = $filters['date_from'] . ' 00:00:00'; }
        if ($filters['date_to'] !== '') { $where[] = 'created_at < ?'; $params[] = (new DateTimeImmutable($filters['date_to']))->modify('+1 day')->format('Y-m-d 00:00:00'); }
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, user_name, role_key, action, module_key, entity_id, before_state, after_state,
                    correlation_id, ip_address, created_at
             FROM activity_logs WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC, id DESC LIMIT 1000'
        );
        $stmt->execute($params);
        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $targetId = !empty($row['entity_id']) ? (int) $row['entity_id'] : (int) ($row['user_id'] ?? 0);
            if (!isset($users[$targetId])) continue;
            $target = $users[$targetId];
            $event = self::EVENT_ALIASES[(string) $row['action']];
            if ($filters['event_type'] !== '' && $event['type'] !== $filters['event_type']) continue;
            if ($filters['role'] !== '' && $target['role_key'] !== $filters['role']) continue;
            if ($filters['target_user'] > 0 && $targetId !== $filters['target_user']) continue;
            if ($filters['result'] !== '' && strtolower($event['result']) !== $filters['result']) continue;
            $after = $this->safeJson((string) ($row['after_state'] ?? ''));
            $revoked = !empty($after['sessions_revoked']);
            $items[] = [
                'id' => (int) $row['id'], 'created_at' => (string) $row['created_at'],
                'event_type' => $event['type'], 'event' => $event['label'],
                'actor' => $row['module_key'] === 'System' ? 'System' : ((string) ($row['user_name'] ?: 'MIS Administrator')),
                'target' => $target['full_name'], 'target_user_id' => $targetId,
                'role_key' => $target['role_key'], 'result' => $event['result'], 'severity' => $event['severity'],
                'safe_context' => $revoked ? 'Existing sessions were revoked.' : $this->safeContext($event['type']),
                'correlation_id' => $this->safeCorrelation($row['correlation_id'] ?? null),
                'ip_address' => $this->safeIp($row['ip_address'] ?? null),
                'sessions_revoked' => $revoked,
            ];
        }
        $total = count($items);
        $offset = ($filters['event_page'] - 1) * $filters['page_size'];
        return ['items' => array_slice($items, $offset, $filters['page_size']), 'total' => $total,
            'page' => $filters['event_page'], 'page_size' => $filters['page_size'],
            'pages' => max(1, (int) ceil($total / $filters['page_size']))];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function validateFilters(array $input): array
    {
        $allowed = ['role','administrative_status','lock_state','failed_attempts','search','date_from','date_to',
            'event_type','target_user','result','personnel_page','event_page','page_size'];
        foreach (array_keys($input) as $key) if (!in_array((string) $key, $allowed, true)) throw new InvalidArgumentException('Unsupported filter.');
        $role = strtolower(trim((string) ($input['role'] ?? '')));
        if ($role !== '' && !in_array($role, self::MANAGED_ROLES, true)) throw new InvalidArgumentException('Invalid Payment role filter.');
        $admin = strtolower(trim((string) ($input['administrative_status'] ?? '')));
        if (!in_array($admin, ['', 'active', 'inactive'], true)) throw new InvalidArgumentException('Invalid administrative status filter.');
        $lock = strtolower(trim((string) ($input['lock_state'] ?? '')));
        if (!in_array($lock, ['', 'locked', 'unlocked', 'lock_expired'], true)) throw new InvalidArgumentException('Invalid lock-state filter.');
        $failed = strtolower(trim((string) ($input['failed_attempts'] ?? '')));
        if (!in_array($failed, ['', 'present'], true)) throw new InvalidArgumentException('Invalid failed-attempt filter.');
        $result = strtolower(trim((string) ($input['result'] ?? '')));
        if (!in_array($result, ['', 'success', 'denied', 'locked'], true)) throw new InvalidArgumentException('Invalid result filter.');
        $event = strtoupper(trim((string) ($input['event_type'] ?? '')));
        $eventTypes = array_values(array_unique(array_column(self::EVENT_ALIASES, 'type')));
        if ($event !== '' && !in_array($event, $eventTypes, true)) throw new InvalidArgumentException('Invalid event type filter.');
        $size = $this->positiveInt($input['page_size'] ?? 25, 'page size');
        if (!in_array($size, self::PAGE_SIZES, true)) throw new InvalidArgumentException('Page size must be 25, 50, or 100.');
        $target = (int) ($input['target_user'] ?? 0);
        if ($target < 0) throw new InvalidArgumentException('Invalid target user filter.');
        $dateFrom = $this->date((string) ($input['date_from'] ?? ''));
        $dateTo = $this->date((string) ($input['date_to'] ?? ''));
        if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) throw new InvalidArgumentException('Date range is invalid.');
        return ['role' => $role, 'administrative_status' => $admin, 'lock_state' => $lock,
            'failed_attempts' => $failed, 'search' => mb_substr(trim((string) ($input['search'] ?? '')), 0, 100),
            'date_from' => $dateFrom, 'date_to' => $dateTo,
            'event_type' => $event, 'target_user' => $target, 'result' => $result,
            'personnel_page' => $this->positiveInt($input['personnel_page'] ?? 1, 'personnel page'),
            'event_page' => $this->positiveInt($input['event_page'] ?? 1, 'event page'), 'page_size' => $size];
    }

    /** @param array<string,mixed> $row @return array{administrative_state:string,security_state:string} */
    private function deriveState(array $row): array
    {
        $status = strtolower((string) ($row['status'] ?? ''));
        $admin = in_array($status, ['inactive', 'suspended'], true) ? 'INACTIVE' : 'ACTIVE';
        $until = trim((string) ($row['locked_until'] ?? ''));
        if ($until !== '') {
            $time = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $until, $this->now()->getTimezone());
            $security = $time && $time > $this->now() ? 'LOCKED' : 'LOCK_EXPIRED';
        } else {
            $security = $status === 'locked' ? 'LOCKED' : 'UNLOCKED';
        }
        return ['administrative_state' => $admin, 'security_state' => $security];
    }

    /** @return array<int,array{full_name:string,role_key:string}> */
    private function managedUsers(): array
    {
        $rows = $this->pdo->query('SELECT id, full_name, role_key FROM users WHERE role_key IN ' . self::ROLE_SQL)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $result = [];
        foreach ($rows as $row) $result[(int) $row['id']] = ['full_name' => (string) $row['full_name'], 'role_key' => (string) $row['role_key']];
        return $result;
    }

    private function recentSessionRevocations(): int
    {
        $since = $this->now()->modify('-30 days')->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare("SELECT entity_id, after_state FROM activity_logs WHERE module_key='payment' AND entity_type='payment_user' AND action IN ('PAYMENT_USER_ROLE_CHANGED','PAYMENT_USER_DEACTIVATED','PAYMENT_USER_PASSWORD_RESET') AND created_at>=? ORDER BY created_at DESC, id DESC LIMIT 1000");
        $stmt->execute([$since]);
        $managed = $this->managedUsers();
        $count = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            if (!isset($managed[(int) ($row['entity_id'] ?? 0)])) continue;
            if (!empty($this->safeJson((string) ($row['after_state'] ?? ''))['sessions_revoked'])) $count++;
        }
        return $count;
    }

    private function lastSecurityActivity(int $userId): ?string
    {
        $actions = array_keys(self::EVENT_ALIASES);
        $marks = implode(',', array_fill(0, count($actions), '?'));
        $stmt = $this->pdo->prepare("SELECT MAX(created_at) FROM activity_logs WHERE action IN ($marks) AND (entity_id=? OR (entity_id IS NULL AND user_id=?))");
        $stmt->execute(array_merge($actions, [$userId, $userId]));
        return $stmt->fetchColumn() ?: null;
    }

    /** @param array<string,mixed> $row @param array<string,string> $state @return list<array<string,string>> */
    private function alerts(array $row, array $state): array
    {
        $alerts = [];
        if ($state['security_state'] === 'LOCKED') $alerts[] = ['level' => 'warning', 'label' => 'Account Locked'];
        if ((int) ($row['failed_login_attempts'] ?? 0) > 0) $alerts[] = ['level' => 'warning', 'label' => 'Elevated Failed Attempts'];
        if ($state['administrative_state'] === 'INACTIVE') $alerts[] = ['level' => 'info', 'label' => 'Account Disabled'];
        return $alerts;
    }

    private function safeContext(string $type): string
    {
        return match ($type) {
            'LOGIN_FAILED' => 'A persisted sign-in attempt was denied.',
            'ACCOUNT_LOCKED' => 'The account reached the configured lockout threshold.',
            'PAYMENT_USER_UNLOCKED' => 'Authentication lock state was cleared.',
            'PAYMENT_USER_PASSWORD_RESET' => 'A temporary password was issued; existing sessions were revoked.',
            'PAYMENT_USER_DEACTIVATED' => 'Administrative access was disabled.',
            'PAYMENT_USER_ROLE_CHANGED' => 'Payment role changed; existing sessions were revoked.',
            default => 'Payment personnel security administration event.',
        };
    }

    /** @return array<string,mixed> */
    private function safeJson(string $json): array
    {
        if ($json === '') return [];
        try { $value = json_decode($json, true, 16, JSON_THROW_ON_ERROR); return is_array($value) ? $value : []; }
        catch (Throwable) { return []; }
    }

    private function safeCorrelation(mixed $value): ?string
    {
        $value = trim((string) $value);
        return preg_match('/^[0-9a-f-]{36}$/i', $value) ? $value : null;
    }

    private function safeIp(mixed $value): ?string
    {
        $value = trim((string) $value);
        return filter_var($value, FILTER_VALIDATE_IP) ? $value : null;
    }

    private function positiveInt(mixed $value, string $field): int
    {
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
        if ($number === false) throw new InvalidArgumentException("Invalid {$field}.");
        return (int) $number;
    }

    private function date(string $value): string
    {
        $value = trim($value);
        if ($value === '') return '';
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) throw new InvalidArgumentException('Invalid date filter.');
        return $value;
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock ?? new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
    }
}
