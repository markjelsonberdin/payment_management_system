<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') throw new RuntimeException('CLI only');
$base = dirname(__DIR__) . '/modules/payment/database/migrations/';
$files = [
 'preflight' => $base . 'mis5b-ocr-foundation-preflight.sql',
 'migration' => $base . 'mis5b-ocr-foundation-migration.sql',
 'validation' => $base . 'mis5b-ocr-foundation-validation.sql',
 'rollback' => $base . 'mis5b-ocr-foundation-rollback.sql',
 'note' => $base . 'mis5b-ocr-foundation-schema-impact.md',
];
$c=0; function ok5(bool $v,string $m):void{global $c;$c++;if(!$v)throw new RuntimeException($m);}
foreach($files as $path) ok5(is_file($path) && filesize($path)>0, 'Missing artifact: '.$path);
$p=file_get_contents($files['preflight']); $m=file_get_contents($files['migration']);
$v=file_get_contents($files['validation']); $r=file_get_contents($files['rollback']); $n=file_get_contents($files['note']);
ok5(str_contains($p,"int(10) unsigned"),'Preflight verifies exact concern type');
ok5(str_contains($p,"partial/existing MIS-5B schema"),'Preflight rejects partial state');
foreach(['ocr_usage_months','ocr_usage_ledger','ocr_scan_attempts','ocr_image_cache'] as $table) ok5(str_contains($m,'CREATE TABLE '.$table),$table.' missing');
ok5(str_contains($m,'reserved_units+consumed_units<=configured_limit'),'Quota invariant missing');
ok5(str_contains($m,'UNIQUE KEY uq_ocr_usage_idempotency (idempotency_key)'),'Durable idempotency missing');
ok5(str_contains($m,'PRIMARY KEY (image_sha256,provider,feature,feature_version)'),'Version-aware cache key missing');
ok5(str_contains($m,'normalized_text MEDIUMTEXT'),'Reprocessable OCR text missing');
ok5(str_contains($m,'cache_source_attempt_id'),'Cache provenance missing');
ok5(str_contains($m,"Core user snapshot; no cross-database FK"),'Core snapshot boundary missing');
ok5(!str_contains($m,'ALTER TABLE payment_concerns') && !str_contains($m,'ALTER TABLE ocr_results'),'Historical tables must remain unchanged');
ok5(str_contains($v,'atomic reservation') || str_contains($v,'899/900'),'Runtime concurrency validation reminder missing');
$expected=['ocr_image_cache','ocr_scan_attempts','ocr_usage_ledger','ocr_usage_months'];
$positions=array_map(fn($t)=>strpos($r,'DROP TABLE IF EXISTS '.$t),$expected);
ok5(!in_array(false,$positions,true) && $positions==array_values($positions) && $positions[0]<$positions[1] && $positions[1]<$positions[2] && $positions[2]<$positions[3],'Rollback order invalid');
ok5(str_contains($n,'SELECT it FOR UPDATE'),'Atomic reservation algorithm missing');
ok5(str_contains($n,'No Google Vision request is needed'),'No-live-call test boundary missing');
echo "PASS: $c MIS-5B OCR database artifact checks.\n";
