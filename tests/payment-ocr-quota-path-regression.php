<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') throw new RuntimeException('CLI only');
$root = dirname(__DIR__);
define('ROOT_PATH', $root);
require_once $root . '/modules/payment/includes/ocr/GoogleOCRService.php';

$checks = 0;
$assert = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    $checks++;
};

$previousToggle = getenv('SMS2_ENABLE_LEGACY_OCR');
putenv('SMS2_ENABLE_LEGACY_OCR=1');
$legacy = new GoogleOCRService();
foreach ([
    static fn() => $legacy->extractFromImage('synthetic bytes'),
    static fn() => $legacy->processReceipt(1, 'synthetic/path', 1),
] as $legacyCall) {
    try {
        $legacyCall();
        $assert(false, 'Legacy provider entry point must remain retired even when the old toggle is enabled.');
    } catch (LogicException $e) {
        $assert($e->getMessage() === 'LEGACY_OCR_SERVICE_RETIRED', 'Legacy calls fail with a stable retired-service category.');
    }
}
$previousToggle === false ? putenv('SMS2_ENABLE_LEGACY_OCR') : putenv('SMS2_ENABLE_LEGACY_OCR=' . $previousToggle);

$legacySource = file_get_contents($root . '/modules/payment/includes/ocr/GoogleOCRService.php');
$assert(is_string($legacySource) && !str_contains($legacySource, 'ImageAnnotatorClient'), 'Legacy service cannot instantiate a Vision client.');
$assert(!str_contains($legacySource, 'documentTextDetection('), 'Legacy service cannot issue a Vision request.');
$assert(!str_contains($legacySource, 'SMS2_ENABLE_LEGACY_OCR'), 'Legacy environment toggle cannot restore provider access.');

$providerFile = 'modules/payment/includes/ocr/GoogleVisionOcrService.php';
$visionFiles = [];
$excludedRoots = ['.git', 'scratch', 'tests', 'vendor'];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') continue;
    $relativePath = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    if (in_array(explode('/', $relativePath)[0], $excludedRoots, true)) continue;
    $source = file_get_contents($file->getPathname());
    if (is_string($source) && (str_contains($source, 'new ImageAnnotatorClient') || str_contains($source, 'documentTextDetection('))) {
        $visionFiles[] = $relativePath;
    }
}
$visionFiles = array_values(array_unique($visionFiles));
$assert($visionFiles === [$providerFile], 'Only GoogleVisionOcrService may instantiate/call the Vision document API.');

$processor = file_get_contents($root . '/modules/payment/includes/ocr/ReceiptOcrProcessor.php');
$scanRoute = file_get_contents($root . '/modules/payment/api/accounting/ocr-scan-concern.php');
$assert(str_contains($scanRoute, 'new ReceiptOcrProcessor') && str_contains($scanRoute, 'new OcrUsageGuardService'), 'Accounting scan route must construct the guarded processor.');
$reserve = strpos($processor, '$this->guard->begin(');
$mark = strpos($processor, '$this->guard->markProviderCalled(');
$call = strpos($processor, '$this->provider->extractDocumentText(');
$assert($reserve !== false && $mark !== false && $call !== false && $reserve < $mark && $mark < $call, 'Quota reservation and provider-call ledger transition must precede every active provider call.');
$assert(str_contains($processor, 'min(900,'), 'Application monthly quota remains capped at 900 units.');
$assert(str_contains($processor, 'cache_hit') && strpos($processor, 'cache_hit') < $call, 'Cache-hit handling returns before a provider request.');

echo "PASS: {$checks} OCR quota-path regression checks.\n";
