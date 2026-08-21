<?php
/**
 * SMS 2 - Payment Channel Service
 * Handles the logic for checking channel availability based on PayMongo capabilities and Admin settings.
 */

declare(strict_types=1);

require_once __DIR__ . '/PayMongoService.php';

class PaymentChannelService
{
    private PDO $pdo;
    private array $adminSettingsCache = [];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Get the active environment mode (test/live)
     */
    public function getActiveEnvironment(): string
    {
        $stmt = $this->pdo->query("SELECT setting_value FROM payment_db.payment_gateway_settings WHERE setting_key = 'gateway_mode'");
        return $stmt->fetchColumn() ?: 'test';
    }

    /**
     * Get admin channel settings for a specific environment
     */
    public function getAdminSettings(string $env): array
    {
        if (isset($this->adminSettingsCache[$env])) {
            return $this->adminSettingsCache[$env];
        }

        $prefix = ($env === 'live') ? 'live_' : 'test_';
        
        $stmt = $this->pdo->prepare("SELECT setting_key, setting_value FROM payment_db.payment_gateway_settings WHERE setting_key LIKE :prefix");
        $stmt->execute([':prefix' => $prefix . 'channel_%']);
        
        $settings = [
            'gcash' => false,
            'maya'  => false,
            'card'  => false,
            'qrph'  => false
        ];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $channelName = str_replace($prefix . 'channel_', '', $row['setting_key']);
            if (array_key_exists($channelName, $settings)) {
                $settings[$channelName] = ($row['setting_value'] === '1');
            }
        }
        
        // If test mode and not configured, assume defaults from master plan
        if ($env === 'test' && empty(array_filter($settings))) {
            $settings['gcash'] = true;
            $settings['maya'] = true;
            $settings['qrph'] = true;
        }

        $this->adminSettingsCache[$env] = $settings;
        return $settings;
    }

    /**
     * Evaluates the final statuses of all channels for the specified environment
     */
    public function getChannelStatuses(PayMongoService $paymongo, string $env): array
    {
        $adminSettings = $this->getAdminSettings($env);
        
        try {
            $providerCapabilities = $paymongo->getMerchantCapabilities();
        } catch (Exception $e) {
            $providerCapabilities = [];
        }

        $channels = ['gcash', 'maya', 'card', 'qrph'];
        $results = [];

        foreach ($channels as $channel) {
            // In test mode, PayMongo allows testing all channels regardless of what the capabilities API returns.
            if ($env === 'test') {
                $providerActive = true;
            } else {
                $providerActive = isset($providerCapabilities[$channel]) && $providerCapabilities[$channel] === 'active';
            }
            
            $adminEnabled = $adminSettings[$channel];

            if ($providerActive && $adminEnabled) {
                $status = 'AVAILABLE';
                $message = 'Available to students';
            } elseif ($providerActive && !$adminEnabled) {
                $status = 'DISABLED_BY_ADMIN';
                $message = 'Disabled by administrator';
            } elseif (!$providerActive && $adminEnabled) {
                $status = 'NOT_ACTIVE_IN_PAYMONGO';
                $message = 'Not enabled in PayMongo';
            } else {
                $status = 'UNAVAILABLE';
                $message = 'Unavailable';
            }

            $results[$channel] = [
                'provider_active' => $providerActive,
                'admin_enabled'   => $adminEnabled,
                'status'          => $status,
                'message'         => $message
            ];
        }

        return $results;
    }

    /**
     * Checks if a specific payment method is completely available (Active in PayMongo AND Enabled by Admin)
     */
    public function isChannelAvailable(PayMongoService $paymongo, string $env, string $channelCode): bool
    {
        $statuses = $this->getChannelStatuses($paymongo, $env);
        if (!isset($statuses[$channelCode])) {
            return false;
        }
        return $statuses[$channelCode]['status'] === 'AVAILABLE';
    }
}
