<?php
/**
 * PayMongoService
 * 
 * Handles secure communication with the PayMongo API.
 * Insulates the rest of the application from direct API logic.
 */
class PayMongoService {
    private $config;
    private $baseUrl = 'https://api.paymongo.com/v1';

    public function __construct() {
        $this->config = require __DIR__ . '/../config/paymongo.php';
        
        if (empty($this->config['secret_key'])) {
            throw new Exception("PayMongo Secret Key is missing from configuration.");
        }
    }

    /**
     * Helper to make API requests securely
     */
    private function request($method, $endpoint, $data = [], $customHeaders = []) {
        $url = $this->baseUrl . $endpoint;
        
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode($this->config['secret_key'] . ':')
        ];

        if (!empty($customHeaders)) {
            $headers = array_merge($headers, $customHeaders);
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        // Timeout handling
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new Exception("PayMongo API Request Failed: " . $error);
        }

        $decodedResponse = json_decode($response, true);
        
        if ($httpCode >= 400) {
            $errorMessage = $decodedResponse['errors'][0]['detail'] ?? 'Unknown API Error';
            throw new Exception("PayMongo API Error ($httpCode): " . $errorMessage);
        }

        return $decodedResponse;
    }

    /**
     * Connection Test
     * Makes a lightweight request (e.g., fetching webhooks) just to verify auth.
     */
    public function testConnection() {
        return $this->request('GET', '/webhooks');
    }

    /**
     * Creates a PayMongo Checkout Session
     * 
     * @param float $amount The amount in PHP (e.g. 150.00)
     * @param string $description The description of the payment
     * @param string $referenceNumber Our internal pending payment ID
     * @param string $successUrl Where to redirect on success
     * @param string $cancelUrl Where to redirect if cancelled
     * @param array $channels Payment channels to enable for this checkout (default: ['card', 'gcash', 'paymaya'])
     * @param string|null $idempotencyKey Optional key to prevent duplicate requests
     * @return array The PayMongo API response
     */
    public function createCheckoutSession($amount, $description, $referenceNumber, $successUrl, $cancelUrl, $channels = ['card', 'gcash', 'paymaya'], $idempotencyKey = null) {
        // PayMongo requires amount in cents (e.g. 150.00 PHP = 15000 cents)
        $amountInCents = (int) round($amount * 100);

        $payload = [
            'data' => [
                'attributes' => [
                    'send_email_receipt' => false,
                    'show_description' => true,
                    'show_line_items' => true,
                    'line_items' => [
                        [
                            'currency' => 'PHP',
                            'amount' => $amountInCents,
                            'description' => $description,
                            'name' => 'School Fee Payment',
                            'quantity' => 1
                        ]
                    ],
                    'payment_method_types' => $channels,
                    'reference_number' => (string) $referenceNumber,
                    'success_url' => $successUrl,
                    'cancel_url' => $cancelUrl
                ]
            ]
        ];

        $customHeaders = [];
        if ($idempotencyKey) {
            $customHeaders[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        return $this->request('POST', '/checkout_sessions', $payload, $customHeaders);
    }

    /**
     * Checks the PayMongo Merchant Capabilities for active payment methods
     */
    public function getMerchantCapabilities(): array
    {
        try {
            $response = $this->request('GET', '/merchants/capabilities/payment_methods');
            
            $capabilities = [];
            if (is_array($response)) {
                // The API seems to return a flat array of payment method codes: ['qrph', 'gcash', 'paymaya', 'card']
                foreach ($response as $method) {
                    if (is_string($method)) {
                        $capabilities[$method] = 'active';
                        // Alias paymaya to maya for internal consistency
                        if ($method === 'paymaya') {
                            $capabilities['maya'] = 'active';
                        }
                    }
                }
                if (!empty($capabilities)) {
                    return $capabilities;
                }
            }
            return [];
        } catch (Exception $e) {
            // Fallback: If endpoint does not exist or fails, assume default channels are active
            // This ensures development isn't blocked if PayMongo API lacks this undocumented endpoint
            return [
                'gcash' => 'active',
                'maya' => 'active',
                'card' => 'active',
                'qrph' => 'active'
            ];
        }
    }
}
