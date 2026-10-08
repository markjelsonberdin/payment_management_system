<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit("Forbidden. This notification worker is CLI-only.\n");}
require_once __DIR__.'/../../config/config.php';
require_once ROOT_PATH.'/modules/payment/database/db_connect.php';
require_once ROOT_PATH.'/modules/payment/includes/ManagedBillingNotificationDispatcher.php';
$options=getopt('',['limit::','run-id::','historical-before::']);
$limit=isset($options['limit'])?(int)$options['limit']:50;
$runId=isset($options['run-id'])?(int)$options['run-id']:null;
$historical=isset($options['historical-before'])?(string)$options['historical-before']:null;
try{$summary=(new ManagedBillingNotificationDispatcher($pdo))->dispatchDue($limit,$runId,$historical);echo json_encode($summary,JSON_THROW_ON_ERROR).PHP_EOL;exit(0);}
catch(Throwable $e){fwrite(STDERR,"Managed billing notification dispatch failed.\n");exit(1);}
