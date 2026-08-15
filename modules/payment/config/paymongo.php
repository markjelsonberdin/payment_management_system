<?php
/**
 * Centralized PayMongo Configuration
 * Loads environment variables and provides them securely.
 */

// If .env is not already loaded by a global bootstrap, load it here.
// Assuming your system has a way to load .env, but if it's basic PHP:
$envPath = __DIR__ . '/../.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) continue;
        
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $name = trim($parts[0]);
            $value = trim($parts[1], " \t\n\r\0\x0B\"'");
            
            if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
                putenv(sprintf('%s=%s', $name, $value));
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }
}

return [
    'env'            => getenv('PAYMONGO_ENV') ?: 'test',
    'public_key'     => getenv('PAYMONGO_PUBLIC_KEY'),
    'secret_key'     => getenv('PAYMONGO_SECRET_KEY'),
    'webhook_secret' => getenv('PAYMONGO_WEBHOOK_SECRET'),
];
