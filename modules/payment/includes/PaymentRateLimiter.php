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
    private static function ensureTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS payment_rate_limits (
                throttle_key VARCHAR(128) NOT NULL PRIMARY KEY,
                ip_address VARCHAR(45) DEFAULT NULL,
                attempts INT NOT NULL DEFAULT 0,
                locked_until DATETIME DEFAULT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_payment_rate_limits_locked (locked_until),
                KEY idx_payment_rate_limits_ip (ip_address)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    /**
     * Throttle API requests without touching login lockout state.
     * 
     * @param string $actionKey Unique action identifier (e.g. 'checkout:user:123')
     * @param int $maxRequests Max requests allowed before locking
     * @param int $decaySeconds Seconds until the lock expires
     * @return bool True if allowed, false if rate limited (Too Many Requests).
     */
    public static function throttle(string $actionKey, int $maxRequests = 5, int $decaySeconds = 60): bool
    {
        $pdo = db();
        if (!$pdo) {
            return true;
        }

        self::ensureTable($pdo);
        
        $ip = function_exists('smsClientIp') ? smsClientIp() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $key = hash('sha256', 'api_payment:' . $actionKey . '|' . $ip);
        
        $stmt = $pdo->prepare('SELECT attempts, locked_until FROM payment_rate_limits WHERE throttle_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        
        if ($row && !empty($row['locked_until'])) {
            $untilTs = strtotime((string) $row['locked_until']);
            if ($untilTs !== false && $untilTs > time()) {
                return false;
            } else {
                $pdo->prepare('DELETE FROM payment_rate_limits WHERE throttle_key = ?')->execute([$key]);
                $row = false;
            }
        }
        
        $pdo->prepare(
            'INSERT INTO payment_rate_limits (throttle_key, ip_address, attempts, locked_until)
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
                'UPDATE payment_rate_limits
                 SET locked_until = DATE_ADD(NOW(), INTERVAL ? SECOND)
                 WHERE throttle_key = ?'
            )->execute([$decaySeconds, $key]);
            return false;
        }
        
        return true;
    }
}
