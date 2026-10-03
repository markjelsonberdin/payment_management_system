<?php

declare(strict_types=1);

if ($argc < 4) { fwrite(STDERR, "worker arguments missing\n"); exit(2); }
define('ROOT_PATH', dirname(__DIR__, 2));
require_once ROOT_PATH . '/modules/payment/includes/SchoolSalesCatalogApiController.php';

final class CatalogConcurrencyScopeProvider implements SchoolSalesApplicabilityScopeProvider
{
    public function isCanonicalProgram(string $programCode): bool { return $programCode === 'BSIT'; }
    public function isCanonicalYearLevel(string $yearLevel): bool { return in_array($yearLevel, ['1','2','3','4'], true); }
    public function isCanonicalProgramYear(string $programCode, string $yearLevel): bool { return $programCode === 'BSIT' && $this->isCanonicalYearLevel($yearLevel); }
}

function workerDsn(string $name): array
{
    $dsn=(string)getenv($name);
    if ($dsn==='' || !preg_match('/dbname=([^;]+)/i',$dsn,$m) || !str_ends_with(strtolower($m[1]),'_test')) throw new RuntimeException('ISOLATED_TEST_DSN_REQUIRED');
    $prefix=str_replace('_DSN','',$name);
    return [$dsn,(string)(getenv($prefix.'_USER')?:'root'),(string)(getenv($prefix.'_PASS')?:'')];
}

[$paymentDsn,$paymentUser,$paymentPass]=workerDsn('SCHOOL_SALES_CATALOG_TEST_DSN');
[$coreDsn,$coreUser,$corePass]=workerDsn('SCHOOL_SALES_CATALOG_CORE_TEST_DSN');
$payload=json_decode(base64_decode($argv[2],true)?:'',true,512,JSON_THROW_ON_ERROR);
$start=(float)$argv[3]; while(microtime(true)<$start) usleep(1000);
$payment=new PDO($paymentDsn,$paymentUser,$paymentPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$payment->exec("SET SESSION innodb_lock_wait_timeout=1");
$core=new PDO($coreDsn,$coreUser,$corePass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$service=new SchoolSalesCatalogService($payment,new SchoolSalesCatalogMutationInfrastructure($payment,new PaymentAuditOutboxService($payment,new StructuredActivityAuditWriter($core))),new CatalogConcurrencyScopeProvider());
$actor=['user_id'=>990001,'user_name'=>'Catalog Concurrency','role_key'=>'accounting_admin','permissions'=>$payload['permissions']??['school_sales.catalog.manage','school_sales.catalog.activate'],'ip_address'=>'127.0.0.1','user_agent'=>'Concurrency regression'];
try {
    $result=match($argv[1]) {
        'create_item'=>$service->createDraftItem($payload['correlation_id'],$payload['data'],$actor),
        'create_variant'=>$service->createDraftVariant((int)$payload['item_id'],$payload['correlation_id'],$payload['data'],$actor),
        'create_price'=>$service->createDraftVariantPrice((int)$payload['variant_id'],$payload['correlation_id'],$payload['data'],$actor),
        'activate_price'=>$service->activateVariantPrice((int)$payload['price_id'],$payload['correlation_id'],$actor),
        'replace_price'=>$service->replaceActiveVariantPrice((int)$payload['variant_id'],$payload['correlation_id'],$payload['data'],$actor),
        'retire_price'=>$service->retireActiveVariantPrice((int)$payload['price_id'],$payload['effective_to'],$payload['correlation_id'],$actor),
        'add_scope'=>$service->addItemApplicabilityAssignment((int)$payload['item_id'],$payload['correlation_id'],$payload['data'],$actor),
        'replace_scopes'=>$service->replaceItemApplicabilityAssignments((int)$payload['item_id'],$payload['correlation_id'],$payload['scopes'],$actor),
        'deactivate_variant'=>$service->deactivateVariant((int)$payload['variant_id'],$payload['correlation_id'],$actor),
        'activate_item'=>$service->activateItem((int)$payload['item_id'],$payload['correlation_id'],$actor),
        'update_book'=>$service->updateBookDetails((int)$payload['item_id'],$payload['correlation_id'],$payload['data'],$actor),
        default=>throw new InvalidArgumentException('UNKNOWN_WORKER_ACTION'),
    };
    echo json_encode(['ok'=>true,'data'=>$result],JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    $code=strtoupper(explode(':',$e->getMessage(),2)[0]);
    if($e instanceof PDOException && ($e->getCode()==='40001'||in_array((int)($e->errorInfo[1]??0),[1205,1213],true))) $code='CATALOG_CONCURRENCY_CONFLICT';
    echo json_encode(['ok'=>false,'error'=>preg_match('/^[A-Z][A-Z0-9_]*$/',$code)?$code:'WORKER_FAILURE'],JSON_THROW_ON_ERROR);
}
