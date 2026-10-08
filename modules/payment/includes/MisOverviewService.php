<?php
declare(strict_types=1);

/** Read-only technical overview. Never queries financial or student records. */
final class MisOverviewService
{
    private const ROLES = ['accounting_admin', 'accounting_officer', 'cashier'];
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
