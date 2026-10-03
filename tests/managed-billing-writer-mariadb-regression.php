<?php
declare(strict_types=1);

// Isolated MariaDB integration test. Never falls back to the application database.
require_once __DIR__ . '/../modules/payment/includes/BillingStudentContextProvider.php';
require_once __DIR__ . '/../modules/payment/includes/ManagedStandardAssessmentService.php';
require_once __DIR__ . '/../modules/payment/includes/ManagedStandardAssessmentWriter.php';

$dsn = (string) getenv('MANAGED_BILLING_TEST_DSN');
if ($dsn === '' || !preg_match('/dbname=([^;]+)/i', $dsn, $match) || !str_ends_with(strtolower($match[1]), '_test')) {
    fwrite(STDERR, "Refusing to run: MANAGED_BILLING_TEST_DSN must name a MariaDB database ending in _test.\n"); exit(2);
}
putenv('PAYMENT_MANAGED_BILLING_DEV_CONTEXT_ENABLED=true'); putenv('APP_ENV=test');
$pdo = new PDO($dsn, (string)(getenv('MANAGED_BILLING_TEST_USER') ?: 'root'), (string)(getenv('MANAGED_BILLING_TEST_PASS') ?: ''), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
if (!str_ends_with(strtolower($database), '_test')) { fwrite(STDERR,"Refusing to run: connected database is not a _test database.\n"); exit(2); }
$required=['billing','billing_items','managed_assessment_headers','fee_versions','fee_applicability','fees','fee_types','fee_groups','students'];
foreach($required as $table){ $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?'); $q->execute([$table]); if(!(int)$q->fetchColumn()) throw new RuntimeException("Missing required test table: $table"); }

$checks=0; $suffix='MBT'.bin2hex(random_bytes(5)); $ids=[]; $legacyNullBefore=(int)$pdo->query('SELECT COUNT(*) FROM billing_items WHERE fee_version_id IS NULL')->fetchColumn();
function ok(bool $value,string $message): void { global $checks; $checks++; if(!$value) throw new RuntimeException("Assertion failed: $message"); }
function context(int $studentId,string $year='1'): BillingStudentContext { return BillingStudentContext::fromArray(['student_id'=>$studentId,'student_number'=>'S'.$studentId,'full_name'=>'MariaDB Test','academic_year'=>'2088-2089','semester'=>'1st','program_code'=>'BSIT','year_level'=>$year,'section'=>null,'financially_eligible'=>true,'eligibility_source'=>'test','metadata'=>['program_code_canonical'=>true]]); }
function fee(PDO $pdo,array &$ids,string $code,string $name,string $behavior,bool $required,float $amount): int { global $suffix; $q=$pdo->prepare("INSERT INTO fees (fee_code,fee_name,fee_type_id,identity_status,default_amount,is_required,status) VALUES (?,?,?,?,0,0,'Inactive')"); $q->execute([$code.$suffix,$name,$ids['type'],'Active']); $fee=(int)$pdo->lastInsertId(); $q=$pdo->prepare("INSERT INTO fee_versions (fee_id,version_no,academic_year,semester,amount,behavior,is_required,effective_status) VALUES (?,1,'2088-2089','1st',?,?,?,'Active')"); $q->execute([$fee,$amount,$behavior,$required?1:0]); $version=(int)$pdo->lastInsertId(); $q=$pdo->prepare('INSERT INTO fee_applicability (fee_version_id,course,year_level,applies_to_all_courses,applies_to_all_year_levels) VALUES (?,NULL,NULL,1,1)'); $q->execute([$version]); $ids['fees'][]=$fee; $ids['versions'][]=$version; return $version; }
function student(PDO $pdo,array &$ids,string $label): int { global $suffix; $pdo->prepare("INSERT INTO students (user_id,student_number,full_name,course,year_level,status) VALUES (?,?,?,?,?,'Enrolled')")->execute([900200000+random_int(1,99999),$label.$suffix,'MariaDB Test','Unknown','1']); $id=(int)$pdo->lastInsertId(); $ids['students'][]=$id; return $id; }
try {
 $pdo->beginTransaction();
 $pdo->prepare("INSERT INTO fee_groups (group_code,group_name,status,sort_order) VALUES (?,?,'Active',0)")->execute(['G'.$suffix,'Group '.$suffix]); $ids['group']=(int)$pdo->lastInsertId();
 $pdo->prepare("INSERT INTO fee_types (fee_group_id,type_code,type_name,status,sort_order) VALUES (?,? ,?,'Active',0)")->execute([$ids['group'],'T'.$suffix,'Type '.$suffix]); $ids['type']=(int)$pdo->lastInsertId();
 $pdo->prepare("INSERT INTO students (user_id,student_number,full_name,course,year_level,status) VALUES (?,?,?,?,?,'Enrolled')")->execute([900000000+random_int(1,99999),'S'.$suffix,'MariaDB Test','Unknown','1']); $ids['student']=(int)$pdo->lastInsertId();
 $pdo->commit();
 $required=fee($pdo,$ids,'REQ','Required Standard','Standard',true,100); $review=fee($pdo,$ids,'REV','Reviewable Standard','Standard',false,200); $one=fee($pdo,$ids,'ONE','One Time','One-Time',false,300); $optional=fee($pdo,$ids,'OPT','Optional','Optional',false,400); $manual=fee($pdo,$ids,'MAN','Manual','Manual',false,500);
 $service=new ManagedStandardAssessmentService($pdo); $writer=new ManagedStandardAssessmentWriter($pdo,$service); $c=context($ids['student']);
 $first=$writer->commitStudentAssessment($c,[$review,$one,$optional,$manual],700001);
 ok($first['outcome']==='Added' && $first['added_item_count']===3,'new canonical adds required, selected Standard, selected One-Time only'); $ids['billing']=(int)$first['billing_id'];
 $map=$pdo->prepare('SELECT billing_id FROM managed_assessment_headers WHERE student_id=? AND academic_year=? AND semester=?'); $map->execute([$ids['student'],'2088-2089','1st']); ok((int)$map->fetchColumn()===$ids['billing'],'canonical mapping created');
 $items=$pdo->prepare('SELECT fee_version_id,fee_name,amount,paid_amount,remaining_amount FROM billing_items WHERE billing_id=? ORDER BY fee_version_id'); $items->execute([$ids['billing']]); $rows=$items->fetchAll(); ok(count($rows)===3 && (int)$rows[0]['fee_version_id']>0,'version-aware managed items inserted'); ok((float)$first['billing_summary']['gross_total']===600.0 && $first['billing_summary']['billing_status']==='Unpaid','header totals/status use item snapshots');
 $duplicate=$writer->commitStudentAssessment($c,[$review,$one],700001); ok($duplicate['outcome']==='AlreadyAssessed' && $duplicate['added_item_count']===0,'duplicate commit is idempotent');
 // Preserve partial payments while appending a newly activated required version.
 $pdo->prepare('UPDATE billing_items SET paid_amount=50,remaining_amount=50,status=\'Partial\' WHERE billing_id=? AND fee_version_id=?')->execute([$ids['billing'],$required]);
 $newRequired=fee($pdo,$ids,'REQ2','Later Required','Standard',true,50); $append=$writer->commitStudentAssessment($c,[],700001); ok($append['outcome']==='Added' && $append['added_item_count']===1,'canonical billing is reused for later version');
 $header=$pdo->prepare('SELECT total_amount,remaining_balance,billing_status FROM billing WHERE billing_id=?'); $header->execute([$ids['billing']]); $h=$header->fetch(); ok((float)$h['total_amount']===650.0 && (float)$h['remaining_balance']===600.0 && $h['billing_status']==='Partial','partial payment and recalculation are preserved');
 // Same-term unmanaged Enrollment must block without adding a managed obligation.
 $pdo->prepare("INSERT INTO students (user_id,student_number,full_name,course,year_level,status) VALUES (?,?,?,?,?,'Enrolled')")->execute([900100000+random_int(1,99999),'L'.$suffix,'Legacy Test','Unknown','1']); $legacyStudent=(int)$pdo->lastInsertId(); $ids['legacyStudent']=$legacyStudent;
 $pdo->prepare("INSERT INTO billing (student_id,billing_type,academic_year,semester,total_amount,discount_amount,remaining_balance,billing_status) VALUES (?,'Enrollment','2088-2089','1st',10,0,10,'Unpaid')")->execute([$legacyStudent]); $ids['legacyBilling']=(int)$pdo->lastInsertId();
 try { $writer->commitStudentAssessment(context($legacyStudent),[],700001); throw new RuntimeException('legacy conflict should block'); } catch(ManagedAssessmentCommitException $e) { ok($e->errorCode==='REVIEW_REQUIRED_LEGACY_TERM_BILLING','unmanaged same-term Enrollment blocks'); }
 $blockedMap=$pdo->prepare('SELECT COUNT(*) FROM managed_assessment_headers WHERE student_id=?'); $blockedMap->execute([$legacyStudent]); ok((int)$blockedMap->fetchColumn()===0,'legacy block created no canonical mapping');

 // An unmanaged Assessment is just as blocking as an unmanaged Enrollment.
 $assessmentBlockStudent=student($pdo,$ids,'A');
 $pdo->prepare("INSERT INTO billing (student_id,billing_type,academic_year,semester,total_amount,discount_amount,remaining_balance,billing_status) VALUES (?,'Assessment','2088-2089','1st',10,0,10,'Unpaid')")->execute([$assessmentBlockStudent]);
 try { $writer->commitStudentAssessment(context($assessmentBlockStudent),[],700001); throw new RuntimeException('unmanaged Assessment should block'); } catch(ManagedAssessmentCommitException $e) { ok($e->errorCode==='REVIEW_REQUIRED_LEGACY_TERM_BILLING','unmanaged same-term Assessment blocks'); }

 // No stale Preview is trusted: lifecycle and applicability are re-read inside commit.
 $staleReview=fee($pdo,$ids,'STALE','Stale lifecycle','Standard',false,55);
 ok($service->preview($c,[$staleReview])->toArray()['summary']['new_count']===1,'reviewable fee is visible before lifecycle change');
 $pdo->prepare("UPDATE fee_versions SET effective_status='Archived' WHERE fee_version_id=?")->execute([$staleReview]);
 $archived=$writer->commitStudentAssessment($c,[$staleReview],700001); ok($archived['added_item_count']===0 && in_array($archived['outcome'],['NoApplicableFees','AlreadyAssessed'],true),'Archived fee version is not committed from stale selection');
 $staleApplicability=fee($pdo,$ids,'SCOPE','Stale scope','Standard',false,56);
 ok($service->preview($c,[$staleApplicability])->toArray()['summary']['new_count']===1,'reviewable fee is visible before scope change');
 $pdo->prepare("UPDATE fee_applicability SET course='BSBA',applies_to_all_courses=0 WHERE fee_version_id=?")->execute([$staleApplicability]);
 $scopeChanged=$writer->commitStudentAssessment($c,[$staleApplicability],700001); ok($scopeChanged['added_item_count']===0 && in_array($scopeChanged['outcome'],['NoApplicableFees','AlreadyAssessed'],true),'Changed applicability is not committed from stale selection');

 // One-Time is rechecked against lifetime history immediately before commit.
 $oneStale=fee($pdo,$ids,'ONEH','One time history','One-Time',false,57);
 ok($service->preview($c,[$oneStale])->toArray()['summary']['new_count']===1,'One-Time is eligible before history exists');
 $q=$pdo->prepare('SELECT fee_id FROM fee_versions WHERE fee_version_id=?'); $q->execute([$oneStale]); $oneFeeId=(int)$q->fetchColumn();
 $pdo->prepare("INSERT INTO billing (student_id,billing_type,academic_year,semester,total_amount,discount_amount,remaining_balance,billing_status) VALUES (?,'Assessment','2087-2088','1st',57,0,57,'Unpaid')")->execute([$ids['student']]); $historyBilling=(int)$pdo->lastInsertId(); $ids['historyBilling']=$historyBilling;
 $pdo->prepare("INSERT INTO billing_items (billing_id,fee_id,fee_version_id,fee_name,source_context,amount,paid_amount,remaining_amount,status) VALUES (?,?,?,?,'Test history',57,0,57,'Unpaid')")->execute([$historyBilling,$oneFeeId,$oneStale,'One time history']);
 $oneHistory=$writer->commitStudentAssessment($c,[$oneStale],700001); ok($oneHistory['added_item_count']===0 && in_array($oneHistory['outcome'],['NoApplicableFees','AlreadyAssessed'],true),'One-Time history added after preview blocks commit');

 // A forced database insert failure must roll back both the new billing and canonical map.
 $rollbackStudent=student($pdo,$ids,'R'); $trigger='mbt_fail_'.$suffix;
 $pdo->exec("CREATE TRIGGER `$trigger` BEFORE INSERT ON billing_items FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='forced managed test failure'");
 try { $writer->commitStudentAssessment(context($rollbackStudent),[],700001); throw new RuntimeException('forced item failure should fail'); }
 catch(ManagedAssessmentCommitException $e) { ok($e->errorCode==='MANAGED_ASSESSMENT_COMMIT_FAILED','forced billing-item failure returns safe commit error'); }
 finally { $pdo->exec("DROP TRIGGER IF EXISTS `$trigger`"); }
 $q=$pdo->prepare('SELECT COUNT(*) FROM managed_assessment_headers WHERE student_id=?'); $q->execute([$rollbackStudent]); ok((int)$q->fetchColumn()===0,'forced item failure rolls back canonical header');
 $q=$pdo->prepare("SELECT COUNT(*) FROM billing WHERE student_id=? AND academic_year='2088-2089' AND semester='1st'"); $q->execute([$rollbackStudent]); ok((int)$q->fetchColumn()===0,'forced item failure rolls back billing header');

 // Database uniqueness remains the final guard for both canonical mapping and version-aware items.
 try { $pdo->prepare('INSERT INTO managed_assessment_headers (student_id,academic_year,semester,billing_id,created_by) VALUES (?,?,?,?,?)')->execute([$ids['student'],'2088-2089','1st',$ids['billing'],700001]); throw new RuntimeException('canonical uniqueness should reject duplicate'); }
 catch(PDOException $e) { ok($e->getCode()==='23000','canonical-header unique constraint protects one managed billing per term'); }
 try { $pdo->prepare("INSERT INTO billing_items (billing_id,fee_id,fee_version_id,fee_name,source_context,amount,paid_amount,remaining_amount,status) SELECT billing_id,fee_id,fee_version_id,fee_name,'Test duplicate',amount,0,amount,'Unpaid' FROM billing_items WHERE billing_id=? AND fee_version_id=?")->execute([$ids['billing'],$required]); throw new RuntimeException('item uniqueness should reject duplicate'); }
 catch(PDOException $e) { ok($e->getCode()==='23000','billing/version unique constraint rejects duplicate financial item'); }

 // Fully paid and partially paid canonical billings retain past payments when a new obligation is appended.
 $pdo->prepare("UPDATE billing_items SET paid_amount=amount,remaining_amount=0,status='Paid' WHERE billing_id=?")->execute([$ids['billing']]); $paidAppend=fee($pdo,$ids,'PAID','Append after paid','Standard',true,25);
 $paidResult=$writer->commitStudentAssessment($c,[],700001); $q=$pdo->prepare('SELECT total_amount,remaining_balance,billing_status FROM billing WHERE billing_id=?'); $q->execute([$ids['billing']]); $paidHeader=$q->fetch(); ok($paidResult['outcome']==='Added' && (float)$paidHeader['remaining_balance']===25.0 && $paidHeader['billing_status']==='Partial','previously Paid canonical billing transitions using stored item balances');
 $q=$pdo->prepare('SELECT paid_amount FROM billing_items WHERE billing_id=? AND fee_version_id=?'); $q->execute([$ids['billing'],$required]); ok((float)$q->fetchColumn()===100.0,'existing paid amount is preserved after append');
 ok((int)$pdo->query('SELECT COUNT(*) FROM billing_items WHERE fee_version_id IS NULL')->fetchColumn()===$legacyNullBefore,'legacy NULL fee_version_id rows remain unchanged');
 echo "Managed Billing MariaDB writer regression passed: $checks checks on $database.\n";
} finally {
 if($pdo->inTransaction()) $pdo->rollBack();
 // Fixture-only cleanup; all predicates use IDs generated above.
 $cleanupStudents=array_values(array_unique(array_filter(array_merge([$ids['student']??0,$ids['legacyStudent']??0],$ids['students']??[]))));
 if($cleanupStudents) { $marks=implode(',',array_fill(0,count($cleanupStudents),'?')); $pdo->prepare("DELETE bi FROM billing_items bi JOIN billing b ON b.billing_id=bi.billing_id WHERE b.student_id IN ($marks)")->execute($cleanupStudents); $pdo->prepare("DELETE FROM managed_assessment_headers WHERE student_id IN ($marks)")->execute($cleanupStudents); $pdo->prepare("DELETE FROM billing WHERE student_id IN ($marks)")->execute($cleanupStudents); $pdo->prepare("DELETE FROM students WHERE student_id IN ($marks)")->execute($cleanupStudents); }
 foreach(array_reverse($ids['versions']??[]) as $id) $pdo->prepare('DELETE FROM fee_applicability WHERE fee_version_id=?')->execute([$id]); foreach(array_reverse($ids['versions']??[]) as $id) $pdo->prepare('DELETE FROM fee_versions WHERE fee_version_id=?')->execute([$id]); foreach(array_reverse($ids['fees']??[]) as $id) $pdo->prepare('DELETE FROM fees WHERE fee_id=?')->execute([$id]); if(isset($ids['type'])) $pdo->prepare('DELETE FROM fee_types WHERE fee_type_id=?')->execute([$ids['type']]); if(isset($ids['group'])) $pdo->prepare('DELETE FROM fee_groups WHERE fee_group_id=?')->execute([$ids['group']]);
}
