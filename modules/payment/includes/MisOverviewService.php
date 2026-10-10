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
            require_once __DIR__ . '/OcrTechnicalStatusService.php';
            require_once __DIR__ . '/ocr/GoogleVisionOcrService.php';
            $stmt = $this->technical->query("SELECT setting_key, setting_value FROM payment_gateway_settings WHERE setting_key LIKE 'ocr_%'");
            $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            $config = [
                'enabled' => ($settings['ocr_enabled'] ?? '0') === '1',
                'project_id' => (string) ($settings['ocr_project_id'] ?? ''),
                'mode' => (string) ($settings['ocr_mode'] ?? 'DOCUMENT_TEXT_DETECTION'),
                'monthly_limit' => (int) ($settings['ocr_monthly_limit'] ?? 900),
                'last_test_status' => $settings['ocr_last_test_status'] ?? null,
                'last_test_authentication' => $settings['ocr_last_test_authentication'] ?? null,
                'last_test_error_category' => $settings['ocr_last_test_error_category'] ?? null,
                'last_diagnostic_at' => $settings['ocr_last_diagnostic_at'] ?? null,
                'last_diagnostic_config_fingerprint' => $settings['ocr_last_diagnostic_config_fingerprint'] ?? null,
                'last_ocr_successful_test' => $settings['ocr_last_ocr_test_successful_at'] ?? null,
                'last_ocr_test_config_fingerprint' => $settings['ocr_last_ocr_test_config_fingerprint'] ?? null,
            ];
            $provider = new GoogleVisionOcrService();
            $credential = $provider->configurationStatus();
            $credential['project_matches'] = $provider->projectIdentityMatches($config['project_id']);
            $credential['configuration_fingerprint'] = $provider->configurationFingerprint(
                $config['project_id'], $config['mode'], $config['monthly_limit'], $config['enabled']
            );
            $technical = (new OcrTechnicalStatusService($this->technical))->load($config, $credential);
            return [
                'enabled' => $config['enabled'],
                'status' => $config['project_id'] !== '' && $config['mode'] === 'DOCUMENT_TEXT_DETECTION'
                    ? 'Technical configuration saved' : 'Configuration incomplete',
                'readiness' => $technical['readiness'],
            ];
        });
        $result['integrations'] = [
            'ocr' => $result['ocr']['status'] ?? 'Configuration unavailable',

        ];
        return $result;
    }
}
