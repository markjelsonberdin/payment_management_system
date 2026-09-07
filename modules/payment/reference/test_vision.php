<?php
require 'c:\xampp\htdocs\SMS2_system\config\config.php';
require 'c:\xampp\htdocs\SMS2_system\vendor\autoload.php';
use Google\Cloud\Vision\V1\ImageAnnotatorClient;

$envPath = 'c:\xampp\htdocs\SMS2_system\modules\payment\.env';
$credentialsPath = 'c:\xampp\htdocs\SMS2_system\secure-config\google-credentials.json';
if (file_exists($envPath)) {
    $envLines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($envLines as $line) {
        $line = trim($line);
        if (strpos($line, '#') === 0) continue;
        
        $parts = explode('=', $line, 2);
        if (count($parts) === 2 && trim($parts[0]) === 'GOOGLE_APPLICATION_CREDENTIALS') {
            $credentialsPath = trim($parts[1], '"\' ');
            break;
        }
    }
}

echo "Path: $credentialsPath\n";
echo "Exists: " . (file_exists($credentialsPath) ? "Yes" : "No") . "\n";

try {
    $client = new ImageAnnotatorClient([
        'credentials' => $credentialsPath
    ]);
    echo "Client created successfully.\n";
} catch (Exception $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n";
} catch (Error $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
