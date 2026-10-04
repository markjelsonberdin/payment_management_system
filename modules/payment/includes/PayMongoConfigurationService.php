<?php

declare(strict_types=1);

require_once __DIR__ . '/SchoolSalesCatalogMutationInfrastructure.php';

final class PayMongoConfigurationService
{
    private const MODES = ['test', 'live'];
    private const CHANNELS = ['gcash', 'maya', 'card', 'qrph'];

    public function __construct(
        private readonly PDO $paymentPdo,
        private readonly SchoolSalesCatalogMutationInfrastructure $mutations
    ) {
    }

    /** @param array<string,mixed> $input @param array{id:int,name:string,role:string} $actor */
    public function update(array $input, array $actor): array
    {
        if (array_key_exists('fee_policy', $input)) {
            throw new DomainException('FINANCIAL_SETTING_FORBIDDEN');
        }

        $mode = strtolower(trim((string) ($input['gateway_mode'] ?? '')));
        if (!in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException('GATEWAY_MODE_INVALID');
        }

        $channels = [];
        foreach (self::CHANNELS as $channel) {
            $channels[$channel] = $this->checkboxValue($input['channel_' . $channel] ?? null);
        }

        $correlationId = trim((string) ($input['correlation_id'] ?? ''));
        CatalogCorrelationId::assertValid($correlationId);
        $request = ['gateway_mode' => $mode, 'channels' => $channels];
        $before = $this->currentTechnicalState();
        $after = ['gateway_mode' => $mode, $mode . '_channels' => $channels];
        $modeChanged = ($before['gateway_mode'] ?? null) !== $mode;

        return $this->mutations->execute($correlationId, $request, [
            'action' => $modeChanged ? 'PAYMONGO_MODE_CHANGED' : 'PAYMONGO_CONFIGURATION_UPDATED',
            'module_key' => 'payment',
            'entity_type' => 'paymongo_configuration',
            'entity_id' => null,
            'detail' => $modeChanged
                ? 'PayMongo technical mode and channel configuration updated.'
                : 'PayMongo technical channel configuration updated.',
            'before_state' => $before,
            'after_state' => $after,
            'actor_user_id' => $actor['id'],
            'actor_user_name' => $actor['name'],
            'actor_role_key' => $actor['role'],
            'actor_ip_address' => function_exists('smsClientIp') ? smsClientIp() : null,
            'actor_user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ], function (PDO $pdo) use ($mode, $channels): array {
            $stmt = $pdo->prepare(
                'INSERT INTO payment_gateway_settings (setting_key, setting_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
            );
            $stmt->execute(['gateway_mode', $mode]);
            foreach ($channels as $channel => $enabled) {
                $stmt->execute([$mode . '_channel_' . $channel, $enabled ? '1' : '0']);
            }
            $stmt->execute(['paymongo_last_config_update', gmdate('c')]);
            return ['gateway_mode' => $mode, 'channels' => $channels];
        });
    }

    /** @param array{id:int,name:string,role:string} $actor */
    public function recordConnectionTest(string $correlationId, array $actor, string $mode, string $result): array
    {
        if (!in_array($mode, self::MODES, true)) throw new InvalidArgumentException('GATEWAY_MODE_INVALID');
        $allowed = ['CONNECTED', 'AUTHENTICATION_FAILED', 'CONFIGURATION_ERROR', 'NETWORK_ERROR', 'PROVIDER_ERROR', 'TIMEOUT'];
        if (!in_array($result, $allowed, true)) throw new InvalidArgumentException('CONNECTION_RESULT_INVALID');
        CatalogCorrelationId::assertValid($correlationId);
        $checkedAt = gmdate('c');

        return $this->mutations->execute($correlationId, ['mode' => $mode, 'result' => $result], [
            'action' => 'PAYMONGO_CONNECTION_TESTED',
            'module_key' => 'payment', 'entity_type' => 'paymongo_connection', 'entity_id' => null,
            'detail' => 'PayMongo non-financial connection test completed: ' . $result . '.',
            'before_state' => null,
            'after_state' => ['mode' => $mode, 'result' => $result, 'checked_at' => $checkedAt],
            'actor_user_id' => $actor['id'], 'actor_user_name' => $actor['name'],
            'actor_role_key' => $actor['role'],
            'actor_ip_address' => function_exists('smsClientIp') ? smsClientIp() : null,
            'actor_user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ], function (PDO $pdo) use ($result, $checkedAt): array {
            $stmt = $pdo->prepare(
                'INSERT INTO payment_gateway_settings (setting_key, setting_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
            );
            $stmt->execute(['paymongo_last_test_status', $result]);
            $stmt->execute([$result === 'CONNECTED' ? 'paymongo_last_successful_test' : 'paymongo_last_failed_test', $checkedAt]);
            return ['status' => $result, 'checked_at' => $checkedAt];
        });
    }

    /** @return array<string,mixed> */
    public function currentTechnicalState(): array
    {
        $stmt = $this->paymentPdo->query("SELECT setting_key, setting_value FROM payment_gateway_settings WHERE setting_key = 'gateway_mode' OR setting_key LIKE 'test_channel_%' OR setting_key LIKE 'live_channel_%'");
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $state = ['gateway_mode' => $rows['gateway_mode'] ?? null];
        foreach (self::MODES as $mode) {
            foreach (self::CHANNELS as $channel) {
                $state[$mode . '_channels'][$channel] = ($rows[$mode . '_channel_' . $channel] ?? '1') === '1';
            }
        }
        return $state;
    }

    private function checkboxValue(mixed $value): bool
    {
        return in_array($value, [1, '1', true, 'on'], true);
    }

    public static function normalizeWebhookUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') return '';
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) return '';
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path = '/' . ltrim((string) ($parts['path'] ?? ''), '/');
        return strtolower((string) $parts['scheme']) . '://' . strtolower((string) $parts['host']) . $port . rtrim($path, '/');
    }
}
