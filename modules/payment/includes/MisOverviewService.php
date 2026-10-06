<?php
declare(strict_types=1);

/** Read-only technical overview. Never queries financial or student records. */
final class MisOverviewService
{
    private const ROLES = ['accounting_admin', 'accounting_officer', 'cashier'];
    private const ACTIONS = [
        'PAYMENT_USER_CREATED' => 'Payment user created',
        'PAYMENT_USER_UPDATED' => 'Payment user updated',
        'PAYMENT_USER_ROLE_CHANGED' => 'Payment user role changed',
        'PAYMENT_USER_ACTIVATED' => 'Payment user activated',
        'PAYMENT_USER_DEACTIVATED' => 'Payment user deactivated',
        'PAYMENT_USER_UNLOCKED' => 'Payment user unlocked',
        'PAYMENT_USER_PASSWORD_RESET' => 'Payment user password reset',
        'PAYMONGO_CONFIGURATION_UPDATED' => 'PayMongo configuration updated',
        'PAYMONGO_MODE_CHANGED' => 'PayMongo mode changed',
        'PAYMONGO_CONNECTION_TESTED' => 'PayMongo connection tested',
        'OCR_CONFIGURATION_UPDATED' => 'Google OCR configuration updated',
        'accounting_user_create' => 'Payment user created',
        'accounting_user_edit' => 'Payment user updated',
        'accounting_user_status' => 'Payment user status changed',
        'accounting_user_password_reset' => 'Payment user password reset',
    ];

    public function __construct(private PDO $core, private ?PDO $technical = null, private array $credentials = []) {}

    private function section(array &$result, string $key, callable $load): void
    {
        try {
            $result[$key] = $load();
            $result['section_status'][$key] = $result[$key] === [] ? 'no_data' : 'ok';
        } catch (Throwable $e) {
            error_log('MIS overview section unavailable: ' . $key);
            $result[$key] = null;
            $result['section_status'][$key] = 'error';
        }
    }

    public function load(): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
        $result = ['ok' => true, 'generated_at' => $now->format(DATE_ATOM), 'section_status' => []];
        $this->section($result, 'accounts', function () use ($now): array {
            $stmt = $this->core->prepare('SELECT role_key, status, failed_login_attempts, locked_until FROM users WHERE role_key IN (?, ?, ?)');
            $stmt->execute(self::ROLES);
            $counts = ['total' => 0, 'active' => 0, 'inactive' => 0, 'locked' => 0, 'failed_attempts' => 0,
                'by_role' => array_fill_keys(self::ROLES, 0)];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $counts['total']++;
                $counts['by_role'][$row['role_key']]++;
                $row['status'] === 'active' ? $counts['active']++ : $counts['inactive']++;
                $counts['failed_attempts'] += max(0, (int) $row['failed_login_attempts']);
                if (!empty($row['locked_until']) && new DateTimeImmutable($row['locked_until'], $now->getTimezone()) > $now) {
                    $counts['locked']++;
                }
            }
            return $counts;
        });
        $this->section($result, 'activity', function (): array {
            // Allowlist actor, module, and event type. Never return raw detail,
            // which can contain historical usernames, secrets, or other data.
            $placeholders = implode(', ', array_fill(0, count(self::ACTIONS), '?'));
            $stmt = $this->core->prepare("SELECT id, action, created_at FROM activity_logs WHERE role_key = 'mis_admin' AND module_key = 'payment' AND action IN ($placeholders) ORDER BY created_at DESC, id DESC LIMIT 12");
            $stmt->execute(array_keys(self::ACTIONS));
            return array_map(static fn(array $row): array => [
                'id' => (int) $row['id'], 'event' => self::ACTIONS[$row['action']], 'created_at' => $row['created_at'],
            ], $stmt->fetchAll(PDO::FETCH_ASSOC));
        });
        $this->section($result, 'paymongo', function (): array {
            if (!$this->technical) throw new RuntimeException('Technical configuration unavailable');
            $stmt = $this->technical->query("SELECT setting_value FROM payment_gateway_settings WHERE setting_key = 'gateway_mode'");
            $mode = $stmt->fetchColumn();
            if (!in_array($mode, ['test', 'live'], true)) {
                return ['environment' => null, 'status' => 'Configuration incomplete', 'api_credentials' => false, 'webhook_secret' => false];
            }
            return ['environment' => $mode, 'status' => 'Configuration checked',
                'api_credentials' => !empty($this->credentials[$mode]['api']),
                'webhook_secret' => !empty($this->credentials[$mode]['webhook'])];
        });
        $this->section($result, 'ocr', function (): array {
            if (!$this->technical) throw new RuntimeException('Technical configuration unavailable');
            $stmt = $this->technical->query("SELECT setting_key, setting_value FROM payment_gateway_settings WHERE setting_key IN ('ocr_enabled', 'ocr_project_id', 'ocr_mode', 'ocr_monthly_limit')");
            $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            $configured = ($settings['ocr_project_id'] ?? '') !== ''
                && ($settings['ocr_mode'] ?? '') === 'DOCUMENT_TEXT_DETECTION'
                && (int) ($settings['ocr_monthly_limit'] ?? 0) >= 1;
            return [
                'enabled' => ($settings['ocr_enabled'] ?? '0') === '1',
                'status' => $configured ? 'Technical configuration saved' : 'Configuration incomplete',
            ];
        });
        $result['integrations'] = [
            'ocr' => $result['ocr']['status'] ?? 'Configuration unavailable',

        ];
        return $result;
    }
}
