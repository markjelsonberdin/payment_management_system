<?php

declare(strict_types=1);

if (!defined('ROOT_PATH')) define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/modules/payment/includes/SchoolSalesCatalogApiController.php';

$checks = 0;
function apiCheck(bool $ok, string $message): void { global $checks; $checks++; if (!$ok) throw new RuntimeException($message); }
function apiUuid(): string { return CatalogCorrelationId::generate(); }

/** @return array{0:PDO,1:PDO} */
function apiDatabases(): array
{
    $p = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $c = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $p->exec("CREATE TABLE school_sale_categories (sale_category_id INTEGER PRIMARY KEY, category_code TEXT, category_name TEXT, sort_order INTEGER, status TEXT, created_by INTEGER, updated_by INTEGER, created_at TEXT, updated_at TEXT)");
    $p->exec("CREATE TABLE school_sale_item_types (sale_item_type_id INTEGER PRIMARY KEY, type_code TEXT, type_name TEXT, metadata_profile TEXT, status TEXT, sort_order INTEGER, created_by INTEGER, updated_by INTEGER, created_at TEXT, updated_at TEXT)");
    $p->exec("CREATE TABLE school_sale_items (sale_item_id INTEGER PRIMARY KEY AUTOINCREMENT, item_code TEXT UNIQUE, sale_category_id INTEGER, sale_item_type_id INTEGER, item_name TEXT, description TEXT, applicability_mode TEXT, status TEXT, created_by INTEGER, updated_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
    $p->exec("CREATE TABLE school_sale_item_variants (sale_variant_id INTEGER PRIMARY KEY AUTOINCREMENT, sale_item_id INTEGER, variant_code TEXT, variant_name TEXT, sku TEXT UNIQUE, size_label TEXT, variant_metadata TEXT, sort_order INTEGER, status TEXT, created_by INTEGER, updated_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
    $p->exec("CREATE TABLE school_sale_variant_prices (sale_variant_price_id INTEGER PRIMARY KEY AUTOINCREMENT, sale_variant_id INTEGER, amount NUMERIC, currency TEXT, effective_from TEXT, effective_to TEXT, status TEXT, created_by INTEGER, updated_by INTEGER, activated_by INTEGER, retired_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP, activated_at TEXT, retired_at TEXT)");
    $p->exec("CREATE TABLE school_sale_item_applicability (sale_item_applicability_id INTEGER PRIMARY KEY AUTOINCREMENT, sale_item_id INTEGER, program_code TEXT, year_level TEXT, status TEXT, created_by INTEGER, updated_by INTEGER, deactivated_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP, deactivated_at TEXT)");
    $p->exec("CREATE TABLE school_sale_book_details (sale_book_detail_id INTEGER PRIMARY KEY AUTOINCREMENT, sale_item_id INTEGER UNIQUE, book_title TEXT, author TEXT, publisher TEXT, edition TEXT, isbn TEXT, notes TEXT, created_by INTEGER, updated_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
    $p->exec("CREATE TABLE payment_audit_outbox (audit_outbox_id INTEGER PRIMARY KEY AUTOINCREMENT, correlation_id TEXT UNIQUE, request_fingerprint TEXT, action TEXT, module_key TEXT, entity_type TEXT, entity_id INTEGER, detail TEXT, before_state TEXT, after_state TEXT, committed_result TEXT, actor_user_id INTEGER, actor_user_name TEXT, actor_role_key TEXT, actor_ip_address TEXT, actor_user_agent TEXT, delivery_status TEXT DEFAULT 'Pending', attempt_count INTEGER DEFAULT 0, next_attempt_at TEXT, last_attempt_at TEXT, delivered_at TEXT, core_activity_log_id INTEGER, last_error TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
    $c->exec("CREATE TABLE activity_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, user_name TEXT, role_key TEXT, action TEXT, module_key TEXT, entity_type TEXT, entity_id INTEGER, detail TEXT, before_state TEXT, after_state TEXT, correlation_id TEXT UNIQUE, ip_address TEXT, user_agent TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
    $p->exec("INSERT INTO school_sale_categories VALUES (1,'GENERAL','General',10,'Active',NULL,NULL,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
    $p->exec("INSERT INTO school_sale_item_types VALUES (1,'UNIFORM','Uniform','NONE','Active',10,NULL,NULL,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP),(2,'BOOK','Book','BOOK_REQUIRED','Active',20,NULL,NULL,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
    $p->exec("INSERT INTO school_sale_items (sale_item_id,item_code,sale_category_id,sale_item_type_id,item_name,description,applicability_mode,status,created_by,updated_by) VALUES
      (1,'DRAFT-A',1,1,'Draft A',NULL,'ALL','Draft',1,1),
      (2,'DRAFT-B',1,1,'Draft B',NULL,'ALL','Draft',1,1),
      (3,'ACTIVE-A',1,1,'Active A',NULL,'ALL','Active',1,1)");
    $p->exec("INSERT INTO school_sale_item_variants (sale_variant_id,sale_item_id,variant_code,variant_name,sort_order,status,created_by,updated_by) VALUES
      (1,1,'DRAFT','Draft',10,'Draft',1,1),(2,2,'ARCHIVE','Archive',10,'Draft',1,1),
      (3,3,'ACTIVE-1','Active 1',10,'Active',1,1),(4,3,'ACTIVE-2','Active 2',20,'Active',1,1)");
    $p->exec("INSERT INTO school_sale_variant_prices (sale_variant_price_id,sale_variant_id,amount,currency,effective_from,effective_to,status,created_by,updated_by,activated_by) VALUES
      (10,1,100,'PHP','2030-01-01 00:00:00',NULL,'Draft',1,1,NULL),
      (11,2,110,'PHP','2099-01-01 00:00:00',NULL,'Active',1,1,1),
      (12,3,120,'PHP','2020-01-01 00:00:00',NULL,'Active',1,1,1),
      (13,4,130,'PHP','2020-01-01 00:00:00',NULL,'Active',1,1,1)");
    return [$p,$c];
}

function apiController(PDO $payment, ?PDO $core): SchoolSalesCatalogApiController
{
    $writer = $core ? new StructuredActivityAuditWriter($core) : null;
    $infra = new SchoolSalesCatalogMutationInfrastructure($payment, new PaymentAuditOutboxService($payment, $writer));
    return new SchoolSalesCatalogApiController(new SchoolSalesCatalogService($payment, $infra));
}

/** @param list<string> $permissions @return array<string,mixed> */
function apiContext(array $permissions, bool $authenticated=true, bool $csrf=true, ?string $correlation=null): array
{
    return ['authenticated'=>$authenticated,'csrf_valid'=>$csrf,'correlation_id'=>$correlation ?? apiUuid(),'permissions'=>$permissions,
        'actor'=>['user_id'=>90,'user_name'=>'API Tester','role_key'=>'accounting_admin','permissions'=>$permissions,'ip_address'=>'127.0.0.1','user_agent'=>'CLI']];
}

[$payment,$core] = apiDatabases();
$api = apiController($payment,$core);
$view = ['school_sales.catalog.view'];
$manage = ['school_sales.catalog.manage'];
$activate = ['school_sales.catalog.activate'];

$response = $api->handle('GET',['action'=>'categories'],[],apiContext([],false));
apiCheck($response['status']===401 && $response['body']['error']==='AUTHENTICATION_REQUIRED','Authentication must be required.');
$response = $api->handle('GET',['action'=>'categories'],[],apiContext([]));
apiCheck($response['status']===403,'Catalog reads must require view permission.');
$response = $api->handle('GET',['action'=>'categories'],[],apiContext($view));
apiCheck($response['status']===200 && count($response['body']['data'])===1,'Categories read must delegate successfully.');
$response = $api->handle('GET',['action'=>'variant_prices','variant_id'=>3],[],apiContext($view));
apiCheck($response['status']===200 && $response['body']['data']['current_price_state']==='Available','Price read must include current state.');
$response = $api->handle('GET',['action'=>'item_details','item_id'=>999],[],apiContext($view));
apiCheck($response['status']===404 && $response['body']['error']==='ITEM_NOT_FOUND','Missing read entity must map to 404.');
$response = $api->handle('GET',['action'=>'nope'],[],apiContext($view));
apiCheck($response['status']===404 && $response['body']['error']==='UNKNOWN_ACTION','Unknown read action must be rejected.');
$response = $api->handle('PATCH',[],[],apiContext($view));
apiCheck($response['status']===405,'Unsupported methods must return 405.');

$create = ['action'=>'create_item','data'=>['item_code'=>'API-ITEM','item_name'=>'API Item','sale_category_id'=>1,'sale_item_type_id'=>1,'description'=>null,'applicability_mode'=>'ALL']];
$response = $api->handle('POST',[],$create,apiContext($manage,true,false));
apiCheck($response['status']===403 && $response['body']['error']==='CSRF_INVALID','CSRF must be checked before mutation.');
$response = $api->handle('POST',[],$create,apiContext($view));
apiCheck($response['status']===403,'Manage actions must reject view-only callers.');
$correlation = apiUuid();
$context = apiContext($manage,true,true,$correlation);
$created = $api->handle('POST',[],$create,$context);
apiCheck($created['status']===200 && $created['body']['data']['committed']===true,'Manage mutation must delegate successfully.');
$replay = $api->handle('POST',[],$create,$context);
apiCheck($replay['body']['data']['idempotent_replay']===true && $replay['body']['data']['result']===$created['body']['data']['result'],'Same request must replay canonical result.');
$different = $create; $different['data']['item_name']='Different';
$conflict = $api->handle('POST',[],$different,$context);
apiCheck($conflict['status']===409 && $conflict['body']['error']==='CORRELATION_ID_CONFLICT','Different request with same correlation must return 409.');
$invalidCorrelation = $api->handle('POST',[],$create,apiContext($manage,true,true,'not-a-uuid'));
apiCheck($invalidCorrelation['status']===422 && $invalidCorrelation['body']['error']==='INVALID_CORRELATION_ID','Invalid correlation UUID must return stable 422.');
$invalidPayload = $api->handle('POST',[],['action'=>'create_item','data'=>[]],apiContext($manage));
apiCheck($invalidPayload['status']===422,'Business validation must return 422.');
$duplicate = $api->handle('POST',[],$create,apiContext($manage));
apiCheck($duplicate['status']===409 && $duplicate['body']['error']==='DUPLICATE_ITEM_CODE','Duplicate catalog state must return 409.');

$draftLifecycle = $api->handle('POST',[],['action'=>'deactivate_variant','variant_id'=>1],apiContext($manage));
apiCheck($draftLifecycle['status']===200 && $draftLifecycle['body']['data']['result']['status']==='Inactive','Manage may change ordinary Draft variant lifecycle.');
$activeDenied = $api->handle('POST',[],['action'=>'deactivate_variant','variant_id'=>3],apiContext($manage));
apiCheck($activeDenied['status']===403 && $activeDenied['body']['error']==='SCHOOL_SALES_CATALOG_ACTIVATE_REQUIRED','Service must escalate Active availability changes to activate permission.');
$activeAllowed = $api->handle('POST',[],['action'=>'deactivate_variant','variant_id'=>3],apiContext($activate));
apiCheck($activeAllowed['status']===200,'Activate permission must allow a valid guarded Active variant change.');

$draftWithActivateOnly = $api->handle('POST',[],['action'=>'cancel_draft_price','price_id'=>10],apiContext($activate));
apiCheck($draftWithActivateOnly['status']===403 && $draftWithActivateOnly['body']['error']==='SCHOOL_SALES_CATALOG_MANAGE_REQUIRED','Draft cancellation must use actual state and require manage.');
$draftByAlias = $api->handle('POST',[],['action'=>'cancel_future_active_price','price_id'=>10],apiContext($manage));
apiCheck($draftByAlias['status']===200,'Cancellation alias must not override actual Draft state permission.');
$futureWithManage = $api->handle('POST',[],['action'=>'cancel_draft_price','price_id'=>11],apiContext($manage));
apiCheck($futureWithManage['status']===403 && $futureWithManage['body']['error']==='SCHOOL_SALES_CATALOG_ACTIVATE_REQUIRED','Future Active cancellation must require activate regardless of alias.');
$futureByAlias = $api->handle('POST',[],['action'=>'cancel_draft_price','price_id'=>11],apiContext($activate));
apiCheck($futureByAlias['status']===200,'Actual future Active state may be cancelled with activate regardless of alias.');
$current = $api->handle('POST',[],['action'=>'cancel_future_active_price','price_id'=>12],apiContext($activate));
apiCheck($current['status']===409 && $current['body']['error']==='PRICE_CANNOT_BE_CANCELLED','Current Active price cancellation must fail safely as a state conflict.');

$activateDenied = $api->handle('POST',[],['action'=>'activate_item','item_id'=>2],apiContext($manage));
apiCheck($activateDenied['status']===403,'Item activation must require activate permission.');

[$pendingPayment] = apiDatabases();
$pendingApi = apiController($pendingPayment,null);
$pendingInput = $create; $pendingInput['data']['item_code']='PENDING-AUDIT';
$pending = $pendingApi->handle('POST',[],$pendingInput,apiContext($manage));
apiCheck($pending['status']===200 && $pending['body']['data']['committed']===true && $pending['body']['data']['audit_status']==='pending','Core failure must preserve committed Payment mutation with pending audit.');

$endpoint = (string) file_get_contents(ROOT_PATH . '/modules/payment/api/accounting/school-sales-catalog.php');
$controllerSource = (string) file_get_contents(ROOT_PATH . '/modules/payment/includes/SchoolSalesCatalogApiController.php');
$page = (string) file_get_contents(ROOT_PATH . '/modules/payment/pages/accounting_admin/school-sales-catalog.php');
foreach (['INSERT INTO school_sale_','UPDATE school_sale_','DELETE FROM school_sale_','INSERT INTO payment_audit_outbox'] as $forbidden) {
    apiCheck(!str_contains($endpoint,$forbidden) && !str_contains($controllerSource,$forbidden),'API must not contain direct catalog mutation SQL.');
}
apiCheck(str_contains($page,'CATALOG_MUTATIONS_PENDING') && str_contains($page,'http_response_code(503)'),'Existing page POST must remain blocked at 503.');
apiCheck(str_contains((string)file_get_contents(ROOT_PATH.'/config/config.php'),"=== 'true'"),'Cashier School Sales must retain strict disabled-by-default gate.');

echo "PASS: {$checks} School Sales Catalog API checks.\n";
