<?php

declare(strict_types=1);

require_once __DIR__ . '/SchoolSalesCatalogMutationInfrastructure.php';

final class OcrConfigurationService
{
    private const DEFAULTS = [
        'ocr_enabled' => '0',
        'ocr_project_id' => '',
        'ocr_mode' => 'DOCUMENT_TEXT_DETECTION',
        'ocr_monthly_limit' => '900',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly SchoolSalesCatalogMutationInfrastructure $mutations
    ) {}

    /** @return array<string,mixed> */
    public function get(): array
    {
        $stmt = $this->pdo->query("SELECT setting_key, setting_value FROM payment_gateway_settings WHERE setting_key LIKE 'ocr_%'");
        $settings = array_replace(self::DEFAULTS, $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
        return [
            'enabled' => $settings['ocr_enabled'] === '1',
            'project_id' => (string) $settings['ocr_project_id'],
            'mode' => (string) $settings['ocr_mode'],
            'monthly_limit' => (int) $settings['ocr_monthly_limit'],
            'last_successful_test' => $settings['ocr_last_successful_test'] ?? null,
            'last_failed_test' => $settings['ocr_last_failed_test'] ?? null,
            'last_test_status' => $settings['ocr_last_test_status'] ?? null,
            'last_test_authentication' => $settings['ocr_last_test_authentication'] ?? null,
            'last_test_error_category' => $settings['ocr_last_test_error_category'] ?? null,
            'last_diagnostic_at' => $settings['ocr_last_diagnostic_at'] ?? null,
            'last_diagnostic_config_fingerprint' => $settings['ocr_last_diagnostic_config_fingerprint'] ?? null,
            'last_ocr_successful_test' => $settings['ocr_last_ocr_test_successful_at'] ?? null,
            'last_ocr_test_config_fingerprint' => $settings['ocr_last_ocr_test_config_fingerprint'] ?? null,
            'last_configuration_update' => $settings['ocr_last_config_update'] ?? null,
        ];
    }

    /** @param array<string,mixed> $input @param array{id:int,name:string,role:string} $actor */
    public function update(array $input, array $actor): array
    {
        $enabled = in_array($input['enabled'] ?? null, [1, '1', true, 'on'], true);
        $projectId = strtolower(trim((string) ($input['project_id'] ?? '')));
        $mode = strtoupper(trim((string) ($input['mode'] ?? '')));
        $limit = filter_var($input['monthly_limit'] ?? null, FILTER_VALIDATE_INT);
        if ($projectId !== '' && !preg_match('/^[a-z][a-z0-9-]{4,28}[a-z0-9]$/', $projectId)) {
            throw new InvalidArgumentException('OCR_PROJECT_ID_INVALID');
        }
        if ($mode !== 'DOCUMENT_TEXT_DETECTION') throw new InvalidArgumentException('OCR_MODE_INVALID');
        if ($limit === false || $limit < 1 || $limit > 900) throw new InvalidArgumentException('OCR_LIMIT_INVALID');
        if ($enabled && $projectId === '') throw new InvalidArgumentException('OCR_PROJECT_REQUIRED');
        $correlationId = trim((string) ($input['correlation_id'] ?? ''));
        CatalogCorrelationId::assertValid($correlationId);
        $before = $this->get();
        $after = ['enabled' => $enabled, 'project_id' => $projectId, 'mode' => $mode, 'monthly_limit' => $limit];

        return $this->mutations->execute($correlationId, $after, [
            'action' => 'OCR_CONFIGURATION_UPDATED', 'module_key' => 'payment',
            'entity_type' => 'ocr_configuration', 'entity_id' => null,
            'detail' => 'Google Cloud Vision OCR technical configuration updated.',
            'before_state' => $before, 'after_state' => $after,
            'actor_user_id' => $actor['id'], 'actor_user_name' => $actor['name'],
            'actor_role_key' => $actor['role'],
            'actor_ip_address' => function_exists('smsClientIp') ? smsClientIp() : null,
            'actor_user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ], function (PDO $pdo) use ($enabled, $projectId, $mode, $limit): array {
            $stmt = $pdo->prepare('INSERT INTO payment_gateway_settings (setting_key, setting_value, description) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), description = VALUES(description)');
            foreach ([
                ['ocr_enabled', $enabled ? '1' : '0', 'Enable Google Cloud Vision OCR after deployment validation'],
                ['ocr_project_id', $projectId, 'Non-secret Google Cloud project ID'],
                ['ocr_mode', $mode, 'Single billable OCR feature'],
                ['ocr_monthly_limit', (string) $limit, 'Application OCR unit ceiling per Asia/Manila month'],
                ['ocr_last_config_update', gmdate('c'), 'Latest OCR configuration update'],
            ] as $row) $stmt->execute($row);
            return ['enabled' => $enabled, 'project_id' => $projectId, 'mode' => $mode, 'monthly_limit' => $limit];
        });
    }
    /** @param array{id:int,name:string,role:string} $actor @param array<string,mixed> $diagnostic */
    public function recordDiagnostic(string $correlationId, array $actor, array $diagnostic): array
    {
        CatalogCorrelationId::assertValid($correlationId);
        $status = (string) ($diagnostic['status'] ?? 'FAILED');
        $authentication = (string) ($diagnostic['authentication'] ?? 'UNVERIFIED');
        $category = (string) ($diagnostic['error_category'] ?? 'NONE');
        $fingerprint = (string) ($diagnostic['configuration_fingerprint'] ?? '');
        if (!in_array($status, ['CONFIGURATION_VALID','AUTHENTICATION_VERIFIED','FAILED'], true)) throw new InvalidArgumentException('OCR_DIAGNOSTIC_STATUS_INVALID');
        if (!in_array($authentication, ['VERIFIED','FAILED','UNVERIFIED'], true)) throw new InvalidArgumentException('OCR_DIAGNOSTIC_AUTH_INVALID');
        if (!preg_match('/^[A-Z0-9_]{1,64}$/', $category)) throw new InvalidArgumentException('OCR_DIAGNOSTIC_CATEGORY_INVALID');
        if ($fingerprint !== '' && !preg_match('/^[a-f0-9]{64}$/', $fingerprint)) throw new InvalidArgumentException('OCR_DIAGNOSTIC_FINGERPRINT_INVALID');
        $checkedAt = gmdate('c');
        $safe = ['status'=>$status,'authentication'=>$authentication,'error_category'=>$category,'provider_capability'=>'UNVERIFIED','actual_ocr_processing'=>'UNVERIFIED','checked_at'=>$checkedAt];
        return $this->mutations->execute($correlationId, $safe, [
            'action'=>'OCR_CONNECTION_DIAGNOSTIC_COMPLETED','module_key'=>'payment','entity_type'=>'ocr_configuration','entity_id'=>null,
            'detail'=>'Non-billable Google OCR configuration and authentication diagnostic completed.',
            'before_state'=>null,'after_state'=>$safe,'actor_user_id'=>$actor['id'],'actor_user_name'=>$actor['name'],'actor_role_key'=>$actor['role'],
            'actor_ip_address'=>function_exists('smsClientIp')?smsClientIp():null,'actor_user_agent'=>substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,255),
        ], function(PDO $pdo) use($safe,$status,$checkedAt,$fingerprint): array {
            $q=$pdo->prepare('INSERT INTO payment_gateway_settings (setting_key,setting_value,description) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),description=VALUES(description)');
            foreach ([['ocr_last_test_status',$safe['status'],'Latest non-billable OCR diagnostic result'],['ocr_last_test_authentication',$safe['authentication'],'Latest OAuth authentication state'],['ocr_last_test_error_category',$safe['error_category'],'Latest safe OCR diagnostic category'],['ocr_last_diagnostic_at',$checkedAt,'Latest non-billable OCR diagnostic timestamp'],['ocr_last_diagnostic_config_fingerprint',$fingerprint,'Configuration marker for the latest non-billable diagnostic']] as $row) $q->execute($row);
            if ($status !== 'AUTHENTICATION_VERIFIED') $q->execute(['ocr_last_failed_test',$checkedAt,'Latest failed non-billable OCR diagnostic timestamp']);
            return $safe;
        });
    }

    /** Record only after a fresh, successful provider call in the authorized receipt scan flow. */
    public function recordSuccessfulOcrEvidence(string $correlationId, array $actor, string $providerRequestId, string $observedFingerprint): array
    {
        CatalogCorrelationId::assertValid($correlationId);
        CatalogCorrelationId::assertValid($providerRequestId);
        if (!preg_match('/^[a-f0-9]{64}$/', $observedFingerprint)) {
            throw new InvalidArgumentException('OCR_SUCCESS_FINGERPRINT_INVALID');
        }
        $before = $this->get();
        if (!$before['enabled'] || $before['project_id'] === '' || $before['mode'] !== 'DOCUMENT_TEXT_DETECTION') {
            throw new DomainException('OCR_SUCCESS_CONFIGURATION_CHANGED');
        }
        require_once __DIR__ . '/ocr/GoogleVisionOcrService.php';
        $provider = new GoogleVisionOcrService();
        $currentFingerprint = $provider->configurationFingerprint(
            $before['project_id'], $before['mode'], $before['monthly_limit']
        );
        if (!is_string($currentFingerprint) || !hash_equals($currentFingerprint, $observedFingerprint)) {
            throw new DomainException('OCR_SUCCESS_CONFIGURATION_CHANGED');
        }
        $evidence=$this->pdo->prepare("SELECT l.request_id FROM ocr_usage_ledger l JOIN ocr_scan_attempts a ON a.request_id=l.request_id WHERE l.request_id=? AND l.provider='google_cloud_vision' AND l.feature='DOCUMENT_TEXT_DETECTION' AND l.provider_called=1 AND l.lifecycle_state='SUCCEEDED' AND l.completed_at IS NOT NULL AND a.extraction_status='SUCCEEDED' AND a.completed_at IS NOT NULL LIMIT 1");
        $evidence->execute([$providerRequestId]);
        if (!$evidence->fetchColumn()) throw new DomainException('OCR_SUCCESS_PROVIDER_EVIDENCE_REQUIRED');
        $checkedAt = gmdate('c');
        $safe = ['status'=>'VERIFIED','feature'=>'DOCUMENT_TEXT_DETECTION','verified_at'=>$checkedAt,
            'provider_request_id'=>$providerRequestId,'configuration_fingerprint'=>$currentFingerprint];
        return $this->mutations->execute($correlationId, $safe, [
            'action'=>'OCR_PROVIDER_PROCESSING_VERIFIED','module_key'=>'payment','entity_type'=>'ocr_configuration','entity_id'=>null,
            'detail'=>'A fresh authorized Google Vision OCR provider request completed successfully; processing evidence was recorded.',
            'before_state'=>null,'after_state'=>$safe,'actor_user_id'=>$actor['id'],'actor_user_name'=>$actor['name'],'actor_role_key'=>$actor['role'],
            'actor_ip_address'=>function_exists('smsClientIp')?smsClientIp():null,'actor_user_agent'=>substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,255),
        ], function(PDO $pdo) use ($checkedAt, $currentFingerprint): array {
            $upsert = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
                ? 'INSERT INTO payment_gateway_settings (setting_key,setting_value,description) VALUES (?,?,?) ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value,description=excluded.description'
                : 'INSERT INTO payment_gateway_settings (setting_key,setting_value,description) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),description=VALUES(description)';
            $q=$pdo->prepare($upsert);
            $q->execute(['ocr_last_ocr_test_successful_at',$checkedAt,'Latest completed authorized Google Vision OCR processing timestamp']);
            $q->execute(['ocr_last_ocr_test_config_fingerprint',$currentFingerprint,'Non-secret configuration marker for latest successful Google Vision OCR processing']);
            return ['status'=>'VERIFIED','verified_at'=>$checkedAt,'feature'=>'DOCUMENT_TEXT_DETECTION'];
        });
    }
}
