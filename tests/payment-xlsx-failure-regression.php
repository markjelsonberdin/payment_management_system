<?php
/** Test-only namespace doubles run the production source without injection hooks. */
namespace XlsxFailureTest;
use RuntimeException;
use Throwable;
$scenario = $argv[1] ?? 'success';
$tmp = null; $headers = []; $logs = []; $entries = []; $failure = null;
function tempnam($dir, $prefix) { global $scenario, $tmp; return $scenario === 'temp' ? false : ($tmp = \tempnam($dir, $prefix)); }
function header($value) { global $headers; $headers[] = $value; }
function error_log($value) { global $logs; $logs[] = $value; return true; }
function filesize($path) { global $scenario; return $scenario === 'size' ? false : \filesize($path); }
function readfile($path) { global $scenario; if ($scenario === 'readthrow') throw new RuntimeException('Injected transport exception'); return $scenario === 'read' ? false : \filesize($path); }
class ZipArchive {
    const OVERWRITE = 8;
    public function open($path, $flags) { global $scenario; return $scenario === 'open' ? 9 : true; }
    public function addFromString($name, $xml) { global $scenario, $entries; $entries[$name] = $xml; return $scenario !== 'entry'.count($entries); }
    public function close() { global $scenario, $tmp; if ($scenario === 'close') return false; \file_put_contents($tmp, $scenario === 'empty' ? '' : 'fake zip'); return true; }
}
register_shutdown_function(function () use (&$tmp, &$headers, &$logs, &$entries, &$failure, $scenario) {
    $transport = in_array($scenario, ['read', 'readthrow'], true);
    $delivered = $scenario === 'success' || $transport;
    $ok = ($tmp === null || !\file_exists($tmp)) && ($delivered ? count($headers) === 4 && $failure === null : !$headers && $failure !== null);
    if ($transport) $ok = $ok && str_contains(implode(' ', $logs), 'DOWNLOAD TRANSPORT FAILURE');
    if ($scenario === 'success') {
        $xml = $entries['xl/worksheets/sheet1.xml'];
        $ok = $ok && !str_contains($xml, '<f>') && substr_count($xml, 't="inlineStr"') === 4;
    }
    echo json_encode(['scenario'=>$scenario,'pass'=>$ok,'cleaned'=>$tmp === null || !\file_exists($tmp),'headers'=>count($headers),'package_hashes'=>array_map(static fn($xml)=>hash('sha256',$xml),$entries),'failure'=>$failure,'logs'=>$logs]).PHP_EOL;
    if (!$ok) exit(1);
});
$source = \file_get_contents(($argv[2] ?? '') === 'baseline' ? __DIR__.'/benchmarks/batch16-writer-before.txt' : __DIR__.'/../modules/payment/includes/SimpleXlsxWriter.php');
eval('namespace XlsxFailureTest; use RuntimeException; use Throwable; '.substr($source, 5));
try { SimpleXlsxWriter::download('test.xlsx', [['=1', '+2', '-3', '@4']]); }
catch (Throwable $e) { $failure = $e->getMessage(); }
