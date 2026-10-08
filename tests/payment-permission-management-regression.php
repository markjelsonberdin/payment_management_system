<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') throw new RuntimeException('CLI only');
require_once __DIR__.'/../config/config.php';
require_once ROOT_PATH.'/includes/authentication.php';
require_once ROOT_PATH.'/modules/payment/includes/PaymentPermissionManagementService.php';

$checks=0;
function pmCheck(bool $value,string $message):void{global $checks;$checks++;if(!$value)throw new RuntimeException($message);}
function pmDb():PDO{$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$pdo->exec('CREATE TABLE role_permissions(role_key TEXT NOT NULL,module_key TEXT NOT NULL,granted INTEGER NOT NULL)');$pdo->exec('CREATE TABLE activity_logs(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,user_name TEXT,role_key TEXT,action TEXT,module_key TEXT,entity_type TEXT,entity_id INTEGER,detail TEXT,before_state TEXT,after_state TEXT,correlation_id TEXT UNIQUE,ip_address TEXT,user_agent TEXT)');return $pdo;}
function pmDecisions(array $data,string $role):array{$out=[];foreach($data['rows'] as $row)if($row['role']===$role)$out[$row['permission']]=$row['effective_access']==='allowed';ksort($out,SORT_STRING);return $out;}
$actor=['id'=>7,'name'=>'MIS Operator','role'=>'mis_admin'];
$pdo=pmDb();
$matrix=new PaymentPermissionMatrixService($pdo);
$data=$matrix->load();
pmCheck(count($data['roles'])===4,'Only four staff roles belong in management');
pmCheck(!in_array('student',array_column($data['roles'],'key'),true),'Student must remain excluded');
$decisions=pmDecisions($data,'accounting_officer');
$decisions['payment.reconciliation.view']=true;
$service=new PaymentPermissionManagementService($pdo,new StructuredActivityAuditWriter($pdo));
$result=$service->update('accounting_officer',$decisions,$data['versions']['accounting_officer'],'11111111-1111-4111-8111-111111111111',$actor);
pmCheck($result['role']==='accounting_officer','Selected role persisted');
pmCheck((int)$pdo->query("SELECT granted FROM role_permissions WHERE role_key='accounting_officer' AND module_key='payment.reconciliation.view'")->fetchColumn()===1,'Eligible grant persisted');
pmCheck((int)$pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action='PAYMENT_ROLE_PERMISSIONS_UPDATED'")->fetchColumn()===1,'Central audit event persisted');
$audit=$pdo->query('SELECT before_state,after_state FROM activity_logs')->fetch(PDO::FETCH_ASSOC);
pmCheck(json_decode($audit['before_state'],true)['payment.reconciliation.view']===false&&json_decode($audit['after_state'],true)['payment.reconciliation.view']===true,'Audit includes before and after decisions');
try{$service->update('accounting_officer',$decisions,$data['versions']['accounting_officer'],'22222222-2222-4222-8222-222222222222',$actor);pmCheck(false,'Stale version accepted');}catch(PaymentPermissionConflictException){pmCheck(true,'Concurrent edit rejected');}

$fresh=$matrix->load();$cashier=pmDecisions($fresh,'cashier');$cashier['fee.manage']=true;
try{$service->update('cashier',$cashier,$fresh['versions']['cashier'],'33333333-3333-4333-8333-333333333333',$actor);pmCheck(false,'Role ceiling bypassed');}catch(DomainException $e){pmCheck($e->getMessage()==='ROLE_CEILING_EXCEEDED','Role ceiling rejection');}
$mis=pmDecisions($fresh,'mis_admin');$mis['payment.permissions.manage']=false;
try{$service->update('mis_admin',$mis,$fresh['versions']['mis_admin'],'44444444-4444-4444-8444-444444444444',$actor);pmCheck(false,'MIS lockout accepted');}catch(DomainException $e){pmCheck($e->getMessage()==='PERMISSION_ADMIN_LOCKOUT','MIS lockout rejection');}
try{$service->update('cashier',pmDecisions($fresh,'cashier'),$fresh['versions']['cashier'],'55555555-5555-4555-8555-555555555555',['id'=>8,'name'=>'Cashier','role'=>'cashier']);pmCheck(false,'Non-MIS actor accepted');}catch(DomainException $e){pmCheck($e->getMessage()==='MIS_ADMIN_REQUIRED','Actor authority enforced');}

$dup=pmDb();$dup->exec("INSERT INTO role_permissions VALUES('cashier','payment.collection',1),('cashier','payment.collection',0)");
$dupData=(new PaymentPermissionMatrixService($dup))->load();
pmCheck(count($dupData['duplicates'])===1,'Duplicate rows surfaced');
try{(new PaymentPermissionManagementService($dup,new StructuredActivityAuditWriter($dup)))->update('cashier',pmDecisions($dupData,'cashier'),$dupData['versions']['cashier'],'66666666-6666-4666-8666-666666666666',$actor);pmCheck(false,'Duplicate rows accepted');}catch(PaymentPermissionDuplicateException){pmCheck(true,'Duplicate save rejected');}

$rollback=pmDb();$rollbackData=(new PaymentPermissionMatrixService($rollback))->load();$rollbackDecisions=pmDecisions($rollbackData,'cashier');$rollbackDecisions['payment.collection']=false;$rollback->exec("CREATE TRIGGER reject_permission_audit BEFORE INSERT ON activity_logs BEGIN SELECT RAISE(ABORT,'audit unavailable'); END");
try{(new PaymentPermissionManagementService($rollback,new StructuredActivityAuditWriter($rollback)))->update('cashier',$rollbackDecisions,$rollbackData['versions']['cashier'],'77777777-7777-4777-8777-777777777777',$actor);pmCheck(false,'Audit failure committed');}catch(Throwable){pmCheck((int)$rollback->query('SELECT COUNT(*) FROM role_permissions')->fetchColumn()===0,'Audit failure rolled back permission rows');}

pmCheck(paymentPermissionDecision('cashier','payment.collection',false)===false,'Explicit revocation affects active authorization checks');
pmCheck(paymentPermissionDecision('cashier','fee.manage',true)===false,'Grant cannot exceed role ceiling');
$api=(string)file_get_contents(ROOT_PATH.'/modules/payment/api/mis_admin/role-permission-update.php');
$page=(string)file_get_contents(ROOT_PATH.'/modules/payment/pages/mis_admin/roles-permissions.php');
$js=(string)file_get_contents(ROOT_PATH.'/modules/payment/assets/js/payment-role-permissions.js');
pmCheck(str_contains($api,"!== 'POST'")&&str_contains($api,'verifyCsrfToken')&&str_contains($api,'requireActiveMisActor'),'POST, CSRF, and active MIS guards');
pmCheck(str_contains($api,'PERMISSION_VERSION_CONFLICT')&&str_contains($api,'ROLE_CEILING_EXCEEDED'),'Conflict and ceiling outcomes exposed');
pmCheck(str_contains($page,'data-update-api')&&str_contains($page,'permissionPreviewModal')&&str_contains($page,"requirePaymentPermission('payment.permissions.manage')"),'Editor page uses dedicated permission');
pmCheck(str_contains($js,'expected_version')&&str_contains($js,'X-CSRF-Token')&&str_contains($js,'data-module'),'Client uses version, CSRF, and module controls');
pmCheck(!str_contains($page,'Student')&&!str_contains($js,'student'),'Student excluded from editor');
echo "PASS: {$checks} Batch 4G-2 configurable permission management checks.\n";
