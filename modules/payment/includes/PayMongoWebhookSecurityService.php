<?php
/**
 * PayMongo Webhook Security Service
 * 
 * Validates incoming webhooks to ensure they are authentic and not replay attacks.
 */
class PayMongoWebhookSecurityService {
    private $pdo;
    private $webhookSecret;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        // Load secret from config (assuming paymongo config is loaded)
        $config = require __DIR__ . '/../config/paymongo.php';
        $this->webhookSecret = $config['webhook_secret'];
    }

    /**
     * Verifies the cryptographic signature of the webhook.
     */
    public function verifySignature($signatureHeader, $rawPayload) {
        if (empty($this->webhookSecret)) {
            throw new Exception("Webhook secret is not configured.");
        }

        if (empty($signatureHeader)) {
            throw new Exception("Missing Paymongo-Signature header.");
        }

        // Parse header: t=1603204910,te=test_sig,li=live_sig
        $parts = explode(',', $signatureHeader);
        $timestamp = '';
        $testSignature = '';
        $liveSignature = '';

        foreach ($parts as $part) {
            $kv = explode('=', $part, 2);
            if (count($kv) !== 2) continue;
            $key = trim($kv[0]);
            $value = trim($kv[1]);

            if ($key === 't') $timestamp = $value;
            if ($key === 'te') $testSignature = $value;
            if ($key === 'li') $liveSignature = $value;
        }

        if (empty($timestamp)) {
            throw new Exception("Invalid signature format: Missing timestamp.");
        }

        // Replay attack protection (e.g., reject if older than 5 minutes)
        if (abs(time() - (int)$timestamp) > 300) {
            throw new Exception("Webhook request is too old (possible replay attack).");
        }

        // Generate HMAC SHA256
        $signaturePayload = $timestamp . '.' . $rawPayload;
        $expectedSignature = hash_hmac('sha256', $signaturePayload, $this->webhookSecret);

        // Compare using hash_equals to prevent timing attacks
        $isValidTest = !empty($testSignature) && hash_equals($expectedSignature, $testSignature);
        $isValidLive = !empty($liveSignature) && hash_equals($expectedSignature, $liveSignature);

        if (!$isValidTest && !$isValidLive) {
            throw new Exception("Invalid webhook signature. Request forged!");
        }

        return true;
    }

    /**
     * Verifies that the payment hasn't already been processed (Idempotency).
     */
    public function isDuplicate($paymentId) {
        $stmt = $this->pdo->prepare("SELECT payment_status FROM payments WHERE payment_id = :pid");
        $stmt->execute([':pid' => $paymentId]);
        $status = $stmt->fetchColumn();

        if ($status && $status !== 'Pending') {
            return true; // Already verified or failed
        }
        return false;
    }
}
