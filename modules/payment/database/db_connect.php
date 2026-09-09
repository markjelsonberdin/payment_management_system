<?php
// Payment owns financial records. Prefer PAYMENT_DB_* so its connection
// remains independent from the SMS2 Core authentication connection.
// The DB_* fallback preserves existing local installations during migration.
$host = getenv('PAYMENT_DB_HOST') ?: (getenv('DB_HOST') ?: '127.0.0.1');
$port = getenv('PAYMENT_DB_PORT') ?: (getenv('DB_PORT') ?: '3307');
$dbname = getenv('PAYMENT_DB_DATABASE') ?: (getenv('DB_DATABASE') ?: 'payment_db');
$username = getenv('PAYMENT_DB_USER') ?: (getenv('DB_USERNAME') ?: 'root');
$password = getenv('PAYMENT_DB_PASS') ?: (getenv('DB_PASSWORD') ?: '');
$charset = getenv('PAYMENT_DB_CHARSET') ?: 'utf8mb4';

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=$charset", $username, $password);
    
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
} catch(PDOException $e) {
    die("Database Connection failed: " . $e->getMessage());
}
?>
