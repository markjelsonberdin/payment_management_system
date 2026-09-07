<?php
require 'c:\xampp\htdocs\SMS2_system\config\config.php';
require 'c:\xampp\htdocs\SMS2_system\modules\payment\includes\ocr\GoogleOCRService.php';

try {
    $ocr = new GoogleOCRService();
    $method = new ReflectionMethod('GoogleOCRService', 'callGoogleVision');
    $method->setAccessible(true);
    $result = $method->invoke($ocr, 'test image content');
    echo "SUCCESS: " . substr((string)$result, 0, 100);
} catch (Exception $e) {
    echo "EXCEPTION: " . $e->getMessage();
} catch (Error $e) {
    echo "ERROR: " . $e->getMessage();
}
