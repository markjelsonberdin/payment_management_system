<?php
/**
 * SMS 2 - Payment Module API Rate Limiter
 * Protects PayMongo checkout endpoints from spam to prevent API abuse.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/security.php';

class PaymentRateLimiter
{
    /**
     * Throttle API requests using the existing login_throttles infrastructure.
     * 
     * @param string $actionKey Unique action identifier (e.g. 'checkout:user:123')
     * @param int $maxRequests Max requests allowed before locking
     * @param int $decaySeconds Seconds until the lock expires
     * @return bool True if allowed, false if rate limited (Too Many Requests).
     */
    public static function throttle(string $actionKey, int $maxRequests = 5, int $decaySeconds = 60): bool
    {
        if (function_exists('smsEnsureLoginThrottleTables')) {
            smsEnsureLoginThrottleTables();
        }

        $pdo = db();
        if (!$pdo) {
            return true;
        }
        
        $ip = function_exists('smsClientIp') ? smsClientIp() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $key = hash('sha256', 'api_payment:' . $actionKey . '|' . $ip);
        
        $stmt = $pdo->prepare('SELECT attempts, locked_until FROM login_throttles WHERE throttle_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        
        if ($row && !empty($row['locked_until'])) {
            $untilTs = strtotime((string) $row['locked_until']);
            if ($untilTs !== false && $untilTs > time()) {
                return false;
            } else {
                $pdo->prepare('DELETE FROM login_throttles WHERE throttle_key = ?')->execute([$key]);
                $row = false;
            }
        }
        
        $pdo->prepare(
            'INSERT INTO login_throttles (throttle_key, ip_address, attempts, locked_until)
             VALUES (?, ?, 1, NULL)
             ON DUPLICATE KEY UPDATE
                attempts = attempts + 1,
                ip_address = VALUES(ip_address)'
        )->execute([$key, $ip]);
        
        $stmt->execute([$key]);
        $freshRow = $stmt->fetch();
        $attempts = (int) ($freshRow['attempts'] ?? 1);
        
        if ($attempts > $maxRequests) {
            $pdo->prepare(
                'UPDATE login_throttles
                 SET locked_until = DATE_ADD(NOW(), INTERVAL ? SECOND)
                 WHERE throttle_key = ?'
            )->execute([$decaySeconds, $key]);
            return false;
        }
        
        return true;
    }
}
