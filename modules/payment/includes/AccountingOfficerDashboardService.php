<?php
declare(strict_types=1);

/** Read-only operational dashboard. It deliberately contains no collection or receivables analytics. */
final class AccountingOfficerDashboardService
{
    private const OUTCOMES = ['Added','PartiallyAdded','AlreadyAssessed','NoApplicableFees','ReviewRequired','Failed','Pending','Processing'];
    public function __construct(private readonly PDO $pdo) {}

    /** @return array<string,mixed> */
    public function load(array $input): array
    {
        $terms = $this->terms();
        $requestedYear = trim((string)($input['academic_year'] ?? ''));
        $year = $requestedYear !== '' ? $requestedYear : (string)($terms[0]['academic_year'] ?? '');
        $semester = (string)($input['semester'] ?? ($terms[0]['semester'] ?? '1st'));
        if (!preg_match('/^\d{4}-\d{4}$/', $year) || !in_array($semester, ['1st','2nd','Summer'], true)) throw new InvalidArgumentException('Select a valid academic year and semester.');
        $scope = [$year, $semester];
        return [
            'ok' => true,
            'scope' => ['academic_year'=>$year, 'semester'=>$semester],
            'filter_options' => ['terms'=>$terms],
            'kpis' => $this->kpis($scope),
            'assessment_trend' => $this->assessmentTrend(),
            'work_queue' => $this->workQueue($scope),
            'outcomes' => $this->outcomes($scope),
            'attention' => $this->attention($scope),
            'recent_activity' => $this->recentActivity($scope),
        ];
    }

    /** @return list<array<string,string>> */
    private function terms(): array
    {
        $found=[];
        foreach (['billing_runs','fee_versions','billing'] as $table) {
            foreach ($this->pdo->query("SELECT academic_year,semester FROM {$table} GROUP BY academic_year,semester")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $found[$row['academic_year'].'|'.$row['semester']] = ['academic_year'=>(string)$row['academic_year'],'semester'=>(string)$row['semester']];
            }
        }
        $terms=array_values($found); usort($terms,static fn($a,$b)=>($a['academic_year']===$b['academic_year'] ? array_search($a['semester'],['1st','2nd','Summer'],true)<=>array_search($b['semester'],['1st','2nd','Summer'],true) : strcmp($b['academic_year'],$a['academic_year']))); return $terms;
    }

    /** @param list<string> $scope @return array<string,int> */
    private function kpis(array $scope): array
    {
        $fees=$this->pdo->prepare("SELECT COUNT(*) FROM fee_versions v JOIN fees f ON f.fee_id=v.fee_id WHERE v.academic_year=? AND v.semester=? AND v.effective_status='Active' AND v.amount>0 AND COALESCE(f.identity_status,f.status)='Active'");$fees->execute($scope);
        $approved=$this->pdo->prepare("SELECT COUNT(*) FROM billing_runs WHERE academic_year=? AND semester=? AND status='Approved'");$approved->execute($scope);
        $action=$this->pdo->prepare("SELECT COUNT(*) FROM billing_runs WHERE academic_year=? AND semester=? AND status IN ('Running','CompletedWithExceptions')");$action->execute($scope);
        $concerns=(int)$this->pdo->query("SELECT COUNT(*) FROM payment_concerns WHERE verification_status IN ('Pending','On Hold')")->fetchColumn();
        return ['active_fees'=>(int)$fees->fetchColumn(),'approved_runs'=>(int)$approved->fetchColumn(),'runs_needing_action'=>(int)$action->fetchColumn(),'pending_concerns'=>$concerns];
    }

    /** @return array{labels:list<string>,values:list<int>} */
    private function assessmentTrend(): array
    {
        $q=$this->pdo->query("SELECT DATE(created_at) day,COUNT(*) total FROM managed_assessment_headers WHERE created_at>=DATE_SUB(CURDATE(),INTERVAL 6 DAY) GROUP BY DATE(created_at)");$map=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)$map[$row['day']]=(int)$row['total'];
        $labels=[];$values=[];for($i=6;$i>=0;$i--){$day=date('Y-m-d',strtotime("-$i days"));$labels[]=date('M j',strtotime($day));$values[]=$map[$day]??0;}return ['labels'=>$labels,'values'=>$values];
    }

    /** @param list<string> $scope @return list<array<string,mixed>> */
    private function workQueue(array $scope): array
    {
        $q=$this->pdo->prepare("SELECT r.run_id,r.academic_year,r.semester,r.status,r.projected_student_count,COUNT(a.assignment_id) assignment_count,COUNT(DISTINCT CONCAT(a.program_code,'|',a.year_level)) context_count,MIN(a.program_code) program_code,MIN(a.year_level) year_level FROM billing_runs r LEFT JOIN billing_run_assignments a ON a.run_id=r.run_id WHERE r.academic_year=? AND r.semester=? AND r.status IN ('Draft','Approved','Running','CompletedWithExceptions') GROUP BY r.run_id ORDER BY FIELD(r.status,'Approved','Running','CompletedWithExceptions','Draft'),r.run_id DESC LIMIT 20");$q->execute($scope);$rows=$q->fetchAll(PDO::FETCH_ASSOC);foreach($rows as &$row){$row['cohort_label']=(int)$row['context_count']===1?trim((string)$row['program_code']).' / '.trim((string)$row['year_level']):'Mixed';$row['student_count']=(int)($row['assignment_count'] ?: $row['projected_student_count']);}unset($row);return $rows;
    }

    /** @param list<string> $scope @return list<array{label:string,count:int}> */
    private function outcomes(array $scope): array
    {
        $q=$this->pdo->prepare("SELECT a.status,COUNT(*) count FROM billing_run_assignments a JOIN billing_runs r ON r.run_id=a.run_id WHERE r.academic_year=? AND r.semester=? GROUP BY a.status");$q->execute($scope);$map=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)$map[$row['status']]=(int)$row['count'];$out=[];foreach(self::OUTCOMES as $status)$out[]=['label'=>$status,'count'=>$map[$status]??0];return $out;
    }

    /** @param list<string> $scope @return array<string,int> */
    private function attention(array $scope): array
    {
        $q=$this->pdo->prepare("SELECT a.status,COUNT(*) count FROM billing_run_assignments a JOIN billing_runs r ON r.run_id=a.run_id WHERE r.academic_year=? AND r.semester=? AND a.status IN ('Failed','ReviewRequired') GROUP BY a.status");$q->execute($scope);$map=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)$map[$row['status']]=(int)$row['count'];
        return ['failed_assignments'=>$map['Failed']??0,'review_required'=>$map['ReviewRequired']??0,'pending_concerns'=>(int)$this->pdo->query("SELECT COUNT(*) FROM payment_concerns WHERE verification_status IN ('Pending','On Hold')")->fetchColumn(),'unmatched_aub_rows'=>(int)$this->pdo->query("SELECT COUNT(*) FROM bank_statement_rows WHERE status='Unmatched'")->fetchColumn()];
    }

    /** @param list<string> $scope @return list<array<string,string>> */
    private function recentActivity(array $scope): array
    {
        $events=[];$add=static function(array &$events, string $at,string $type,string $detail):void{if($at!=='')$events[]=['occurred_at'=>$at,'type'=>$type,'detail'=>$detail];};
        $q=$this->pdo->prepare("SELECT run_id,created_at,approved_at,completed_at,status FROM billing_runs WHERE academic_year=? AND semester=? ORDER BY updated_at DESC LIMIT 10");$q->execute($scope);foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){$add($events,(string)$r['created_at'],'Bulk run created','Run #'.$r['run_id'].' created');$add($events,(string)($r['approved_at']??''),'Bulk run approved','Run #'.$r['run_id'].' approved');$add($events,(string)($r['completed_at']??''),'Bulk run completed','Run #'.$r['run_id'].' '.$r['status']);}
        $q=$this->pdo->prepare("SELECT managed_header_id,created_at FROM managed_assessment_headers WHERE academic_year=? AND semester=? ORDER BY created_at DESC LIMIT 10");$q->execute($scope);foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$add($events,(string)$r['created_at'],'Managed assessment completed','Managed assessment #'.$r['managed_header_id'].' created');
        foreach($this->pdo->query("SELECT concern_id,reviewed_at,verification_status FROM payment_concerns WHERE reviewed_at IS NOT NULL ORDER BY reviewed_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC) as $r)$add($events,(string)$r['reviewed_at'],'Payment concern reviewed','Concern #'.$r['concern_id'].' '.$r['verification_status']);
        foreach($this->pdo->query("SELECT id,uploaded_at,filename,status FROM bank_statements ORDER BY uploaded_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC) as $r)$add($events,(string)$r['uploaded_at'],'AUB statement import','Statement #'.$r['id'].' '.$r['status'].' — '.$r['filename']);
        usort($events,static fn($a,$b)=>strcmp($b['occurred_at'],$a['occurred_at']));return array_slice($events,0,10);
    }
}
