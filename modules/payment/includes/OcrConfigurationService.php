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
        if (!in_array($status, ['CONFIGURATION_VALID','AUTHENTICATION_VERIFIED','FAILED'], true)) throw new InvalidArgumentException('OCR_DIAGNOSTIC_STATUS_INVALID');
        if (!in_array($authentication, ['VERIFIED','FAILED','UNVERIFIED'], true)) throw new InvalidArgumentException('OCR_DIAGNOSTIC_AUTH_INVALID');
        if (!preg_match('/^[A-Z0-9_]{1,64}$/', $category)) throw new InvalidArgumentException('OCR_DIAGNOSTIC_CATEGORY_INVALID');
        $checkedAt = gmdate('c');
        $safe = ['status'=>$status,'authentication'=>$authentication,'error_category'=>$category,'provider_capability'=>'UNVERIFIED','actual_ocr_processing'=>'UNVERIFIED','checked_at'=>$checkedAt];
        return $this->mutations->execute($correlationId, $safe, [
            'action'=>'OCR_CONNECTION_DIAGNOSTIC_COMPLETED','module_key'=>'payment','entity_type'=>'ocr_configuration','entity_id'=>null,
            'detail'=>'Non-billable Google OCR configuration and authentication diagnostic completed.',
            'before_state'=>null,'after_state'=>$safe,'actor_user_id'=>$actor['id'],'actor_user_name'=>$actor['name'],'actor_role_key'=>$actor['role'],
            'actor_ip_address'=>function_exists('smsClientIp')?smsClientIp():null,'actor_user_agent'=>substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,255),
        ], function(PDO $pdo) use($safe,$status,$checkedAt): array {
            $q=$pdo->prepare('INSERT INTO payment_gateway_settings (setting_key,setting_value,description) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),description=VALUES(description)');
            foreach ([['ocr_last_test_status',$safe['status'],'Latest non-billable OCR diagnostic result'],['ocr_last_test_authentication',$safe['authentication'],'Latest OCR authentication state'],['ocr_last_test_error_category',$safe['error_category'],'Latest safe OCR diagnostic category'],[$status==='AUTHENTICATION_VERIFIED'?'ocr_last_successful_test':'ocr_last_failed_test',$checkedAt,'Latest OCR diagnostic timestamp']] as $row) $q->execute($row);
            return $safe;
        });
    }
}
