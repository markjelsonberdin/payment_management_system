<?php
/**
 * Payment Module Environment Loader
 * Parses the .env file specific to the Payment Module and loads it into $_ENV / getenv().
 * 
 * IMPORTANT: This file should not be publicly accessible. Ensure your web server 
 * configuration denies access to .env files.
 */

if (!function_exists('payment_load_env')) {
    function payment_load_env($filePath) {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return false; // Silently fail if no .env file
        }
        
        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return false;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            
            // Skip empty lines and comments
            if (empty($line) || strpos($line, '#') === 0 || strpos($line, ';') === 0) {
                continue;
            }
            
            // Extract Name and Value
            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
                $name = trim($parts[0]);
                // Remove surrounding quotes if they exist
                $value = trim($parts[1], " \t\n\r\0\x0B\"'");
                
                // Do not overwrite existing environment variables
                // Wait, if it was already set by a previous require, we might want to overwrite it if it's explicitly loaded again?
                // Actually, standard behavior is not to overwrite.
                if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
                    putenv(sprintf('%s=%s', $name, $value));
                    $_ENV[$name] = $value;
                    $_SERVER[$name] = $value;
                }
            }
        }
        
        return true;
    }
}
