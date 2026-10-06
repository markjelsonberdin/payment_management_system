<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')throw new RuntimeException('CLI only');
define('ROOT_PATH',dirname(__DIR__));
require_once ROOT_PATH.'/modules/payment/config/env_loader.php';
payment_load_env(ROOT_PATH.'/modules/payment/.env');
require_once ROOT_PATH.'/vendor/autoload.php';
require_once ROOT_PATH.'/modules/payment/includes/ocr/GoogleVisionOcrService.php';
require_once ROOT_PATH.'/modules/payment/includes/ocr/PrivateReceiptStorageService.php';

/** @return array{reachable:bool,error:?string} */
function mis5Endpoint(string $host):array
{
    $errno=0;$error='';
    $socket=@stream_socket_client('ssl://'.$host.':443',$errno,$error,5,STREAM_CLIENT_CONNECT);
    if(is_resource($socket)){fclose($socket);return ['reachable'=>true,'error'=>null];}
    return ['reachable'=>false,'error'=>'TLS_CONNECT_FAILED'];
}

$report=[
    'safe_non_billable'=>true,
    'vision_image_request_made'=>false,
    'payment_database'=>['connected'=>false,'required_tables'=>false],
    'credentials'=>['status'=>'NOT_CONFIGURED','readable'=>false,'project_configured'=>false,'oauth_authenticated'=>false],
    'private_storage'=>['status'=>'NOT_CONFIGURED','readable'=>false,'writable'=>false,'outside_application'=>false],
    'configuration'=>['enabled'=>null,'mode'=>null,'monthly_limit'=>null],
    'usage'=>['billing_month'=>(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-01'),'reserved'=>null,'consumed'=>null,'successful'=>null,'failed'=>null,'released'=>null],
    'network'=>['oauth'=>mis5Endpoint('oauth2.googleapis.com'),'vision'=>mis5Endpoint('vision.googleapis.com')],
];

$host=getenv('PAYMENT_DB_HOST')?:getenv('DB_HOST');
$port=getenv('PAYMENT_DB_PORT')?:getenv('DB_PORT');
$database=getenv('PAYMENT_DB_DATABASE')?:getenv('DB_DATABASE');
$user=getenv('PAYMENT_DB_USER')?:getenv('DB_USERNAME');
$password=getenv('PAYMENT_DB_PASS')?:getenv('DB_PASSWORD');
try{
    if(!$host||!$port||!$database||!$user)throw new RuntimeException('PAYMENT_DB_CONFIGURATION_MISSING');
    $pdo=new PDO('mysql:host='.$host.';port='.$port.';dbname='.$database.';charset=utf8mb4',(string)$user,(string)$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $report['payment_database']['connected']=true;
    $required=['payment_gateway_settings','payment_concerns','ocr_results','ocr_usage_months','ocr_usage_ledger','ocr_scan_attempts','ocr_image_cache'];
    $placeholders=implode(',',array_fill(0,count($required),'?'));
    $stmt=$pdo->prepare('SELECT table_name FROM information_schema.tables WHERE table_schema=? AND table_name IN ('.$placeholders.')');
    $stmt->execute(array_merge([(string)$database],$required));
    $found=$stmt->fetchAll(PDO::FETCH_COLUMN);
    $report['payment_database']['required_tables']=count(array_intersect($required,$found))===count($required);
    $settings=$pdo->query("SELECT setting_key,setting_value FROM payment_gateway_settings WHERE setting_key IN ('ocr_enabled','ocr_mode','ocr_monthly_limit')")->fetchAll(PDO::FETCH_KEY_PAIR);
    $report['configuration']=['enabled'=>($settings['ocr_enabled']??'0')==='1','mode'=>$settings['ocr_mode']??'DOCUMENT_TEXT_DETECTION','monthly_limit'=>(int)($settings['ocr_monthly_limit']??900)];
    $month=$report['usage']['billing_month'];
    $usage=$pdo->prepare('SELECT reserved_units,consumed_units,successful_units,failed_units,released_units FROM ocr_usage_months WHERE billing_month=?');$usage->execute([$month]);
    if($row=$usage->fetch())$report['usage']=array_merge($report['usage'],['reserved'=>(int)$row['reserved_units'],'consumed'=>(int)$row['consumed_units'],'successful'=>(int)$row['successful_units'],'failed'=>(int)$row['failed_units'],'released'=>(int)$row['released_units']]);
}catch(Throwable){$report['payment_database']['error']='PAYMENT_DB_PREFLIGHT_FAILED';}

$google=new GoogleVisionOcrService();
$credential=$google->configurationStatus();
$authentication=$credential['status']==='CONFIGURED'?$google->nonBillableAuthenticationStatus():['status'=>'NOT_CONFIGURED','authenticated'=>false];
$report['credentials']=['status'=>$credential['status'],'readable'=>$credential['readable'],'project_configured'=>$credential['project_configured'],'oauth_authenticated'=>$authentication['authenticated'],'oauth_status'=>$authentication['status']];
$report['private_storage']=(new PrivateReceiptStorageService())->configurationStatus(true);
$report['passed']=$report['payment_database']['connected']&&$report['payment_database']['required_tables']&&$report['credentials']['status']==='CONFIGURED'&&$report['credentials']['oauth_authenticated']&&$report['private_storage']['status']==='CONFIGURED'&&$report['network']['oauth']['reachable']&&$report['network']['vision']['reachable']&&$report['configuration']['enabled']===false&&$report['configuration']['mode']==='DOCUMENT_TEXT_DETECTION'&&$report['configuration']['monthly_limit']===900;
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($report['passed']?0:1);
