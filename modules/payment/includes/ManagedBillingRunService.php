<?php
declare(strict_types=1);

require_once __DIR__ . '/BillingStudentContextProvider.php';
require_once __DIR__ . '/RegistrarCohortClient.php';
require_once __DIR__ . '/ManagedStandardAssessmentService.php';
require_once __DIR__ . '/ManagedStandardAssessmentWriter.php';

final class ManagedBillingRunException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $httpStatus = 422, ?Throwable $previous = null)
    { parent::__construct($message, 0, $previous); }
}

/** Coordinates Phase 4 per-student writes; it intentionally owns no billing rules. */
final class ManagedBillingRunService
{
    private const CHUNK_SIZES = [25, 50, 100];
    public function __construct(private readonly PDO $pdo) {}

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function preview(array $input): array
    {
        $resolved = $this->resolveCohort($input);
        $fees = $this->runFeeSnapshot($resolved['academic_year'], $resolved['semester'], $input['selected_reviewable_fee_version_ids'] ?? []);
        return ['academic_year'=>$resolved['academic_year'], 'semester'=>$resolved['semester'], 'cohort_count'=>count($resolved['students']), 'cohort_id'=>$resolved['cohort_id'], 'fee_versions'=>$fees, 'projected_amount'=>round(array_sum(array_column(array_filter($fees, static fn($f)=>$f['selection_state']==='Selected'), 'preview_amount_snapshot')) * count($resolved['students']), 2)];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createDraft(array $input, int $actorId, string $actorName = ''): array
    {
        if ($actorId <= 0) throw new ManagedBillingRunException('ACTOR_INVALID', 'A valid Accounting actor is required.');
        $preview = $this->preview($input); $resolved = $this->resolveCohort($input); $cohort = $resolved['students'];
        $freshFees = $this->runFeeSnapshot($resolved['academic_year'], $resolved['semester'], $input['selected_reviewable_fee_version_ids'] ?? []);
        $freshProjectedAmount = round(array_sum(array_column(array_filter($freshFees, static fn($f)=>$f['selection_state']==='Selected'), 'preview_amount_snapshot')) * count($cohort), 2);
        if (!$cohort) throw new ManagedBillingRunException('COHORT_EMPTY', 'A controlled cohort is required.');
        $this->pdo->beginTransaction();
        try {
            $q=$this->pdo->prepare("INSERT INTO billing_runs (academic_year,semester,course,year_level,status,initiated_by,initiated_by_name_snapshot,projected_student_count,projected_amount) VALUES (?,?,NULL,NULL,'Draft',?,?,?,?)");
            $q->execute([$resolved['academic_year'],$resolved['semester'],$actorId,$this->actorName($actorName,$actorId),count($cohort),$freshProjectedAmount]); $runId=(int)$this->pdo->lastInsertId();
            $fee=$this->pdo->prepare('INSERT INTO billing_run_fee_versions (run_id,fee_version_id,behavior,is_required,selection_state,preview_name_snapshot,preview_amount_snapshot) VALUES (?,?,?,?,?,?,?)');
            foreach($freshFees as $row) $fee->execute([$runId,$row['fee_version_id'],$row['behavior'],$row['is_required']?1:0,$row['selection_state'],$row['preview_name_snapshot'],$row['preview_amount_snapshot']]);
            $assignment=$this->pdo->prepare("INSERT INTO billing_run_assignments (run_id,student_id,student_number,student_full_name,program_code,year_level,enrollment_status,status) VALUES (?,?,?,?,?,?,?,'Pending')");
            foreach($cohort as $row) $assignment->execute([$runId,$row['student_id'],$row['student_number'],$row['full_name'],$row['program_code'],$row['year_level'],$row['enrollment_status']]);
            $this->pdo->commit(); $result=$this->detail($runId); $result['cohort_revalidated']=true; $result['preview_cohort_count']=(int)$preview['cohort_count']; $result['authoritative_cohort_count']=count($cohort); return $result;
        } catch(Throwable $e) { if($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e instanceof ManagedBillingRunException ? $e : new ManagedBillingRunException('DRAFT_CREATE_FAILED','Managed bulk draft could not be created.',500,$e); }
    }

    /** @return array<string,mixed> */
    public function approve(int $runId, int $actorId, string $actorName = ''): array
    {
        $this->pdo->beginTransaction();
        try { $q=$this->pdo->prepare('SELECT initiated_by,status FROM billing_runs WHERE run_id=? FOR UPDATE'); $q->execute([$runId]); $run=$q->fetch();
            if(!$run) throw new ManagedBillingRunException('RUN_NOT_FOUND','Managed billing run was not found.',404);
            if($run['status']!=='Draft') throw new ManagedBillingRunException('RUN_NOT_DRAFT','Only Draft runs can be approved.');
            if((int)$run['initiated_by']===$actorId) throw new ManagedBillingRunException('SELF_APPROVAL_FORBIDDEN','Draft creator cannot approve this run.',403);
            $this->pdo->prepare("UPDATE billing_runs SET status='Approved',approved_by=?,approved_by_name_snapshot=?,approved_at=CURRENT_TIMESTAMP WHERE run_id=?")->execute([$actorId,$this->actorName($actorName,$actorId),$runId]); $this->pdo->commit(); return $this->detail($runId);
        } catch(Throwable $e) { if($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }

    /** @return array<string,mixed> */
    public function processChunk(int $runId, int $actorId, int $batchSize=50): array
    {
        $this->requireDevelopmentContext(); if(!in_array($batchSize,self::CHUNK_SIZES,true)) throw new ManagedBillingRunException('CHUNK_SIZE_INVALID','Chunk size must be 25, 50, or 100.');
        $token=bin2hex(random_bytes(16)); $run=$this->claimRun($runId,$token); $selected=$this->selectedVersions($runId); $assignments=$this->claimAssignments($runId,$batchSize);
        $service=new ManagedStandardAssessmentService($this->pdo); $writer=new ManagedStandardAssessmentWriter($this->pdo,$service);
        foreach($assignments as $assignment) { $this->refreshLease($runId,$token); $this->processAssignment($assignment,$run,$selected,$writer,$actorId); }
        $this->pdo->prepare('UPDATE billing_runs SET runner_token=NULL,lease_expires_at=NULL,processing_cursor=processing_cursor+? WHERE run_id=? AND runner_token=?')->execute([count($assignments),$runId,$token]);
        return $this->reconcile($runId);
    }

    /** @return array<string,mixed> */
    public function retry(int $runId): array
    { $this->pdo->prepare("UPDATE billing_run_assignments SET status='Pending',exception_code=NULL,exception_message=NULL,last_error=NULL WHERE run_id=? AND status='Failed'")->execute([$runId]); return $this->reconcile($runId); }

    /** @return array<string,mixed> */
    public function detail(int $runId, int $page = 1, int $pageSize = 25, string $studentSearch = '', string $assignmentStatus = ''): array
    { $pageSize=$this->pageSize($pageSize,[25,50,100],25); $page=max(1,$page);
      $q=$this->pdo->prepare('SELECT * FROM billing_runs WHERE run_id=?'); $q->execute([$runId]); $run=$q->fetch(); if(!$run) throw new ManagedBillingRunException('RUN_NOT_FOUND','Managed billing run was not found.',404);
      $f=$this->pdo->prepare('SELECT * FROM billing_run_fee_versions WHERE run_id=? ORDER BY fee_version_id'); $f->execute([$runId]);
      $where=['run_id=?']; $params=[$runId]; $studentSearch=trim($studentSearch); if($studentSearch!==''){if(mb_strlen($studentSearch)>100)throw new ManagedBillingRunException('FILTER_INVALID','Student search is too long.');$where[]='(student_number LIKE ? OR student_full_name LIKE ?)';$params[]='%'.$studentSearch.'%';$params[]='%'.$studentSearch.'%';}
      $allowedStatuses=['Pending','Processing','Added','PartiallyAdded','AlreadyAssessed','NoApplicableFees','ReviewRequired','Failed']; if($assignmentStatus!==''){if(!in_array($assignmentStatus,$allowedStatuses,true))throw new ManagedBillingRunException('FILTER_INVALID','Assignment status filter is invalid.');$where[]='status=?';$params[]=$assignmentStatus;}
      $sql=' WHERE '.implode(' AND ',$where); $c=$this->pdo->prepare('SELECT COUNT(*) FROM billing_run_assignments'.$sql); $c->execute($params); $total=(int)$c->fetchColumn(); $offset=($page-1)*$pageSize;
      $a=$this->pdo->prepare('SELECT * FROM billing_run_assignments'.$sql.' ORDER BY assignment_id LIMIT ? OFFSET ?'); foreach($params as $i=>$value)$a->bindValue($i+1,$value);$a->bindValue(count($params)+1,$pageSize,PDO::PARAM_INT);$a->bindValue(count($params)+2,$offset,PDO::PARAM_INT);$a->execute();
      return ['run'=>$run,'fee_versions'=>$f->fetchAll(),'assignments'=>$a->fetchAll(),'assignment_pagination'=>$this->pagination($page,$pageSize,$total),'assignment_filters'=>['student_search'=>$studentSearch,'status'=>$assignmentStatus]]; }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function listRuns(array $filters): array
    { $page=max(1,(int)($filters['page']??1));$size=$this->pageSize((int)($filters['page_size']??10),[10,25,50,100],10);$where=[];$params=[];
      $runId=(int)($filters['run_id']??0);if($runId>0){$where[]='run_id=?';$params[]=$runId;}
      $ay=trim((string)($filters['academic_year']??''));if($ay!==''){if(!preg_match('/^\d{4}-\d{4}$/',$ay))throw new ManagedBillingRunException('FILTER_INVALID','Academic year filter is invalid.');$where[]='academic_year=?';$params[]=$ay;}
      $semester=(string)($filters['semester']??'');if($semester!==''){if(!in_array($semester,['1st','2nd','Summer'],true))throw new ManagedBillingRunException('FILTER_INVALID','Semester filter is invalid.');$where[]='semester=?';$params[]=$semester;}
      $status=(string)($filters['status']??'');$statuses=['Draft','Approved','Running','Completed','CompletedWithExceptions','Failed'];if($status!==''){ $selected=array_values(array_filter(array_map('trim',explode(',',$status)),static fn($value)=>$value!=='')); if(!$selected||array_diff($selected,$statuses))throw new ManagedBillingRunException('FILTER_INVALID','Run status filter is invalid.'); $where[]='status IN ('.implode(',',array_fill(0,count($selected),'?')).')'; array_push($params,...$selected); }
      $sql=$where?' WHERE '.implode(' AND ',$where):'';$count=$this->pdo->prepare('SELECT COUNT(*) FROM billing_runs'.$sql);$count->execute($params);$total=(int)$count->fetchColumn();$offset=($page-1)*$size;
      $q=$this->pdo->prepare('SELECT * FROM billing_runs'.$sql.' ORDER BY run_id DESC LIMIT ? OFFSET ?');foreach($params as $i=>$v)$q->bindValue($i+1,$v);$q->bindValue(count($params)+1,$size,PDO::PARAM_INT);$q->bindValue(count($params)+2,$offset,PDO::PARAM_INT);$q->execute();return ['runs'=>$q->fetchAll(),'pagination'=>$this->pagination($page,$size,$total)]; }

    private function pageSize(int $value,array $allowed,int $default): int { return in_array($value,$allowed,true)?$value:$default; }
    private function actorName(string $name,int $actorId): string { $name=trim($name); return $name!==''?mb_substr($name,0,255):'User #'.$actorId; }
    /** @return array<string,int> */ private function pagination(int $page,int $size,int $total): array { $pages=max(1,(int)ceil($total/$size));return ['page'=>min($page,$pages),'page_size'=>$size,'total_rows'=>$total,'total_pages'=>$pages]; }

    private function requireDevelopmentContext(): void { if(!BillingStudentContextProviderFactory::developmentEnabled()) throw new AcademicContextUnavailableException(); }
    /** @return array{academic_year:string,semester:string,cohort_id:string,students:list<array<string,mixed>>} */
    private function resolveCohort(array $input): array
    {
      // Controlled JSON is an explicit test-only source. Production never accepts it.
      if (BillingStudentContextProviderFactory::developmentEnabled() && array_key_exists('cohort', $input)) {
        $year=(string)($input['academic_year']??'');$semester=(string)($input['semester']??'');
        return ['academic_year'=>$year,'semester'=>$semester,'cohort_id'=>'development-controlled','students'=>$this->normaliseCohort($input['cohort'],$year,$semester)];
      }
      $client=new RegistrarCohortClient(); $context=$client->getActiveEnrollmentContext(); $cohortId=trim((string)($input['cohort_id']??''));
      $options=$client->listAvailableCohorts($context); $option=null; foreach($options as $row) if(hash_equals($row['cohort_id'],$cohortId)){$option=$row;break;}
      if($option===null) throw new ManagedBillingRunException('COHORT_INVALID','Selected cohort is not an available Registrar cohort.');
      $rows=$client->getCohortStudents($context,$cohortId); if(!$rows) throw new ManagedBillingRunException('COHORT_EMPTY','No eligible students were returned for this cohort.');
      $out=[];$seen=[];foreach($rows as $raw){if(!is_array($raw))throw new ManagedBillingRunException('COHORT_RESPONSE_INVALID','Registrar returned an invalid student.');$source=trim((string)($raw['source_student_id']??''));$number=trim((string)($raw['student_number']??''));$name=trim((string)($raw['full_name']??''));$program=trim((string)($raw['program_code']??''));$year=trim((string)($raw['year_level']??''));$status=trim((string)($raw['enrollment_status']??''));if($source===''||$number===''||$name===''||$program===''||$year===''||$status!=='Enrolled')throw new ManagedBillingRunException('COHORT_RESPONSE_INVALID','Registrar student context is incomplete.');if(isset($seen[$number]))throw new ManagedBillingRunException('COHORT_DUPLICATE_STUDENT','Registrar returned a duplicate student.');$q=$this->pdo->prepare('SELECT student_id,student_number FROM students WHERE student_number=? LIMIT 1');$q->execute([$number]);$local=$q->fetch();if(!$local)throw new ManagedBillingRunException('COHORT_IDENTITY_MISMATCH','A Registrar student could not be resolved in Payment.');$seen[$number]=true;$out[]=['student_id'=>(int)$local['student_id'],'student_number'=>$number,'full_name'=>$name,'program_code'=>$program,'year_level'=>$year,'enrollment_status'=>$status];}
      return ['academic_year'=>$context['academic_year'],'semester'=>$context['semester'],'cohort_id'=>$cohortId,'students'=>$out];
    }
    /** @return array<string,mixed> */ public function cohortBootstrap(): array
    { if(BillingStudentContextProviderFactory::developmentEnabled()) return ['source'=>'development','context'=>null,'cohorts'=>[]]; $client=new RegistrarCohortClient();$context=$client->getActiveEnrollmentContext();return ['source'=>'registrar','context'=>$context,'cohorts'=>$client->listAvailableCohorts($context)]; }
    /** @return array<string,mixed> */ public function cohortPreview(array $input): array
    { $resolved=$this->resolveCohort($input);$page=max(1,(int)($input['cohort_page']??1));$size=$this->pageSize((int)($input['cohort_page_size']??25),[25,50,100],25);$total=count($resolved['students']);$pages=max(1,(int)ceil($total/$size));$page=min($page,$pages);return ['academic_year'=>$resolved['academic_year'],'semester'=>$resolved['semester'],'cohort_id'=>$resolved['cohort_id'],'students'=>array_slice($resolved['students'],($page-1)*$size,$size),'pagination'=>$this->pagination($page,$size,$total)]; }
    /** @return list<array<string,mixed>> */
    private function normaliseCohort(mixed $cohort,string $year,string $semester): array
    { $this->requireDevelopmentContext(); if(!is_array($cohort)||!preg_match('/^\d{4}-\d{4}$/',$year)||!in_array($semester,['1st','2nd','Summer'],true)) throw new ManagedBillingRunException('COHORT_INVALID','Controlled cohort and valid term are required.'); $out=[];$seen=[];
      foreach($cohort as $raw){ if(!is_array($raw)) throw new ManagedBillingRunException('COHORT_INVALID','Every cohort entry must be an object.'); $status=trim((string)($raw['enrollment_status']??'')); if(trim((string)($raw['full_name']??''))==='') throw new ManagedBillingRunException('COHORT_INVALID','Every cohort entry requires a student full name.'); $raw['academic_year']=$year;$raw['semester']=$semester;$raw['financially_eligible']=$status==='Enrolled'; $context=(new DevelopmentManualBillingStudentContextProvider())->resolve($raw); if(isset($seen[$context->studentId])) throw new ManagedBillingRunException('COHORT_DUPLICATE_STUDENT','Cohort contains a duplicate student.');
        $check=$this->pdo->prepare('SELECT student_number,full_name FROM students WHERE student_id=?');$check->execute([$context->studentId]);$student=$check->fetch(); if(!$student||$student['student_number']!==$context->studentNumber) throw new ManagedBillingRunException('COHORT_STUDENT_INVALID','Controlled cohort student does not match Payment identity.');
        $seen[$context->studentId]=true; $out[]=['student_id'=>$context->studentId,'student_number'=>$context->studentNumber,'full_name'=>trim((string)$raw['full_name']),'program_code'=>$context->programCode,'year_level'=>$context->yearLevel,'enrollment_status'=>$status]; }
      return $out; }
    /** @return list<array<string,mixed>> */
    private function runFeeSnapshot(string $year,string $semester,mixed $requested): array
    { if(!is_array($requested)) throw new ManagedBillingRunException('SELECTION_INVALID','Fee-version selection must be a list.'); $selected=[];foreach($requested as $id){if(!is_scalar($id)||!ctype_digit((string)$id)||(int)$id<1)throw new ManagedBillingRunException('SELECTION_INVALID','Invalid fee-version selection.');$selected[(int)$id]=true;}
      $q=$this->pdo->prepare("SELECT fv.fee_version_id,fv.behavior,fv.is_required,fv.amount,f.fee_name FROM fee_versions fv JOIN fees f ON f.fee_id=fv.fee_id WHERE fv.academic_year=? AND fv.semester=? AND fv.effective_status='Active' AND f.identity_status='Active' ORDER BY fv.fee_version_id");$q->execute([$year,$semester]);$rows=$q->fetchAll();$known=[];$out=[];foreach($rows as $r){$id=(int)$r['fee_version_id'];$known[$id]=true;$allowed=$r['behavior']==='Standard'||$r['behavior']==='One-Time';$state=($r['behavior']==='Standard'&&(int)$r['is_required']===1)||($allowed&&isset($selected[$id]))?'Selected':'Excluded';$out[]=['fee_version_id'=>$id,'behavior'=>$r['behavior'],'is_required'=>(bool)$r['is_required'],'selection_state'=>$state,'preview_name_snapshot'=>$r['fee_name'],'preview_amount_snapshot'=>(float)$r['amount']];}
      foreach($selected as $id=>$_){if(!isset($known[$id]))throw new ManagedBillingRunException('SELECTION_INVALID','Selected fee version is not active for this term.');$snapshot=array_values(array_filter($out,static fn(array $row): bool => (int)$row['fee_version_id']===$id))[0]??null;if(!$snapshot||!in_array($snapshot['behavior'],['Standard','One-Time'],true)||($snapshot['behavior']==='Standard'&&$snapshot['is_required']))throw new ManagedBillingRunException('SELECTION_INVALID','Selected fee version is not reviewable.');} return $out; }
    /** @return array<string,mixed> */
    private function claimRun(int $runId,string $token): array { $this->pdo->beginTransaction();try{$q=$this->pdo->prepare('SELECT * FROM billing_runs WHERE run_id=? FOR UPDATE');$q->execute([$runId]);$run=$q->fetch();if(!$run)throw new ManagedBillingRunException('RUN_NOT_FOUND','Managed billing run was not found.',404);if(!in_array($run['status'],['Approved','Running'],true))throw new ManagedBillingRunException('RUN_NOT_PROCESSABLE','Run is not approved for processing.');if($run['lease_expires_at']&&strtotime($run['lease_expires_at'])>time())throw new ManagedBillingRunException('RUN_LEASED','Run is currently being processed.',409);if($run['status']==='Running')$this->pdo->prepare("UPDATE billing_run_assignments SET status='Pending',last_error=COALESCE(last_error,'Processing lease expired; resumed.') WHERE run_id=? AND status='Processing'")->execute([$runId]);$this->pdo->prepare("UPDATE billing_runs SET status='Running',runner_token=?,lease_expires_at=DATE_ADD(CURRENT_TIMESTAMP,INTERVAL 15 MINUTE),started_at=COALESCE(started_at,CURRENT_TIMESTAMP) WHERE run_id=?")->execute([$token,$runId]);$this->pdo->commit();return $run;}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;} }
    private function refreshLease(int $runId,string $token): void { $q=$this->pdo->prepare("UPDATE billing_runs SET lease_expires_at=DATE_ADD(CURRENT_TIMESTAMP,INTERVAL 15 MINUTE) WHERE run_id=? AND runner_token=? AND status='Running'"); $q->execute([$runId,$token]); $owned=$this->pdo->prepare("SELECT 1 FROM billing_runs WHERE run_id=? AND runner_token=? AND status='Running'"); $owned->execute([$runId,$token]); if(!$owned->fetchColumn()) throw new ManagedBillingRunException('RUN_LEASE_LOST','Managed bulk processing lease was lost.',409); }
    /** @return list<array<string,mixed>> */
    private function claimAssignments(int $runId,int $limit): array { $this->pdo->beginTransaction();try{$q=$this->pdo->prepare("SELECT * FROM billing_run_assignments WHERE run_id=? AND status='Pending' ORDER BY assignment_id LIMIT $limit FOR UPDATE");$q->execute([$runId]);$rows=$q->fetchAll();if($rows){$ids=array_column($rows,'assignment_id');$marks=implode(',',array_fill(0,count($ids),'?'));$this->pdo->prepare("UPDATE billing_run_assignments SET status='Processing',attempt_count=attempt_count+1,started_at=COALESCE(started_at,CURRENT_TIMESTAMP) WHERE assignment_id IN ($marks)")->execute($ids);}$this->pdo->commit();return $rows;}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;} }
    /** @param array<string,mixed> $a @param array<string,mixed> $run @param list<int> $selected */
    private function processAssignment(array $a,array $run,array $selected,ManagedStandardAssessmentWriter $writer,int $actorId): void { try{$this->pdo->beginTransaction();$context=BillingStudentContext::fromArray(['student_id'=>(int)$a['student_id'],'student_number'=>$a['student_number'],'full_name'=>$a['student_full_name'],'academic_year'=>$run['academic_year'],'semester'=>$run['semester'],'program_code'=>$a['program_code'],'year_level'=>$a['year_level'],'financially_eligible'=>$a['enrollment_status']==='Enrolled','eligibility_source'=>'managed_bulk_snapshot','metadata'=>['program_code_canonical'=>true,'development_context'=>true]]);$result=$writer->commitStudentAssessment($context,$selected,$actorId,false);$this->saveOutcome($a,$result);$this->pdo->commit();}catch(ManagedAssessmentCommitException $e){if($this->pdo->inTransaction())$this->pdo->rollBack();$status=$e->errorCode==='REVIEW_REQUIRED_LEGACY_TERM_BILLING'?'ReviewRequired':'Failed';$this->saveFailure((int)$a['assignment_id'],$status,$e->errorCode,$e->getMessage());}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();error_log('Managed bulk assignment failure: '.$e->getMessage());$this->saveFailure((int)$a['assignment_id'],'Failed','MANAGED_BULK_ASSIGNMENT_FAILED','Managed assignment could not be processed.');} }
    /** @param array<string,mixed> $a @param array<string,mixed> $r */
    private function saveOutcome(array $a,array $r): void { $status=in_array($r['outcome'],['Added','PartiallyAdded','AlreadyAssessed','NoApplicableFees'],true)?$r['outcome']:'Failed';$q=$this->pdo->prepare('UPDATE billing_run_assignments SET status=?,billing_id=?,added_item_count=?,added_amount=?,duplicate_count=?,excluded_count=?,notification_state=?,processed_at=CURRENT_TIMESTAMP WHERE assignment_id=?');$notify=in_array($status,['Added','PartiallyAdded'],true)&&(int)$r['added_item_count']>0&&(float)$r['added_amount']>0?'Pending':'NotRequired';$q->execute([$status,$r['billing_id'],(int)$r['added_item_count'],(float)$r['added_amount'],(int)$r['existing_item_count'],(int)$r['excluded_item_count'],$notify,$a['assignment_id']]);if($notify==='Pending'){$s=$r['billing_summary']??[];$out=$this->pdo->prepare("INSERT INTO billing_notification_outbox (run_id,student_id,billing_id,event_key,academic_year,semester,new_assessment_amount,term_remaining_balance,delivery_status) SELECT a.run_id,a.student_id,a.billing_id,CONCAT('managed-run:',a.run_id,':student:',a.student_id),r.academic_year,r.semester,?,?,'Pending' FROM billing_run_assignments a JOIN billing_runs r ON r.run_id=a.run_id WHERE a.assignment_id=?");try{$out->execute([(float)$r['added_amount'],(float)($s['remaining_balance']??0),$a['assignment_id']]);}catch(PDOException $e){if($e->getCode()!=='23000')throw $e;}} }
    private function saveFailure(int $id,string $status,string $code,string $message): void {$this->pdo->prepare('UPDATE billing_run_assignments SET status=?,exception_code=?,exception_message=?,last_error=?,notification_state=\'NotRequired\',processed_at=CURRENT_TIMESTAMP WHERE assignment_id=?')->execute([$status,$code,$message,$message,$id]);}
    /** @return list<int> */ private function selectedVersions(int $runId): array{$q=$this->pdo->prepare("SELECT fee_version_id FROM billing_run_fee_versions WHERE run_id=? AND selection_state='Selected'");$q->execute([$runId]);return array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));}
    /** @return array<string,mixed> */ private function reconcile(int $runId): array{$q=$this->pdo->prepare("SELECT status,COUNT(*) c,COALESCE(SUM(added_item_count),0) items,COALESCE(SUM(added_amount),0) amount FROM billing_run_assignments WHERE run_id=? GROUP BY status");$q->execute([$runId]);$rows=$q->fetchAll();$counts=[];$items=0;$amount=0;foreach($rows as $r){$counts[$r['status']]=(int)$r['c'];$items+=(int)$r['items'];$amount+=(float)$r['amount'];}$unfinished=($counts['Pending']??0)+($counts['Processing']??0);$exceptions=($counts['Failed']??0)+($counts['ReviewRequired']??0);$status=$unfinished?'Running':($exceptions?'CompletedWithExceptions':'Completed');$u=$this->pdo->prepare('UPDATE billing_runs SET status=?,actual_added_student_count=?,actual_partially_added_student_count=?,actual_already_assessed_student_count=?,actual_no_applicable_fee_student_count=?,actual_review_required_student_count=?,actual_failed_student_count=?,actual_added_item_count=?,actual_added_amount=?,completed_at=CASE WHEN ? IN (\'Completed\',\'CompletedWithExceptions\') THEN CURRENT_TIMESTAMP ELSE NULL END WHERE run_id=?');$u->execute([$status,$counts['Added']??0,$counts['PartiallyAdded']??0,$counts['AlreadyAssessed']??0,$counts['NoApplicableFees']??0,$counts['ReviewRequired']??0,$counts['Failed']??0,$items,$amount,$status,$runId]);return $this->detail($runId);}
}
