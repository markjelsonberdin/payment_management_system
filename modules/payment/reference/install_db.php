<?php
/**
 * One-click Database Installer for HostForge Deployment
 */
require_once 'config/config.php';

echo "<h1>HostForge Database Installer</h1>";

try {
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('DB_PORT') ?: '3306';
    $dbname = getenv('DB_DATABASE'); // Should be hf_db_r2moxxqg
    $username = getenv('DB_USERNAME') ?: 'root';
    $password = getenv('DB_PASSWORD') ?: '';

    if (!$dbname) {
        die("<h3>Error: DB_DATABASE environment variable not set. Please set your HostForge credentials in the Environment Variables tab.</h3>");
    }

    echo "Connecting to HostForge Database: <strong>$host</strong><br>";
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "<span style='color:green;'>Connected successfully!</span><br><br>";

    // Import SMS2_DB
    echo "Importing sms2_db tables...<br>";
    $sms2_sql = file_get_contents(__DIR__ . '/database/sms2_db.sql');
    if ($sms2_sql) {
        $pdo->exec($sms2_sql);
        echo "<span style='color:green;'>SMS2 tables imported successfully!</span><br><br>";
    } else {
        echo "<span style='color:red;'>Could not read sms2_db.sql</span><br><br>";
    }

    // Import PAYMENT_DB
    echo "Importing payment_db tables...<br>";
    $payment_sql = file_get_contents(__DIR__ . '/modules/payment/database/payment_db.sql');
    if ($payment_sql) {
        $pdo->exec($payment_sql);
        echo "<span style='color:green;'>Payment tables imported successfully!</span><br><br>";
    } else {
        echo "<span style='color:red;'>Could not read payment_db.sql</span><br><br>";
    }

    echo "<h3>✅ Database Migration Complete!</h3>";
    echo "<p>You can now use the system. Please delete this file (install_db.php) for security purposes.</p>";

} catch (PDOException $e) {
    echo "<h3>Database Error:</h3><pre>" . $e->getMessage() . "</pre>";
} catch (Exception $e) {
    echo "<h3>Error:</h3><pre>" . $e->getMessage() . "</pre>";
}
?>
