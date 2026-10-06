<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') throw new RuntimeException('CLI only');
$s=file_get_contents(dirname(__DIR__).'/modules/payment/includes/ocr/OcrUsageGuardService.php');$n=0;
function guardCheck(bool $v,string $m):void{global $n;$n++;if(!$v)throw new RuntimeException($m);}
guardCheck(str_contains($s,"new DateTimeZone('Asia/Manila')"),'Asia/Manila month required');
guardCheck(str_contains($s,'SELECT * FROM ocr_usage_months WHERE billing_month=? FOR UPDATE'),'Monthly row lock required');
guardCheck(str_contains($s,'reserved_units+1'),'Atomic reservation increment required');
guardCheck(str_contains($s,"reserved_units'] + (int)\$month['consumed_units']"),'Capacity uses reserved plus consumed');
guardCheck(str_contains($s,"hash('sha256', implode('|',"),'Server-derived idempotency required');
guardCheck(str_contains($s,'SELECT * FROM ocr_usage_ledger WHERE idempotency_key=?'),'Durable idempotency lookup required');
guardCheck(str_contains($s,"lifecycle_state='PROVIDER_CALLED'"),'Provider boundary transition required');
guardCheck(str_contains($s,"lifecycle_state='RELEASED'"),'Pre-call release required');
guardCheck(str_contains($s,'failed_units=failed_units+1'),'Called-provider failure remains consumed');
guardCheck(str_contains($s,"'CACHE_HIT'"),'Cache-hit lifecycle required');
guardCheck(str_contains($s,",0,'CACHE_HIT'") && str_contains($s,",0,'REJECTED_LIMIT'"),'Cache and rejected requests use zero units');
guardCheck(str_contains($s,'feature_version=?'),'Version-aware cache lookup required');
guardCheck(str_contains($s,'normalized_text'),'Reprocessable normalized text required');
guardCheck(str_contains($s,'cache_source_attempt_id'),'Cache provenance required');
guardCheck(!str_contains($s,'ImageAnnotatorClient') && !str_contains($s,'documentTextDetection('),'Usage foundation must not call Google Vision');
guardCheck(!preg_match('/GOOGLE_APPLICATION_CREDENTIALS|PRIVATE_KEY|PAYMONGO_SK_/',$s),'No credentials in service');
echo "PASS: $n MIS-5B5-B8 usage, cache, history, and fail-safe source checks.\n";
