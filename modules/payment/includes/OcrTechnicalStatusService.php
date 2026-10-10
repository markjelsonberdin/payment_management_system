<?php
declare(strict_types=1);

require_once __DIR__ . '/ocr/PrivateReceiptStorageService.php';

final class OcrTechnicalStatusService
{
    private const REQUIRED_TABLES = [
        'payment_gateway_settings', 'payment_concerns', 'ocr_results',
        'ocr_usage_months', 'ocr_usage_ledger', 'ocr_scan_attempts', 'ocr_image_cache',
    ];

    public function __construct(private readonly PDO $pdo) {}

    /** @return array<string,mixed> */
    public function load(array $config, array $credential): array
    {
        $tz = new DateTimeZone('Asia/Manila');
        $now = new DateTimeImmutable('now', $tz);
        $month = $now->format('Y-m-01');
        $limit = (int) ($config['monthly_limit'] ?? 900);
        $usage = ['available' => false, 'month' => $month, 'record_present' => false,
            'configured_limit' => $limit, 'consumed' => null, 'reserved' => null,
            'remaining' => null, 'provider_attempts' => null, 'successful' => null,
            'failed' => null, 'cache_hits' => null];
        $failures = $states = [];
        $stale = null;
        $database = ['available' => false, 'required_tables' => false, 'quota_tables' => false, 'missing_tables' => self::REQUIRED_TABLES];

        try {
            if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $found = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
            } else {
                $marks = implode(',', array_fill(0, count(self::REQUIRED_TABLES), '?'));
                $tables = $this->pdo->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (' . $marks . ')');
                $tables->execute(self::REQUIRED_TABLES);
                $found = $tables->fetchAll(PDO::FETCH_COLUMN);
            }
            $missing = array_values(array_diff(self::REQUIRED_TABLES, $found));
            $quotaTables = in_array('ocr_usage_months', $found, true) && in_array('ocr_usage_ledger', $found, true);
            $database = ['available' => true, 'required_tables' => $missing === [], 'quota_tables' => $quotaTables, 'missing_tables' => $missing];

            if ($quotaTables) {
                $q = $this->pdo->prepare('SELECT configured_limit,reserved_units,consumed_units,successful_units,failed_units FROM ocr_usage_months WHERE billing_month=?');
                $q->execute([$month]);
                if ($row = $q->fetch(PDO::FETCH_ASSOC)) {
                    $monthLimit = (int) $row['configured_limit'];
                    $usage = array_merge($usage, ['available' => true, 'record_present' => true,
                        'configured_limit' => $monthLimit, 'reserved' => (int) $row['reserved_units'],
                        'consumed' => (int) $row['consumed_units'],
                        'remaining' => max(0, $monthLimit - (int) $row['reserved_units'] - (int) $row['consumed_units']),
                        'successful' => (int) $row['successful_units'], 'failed' => (int) $row['failed_units']]);
                } else {
                    $usage['available'] = true;
                }
                $q = $this->pdo->prepare('SELECT COUNT(*) FROM ocr_usage_ledger WHERE billing_month=? AND provider_called=1');
                $q->execute([$month]); $usage['provider_attempts'] = (int) $q->fetchColumn();
                $q = $this->pdo->prepare("SELECT COUNT(*) FROM ocr_usage_ledger WHERE billing_month=? AND lifecycle_state='CACHE_HIT'");
                $q->execute([$month]); $usage['cache_hits'] = (int) $q->fetchColumn();
                $q = $this->pdo->prepare('SELECT lifecycle_state,COUNT(*) total FROM ocr_usage_ledger WHERE billing_month=? GROUP BY lifecycle_state ORDER BY lifecycle_state');
                $q->execute([$month]); $states = $q->fetchAll(PDO::FETCH_ASSOC);
                $q = $this->pdo->prepare("SELECT COALESCE(failure_category,'UNCLASSIFIED') category,COUNT(*) total FROM ocr_usage_ledger WHERE billing_month=? AND lifecycle_state IN ('FAILED','RELEASED','REJECTED_LIMIT') GROUP BY COALESCE(failure_category,'UNCLASSIFIED') ORDER BY total DESC,category LIMIT 10");
                $q->execute([$month]); $failures = $q->fetchAll(PDO::FETCH_ASSOC);
                $cutoff = $now->modify('-15 minutes')->format('Y-m-d H:i:s');
                $q = $this->pdo->prepare("SELECT COUNT(*) FROM ocr_usage_ledger WHERE (lifecycle_state='RESERVED' AND reserved_at<?) OR (lifecycle_state='PROVIDER_CALLED' AND provider_called_at<? AND completed_at IS NULL)");
                $q->execute([$cutoff, $cutoff]); $stale = (int) $q->fetchColumn();
            }
        } catch (Throwable $e) {
            error_log('OCR technical monitoring unavailable: ' . get_class($e));
            $usage['available'] = false;
            $database = ['available' => false, 'required_tables' => false, 'quota_tables' => false, 'missing_tables' => self::REQUIRED_TABLES];
            $usage['provider_attempts'] = $usage['cache_hits'] = null;
            $failures = $states = [];
            $stale = null;
        }

        $projectMatches = ($credential['project_matches'] ?? false) === true;
        $projectConfigured = trim((string) ($config['project_id'] ?? '')) !== '';
        $modeValid = strtoupper((string) ($config['mode'] ?? '')) === 'DOCUMENT_TEXT_DETECTION';
        $configurationMatches = $projectMatches && $modeValid;
        $credentialReady = ($credential['status'] ?? '') === 'CONFIGURED'
            && ($credential['readable'] ?? false) === true
            && ($credential['protected_location'] ?? false) === true;
        $storage = (new PrivateReceiptStorageService())->configurationStatus(false);
        $fingerprint = (string) ($credential['configuration_fingerprint'] ?? '');
        $diagnosticCurrent = $fingerprint !== ''
            && $fingerprint === (string) ($config['last_diagnostic_config_fingerprint'] ?? '');
        $authentication = $diagnosticCurrent ? ($config['last_test_authentication'] ?? 'UNVERIFIED') : 'UNVERIFIED';
        $hasPriorOcrTest = !empty($config['last_ocr_successful_test']);
        $ocrTestCurrent = $hasPriorOcrTest && $fingerprint !== ''
            && $fingerprint === (string) ($config['last_ocr_test_config_fingerprint'] ?? '');

        $steps = [
            ['key' => 'configuration_project', 'label' => 'Configuration & project match',
                'state' => $configurationMatches ? 'verified' : 'blocked',
                'detail' => $configurationMatches ? 'Saved, runtime, and credential project identities match.' : 'Saved project, runtime project, credential identity, or detection mode does not match.'],
            ['key' => 'credentials', 'label' => 'Credentials readable & protected',
                'state' => $credentialReady ? 'verified' : 'blocked',
                'detail' => $credentialReady ? 'Credential structure is readable from a protected location.' : 'Credential is missing, unreadable, invalid, or not protected.'],
            ['key' => 'authentication', 'label' => 'OAuth authentication',
                'state' => $authentication === 'VERIFIED' ? 'verified' : ($authentication === 'FAILED' ? 'blocked' : 'pending'),
                'detail' => $authentication === 'VERIFIED' ? 'OAuth was verified for the current configuration.' : ($authentication === 'FAILED' ? 'The current configuration failed OAuth authentication.' : 'Run the non-billable authentication diagnostic.')],
            ['key' => 'vision_api', 'label' => 'Vision API & processing',
                'state' => $ocrTestCurrent ? 'verified' : 'pending',
                'detail' => $ocrTestCurrent ? 'A successful OCR test matches the current configuration.' : 'OAuth alone does not verify Vision API access or OCR processing.'],
            ['key' => 'receipt_storage', 'label' => 'Private receipt storage',
                'state' => ($storage['status'] ?? '') === 'CONFIGURED' && ($storage['outside_application'] ?? false) ? 'verified' : 'blocked',
                'detail' => ($storage['status'] ?? '') === 'CONFIGURED' && ($storage['outside_application'] ?? false) ? 'Storage is readable, writable, and outside the application directory.' : 'Private storage is not configured, writable, or safely outside the application directory.'],
            ['key' => 'database_quota', 'label' => 'Payment DB & quota schema',
                'state' => $database['available'] && $database['required_tables'] ? 'verified' : 'blocked',
                'detail' => $database['available'] && $database['required_tables'] ? 'Required payment and quota tables are available.' : 'Payment DB or one or more required quota tables are unavailable.'],
            ['key' => 'receipt_scanning', 'label' => 'Receipt scanning switch',
                'state' => !empty($config['enabled']) ? 'verified' : 'pending',
                'detail' => !empty($config['enabled']) ? 'Authorized receipt scanning is enabled.' : 'Receipt scanning remains disabled in configuration.'],
            ['key' => 'last_ocr_test', 'label' => 'Last successful OCR test',
                'state' => $ocrTestCurrent ? 'verified' : 'pending',
                'detail' => $ocrTestCurrent ? 'The successful OCR test matches the active configuration.' : 'No successful OCR test is verified for the active configuration.'],
        ];
        $allVerified = count(array_filter($steps, static fn(array $step): bool => $step['state'] === 'verified')) === count($steps);
        $storageReady = ($storage['status'] ?? '') === 'CONFIGURED' && ($storage['outside_application'] ?? false);
        $databaseReady = $database['available'] && $database['required_tables'];
        $configurationReady = $projectConfigured && $configurationMatches && $credentialReady && $databaseReady && $storageReady;
        $readinessState = $allVerified ? 'READY'
            : (!$projectConfigured || ($credential['status'] ?? '') !== 'CONFIGURED' ? 'NOT_CONFIGURED'
                : (!$configurationMatches || !$databaseReady || !$storageReady || $authentication === 'FAILED' ? 'BLOCKED'
                    : ($configurationReady && $hasPriorOcrTest && !$ocrTestCurrent ? 'DEGRADED'
                        : ($authentication === 'VERIFIED' ? 'AUTHENTICATED' : 'CONFIGURED'))));

        return [
            'configuration' => ['enabled' => (bool) ($config['enabled'] ?? false), 'provider' => 'Google Cloud Vision',
                'feature' => 'DOCUMENT_TEXT_DETECTION', 'project_configured' => $projectConfigured,
                'project_matches' => $projectMatches, 'mode_valid' => $modeValid,
                'credential_status' => $credential['status'] ?? 'NOT_CONFIGURED',
                'credential_readable' => (bool) ($credential['readable'] ?? false),
                'credential_protected' => (bool) ($credential['protected_location'] ?? false)],
            'diagnostic' => ['last_status' => $config['last_test_status'] ?? null,
                'last_successful' => $config['last_diagnostic_at'] ?? null, 'last_failed' => $config['last_failed_test'] ?? null,
                'authentication_state' => $authentication, 'diagnostic_current' => $diagnosticCurrent,
                'error_category' => $config['last_test_error_category'] ?? null,
                'provider_capability' => $ocrTestCurrent ? 'VERIFIED' : 'UNVERIFIED',
                'actual_ocr_processing' => $ocrTestCurrent ? 'VERIFIED' : 'UNVERIFIED'],
            'readiness' => ['state' => $readinessState, 'steps' => $steps,
                'last_successful_ocr_test' => $ocrTestCurrent ? $config['last_ocr_successful_test'] : null],
            'database' => $database,
            'receipt_storage' => ['status' => $storage['status'], 'readable' => $storage['readable'],
                'writable' => $storage['writable'], 'outside_application' => $storage['outside_application']],
            'usage' => $usage, 'lifecycle_states' => $states,
            'failure_categories' => $failures, 'stale_incomplete' => $stale,
        ];
    }
}
