<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') throw new RuntimeException('CLI only');
require_once __DIR__ . '/../config/database.php';
$pdo = db();
if (!$pdo) {
    fwrite(STDERR, "BLOCKED: Core database is unavailable; MIS personnel deployment cannot be certified.\n");
    exit(2);
}
$database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
$failures = [];
$columns = static function (string $table) use ($pdo, $database): array {
    $q=$pdo->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema=? AND table_name=?');
    $q->execute([$database,$table]); return $q->fetchAll(PDO::FETCH_COLUMN);
};
$requiredUsers=['id','username','email','password_hash','full_name','role_key','status','must_change_password','failed_login_attempts','locked_until','password_changed_at','created_at','updated_at'];
$requiredAudit=['user_id','user_name','role_key','action','module_key','entity_type','entity_id','detail','before_state','after_state','correlation_id','ip_address','user_agent','created_at'];
foreach ([['users',$requiredUsers],['activity_logs',$requiredAudit],['system_settings',['setting_key','setting_value']]] as [$table,$required]) {
    $found=$columns($table); $missing=array_values(array_diff($required,$found));
    if($missing)$failures[]=$table.' missing: '.implode(', ',$missing);
}
$q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=? AND table_name='activity_logs' AND index_name='uq_logs_correlation' AND non_unique=0");
$q->execute([$database]); if((int)$q->fetchColumn()!==1)$failures[]='activity_logs.uq_logs_correlation unique index missing';
$q=$pdo->query("SELECT role_key FROM roles WHERE role_key IN ('mis_admin','accounting_admin','accounting_officer','cashier')");
$roles=$q->fetchAll(PDO::FETCH_COLUMN); foreach(['mis_admin','accounting_admin','accounting_officer','cashier'] as $role)if(!in_array($role,$roles,true))$failures[]='canonical role missing: '.$role;
if($failures){foreach($failures as $failure)fwrite(STDERR,'FAIL: '.$failure."\n");exit(1);}
echo 'PASS: MIS personnel deployment prerequisites verified on '.$database.".\n";
