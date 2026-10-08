<?php
declare(strict_types=1);

final class ManagedBillingNotificationDispatcher
{
    public function __construct(private readonly PDO $pdo, private readonly int $maxAttempts = 5, private readonly int $retryDelaySeconds = 60, private readonly int $staleProcessingSeconds = 300)
    {
        if ($maxAttempts < 1 || $retryDelaySeconds < 0 || $staleProcessingSeconds < 1) throw new InvalidArgumentException('Invalid notification dispatcher limits.');
    }

    /** @return array{processed:int,sent:int,pending_account_link:int,failed:int} */
    public function dispatchDue(int $limit = 25, ?int $runId = null, ?string $createdBefore = null): array
    {
        if ($limit < 1 || $limit > 100) throw new InvalidArgumentException('Notification dispatch limit must be between 1 and 100.');
        if ($runId !== null && $runId < 1) throw new InvalidArgumentException('Run ID must be positive.');
        if ($createdBefore !== null && strtotime($createdBefore) === false) throw new InvalidArgumentException('Historical cutoff is invalid.');
        $summary=['processed'=>0,'sent'=>0,'pending_account_link'=>0,'failed'=>0];
        for($i=0;$i<$limit;$i++){
            $result=$this->dispatchNext($runId,$createdBefore); if($result===null)break; $summary['processed']++;
            if($result==='Sent')$summary['sent']++; elseif($result==='PendingAccountLink')$summary['pending_account_link']++; else $summary['failed']++;
        }
        return $summary;
    }

    private function dispatchNext(?int $runId, ?string $createdBefore): ?string
    {
        $retryCutoff=date('Y-m-d H:i:s',time()-$this->retryDelaySeconds); $staleCutoff=date('Y-m-d H:i:s',time()-$this->staleProcessingSeconds);
        $where=["((delivery_status='Pending') OR (delivery_status IN ('Failed','PendingAccountLink') AND attempt_count<? AND updated_at<=?) OR (delivery_status='Processing' AND attempt_count<? AND updated_at<=?))"];
        $params=[$this->maxAttempts,$retryCutoff,$this->maxAttempts,$staleCutoff];
        if($runId!==null){$where[]='run_id=?';$params[]=$runId;} if($createdBefore!==null){$where[]='created_at<=?';$params[]=date('Y-m-d H:i:s',strtotime($createdBefore));}
        $this->pdo->beginTransaction(); $outboxId=null;
        try{
            $sql='SELECT * FROM billing_notification_outbox WHERE '.implode(' AND ',$where).' ORDER BY outbox_id LIMIT 1'; if($this->driver()!=='sqlite')$sql.=' FOR UPDATE';
            $claim=$this->pdo->prepare($sql);$claim->execute($params);$row=$claim->fetch(PDO::FETCH_ASSOC); if(!$row){$this->pdo->commit();return null;}
            $outboxId=(int)$row['outbox_id'];$attempt=(int)$row['attempt_count']+1;
            $this->pdo->prepare("UPDATE billing_notification_outbox SET delivery_status='Processing',attempt_count=?,last_error=NULL WHERE outbox_id=?")->execute([$attempt,$outboxId]);
            $recipient=$this->pdo->prepare('SELECT user_id FROM students WHERE student_id=? LIMIT 1');$recipient->execute([(int)$row['student_id']]);$userId=(int)$recipient->fetchColumn();
            if($userId<=0){$this->setOutcome($row,'PendingAccountLink','RECIPIENT_ACCOUNT_NOT_LINKED');$this->pdo->commit();return 'PendingAccountLink';}
            $body=sprintf('A new assessment of PHP %s was added for AY %s, %s. Your remaining term balance is PHP %s.',number_format((float)$row['new_assessment_amount'],2),(string)$row['academic_year'],(string)$row['semester'],number_format((float)$row['term_remaining_balance'],2));
            try{$notice=$this->pdo->prepare('INSERT INTO payment_notifications (recipient_user_id,event_key,title,body,target_url) VALUES (?,?,?,?,?)');$notice->execute([$userId,(string)$row['event_key'],'New Billing Assessment',$body,'/modules/student-portal/pages/statement-of-account.php']);}
            catch(PDOException $e){if($e->getCode()!=='23000')throw $e;$existing=$this->pdo->prepare('SELECT notification_id FROM payment_notifications WHERE recipient_user_id=? AND event_key=? LIMIT 1');$existing->execute([$userId,(string)$row['event_key']]);if(!$existing->fetchColumn())throw $e;}
            $this->setOutcome($row,'Sent',null);$this->pdo->commit();return 'Sent';
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();if($outboxId!==null)$this->recordFailure($outboxId,$e instanceof PDOException?'PAYMENT_NOTIFICATION_STORAGE_FAILED':'NOTIFICATION_DISPATCH_FAILED');return 'Failed';}
    }

    /** @param array<string,mixed> $row */
    private function setOutcome(array $row,string $status,?string $error): void
    {
        $sentAt=$status==='Sent'?date('Y-m-d H:i:s'):null;
        $this->pdo->prepare('UPDATE billing_notification_outbox SET delivery_status=?,last_error=?,sent_at=? WHERE outbox_id=?')->execute([$status,$error,$sentAt,(int)$row['outbox_id']]);
        $this->pdo->prepare('UPDATE billing_run_assignments SET notification_state=? WHERE run_id=? AND student_id=?')->execute([$status,(int)$row['run_id'],(int)$row['student_id']]);
    }

    private function recordFailure(int $outboxId,string $classification): void
    {
        try{$this->pdo->beginTransaction();$sql='SELECT run_id,student_id,attempt_count FROM billing_notification_outbox WHERE outbox_id=?'.($this->driver()!=='sqlite'?' FOR UPDATE':'');$q=$this->pdo->prepare($sql);$q->execute([$outboxId]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if($row){$attempt=min($this->maxAttempts,(int)$row['attempt_count']+1);$this->pdo->prepare("UPDATE billing_notification_outbox SET delivery_status='Failed',attempt_count=?,last_error=? WHERE outbox_id=?")->execute([$attempt,$classification,$outboxId]);$this->pdo->prepare("UPDATE billing_run_assignments SET notification_state='Failed' WHERE run_id=? AND student_id=?")->execute([(int)$row['run_id'],(int)$row['student_id']]);}
            $this->pdo->commit();
        }catch(Throwable $failure){if($this->pdo->inTransaction())$this->pdo->rollBack();error_log('Managed billing notification failure state could not be recorded.');}
    }

    private function driver(): string{return (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);}
}
