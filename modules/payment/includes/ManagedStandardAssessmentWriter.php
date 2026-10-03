<?php
declare(strict_types=1);
require_once __DIR__ . '/ManagedStandardAssessmentService.php';

final class ManagedAssessmentCommitException extends RuntimeException {
    public function __construct(public readonly string $errorCode, string $message, public readonly int $httpStatus = 422, ?Throwable $previous = null) { parent::__construct($message, 0, $previous); }
}
/** Transactional writer. It owns no fee-resolution rules: preview() is re-run at commit time. */
final class ManagedStandardAssessmentWriter
{
    public function __construct(private readonly PDO $pdo, private readonly ManagedStandardAssessmentService $assessment) {}
    /** @param list<int|string> $selection @return array<string,mixed> */
    public function commitStudentAssessment(BillingStudentContext $context, array $selection, int $actorId, bool $manageTransaction = true): array
    {
        if ($actorId <= 0) throw new ManagedAssessmentCommitException('ACTOR_INVALID', 'A valid Accounting actor is required.');
        if ($manageTransaction) $this->pdo->beginTransaction();
        elseif (!$this->pdo->inTransaction()) throw new LogicException('Caller-owned managed assessment commit requires an active transaction.');
        try {
            // Serialize canonical creation for cooperating managed writers, then revalidate stale Preview state.
            $lock=$this->pdo->prepare('SELECT student_id FROM students WHERE student_id=? FOR UPDATE'); $lock->execute([$context->studentId]);
            if (!$lock->fetchColumn()) throw new ManagedAssessmentCommitException('STUDENT_NOT_FOUND','Student does not exist in Payment records.',404);
            $preview=$this->assessment->preview($context,$selection)->toArray();
            if ($preview['status']==='InvalidContext') throw new ManagedAssessmentCommitException('INVALID_CONTEXT','Managed billing context is invalid.');
            if ($preview['legacy_conflict']) throw new ManagedAssessmentCommitException('REVIEW_REQUIRED_LEGACY_TERM_BILLING','Same-term unmanaged billing requires Accounting review.');
            foreach ($preview['items'] as $item) if (in_array($item['classification'],['ONE_TIME_HISTORY_AMBIGUOUS','APPLICABILITY_CONTEXT_MISSING','FEE_CONFIGURATION_ERROR'],true) && ($item['classification']!=='FEE_CONFIGURATION_ERROR' || $item['is_required'])) throw new ManagedAssessmentCommitException($item['classification'],'Managed assessment contains a blocking fee configuration or context issue.');
            $new=array_values(array_filter($preview['items'],static fn(array $i): bool => !empty($i['selected_for_preview']) && in_array($i['classification'],['NEW_REQUIRED_STANDARD','REVIEWABLE_STANDARD','ONE_TIME_ELIGIBLE'],true)));
            if (!$new) { if ($manageTransaction) $this->pdo->commit(); return $this->emptyResult($preview); }
            $canonical=$this->canonicalForUpdate($context);
            $billingId=$canonical ? (int)$canonical['billing_id'] : 0;
            if (!$canonical) {
                $create=$this->pdo->prepare("INSERT INTO billing (student_id,generated_by,billing_type,academic_year,semester,total_amount,discount_amount,remaining_balance,billing_status) VALUES (?,?,'Assessment',?,?,0,0,0,'Unpaid')");
                $create->execute([$context->studentId,$actorId,$context->academicYear,$context->semester]); $billingId=(int)$this->pdo->lastInsertId();
                $map=$this->pdo->prepare('INSERT INTO managed_assessment_headers (student_id,academic_year,semester,billing_id,created_by) VALUES (?,?,?,?,?)'); $map->execute([$context->studentId,$context->academicYear,$context->semester,$billingId,$actorId]);
            }
            $insert=$this->pdo->prepare("INSERT INTO billing_items (billing_id,fee_id,fee_version_id,fee_name,source_context,added_by,amount,paid_amount,remaining_amount,status) VALUES (?,?,?,?,?,?,?,0,?,'Unpaid')");
            $added=[]; $existing=[];
            foreach($new as $item) {
                $exists=$this->pdo->prepare('SELECT billing_item_id FROM billing_items WHERE billing_id=? AND fee_version_id=? FOR UPDATE'); $exists->execute([$billingId,$item['fee_version_id']]);
                if($exists->fetchColumn()){ $existing[]=$item; continue; }
                try { $insert->execute([$billingId,$item['fee_id'],$item['fee_version_id'],$item['fee_name'],'Managed Standard Assessment',$actorId,$item['amount'],$item['amount']]); $added[]=$item; }
                catch(PDOException $e){ if($e->getCode()==='23000'){ $existing[]=$item; continue; } throw $e; }
            }
            if(!$added){ if ($manageTransaction) $this->pdo->commit(); return ['outcome'=>'AlreadyAssessed','billing_id'=>$billingId,'added_item_count'=>0,'added_amount'=>0.0,'existing_item_count'=>count($existing),'excluded_item_count'=>$preview['summary']['excluded_count'],'committed_items'=>[],'skipped_items'=>$existing]; }
            $summary=$this->recalculateHeader($billingId);
            if ($manageTransaction) $this->pdo->commit();
            return ['outcome'=>$existing?'PartiallyAdded':'Added','billing_id'=>$billingId,'added_item_count'=>count($added),'added_amount'=>round(array_sum(array_column($added,'amount')),2),'existing_item_count'=>count($existing),'excluded_item_count'=>$preview['summary']['excluded_count'],'committed_items'=>$added,'skipped_items'=>$existing,'billing_summary'=>$summary];
        } catch (Throwable $e) { if($manageTransaction && $this->pdo->inTransaction()) $this->pdo->rollBack(); if($e instanceof ManagedAssessmentCommitException) throw $e; error_log('Managed assessment commit failure: '.$e->getMessage()); throw new ManagedAssessmentCommitException('MANAGED_ASSESSMENT_COMMIT_FAILED','Managed assessment could not be committed.',500,$e); }
    }
    private function canonicalForUpdate(BillingStudentContext $c): ?array { $q=$this->pdo->prepare('SELECT billing_id FROM managed_assessment_headers WHERE student_id=? AND academic_year=? AND semester=? FOR UPDATE'); $q->execute([$c->studentId,$c->academicYear,$c->semester]); return $q->fetch(PDO::FETCH_ASSOC) ?: null; }
    /** @return array<string,float|string> */
    private function recalculateHeader(int $billingId): array {
        $q=$this->pdo->prepare('SELECT COALESCE(SUM(amount),0) gross_total,COALESCE(SUM(paid_amount),0) total_paid,COALESCE(SUM(remaining_amount),0) item_remaining_total FROM billing_items WHERE billing_id=?'); $q->execute([$billingId]); $s=$q->fetch(PDO::FETCH_ASSOC);
        $d=$this->pdo->prepare('SELECT discount_amount FROM billing WHERE billing_id=? FOR UPDATE'); $d->execute([$billingId]); $discount=(float)$d->fetchColumn(); $gross=(float)$s['gross_total']; $remaining=max(0,(float)$s['item_remaining_total']-$discount); $net=max(0,$gross-$discount); $status=$remaining<=0?'Paid':($remaining<$net?'Partial':'Unpaid');
        $u=$this->pdo->prepare('UPDATE billing SET total_amount=?,remaining_balance=?,billing_status=?,updated_at=CURRENT_TIMESTAMP WHERE billing_id=?'); $u->execute([$gross,$remaining,$status,$billingId]);
        return ['gross_total'=>$gross,'total_paid'=>(float)$s['total_paid'],'item_remaining_total'=>(float)$s['item_remaining_total'],'discount_amount'=>$discount,'remaining_balance'=>$remaining,'billing_status'=>$status];
    }
    /** @param array<string,mixed> $preview @return array<string,mixed> */
    private function emptyResult(array $preview): array { return ['outcome'=>$preview['status']==='AlreadyAssessed'?'AlreadyAssessed':'NoApplicableFees','billing_id'=>$preview['canonical_billing']['billing_id']??null,'added_item_count'=>0,'added_amount'=>0.0,'existing_item_count'=>$preview['summary']['existing_count'],'excluded_item_count'=>$preview['summary']['excluded_count'],'committed_items'=>[],'skipped_items'=>$preview['items']]; }
}
