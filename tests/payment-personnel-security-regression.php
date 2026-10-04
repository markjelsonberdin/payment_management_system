<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') throw new RuntimeException('CLI only');
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/includes/security.php';
require_once ROOT_PATH . '/includes/module-controls.php';
require_once ROOT_PATH . '/includes/authentication.php';
require_once ROOT_PATH . '/modules/payment/includes/PaymentPersonnelService.php';

$checks = 0;
function pcheck(bool $ok, string $message): void { global $checks; $checks++; if (!$ok) throw new RuntimeException($message); }
function pdb(): PDO {
    $p = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $p->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE, email TEXT UNIQUE,
      password_hash TEXT, full_name TEXT, role_key TEXT, status TEXT DEFAULT 'active', must_change_password INTEGER DEFAULT 0,
      failed_login_attempts INTEGER DEFAULT 0, locked_until TEXT, password_changed_at TEXT, last_seen_at TEXT,
      created_at TEXT, updated_at TEXT)");
    $p->exec('CREATE TABLE system_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, updated_at TEXT)');
    $p->exec('CREATE TABLE activity_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, user_name TEXT,
      role_key TEXT, action TEXT, module_key TEXT, entity_type TEXT, entity_id INTEGER, detail TEXT,
      before_state TEXT, after_state TEXT, correlation_id TEXT UNIQUE, ip_address TEXT, user_agent TEXT,
      created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    return $p;
}
function svc(PDO $p): PaymentPersonnelService {
    return new PaymentPersonnelService($p, new StructuredActivityAuditWriter($p),
        static fn(int $id): int => smsBumpUserKickEpoch($p, $id));
}
function actor(): array { return ['id'=>99,'name'=>'MIS Admin','role'=>'mis_admin']; }
function addUser(PDO $p, string $role, string $name): int {
    $q=$p->prepare("INSERT INTO users (username,email,password_hash,full_name,role_key,status,must_change_password,
      failed_login_attempts,locked_until,created_at,updated_at) VALUES (?,?,?,?,?,'active',0,0,NULL,?,?)");
    $q->execute([$name,$name.'@example.test',password_hash('Old!Password123',PASSWORD_DEFAULT),$name,$role,'2026-10-04 10:00:00','2026-10-04 10:00:00']);
    return (int)$p->lastInsertId();
}

$p=pdb(); $s=svc($p);
$admin=addUser($p,'accounting_admin','admin1'); $officer=addUser($p,'accounting_officer','officer1'); $cashier=addUser($p,'cashier','cashier1');
foreach(['mis_admin','student','superadmin','registrar','faculty','finance','payment_admin','unknown'] as $i=>$role) addUser($p,$role,'outside'.$i);
pcheck(count($s->list())===3,'Only managed roles may be listed');
foreach(PaymentPersonnelService::MANAGED_ROLES as $i=>$role){
    $r=$s->create(['full_name'=>'Created User '.$i,'username'=>'created'.$i,'email'=>'created'.$i.'@example.test',
      'role_key'=>$role,'password'=>'Strong!Password9'.$i,'password_confirm'=>'Strong!Password9'.$i],actor());
    pcheck($r['user_id']>0,'Managed role creation failed');
}
foreach(['mis_admin','student','superadmin','registrar','faculty','finance','payment_admin','unknown'] as $role){
    try{$s->create(['full_name'=>'Attack','username'=>'attack'.md5($role),'email'=>md5($role).'@example.test',
      'role_key'=>$role,'password'=>'Strong!Password99','password_confirm'=>'Strong!Password99'],actor());pcheck(false,'Forbidden role created');}
    catch(DomainException $e){pcheck($e->getMessage()==='PAYMENT_USER_ROLE_FORBIDDEN','Wrong role denial');}
}
try{$s->updateProfile(['user_id'=>$cashier,'full_name'=>'Attack','username'=>'cashier1','email'=>'cashier1@example.test','permissions'=>['*']],actor());pcheck(false,'Permission injection accepted');}
catch(InvalidArgumentException $e){pcheck(str_contains($e->getMessage(),'Unsupported request field'),'Protected field not rejected');}

$old=smsUserKickEpoch($cashier); $s->assignRole(['user_id'=>$cashier,'role_key'=>'accounting_officer'],actor()); $new=smsUserKickEpoch($cashier);
pcheck($new!==$old && $new>time(),'Role change did not revoke same-second sessions');
foreach(['mis_admin','superadmin','student','custom_role'] as $role){try{$s->assignRole(['user_id'=>$cashier,'role_key'=>$role],actor());pcheck(false,'Escalation accepted');}catch(DomainException $e){pcheck(true,'Escalation denied');}}

$p->prepare("UPDATE users SET status='locked',failed_login_attempts=5,locked_until='2099-01-01 00:00:00' WHERE id=?")->execute([$officer]);
$s->setAdministrativeStatus(['user_id'=>$officer],actor(),true);
$row=$p->query("SELECT status,failed_login_attempts,locked_until FROM users WHERE id=$officer")->fetch();
pcheck($row['status']==='locked'&&(int)$row['failed_login_attempts']===5&&$row['locked_until']==='2099-01-01 00:00:00','Activation changed lock state');
$before=smsUserKickEpoch($officer); $s->setAdministrativeStatus(['user_id'=>$officer],actor(),false);
$row=$p->query("SELECT status,failed_login_attempts,locked_until FROM users WHERE id=$officer")->fetch();
pcheck($row['status']==='inactive'&&(int)$row['failed_login_attempts']===5&&$row['locked_until']==='2099-01-01 00:00:00','Deactivation changed lock state');
pcheck(smsUserKickEpoch($officer)!==$before,'Deactivation did not revoke sessions');
$hash=$p->query("SELECT password_hash FROM users WHERE id=$officer")->fetchColumn(); $s->unlock(['user_id'=>$officer],actor());
$row=$p->query("SELECT status,failed_login_attempts,locked_until,password_hash FROM users WHERE id=$officer")->fetch();
pcheck($row['status']==='inactive'&&(int)$row['failed_login_attempts']===0&&$row['locked_until']===null,'Unlock changed administrative state');
pcheck(hash_equals($hash,$row['password_hash']),'Unlock changed password');

$p->prepare("UPDATE users SET failed_login_attempts=4,locked_until='2099-01-01 00:00:00' WHERE id=?")->execute([$admin]);
$before=smsUserKickEpoch($admin); $s->resetPassword(['user_id'=>$admin,'password'=>'New!Password1234','password_confirm'=>'New!Password1234'],actor());
$row=$p->query("SELECT failed_login_attempts,locked_until,password_hash,must_change_password FROM users WHERE id=$admin")->fetch();
pcheck((int)$row['failed_login_attempts']===4&&$row['locked_until']==='2099-01-01 00:00:00','Password reset unlocked account');
pcheck(password_verify('New!Password1234',$row['password_hash'])&&(int)$row['must_change_password']===1,'Password reset failed');
pcheck(smsUserKickEpoch($admin)!==$before,'Password reset did not revoke sessions');

$events=$p->query('SELECT action,before_state,after_state,correlation_id FROM activity_logs')->fetchAll(PDO::FETCH_ASSOC);
$actions=array_column($events,'action');
foreach(['PAYMENT_USER_CREATED','PAYMENT_USER_ROLE_CHANGED','PAYMENT_USER_ACTIVATED','PAYMENT_USER_DEACTIVATED','PAYMENT_USER_UNLOCKED','PAYMENT_USER_PASSWORD_RESET'] as $a)pcheck(in_array($a,$actions,true),'Missing audit '.$a);
$json=json_encode($events); pcheck(!str_contains($json,'New!Password1234')&&!str_contains($json,'$2y$'),'Audit leaked password material');
pcheck(count(array_unique(array_column($events,'correlation_id')))===count($events),'Correlation IDs are not unique per command');
$reset=array_values(array_filter($events,static fn($e)=>$e['action']==='PAYMENT_USER_PASSWORD_RESET'))[0];
pcheck(str_contains($reset['after_state'],'sessions_revoked'),'Audit lacks revocation linkage');

$p2=pdb();$id2=addUser($p2,'cashier','same');$loginAt=time();$g1=smsBumpUserKickEpoch($p2,$id2);pcheck($g1>$loginAt,'Legacy same-second race remains');
$sessionOne=$g1;$sessionTwo=$g1;$g2=smsBumpUserKickEpoch($p2,$id2);
pcheck($g2!==$g1,'Generation snapshot race remains');
pcheck(smsUserSessionGenerationRevoked($sessionOne,$g2,$loginAt)&&smsUserSessionGenerationRevoked($sessionTwo,$g2,$loginAt),'Two old sessions survived revocation');
pcheck(!smsUserSessionGenerationRevoked($g2,$g2,time()),'New login after revocation was rejected');
$broken=pdb();$broken->exec('DROP TABLE activity_logs');$bs=svc($broken);
try{$bs->create(['full_name'=>'No Audit','username'=>'noaudit','email'=>'noaudit@example.test','role_key'=>'cashier','password'=>'Strong!Password88','password_confirm'=>'Strong!Password88'],actor());pcheck(false,'Unaudited mutation committed');}
catch(Throwable $e){pcheck((int)$broken->query("SELECT COUNT(*) FROM users WHERE username='noaudit'")->fetchColumn()===0,'Audit failure did not roll back');}
echo "PASS: $checks Payment personnel security checks.\n";
