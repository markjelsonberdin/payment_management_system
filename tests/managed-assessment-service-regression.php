<?php
declare(strict_types=1);

require_once __DIR__ . '/../modules/payment/includes/ManagedStandardAssessmentService.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) { fwrite(STDERR, "SKIP: PDO SQLite is unavailable.\n"); exit(0); }
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
foreach ([
 'CREATE TABLE fees (fee_id INTEGER PRIMARY KEY, fee_code TEXT, fee_name TEXT, fee_type_id INTEGER, identity_status TEXT)',
 'CREATE TABLE fee_versions (fee_version_id INTEGER PRIMARY KEY, fee_id INTEGER, academic_year TEXT, semester TEXT, amount REAL, behavior TEXT, is_required INTEGER, effective_status TEXT)',
 'CREATE TABLE fee_types (fee_type_id INTEGER PRIMARY KEY, type_code TEXT, type_name TEXT, fee_group_id INTEGER)',
 'CREATE TABLE fee_groups (fee_group_id INTEGER PRIMARY KEY, group_code TEXT, group_name TEXT)',
 'CREATE TABLE fee_applicability (fee_applicability_id INTEGER PRIMARY KEY, fee_version_id INTEGER, course TEXT, year_level TEXT, applies_to_all_courses INTEGER, applies_to_all_year_levels INTEGER)',
 'CREATE TABLE billing (billing_id INTEGER PRIMARY KEY, student_id INTEGER, academic_year TEXT, semester TEXT, billing_type TEXT, billing_status TEXT, total_amount REAL, remaining_balance REAL)',
 'CREATE TABLE billing_items (billing_item_id INTEGER PRIMARY KEY, billing_id INTEGER, fee_id INTEGER, fee_version_id INTEGER, fee_name TEXT)',
 'CREATE TABLE managed_assessment_headers (managed_header_id INTEGER PRIMARY KEY, student_id INTEGER, academic_year TEXT, semester TEXT, billing_id INTEGER)',
 'CREATE TABLE billing_runs (run_id INTEGER PRIMARY KEY)',
 'CREATE TABLE billing_run_fee_versions (run_fee_version_id INTEGER PRIMARY KEY)',
 'CREATE TABLE billing_run_assignments (assignment_id INTEGER PRIMARY KEY)',
 'CREATE TABLE billing_notification_outbox (outbox_id INTEGER PRIMARY KEY)',
] as $sql) $pdo->exec($sql);
$pdo->exec("INSERT INTO fee_groups VALUES (1,'TUITION','Tuition'); INSERT INTO fee_types VALUES (1,'TUITION','Tuition',1)");
$fees = [[1,'REQ','Required',1,'Active'],[2,'REV','Reviewable',1,'Active'],[3,'ONE','One Time',1,'Active'],[4,'OPT','Optional',1,'Active'],[5,'MAN','Manual',1,'Active'],[6,'ZERO','Zero',1,'Active'],[7,'MISS','Missing',1,'Active']];
$feeInsert=$pdo->prepare('INSERT INTO fees VALUES (?,?,?,?,?)'); foreach($fees as $row)$feeInsert->execute($row);
$versions=[[1,1,'2099-2100','1st',100,'Standard',1,'Active'],[2,2,'2099-2100','1st',200,'Standard',0,'Active'],[3,3,'2099-2100','1st',300,'One-Time',0,'Active'],[4,4,'2099-2100','1st',400,'Optional',0,'Active'],[5,5,'2099-2100','1st',500,'Manual',0,'Active'],[6,6,'2099-2100','1st',0,'Standard',1,'Active'],[7,7,'2099-2100','1st',700,'Standard',1,'Draft'],[8,1,'2098-2099','1st',100,'Standard',1,'Active']];
$versionInsert=$pdo->prepare('INSERT INTO fee_versions VALUES (?,?,?,?,?,?,?,?)'); foreach($versions as $row)$versionInsert->execute($row);
$scope=$pdo->prepare('INSERT INTO fee_applicability VALUES (?,?,?,?,?,?)'); foreach([1,2,3,4,5,6] as $id)$scope->execute([$id,$id,null,null,1,1]);
$context = BillingStudentContext::fromArray(['student_id'=>99,'student_number'=>'S99','full_name'=>'Preview Test','academic_year'=>'2099-2100','semester'=>'1st','program_code'=>'BSIT','year_level'=>'1','section'=>null,'financially_eligible'=>true,'eligibility_source'=>'isolated_test','metadata'=>['program_code_canonical'=>true]]);
$service = new ManagedStandardAssessmentService($pdo); $result=$service->preview($context,[2,3])->toArray();
$checks = 0;
function assertion(bool $ok, string $message): void { global $checks; $checks++; if(!$ok) throw new RuntimeException($message); }
$by=[]; foreach($result['items'] as $item)$by[$item['fee_version_id']]=$item;
assertion($by[1]['classification']==='NEW_REQUIRED_STANDARD','required Standard is automatic');
assertion($by[2]['classification']==='REVIEWABLE_STANDARD' && $by[2]['selected_for_preview'],'non-required Standard is reviewable');
assertion($by[3]['classification']==='ONE_TIME_ELIGIBLE' && $by[3]['selected_for_preview'],'new One-Time is reviewable');
assertion($by[4]['classification']==='OPTIONAL_OPTIN_REQUIRED','Optional is excluded');
assertion($by[5]['classification']==='MANUAL_EXCLUDED_FROM_BULK','Manual is excluded');
assertion($by[6]['classification']==='FEE_CONFIGURATION_ERROR','zero amount is configuration error');
assertion(!isset($by[7]) && !isset($by[8]),'Draft and wrong-term versions are not candidates');
assertion($result['summary']['automatic_new_amount']===100.0 && $result['summary']['selected_reviewable_amount']===500.0 && $result['summary']['projected_new_amount']===600.0,'amounts are separated and deterministic');
$pdo->exec("INSERT INTO billing VALUES (10,99,'2099-2100','1st','Assessment','Unpaid',100,100); INSERT INTO managed_assessment_headers VALUES (1,99,'2099-2100','1st',10); INSERT INTO billing_items VALUES (1,10,1,1,'Required')");
$duplicate=$service->preview($context)->toArray(); $duplicateBy=[]; foreach($duplicate['items'] as $item)$duplicateBy[$item['fee_version_id']]=$item;
assertion($duplicateBy[1]['classification']==='ALREADY_ASSESSED','managed duplicate uses fee_version identity');
$pdo->exec("INSERT INTO billing VALUES (11,99,'2098-2099','1st','Assessment','Paid',300,0); INSERT INTO billing_items VALUES (2,11,3,NULL,'One Time')");
$history=$service->preview($context)->toArray(); $historyBy=[]; foreach($history['items'] as $item)$historyBy[$item['fee_version_id']]=$item;
assertion($historyBy[3]['classification']==='ONE_TIME_ALREADY_ASSESSED','One-Time history uses fee_id across legacy rows');

function contextFor(int $studentId, ?string $program = 'BSIT', ?string $year = '1', bool $canonical = true): BillingStudentContext {
    return BillingStudentContext::fromArray(['student_id'=>$studentId,'student_number'=>'S'.$studentId,'full_name'=>'Preview Test','academic_year'=>'2099-2100','semester'=>'1st','program_code'=>$program,'year_level'=>$year,'section'=>null,'financially_eligible'=>true,'eligibility_source'=>'isolated_test','metadata'=>['program_code_canonical'=>$canonical]]);
}
function financialCounts(PDO $pdo): array {
    $out=[]; foreach(['billing','billing_items','managed_assessment_headers','billing_runs','billing_run_fee_versions','billing_run_assignments','billing_notification_outbox'] as $table) $out[$table]=(int)$pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn(); return $out;
}
function readonlyPreview(ManagedStandardAssessmentService $service, PDO $pdo, BillingStudentContext $context, array $selected=[]): array {
    $before=financialCounts($pdo); $result=$service->preview($context,$selected)->toArray(); assertion($before===financialCounts($pdo),'Preview must not mutate financial or managed-run tables'); return $result;
}
function itemsByVersion(array $preview): array { $out=[]; foreach($preview['items'] as $item)$out[$item['fee_version_id']]=$item; return $out; }

// Add all applicability shapes without using students.course/year_level as fallback.
$moreFees=[[8,'ALLYEAR','All Year',1,'Active'],[9,'PROGRAM','Program',1,'Active'],[10,'BOTH','Both',1,'Active'],[11,'PROGMIS','Program Mismatch',1,'Active'],[12,'YEARMIS','Year Mismatch',1,'Active']];
foreach($moreFees as $row)$feeInsert->execute($row);
$moreVersions=[[9,8,'2099-2100','1st',110,'Standard',1,'Active'],[10,9,'2099-2100','1st',120,'Standard',1,'Active'],[11,10,'2099-2100','1st',130,'Standard',1,'Active'],[12,11,'2099-2100','1st',140,'Standard',1,'Active'],[13,12,'2099-2100','1st',150,'Standard',1,'Active'],[14,8,'2099-2100','2nd',110,'Standard',1,'Active'],[15,8,'2099-2100','1st',110,'Standard',1,'Archived']];
foreach($moreVersions as $row)$versionInsert->execute($row);
$scope->execute([9,9,null,'2',1,0]);       // all program, specific year
$scope->execute([10,10,'BSIT',null,0,1]);  // specific program, all year
$scope->execute([11,11,'BSIT','2',0,0]);   // specific both
$scope->execute([12,12,'BSCS',null,0,1]);
$scope->execute([13,13,null,'3',1,0]);
$yearTwo = readonlyPreview($service,$pdo,contextFor(100,'BSIT','2'));
$yearTwoItems=itemsByVersion($yearTwo);
assertion($yearTwoItems[9]['classification']==='NEW_REQUIRED_STANDARD','all-program specific-year match applies');
assertion($yearTwoItems[10]['classification']==='NEW_REQUIRED_STANDARD','specific-program all-year match applies with canonical code');
assertion($yearTwoItems[11]['classification']==='NEW_REQUIRED_STANDARD','specific-program specific-year match applies');
assertion($yearTwoItems[12]['classification']==='NOT_APPLICABLE','specific-program mismatch is not applicable');
assertion($yearTwoItems[13]['classification']==='NOT_APPLICABLE','specific-year mismatch is not applicable');
assertion(!isset($yearTwoItems[14]) && !isset($yearTwoItems[15]),'wrong semester and archived versions are excluded');
$missingProgram=itemsByVersion(readonlyPreview($service,$pdo,contextFor(101,null,'2',false)));
assertion($missingProgram[10]['classification']==='APPLICABILITY_CONTEXT_MISSING','missing canonical program fails closed');
$missingYear=itemsByVersion(readonlyPreview($service,$pdo,contextFor(102,'BSIT',null,true)));
assertion($missingYear[9]['classification']==='APPLICABILITY_CONTEXT_MISSING','missing required year fails closed');

// Ambiguous legacy One-Time history is a review blocker, while legacy rows remain readable.
$pdo->exec("INSERT INTO billing VALUES (20,103,'2098-2099','1st','Assessment','Paid',300,0); INSERT INTO billing_items VALUES (3,20,3,NULL,'Historical renamed fee')");
$ambiguous=itemsByVersion(readonlyPreview($service,$pdo,contextFor(103)));
assertion($ambiguous[3]['classification']==='ONE_TIME_HISTORY_AMBIGUOUS','conflicting legacy snapshot is ambiguous');
assertion($service->preview(contextFor(103))->toArray()['status']==='ReviewRequired','ambiguous One-Time history blocks student preview');

// A same-term canonical header alone is not a legacy conflict; a managed duplicate remains independently detectable.
$pdo->exec("INSERT INTO billing VALUES (30,104,'2099-2100','1st','Assessment','Unpaid',100,100); INSERT INTO managed_assessment_headers VALUES (2,104,'2099-2100','1st',30); INSERT INTO billing_items VALUES (4,30,1,1,'Required')");
$managedOnly=readonlyPreview($service,$pdo,contextFor(104)); $managedItems=itemsByVersion($managedOnly);
assertion($managedOnly['legacy_conflict']===null,'canonical managed billing alone is not legacy conflict');
assertion($managedItems[1]['classification']==='ALREADY_ASSESSED','canonical same version is already assessed');
assertion($managedOnly['status']==='PartiallyAssessed','some managed duplicates plus remaining automatic fees are partial');

// Context validation is deterministic and never invents academic values.
try { BillingStudentContext::fromArray(['student_id'=>0,'student_number'=>'','full_name'=>'','academic_year'=>'bad','semester'=>'X','financially_eligible'=>true,'eligibility_source'=>'x']); throw new RuntimeException('invalid context should throw'); }
catch (InvalidArgumentException) { assertion(true,'invalid context fails closed'); }
assertion($service->preview(BillingStudentContext::fromArray(['student_id'=>105,'student_number'=>'S105','full_name'=>'Ineligible','academic_year'=>'2099-2100','semester'=>'1st','financially_eligible'=>false,'eligibility_source'=>'fixture']))->toArray()['status']==='InvalidContext','financially ineligible context is invalid');
$pdo->exec("INSERT INTO billing VALUES (12,99,'2099-2100','1st','Enrollment','Partial',100,50)");
assertion($service->preview($context)->toArray()['status']==='ReviewRequired','unmanaged same-term billing blocks commit preview');
assertion(!str_contains((string)file_get_contents(__DIR__.'/../modules/payment/includes/ManagedStandardAssessmentService.php'),'FOR UPDATE'),'Preview source contains no locking read');
echo "Managed assessment regression passed: $checks checks across applicability, lifecycle, history, duplicate, status, and read-only coverage.\n";
