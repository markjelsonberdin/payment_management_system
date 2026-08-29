<?php
require 'modules/payment/database/db_connect.php';
$stmt = $pdo->query("SELECT * FROM ocr_results WHERE concern_id = 2");
print_r($stmt->fetch(PDO::FETCH_ASSOC));
foreach ($refRegexes as $regex) {
    if (preg_match_all($regex, $rawText, $matches)) {
        print_r($matches);
    } else {
        echo "Regex failed: $regex\n";
    }
}
